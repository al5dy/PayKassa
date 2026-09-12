<?php

declare(strict_types=1);

namespace PayKassaWoo\Tests\Unit;

use Al5dy\PayKassaWoo\Gateway\BrowserDestinationUrlMapper;
use Al5dy\PayKassaWoo\Gateway\MerchantEndpointUrls;
use PHPUnit\Framework\TestCase;

final class BrowserDestinationUrlMapperTest extends TestCase
{
    public function test_default_home_base_leaves_native_destination_byte_for_byte_unchanged(): void
    {
        $urls = new MerchantEndpointUrls(array('testmode' => 'no'), 'https://store.example/wordpress/');
        $mapper = new BrowserDestinationUrlMapper($urls, 'https://store.example/wordpress/');
        $native = 'https://store.example/wordpress/checkout/order-received/145/?key=wc_order_a%2Fb+c&source=return#details';

        self::assertSame($native, $mapper->map($native, 'browser_return_success'));
    }

    public function test_override_maps_native_destination_and_preserves_path_query_and_fragment(): void
    {
        $urls = new MerchantEndpointUrls(
            array('testmode' => 'no', 'browser_return_base_url' => 'https://public.example/'),
            'https://private.example/'
        );
        $mapper = new BrowserDestinationUrlMapper($urls, 'https://private.example/');
        $native = 'https://private.example/checkout/order-received/145/?key=wc_order_a%2Fb+c&source=return#details';

        self::assertSame(
            'https://public.example/checkout/order-received/145/?key=wc_order_a%2Fb+c&source=return#details',
            $mapper->map($native, 'browser_return_success')
        );
    }

    public function test_corresponding_subdirectory_prefixes_are_mapped(): void
    {
        $urls = new MerchantEndpointUrls(
            array('testmode' => 'no', 'browser_return_base_url' => 'https://public.example/storefront/'),
            'https://private.example/wordpress/'
        );
        $mapper = new BrowserDestinationUrlMapper($urls, 'https://private.example/wordpress/');

        self::assertSame(
            'https://public.example/storefront/checkout/order-pay/145/?pay_for_order=true&key=wc_order_123',
            $mapper->map(
                'https://private.example/wordpress/checkout/order-pay/145/?pay_for_order=true&key=wc_order_123',
                'browser_return_failure'
            )
        );
    }

    public function test_selected_wordpress_page_is_mapped_after_selection_while_native_url_is_preserved(): void
    {
        $urls = new MerchantEndpointUrls(
            array('testmode' => 'no', 'browser_return_base_url' => 'https://public.example/storefront/'),
            'https://private.example/wordpress/'
        );
        $mapper = new BrowserDestinationUrlMapper($urls, 'https://private.example/wordpress/');

        self::assertSame(
            'https://public.example/storefront/payment-success/?campaign=crypto',
            $mapper->map(
                'https://private.example/wordpress/payment-success/?campaign=crypto',
                'browser_return_success',
                null,
                'https://private.example/wordpress/checkout/order-received/145/?key=wc_order_123'
            )
        );
    }

    public function test_third_party_and_canonical_prefix_collision_destinations_fail_to_public_root(): void
    {
        $urls = new MerchantEndpointUrls(
            array('testmode' => 'no', 'browser_return_base_url' => 'https://public.example/storefront/'),
            'https://private.example/wordpress/'
        );
        $mapper = new BrowserDestinationUrlMapper($urls, 'https://private.example/wordpress/');

        self::assertSame(
            'https://public.example/storefront/',
            $mapper->map('https://provider.example/checkout/?key=secret', 'browser_return_success')
        );
        self::assertSame(
            'https://public.example/storefront/',
            $mapper->map('https://private.example/wordpress-evil/checkout/?key=secret', 'browser_return_success')
        );
    }

    public function test_public_destination_policy_rejects_other_origin_port_and_path_prefix(): void
    {
        $urls = new MerchantEndpointUrls(
            array('testmode' => 'no', 'browser_return_base_url' => 'https://public.example:8443/store/'),
            'https://private.example/wordpress/'
        );
        $mapper = new BrowserDestinationUrlMapper($urls, 'https://private.example/wordpress/');

        self::assertTrue($mapper->is_safe_browser_destination('https://public.example:8443/store/checkout/?key=wc_order_1'));
        self::assertFalse($mapper->is_safe_browser_destination('https://public.example/store/checkout/?key=wc_order_1'));
        self::assertFalse($mapper->is_safe_browser_destination('https://public.example:8443/store-evil/?key=wc_order_1'));
        self::assertFalse($mapper->is_safe_browser_destination('https://third-party.example/store/?key=wc_order_1'));
        self::assertFalse($mapper->is_safe_browser_destination('https://public.example:8443/store/%2e%2e/private/?key=wc_order_1'));
        self::assertFalse($mapper->is_safe_browser_destination('https://public.example:8443/store/%5cevil/?key=wc_order_1'));
        self::assertFalse($mapper->is_safe_browser_destination("https://public.example:8443/store/\r\nLocation:https://evil.example/"));
    }
}
