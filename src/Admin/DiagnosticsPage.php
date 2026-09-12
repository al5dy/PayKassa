<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Admin;

use Al5dy\PayKassaWoo\Gateway\MerchantEndpointUrls;
use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;
use Al5dy\PayKassaWoo\PayKassa\PayKassaClientFactory;
use Al5dy\PayKassaWoo\Reconciliation\ReconciliationService;

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
        set_transient('paykassa_connection_test_' . get_current_user_id(), $state, 5 * MINUTE_IN_SECONDS);
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
        $test_mode = 'yes' === ( $settings['testmode'] ?? 'no' );
        $endpoint_settings = $settings;
        $invalid_callback_url = ! self::sanitize_external_base_setting($endpoint_settings, 'external_base_url', ! $test_mode);
        $invalid_browser_url = ! self::sanitize_external_base_setting($endpoint_settings, 'browser_return_base_url', ! $test_mode);
        $endpoint_urls = new MerchantEndpointUrls($endpoint_settings);
        $report = array(
            'Plugin version' => PAYKASSA_VERSION,
            'WordPress version' => get_bloginfo('version'),
            'WooCommerce version' => defined('WC_VERSION') ? WC_VERSION : __('Not active', 'paykassa'),
            'PHP version' => PHP_VERSION,
            'HTTPS' => is_ssl() ? __('Yes', 'paykassa') : __('No', 'paykassa'),
            'Mode' => $test_mode ? __('Test', 'paykassa') : __('Live', 'paykassa'),
            'Merchant credentials configured' => ( '' !== ( $settings['shop_id'] ?? '' ) && '' !== ( $settings['shop_password'] ?? '' ) ) ? __('Yes', 'paykassa') : __('No', 'paykassa'),
            'API credentials configured' => ( '' !== ( $settings['api_id'] ?? '' ) && '' !== ( $settings['api_password'] ?? '' ) ) ? __('Yes', 'paykassa') : __('No', 'paykassa'),
            __('Public PayKassa callback base URL', 'paykassa') => $endpoint_urls->server_callback_base_url(),
            __('Callback URL source', 'paykassa') => 'external_override' === $endpoint_urls->server_callback_source() ? __('External override', 'paykassa') : __('WordPress home URL', 'paykassa'),
            __('Browser return base URL', 'paykassa') => $endpoint_urls->browser_return_base_url(),
            __('Browser return URL source', 'paykassa') => 'external_override' === $endpoint_urls->browser_return_source() ? __('External override', 'paykassa') : __('WordPress home URL', 'paykassa'),
            'Last reconciliation' => get_option('paykassa_last_reconciliation', __('Never', 'paykassa')),
            __('Last completed history scan', 'paykassa') => get_option('paykassa_last_history_scan', __('Never', 'paykassa')),
            __('Last recovery error', 'paykassa') => get_option('paykassa_last_reconciliation_error', __('Never', 'paykassa')),
            __('History recovery capability', 'paykassa') => $test_mode
                ? __('Unsupported in PayKassa Test Mode: history returned no authoritative incoming-payment records during real sandbox validation.', 'paykassa')
                : __('Unverified for unattended use until a controlled live lost-webhook payment proves that history provides authoritative matching fields.', 'paykassa'),
        );
        $recovery = get_option(ReconciliationService::REPORT_OPTION, array());
        if (is_array($recovery)) {
            foreach (array('status' => __('Recovery status', 'paykassa'), 'checked' => __('History records checked', 'paykassa'), 'recovered' => __('Payments recovered', 'paykassa'), 'duplicate' => __('Duplicate payments ignored', 'paykassa'), 'manual_review' => __('Payments requiring manual review', 'paykassa'), 'unverifiable' => __('History records without sufficient verification', 'paykassa')) as $key => $label) {
                $value = $recovery[$key] ?? '';
                if (is_string($value) || is_int($value)) {
                    $report[$label] = $value;
                }
            }
        }
        echo '<div class="wrap"><h1>' . esc_html__('PayKassa payment health', 'paykassa') . '</h1>';
        if ($invalid_callback_url) {
            echo '<div class="notice notice-error"><p>' . esc_html__('The stored external PayKassa callback base URL is invalid. Server callback URLs below use the WordPress home URL until the setting is corrected.', 'paykassa') . '</p></div>';
        } elseif (! $test_mode && ! $endpoint_urls->server_callbacks_use_https()) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Critical: Live PayKassa notification URLs must use HTTPS.', 'paykassa') . '</p></div>';
        }
        if ($invalid_browser_url) {
            echo '<div class="notice notice-error"><p>' . esc_html__('The stored external PayKassa browser return base URL is invalid. Browser return URLs below use the WordPress home URL until the setting is corrected.', 'paykassa') . '</p></div>';
        } elseif (! $test_mode && ! $endpoint_urls->browser_returns_use_https()) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Critical: Live PayKassa browser return URLs must use HTTPS.', 'paykassa') . '</p></div>';
        }
        echo '<div class="notice notice-warning"><p>' . esc_html__('Payment recovery is experimental and disabled by default. History records and invoice TXIDs are never sufficient payment evidence; settlement still requires SCI-verified immutable payment facts.', 'paykassa') . '</p></div>';
        $connection_key = 'paykassa_connection_test_' . get_current_user_id();
        $result = get_transient($connection_key);
        if (false !== $result) {
            echo '<div class="notice notice-info"><p>' . esc_html('connected' === $result ? __('Connected: PayKassa API credentials were accepted.', 'paykassa') : ( 'failed' === $result ? __('Connection failed. Check API credentials or PayKassa availability.', 'paykassa') : __('Add API credentials to run the read-only connection test. SCI credentials have no documented read-only test.', 'paykassa') )) . '</p></div>';
            delete_transient($connection_key);
        }
        echo '<table class="widefat striped"><tbody>';
        foreach ($report as $label => $value) {
            echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>' . esc_html__('Endpoint diagnostics', 'paykassa') . '</h2><table class="widefat striped"><tbody>';
        foreach ($this->endpoint_registration() as $label => $registered) {
            echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($registered ? __('Registered', 'paykassa') : __('Not registered', 'paykassa')) . '</td></tr>';
        }
        echo '</tbody></table><p>' . esc_html__('PayKassa notification servers must be able to reach both public POST endpoints. Source IP is diagnostic only; authenticity always comes from private_hash followed by PayKassa SCI verification. A GET request returning 405 on either server callback is expected.', 'paykassa') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="paykassa_test_connection">';
        wp_nonce_field('paykassa_test_connection');
        submit_button(__('Test API connection', 'paykassa'), 'secondary', 'submit', false);
        echo '</form></div>';
    }

    /** @return array<string, bool> */
    private function endpoint_registration(): array
    {
        return array(
            __('Invoice notification endpoint (POST)', 'paykassa') => false !== has_action('woocommerce_api_' . MerchantEndpointUrls::INVOICE_NOTIFICATION),
            __('Successful payment endpoint (GET)', 'paykassa') => false !== has_action('woocommerce_api_' . MerchantEndpointUrls::SUCCESS_RETURN),
            __('Malfunction endpoint (GET)', 'paykassa') => false !== has_action('woocommerce_api_' . MerchantEndpointUrls::FAILURE_RETURN),
            __('Transaction processor endpoint (POST, Live only)', 'paykassa') => false !== has_action('woocommerce_api_' . MerchantEndpointUrls::TRANSACTION_NOTIFICATION),
        );
    }

    /** @param array<string, mixed> $settings */
    private static function sanitize_external_base_setting(array &$settings, string $key, bool $live_mode): bool
    {
        $value = $settings[$key] ?? '';
        if ('' === $value) {
            $settings[$key] = '';
            return true;
        }
        if (! is_string($value)) {
            $settings[$key] = '';
            return false;
        }
        if ('' === trim($value)) {
            $settings[$key] = '';
            return true;
        }
        try {
            $settings[$key] = MerchantEndpointUrls::normalize_external_base_url($value, $live_mode);
            return true;
        } catch (\InvalidArgumentException $exception) {
            $settings[$key] = '';
            return false;
        }
    }
}
