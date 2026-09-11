<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\PayKassa;

use Al5dy\PayKassaWoo\Infrastructure\Logger;
use Al5dy\PayKassaWoo\PayKassa\Dto\PaymentEvidence;
use Al5dy\PayKassaWoo\PayKassa\Dto\PaymentResult;
use Al5dy\PayKassaWoo\PayKassa\Dto\TransactionNotificationEvidence;
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

    public function verify_transaction_notification(string $private_hash): TransactionNotificationEvidence
    {
        if ($this->test_mode) {
            throw new WebhookVerificationException('Transaction notifications are available only for retained Live SCI credentials.');
        }
        $response = $this->request(array('func' => 'sci_confirm_transaction_notification', 'private_hash' => $private_hash), WebhookVerificationException::class);
        $data = $response['data'] ?? null;
        if (! is_array($data)) {
            throw new WebhookVerificationException('Transaction verification response has no evidence.');
        }
        foreach (array('order_id', 'transaction', 'shop_id', 'amount', 'fee', 'currency', 'system', 'status') as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key]) || '' === $data[$key] || strlen($data[$key]) > 128) {
                throw new WebhookVerificationException('Transaction verification response has invalid field types.');
            }
        }
        if (! isset($data['txid']) || ! is_string($data['txid']) || '' === $data['txid'] || strlen($data['txid']) > 256) {
            throw new WebhookVerificationException('Transaction verification response has invalid transaction hash.');
        }
        $order_id = filter_var($data['order_id'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        $amount = $data['amount'];
        $fee = $data['fee'];
        $status = $data['status'];
        if (
            false === $order_id
            || ! Decimal::equal($amount, $amount)
            || Decimal::equal($amount, '0')
            || ! Decimal::equal($fee, $fee)
            || Decimal::compare($fee, '0') < 0
            || ! in_array($status, array('no', 'yes'), true)
        ) {
            throw new WebhookVerificationException('Transaction verification response is incomplete or ambiguous.');
        }
        $address_from = self::normalize_optional_address_metadata($data['address_from'] ?? null);
        $address = self::normalize_optional_address_metadata($data['address'] ?? null);
        $tag = self::normalize_optional_address_metadata($data['tag'] ?? null);
        $confirmations = self::normalize_confirmation_count($data['confirmations'] ?? null);
        $required_confirmations = self::normalize_confirmation_count($data['required_confirmations'] ?? null);

        return new TransactionNotificationEvidence(
            (int) $order_id,
            $data['transaction'],
            $data['txid'],
            $data['shop_id'],
            $amount,
            $fee,
            strtoupper($data['currency']),
            $data['system'],
            $address_from,
            $address,
            $tag,
            $confirmations,
            $required_confirmations,
            $status,
            Logger::fingerprint($private_hash)
        );
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

    private static function normalize_confirmation_count(mixed $value): string
    {
        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,18})$/', $value)) {
            return $value;
        }
        throw new WebhookVerificationException('Transaction verification response has invalid confirmation data.');
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
