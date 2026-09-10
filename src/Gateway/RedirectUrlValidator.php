<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Gateway;

final class RedirectUrlValidator
{
    public static function is_valid(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || 'https' !== strtolower((string) ( $parts['scheme'] ?? '' )) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower((string) ( $parts['host'] ?? '' ));
        return 'paykassa.app' === $host || 'paykassa.pro' === $host || str_ends_with($host, '.paykassa.app') || str_ends_with($host, '.paykassa.pro');
    }
}
