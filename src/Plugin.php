<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo;

use Al5dy\PayKassaWoo\Admin\OrderMetaBox;
use Al5dy\PayKassaWoo\Admin\DiagnosticsPage;
use Al5dy\PayKassaWoo\Admin\SiteHealth;
use Al5dy\PayKassaWoo\Blocks\PayKassaPaymentMethod;
use Al5dy\PayKassaWoo\Gateway\PayKassaGateway;
use Al5dy\PayKassaWoo\Infrastructure\Installer;
use Al5dy\PayKassaWoo\Reconciliation\ReconciliationScheduler;
use Al5dy\PayKassaWoo\Webhook\WebhookController;

final class Plugin
{
    public function register(): void
    {
        if (get_option(Installer::OPTION) !== Installer::SCHEMA_VERSION) {
            Installer::migrate();
        }
        add_filter('woocommerce_payment_gateways', static function (array $methods): array {
            $methods[] = PayKassaGateway::class;
            return $methods;
        });
        add_action('woocommerce_api_wc_gateway_paykassa', array( new WebhookController(), 'handle' ));
        add_action('woocommerce_blocks_payment_method_type_registration', array( $this, 'register_blocks' ));
        ( new OrderMetaBox() )->register();
        ( new SiteHealth() )->register();
        ( new DiagnosticsPage() )->register();
        ( new ReconciliationScheduler() )->register();
    }
    public function register_blocks($registry): void
    {
        if (class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
            $registry->register(new PayKassaPaymentMethod());
        }
    }
}
