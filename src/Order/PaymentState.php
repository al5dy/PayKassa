<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Order;

final class PaymentState
{
    public const INVOICE_CREATED = 'invoice_created';
    public const AWAITING_PAYMENT = 'awaiting_payment';
    public const PAID = 'paid';
    public const CONFLICTED = 'conflicted';
    public const MANUAL_REVIEW = 'manual_review';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = array(
        self::INVOICE_CREATED => array( self::AWAITING_PAYMENT, self::CONFLICTED, self::MANUAL_REVIEW ),
        self::AWAITING_PAYMENT => array( self::PAID, self::CONFLICTED, self::MANUAL_REVIEW ),
        self::MANUAL_REVIEW => array( self::PAID ),
        self::PAID => array(),
        self::CONFLICTED => array(),
    );

    public static function can_transition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[ $from ] ?? array(), true);
    }
}
