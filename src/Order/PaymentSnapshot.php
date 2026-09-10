<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Order;

use InvalidArgumentException;

/** Immutable provider/payment agreement stored on the WooCommerce order. */
final class PaymentSnapshot
{
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
        public readonly string $expires_at = '',
        public readonly string $merchant_shop_id = ''
    ) {
        if ($order_id < 1 || '' === $expected_amount || '' === $order_currency || '' === $provider_system || '' === $provider_currency || '' === $provider_invoice_id) {
            throw new InvalidArgumentException('A payment snapshot is incomplete.');
        }
    }

    public function environment(): string
    {
        return $this->test_mode ? 'test' : 'live';
    }

    public function is_expired(?int $now = null): bool
    {
        if ('' === $this->expires_at) {
            return false;
        }
        $timestamp = strtotime($this->expires_at);
        return false !== $timestamp && $timestamp <= ( $now ?? time() );
    }

    /** @return array<string, int|string|bool> */
    public function to_array(): array
    {
        return array(
            'order_id' => $this->order_id, 'expected_amount' => $this->expected_amount,
            'order_currency' => $this->order_currency, 'provider_system' => $this->provider_system,
            'provider_currency' => $this->provider_currency, 'provider_invoice_id' => $this->provider_invoice_id,
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
        try {
            return new self((int) ( $data['order_id'] ?? 0 ), (string) ( $data['expected_amount'] ?? '' ), (string) ( $data['order_currency'] ?? '' ), (string) ( $data['provider_system'] ?? '' ), (string) ( $data['provider_currency'] ?? '' ), (string) ( $data['provider_invoice_id'] ?? '' ), (string) ( $data['created_at'] ?? '' ), (bool) ( $data['test_mode'] ?? false ), (string) ( $data['mode'] ?? 'hosted' ), (string) ( $data['merchant_context'] ?? '' ), (string) ( $data['expires_at'] ?? '' ), (string) ( $data['merchant_shop_id'] ?? '' ));
        } catch (InvalidArgumentException $exception) {
            return null;
        }
    }
}
