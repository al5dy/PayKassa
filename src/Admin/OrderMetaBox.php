<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Admin;

use Al5dy\PayKassaWoo\Order\OrderMeta;

final class OrderMetaBox
{
    public function register(): void
    {
        add_action('woocommerce_admin_order_data_after_order_details', array( $this, 'render' ));
    }

    public function render(\WC_Order $order): void
    {
        if ('paykassa' !== $order->get_payment_method()) {
            return;
        }
        $rows = array(
            __('Payment state', 'paykassa') => $order->get_meta(OrderMeta::STATE, true),
            __('PayKassa transaction', 'paykassa') => $order->get_meta(OrderMeta::TRANSACTION, true),
            __('Invoice', 'paykassa') => $order->get_meta('_paykassa_invoice_id', true),
            __('Network', 'paykassa') => $order->get_meta('_paykassa_provider_system', true),
            __('Amount', 'paykassa') => $order->get_meta('_paykassa_provider_amount', true),
            __('Payment address', 'paykassa') => $order->get_meta('_paykassa_payment_address', true),
            __('Tag / memo', 'paykassa') => $order->get_meta('_paykassa_tag', true),
            __('Last webhook', 'paykassa') => $order->get_meta(OrderMeta::LAST_WEBHOOK, true),
            __('Environment', 'paykassa') => $order->get_meta('_paykassa_environment', true),
        );
        echo '<div class="paykassa-order-panel"><h3>' . esc_html__('PayKassa payment', 'paykassa') . '</h3><table class="widefat striped"><tbody>';
        foreach ($rows as $label => $value) {
            if ('' !== (string) $value) {
                echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
            }
        }
        echo '</tbody></table></div>';
    }
}
