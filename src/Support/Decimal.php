<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Support;

/** Decimal-string comparison without a float conversion. */
final class Decimal
{
    public static function equal(string $left, string $right): bool
    {
        $normal_left = self::normalise($left);
        $normal_right = self::normalise($right);
        return '' !== $normal_left && '' !== $normal_right && $normal_left === $normal_right;
    }

    public static function normalise(string $amount): string
    {
        $amount = trim($amount);
        if (! preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $amount)) {
            return '';
        }
        $parts = explode('.', $amount, 2);
        $whole = ltrim($parts[0], '0');
        $whole = '' === $whole ? '0' : $whole;
        $fraction = isset($parts[1]) ? rtrim($parts[1], '0') : '';
        return '' === $fraction ? $whole : $whole . '.' . $fraction;
    }
}
