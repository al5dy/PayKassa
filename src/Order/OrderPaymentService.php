<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Order;

use Al5dy\PayKassaWoo\Gateway\RedirectUrlValidator;
use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;
use Al5dy\PayKassaWoo\PayKassa\PayKassaClientFactory;
use Al5dy\PayKassaWoo\PayKassa\PaymentSystemRegistry;
use Al5dy\PayKassaWoo\PayKassa\Exception\ProviderUnavailableException;

final class OrderPaymentService
{
    /** @param array<string, string> $settings */
    public function create_or_reuse(\WC_Order $order, array $settings, string $system_key): string
    {
        $existing = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::SNAPSHOT, true));
        $url = (string) $order->get_meta('_paykassa_redirect_url', true);
        if ($existing instanceof PaymentSnapshot && $existing->order_id === $order->get_id() && ! $existing->is_expired() && PaymentState::PAID !== $order->get_meta(OrderMeta::STATE, true)) {
            if (RedirectUrlValidator::is_valid($url)) {
                return $url;
            }
            throw new PayKassaException('A previous payment request is awaiting confirmation. Do not create another invoice.');
        }

        $locks = new InvoiceLockStore();
        $reservation = $locks->acquire($order->get_id());
        if (! $reservation->acquired()) {
            if (InvoiceReservation::ERROR === $reservation->status) {
                throw new PayKassaException('Could not safely reserve this payment request. Please try again later.');
            }
            // A concurrent owner might have committed while we waited. Reload
            // through CRUD before deciding that the customer must retry.
            $order = wc_get_order($order->get_id());
            if (! $order instanceof \WC_Order) {
                throw new PayKassaException('The order could not be reloaded.');
            }
            $saved = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::SNAPSHOT, true));
            $url = (string) $order->get_meta('_paykassa_redirect_url', true);
            if ($saved instanceof PaymentSnapshot && ! $saved->is_expired() && RedirectUrlValidator::is_valid($url)) {
                return $url;
            }
            throw new PayKassaException('A payment request is already being created. Please wait and retry.');
        }

        $currency = strtoupper((string) $order->get_currency());
        $amount = (string) $order->get_total();
        $registry = new PaymentSystemRegistry();
        if (! $registry->supports_currency($system_key, $currency)) {
            $locks->fail($order->get_id(), $reservation->owner_token);
            throw new PayKassaException('The selected PayKassa direction cannot accept this order currency.');
        }

        try {
            $comment = sprintf('WooCommerce order #%s', $order->get_order_number());
            $result = (new PayKassaClientFactory())->sci($settings)->create_payment($amount, $system_key, $currency, $order->get_id(), $comment);
            if (! RedirectUrlValidator::is_valid($result->redirect_url)) {
                $locks->uncertain($order->get_id(), $reservation->owner_token);
                throw new PayKassaException('PayKassa returned an unsafe payment URL.');
            }

            $environment = 'yes' === ($settings['testmode'] ?? 'no') ? 'test' : 'live';
            // Context identifies the merchant configuration without retaining a
            // password. Existing invoices are verified against this snapshot,
            // not today's test/live setting after a credential rotation.
            $context = hash('sha256', (string) ($settings['shop_id'] ?? '') . "\0" . $environment);
            $snapshot = new PaymentSnapshot($order->get_id(), $amount, $currency, $result->system, $result->currency, $result->invoice_id, gmdate('c'), 'test' === $environment, 'hosted', $context, '', (string) ($settings['shop_id'] ?? ''));
            PaymentState::assert_transition((string) $order->get_meta(OrderMeta::STATE, true), PaymentState::AWAITING_PAYMENT);
            $order->update_meta_data(OrderMeta::SNAPSHOT, wp_json_encode($snapshot->to_array()));
            $order->update_meta_data(OrderMeta::STATE, PaymentState::AWAITING_PAYMENT);
            $order->update_meta_data('_paykassa_redirect_url', $result->redirect_url);
            $order->update_meta_data('_paykassa_provider_system', $result->system);
            $order->update_meta_data('_paykassa_provider_currency', $result->currency);
            $order->update_meta_data(OrderMeta::PAYMENT_LINK_HASH, $result->invoice_id);
            // Compatibility only: legacy versions mislabeled this link hash as
            // an invoice id. New domain logic never reads this key.
            $order->update_meta_data('_paykassa_invoice_id', $result->invoice_id);
            $order->update_meta_data('_paykassa_environment', $environment);
            $order->update_meta_data(OrderMeta::CREDENTIAL_CONTEXT, $context);
            $order->save();

            if (! $locks->complete($order->get_id(), $reservation->owner_token, hash('sha256', wp_json_encode($snapshot->to_array())))) {
                // The invoice is stored and can be reused. Do not issue a
                // second invoice if the lock table fails at this point.
                throw new PayKassaException('The payment request was saved but its reservation could not be finalized. Please contact the store.');
            }
            $order->add_order_note(__('PayKassa payment invoice created; awaiting provider confirmation.', 'paykassa'));
            do_action('paykassa_payment_created', $order, $snapshot);
            return $result->redirect_url;
        } catch (ProviderUnavailableException $exception) {
            // A provider timeout is ambiguous: retaining the lease is safer
            // than releasing it and creating a potentially duplicate invoice.
            $locks->uncertain($order->get_id(), $reservation->owner_token);
            throw $exception;
        } catch (PayKassaException $exception) {
            $locks->uncertain($order->get_id(), $reservation->owner_token);
            throw $exception;
        }
    }
}
