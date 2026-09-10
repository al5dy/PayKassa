<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Gateway;

use Al5dy\PayKassaWoo\PayKassa\PaymentSystemRegistry;

final class GatewayAvailability
{
    /** @param array<string, string> $settings */
    public function for_currency(string $currency, array $settings): bool
    {
        if ('yes' !== ( $settings['enabled'] ?? 'no' ) || '' === ( $settings['shop_id'] ?? '' ) || '' === ( $settings['shop_password'] ?? '' )) {
            return false;
        }
        foreach (( new PaymentSystemRegistry() )->all() as $key => $system) {
            if ($this->enabled((string) $key, $settings) && in_array(strtoupper($currency), $system['currencies'], true)) {
                return (bool) apply_filters('paykassa_gateway_available', true, $currency, $key);
            }
        }
        return false;
    }

    /** @param array<string, string> $settings */
    public function enabled(string $key, array $settings): bool
    {
        $enabled = $settings['enabled_systems'] ?? '';
        $items = '' === $enabled ? array_keys(( new PaymentSystemRegistry() )->all()) : array_filter(array_map('sanitize_key', explode(',', $enabled)));
        return in_array($key, $items, true);
    }
}
