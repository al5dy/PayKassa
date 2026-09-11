<?php

declare(strict_types=1);

namespace PayKassaWoo\Tests\Unit;

use Al5dy\PayKassaWoo\Gateway\MerchantEndpointUrls;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MerchantEndpointUrlsTest extends TestCase
{
    public function test_home_url_is_the_default_base_for_all_endpoints(): void
    {
        $urls = new MerchantEndpointUrls(array('testmode' => 'no'), 'https://store.example/shop');

        self::assertSame('https://store.example/shop/', $urls->server_callback_base_url());
        self::assertSame('https://store.example/shop/', $urls->browser_return_base_url());
        self::assertSame('wordpress_home', $urls->server_callback_source());
        self::assertSame('wordpress_home', $urls->browser_return_source());
        self::assertTrue($urls->server_callbacks_use_https());
        self::assertTrue($urls->browser_returns_use_https());
        self::assertSame('https://store.example/shop/?wc-api=wc_gateway_paykassa', $urls->invoice_notification_url());
        self::assertSame('https://store.example/shop/?wc-api=wc_gateway_paykassa_return', $urls->success_return_url());
        self::assertSame('https://store.example/shop/?wc-api=wc_gateway_paykassa_cancel', $urls->failure_return_url());
        self::assertSame('https://store.example/shop/?wc-api=wc_gateway_paykassa_transaction', $urls->transaction_notification_url());
    }

    public function test_callback_override_does_not_change_default_browser_return_base(): void
    {
        $urls = new MerchantEndpointUrls(
            array('testmode' => 'no', 'external_base_url' => 'https://public.example/proxy/store///'),
            'https://private.example/wordpress/'
        );

        self::assertSame('https://public.example/proxy/store/', $urls->server_callback_base_url());
        self::assertSame('https://private.example/wordpress/', $urls->browser_return_base_url());
        self::assertSame('external_override', $urls->server_callback_source());
        self::assertSame('wordpress_home', $urls->browser_return_source());
        self::assertTrue($urls->server_callbacks_use_https());
        self::assertTrue($urls->browser_returns_use_https());
        self::assertSame('https://public.example/proxy/store/?wc-api=wc_gateway_paykassa', $urls->invoice_notification_url());
        self::assertSame('https://public.example/proxy/store/?wc-api=wc_gateway_paykassa_transaction', $urls->transaction_notification_url());
        self::assertSame('https://private.example/wordpress/?wc-api=wc_gateway_paykassa_return', $urls->success_return_url());
        self::assertSame('https://private.example/wordpress/?wc-api=wc_gateway_paykassa_cancel', $urls->failure_return_url());
    }

    public function test_callback_and_browser_return_overrides_are_independent(): void
    {
        $urls = new MerchantEndpointUrls(
            array(
                'testmode' => 'no',
                'external_base_url' => 'https://callbacks.example/paykassa/',
                'browser_return_base_url' => 'https://checkout.example/store/',
            ),
            'https://upstream.example/wordpress/'
        );

        self::assertSame('https://callbacks.example/paykassa/', $urls->server_callback_base_url());
        self::assertSame('https://checkout.example/store/', $urls->browser_return_base_url());
        self::assertSame('external_override', $urls->server_callback_source());
        self::assertSame('external_override', $urls->browser_return_source());
        self::assertSame('https://callbacks.example/paykassa/?wc-api=wc_gateway_paykassa', $urls->invoice_notification_url());
        self::assertSame('https://callbacks.example/paykassa/?wc-api=wc_gateway_paykassa_transaction', $urls->transaction_notification_url());
        self::assertSame('https://checkout.example/store/?wc-api=wc_gateway_paykassa_return', $urls->success_return_url());
        self::assertSame('https://checkout.example/store/?wc-api=wc_gateway_paykassa_cancel', $urls->failure_return_url());
    }

    public function test_same_public_override_is_supported_for_callbacks_and_browser_returns(): void
    {
        $urls = new MerchantEndpointUrls(
            array(
                'testmode' => 'no',
                'external_base_url' => 'https://public.example/',
                'browser_return_base_url' => 'https://public.example/',
            ),
            'https://upstream.example/'
        );

        self::assertSame('https://public.example/?wc-api=wc_gateway_paykassa', $urls->invoice_notification_url());
        self::assertSame('https://public.example/?wc-api=wc_gateway_paykassa_return', $urls->success_return_url());
        self::assertSame('https://public.example/?wc-api=wc_gateway_paykassa_cancel', $urls->failure_return_url());
        self::assertSame('https://public.example/?wc-api=wc_gateway_paykassa_transaction', $urls->transaction_notification_url());
    }

    public function test_http_home_url_is_reported_for_browser_returns_even_with_https_callback_override(): void
    {
        $urls = new MerchantEndpointUrls(
            array('testmode' => 'no', 'external_base_url' => 'https://callbacks.example/'),
            'http://store.example/'
        );

        self::assertTrue($urls->server_callbacks_use_https());
        self::assertFalse($urls->browser_returns_use_https());
    }

    public function test_http_override_is_allowed_only_in_test_mode(): void
    {
        self::assertSame(
            'http://127.0.0.1:8080/wordpress/',
            MerchantEndpointUrls::normalize_external_base_url('http://127.0.0.1:8080/wordpress', false)
        );

        $this->expectException(\InvalidArgumentException::class);
        MerchantEndpointUrls::normalize_external_base_url('http://public.example/', true);
    }

    public function test_browser_return_override_uses_live_https_validation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MerchantEndpointUrls(
            array('testmode' => 'no', 'browser_return_base_url' => 'http://checkout.example/'),
            'https://store.example/'
        );
    }

    #[DataProvider('external_setting_keys')]
    public function test_non_string_external_settings_are_rejected(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MerchantEndpointUrls(array('testmode' => 'no', $key => array('https://invalid.example/')), 'https://store.example/');
    }

    /** @return array<string, array{string}> */
    public static function external_setting_keys(): array
    {
        return array(
            'callback' => array('external_base_url'),
            'browser return' => array('browser_return_base_url'),
        );
    }

    #[DataProvider('invalid_external_urls')]
    public function test_invalid_external_base_urls_are_rejected(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MerchantEndpointUrls::normalize_external_base_url($url, false);
    }

    /** @return array<string, array{string}> */
    public static function invalid_external_urls(): array
    {
        return array(
            'javascript protocol' => array('javascript:alert(1)'),
            'data protocol' => array('data:text/plain,hello'),
            'file protocol' => array('file:///tmp/store'),
            'credentials' => array('https://user:password@public.example/'),
            'query' => array('https://public.example/store?proxy=1'),
            'empty query' => array('https://public.example/store?'),
            'fragment' => array('https://public.example/store#callbacks'),
            'malformed host' => array('https:///store'),
            'relative URL' => array('/wordpress/'),
        );
    }
}
