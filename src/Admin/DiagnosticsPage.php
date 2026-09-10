<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Admin;

use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;
use Al5dy\PayKassaWoo\PayKassa\PayKassaClientFactory;

final class DiagnosticsPage
{
    public function register(): void
    {
        add_action('admin_menu', array( $this, 'menu' ));
        add_action('admin_post_paykassa_test_connection', array( $this, 'test_connection' ));
    }

    public function menu(): void
    {
        add_submenu_page('woocommerce', __('PayKassa health', 'paykassa'), __('PayKassa health', 'paykassa'), 'manage_woocommerce', 'paykassa-health', array( $this, 'render' ));
    }

    public function test_connection(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to run this check.', 'paykassa'));
        }
        check_admin_referer('paykassa_test_connection');
        $settings = get_option('woocommerce_paykassa_settings', array());
        $settings = is_array($settings) ? $settings : array();
        $state = 'unavailable';
        if ('' !== ( $settings['api_id'] ?? '' ) && '' !== ( $settings['api_password'] ?? '' )) {
            try {
                ( new PayKassaClientFactory() )->api($settings)->merchant_info((string) ( $settings['shop_id'] ?? '' ));
                $state = 'connected';
            } catch (PayKassaException $exception) {
                $state = 'failed';
            }
        }
        set_transient('paykassa_connection_test', $state, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=paykassa-health'));
        exit;
    }

    public function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        $settings = get_option('woocommerce_paykassa_settings', array());
        $settings = is_array($settings) ? $settings : array();
        $report = array(
            'Plugin version' => PAYKASSA_VERSION,
            'WordPress version' => get_bloginfo('version'),
            'WooCommerce version' => defined('WC_VERSION') ? WC_VERSION : __('Not active', 'paykassa'),
            'PHP version' => PHP_VERSION,
            'HTTPS' => is_ssl() ? __('Yes', 'paykassa') : __('No', 'paykassa'),
            'Mode' => 'yes' === ( $settings['testmode'] ?? 'no' ) ? __('Test', 'paykassa') : __('Live', 'paykassa'),
            'Merchant credentials configured' => ( '' !== ( $settings['shop_id'] ?? '' ) && '' !== ( $settings['shop_password'] ?? '' ) ) ? __('Yes', 'paykassa') : __('No', 'paykassa'),
            'API credentials configured' => ( '' !== ( $settings['api_id'] ?? '' ) && '' !== ( $settings['api_password'] ?? '' ) ) ? __('Yes', 'paykassa') : __('No', 'paykassa'),
            'Legacy callback URL' => add_query_arg('wc-api', 'wc_gateway_paykassa', home_url('/')),
            'Last reconciliation' => get_option('paykassa_last_reconciliation', __('Never', 'paykassa')),
        );
        echo '<div class="wrap"><h1>' . esc_html__('PayKassa payment health', 'paykassa') . '</h1>';
        $result = get_transient('paykassa_connection_test');
        if (false !== $result) {
            echo '<div class="notice notice-info"><p>' . esc_html('connected' === $result ? __('Connected: PayKassa API credentials were accepted.', 'paykassa') : ( 'failed' === $result ? __('Connection failed. Check API credentials or PayKassa availability.', 'paykassa') : __('Add API credentials to run the read-only connection test. SCI credentials have no documented read-only test.', 'paykassa') )) . '</p></div>';
            delete_transient('paykassa_connection_test');
        }
        echo '<table class="widefat striped"><tbody>';
        foreach ($report as $label => $value) {
            echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</tbody></table><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="paykassa_test_connection">';
        wp_nonce_field('paykassa_test_connection');
        submit_button(__('Test API connection', 'paykassa'), 'secondary', 'submit', false);
        echo '</form></div>';
    }
}
