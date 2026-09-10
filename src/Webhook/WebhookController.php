<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Webhook;

use Al5dy\PayKassaWoo\Infrastructure\Logger;
use Al5dy\PayKassaWoo\PayKassa\PayKassaClientFactory;
use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;

final class WebhookController
{
    public function handle(): void
    {
        if ('POST' !== strtoupper((string) ( $_SERVER['REQUEST_METHOD'] ?? '' )) || (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 16384) {
            status_header(405);
            exit;
        }
        $hash = isset($_POST['private_hash']) && is_string($_POST['private_hash']) ? $_POST['private_hash'] : '';
        if (! preg_match('/^[A-Za-z0-9_-]{16,512}$/', $hash)) {
            status_header(400);
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
        } catch (PayKassaException $exception) {
            ( new Logger() )->log('warning', 'webhook_rejected', array( 'error_code' => Logger::fingerprint($exception->getMessage()), 'request_id' => Logger::fingerprint($hash) ));
        }
        status_header(400);
        exit;
    }
}
