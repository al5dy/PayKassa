<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Order;

use Al5dy\PayKassaWoo\Gateway\GatewayAvailability;
use Al5dy\PayKassaWoo\Gateway\MinimumPaymentPolicy;
use Al5dy\PayKassaWoo\Gateway\RedirectUrlValidator;
use Al5dy\PayKassaWoo\Infrastructure\DatabaseMutex;
use Al5dy\PayKassaWoo\PayKassa\CurrencyRateClient;
use Al5dy\PayKassaWoo\PayKassa\Exception\InvalidResponseException;
use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;
use Al5dy\PayKassaWoo\PayKassa\Exception\PaymentCreationException;
use Al5dy\PayKassaWoo\PayKassa\Exception\ProviderUnavailableException;
use Al5dy\PayKassaWoo\PayKassa\PayKassaClientFactory;
use Al5dy\PayKassaWoo\PayKassa\PaymentSystemRegistry;
use Al5dy\PayKassaWoo\PayKassa\SciCredentialStore;

final class OrderPaymentService
{
    /** @param array<string, mixed> $settings */
    public function create_or_reuse(\WC_Order $order, array $settings, string $direction_key): string
    {
        $mutex = new DatabaseMutex();
        if (! $mutex->acquire(InvoiceLockStore::creation_mutex_resource($order->get_id()))) {
            throw new PayKassaException('A payment request for this order is already being prepared or created. Please wait and retry.');
        }
        try {
            // Read all reusable-invoice and lease state only after acquiring the
            // connection-bound fence. A live slow worker cannot lose this fence
            // merely because the durable diagnostic lease reached its TTL.
            return $this->create_or_reuse_guarded($this->reload($order->get_id()), $settings, $direction_key, $mutex);
        } finally {
            $mutex->release();
        }
    }

