<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\PayKassa;

use Al5dy\PayKassaWoo\Infrastructure\Logger;
use Al5dy\PayKassaWoo\PayKassa\Dto\PaymentEvidence;
use Al5dy\PayKassaWoo\PayKassa\Dto\PaymentResult;
use Al5dy\PayKassaWoo\PayKassa\Exception\ConfigurationException;
use Al5dy\PayKassaWoo\PayKassa\Exception\InvalidResponseException;
use Al5dy\PayKassaWoo\PayKassa\Exception\PaymentCreationException;
use Al5dy\PayKassaWoo\PayKassa\Exception\ProviderUnavailableException;
use Al5dy\PayKassaWoo\PayKassa\Exception\WebhookVerificationException;
use Al5dy\PayKassaWoo\Support\Decimal;

/** Adapter for the documented PayKassa SCI 0.4 contract, using WP's TLS transport. */
final class SciClient
{
    private const ENDPOINT = 'https://paykassa.app/sci/0.4/index.php';

    public function __construct(
        private readonly string $shop_id,
        private readonly string $shop_password,
        private readonly bool $test_mode,
        private readonly Logger $logger
    ) {
        if ('' === $shop_id || '' === $shop_password) {
            throw new ConfigurationException('Merchant SCI credentials are required.');
        }
    }

    public function create_payment(string $amount, string $system_key, string $currency, int $order_id, string $comment): PaymentResult
    {
        $system = ( new PaymentSystemRegistry() )->get($system_key);
        if (! is_array($system) || ! in_array($currency, $system['currencies'], true)) {
            throw new PaymentCreationException('Unsupported payment direction.');
        }
        $response = $this->request(array(
            'func' => 'sci_create_order', 'amount' => $amount, 'system' => (string) $system['id'],
            'currency' => $currency, 'order_id' => (string) $order_id, 'comment' => $comment,
        ), PaymentCreationException::class);
        $data = $response['data'] ?? null;
        if (! is_array($data) || ! isset($data['url']) || ! is_string($data['url'])) {
            throw new InvalidResponseException('Payment creation response does not contain a URL.');
        }
        $hash = '';
        if (isset($data['params']) && is_array($data['params']) && isset($data['params']['hash']) && is_string($data['params']['hash'])) {
            $hash = $data['params']['hash'];
        }
        if ('' === $hash) {
            $url_parts = wp_parse_url($data['url']);
            if (is_array($url_parts) && isset($url_parts['query'])) {
                parse_str($url_parts['query'], $query);
                $hash = isset($query['hash']) && is_string($query['hash']) ? $query['hash'] : '';
            }
        }
        if ('' === $hash || ! preg_match('/^[a-f0-9]{32,128}$/i', $hash)) {
            throw new InvalidResponseException('Payment creation response does not contain a valid payment-link hash.');
        }
        return new PaymentResult($hash, $data['url'], (string) $system['system'], $currency, $hash);
    }

    public function verify_ipn(string $private_hash): PaymentEvidence
    {
        $response = $this->request(array( 'func' => 'sci_confirm_order', 'private_hash' => $private_hash ), WebhookVerificationException::class);
        $data = $response['data'] ?? null;
        if (! is_array($data)) {
            throw new WebhookVerificationException('Verification response has no evidence.');
        }
        foreach (array('order_id', 'transaction', 'shop_id', 'amount', 'currency', 'system', 'hash') as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key]) || '' === $data[$key] || strlen($data[$key]) > 128) {
                // JSON numbers can lose crypto precision. Financial evidence
                // must use the documented string representation.
                throw new WebhookVerificationException('Verification response has invalid field types.');
            }
        }
        $address = self::normalize_optional_address_metadata($data['address'] ?? null);
        $tag = self::normalize_optional_address_metadata($data['tag'] ?? null);
        $order_id = filter_var($data['order_id'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ));
        $transaction = isset($data['transaction']) ? (string) $data['transaction'] : '';
        $amount = isset($data['amount']) && is_string($data['amount']) ? $data['amount'] : '';
        $currency = isset($data['currency']) ? strtoupper((string) $data['currency']) : '';
        $system = isset($data['system']) ? (string) $data['system'] : '';
        $shop_id = isset($data['shop_id']) ? (string) $data['shop_id'] : '';
        $payment_link_hash = isset($data['hash']) ? (string) $data['hash'] : '';
        if (false === $order_id || '' === $transaction || '' === $amount || '' === $currency || '' === $system || '' === $shop_id || '' === $payment_link_hash) {
            throw new WebhookVerificationException('Verification response is incomplete.');
        }
        if (! Decimal::equal($amount, $amount) || Decimal::equal($amount, '0') || 'no' !== ($data['partial'] ?? null)) {
            throw new WebhookVerificationException('Partial or ambiguous payment evidence cannot settle automatically.');
        }
        return new PaymentEvidence((int) $order_id, $transaction, Logger::fingerprint($private_hash), $amount, $currency, $system, $address, $tag, $shop_id, $payment_link_hash, $this->test_mode ? 'test' : 'live');
    }

    private static function normalize_optional_address_metadata(mixed $value): string
    {
        if (false === $value || null === $value) {
            return '';
        }
        if (! is_string($value) || strlen($value) > 256) {
            throw new WebhookVerificationException('Verification response has invalid address data.');
        }
        return $value;
    }

    /** @param array<string, string> $payload @return array<string, mixed> */
    private function request(array $payload, string $provider_error_class): array
    {
        $payload += array( 'sci_id' => $this->shop_id, 'sci_key' => $this->shop_password, 'test' => $this->test_mode ? '1' : '0', 'domain' => '' );
        $response = wp_remote_post(self::ENDPOINT, array( 'timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'body' => $payload ));
        if (is_wp_error($response)) {
            $this->logger->log('warning', 'provider_request_failed', array( 'error_code' => $response->get_error_code() ));
            throw new ProviderUnavailableException('PayKassa is temporarily unavailable.');
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code > 299) {
            throw new ProviderUnavailableException('PayKassa returned an unavailable response.');
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true, 32, JSON_BIGINT_AS_STRING);
        if (! is_array($decoded) || ! isset($decoded['error'], $decoded['message']) || ! is_bool($decoded['error'])) {
            throw new InvalidResponseException('PayKassa returned malformed data.');
        }
        if (true === $decoded['error']) {
            if (PaymentCreationException::class === $provider_error_class) {
                throw new PaymentCreationException('PayKassa rejected the payment request.');
            }
            throw new WebhookVerificationException('PayKassa rejected the verification request.');
        }
        return $decoded;
    }
}
