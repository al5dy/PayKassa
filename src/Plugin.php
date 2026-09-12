<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo;

use Al5dy\PayKassaWoo\Admin\OrderMetaBox;
use Al5dy\PayKassaWoo\Admin\DiagnosticsPage;
use Al5dy\PayKassaWoo\Admin\SiteHealth;
use Al5dy\PayKassaWoo\Blocks\PayKassaPaymentMethod;
use Al5dy\PayKassaWoo\Gateway\PayKassaGateway;
use Al5dy\PayKassaWoo\Gateway\BrowserReturnController;
use Al5dy\PayKassaWoo\Gateway\MerchantEndpointUrls;
use Al5dy\PayKassaWoo\Infrastructure\Installer;
use Al5dy\PayKassaWoo\Reconciliation\ReconciliationScheduler;
use Al5dy\PayKassaWoo\PayKassa\SciCredentialStore;
use Al5dy\PayKassaWoo\Webhook\WebhookController;
use Al5dy\PayKassaWoo\Webhook\TransactionNotificationController;

final class Plugin
{
    public function register(): void
    {
        if (get_option(Installer::OPTION) !== Installer::SCHEMA_VERSION) {
            try {
                Installer::migrate();
            } catch (\Throwable $exception) {
                // A broken/customized database must not turn ordinary frontend
                // requests into a PHP fatal or leave a partially initialized
                // payment gateway available.
                add_action('admin_notices', static function (): void {
                    echo '<div class="notice notice-error"><p>' . esc_html__('PayKassa could not verify its payment-event database schema. The gateway remains disabled; please retry the migration from WooCommerce status tools.', 'paykassa') . '</p></div>';
                });
                return;
            }
        }
        if (Installer::gateway_settings_migration_required()) {
            Installer::migrate_gateway_settings();
        }
        ( new SciCredentialStore() )->register_rotation_guard();
        $settings_problem = Installer::settings_configuration_problem();
        if (null !== $settings_problem) {
            add_action('admin_notices', static function () use ($settings_problem): void {
                if (current_user_can('manage_woocommerce')) {
                    echo '<div class="notice notice-warning"><p>' . esc_html($settings_problem) . '</p></div>';
                }
            });
        }
        if (false !== get_option(Installer::CREDENTIAL_RETENTION_ERROR, false)) {
            add_action('admin_notices', static function (): void {
                if (current_user_can('manage_woocommerce')) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('PayKassa cannot safely retain SCI credentials for unfinished orders. New invoices are blocked until credential-profile storage is available.', 'paykassa') . '</p></div>';
                }
            });
        }
        add_filter('woocommerce_payment_gateways', static function (array $methods): array {
            $methods[] = PayKassaGateway::class;
            return $methods;
        });
        add_filter('plugin_action_links_' . plugin_basename(PAYKASSA_FILE), array($this, 'plugin_action_links'));
        add_action('admin_enqueue_scripts', array(PayKassaGateway::class, 'enqueue_admin_assets'));
        add_action('woocommerce_api_' . MerchantEndpointUrls::INVOICE_NOTIFICATION, array(new WebhookController(), 'handle'));
        add_action('woocommerce_api_' . MerchantEndpointUrls::TRANSACTION_NOTIFICATION, array(new TransactionNotificationController(), 'handle'));
        $browser_returns = new BrowserReturnController();
        add_action('woocommerce_api_' . MerchantEndpointUrls::SUCCESS_RETURN, array($browser_returns, 'success'));
        add_action('woocommerce_api_' . MerchantEndpointUrls::FAILURE_RETURN, array($browser_returns, 'failure'));
        add_action('woocommerce_blocks_payment_method_type_registration', array( $this, 'register_blocks' ));
        ( new OrderMetaBox() )->register();
        ( new SiteHealth() )->register();
        ( new DiagnosticsPage() )->register();
        ( new ReconciliationScheduler() )->register();
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public function plugin_action_links(array $links): array
    {
        if (! current_user_can('manage_woocommerce')) {
            return $links;
        }

        $links['paykassa_settings'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(PayKassaGateway::settings_url()),
            esc_html__('Settings', 'paykassa')
        );
        $links['paykassa_health'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(DiagnosticsPage::url()),
            esc_html__('Health', 'paykassa')
        );

        return $links;
    }

    public function register_blocks($registry): void
    {
        if (class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
            $registry->register(new PayKassaPaymentMethod());
        }
    }
}
