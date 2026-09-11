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
    public const PAYMENT_LINK_HASH = '_paykassa_payment_link_hash';
    public const CREDENTIAL_CONTEXT = '_paykassa_credential_context';
    public const INVOICE_ATTEMPT = '_paykassa_invoice_attempt';
    public const INVOICE_RESOLUTION = '_paykassa_invoice_resolution';
    public const RETIRED_SNAPSHOTS = '_paykassa_retired_snapshots';
}
