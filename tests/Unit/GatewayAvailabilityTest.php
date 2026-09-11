<?php

declare(strict_types=1);

namespace PayKassaWoo\Tests\Unit;

use Al5dy\PayKassaWoo\Gateway\GatewayAvailability;
use PHPUnit\Framework\TestCase;

final class GatewayAvailabilityTest extends TestCase
{
    public function test_enabled_usd_and_usdt_trc20_are_available_as_separate_order_and_payment_currencies(): void
    {
        $settings = array(
            'enabled' => 'yes', 'shop_id' => 'merchant', 'shop_password' => 'secret',
            'accepted_order_currencies' => array('USD'),
            'enabled_payment_directions' => array('tron_trc20:USDT'),
        );
        $availability = new GatewayAvailability();
        self::assertTrue($availability->for_currency('USD', $settings));
        self::assertArrayHasKey('tron_trc20:USDT', $availability->directions_for_order_currency('USD', $settings));
        self::assertFalse($availability->for_currency('BYN', $settings));
    }
}
