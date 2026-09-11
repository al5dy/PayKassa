<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\PayKassa;

use Al5dy\PayKassaWoo\PayKassa\Exception\InvalidResponseException;
use Al5dy\PayKassaWoo\PayKassa\Exception\ProviderUnavailableException;
use Al5dy\PayKassaWoo\Support\Decimal;

/** Adapter for the current official PayKassaCurrency pairs.php API. */
final class CurrencyRateClient
{
    private const ENDPOINT = 'https://currency.paykassa.pro/pairs.php';

    public function quote(string $order_amount, string $order_currency, string $payment_currency, string $payment_system): CurrencyQuote
    {
        $order_amount = Decimal::normalise($order_amount);
        $order_currency = strtoupper($order_currency);
        $payment_currency = strtoupper($payment_currency);
        $currencies = new CurrencyRegistry();
        if ('' === $order_amount || '' === $payment_system || ! $currencies->supports_quote($order_currency) || ! $currencies->supports_quote($payment_currency)) {
            throw new InvalidResponseException('The selected PayKassa conversion currency is unsupported.');
        }
        if ($order_currency === $payment_currency) {
            return new CurrencyQuote($order_amount, $order_currency, $order_amount, $payment_currency, $payment_system, '1', $order_currency . '_' . $payment_currency, gmdate('c'), 'identity');
        }
        // Live PayKassa Currency API evidence: USD_BTC returns BTC per USD.
        // Therefore this is an order-currency -> payment-currency multiplier.
        $pair = $order_currency . '_' . $payment_currency;
        $response = wp_remote_post(self::ENDPOINT, array('timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 65536, 'body' => array('pairs' => array($pair))));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) < 200 || (int) wp_remote_retrieve_response_code($response) > 299) {
            throw new ProviderUnavailableException('PayKassa currency service is temporarily unavailable.');
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true, 16, JSON_BIGINT_AS_STRING);
        if (! is_array($decoded) || true === ($decoded['error'] ?? null) || ! isset($decoded['data']) || ! is_array($decoded['data'])) {
            throw new InvalidResponseException('PayKassa currency service returned malformed data.');
        }
        $rate = $this->rate_from_data($decoded['data'], $pair);
        if ('' === Decimal::normalise($rate) || Decimal::equal($rate, '0')) {
            throw new InvalidResponseException('PayKassa currency service returned an invalid rate.');
        }
        $payment_amount = Decimal::multiply($order_amount, $rate);
        if ('' === $payment_amount || Decimal::equal($payment_amount, '0')) {
            throw new InvalidResponseException('PayKassa currency conversion produced an invalid amount.');
        }
        return new CurrencyQuote($order_amount, $order_currency, $payment_amount, $payment_currency, $payment_system, $rate, $pair, gmdate('c'), 'paykassa_currency_pairs_v1');
    }

    /** @param array<mixed> $data */
    private function rate_from_data(array $data, string $pair): string
    {
        if (isset($data[$pair]) && is_string($data[$pair])) {
            return $data[$pair];
        }
        foreach ($data as $group) {
            if (is_array($group) && isset($group[$pair]) && is_string($group[$pair])) {
                return $group[$pair];
            }
        }
        return '';
    }
}
