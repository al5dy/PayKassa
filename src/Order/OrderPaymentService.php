<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Order;

use Al5dy\PayKassaWoo\Gateway\RedirectUrlValidator;
use Al5dy\PayKassaWoo\PayKassa\PayKassaClientFactory;
use Al5dy\PayKassaWoo\PayKassa\PaymentSystemRegistry;
use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;

final class OrderPaymentService
{
    /** @param array<string, string> $settings */
    public function create_or_reuse(\WC_Order $order, array $settings, string $system_key): string
    {
        $existing = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::SNAPSHOT, true));
        if ($existing instanceof PaymentSnapshot && $existing->order_id === $order->get_id() && PaymentState::PAID !== $order->get_meta(OrderMeta::STATE, true)) {
            $url = (string) $order->get_meta('_paykassa_redirect_url', true);
            if (RedirectUrlValidator::is_valid($url)) {
                return $url;
            }
            throw new PayKassaException('A previous payment request is awaiting confirmation. Do not create another invoice.');
        }
        $currency = strtoupper((string) $order->get_currency());
        $amount = (string) $order->get_total();
        $registry = new PaymentSystemRegistry();
        if (! $registry->supports_currency($system_key, $currency)) {
            throw new PayKassaException('The selected PayKassa direction cannot accept this order currency.');
        }
        $comment = sprintf('WooCommerce order #%s', $order->get_order_number());
        $result = ( new PayKassaClientFactory() )->sci($settings)->create_payment($amount, $system_key, $currency, $order->get_id(), $comment);
        if (! RedirectUrlValidator::is_valid($result->redirect_url)) {
            throw new PayKassaException('PayKassa returned an unsafe payment URL.');
        }
        $snapshot = new PaymentSnapshot($order->get_id(), $amount, $currency, $result->system, $result->currency, $result->invoice_id, gmdate('c'), 'yes' === ( $settings['testmode'] ?? 'no' ));
        $order->update_meta_data(OrderMeta::SNAPSHOT, wp_json_encode($snapshot->to_array()));
        $order->update_meta_data(OrderMeta::STATE, PaymentState::AWAITING_PAYMENT);
        $order->update_meta_data('_paykassa_redirect_url', $result->redirect_url);
        $order->update_meta_data('_paykassa_provider_system', $result->system);
        $order->update_meta_data('_paykassa_provider_currency', $result->currency);
        $order->update_meta_data('_paykassa_invoice_id', $result->invoice_id);
        $order->update_meta_data('_paykassa_environment', $snapshot->test_mode ? 'test' : 'live');
        $order->save();
        $order->add_order_note(__('PayKassa payment invoice created; awaiting provider confirmation.', 'paykassa'));
        do_action('paykassa_payment_created', $order, $snapshot);
        return $result->redirect_url;
    }
}
