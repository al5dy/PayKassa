<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Admin;

use Al5dy\PayKassaWoo\Gateway\MerchantEndpointUrls;

final class SiteHealth
{
    public function register(): void
    {
        add_filter('site_status_tests', array( $this, 'tests' ));
    }
    /** @param array<string, mixed> $tests @return array<string, mixed> */
    public function tests(array $tests): array
    {
        $tests['direct']['paykassa_configuration'] = array( 'label' => __('PayKassa payment configuration', 'paykassa'), 'test' => array( $this, 'test_configuration' ) );
        return $tests;
    }
    /** @return array<string, mixed> */
    public function test_configuration(): array
    {
        $settings = get_option('woocommerce_paykassa_settings', array());
        $settings = is_array($settings) ? $settings : array();
        if ('yes' === ( $settings['enabled'] ?? 'no' ) && ( '' === ( $settings['shop_id'] ?? '' ) || '' === ( $settings['shop_password'] ?? '' ) )) {
            return array( 'label' => __('PayKassa needs merchant credentials', 'paykassa'), 'status' => 'critical', 'badge' => array( 'label' => 'PayKassa', 'color' => 'red' ), 'description' => '<p>' . esc_html__('The gateway is enabled but its merchant credentials are incomplete.', 'paykassa') . '</p>', 'actions' => '' );
        }
        try {
            $urls = new MerchantEndpointUrls($settings);
        } catch (\InvalidArgumentException $exception) {
            return array( 'label' => __('PayKassa public URL override is invalid', 'paykassa'), 'status' => 'critical', 'badge' => array( 'label' => 'PayKassa', 'color' => 'red' ), 'description' => '<p>' . esc_html__('Correct the external callback or browser return base URL before copying Merchant URLs.', 'paykassa') . '</p>', 'actions' => '' );
        }
        if ('yes' === ( $settings['enabled'] ?? 'no' ) && 'yes' !== ( $settings['testmode'] ?? 'no' ) && (! is_ssl() || ! $urls->server_callbacks_use_https() || ! $urls->browser_returns_use_https())) {
            return array( 'label' => __('PayKassa needs HTTPS in live mode', 'paykassa'), 'status' => 'critical', 'badge' => array( 'label' => 'PayKassa', 'color' => 'red' ), 'description' => '<p>' . esc_html__('Enable HTTPS for both the public PayKassa callback base and browser return base before accepting live cryptocurrency payments.', 'paykassa') . '</p>', 'actions' => '' );
        }
        return array( 'label' => __('PayKassa configuration looks ready', 'paykassa'), 'status' => 'good', 'badge' => array( 'label' => 'PayKassa', 'color' => 'blue' ), 'description' => '<p>' . esc_html__('No credentials or HTTPS issue was detected.', 'paykassa') . '</p>', 'actions' => '' );
    }
}
