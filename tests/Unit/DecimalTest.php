<?php

declare(strict_types=1);

namespace PayKassaWoo\Tests\Unit;

use Al5dy\PayKassaWoo\Support\Decimal;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    public function test_equivalent_decimal_strings_match_without_floats(): void
    {
        self::assertTrue(Decimal::equal('1.00000000', '1'));
        self::assertTrue(Decimal::equal('0.100000000000000001', '0.1000000000000000010'));
        self::assertFalse(Decimal::equal('1.00000001', '1'));
        self::assertFalse(Decimal::equal('1.0', '1e0'));
        self::assertFalse(Decimal::equal('1.0', '-1.0'));
        self::assertFalse(Decimal::equal('abc', 'def'));
        self::assertFalse(Decimal::equal('', ''));
    }

    public function test_multiplies_rate_and_order_amount_without_float_rounding(): void
    {
        self::assertSame('99.843217', Decimal::multiply('100.00', '0.99843217'));
        self::assertSame('0.0000001295', Decimal::multiply('0.01', '0.00001295'));
        self::assertSame('12949999.8705', Decimal::multiply('999999.99', '12.95'));
    }

    public function test_compares_decimal_strings_without_float_or_integer_overflow(): void
    {
        self::assertSame(0, Decimal::compare('5.000000', '5'));
        self::assertSame(-1, Decimal::compare('0.999999999999999999', '1'));
        self::assertSame(1, Decimal::compare('100000000000000000000', '99999999999999999999.99'));
        self::assertNull(Decimal::compare('1e3', '1000'));
    }
}
