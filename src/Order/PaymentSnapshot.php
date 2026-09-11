<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Order;

use InvalidArgumentException;

/** Immutable provider/payment agreement stored on the WooCommerce order. */
final class PaymentSnapshot
{
    public readonly string $payment_amount;
    public readonly string $conversion_pair;
    public readonly string $conversion_rate;
    public readonly string $conversion_source;
    public readonly string $conversion_quoted_at;

    public function __construct(
        public readonly int $order_id,
        public readonly string $expected_amount,
        public readonly string $order_currency,
        public readonly string $provider_system,
        public readonly string $provider_currency,
        public readonly string $provider_invoice_id,
        public readonly string $created_at,
        public readonly bool $test_mode,
        public readonly string $mode = 'hosted',
        public readonly string $merchant_context = '',
        public readonly ?string $expires_at = null,
        public readonly string $merchant_shop_id = '',
        string $payment_amount = '',
        string $conversion_pair = '',
        string $conversion_rate = '',
        string $conversion_source = '',
        string $conversion_quoted_at = ''
    ) {
        if ($order_id < 1 || '' === $expected_amount || '' === $order_currency || '' === $provider_system || '' === $provider_currency || '' === $provider_invoice_id) {
            throw new InvalidArgumentException('A payment snapshot is incomplete.');
        }
        // Legacy SCI snapshots were necessarily same-currency invoices. Keep
        // them verifiable without fabricating a historical FX conversion.
        $this->payment_amount = '' === $payment_amount ? $expected_amount : $payment_amount;
        $this->conversion_pair = $conversion_pair;
        $this->conversion_rate = $conversion_rate;
        $this->conversion_source = $conversion_source;
        $this->conversion_quoted_at = $conversion_quoted_at;
    }

    public function environment(): string
    {
        return $this->test_mode ? 'test' : 'live';
    }

    public function is_expired(?int $now = null): bool
    {
        if (null === $this->expires_at) {
            return false;
        }
        $timestamp = strtotime($this->expires_at);
        return false !== $timestamp && $timestamp <= ( $now ?? time() );
    }

    public function expiration_is_known(): bool
    {
        return null !== $this->expires_at && false !== strtotime($this->expires_at);
    }

    public function fingerprint(): string
    {
        $data = $this->to_array();
        // Schema-v3 locks were hashed while an unknown expiration was encoded
        // as an empty string. Preserve that identity across the nullable-field
        // migration so existing active invoices remain reusable after upgrade.
        $data['expires_at'] = $data['expires_at'] ?? '';
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, int|string|bool|null> */
    public function to_array(): array
    {
        return array(
            'order_id' => $this->order_id, 'expected_amount' => $this->expected_amount, 'order_amount' => $this->expected_amount,
            'order_currency' => $this->order_currency, 'provider_system' => $this->provider_system,
            'provider_currency' => $this->provider_currency, 'provider_invoice_id' => $this->provider_invoice_id,
            'payment_amount' => $this->payment_amount, 'payment_currency' => $this->provider_currency,
            'payment_system' => $this->provider_system, 'conversion_pair' => $this->conversion_pair,
            'conversion_rate' => $this->conversion_rate, 'conversion_source' => $this->conversion_source,
            'conversion_quoted_at' => $this->conversion_quoted_at,
            'created_at' => $this->created_at, 'test_mode' => $this->test_mode, 'mode' => $this->mode,
            'merchant_context' => $this->merchant_context, 'expires_at' => $this->expires_at,
            'merchant_shop_id' => $this->merchant_shop_id,
        );
    }

    public static function from_json(string $json): ?self
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }
        return self::from_array($data);
    }

    /** @param array<string, mixed> $data */
    public static function from_array(array $data): ?self
    {
        try {
            $expires_at = isset($data['expires_at']) && is_string($data['expires_at']) && '' !== $data['expires_at'] ? $data['expires_at'] : null;
            return new self((int) ( $data['order_id'] ?? 0 ), (string) ( $data['order_amount'] ?? $data['expected_amount'] ?? '' ), (string) ( $data['order_currency'] ?? '' ), (string) ( $data['payment_system'] ?? $data['provider_system'] ?? '' ), (string) ( $data['payment_currency'] ?? $data['provider_currency'] ?? '' ), (string) ( $data['provider_invoice_id'] ?? '' ), (string) ( $data['created_at'] ?? '' ), (bool) ( $data['test_mode'] ?? false ), (string) ( $data['mode'] ?? 'hosted' ), (string) ( $data['merchant_context'] ?? '' ), $expires_at, (string) ( $data['merchant_shop_id'] ?? '' ), (string) ( $data['payment_amount'] ?? $data['order_amount'] ?? $data['expected_amount'] ?? '' ), (string) ( $data['conversion_pair'] ?? '' ), (string) ( $data['conversion_rate'] ?? '' ), (string) ( $data['conversion_source'] ?? '' ), (string) ( $data['conversion_quoted_at'] ?? '' ));
        } catch (InvalidArgumentException $exception) {
            return null;
        }
    }
}
