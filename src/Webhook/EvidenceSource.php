<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Webhook;

final class EvidenceSource
{
    public const WEBHOOK_INVOICE = 'webhook_invoice';
    public const WEBHOOK_TRANSACTION = 'webhook_transaction';
    public const RECONCILIATION = 'reconciliation';
}
