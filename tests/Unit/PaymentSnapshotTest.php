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
}
