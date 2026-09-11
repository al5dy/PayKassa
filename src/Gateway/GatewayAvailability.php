<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Gateway;

use Al5dy\PayKassaWoo\PayKassa\CurrencyRegistry;
use Al5dy\PayKassaWoo\PayKassa\PaymentSystemRegistry;

final class GatewayAvailability
{
    /** @param array<string, mixed> $settings */
    public function for_currency(string $currency, array $settings): bool
    {
        if ('yes' !== ( $settings['enabled'] ?? 'no' ) || '' === ( $settings['shop_id'] ?? '' ) || '' === ( $settings['shop_password'] ?? '' )) {
            return false;
        }
        if (! $this->accepts_order_currency($currency, $settings)) {
            return false;
        }
        foreach ($this->directions_for_order_currency($currency, $settings) as $key => $direction) {
            if ((new CurrencyRegistry())->supports_quote($direction['currency'])) {
                return (bool) apply_filters('paykassa_gateway_available', true, $currency, $key);
            }
        }
        return false;
    }

    /** @param array<string, mixed> $settings */
    public function accepts_order_currency(string $currency, array $settings): bool
    {
        $currency = strtoupper($currency);
        if (! array_key_exists('accepted_order_currencies', $settings)) {
            return false;
        }
        $enabled = $settings['accepted_order_currencies'];
        $enabled = is_array($enabled) ? $enabled : array_filter(array_map('trim', explode(',', (string) $enabled)));
        return in_array($currency, array_map('strtoupper', $enabled), true) && (new CurrencyRegistry())->supports_quote($currency);
    }

    /** @param array<string, mixed> $settings @return array<string, array{system_key:string,currency:string,system:string,label:string}> */
    public function directions_for_order_currency(string $order_currency, array $settings): array
    {
        if (! $this->accepts_order_currency($order_currency, $settings)) {
            return array();
        }
        $directions = (new PaymentSystemRegistry())->directions();
        $enabled = $this->enabled_directions($settings);
        foreach ($directions as $key => $direction) {
            if (! in_array($key, $enabled, true) || ! (new CurrencyRegistry())->supports_quote($direction['currency'])) {
                unset($directions[$key]);
            }
        }
        return $directions;
    }

    /** @param array<string, mixed> $settings */
    public function enabled_direction(string $direction, array $settings): bool
    {
        $normalised = (new PaymentSystemRegistry())->direction($direction);
        if (! is_array($normalised)) {
            return false;
        }
        $key = $normalised['system_key'] . ':' . $normalised['currency'];
        return in_array($key, $this->enabled_directions($settings), true);
    }

    /** @param array<string, mixed> $settings @return string[] */
    private function enabled_directions(array $settings): array
    {
        if (! array_key_exists('enabled_payment_directions', $settings)) {
            return array();
        }
        $registry = new PaymentSystemRegistry();
        $stored = $settings['enabled_payment_directions'];
        $stored = is_array($stored) ? $stored : array_filter(array_map('trim', explode(',', (string) $stored)));
        $normalised = array();
        foreach ($stored as $item) {
            $direction = $registry->direction((string) $item);
            if (is_array($direction)) {
                $normalised[] = $direction['system_key'] . ':' . $direction['currency'];
            }
        }
        return array_values(array_unique($normalised));
    }
}
