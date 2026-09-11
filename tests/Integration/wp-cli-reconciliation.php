<?php

declare(strict_types=1);

use Al5dy\PayKassaWoo\Gateway\PayKassaGateway;
use Al5dy\PayKassaWoo\Infrastructure\Logger;
use Al5dy\PayKassaWoo\Order\OrderMeta;
use Al5dy\PayKassaWoo\PayKassa\PayKassaClientFactory;
use Al5dy\PayKassaWoo\Reconciliation\ReconciliationScheduler;
use Al5dy\PayKassaWoo\Reconciliation\ReconciliationService;
use Al5dy\PayKassaWoo\Webhook\WebhookEventStore;
use Al5dy\PayKassaWoo\Webhook\WebhookProcessor;
use Automattic\WooCommerce\Utilities\OrderUtil;

if (! defined('PAYKASSA_TEST_DATABASE') || ! PAYKASSA_TEST_DATABASE || ! str_starts_with(DB_NAME, 'paykassa_test_')) {
    throw new RuntimeException('This test requires a disposable integration database.');
}
$assertions = 0;
$check = static function (bool $ok, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $ok) {
        throw new RuntimeException($message);
    }
};
$original = get_option('woocommerce_paykassa_settings');
$original_currency = get_option('woocommerce_currency');
$settings = array('enabled' => 'yes', 'shop_id' => 'recovery-merchant', 'shop_password' => 'synthetic-sci-secret', 'api_id' => 'synthetic-api-id', 'api_password' => 'synthetic-api-secret', 'testmode' => 'yes', 'enabled_systems' => 'bitcoin,ethereum', 'accepted_order_currencies' => array('BTC', 'ETH'), 'enabled_payment_directions' => array('bitcoin:BTC', 'ethereum:ETH'), 'reconciliation_enabled' => 'yes');
$orders = array();
$evidence = array();
$history = array();
$requests = array();
$api_failure = false;
$raw_envelope = null;
$payment_calls = 0;
$from = gmdate('c', time() - 7 * DAY_IN_SECONDS);
$to = gmdate('c', time() - 60);
$context = hash('sha256', "recovery-merchant\0synthetic-api-id\0yes");
$reset = static function () use ($context): void {
    foreach (array(ReconciliationService::JOB_OPTION, ReconciliationService::REPORT_OPTION, 'paykassa_last_reconciliation', 'paykassa_last_history_scan', 'paykassa_last_reconciliation_error', 'paykassa_reconciliation_through_' . $context) as $key) {
        delete_option($key);
    }
};
$http = static function ($preempt, array $args, string $url) use (&$evidence, &$history, &$requests, &$api_failure, &$raw_envelope, $check) {
    $body = $args['body'];
    $check(in_array($url, array('https://paykassa.app/api/0.9/index.php', 'https://paykassa.app/sci/0.4/index.php'), true), 'Only reviewed PayKassa origins may be called.');
    $check(true === $args['sslverify'] && 0 === $args['redirection'] && $args['timeout'] <= 20, 'Safe transport options required.');
    $requests[] = array('func' => $body['func'], 'page' => $body['page_num'] ?? '', 'from' => $body['datetime_start'] ?? '', 'test' => $body['test']);
    if ('api_get_history' === $body['func']) {
        $check('pay_in' === $body['type'] && 'yes' === $body['status'] && 'recovery-merchant' === $body['shop_id'], 'History filters must be explicit.');
        if ($api_failure) {
            return new WP_Error('test_timeout', 'Synthetic transport timeout.');
        }
        $data = $raw_envelope ?? array('error' => false, 'message' => '', 'data' => array('page_count' => count($history), 'list' => $history[(int) $body['page_num']] ?? array()));
    } elseif ('sci_create_order' === $body['func']) {
        $hash = hash('sha256', 'invoice-' . $body['order_id']);
        $data = array('error' => false, 'message' => '', 'data' => array('url' => 'https://paykassa.app/pay/?hash=' . $hash, 'params' => array('hash' => $hash)));
    } elseif ('sci_confirm_order' === $body['func']) {
        $row = $evidence[$body['private_hash']] ?? null;
        $data = null === $row ? array('error' => true, 'message' => 'Synthetic invalid token') : array('error' => false, 'message' => '', 'data' => $row);
    } else {
        throw new RuntimeException('Unexpected remote operation.');
    }
    return array('headers' => array(), 'body' => wp_json_encode($data), 'response' => array('code' => 200), 'cookies' => array());
};
add_filter('pre_http_request', $http, 10, 3);
$paid_hook = static function () use (&$payment_calls): void {
    ++$payment_calls;
};
add_action('woocommerce_payment_complete', $paid_hook);
$number = static fn($value, $order): string => 'WC-2026-' . $order->get_id();
add_filter('woocommerce_order_number', $number, 10, 2);
update_option('woocommerce_paykassa_settings', $settings, false);
$make = static function (string $currency = 'BTC') use (&$orders, &$evidence, $check): array {
    $order = wc_create_order();
    $order->set_payment_method('paykassa');
    $order->set_currency($currency);
    $order->set_total('1.00');
    $order->set_date_created(time() - 3 * DAY_IN_SECONDS);
    $order->save();
    $orders[] = $order->get_id();
    $_POST['paykassa_system'] = 'ETH' === $currency ? 'ethereum' : 'bitcoin';
    $result = (new PayKassaGateway())->process_payment($order->get_id());
    $check('success' === ($result['result'] ?? ''), 'Invoice creation must succeed.');
    $token = hash('sha256', 'synthetic-token-' . $order->get_id());
    $evidence[$token] = array(
        'order_id' => (string) $order->get_id(), 'transaction' => 'recovery-' . $order->get_id(),
        'shop_id' => 'recovery-merchant', 'amount' => '1.00000000', 'currency' => $currency,
        'system' => 'ETH' === $currency ? 'Ethereum' : 'BitCoin',
        'hash' => hash('sha256', 'invoice-' . $order->get_id()), 'partial' => 'no',
    );
    return array('order_id' => (string) $order->get_id(), 'private_hash' => $token);
};
$run = static function () use (&$from, &$to): array {
    return (new ReconciliationService())->run($from, $to);
};
$retry_now = static function (): void {
    global $wpdb;
    $job = get_option(ReconciliationService::JOB_OPTION);
    $job['retry_at'] = 0;
    update_option(ReconciliationService::JOB_OPTION, $job, false);
    $wpdb->query("UPDATE {$wpdb->prefix}paykassa_events SET lease_expires_at = '2000-01-01 00:00:00' WHERE status IN ('received', 'settling')");
};
try {
    $expected_hpos = getenv('PAYKASSA_EXPECT_HPOS');
    $check(in_array($expected_hpos, array('yes', 'no'), true) && ('yes' === $expected_hpos) === OrderUtil::custom_orders_table_usage_is_enabled(), 'Requested HPOS store must actually be active.');
    $gateway = new PayKassaGateway();
    $check('no' === $gateway->form_fields['reconciliation_enabled']['default'], 'Unattended reconciliation must remain disabled by default.');
    $reset();
    $records = array();
    for ($i = 0; $i < 12; ++$i) {
        $records[] = $make(0 === $i % 2 ? 'BTC' : 'ETH');
    }
    update_option('woocommerce_currency', 'EUR', false);
    // Synthetic candidate rows test the consumer contract; the upstream SDK
    // does NOT document that history actually returns private_hash.
    $history = array(array_slice($records, 0, 3), array_slice($records, 3));
    $first = $run();
    $check('in_progress' === $first['status'] && 10 === $first['recovered'], 'First batch must stop at the bounded candidate budget.');
    $job = get_option(ReconciliationService::JOB_OPTION);
    $check(1 === $job['page'] && 7 === $job['offset'], 'Resume must retain the real second page and row offset.');
    $check(! str_contains(wp_json_encode($job), $records[0]['private_hash']), 'Cursor must not contain verification tokens.');
    $second = $run();
    $check('completed' === $second['status'] && 12 === $second['recovered'] && 12 === $payment_calls, 'Lost callbacks must settle orders across pages and runs.');
    foreach ($records as $record) {
        $order = wc_get_order((int) $record['order_id']);
        $check($order->is_paid(), 'Recovered order must really be paid in WC.');
        $check('' !== $order->get_meta(OrderMeta::RECONCILIATION) && '' === $order->get_meta(OrderMeta::LAST_WEBHOOK), 'Recovery must not pretend a webhook arrived.');
    }
    $check(false === get_option(ReconciliationService::JOB_OPTION, false), 'Completed job must be removed.');
    $check(count(array_filter($requests, static fn(array $request): bool => '1' === $request['page'])) >= 2, 'Second history page must actually be fetched on resume.');
    $processor = new WebhookProcessor(new WebhookEventStore(), new Logger());
    $client = (new PayKassaClientFactory())->sci($settings);
    $before = $payment_calls;
    $notes = count(wc_get_order_notes(array('order_id' => (int) $records[0]['order_id'])));
    $callback = $processor->process($client->verify_ipn($records[0]['private_hash']));
    $check($callback['accepted'] && 'duplicate' === $callback['outcome'] && $before === $payment_calls, 'Webhook after recovery must be a duplicate.');
    $repeat = $run();
    $check('in_progress' === $repeat['status'], 'Backfill must replay safely in bounded batches.');
    $repeat = $run();
    $check(12 === $repeat['duplicate'] && $before === $payment_calls, 'Repeated backfill must not settle again.');
    $check($notes === count(wc_get_order_notes(array('order_id' => (int) $records[0]['order_id']))), 'Duplicate backfill must not add notes.');

    // A distinct credited transaction is not an ordinary duplicate.
    $reset();
    $additional = $records[0];
    $additional['private_hash'] = hash('sha256', 'additional-synthetic-token');
    $evidence[$additional['private_hash']] = $evidence[$records[0]['private_hash']];
    $additional_transaction = 'additional-transaction-' . $additional['order_id'];
    $evidence[$additional['private_hash']]['transaction'] = $additional_transaction;
    $history = array(array($additional));
    $report = $run();
    $check('needs_review' === $report['status'] && 1 === $report['manual_review'] && $before === $payment_calls, 'A second real payment must be recorded for review, never settled twice.');
    $order = wc_get_order((int) $additional['order_id']);
    $check($order->is_paid() && $additional_transaction === $order->get_meta('_paykassa_additional_transaction_id'), 'Additional funds must remain visible without paid status regression.');

    $reset();
    $collision = $make();
    $evidence[$collision['private_hash']]['transaction'] = $evidence[$records[0]['private_hash']]['transaction'];
    $history = array(array($collision));
    $check('failed' === $run()['status'] && ! wc_get_order((int) $collision['order_id'])->is_paid(), 'A transaction already bound to another order is not an acknowledged duplicate.');

    // Invalid history token, missing token, partial payment, mismatches.
    foreach (array('amount', 'currency', 'system', 'shop_id', 'hash', 'order_id', 'partial', 'missing_token', 'invalid_token', 'changed_total', 'changed_currency') as $scenario) {
        $reset();
        $row = $make();
        $order_id = (int) $row['order_id'];
        if (in_array($scenario, array('amount', 'currency', 'system', 'shop_id', 'hash', 'order_id', 'partial'), true)) {
            $values = array('amount' => '0.50', 'currency' => 'ETH', 'system' => 'Ethereum', 'shop_id' => 'wrong', 'hash' => str_repeat('f', 64), 'order_id' => '9999999', 'partial' => 'yes');
            $evidence[$row['private_hash']][$scenario] = $values[$scenario];
        } elseif ('missing_token' === $scenario) {
            unset($row['private_hash']);
            $row['status'] = 'yes';
            $row['amount'] = '1.00';
        } elseif ('invalid_token' === $scenario) {
            unset($evidence[$row['private_hash']]);
        } else {
            $order = wc_get_order($order_id);
            'changed_total' === $scenario ? $order->set_total('2.00') : $order->set_currency('ETH');
            $order->save();
        }
        $history = array(array($row));
        $report = $run();
        $check('needs_review' === $report['status'] && ! wc_get_order($order_id)->is_paid(), 'Unsafe recovery must not settle: ' . $scenario);
        $check(false === get_option('paykassa_last_reconciliation', false), 'No successful recovery timestamp for ' . $scenario);
    }

    // Two identical invoices cannot be distinguished by amount/currency or a
    // public hash. Unknown history identity must not mutate either WC order.
    $reset();
    $ambiguous_a = $make();
    $ambiguous_b = $make();
    $before = $payment_calls;
    $history = array(array(array(
        'transaction' => 'synthetic-ambiguous-transaction', 'status' => 'yes',
        'amount' => '1.00000000', 'currency' => 'BTC', 'system' => 'BitCoin',
        'hash' => $evidence[$ambiguous_a['private_hash']]['hash'],
    )));
    $report = $run();
    $check('needs_review' === $report['status'] && 1 === $report['unverifiable'], 'An ambiguous history row must require review without guessing an order or private token.');
    $check($before === $payment_calls && ! wc_get_order((int) $ambiguous_a['order_id'])->is_paid() && ! wc_get_order((int) $ambiguous_b['order_id'])->is_paid(), 'Equal-amount invoices must both remain unpaid without verified identity.');
    $check(false === get_option('paykassa_reconciliation_through_' . $context, false), 'Unknown identity must not advance the recovery watermark.');
    $reset();
    $history = array(array(array('order_id' => $ambiguous_a['order_id'], 'private_hash' => $ambiguous_b['private_hash'])));
    $report = $run();
    $check('needs_review' === $report['status'] && 1 === $report['unverifiable'] && $before === $payment_calls, 'A valid token for a different existing order must not settle either candidate.');
    $check(! wc_get_order((int) $ambiguous_a['order_id'])->is_paid() && ! wc_get_order((int) $ambiguous_b['order_id'])->is_paid(), 'Conflicting discovery and verified identities cannot be silently rebound.');

    $reset();
    $cancelled = $make();
    wc_get_order((int) $cancelled['order_id'])->update_status('cancelled');
    $history = array(array($cancelled));
    $report = $run();
    $check(1 === $report['manual_review'] && wc_get_order((int) $cancelled['order_id'])->has_status('on-hold'), 'Late cancelled-order recovery needs manual review.');
    $reset();
    $later = $cancelled;
    $later['private_hash'] = hash('sha256', 'later-' . $cancelled['order_id']);
    $evidence[$later['private_hash']] = $evidence[$cancelled['private_hash']];
    $evidence[$later['private_hash']]['transaction'] .= '-later';
    $history = array(array($later));
    $check(1 === $run()['manual_review'] && ! wc_get_order((int) $cancelled['order_id'])->is_paid(), 'A second payment must not silently fulfil an order held for a late cancelled payment.');

    $reset();
    $environment = $make();
    $live_client = (new PayKassaClientFactory())->sci(array_replace($settings, array('testmode' => 'no')));
    $wrong_environment = $processor->process($live_client->verify_ipn($environment['private_hash']));
    $check(! $wrong_environment['accepted'] && ! wc_get_order((int) $environment['order_id'])->is_paid(), 'Live evidence must not settle a test invoice.');

    // Test -> Live changes: SCI request still uses the test invoice context.
    $reset();
    $test_invoice = $make();
    update_option('woocommerce_paykassa_settings', array_replace($settings, array('testmode' => 'no')), false);
    $history = array(array($test_invoice));
    $report = $run();
    $check(1 === $report['recovered'] && '1' === end($requests)['test'], 'Verification must use the snapshot environment.');
    update_option('woocommerce_paykassa_settings', $settings, false);

    // The exact sandbox no-result envelope is an empty first page, not an
    // outage. Similar provider errors must still fail closed and back off.
    $reset();
    $history = array();
    $raw_envelope = array('error' => true, 'message' => 'No data');
    $before = $payment_calls;
    $report = $run();
    $check('completed' === $report['status'] && 0 === $report['checked'], 'PayKassa No data must complete an empty reconciliation run.');
    $check(false === get_option(ReconciliationService::JOB_OPTION, false), 'No data must not leave a retry/backoff checkpoint.');
    $check(false === get_option('paykassa_last_reconciliation_error', false), 'No data must not be recorded as a provider outage.');
    $check($before === $payment_calls, 'An empty history result cannot invoke payment completion.');
    $reset();
    $raw_envelope = array('error' => true, 'message' => 'Access is denied. Error code: 10.');
    $check('failed' === $run()['status'], 'Authentication and permission errors must remain fail closed.');
    $job = get_option(ReconciliationService::JOB_OPTION);
    $check(is_array($job) && $job['retry_at'] > time(), 'A provider rejection other than exact No data must retain backoff state.');
    $raw_envelope = null;

    // API failure/backoff never advances the cursor or reports recovery success.
    $reset();
    $history = array(array($make()));
    $api_failure = true;
    $check('failed' === $run()['status'], 'API timeout must fail safely.');
    $calls = count($requests);
    $check('backoff' === $run()['status'] && $calls === count($requests), 'Backoff must not make another remote call.');
    $check(0 === get_option(ReconciliationService::JOB_OPTION)['offset'], 'Timeout cannot skip an unprocessed record.');
    $api_failure = false;
    $retry_now();
    $check(1 === $run()['recovered'], 'Recovery must resume after API availability returns.');

    // Fault before payment_complete: saved paid metadata is not a finished WC payment.
    $reset();
    $fault = $make();
    $history = array(array($fault));
    $throw = static function (): void {
        throw new RuntimeException('Synthetic failure before WC payment completion.');
    };
    add_action('woocommerce_pre_payment_complete', $throw);
    $report = $run();
    remove_action('woocommerce_pre_payment_complete', $throw);
    $check('failed' === $report['status'] && ! wc_get_order((int) $fault['order_id'])->is_paid(), 'Failed WC settlement must remain retryable.');
    $retry_now();
    $check(1 === $run()['recovered'] && wc_get_order((int) $fault['order_id'])->is_paid(), 'Saved-metadata failure must be recoverable.');

    // Real DB failure precisely at event finish, after WC has committed payment.
    $reset();
    $finish = $make();
    $history = array(array($finish));
    global $wpdb;
    $fail_update = static function (string $sql) use ($wpdb): string {
        if (str_contains($sql, "UPDATE {$wpdb->prefix}paykassa_events") && str_contains($sql, 'processed_at = UTC_TIMESTAMP()')) {
            return "UPDATE {$wpdb->prefix}paykassa_events SET paykassa_missing_test_column = 1";
        }
        return $sql;
    };
    $suppressed = $wpdb->suppress_errors(true);
    add_filter('query', $fail_update);
    $report = $run();
    remove_filter('query', $fail_update);
    $wpdb->suppress_errors($suppressed);
    $check('failed' === $report['status'] && wc_get_order((int) $finish['order_id'])->is_paid(), 'Event finish failure must not claim success.');
    $calls = $payment_calls;
    $retry_now();
    $check(1 === $run()['duplicate'] && $calls === $payment_calls, 'Finish retry must finalize the event without another WC settlement.');

    // Two independent PHP processes, shared DB, expired event lease while the
    // first worker is paused after order save and before WC payment completion.
    $reset();
    $concurrent = $make();
    $history = array(array($concurrent));
    update_option('paykassa_test_worker', array('token' => $concurrent['private_hash'], 'response' => $evidence[$concurrent['private_hash']]), false);
    $pipes = array();
    $worker = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/fixtures/reconciliation-worker.php', ABSPATH), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $check(is_resource($worker), 'Concurrent PHP worker must start.');
    try {
        $ready = false;
        $deadline = time() + 15;
        while (time() < $deadline) {
            $read = array($pipes[1]);
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, max(0, $deadline - time())) > 0) {
                $line = fgets($pipes[1]);
                if ("WORKER_READY\n" === $line) {
                    $ready = true;
                    break;
                }
                if (false === $line) {
                    break;
                }
            }
        }
        $check($ready, 'Worker must reach the settlement barrier.');
        $wpdb->query("UPDATE {$wpdb->prefix}paykassa_events SET lease_expires_at = '2000-01-01 00:00:00' WHERE status = 'settling'");
        $check('failed' === $run()['status'], 'Recovery cannot steal settlement from a live worker even after lease expiry.');
        fwrite($pipes[0], "resume\n");
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $check(0 === proc_close($worker) && str_contains($output, '"accepted":true'), 'First worker must finish exactly once: ' . $errors);
        $worker = null;
        $notes = count(wc_get_order_notes(array('order_id' => (int) $concurrent['order_id'])));
        $retry_now();
        $check(1 === $run()['duplicate'], 'Recovery after concurrent webhook must deduplicate the payment.');
        $check($notes === count(wc_get_order_notes(array('order_id' => (int) $concurrent['order_id']))), 'Concurrency must not duplicate settlement notes.');
    } finally {
        if (is_resource($worker)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($worker);
            proc_close($worker);
        }
        delete_option('paykassa_test_worker');
    }

    // Malformed/looping pagination and missing configuration.
    $reset();
    $raw_envelope = array('error' => false, 'data' => array('page_count' => 2, 'list' => array()));
    $check('failed' === $run()['status'], 'Empty non-final page is malformed, not completed.');
    $raw_envelope = null;
    $reset();
    $history = array(array($records[0]), array($records[0]));
    $check('failed' === $run()['status'], 'Repeated pages must be detected.');
    $reset();
    update_option('woocommerce_paykassa_settings', array_replace($settings, array('api_password' => '')), false);
    $calls = count($requests);
    $check('failed' === $run()['status'] && $calls === count($requests), 'Missing credentials must not make requests.');
    update_option('woocommerce_paykassa_settings', array_replace($settings, array('reconciliation_enabled' => 'no')), false);
    $check('disabled' === $run()['status'] && $calls === count($requests), 'Disabled recovery must not run.');
    update_option('woocommerce_paykassa_settings', $settings, false);

    // Default lookback/checkpoint survives downtime longer than 24 hours.
    $reset();
    $history = array();
    (new ReconciliationService())->run();
    $scan = end($requests);
    $check(strtotime($scan['from']) < time() - 29 * DAY_IN_SECONDS, 'Initial scan must not be restricted to 24 hours.');
    update_option('paykassa_reconciliation_through_' . $context, gmdate('c', time() - 10 * DAY_IN_SECONDS), false);
    (new ReconciliationService())->run();
    $check(strtotime(end($requests)['from']) <= time() - 10 * DAY_IN_SECONDS, 'Recovery must include the entire outage since its checkpoint.');

    // Scheduler owns exactly one recurring job and only PayKassa cleanup.
    $scheduler = new ReconciliationScheduler();
    ReconciliationScheduler::unschedule();
    $scheduler->schedule();
    $scheduler->schedule();
    $actions = as_get_scheduled_actions(array('hook' => ReconciliationScheduler::HOOK, 'group' => 'paykassa', 'status' => 'pending'), 'ids');
    $check(1 === count($actions), 'Repeated scheduling must remain unique.');
    // Exercise the real AS running -> completed lifecycle, including the
    // uniqueness guard that would prevent a running continuation scheduling itself.
    $reset();
    $history = array(array_fill(0, 25, array('status' => 'yes')));
    $execute_action = static function (int $action_id) use ($check): void {
        ActionScheduler_QueueRunner::instance()->process_action($action_id, 'paykassa-recovery-test');
        $logs = array_map(static fn($entry): string => $entry->get_message(), ActionScheduler::logger()->get_logs($action_id));
        $check('complete' === ActionScheduler::store()->get_status($action_id), 'Recovery action must complete: ' . implode(' | ', $logs));
    };
    $execute_action((int) $actions[0]);
    for ($batch = 0; $batch < 2; ++$batch) {
        $continuations = as_get_scheduled_actions(array('hook' => ReconciliationScheduler::CONTINUE_HOOK, 'group' => 'paykassa', 'status' => 'pending'), 'ids');
        $check(1 === count($continuations), 'AS must enqueue exactly one next batch after completing the current batch: ' . wp_json_encode(array('batch' => $batch, 'pending' => count($continuations), 'report' => get_option(ReconciliationService::REPORT_OPTION))));
        $execute_action((int) $continuations[0]);
    }
    $scheduled_report = get_option(ReconciliationService::REPORT_OPTION);
    $check('needs_review' === $scheduled_report['status'] && 25 === $scheduled_report['unverifiable'], 'AS must consume all bounded batches without claiming unknown history records as paid.');
    $check(false === get_option(ReconciliationService::JOB_OPTION, false), 'AS final batch must finish the saved job.');
    $other = as_schedule_single_action(time() + 3600, 'unrelated_test_job', array(), 'other');
    ReconciliationScheduler::unschedule();
    $check(! as_has_scheduled_action(ReconciliationScheduler::HOOK, array(), 'paykassa') && as_has_scheduled_action('unrelated_test_job', array(), 'other'), 'Cleanup must not touch other jobs.');
    as_unschedule_all_actions('unrelated_test_job', array(), 'other');
    WP_CLI::success('Recovery regression passed: ' . $assertions . ' assertions; HPOS=' . (OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off') . '. Synthetic history hints + documented SCI responses; no external sandbox credentials.');
} finally {
    remove_filter('pre_http_request', $http);
    remove_filter('woocommerce_order_number', $number);
    remove_action('woocommerce_payment_complete', $paid_hook);
    if (is_array($original)) {
        update_option('woocommerce_paykassa_settings', $original, false);
    } else {
        delete_option('woocommerce_paykassa_settings');
    }
    update_option('woocommerce_currency', $original_currency, false);
    foreach ($orders as $id) {
        $order = wc_get_order($id);
        if ($order instanceof WC_Order) {
            $order->delete(true);
        }
    }
    $reset();
    ReconciliationScheduler::unschedule();
}