    /** @param array<string, mixed> $settings */
    private function create_or_reuse_guarded(\WC_Order $order, array $settings, string $direction_key, DatabaseMutex $mutex): string
    {
        $locks = new InvoiceLockStore();
        $reused = $this->reuse_active_invoice($order, $locks);
        if (null !== $reused) {
            return $reused;
        }

        $state = (string) $order->get_meta(OrderMeta::STATE, true);
        if (PaymentState::INVOICE_UNCERTAIN === $state || InvoiceLockStatus::UNCERTAIN === $locks->status($order->get_id())) {
            throw new PayKassaException('A previous PayKassa create has an uncertain provider outcome. A merchant must resolve it before another invoice can be created.');
        }
        if (in_array($state, array( PaymentState::PAID, PaymentState::CONFLICTED, PaymentState::MANUAL_REVIEW ), true)) {
            throw new PayKassaException('This PayKassa payment is not eligible for another automatic invoice.');
        }

        $reservation = $locks->acquire($order->get_id());
        if (! $reservation->acquired()) {
            if (InvoiceReservation::ERROR === $reservation->status) {
                throw new PayKassaException('Could not safely reserve this payment request. Please try again later.');
            }
            // A concurrent owner might have committed while this worker was
            // attempting to reserve. Reload through Woo CRUD before deciding.
            $order = $this->reload($order->get_id());
            $reused = $this->reuse_active_invoice($order, $locks);
            if (null !== $reused) {
                return $reused;
            }
            if (InvoiceLockStatus::UNCERTAIN === $locks->status($order->get_id())) {
                throw new PayKassaException('A previous PayKassa create has an uncertain provider outcome. A merchant must resolve it before another invoice can be created.');
            }
            throw new PayKassaException('A payment request is already being prepared or created. Please wait and retry.');
        }

        $order_currency = strtoupper((string) $order->get_currency());
        $order_amount = (string) $order->get_total();
        $registry = new PaymentSystemRegistry();
        $direction = $registry->direction($direction_key);
        $availability = new GatewayAvailability();
        if (! is_array($direction) || ! $availability->enabled_direction($direction_key, $settings) || ! isset($availability->directions_for_order_currency($order_currency, $settings)[$direction_key])) {
            $locks->fail($order->get_id(), $reservation->owner_token);
            throw new PayKassaException('The selected PayKassa payment currency and network are unavailable for this order.');
        }

        // A failed rate request has not contacted SCI and is safe to retry.
        try {
            // Persist the exact secret/mode profile before creating an invoice.
            // A later settings rotation must not make its callback unverifiable.
            $context = ( new SciCredentialStore() )->retain($settings);
            $quote = ( new CurrencyRateClient() )->quote($order_amount, $order_currency, $direction['currency'], $direction['system']);
            (new MinimumPaymentPolicy())->assert_allows($quote->payment_amount, $direction['system'], $direction['currency'], $settings);
            $sci = ( new PayKassaClientFactory() )->sci($settings);
        } catch (PayKassaException $exception) {
            $locks->fail($order->get_id(), $reservation->owner_token);
            throw $exception;
        }

        $environment = 'yes' === ($settings['testmode'] ?? 'no') ? 'test' : 'live';
        $attempt = array(
            'status' => InvoiceLockStatus::PREPARING,
            'started_at' => gmdate('c'),
            'order_amount' => $order_amount,
            'order_currency' => $order_currency,
            'payment_amount' => $quote->payment_amount,
            'payment_currency' => $direction['currency'],
            'payment_system' => $direction['system'],
            'conversion_pair' => $quote->rate_pair,
            'conversion_rate' => $quote->exchange_rate,
            'conversion_quoted_at' => $quote->quoted_at,
            'environment' => $environment,
            'merchant_context' => $context,
        );
        try {
            $this->transition($order, PaymentState::INVOICE_CREATING);
            $order->update_meta_data(OrderMeta::INVOICE_ATTEMPT, $attempt);
            $order->save();
        } catch (\Throwable $exception) {
            $locks->fail($order->get_id(), $reservation->owner_token);
            throw new PayKassaException('The PayKassa invoice attempt could not be persisted safely.', 0, $exception);
        }

        try {
            $mutex->assert_owned();
            if (! $locks->begin_creation($order->get_id(), $reservation->owner_token)) {
                throw new PayKassaException('The PayKassa invoice reservation changed before provider creation. Please retry.');
            }
            // This is the final local operation before the money-changing
            // remote side effect. It catches a lost DB connection/mutex after
            // the durable owner-token CAS and before SCI is contacted.
            $mutex->assert_owned();
        } catch (\Throwable $exception) {
            $locks->fail($order->get_id(), $reservation->owner_token);
            $this->record_attempt_outcome($order->get_id(), PaymentState::INVOICE_FAILED, InvoiceLockStatus::FAILED);
            throw $exception;
        }

        try {
            $comment = sprintf('WooCommerce order #%s', $order->get_order_number());
            $result = $sci->create_payment($quote->payment_amount, $direction['system_key'], $direction['currency'], $order->get_id(), $comment);
        } catch (PaymentCreationException $exception) {
            // A documented error=true response is a definitive rejection, not
            // an ambiguous transport outcome; no usable invoice is returned.
            $locks->fail($order->get_id(), $reservation->owner_token);
            $this->record_attempt_outcome($order->get_id(), PaymentState::INVOICE_FAILED, InvoiceLockStatus::FAILED);
            throw $exception;
        } catch (ProviderUnavailableException | InvalidResponseException $exception) {
            $locks->uncertain($order->get_id(), $reservation->owner_token);
            $this->record_attempt_outcome($order->get_id(), PaymentState::INVOICE_UNCERTAIN, InvoiceLockStatus::UNCERTAIN);
            throw $exception;
        } catch (PayKassaException $exception) {
            $locks->uncertain($order->get_id(), $reservation->owner_token);
            $this->record_attempt_outcome($order->get_id(), PaymentState::INVOICE_UNCERTAIN, InvoiceLockStatus::UNCERTAIN);
            throw $exception;
        } catch (\Throwable $exception) {
            $locks->uncertain($order->get_id(), $reservation->owner_token);
            $this->record_attempt_outcome($order->get_id(), PaymentState::INVOICE_UNCERTAIN, InvoiceLockStatus::UNCERTAIN);
            throw new PayKassaException('The PayKassa create outcome is uncertain and another invoice was not created.', 0, $exception);
        }

        // SCI 0.4 createOrder documents only url/method/params. It exposes no
        // invoice expiry timestamp, so null is an explicit unknown rather than
        // a locally invented TTL derived from the currency quote.
        $snapshot = new PaymentSnapshot(
            $order->get_id(),
            $order_amount,
            $order_currency,
            $result->system,
            $result->currency,
            $result->invoice_id,
            gmdate('c'),
            'test' === $environment,
            'hosted',
            $context,
            null,
            (string) ($settings['shop_id'] ?? ''),
            $quote->payment_amount,
            $quote->rate_pair,
            $quote->exchange_rate,
            $quote->source,
            $quote->quoted_at
        );

        try {
            $order = $this->reload($order->get_id());
            $this->transition($order, PaymentState::INVOICE_CREATED);
            $order->update_meta_data(OrderMeta::SNAPSHOT, wp_json_encode($snapshot->to_array()));
            $order->update_meta_data(OrderMeta::STATE, PaymentState::INVOICE_CREATED);
            $order->update_meta_data('_paykassa_provider_system', $result->system);
            $order->update_meta_data('_paykassa_provider_currency', $result->currency);
            $order->update_meta_data('_paykassa_payment_amount', $quote->payment_amount);
            $order->update_meta_data('_paykassa_conversion_pair', $quote->rate_pair);
            $order->update_meta_data('_paykassa_conversion_rate', $quote->exchange_rate);
            $order->update_meta_data(OrderMeta::PAYMENT_LINK_HASH, $result->invoice_id);
            // Compatibility only: legacy versions mislabeled this link hash as
            // an invoice id. New domain logic never reads this key.
            $order->update_meta_data('_paykassa_invoice_id', $result->invoice_id);
            $order->update_meta_data('_paykassa_environment', $environment);
            $order->update_meta_data(OrderMeta::CREDENTIAL_CONTEXT, $context);
            $order->delete_meta_data(OrderMeta::INVOICE_ATTEMPT);
            if (RedirectUrlValidator::is_valid($result->redirect_url)) {
                $order->update_meta_data('_paykassa_redirect_url', $result->redirect_url);
            } else {
                $order->delete_meta_data('_paykassa_redirect_url');
            }
            $order->save();
        } catch (\Throwable $exception) {
            // PayKassa returned success, but the immutable snapshot was not
            // durably committed. Never create a replacement automatically.
            $locks->uncertain($order->get_id(), $reservation->owner_token);
            $this->record_attempt_outcome($order->get_id(), PaymentState::INVOICE_UNCERTAIN, InvoiceLockStatus::UNCERTAIN);
            throw new PayKassaException('PayKassa created a payment request, but its snapshot could not be saved. Manual review is required.', 0, $exception);
        }

        if (! $locks->complete($order->get_id(), $reservation->owner_token, $snapshot->fingerprint())) {
            // The exact invoice is already in WooCommerce. A later checkout
            // can atomically adopt and reuse it, never create a second one.
            throw new PayKassaException('The payment request was saved but its lifecycle record could not be finalized. Please retry to reuse the saved invoice.');
        }

        if (! RedirectUrlValidator::is_valid($result->redirect_url)) {
            $order = $this->reload($order->get_id());
            $order->update_meta_data('_paykassa_manual_review_reason', 'unsafe_invoice_redirect');
            $this->transition($order, PaymentState::MANUAL_REVIEW);
            $order->update_meta_data(OrderMeta::STATE, PaymentState::MANUAL_REVIEW);
            $order->save();
            $order->add_order_note(__('PayKassa returned a created invoice with an untrusted redirect URL. The invoice was preserved and automatic retry is blocked.', 'paykassa'));
            throw new PayKassaException('PayKassa returned an unsafe payment URL.');
        }

        $order = $this->reload($order->get_id());
        try {
            $this->transition($order, PaymentState::AWAITING_PAYMENT);
            $order->update_meta_data(OrderMeta::STATE, PaymentState::AWAITING_PAYMENT);
            $order->save();
        } catch (\Throwable $exception) {
            // INVOICE_CREATED plus the immutable snapshot and a completed lock
            // is already a safe, webhook-settleable state. The next checkout
            // normalizes it to AWAITING_PAYMENT while reusing the same link.
        }
        $order->add_order_note(__('PayKassa payment invoice created; awaiting provider confirmation.', 'paykassa'));
        do_action('paykassa_payment_created', $order, $snapshot);
        return $result->redirect_url;
    }

