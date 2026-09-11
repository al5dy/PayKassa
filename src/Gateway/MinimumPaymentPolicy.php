<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Gateway;

use Al5dy\PayKassaWoo\PayKassa\Exception\PaymentCreationException;
use Al5dy\PayKassaWoo\PayKassa\PaymentSystemRegistry;
use Al5dy\PayKassaWoo\Support\Decimal;

/** Optional merchant policy keyed by exact provider system and currency. */
final class MinimumPaymentPolicy
{
    /** @param array<string, mixed> $settings */
    public function assert_allows(string $amount, string $system, string $currency, array $settings): void
    {
        $minimum = $this->minimum($system, $currency, $settings);
        if (null === $minimum) {
            return;
        }
        $comparison = Decimal::compare($amount, $minimum);
        if (null === $comparison || $comparison < 0) {
            throw new PaymentCreationException('The payment amount is below the merchant-configured minimum for this PayKassa network and currency.');
        }
    }

    /** @param array<string, mixed> $settings */
    public function minimum(string $system, string $currency, array $settings): ?string
    {
        $rules = isset($settings['minimum_payment_directions']) && is_string($settings['minimum_payment_directions'])
            ? $this->parse($settings['minimum_payment_directions'])
            : array();
        return $rules[strtolower($system) . ':' . strtoupper($currency)] ?? null;
    }

    public function normalize_rules(string $rules): string
    {
        $parsed = $this->parse($rules);
        $normalized = array();
        foreach ((new PaymentSystemRegistry())->directions() as $direction) {
            $lookup = strtolower($direction['system']) . ':' . strtoupper($direction['currency']);
            if (isset($parsed[$lookup])) {
                $normalized[] = $direction['system'] . ':' . strtoupper($direction['currency']) . '=' . $parsed[$lookup];
            }
        }
        return implode("\n", $normalized);
    }

    /** @return array<string, string> */
    private function parse(string $rules): array
    {
        if (strlen($rules) > 4096) {
            throw new \InvalidArgumentException('PayKassa minimum-payment rules are too long.');
        }
        $known = array();
        foreach ((new PaymentSystemRegistry())->directions() as $direction) {
            $key = strtolower($direction['system']) . ':' . strtoupper($direction['currency']);
            $known[$key] = $direction['system'] . ':' . strtoupper($direction['currency']);
        }
        $parsed = array();
        $lines = preg_split('/\R/', trim($rules));
        foreach (false === $lines ? array() : $lines as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            if (! preg_match('/^([A-Za-z][A-Za-z0-9_]{1,63}):([A-Za-z0-9]{2,16})\s*=\s*(\S{1,128})$/', $line, $matches)) {
                throw new \InvalidArgumentException('Use one PayKassa minimum per line in Provider_System:CURRENCY=amount format.');
            }
            $lookup = strtolower($matches[1]) . ':' . strtoupper($matches[2]);
            $amount = Decimal::normalise($matches[3]);
            if (! isset($known[$lookup]) || '' === $amount || Decimal::equal($amount, '0') || isset($parsed[$lookup])) {
                throw new \InvalidArgumentException('PayKassa minimum-payment rules contain an unknown direction, invalid amount, or duplicate.');
            }
            $parsed[$lookup] = $amount;
        }
        return $parsed;
    }
}
