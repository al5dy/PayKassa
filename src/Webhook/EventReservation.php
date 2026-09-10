<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Webhook;

final class EventReservation
{
    public const ACQUIRED = 'acquired';
    public const DUPLICATE = 'duplicate';
    public const ERROR = 'error';

    public function __construct(public readonly string $status, public readonly string $event_key)
    {
    }

    public function acquired(): bool
    {
        return self::ACQUIRED === $this->status;
    }
}
