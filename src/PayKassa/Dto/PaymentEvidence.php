<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\PayKassa\Dto;

final class PaymentEvidence
{
    public function __construct(
        public readonly int $order_id,
        public readonly string $transaction_id,
        public readonly string $hash_fingerprint,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $system,
        public readonly string $address = '',
        public readonly string $tag = '',
        public readonly string $shop_id = '',
        public readonly string $payment_link_hash = ''
    ) {
    }
}