    private function reuse_active_invoice(\WC_Order $order, InvoiceLockStore $locks): ?string
    {
        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::SNAPSHOT, true));
        if (! $snapshot instanceof PaymentSnapshot || $snapshot->order_id !== $order->get_id()) {
            return null;
        }
        $state = (string) $order->get_meta(OrderMeta::STATE, true);
        $status = $locks->status($order->get_id());
        if (InvoiceLockStatus::EXPIRED === $status || ($snapshot->expiration_is_known() && $snapshot->is_expired())) {
            ( new InvoiceLifecycleService($locks) )->expire_current(
                $order,
                $snapshot->is_expired() ? 'provider_expiration_timestamp' : 'lifecycle_recovery'
            );
            return null;
        }
        if (! in_array($state, array( '', PaymentState::INVOICE_CREATING, PaymentState::INVOICE_CREATED, PaymentState::INVOICE_UNCERTAIN, PaymentState::AWAITING_PAYMENT ), true)) {
            return null;
        }
        $url = (string) $order->get_meta('_paykassa_redirect_url', true);
        if (! RedirectUrlValidator::is_valid($url)) {
            throw new PayKassaException('A previous payment request is unresolved and has no trusted redirect. Do not create another invoice.');
        }
        if (InvoiceLockStatus::PREPARING === $status) {
            throw new PayKassaException('A replacement PayKassa invoice is already being prepared.');
        }
        if (! $locks->recover_created($order->get_id(), $snapshot->fingerprint())) {
            throw new PayKassaException('The saved PayKassa invoice conflicts with its durable lifecycle record.');
        }
        if (PaymentState::AWAITING_PAYMENT !== $state) {
            if (in_array($state, array( PaymentState::INVOICE_CREATING, PaymentState::INVOICE_UNCERTAIN ), true)) {
                PaymentState::assert_transition($state, PaymentState::INVOICE_CREATED);
                $state = PaymentState::INVOICE_CREATED;
            }
            PaymentState::assert_transition($state, PaymentState::AWAITING_PAYMENT);
            $order->update_meta_data(OrderMeta::STATE, PaymentState::AWAITING_PAYMENT);
            $order->delete_meta_data(OrderMeta::INVOICE_ATTEMPT);
            $order->save();
        }
        return $url;
    }

    private function record_attempt_outcome(int $order_id, string $payment_state, string $invoice_status): void
    {
        try {
            $order = $this->reload($order_id);
            $state = (string) $order->get_meta(OrderMeta::STATE, true);
            if ($payment_state !== $state) {
                PaymentState::assert_transition($state, $payment_state);
                $order->update_meta_data(OrderMeta::STATE, $payment_state);
            }
            $attempt = $order->get_meta(OrderMeta::INVOICE_ATTEMPT, true);
            $attempt = is_array($attempt) ? $attempt : array();
            $attempt['status'] = $invoice_status;
            $attempt['updated_at'] = gmdate('c');
            $order->update_meta_data(OrderMeta::INVOICE_ATTEMPT, $attempt);
            $order->save();
            if (InvoiceLockStatus::UNCERTAIN === $invoice_status) {
                $order->add_order_note(__('PayKassa invoice creation has an uncertain provider outcome. Automatic retry is blocked until a merchant resolves it.', 'paykassa'));
            }
        } catch (\Throwable $exception) {
            // The lock table remains the authoritative fail-closed fence even
            // if WooCommerce diagnostic metadata cannot be updated.
        }
    }

    private function transition(\WC_Order $order, string $to): void
    {
        PaymentState::assert_transition((string) $order->get_meta(OrderMeta::STATE, true), $to);
        $order->update_meta_data(OrderMeta::STATE, $to);
    }

    private function reload(int $order_id): \WC_Order
    {
        $order = wc_get_order($order_id);
        if (! $order instanceof \WC_Order) {
            throw new PayKassaException('The order could not be reloaded.');
        }
        return $order;
    }
}
