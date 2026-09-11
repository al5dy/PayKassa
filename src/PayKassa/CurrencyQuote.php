<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\PayKassa;

use Al5dy\PayKassaWoo\PayKassa\Exception\PaymentCreationException;
use Al5dy\PayKassaWoo\Support\Decimal;

/** Immutable PayKassa conversion agreement used for one SCI invoice. */
final class CurrencyQuote
{
    public function __construct(
        public readonly string $order_amount,
        public readonly string $order_currency,
        public readonly string $payment_amount,
        public readonly string $payment_currency,
        public readonly string $payment_system,
        public readonly string $exchange_rate,
        public readonly string $rate_pair,
        public readonly string $quoted_at,
        public readonly string $source
    ) {
        if ('' === Decimal::normalise($order_amount) || '' === Decimal::normalise($payment_amount) || '' === Decimal::normalise($exchange_rate) || Decimal::equal($payment_amount, '0')) {
            throw new PaymentCreationException('PayKassa returned an invalid payment quote.');
        }
    }
}
