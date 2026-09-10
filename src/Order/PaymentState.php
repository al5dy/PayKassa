<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Order;

final class PaymentState
{
    public const INVOICE_CREATED = 'invoice_created';
    public const AWAITING_PAYMENT = 'awaiting_payment';
    public const EXPIRED = 'expired';
    public const PAID = 'paid';
    public const CONFLICTED = 'conflicted';
    public const MANUAL_REVIEW = 'manual_review';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = array(
        self::INVOICE_CREATED => array( self::AWAITING_PAYMENT, self::CONFLICTED, self::MANUAL_REVIEW ),
        self::AWAITING_PAYMENT => array( self::PAID, self::CONFLICTED, self::MANUAL_REVIEW, self::EXPIRED ),
        self::EXPIRED => array( self::INVOICE_CREATED, self::MANUAL_REVIEW ),
        self::MANUAL_REVIEW => array( self::PAID ),
        self::PAID => array(),
        self::CONFLICTED => array(),
    );

    public static function can_transition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[ $from ] ?? array(), true);
    }

    public static function assert_transition(string $from, string $to): void
    {
        // Pre-2.0 orders have no internal state. Their first persisted state is
        // a migration boundary, rather than a state-machine transition.
        if ('' === $from) {
            return;
        }
        if (! self::can_transition($from, $to)) {
            throw new \LogicException(sprintf('Invalid PayKassa payment state transition: %s -> %s', $from, $to));
        }
    }
}
