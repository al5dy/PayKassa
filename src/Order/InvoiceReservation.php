<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Order;

/** Durable, database-backed lock for a single order's hosted invoice creation. */
final class InvoiceReservation
{
    public const ACQUIRED = 'acquired';
    public const BUSY = 'busy';
    public const ERROR = 'error';

    public function __construct(public readonly string $status, public readonly string $owner_token = '')
    {
    }

    public function acquired(): bool
    {
        return self::ACQUIRED === $this->status;
    }
}
