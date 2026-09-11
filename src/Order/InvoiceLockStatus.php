<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Order;

/** Durable lifecycle states for a single order's hosted PayKassa invoice. */
final class InvoiceLockStatus
{
    /** No provider side effect can have happened yet. */
    public const PREPARING = 'preparing';

    /** The SCI create request may have reached PayKassa. */
    public const CREATING = 'creating';

    /** A provider success response and immutable snapshot were persisted. */
    public const CREATED = 'created';

    /** The provider outcome is unknown and automatic retry is forbidden. */
    public const UNCERTAIN = 'uncertain';

    /** The create was definitively rejected before an invoice could be used. */
    public const FAILED = 'failed';

    /** A known invoice was explicitly retired and may not be reused. */
    public const EXPIRED = 'expired';

    private function __construct()
    {
    }
}
