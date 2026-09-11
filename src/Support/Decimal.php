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

    /** Compare two unsigned decimal strings without integer or float conversion. */
    public static function compare(string $left, string $right): ?int
    {
        $left = self::normalise($left);
        $right = self::normalise($right);
        if ('' === $left || '' === $right) {
            return null;
        }
        [$left_whole, $left_fraction] = array_pad(explode('.', $left, 2), 2, '');
        [$right_whole, $right_fraction] = array_pad(explode('.', $right, 2), 2, '');
        if (strlen($left_whole) !== strlen($right_whole)) {
            return strlen($left_whole) <=> strlen($right_whole);
        }
        $whole_comparison = strcmp($left_whole, $right_whole);
        if (0 !== $whole_comparison) {
            return $whole_comparison <=> 0;
        }
        $scale = max(strlen($left_fraction), strlen($right_fraction));
        $fraction_comparison = strcmp(str_pad($left_fraction, $scale, '0'), str_pad($right_fraction, $scale, '0'));
        return $fraction_comparison <=> 0;
    }

    /** Multiply unsigned decimal strings without using binary floating point. */
    public static function multiply(string $left, string $right): string
    {
        $left = self::normalise($left);
        $right = self::normalise($right);
        if ('' === $left || '' === $right) {
            return '';
        }
        $left_parts = explode('.', $left, 2);
        $right_parts = explode('.', $right, 2);
        $left_fraction = $left_parts[1] ?? '';
        $right_fraction = $right_parts[1] ?? '';
        $left_digits = ltrim($left_parts[0] . $left_fraction, '0');
        $right_digits = ltrim($right_parts[0] . $right_fraction, '0');
        if ('' === $left_digits || '' === $right_digits) {
            return '0';
        }
        $digits = array_fill(0, strlen($left_digits) + strlen($right_digits), 0);
        for ($left_index = strlen($left_digits) - 1; $left_index >= 0; --$left_index) {
            for ($right_index = strlen($right_digits) - 1; $right_index >= 0; --$right_index) {
                $digits[$left_index + $right_index + 1] += (int) $left_digits[$left_index] * (int) $right_digits[$right_index];
            }
        }
        for ($index = count($digits) - 1; $index > 0; --$index) {
            $digits[$index - 1] += intdiv($digits[$index], 10);
            $digits[$index] %= 10;
        }
        $product = ltrim(implode('', $digits), '0');
        $scale = strlen($left_fraction) + strlen($right_fraction);
        if (0 === $scale) {
            return '' === $product ? '0' : $product;
        }
        $product = str_pad('' === $product ? '0' : $product, $scale + 1, '0', STR_PAD_LEFT);
        return self::normalise(substr($product, 0, -$scale) . '.' . substr($product, -$scale));
    }
}
