<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Webhook;

use Al5dy\PayKassaWoo\Infrastructure\Logger;
use Al5dy\PayKassaWoo\PayKassa\PayKassaClientFactory;
use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;
use Al5dy\PayKassaWoo\PayKassa\Exception\ProviderUnavailableException;

final class WebhookController
{
    public function handle(): void
    {
        if ('POST' !== strtoupper((string) ( $_SERVER['REQUEST_METHOD'] ?? '' ))) {
            status_header(405);
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
        if (! $this->within_rate_limit()) {
            status_header(429);
            header('Retry-After: 60');
            exit;
        }
        $settings = get_option('woocommerce_paykassa_settings', array());
        try {
            $evidence = ( new PayKassaClientFactory() )->sci(is_array($settings) ? $settings : array())->verify_ipn($hash);
            $result = ( new WebhookProcessor(new WebhookEventStore(), new Logger()) )->process($evidence);
            if ($result['accepted']) {
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

    private function within_rate_limit(): bool
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        $key = 'paykassa_ipn_' . hash('sha256', $ip);
        $count = (int) get_transient($key);
        if ($count >= 60) {
            return false;
        }
        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }
}
