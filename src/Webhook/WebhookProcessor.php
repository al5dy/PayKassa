<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Webhook;

use Al5dy\PayKassaWoo\Infrastructure\Logger;
use Al5dy\PayKassaWoo\Order\OrderMeta;
use Al5dy\PayKassaWoo\Order\PaymentSnapshot;
use Al5dy\PayKassaWoo\Order\PaymentState;
use Al5dy\PayKassaWoo\PayKassa\Dto\PaymentEvidence;
use Al5dy\PayKassaWoo\Support\Decimal;

final class WebhookProcessor
{
    public function __construct(private readonly WebhookEventStore $events, private readonly Logger $logger)
    {
    }

    /** @return array{accepted:bool,ack:string} */
    public function process(PaymentEvidence $evidence, string $source = 'webhook'): array
    {
        $order = wc_get_order($evidence->order_id);
        if (! $order instanceof \WC_Order || 'paykassa' !== $order->get_payment_method()) {
            return $this->retry();
        }
        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::SNAPSHOT, true));
        if (! $snapshot instanceof PaymentSnapshot) {
            return $this->retry();
        }

        $context = '' !== $snapshot->merchant_context
            ? $snapshot->merchant_context
            : hash('sha256', $evidence->shop_id . "\0" . $snapshot->environment());
        $reservation = $this->events->acquire($evidence->transaction_id, $evidence->hash_fingerprint, $order->get_id(), $context, $snapshot->environment(), $source);
        if (EventReservation::ERROR === $reservation->status) {
            $this->logger->log('error', 'webhook_event_store_failed', array('order_id' => $order->get_id(), 'transaction_id' => $evidence->transaction_id));
            return $this->retry();
        }
        if (EventReservation::DUPLICATE === $reservation->status) {
            $status = $this->events->status($reservation->event_key);
            return in_array($status, array('processed', 'duplicate', 'manual_review'), true) ? $this->ack($order) : $this->retry();
        }
        if (! $this->events->begin_settlement($reservation->event_key, $reservation->owner_token)) {
            return $this->retry();
        }

        try {
            if (! $this->matches($snapshot, $evidence, $order)) {
                $this->mark_manual_review($order, __('PayKassa verified a payment that does not match the immutable invoice snapshot. Manual review required.', 'paykassa'));
                return $this->finished($reservation->event_key, $reservation->owner_token, 'rejected', 'payment_mismatch', false, $order);
            }
            $stored_transaction = (string) $order->get_meta(OrderMeta::TRANSACTION, true);
            if ($order->has_status(wc_get_is_paid_statuses())) {
                if ('' !== $stored_transaction && hash_equals($stored_transaction, $evidence->transaction_id)) {
                    return $this->finished($reservation->event_key, $reservation->owner_token, 'duplicate', '', true, $order);
                }
                // A distinct, verified transaction for an already-paid order is
                // evidence of a duplicate/overpayment, not a harmless retry.
                $order->update_meta_data('_paykassa_additional_transaction_id', $evidence->transaction_id);
                $order->update_meta_data('_paykassa_manual_review_reason', 'additional_provider_transaction');
                $this->mark_manual_review($order, __('PayKassa reported an additional verified payment for an already paid order. Manual financial review is required.', 'paykassa'));
                do_action('paykassa_payment_conflict', $order, $evidence);
                return $this->finished($reservation->event_key, $reservation->owner_token, 'manual_review', 'additional_transaction', true, $order);
            }
            if ($order->has_status('cancelled')) {
                $this->mark_manual_review($order, __('PayKassa confirmed a payment after this order was cancelled. Funds were not ignored; manual review is required before fulfilment.', 'paykassa'));
                do_action('paykassa_payment_conflict', $order, $evidence);
                return $this->finished($reservation->event_key, $reservation->owner_token, 'manual_review', 'late_cancelled_payment', true, $order);
            }

            // This exact state is the recoverable boundary: if PHP died after
            // save() but before payment_complete(), a reclaimed event with the
            // same transaction resumes the WC settlement below.
            if (! (PaymentState::PAID === (string) $order->get_meta(OrderMeta::STATE, true) && hash_equals($stored_transaction, $evidence->transaction_id))) {
                PaymentState::assert_transition((string) $order->get_meta(OrderMeta::STATE, true), PaymentState::PAID);
                $order->update_meta_data(OrderMeta::TRANSACTION, $evidence->transaction_id);
                $order->update_meta_data(OrderMeta::HASH_FINGERPRINT, $evidence->hash_fingerprint);
                $order->update_meta_data(OrderMeta::LAST_WEBHOOK, gmdate('c'));
                $order->update_meta_data(OrderMeta::STATE, PaymentState::PAID);
                $order->update_meta_data('_paykassa_provider_amount', $evidence->amount);
                $order->update_meta_data('_paykassa_payment_address', $evidence->address);
                $order->update_meta_data('_paykassa_tag', $evidence->tag);
                $order->save();
            }
            // WooCommerce itself is responsible for stock and only executes its
            // payment lifecycle once the order is not yet paid.
            $order->payment_complete($evidence->transaction_id);
            $order->add_order_note(__('PayKassa payment confirmed by provider verification.', 'paykassa'));
            $this->logger->log('info', 'payment_confirmed', array('order_id' => $order->get_id(), 'transaction_id' => $evidence->transaction_id, 'state' => PaymentState::PAID));
            do_action('paykassa_payment_confirmed', $order, $evidence);
            return $this->finished($reservation->event_key, $reservation->owner_token, 'processed', '', true, $order);
        } catch (\Throwable $exception) {
            // Do not finish: the lease lets a subsequent provider delivery resume
            // safely. No acknowledgement is sent for an uncertain settlement.
            $this->logger->log('error', 'webhook_settlement_failed', array('order_id' => $order->get_id(), 'transaction_id' => $evidence->transaction_id, 'error_code' => Logger::fingerprint($exception->getMessage())));
            return $this->retry();
        }
    }

    private function ack(\WC_Order $order): array
    {
        return array('accepted' => true, 'ack' => $order->get_id() . '|success');
    }

    private function retry(): array
    {
        return array('accepted' => false, 'ack' => '');
    }

    private function finished(string $event_key, string $owner_token, string $status, string $error, bool $accepted, \WC_Order $order): array
    {
        if (! $this->events->finish($event_key, $owner_token, $status, $error)) {
            return $this->retry();
        }
        return $accepted ? $this->ack($order) : $this->retry();
    }

    private function matches(PaymentSnapshot $snapshot, PaymentEvidence $evidence, \WC_Order $order): bool
    {
        $legacy_settings = get_option('woocommerce_paykassa_settings', array());
        $expected_shop = '' !== $snapshot->merchant_shop_id ? $snapshot->merchant_shop_id : (is_array($legacy_settings) ? (string) ($legacy_settings['shop_id'] ?? '') : '');
        return $snapshot->order_id === $order->get_id()
            && Decimal::equal($snapshot->expected_amount, (string) $order->get_total())
            && Decimal::equal($snapshot->expected_amount, $evidence->amount)
            && strtoupper($snapshot->provider_currency) === strtoupper($evidence->currency)
            && strtolower($snapshot->provider_system) === strtolower($evidence->system)
            && hash_equals($snapshot->provider_invoice_id, $evidence->payment_link_hash)
            && '' !== $expected_shop
            && hash_equals($expected_shop, $evidence->shop_id);
    }

    private function mark_manual_review(\WC_Order $order, string $note): void
    {
        $from = (string) $order->get_meta(OrderMeta::STATE, true);
        if (PaymentState::MANUAL_REVIEW === $from) {
            return;
        }
        if (PaymentState::PAID !== $from) {
            PaymentState::assert_transition($from, PaymentState::MANUAL_REVIEW);
            $order->update_meta_data(OrderMeta::STATE, PaymentState::MANUAL_REVIEW);
        }
        $order->save();
        // Never regress a paid WooCommerce order merely because an additional
        // transaction needs human review. The internal audit metadata and note
        // make the conflict visible without re-triggering fulfilment flows.
        if (! $order->has_status(wc_get_is_paid_statuses())) {
            $order->update_status('on-hold', $note);
        } else {
            $order->add_order_note($note);
        }
    }
}
