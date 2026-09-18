<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\PayKassa\Exception;

/** PayKassa rejected the request because this server's outbound IP is missing from the shop's API settings whitelist. */
final class IpNotAllowlistedException extends PayKassaException
{
    public function __construct(public readonly ?string $ip)
    {
        parent::__construct("PayKassa rejected the request: this server's IP is not in the API whitelist.");
    }
}
