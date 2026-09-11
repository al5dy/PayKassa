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

    public function test_all_declared_transitions_and_rejections_are_explicit(): void
    {
        self::assertTrue(PaymentState::can_transition(PaymentState::INVOICE_CREATING, PaymentState::INVOICE_CREATED));
        self::assertTrue(PaymentState::can_transition(PaymentState::INVOICE_CREATING, PaymentState::INVOICE_UNCERTAIN));
        self::assertTrue(PaymentState::can_transition(PaymentState::INVOICE_UNCERTAIN, PaymentState::INVOICE_FAILED));
        self::assertTrue(PaymentState::can_transition(PaymentState::INVOICE_FAILED, PaymentState::INVOICE_CREATING));
        self::assertTrue(PaymentState::can_transition(PaymentState::INVOICE_CREATED, PaymentState::AWAITING_PAYMENT));
        self::assertTrue(PaymentState::can_transition(PaymentState::AWAITING_PAYMENT, PaymentState::EXPIRED));
        self::assertTrue(PaymentState::can_transition(PaymentState::EXPIRED, PaymentState::INVOICE_CREATED));
        self::assertFalse(PaymentState::can_transition(PaymentState::EXPIRED, PaymentState::PAID));
        self::expectException(\LogicException::class);
        PaymentState::assert_transition(PaymentState::PAID, PaymentState::MANUAL_REVIEW);
    }
}
