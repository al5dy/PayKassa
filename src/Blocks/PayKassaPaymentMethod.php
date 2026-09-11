<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Blocks;

use Al5dy\PayKassaWoo\Gateway\GatewayAvailability;
use Al5dy\PayKassaWoo\PayKassa\PaymentSystemRegistry;
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class PayKassaPaymentMethod extends AbstractPaymentMethodType
{
    protected $name = 'paykassa';
    public function initialize(): void
    {
        $settings = get_option('woocommerce_paykassa_settings', array());
        $this->settings = is_array($settings) ? $settings : array();
    }

    public function is_active(): bool
    {
        return 'yes' === ( $this->settings['enabled'] ?? 'no' ) && '' !== ( $this->settings['shop_id'] ?? '' ) && '' !== ( $this->settings['shop_password'] ?? '' );
    }

    public function get_payment_method_script_handles(): array
    {
        $handle = 'paykassa-blocks';
        wp_register_script($handle, PAYKASSA_URL . 'assets/build/blocks.js', array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n' ), PAYKASSA_VERSION, true);
        return array( $handle );
    }

    /** @return array<string, mixed> */
    public function get_payment_method_data(): array
    {
        $currency = get_woocommerce_currency();
        $directions = array();
        foreach ((new GatewayAvailability())->directions_for_order_currency($currency, $this->settings) as $key => $direction) {
            $directions[] = array('value' => $key, 'label' => $direction['label']);
        }
        return array(
            'title' => $this->settings['title'] ?? __('Cryptocurrency (PayKassa)', 'paykassa'),
            'description' => $this->settings['description'] ?? __('Pay securely with cryptocurrency through PayKassa.', 'paykassa'),
            'supports' => array( 'products' ),
            'available' => ( new GatewayAvailability() )->for_currency($currency, $this->settings),
            'directions' => $directions,
        );
    }
}
