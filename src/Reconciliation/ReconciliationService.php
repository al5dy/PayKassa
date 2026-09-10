<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Reconciliation;

use Al5dy\PayKassaWoo\Infrastructure\DatabaseMutex;
use Al5dy\PayKassaWoo\Infrastructure\Logger;
use Al5dy\PayKassaWoo\Order\OrderMeta;
use Al5dy\PayKassaWoo\Order\PaymentSnapshot;
use Al5dy\PayKassaWoo\PayKassa\Dto\HistoryCandidate;
use Al5dy\PayKassaWoo\PayKassa\Exception\ConfigurationException;
use Al5dy\PayKassaWoo\PayKassa\Exception\InvalidResponseException;
use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;
use Al5dy\PayKassaWoo\PayKassa\Exception\WebhookVerificationException;
use Al5dy\PayKassaWoo\PayKassa\PayKassaClientFactory;
use Al5dy\PayKassaWoo\Webhook\WebhookEventStore;
use Al5dy\PayKassaWoo\Webhook\WebhookProcessor;

/** History discovers candidates; only SCI-verified evidence enters settlement. */
final class ReconciliationService
{
    public const JOB_OPTION = 'paykassa_reconciliation_job';
    public const REPORT_OPTION = 'paykassa_reconciliation_report';

    /**
     * Bounded and resumable. Optional UTC ranges enable explicit backfill by
     * trusted PHP callers; this method is not a public endpoint.
     *
     * @return array<string, int|string>
     */
    public function run(?string $from = null, ?string $to = null): array
    {
        $settings = get_option('woocommerce_paykassa_settings', array());
        $settings = is_array($settings) ? $settings : array();
        if ('yes' !== ($settings['reconciliation_enabled'] ?? 'no')) {
            return array('status' => 'disabled');
        }
        $mutex = new DatabaseMutex();
        try {
            if (! $mutex->acquire('reconciliation')) {
                return array('status' => 'busy');
            }
            try {
                foreach (array('api_id', 'api_password', 'shop_id', 'shop_password') as $key) {
                    if (! isset($settings[$key]) || ! is_string($settings[$key]) || '' === $settings[$key]) {
                        throw new ConfigurationException('Recovery requires API and SCI credentials.');
                    }
                }
                return $this->run_locked($settings, $from, $to, $mutex);
            } finally {
                $mutex->release();
            }
        } catch (\Throwable $exception) {
            $report = array('status' => 'failed', 'at' => gmdate('c'), 'error_code' => Logger::fingerprint($exception->getMessage()));
            update_option('paykassa_last_reconciliation_error', gmdate('c'), false);
            update_option(self::REPORT_OPTION, $report, false);
            (new Logger())->log('error', 'reconciliation_failed', $report);
            return $report;
        }
    }

    /** @param array<string, string> $settings @return array<string, int|string> */
    private function run_locked(array $settings, ?string $from, ?string $to, DatabaseMutex $mutex): array
    {
        $context = hash('sha256', $settings['shop_id'] . "\0" . $settings['api_id'] . "\0" . ($settings['testmode'] ?? 'no'));
        $job = get_option(self::JOB_OPTION, false);
        if (false !== $job && ! is_array($job)) {
            throw new ConfigurationException('Invalid saved recovery checkpoint.');
        }
        if (false === $job) {
            $days = max(1, min(365, (int) ($settings['reconciliation_backfill_days'] ?? 30)));
            $through = get_option('paykassa_reconciliation_through_' . $context, '');
            $last = is_string($through) ? strtotime($through) : false;
            $start = null !== $from ? strtotime($from) : (false !== $last ? $last - DAY_IN_SECONDS : time() - $days * DAY_IN_SECONDS);
            $end = null !== $to ? strtotime($to) : time();
            if (false === $start || false === $end || $start < 0 || $start >= $end || $end > time() + 60) {
                throw new ConfigurationException('Invalid recovery date range.');
            }
            $job = array(
                'context' => $context, 'from' => gmdate('c', $start), 'to' => gmdate('c', $end),
                'page' => 0, 'offset' => 0, 'page_fingerprint' => '', 'seen_pages' => array(),
                'recovered' => 0, 'duplicate' => 0, 'manual_review' => 0, 'unverifiable' => 0, 'unmatched' => 0,
                'checked' => 0, 'failures' => 0, 'retry_at' => 0,
            );
            $this->save_job($job);
        }
        if (($job['context'] ?? '') !== $context) {
            throw new ConfigurationException('Recovery configuration changed; finish or explicitly reset the saved backfill before switching context.');
        }
        $this->validate_job($job);
        if ((null !== $from && strtotime($from) !== strtotime($job['from'])) || (null !== $to && strtotime($to) !== strtotime($job['to']))) {
            throw new ConfigurationException('A different recovery range is already in progress.');
        }
        if ($job['retry_at'] > time()) {
            return array('status' => 'backoff', 'retry_at' => $job['retry_at']);
        }
        $factory = new PayKassaClientFactory();
        $api = $factory->api($settings);
        $processor = new WebhookProcessor(new WebhookEventStore(), new Logger());
        $deadline = time() + 40;
        $checked = 0;
        try {
            for ($pages = 0; $pages < 5; ++$pages) {
                $mutex->assert_owned();
                $page = $api->history($settings['shop_id'], $job['from'], $job['to'], $job['page']);
                if (in_array($page->fingerprint, $job['seen_pages'], true)) {
                    throw new InvalidResponseException('Provider repeated a previous history page.');
                }
                // Pagination may move while payments are credited. Replay a
                // changed page from its start; settlement is idempotent.
                if ($job['page_fingerprint'] !== $page->fingerprint) {
                    $job['offset'] = 0;
                    $job['page_fingerprint'] = $page->fingerprint;
                }
                for ($index = $job['offset']; $index < count($page->candidates); ++$index) {
                    if ($checked >= 10 || time() >= $deadline) {
                        $this->save_job($job);
                        return $this->report($job, 'in_progress');
                    }
                    $mutex->assert_owned();
                    $outcome = $this->recover($page->candidates[$index], $settings, $factory, $processor);
                    if ('retry' === $outcome) {
                        throw new PayKassaException('Settlement did not finish durably; recovery will retry.');
                    }
                    ++$job[$outcome];
                    ++$job['checked'];
                    ++$checked;
                    $job['offset'] = $index + 1;
                    // If this write fails after settlement, replay the row.
                    // Never persist provider tokens/raw payloads in a cursor.
                    $this->save_job($job);
                }
                if ($job['page'] + 1 >= $page->page_count) {
                    $report = $this->report($job, $job['manual_review'] + $job['unverifiable'] > 0 ? 'needs_review' : 'completed');
                    if (0 === $job['unverifiable']) {
                        $this->save_option('paykassa_reconciliation_through_' . $context, $job['to']);
                    }
                    $this->save_option('paykassa_last_history_scan', gmdate('c'));
                    if ('completed' === $report['status']) {
                        $this->save_option('paykassa_last_reconciliation', gmdate('c'));
                    }
                    if (! delete_option(self::JOB_OPTION)) {
                        throw new PayKassaException('Could not finish recovery checkpoint.');
                    }
                    return $report;
                }
                $job['seen_pages'][] = $page->fingerprint;
                ++$job['page'];
                $job['offset'] = 0;
                $job['page_fingerprint'] = '';
                $this->save_job($job);
                if (time() >= $deadline) {
                    break;
                }
            }
            return $this->report($job, 'in_progress');
        } catch (\Throwable $exception) {
            ++$job['failures'];
            $job['retry_at'] = time() + min(21600, 300 * (2 ** min(6, $job['failures'] - 1)));
            $this->save_job($job);
            throw $exception;
        }
    }

