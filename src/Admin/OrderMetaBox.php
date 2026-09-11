<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Admin;

use Al5dy\PayKassaWoo\Order\InvoiceLifecycleService;
use Al5dy\PayKassaWoo\Order\InvoiceLockStatus;
use Al5dy\PayKassaWoo\Order\InvoiceLockStore;
use Al5dy\PayKassaWoo\Order\OrderMeta;
use Al5dy\PayKassaWoo\Order\PaymentSnapshot;

final class OrderMetaBox
{
    public function register(): void
    {
        add_action('woocommerce_admin_order_data_after_order_details', array( $this, 'render' ));
        add_action('admin_post_paykassa_invoice_lifecycle', array( $this, 'handle_lifecycle_action' ));
    }

    public function render(\WC_Order $order): void
    {
        if ('paykassa' !== $order->get_payment_method()) {
            return;
        }
        $lock_status = ( new InvoiceLockStore() )->status($order->get_id());
        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::SNAPSHOT, true));
        $rows = array(
            __('Payment state', 'paykassa') => $order->get_meta(OrderMeta::STATE, true),
            __('Invoice lifecycle', 'paykassa') => $lock_status,
            __('PayKassa transaction', 'paykassa') => $order->get_meta(OrderMeta::TRANSACTION, true),
            __('Invoice', 'paykassa') => $order->get_meta('_paykassa_invoice_id', true),
            __('Network', 'paykassa') => $order->get_meta('_paykassa_provider_system', true),
            __('Amount', 'paykassa') => $order->get_meta('_paykassa_provider_amount', true),
            __('Payment address', 'paykassa') => $order->get_meta('_paykassa_payment_address', true),
            __('Tag / memo', 'paykassa') => $order->get_meta('_paykassa_tag', true),
            __('Last webhook', 'paykassa') => $order->get_meta(OrderMeta::LAST_WEBHOOK, true),
            __('Environment', 'paykassa') => $order->get_meta('_paykassa_environment', true),
            __('Provider expiry', 'paykassa') => $snapshot instanceof PaymentSnapshot
                ? ($snapshot->expiration_is_known() ? $snapshot->expires_at : __('Not supplied by PayKassa SCI; automatic TTL is disabled', 'paykassa'))
                : '',
        );
        echo '<div class="paykassa-order-panel"><h3>' . esc_html__('PayKassa payment', 'paykassa') . '</h3>';
        $notice_key = 'paykassa_invoice_lifecycle_' . get_current_user_id() . '_' . $order->get_id();
        $notice = get_transient($notice_key);
        if (is_array($notice) && isset($notice['message'], $notice['type'])) {
            $class = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . ' inline"><p>' . esc_html((string) $notice['message']) . '</p></div>';
            delete_transient($notice_key);
        }
        echo '<table class="widefat striped"><tbody>';
        foreach ($rows as $label => $value) {
            if ('' !== (string) $value) {
                echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
            }
        }
        echo '</tbody></table>';

        if (! $order->is_paid() && InvoiceLockStatus::UNCERTAIN === $lock_status) {
            $this->render_action_button(
                $order,
                'resolve_uncertain',
                __('I confirmed no usable PayKassa invoice exists — allow one retry', 'paykassa'),
                __('Use only after checking PayKassa. A timeout may have created an invoice; an incorrect confirmation can lead to two payable invoices.', 'paykassa')
            );
        } elseif (! $order->is_paid() && InvoiceLockStatus::CREATED === $lock_status && $snapshot instanceof PaymentSnapshot) {
            $this->render_action_button(
                $order,
                'expire_created',
                __('Retire this PayKassa invoice and allow one replacement', 'paykassa'),
                __('Use only after confirming this hosted invoice is no longer usable. A later verified payment for the retired invoice will be held for manual review.', 'paykassa')
            );
        }
        echo '</div>';
    }

    public function handle_lifecycle_action(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to resolve PayKassa invoices.', 'paykassa'));
        }
        $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        if ($order_id < 1) {
            wp_die(esc_html__('A valid WooCommerce order is required.', 'paykassa'));
        }
        check_admin_referer('paykassa_invoice_lifecycle_' . $order_id);
        $order = wc_get_order($order_id);
        if (! $order instanceof \WC_Order) {
            wp_die(esc_html__('The WooCommerce order could not be found.', 'paykassa'));
        }

        $resolution = isset($_POST['resolution']) ? sanitize_key((string) wp_unslash($_POST['resolution'])) : '';
        $notice = array( 'type' => 'error', 'message' => __('The PayKassa invoice lifecycle was not changed.', 'paykassa') );
        try {
            $service = new InvoiceLifecycleService();
            if ('expire_created' === $resolution) {
                $service->expire_current($order, 'merchant_confirmed_invoice_unusable', get_current_user_id());
                $notice = array( 'type' => 'success', 'message' => __('The old PayKassa invoice was retired. Checkout may create one replacement invoice.', 'paykassa') );
            } elseif ('resolve_uncertain' === $resolution) {
                $service->resolve_uncertain_as_failed($order, get_current_user_id());
                $notice = array( 'type' => 'success', 'message' => __('The uncertain create was resolved as failed. Checkout may make one new attempt.', 'paykassa') );
            }
        } catch (\Throwable $exception) {
            $notice = array( 'type' => 'error', 'message' => $exception->getMessage() );
        }
        set_transient('paykassa_invoice_lifecycle_' . get_current_user_id() . '_' . $order_id, $notice, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect($order->get_edit_order_url());
        exit;
    }

    private function render_action_button(\WC_Order $order, string $resolution, string $label, string $warning): void
    {
        $form_id = 'paykassa-invoice-lifecycle-' . $order->get_id();
        echo '<p><strong>' . esc_html__('Manual invoice resolution', 'paykassa') . '</strong><br>' . esc_html($warning) . '</p>';
        echo '<p><button type="submit" class="button" form="' . esc_attr($form_id) . '" name="resolution" value="' . esc_attr($resolution) . '">' . esc_html($label) . '</button></p>';
        add_action('admin_footer', static function () use ($form_id, $order): void {
            echo '<form id="' . esc_attr($form_id) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="paykassa_invoice_lifecycle">';
            echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order->get_id()) . '">';
            wp_nonce_field('paykassa_invoice_lifecycle_' . $order->get_id());
            echo '</form>';
        });
    }
}
