<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Order;

final class OrderMeta
{
    public const SNAPSHOT = '_paykassa_snapshot';
    public const STATE = '_paykassa_payment_state';
    public const TRANSACTION = '_paykassa_transaction_id';
    public const HASH_FINGERPRINT = '_paykassa_hash_fingerprint';
    public const LAST_WEBHOOK = '_paykassa_last_webhook_at';
    public const RECONCILIATION = '_paykassa_reconciliation_at';
}
