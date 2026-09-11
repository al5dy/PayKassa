<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Webhook;

use Al5dy\PayKassaWoo\Infrastructure\Logger;
use Al5dy\PayKassaWoo\PayKassa\Dto\TransactionNotificationEvidence;
use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;
use Al5dy\PayKassaWoo\PayKassa\Exception\ProviderUnavailableException;

final class TransactionNotificationController
{
    private readonly WebhookProcessor $processor;
    private readonly Logger $logger;

    public function __construct(?WebhookProcessor $processor = null, ?Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
        $this->processor = $processor ?? new WebhookProcessor(new WebhookEventStore(), $this->logger);
    }

    public function handle(): void
    {
        if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) {
            status_header(405);
            header('Allow: POST');
            exit;
        }
        $body = file_get_contents('php://input', false, null, 0, 16385);
        if (false === $body || strlen($body) > 16384 || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
            status_header(413);
            exit;
        }
        parse_str($body, $payload);
        $hash = isset($payload['private_hash']) && is_string($payload['private_hash']) ? $payload['private_hash'] : '';
        if (! preg_match('/^[A-Za-z0-9_-]{16,512}$/', $hash)) {
            status_header(400);
            exit;
        }
        $routing_order_raw = isset($payload['order_id']) && is_string($payload['order_id']) ? $payload['order_id'] : '';
        $routing_order_id = preg_match('/^[1-9][0-9]{0,18}$/', $routing_order_raw)
            ? filter_var($routing_order_raw, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)))
            : false;
        if (false === $routing_order_id) {
            status_header(400);
            exit;
        }
        if (! $this->within_rate_limit()) {
            status_header(429);
            header('Retry-After: 60');
            exit;
        }

        try {
            $evidence = (new WebhookCredentialResolver())->verify_transaction_notification($hash, (int) $routing_order_id);
            $result = $this->process_verified($evidence);
            if ($result['accepted']) {
                $this->acknowledge($evidence->order_id);
            }
        } catch (ProviderUnavailableException $exception) {
            (new Logger())->log('warning', 'transaction_notification_provider_unavailable', array(
                'error_code' => Logger::fingerprint($exception->getMessage()),
                'request_id' => Logger::fingerprint($hash),
            ));
            status_header(503);
            exit;
        } catch (PayKassaException $exception) {
            (new Logger())->log('warning', 'transaction_notification_rejected', array(
                'error_code' => Logger::fingerprint($exception->getMessage()),
                'request_id' => Logger::fingerprint($hash),
            ));
        }
        status_header(503);
        exit;
    }

    /** @return array{accepted:bool,ack:string,outcome:string} */
    public function process_verified(TransactionNotificationEvidence $evidence): array
    {
        if (! $evidence->is_credited()) {
            $this->logger->log('info', 'transaction_notification_pending', array(
                'order_id' => $evidence->order_id,
                'transaction_id' => $evidence->transaction_id,
                'confirmations' => $evidence->confirmations,
                'required_confirmations' => $evidence->required_confirmations,
            ));
            return array('accepted' => true, 'ack' => $evidence->order_id . '|success', 'outcome' => 'pending');
        }
        $result = $this->processor->process($evidence, EvidenceSource::WEBHOOK_TRANSACTION);
        if ($result['accepted']) {
            $this->logger->log('info', 'transaction_notification_confirmed', array(
                'order_id' => $evidence->order_id,
                'transaction_id' => $evidence->transaction_id,
                'outcome' => $result['outcome'],
            ));
        }
        return $result;
    }

    private function acknowledge(int $order_id): never
    {
        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo $order_id . '|success';
        exit;
    }

    private function within_rate_limit(): bool
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        $key = 'paykassa_transaction_ipn_' . hash('sha256', $ip);
        $count = (int) get_transient($key);
        if ($count >= 60) {
            return false;
        }
        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }
}
