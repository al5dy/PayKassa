<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\PayKassa\Dto;

final class PaymentResult
{
    public function __construct(
        public readonly string $invoice_id,
        public readonly string $redirect_url,
        public readonly string $system,
        public readonly string $currency,
        public readonly string $payment_link_hash
    ) {
    }
}
