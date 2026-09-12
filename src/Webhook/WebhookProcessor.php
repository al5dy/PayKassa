<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Webhook;

use Al5dy\PayKassaWoo\Infrastructure\Logger;
use Al5dy\PayKassaWoo\Infrastructure\DatabaseMutex;
use Al5dy\PayKassaWoo\Order\OrderMeta;
use Al5dy\PayKassaWoo\Order\InvoiceLifecycleService;
use Al5dy\PayKassaWoo\Order\PaymentSnapshot;
use Al5dy\PayKassaWoo\Order\PaymentState;
use Al5dy\PayKassaWoo\PayKassa\Dto\PaymentEvidence;
use Al5dy\PayKassaWoo\PayKassa\Dto\TransactionNotificationEvidence;
use Al5dy\PayKassaWoo\Support\Decimal;

final class WebhookProcessor
{
    public function __construct(private readonly WebhookEventStore $events, private readonly Logger $logger)
    {
    }

    /** @return array{accepted:bool,ack:string,outcome:string} */
    public function process(PaymentEvidence|TransactionNotificationEvidence $evidence, string $source = ''): array
    {
        if ('' === $source) {
            $source = $evidence instanceof TransactionNotificationEvidence
                ? EvidenceSource::WEBHOOK_TRANSACTION
                : EvidenceSource::WEBHOOK_INVOICE;
        }
        $mutex = new DatabaseMutex();
        try {
            if (! $mutex->acquire('settlement:' . $evidence->order_id)) {
                return $this->retry();
            }
            try {
                return $this->settle($evidence, $source, $mutex);
            } finally {
                $mutex->release();
            }
        } catch (\Throwable $exception) {
            $this->logger->log('error', 'settlement_mutex_failed', array('order_id' => $evidence->order_id, 'error_code' => Logger::fingerprint($exception->getMessage())));
            return $this->retry();
        }
    }

