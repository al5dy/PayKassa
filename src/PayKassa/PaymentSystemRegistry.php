<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\PayKassa;

/**
 * Current crypto directions published by PayKassa's official PHP wrapper
 * (paykassa-dev/paykassa-modules, commit 1b3b4d4, 2025-03-29). The wrapper
 * exposes this list locally rather than through an authenticated discovery API.
 */
final class PaymentSystemRegistry
{
    private const CACHE_KEY = 'paykassa_crypto_systems_v1';

    /** @return array<string, array<string, int|string|bool|array>> */
    public function all(): array
    {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        $systems = array(
            'bitcoin' => array( 'id' => 11, 'system' => 'BitCoin', 'label' => 'Bitcoin', 'currencies' => array( 'BTC' ), 'tag' => false ),
            'ethereum' => array( 'id' => 12, 'system' => 'Ethereum', 'label' => 'Ethereum', 'currencies' => array( 'ETH' ), 'tag' => false ),
            'litecoin' => array( 'id' => 14, 'system' => 'LiteCoin', 'label' => 'Litecoin', 'currencies' => array( 'LTC' ), 'tag' => false ),
            'dogecoin' => array( 'id' => 15, 'system' => 'DogeCoin', 'label' => 'Dogecoin', 'currencies' => array( 'DOGE' ), 'tag' => false ),
            'dash' => array( 'id' => 16, 'system' => 'Dash', 'label' => 'Dash', 'currencies' => array( 'DASH' ), 'tag' => false ),
            'bitcoincash' => array( 'id' => 18, 'system' => 'BitcoinCash', 'label' => 'Bitcoin Cash', 'currencies' => array( 'BCH' ), 'tag' => false ),
            'ripple' => array( 'id' => 22, 'system' => 'Ripple', 'label' => 'XRP', 'currencies' => array( 'XRP' ), 'tag' => true ),
            'tron' => array( 'id' => 27, 'system' => 'TRON', 'label' => 'TRON', 'currencies' => array( 'TRX' ), 'tag' => false ),
            'stellar' => array( 'id' => 28, 'system' => 'Stellar', 'label' => 'Stellar', 'currencies' => array( 'XLM' ), 'tag' => true ),
            'binancecoin' => array( 'id' => 29, 'system' => 'BinanceCoin', 'label' => 'BNB Chain', 'currencies' => array( 'BNB' ), 'tag' => false ),
            'tron_trc20' => array( 'id' => 30, 'system' => 'TRON_TRC20', 'label' => 'TRON (TRC20)', 'currencies' => array( 'USDT' ), 'tag' => false ),
            'binancesmartchain_bep20' => array( 'id' => 31, 'system' => 'BinanceSmartChain_BEP20', 'label' => 'BNB Smart Chain (BEP20)', 'currencies' => array( 'USDT', 'USDC', 'ADA', 'EOS', 'BTC', 'ETH', 'DOGE', 'SHIB' ), 'tag' => false ),
            'ethereum_erc20' => array( 'id' => 32, 'system' => 'Ethereum_ERC20', 'label' => 'Ethereum (ERC20)', 'currencies' => array( 'USDT', 'USDC', 'SHIB' ), 'tag' => false ),
            'ton' => array( 'id' => 33, 'system' => 'TON', 'label' => 'TON', 'currencies' => array( 'TON', 'USDT' ), 'tag' => true ),
        );
        $systems = apply_filters('paykassa_payment_systems', $systems);
        set_transient(self::CACHE_KEY, $systems, DAY_IN_SECONDS);
        return $systems;
    }

    /** @return array<string, int|string|bool|array>|null */
    public function get(string $key): ?array
    {
        $systems = $this->all();
        return $systems[ sanitize_key($key) ] ?? null;
    }

    public function supports_currency(string $key, string $currency): bool
    {
        $system = $this->get($key);
        return is_array($system) && in_array(strtoupper($currency), $system['currencies'], true);
    }
}
