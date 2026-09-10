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
    public function process(PaymentEvidence $evidence): array
    {
        $order = wc_get_order($evidence->order_id);
        if (! $order instanceof \WC_Order || 'paykassa' !== $order->get_payment_method()) {
            return array( 'accepted' => false, 'ack' => '' );
        }
        if (! $this->events->reserve($evidence->transaction_id, $evidence->hash_fingerprint, $order->get_id())) {
            $status = $this->events->status($evidence->transaction_id);
            $acknowledged = in_array($status, array( 'processed', 'duplicate', 'manual_review' ), true);
            return array( 'accepted' => $acknowledged, 'ack' => $acknowledged ? $order->get_id() . '|success' : '' );
        }
        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::SNAPSHOT, true));
        $settings = get_option('woocommerce_paykassa_settings', array());
        if (! $snapshot instanceof PaymentSnapshot || ! is_array($settings) || ! $this->matches($snapshot, $evidence, $order, $settings)) {
            $this->events->finish($evidence->transaction_id, 'rejected', 'payment_mismatch');
            $this->mark_manual_review($order, __('PayKassa verified a payment that does not match the immutable invoice snapshot. Manual review required.', 'paykassa'));
            $this->logger->log('warning', 'webhook_mismatch', array( 'order_id' => $order->get_id(), 'transaction_id' => $evidence->transaction_id, 'error_code' => 'payment_mismatch' ));
            do_action('paykassa_payment_conflict', $order, $evidence);
            return array( 'accepted' => false, 'ack' => '' );
        }
        if ($order->has_status(wc_get_is_paid_statuses())) {
            $this->events->finish($evidence->transaction_id, 'duplicate');
            return array( 'accepted' => true, 'ack' => $order->get_id() . '|success' );
        }
        if ($order->has_status('cancelled')) {
            $this->events->finish($evidence->transaction_id, 'manual_review', 'late_cancelled_payment');
            $this->mark_manual_review($order, __('PayKassa confirmed a payment after this order was cancelled. Funds were not ignored; manual review is required before fulfilment.', 'paykassa'));
            do_action('paykassa_payment_conflict', $order, $evidence);
            return array( 'accepted' => true, 'ack' => $order->get_id() . '|success' );
        }
        $order->update_meta_data(OrderMeta::TRANSACTION, $evidence->transaction_id);
        $order->update_meta_data(OrderMeta::HASH_FINGERPRINT, $evidence->hash_fingerprint);
        $order->update_meta_data(OrderMeta::LAST_WEBHOOK, gmdate('c'));
        $order->update_meta_data(OrderMeta::STATE, PaymentState::PAID);
        $order->update_meta_data('_paykassa_provider_amount', $evidence->amount);
        $order->update_meta_data('_paykassa_payment_address', $evidence->address);
        $order->update_meta_data('_paykassa_tag', $evidence->tag);
        $order->save();
        $order->payment_complete($evidence->transaction_id);
        $order->add_order_note(__('PayKassa payment confirmed by provider verification.', 'paykassa'));
        $this->events->finish($evidence->transaction_id, 'processed');
        $this->logger->log('info', 'payment_confirmed', array( 'order_id' => $order->get_id(), 'transaction_id' => $evidence->transaction_id, 'state' => PaymentState::PAID ));
        do_action('paykassa_payment_confirmed', $order, $evidence);
        return array( 'accepted' => true, 'ack' => $order->get_id() . '|success' );
    }

    /** @param array<string, mixed> $settings */
    private function matches(PaymentSnapshot $snapshot, PaymentEvidence $evidence, \WC_Order $order, array $settings): bool
    {
        return $snapshot->order_id === $order->get_id()
            && Decimal::equal($snapshot->expected_amount, $evidence->amount)
            && strtoupper($snapshot->provider_currency) === strtoupper($evidence->currency)
            && strtolower($snapshot->provider_system) === strtolower($evidence->system)
            && hash_equals($snapshot->provider_invoice_id, $evidence->payment_link_hash)
            && hash_equals((string) ( $settings['shop_id'] ?? '' ), $evidence->shop_id)
            && $snapshot->test_mode === ( 'yes' === ( $settings['testmode'] ?? 'no' ) );
    }

    private function mark_manual_review(\WC_Order $order, string $note): void
    {
        $order->update_meta_data(OrderMeta::STATE, PaymentState::MANUAL_REVIEW);
        $order->save();
        $order->update_status('on-hold', $note);
    }
}
