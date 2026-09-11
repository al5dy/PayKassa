<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\PayKassa;

/** Currency codes confirmed by the current PayKassa Currency API/SDK. */
final class CurrencyRegistry
{
    /** @return array<string, string> */
    public function order_currency_options(): array
    {
        $codes = array('USD', 'EUR', 'GBP', 'RUB', 'BTC', 'ETH', 'LTC', 'DOGE', 'DASH', 'BCH', 'XRP', 'TRX', 'XLM', 'BNB', 'USDT', 'USDC', 'ADA', 'EOS', 'TON', 'SHIB');
        return array_combine($codes, $codes) ?: array();
    }

    public function supports_quote(string $currency): bool
    {
        return isset($this->order_currency_options()[strtoupper($currency)]);
    }

    /** @return string[] */
    public function legacy_order_currencies(): array
    {
        $currencies = array();
        foreach ((new PaymentSystemRegistry())->directions() as $direction) {
            $currencies[] = $direction['currency'];
        }
        return array_values(array_unique($currencies));
    }
}
