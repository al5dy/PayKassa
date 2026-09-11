<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\PayKassa\Dto;

/** Immutable facts returned by sci_confirm_transaction_notification. */
final class TransactionNotificationEvidence
{
    public function __construct(
        public readonly int $order_id,
        public readonly string $transaction_id,
        public readonly string $txid,
        public readonly string $shop_id,
        public readonly string $amount,
        public readonly string $fee,
        public readonly string $currency,
        public readonly string $system,
        public readonly string $address_from,
        public readonly string $address,
        public readonly string $tag,
        public readonly string $confirmations,
        public readonly string $required_confirmations,
        public readonly string $status,
        public readonly string $hash_fingerprint,
        public readonly string $environment = 'live'
    ) {
    }

    public function is_credited(): bool
    {
        return 'yes' === $this->status;
    }
}
