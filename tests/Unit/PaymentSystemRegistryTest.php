<?php

declare(strict_types=1);

namespace PayKassaWoo\Tests\Unit;

use Al5dy\PayKassaWoo\PayKassa\PaymentSystemRegistry;
use PHPUnit\Framework\TestCase;

final class PaymentSystemRegistryTest extends TestCase
{
    public function test_current_official_crypto_directions_include_multinetwork_usdt(): void
    {
        $registry = new PaymentSystemRegistry();
        self::assertTrue($registry->supports_currency('tron_trc20', 'USDT'));
        self::assertTrue($registry->supports_currency('ton', 'USDT'));
        self::assertFalse($registry->supports_currency('bitcoin', 'USDT'));
    }
}