    /** @return array{accepted:bool,ack:string,outcome:string} */
    private function settle(PaymentEvidence|TransactionNotificationEvidence $evidence, string $source, DatabaseMutex $mutex): array
    {
        $order = wc_get_order($evidence->order_id);
        if (! $order instanceof \WC_Order || 'paykassa' !== $order->get_payment_method()) {
            return $this->retry();
        }
        // The mutex is acquired before reading any state that controls settlement.
        $order->get_data_store()->read($order);
        $order->read_meta_data(true);
        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::SNAPSHOT, true));
        $resolution = $this->resolve_snapshot($order, $snapshot, $evidence);
        $reference_snapshot = $resolution['reference'];
        if (! $reference_snapshot instanceof PaymentSnapshot) {
            return $this->retry();
        }
        $context = '' !== $reference_snapshot->merchant_context
            ? $reference_snapshot->merchant_context
            : hash('sha256', $evidence->shop_id . "\0" . $reference_snapshot->environment());
        $reservation = $this->events->acquire($evidence->transaction_id, $evidence->hash_fingerprint, $order->get_id(), $context, $reference_snapshot->environment(), $source);
        if (EventReservation::ERROR === $reservation->status) {
            $this->logger->log('error', 'webhook_event_store_failed', array('order_id' => $order->get_id(), 'transaction_id' => $evidence->transaction_id));
            return $this->retry();
        }
        if (EventReservation::DUPLICATE === $reservation->status) {
            $status = $this->events->status($reservation->event_key);
            if ('rejected' === $status) {
                return $this->ack($order, 'manual_review');
            }
            if ('manual_review' === $status && $evidence instanceof TransactionNotificationEvidence) {
                return $this->ack($order, 'manual_review');
            }
            if (! $resolution['compatible']) {
                return $this->retry();
            }
            return in_array($status, array('processed', 'duplicate', 'manual_review'), true) ? $this->ack($order, 'manual_review' === $status ? $status : 'duplicate') : $this->retry();
        }
        if (! $this->events->begin_settlement($reservation->event_key, $reservation->owner_token)) {
            return $this->retry();
        }

        try {
            $mutex->assert_owned();
            if ('retired_invoice_payment' === $resolution['manual_reason']) {
                return $this->record_manual_payment(
                    $reservation,
                    $order,
                    $evidence,
                    $source,
                    'retired_invoice_payment',
                    __('PayKassa verified a payment for a retired invoice. The order was not fulfilled; manual financial review is required.', 'paykassa')
                );
            }
            if (! $resolution['automatic']) {
                if ($evidence instanceof TransactionNotificationEvidence) {
                    return $this->record_manual_payment(
                        $reservation,
                        $order,
                        $evidence,
                        $source,
                        $resolution['manual_reason'],
                        $resolution['manual_note']
                    );
                }
                $order->update_meta_data('_paykassa_manual_review_reason', 'verified_payment_mismatch');
                $this->mark_manual_review($order, __('PayKassa verified a payment that does not match the immutable invoice snapshot. Manual review required.', 'paykassa'));
                return $this->finished($reservation->event_key, $reservation->owner_token, 'rejected', 'payment_mismatch', $order);
            }
            $manual_reason = (string) $order->get_meta('_paykassa_manual_review_reason', true);
            if (in_array($manual_reason, array( 'retired_invoice_payment', 'verified_payment_mismatch', 'transaction_invoice_ambiguous', 'transaction_payment_mismatch' ), true)) {
                return $this->record_manual_payment(
                    $reservation,
                    $order,
                    $evidence,
                    $source,
                    'payment_after_retired_invoice',
                    __('PayKassa verified another payment while an earlier verified payment conflict is unresolved. Automatic fulfilment remains blocked.', 'paykassa')
                );
            }
            $stored_transaction = (string) $order->get_meta(OrderMeta::TRANSACTION, true);
            if ($order->has_status(wc_get_is_paid_statuses()) || ('' !== $stored_transaction && ! hash_equals($stored_transaction, $evidence->transaction_id))) {
                if ('' !== $stored_transaction && hash_equals($stored_transaction, $evidence->transaction_id)) {
                    return $this->finished($reservation->event_key, $reservation->owner_token, 'duplicate', '', $order);
                }
                // A distinct, verified transaction for an already-paid order is
                // evidence of a duplicate/overpayment, not a harmless retry.
                $additional = $order->get_meta('_paykassa_additional_transactions', true);
                $additional = is_array($additional) ? $additional : array();
                if (isset($additional[$evidence->transaction_id])) {
                    return $this->finished($reservation->event_key, $reservation->owner_token, 'manual_review', 'additional_transaction', $order);
                }
                $additional[$evidence->transaction_id] = array('amount' => $evidence->amount, 'currency' => $evidence->currency, 'system' => $evidence->system, 'environment' => $reference_snapshot->environment(), 'source' => $source, 'at' => gmdate('c'));
                $order->update_meta_data('_paykassa_additional_transactions', $additional);
                $order->update_meta_data('_paykassa_additional_transaction_id', $evidence->transaction_id);
                $order->update_meta_data('_paykassa_manual_review_reason', 'additional_provider_transaction');
                $this->mark_manual_review($order, __('PayKassa reported an additional verified payment for a paid or settling order. Manual financial review is required.', 'paykassa'));
                do_action('paykassa_payment_conflict', $order, $evidence);
                return $this->finished($reservation->event_key, $reservation->owner_token, 'manual_review', 'additional_transaction', $order);
            }
            if ($order->has_status('cancelled') || 'late_cancelled_payment' === $order->get_meta('_paykassa_manual_review_reason', true)) {
                $order->update_meta_data('_paykassa_manual_review_reason', 'late_cancelled_payment');
                $this->mark_manual_review($order, __('PayKassa confirmed a payment after this order was cancelled. Funds were not ignored; manual review is required before fulfilment.', 'paykassa'));
                do_action('paykassa_payment_conflict', $order, $evidence);
                return $this->finished($reservation->event_key, $reservation->owner_token, 'manual_review', 'late_cancelled_payment', $order);
            }

            // This exact state is the recoverable boundary: if PHP died after
            // save() but before payment_complete(), a reclaimed event with the
            // same transaction resumes the WC settlement below.
            if (! (PaymentState::PAID === (string) $order->get_meta(OrderMeta::STATE, true) && hash_equals($stored_transaction, $evidence->transaction_id))) {
                PaymentState::assert_transition((string) $order->get_meta(OrderMeta::STATE, true), PaymentState::PAID);
                $order->update_meta_data(OrderMeta::TRANSACTION, $evidence->transaction_id);
                $order->update_meta_data(OrderMeta::HASH_FINGERPRINT, $evidence->hash_fingerprint);
                $order->update_meta_data(EvidenceSource::RECONCILIATION === $source ? OrderMeta::RECONCILIATION : OrderMeta::LAST_WEBHOOK, gmdate('c'));
                $order->update_meta_data('_paykassa_recovery_source', $source);
                $order->update_meta_data(OrderMeta::STATE, PaymentState::PAID);
                $order->update_meta_data('_paykassa_provider_amount', $evidence->amount);
                $order->update_meta_data('_paykassa_payment_address', $evidence->address);
                $order->update_meta_data('_paykassa_tag', $evidence->tag);
                $order->save();
            }
            // WooCommerce itself is responsible for stock and only executes its
            // payment lifecycle once the order is not yet paid.
            $mutex->assert_owned();
            if (! $order->payment_complete($evidence->transaction_id)) {
                return $this->retry();
            }
            $settled = wc_get_order($order->get_id());
            if (! $settled instanceof \WC_Order) {
                return $this->retry();
            }
            $settled->get_data_store()->read($settled);
            if (! $settled->has_status(wc_get_is_paid_statuses()) || $settled->get_transaction_id() !== $evidence->transaction_id) {
                return $this->retry();
            }
            $order->add_order_note(__('PayKassa payment confirmed by provider verification.', 'paykassa'));
            $this->logger->log('info', 'payment_confirmed', array('order_id' => $order->get_id(), 'transaction_id' => $evidence->transaction_id, 'state' => PaymentState::PAID));
            do_action('paykassa_payment_confirmed', $order, $evidence);
            return $this->finished($reservation->event_key, $reservation->owner_token, 'processed', '', $order);
        } catch (\Throwable $exception) {
            // Do not finish: the lease lets a subsequent provider delivery resume
            // safely. No acknowledgement is sent for an uncertain settlement.
            $this->logger->log('error', 'webhook_settlement_failed', array('order_id' => $order->get_id(), 'transaction_id' => $evidence->transaction_id, 'error_code' => Logger::fingerprint($exception->getMessage())));
            return $this->retry();
        }
    }

    private function ack(\WC_Order $order, string $outcome = 'processed'): array
    {
        return array('accepted' => true, 'ack' => $order->get_id() . '|success', 'outcome' => $outcome);
    }

    private function retry(): array
    {
        return array('accepted' => false, 'ack' => '', 'outcome' => 'retry');
    }

    private function finished(string $event_key, string $owner_token, string $status, string $error, \WC_Order $order): array
    {
        if (! $this->events->finish($event_key, $owner_token, $status, $error)) {
            return $this->retry();
        }
        // `rejected` means verified evidence was durably preserved as a
        // terminal manual-review conflict, not that provider delivery failed.
        return $this->ack($order, 'rejected' === $status ? 'manual_review' : $status);
    }

    private function matches(PaymentSnapshot $snapshot, PaymentEvidence $evidence, \WC_Order $order): bool
    {
        $legacy_settings = get_option('woocommerce_paykassa_settings', array());
        $expected_shop = '' !== $snapshot->merchant_shop_id ? $snapshot->merchant_shop_id : (is_array($legacy_settings) ? (string) ($legacy_settings['shop_id'] ?? '') : '');
        return $snapshot->order_id === $order->get_id()
            && strtoupper($snapshot->order_currency) === strtoupper((string) $order->get_currency())
            && $snapshot->environment() === $evidence->environment
            && Decimal::equal($snapshot->expected_amount, (string) $order->get_total())
            && Decimal::equal($snapshot->payment_amount, $evidence->amount)
            && strtoupper($snapshot->provider_currency) === strtoupper($evidence->currency)
            && strtolower($snapshot->provider_system) === strtolower($evidence->system)
            && hash_equals($snapshot->provider_invoice_id, $evidence->payment_link_hash)
            && '' !== $expected_shop
            && hash_equals($expected_shop, $evidence->shop_id);
    }

    /**
     * @return array{reference:?PaymentSnapshot,automatic:bool,compatible:bool,manual_reason:string,manual_note:string}
     */
    private function resolve_snapshot(
        \WC_Order $order,
        ?PaymentSnapshot $active,
        PaymentEvidence|TransactionNotificationEvidence $evidence
    ): array {
        if ($evidence instanceof PaymentEvidence) {
            $retired = $this->matching_retired_snapshot($order, $evidence);
            $active_matches = $active instanceof PaymentSnapshot && $this->matches($active, $evidence, $order);
            return array(
                'reference' => $retired instanceof PaymentSnapshot ? $retired : $active,
                'automatic' => ! $retired instanceof PaymentSnapshot && $active_matches,
                'compatible' => $retired instanceof PaymentSnapshot || $active_matches,
                'manual_reason' => $retired instanceof PaymentSnapshot ? 'retired_invoice_payment' : 'verified_payment_mismatch',
                'manual_note' => __('PayKassa verified a payment that does not match the immutable invoice snapshot. Manual review required.', 'paykassa'),
            );
        }

        $matches = array();
        if ($active instanceof PaymentSnapshot && $this->matches_transaction_snapshot($active, $evidence, $order, true)) {
            $matches[$active->fingerprint()] = array('snapshot' => $active, 'active' => true);
        }
        $retired_snapshots = InvoiceLifecycleService::retired_snapshots($order);
        foreach ($retired_snapshots as $retired) {
            if ($this->matches_transaction_snapshot($retired, $evidence, $order, false)) {
                $matches[$retired->fingerprint()] = array('snapshot' => $retired, 'active' => false);
            }
        }
        if (1 === count($matches)) {
            $match = reset($matches);
            if (is_array($match) && $match['snapshot'] instanceof PaymentSnapshot) {
                return array(
                    'reference' => $match['snapshot'],
                    'automatic' => true === $match['active'],
                    'compatible' => true,
                    'manual_reason' => true === $match['active'] ? '' : 'retired_invoice_payment',
                    'manual_note' => __('PayKassa verified a credited transaction for a retired invoice. The order was not fulfilled; manual financial review is required.', 'paykassa'),
                );
            }
        }
        if (count($matches) > 1) {
            $reference = $active;
            if (! $reference instanceof PaymentSnapshot) {
                $first = reset($matches);
                $reference = is_array($first) && $first['snapshot'] instanceof PaymentSnapshot ? $first['snapshot'] : null;
            }
            return array(
                'reference' => $reference,
                'automatic' => false,
                'compatible' => true,
                'manual_reason' => 'transaction_invoice_ambiguous',
                'manual_note' => __('PayKassa verified a credited transaction that matches more than one active or retired invoice. Automatic fulfilment is blocked for manual financial review.', 'paykassa'),
            );
        }
        $reference = $active ?? ($retired_snapshots[0] ?? null);
        return array(
            'reference' => $reference,
            'automatic' => false,
            'compatible' => false,
            'manual_reason' => 'transaction_payment_mismatch',
            'manual_note' => __('PayKassa verified a credited transaction that does not unambiguously match the immutable invoice history. Automatic fulfilment is blocked for manual financial review.', 'paykassa'),
        );
    }

    private function matches_transaction_snapshot(
        PaymentSnapshot $snapshot,
        TransactionNotificationEvidence $evidence,
        \WC_Order $order,
        bool $active
    ): bool {
        $legacy_settings = get_option('woocommerce_paykassa_settings', array());
        $expected_shop = '' !== $snapshot->merchant_shop_id ? $snapshot->merchant_shop_id : (is_array($legacy_settings) ? (string) ($legacy_settings['shop_id'] ?? '') : '');
        return $snapshot->order_id === $order->get_id()
            && 'live' === $snapshot->environment()
            && 'live' === $evidence->environment
            && Decimal::equal($snapshot->payment_amount, $evidence->amount)
            && strtoupper($snapshot->provider_currency) === strtoupper($evidence->currency)
            && strtolower($snapshot->provider_system) === strtolower($evidence->system)
            && '' !== $expected_shop
            && hash_equals($expected_shop, $evidence->shop_id)
            && (! $active || (
                strtoupper($snapshot->order_currency) === strtoupper((string) $order->get_currency())
                && Decimal::equal($snapshot->expected_amount, (string) $order->get_total())
            ));
    }

    private function matching_retired_snapshot(\WC_Order $order, PaymentEvidence $evidence): ?PaymentSnapshot
    {
        foreach (InvoiceLifecycleService::retired_snapshots($order) as $snapshot) {
            $legacy_settings = get_option('woocommerce_paykassa_settings', array());
            $expected_shop = '' !== $snapshot->merchant_shop_id ? $snapshot->merchant_shop_id : (is_array($legacy_settings) ? (string) ($legacy_settings['shop_id'] ?? '') : '');
            if (
                $snapshot->order_id === $order->get_id()
                && $snapshot->environment() === $evidence->environment
                && Decimal::equal($snapshot->payment_amount, $evidence->amount)
                && strtoupper($snapshot->provider_currency) === strtoupper($evidence->currency)
                && strtolower($snapshot->provider_system) === strtolower($evidence->system)
                && hash_equals($snapshot->provider_invoice_id, $evidence->payment_link_hash)
                && '' !== $expected_shop
                && hash_equals($expected_shop, $evidence->shop_id)
            ) {
                return $snapshot;
            }
        }
        return null;
    }

    private function record_manual_payment(
        EventReservation $reservation,
        \WC_Order $order,
        PaymentEvidence|TransactionNotificationEvidence $evidence,
        string $source,
        string $reason,
        string $note
    ): array {
        $additional = $order->get_meta('_paykassa_additional_transactions', true);
        $additional = is_array($additional) ? $additional : array();
        $additional[$evidence->transaction_id] = array(
            'amount' => $evidence->amount,
            'currency' => $evidence->currency,
            'system' => $evidence->system,
            'environment' => $evidence->environment,
            'source' => $source,
            'reason' => $reason,
            'at' => gmdate('c'),
        );
        $order->update_meta_data('_paykassa_additional_transactions', $additional);
        $order->update_meta_data('_paykassa_additional_transaction_id', $evidence->transaction_id);
        if ('' === (string) $order->get_meta('_paykassa_manual_review_reason', true)) {
            $order->update_meta_data('_paykassa_manual_review_reason', $reason);
        }
        $this->mark_manual_review($order, $note);
        do_action('paykassa_payment_conflict', $order, $evidence);
        return $this->finished($reservation->event_key, $reservation->owner_token, 'manual_review', $reason, $order);
    }

    private function mark_manual_review(\WC_Order $order, string $note): void
    {
        $from = (string) $order->get_meta(OrderMeta::STATE, true);
        if (PaymentState::MANUAL_REVIEW === $from) {
            $order->save();
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
