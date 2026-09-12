<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Webhook;

use Al5dy\PayKassaWoo\Infrastructure\Logger;
use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;
use Al5dy\PayKassaWoo\PayKassa\Exception\ProviderUnavailableException;

final class WebhookController
{
    public function __construct(private readonly WebhookRateLimitPolicy $rate_limit = new WebhookRateLimitPolicy())
    {
    }

    public function handle(): void
    {
        if ('POST' !== strtoupper((string) ( $_SERVER['REQUEST_METHOD'] ?? '' ))) {
            status_header(405);
            header('Allow: POST');
            exit;
        }
        // CONTENT_LENGTH is client supplied. Enforce the cap against bytes read
        // as well, before parsing or making the comparatively expensive SCI call.
        $body = file_get_contents('php://input', false, null, 0, 16385);
        if (false === $body || strlen($body) > 16384 || (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 16384) {
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
        if (! $this->rate_limit->allows(WebhookRateLimitPolicy::INVOICE_CHANNEL, $hash)) {
            status_header(429);
            header('Retry-After: 60');
            exit;
        }
        try {
            // Raw order_id selects only an immutable credential context. It is
            // not payment evidence and must match the provider-verified ID.
            $evidence = ( new WebhookCredentialResolver() )->verify($hash, (int) $routing_order_id);
            $result = ( new WebhookProcessor(new WebhookEventStore(), new Logger()) )->process($evidence, EvidenceSource::WEBHOOK_INVOICE);
            if ($result['accepted']) {
                status_header(200);
                header('Content-Type: text/plain; charset=utf-8');
                echo $result['ack']; // PayKassa SCI's documented acknowledgement format.
                exit;
            }
        } catch (ProviderUnavailableException $exception) {
            ( new Logger() )->log('warning', 'webhook_provider_unavailable', array( 'error_code' => Logger::fingerprint($exception->getMessage()), 'request_id' => Logger::fingerprint($hash) ));
            status_header(503);
            exit;
        } catch (PayKassaException $exception) {
            ( new Logger() )->log('warning', 'webhook_rejected', array( 'error_code' => Logger::fingerprint($exception->getMessage()), 'request_id' => Logger::fingerprint($hash) ));
        }
        // A false processor result is intentionally retryable: the event store,
        // WooCommerce, or an in-flight lease may need another delivery.
        status_header(503);
        exit;
    }
}
