<?php

declare(strict_types=1);

namespace PayKassaWoo\Tests\Unit;

use Al5dy\PayKassaWoo\Gateway\MinimumPaymentPolicy;
use Al5dy\PayKassaWoo\PayKassa\Exception\PaymentCreationException;
use PHPUnit\Framework\TestCase;

final class MinimumPaymentPolicyTest extends TestCase
{
    public function test_empty_rules_are_disabled_for_backward_compatibility(): void
    {
        $policy = new MinimumPaymentPolicy();
        $policy->assert_allows('0.000001', 'Ethereum_ERC20', 'USDT', array());

        self::assertNull($policy->minimum('Ethereum_ERC20', 'USDT', array()));
    }

    public function test_rules_are_scoped_to_exact_network_and_currency(): void
    {
        $settings = array('minimum_payment_directions' => "Ethereum_ERC20:USDT=5\nTRON_TRC20:USDT=1");
        $policy = new MinimumPaymentPolicy();

        self::assertSame('5', $policy->minimum('Ethereum_ERC20', 'USDT', $settings));
        self::assertSame('1', $policy->minimum('TRON_TRC20', 'USDT', $settings));
        $policy->assert_allows('1', 'TRON_TRC20', 'USDT', $settings);
        $this->expectException(PaymentCreationException::class);
        $policy->assert_allows('1', 'Ethereum_ERC20', 'USDT', $settings);
    }

    public function test_rules_are_validated_and_canonically_normalized(): void
    {
        $policy = new MinimumPaymentPolicy();

        self::assertSame(
            "TRON_TRC20:USDT=2\nEthereum_ERC20:USDT=5",
            $policy->normalize_rules("ethereum_erc20:usdt=5.000\nTRON_TRC20:USDT=2.0")
        );
    }

    public function test_unknown_or_duplicated_directions_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MinimumPaymentPolicy())->normalize_rules("Unknown_Network:USDT=5\nUnknown_Network:USDT=6");
    }
}
