<?php

declare(strict_types=1);

namespace PayKassaWoo\Tests\Unit;

use Al5dy\PayKassaWoo\Order\PaymentState;
use PHPUnit\Framework\TestCase;

final class PaymentStateTest extends TestCase
{
    public function test_paid_state_cannot_regress(): void
    {
        self::assertFalse(PaymentState::can_transition(PaymentState::PAID, PaymentState::AWAITING_PAYMENT));
        self::assertTrue(PaymentState::can_transition(PaymentState::AWAITING_PAYMENT, PaymentState::PAID));
    }
}