    /** @param array<string, string> $settings */
    private function recover(HistoryCandidate $candidate, array $settings, PayKassaClientFactory $factory, WebhookProcessor $processor): string
    {
        if (! $candidate->can_verify()) {
            return 'unverifiable';
        }
        $order = wc_get_order($candidate->order_id);
        if (! $order instanceof \WC_Order || 'paykassa' !== $order->get_payment_method()) {
            return 'unmatched';
        }
        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::SNAPSHOT, true));
        if (! $snapshot instanceof PaymentSnapshot || $snapshot->order_id !== $candidate->order_id || $snapshot->merchant_shop_id !== $settings['shop_id']) {
            return 'unverifiable';
        }
        $settings['testmode'] = $snapshot->test_mode ? 'yes' : 'no';
        try {
            $evidence = $factory->sci($settings)->verify_ipn($candidate->verification_token());
        } catch (WebhookVerificationException $exception) {
            (new Logger())->log('warning', 'reconciliation_unverifiable', array('order_id' => $candidate->order_id, 'error_code' => Logger::fingerprint($exception->getMessage())));
            return 'unverifiable';
        }
        if ($evidence->order_id !== $candidate->order_id) {
            return 'unverifiable';
        }
        $result = $processor->process($evidence, 'reconciliation');
        return 'processed' === $result['outcome'] ? 'recovered' : $result['outcome'];
    }

    /** @param array<string, mixed> $job */
    private function save_job(array $job): void
    {
        $this->save_option(self::JOB_OPTION, $job);
    }

    /** @param array<string, mixed> $job */
    private function validate_job(array $job): void
    {
        foreach (array('page', 'offset', 'checked', 'recovered', 'duplicate', 'manual_review', 'unverifiable', 'unmatched', 'failures', 'retry_at') as $key) {
            if (! isset($job[$key]) || ! is_int($job[$key]) || $job[$key] < 0) {
                throw new ConfigurationException('Invalid saved recovery checkpoint.');
            }
        }
        foreach (array('from', 'to', 'page_fingerprint') as $key) {
            if (! isset($job[$key]) || ! is_string($job[$key])) {
                throw new ConfigurationException('Invalid saved recovery checkpoint.');
            }
        }
        if (
            false === strtotime($job['from']) || false === strtotime($job['to']) || strtotime($job['from']) >= strtotime($job['to'])
            || $job['page'] >= 10000 || $job['offset'] > 1000 || ! isset($job['seen_pages']) || ! is_array($job['seen_pages'])
            || count($job['seen_pages']) !== $job['page']
        ) {
            throw new ConfigurationException('Invalid saved recovery checkpoint.');
        }
    }

    /** @param string|array<string, mixed> $value */
    private function save_option(string $name, $value): void
    {
        if (! update_option($name, $value, false) && get_option($name) !== $value) {
            throw new PayKassaException('Could not persist recovery progress.');
        }
    }

    /** @param array<string, mixed> $job @return array<string, int|string> */
    private function report(array $job, string $status): array
    {
        $report = array('status' => $status, 'at' => gmdate('c'));
        foreach (array('from', 'to', 'page', 'checked', 'recovered', 'duplicate', 'manual_review', 'unverifiable', 'unmatched') as $key) {
            $report[$key] = $job[$key];
        }
        $this->save_option(self::REPORT_OPTION, $report);
        (new Logger())->log('info', 'reconciliation_progress', $report);
        return $report;
    }
}
