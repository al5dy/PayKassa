<?php

declare(strict_types=1);

namespace PayKassaWoo\Tests\Unit;

use Al5dy\PayKassaWoo\Order\PaymentSnapshot;
use PHPUnit\Framework\TestCase;

final class PaymentSnapshotTest extends TestCase
{
    public function test_snapshot_round_trip_preserves_internal_order_id(): void
    {
        $snapshot = new PaymentSnapshot(123, '1.00000000', 'BTC', 'BitCoin', 'BTC', 'invoice-1', '2026-01-01T00:00:00+00:00', false);
        $decoded = PaymentSnapshot::from_json((string) json_encode($snapshot->to_array()));
        self::assertInstanceOf(PaymentSnapshot::class, $decoded);
        self::assertSame(123, $decoded->order_id);
    }

    public function test_snapshot_rejects_incomplete_or_malformed_data(): void
    {
        self::assertNull(PaymentSnapshot::from_json('{'));
        self::assertNull(PaymentSnapshot::from_json('{"order_id":0}'));
    }

    public function test_expiration_and_environment_are_immutable(): void
    {
        $snapshot = new PaymentSnapshot(123, '10.00', 'USD', 'BitCoin', 'BTC', 'link-hash', '2026-01-01T00:00:00+00:00', true, 'hosted', 'context', '2026-01-01T01:00:00+00:00', 'merchant-7');
        self::assertSame('test', $snapshot->environment());
        self::assertFalse($snapshot->is_expired(strtotime('2026-01-01T00:59:59+00:00')));
        self::assertTrue($snapshot->is_expired(strtotime('2026-01-01T01:00:00+00:00')));
        self::assertSame('merchant-7', PaymentSnapshot::from_json((string) json_encode($snapshot->to_array()))->merchant_shop_id);
    }

    public function test_fiat_order_and_crypto_payment_are_stored_independently(): void
    {
        $snapshot = new PaymentSnapshot(123, '100.00', 'USD', 'TRON_TRC20', 'USDT', 'link-hash', '2026-01-01T00:00:00+00:00', false, 'hosted', 'context', '', 'merchant-7', '99.843217', 'USD_USDT', '0.99843217', 'paykassa_currency_pairs_v1', '2026-01-01T00:00:00+00:00');
        $decoded = PaymentSnapshot::from_json((string) json_encode($snapshot->to_array()));
        self::assertInstanceOf(PaymentSnapshot::class, $decoded);
        self::assertSame('100.00', $decoded->expected_amount);
        self::assertSame('USD', $decoded->order_currency);
        self::assertSame('99.843217', $decoded->payment_amount);
        self::assertSame('USDT', $decoded->provider_currency);
        self::assertSame('TRON_TRC20', $decoded->provider_system);
        self::assertSame('USD_USDT', $decoded->conversion_pair);
    }

    public function test_unknown_provider_expiration_is_explicit_and_legacy_hash_stays_stable(): void
    {
        $snapshot = new PaymentSnapshot(123, '100.00', 'USD', 'TRON_TRC20', 'USDT', 'link-hash', '2026-01-01T00:00:00+00:00', true, 'hosted', 'context', null, 'merchant-7', '99.843217');
        self::assertFalse($snapshot->expiration_is_known());
        self::assertFalse($snapshot->is_expired(strtotime('2036-01-01T00:00:00+00:00')));

        $legacy = $snapshot->to_array();
        $legacy['expires_at'] = '';
        $decoded = PaymentSnapshot::from_json((string) json_encode($legacy));
        self::assertInstanceOf(PaymentSnapshot::class, $decoded);
        self::assertNull($decoded->expires_at);
        self::assertSame(hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR)), $decoded->fingerprint());
    }
}
