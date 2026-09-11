<?php

declare(strict_types=1);

namespace PayKassaWoo\Tests\Unit;

use Al5dy\PayKassaWoo\Gateway\MerchantEndpointUrls;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MerchantEndpointUrlsTest extends TestCase
{
    public function test_home_url_is_the_default_single_base_for_all_endpoints(): void
    {
        $urls = new MerchantEndpointUrls(array('testmode' => 'no'), 'https://store.example/shop');

        self::assertSame('https://store.example/shop/', $urls->base_url());
        self::assertSame('wordpress_home', $urls->source());
        self::assertSame('https://store.example/shop/?wc-api=wc_gateway_paykassa', $urls->invoice_notification_url());
        self::assertSame('https://store.example/shop/?wc-api=wc_gateway_paykassa_return', $urls->success_return_url());
        self::assertSame('https://store.example/shop/?wc-api=wc_gateway_paykassa_cancel', $urls->failure_return_url());
        self::assertSame('https://store.example/shop/?wc-api=wc_gateway_paykassa_transaction', $urls->transaction_notification_url());
    }

    public function test_external_override_preserves_subdirectory_and_normalizes_trailing_slash(): void
    {
        $urls = new MerchantEndpointUrls(
            array('testmode' => 'no', 'external_base_url' => 'https://public.example/proxy/store///'),
            'https://private.example/wordpress/'
        );

        self::assertSame('https://public.example/proxy/store/', $urls->base_url());
        self::assertSame('external_override', $urls->source());
        self::assertTrue($urls->is_https());
        self::assertStringNotContainsString('private.example', $urls->invoice_notification_url());
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
