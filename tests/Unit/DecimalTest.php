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
    }
}
