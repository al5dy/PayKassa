<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Order;

use Al5dy\PayKassaWoo\Infrastructure\DatabaseMutex;
use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;

/** Coordinates explicit, audited operator resolution of invoice lifecycle states. */
final class InvoiceLifecycleService
{
    private readonly InvoiceLockStore $locks;

    public function __construct(?InvoiceLockStore $locks = null)
    {
        $this->locks = $locks ?? new InvoiceLockStore();
    }

    /**
     * Retire a known hosted invoice. The provider contract has no documented
     * expiry lookup, so this is called only for a snapshot with a known expiry
     * or after an explicit merchant decision.
     */
    public function expire_current(
        \WC_Order $order,
        string $reason = 'merchant_confirmed_invoice_unusable',
        int $resolved_by = 0
    ): void {
        $order = $this->reload($order);
        $this->assert_unpaid_paykassa_order($order);
        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::SNAPSHOT, true));
        if (! $snapshot instanceof PaymentSnapshot || $snapshot->order_id !== $order->get_id()) {
            throw new PayKassaException('No immutable PayKassa invoice snapshot is available to retire.');
        }

        $snapshot_hash = $snapshot->fingerprint();
        $status = $this->locks->status($order->get_id());
        if (null === $status || InvoiceLockStatus::CREATING === $status || InvoiceLockStatus::UNCERTAIN === $status) {
            if (! $this->locks->recover_created($order->get_id(), $snapshot_hash)) {
                throw new PayKassaException('The saved PayKassa invoice could not be reconciled with its lifecycle record.');
            }
            $status = InvoiceLockStatus::CREATED;
        }
        if (InvoiceLockStatus::CREATED === $status && ! $this->locks->expire_created($order->get_id(), $snapshot_hash)) {
            throw new PayKassaException('The PayKassa invoice lifecycle changed concurrently. Reload the order and review it again.');
        }
        if (! in_array($status, array( InvoiceLockStatus::CREATED, InvoiceLockStatus::EXPIRED ), true)) {
            throw new PayKassaException('Only a confirmed created PayKassa invoice can be retired.');
        }

        $this->archive_snapshot($order, $snapshot, $reason, $resolved_by);
        $state = (string) $order->get_meta(OrderMeta::STATE, true);
        if (PaymentState::EXPIRED !== $state) {
            if (in_array($state, array( PaymentState::INVOICE_CREATING, PaymentState::INVOICE_UNCERTAIN ), true)) {
                PaymentState::assert_transition($state, PaymentState::INVOICE_CREATED);
                $state = PaymentState::INVOICE_CREATED;
            }
            PaymentState::assert_transition($state, PaymentState::EXPIRED);
            $order->update_meta_data(OrderMeta::STATE, PaymentState::EXPIRED);
        }
        // The immutable record remains in RETIRED_SNAPSHOTS, but it is no
        // longer the active agreement against which a new invoice is created.
        $order->delete_meta_data(OrderMeta::SNAPSHOT);
        $order->delete_meta_data('_paykassa_redirect_url');
        $order->delete_meta_data(OrderMeta::PAYMENT_LINK_HASH);
        $order->delete_meta_data('_paykassa_invoice_id');
        $order->delete_meta_data('_paykassa_provider_system');
        $order->delete_meta_data('_paykassa_provider_currency');
        $order->delete_meta_data('_paykassa_payment_amount');
        $order->delete_meta_data('_paykassa_conversion_pair');
        $order->delete_meta_data('_paykassa_conversion_rate');
        $order->update_meta_data(OrderMeta::INVOICE_RESOLUTION, array(
            'action' => 'expired',
            'reason' => $reason,
            'resolved_at' => gmdate('c'),
            'resolved_by' => $resolved_by,
            'snapshot_hash' => $snapshot_hash,
        ));
        $order->save();
        $order->add_order_note(__('PayKassa invoice was retired. A later provider-confirmed payment for it will require manual review; checkout may create one replacement invoice.', 'paykassa'));
    }

    /**
     * Release an ambiguous creation only after a merchant has verified outside
     * this plugin that no usable provider invoice exists.
     */
    public function resolve_uncertain_as_failed(\WC_Order $order, int $resolved_by = 0): void
    {
        $mutex = new DatabaseMutex();
        if (! $mutex->acquire(InvoiceLockStore::creation_mutex_resource($order->get_id()))) {
            throw new PayKassaException('The original PayKassa creation worker is still active. Its uncertain result cannot be released yet.');
        }
        try {
            $this->resolve_uncertain_as_failed_guarded($order, $resolved_by, $mutex);
        } finally {
            $mutex->release();
        }
    }

    private function resolve_uncertain_as_failed_guarded(\WC_Order $order, int $resolved_by, DatabaseMutex $mutex): void
    {
        $mutex->assert_owned();
        $order = $this->reload($order);
        $this->assert_unpaid_paykassa_order($order);
        if (! $this->locks->mark_abandoned_creation_uncertain($order->get_id())) {
            throw new PayKassaException('The PayKassa invoice lifecycle could not be read safely.');
        }

        $snapshot = PaymentSnapshot::from_json((string) $order->get_meta(OrderMeta::SNAPSHOT, true));
        if ($snapshot instanceof PaymentSnapshot) {
            if ($this->locks->recover_created($order->get_id(), $snapshot->fingerprint())) {
                throw new PayKassaException('A saved PayKassa invoice exists and must be reused or retired; it cannot be resolved as a failed create.');
            }
            throw new PayKassaException('A saved PayKassa invoice conflicts with the lifecycle record. Manual financial review is required.');
        }
        if (InvoiceLockStatus::UNCERTAIN !== $this->locks->status($order->get_id())) {
            throw new PayKassaException('This order has no uncertain PayKassa invoice creation to resolve.');
        }

        $state = (string) $order->get_meta(OrderMeta::STATE, true);
        if (PaymentState::INVOICE_FAILED !== $state) {
            PaymentState::assert_transition($state, PaymentState::INVOICE_FAILED);
            $order->update_meta_data(OrderMeta::STATE, PaymentState::INVOICE_FAILED);
        }
        $attempt = $order->get_meta(OrderMeta::INVOICE_ATTEMPT, true);
        $attempt = is_array($attempt) ? $attempt : array();
        $attempt['status'] = 'manually_resolved_failed';
        $attempt['resolved_at'] = gmdate('c');
        $attempt['resolved_by'] = $resolved_by;
        $order->update_meta_data(OrderMeta::INVOICE_ATTEMPT, $attempt);
        $order->update_meta_data(OrderMeta::INVOICE_RESOLUTION, array(
            'action' => 'uncertain_to_failed',
            'reason' => 'merchant_confirmed_no_usable_invoice',
            'resolved_at' => gmdate('c'),
            'resolved_by' => $resolved_by,
        ));
        // Persist the operator's confirmation before releasing the durable
        // provider-side-effect fence. A failed save therefore stays blocked.
        $order->save();
        $mutex->assert_owned();
        if (! $this->locks->resolve_uncertain_as_failed($order->get_id())) {
            throw new PayKassaException('The uncertain PayKassa invoice changed concurrently and remains blocked.');
        }
        $order->add_order_note(__('A merchant manually confirmed that the uncertain PayKassa create produced no usable invoice. One new checkout attempt is now allowed.', 'paykassa'));
    }

    /** @return list<PaymentSnapshot> */
    public static function retired_snapshots(\WC_Order $order): array
    {
        $records = $order->get_meta(OrderMeta::RETIRED_SNAPSHOTS, true);
        if (! is_array($records)) {
            return array();
        }
        $snapshots = array();
        foreach ($records as $record) {
            if (! is_array($record) || ! isset($record['snapshot']) || ! is_array($record['snapshot'])) {
                continue;
            }
            $snapshot = PaymentSnapshot::from_array($record['snapshot']);
            if ($snapshot instanceof PaymentSnapshot) {
                $snapshots[] = $snapshot;
            }
        }
        return $snapshots;
    }

    private function archive_snapshot(\WC_Order $order, PaymentSnapshot $snapshot, string $reason, int $resolved_by): void
    {
        $records = $order->get_meta(OrderMeta::RETIRED_SNAPSHOTS, true);
        $records = is_array($records) ? $records : array();
        $key = $snapshot->fingerprint();
        if (! isset($records[$key])) {
            $records[$key] = array(
                'snapshot' => $snapshot->to_array(),
                'retired_at' => gmdate('c'),
                'reason' => $reason,
                'resolved_by' => $resolved_by,
            );
            $order->update_meta_data(OrderMeta::RETIRED_SNAPSHOTS, $records);
        }
    }

    private function reload(\WC_Order $order): \WC_Order
    {
        $fresh = wc_get_order($order->get_id());
        if (! $fresh instanceof \WC_Order) {
            throw new PayKassaException('The WooCommerce order could not be reloaded.');
        }
        return $fresh;
    }

    private function assert_unpaid_paykassa_order(\WC_Order $order): void
    {
        if ('paykassa' !== $order->get_payment_method()) {
            throw new PayKassaException('This order does not belong to PayKassa.');
        }
        if ($order->is_paid() || PaymentState::PAID === (string) $order->get_meta(OrderMeta::STATE, true)) {
            throw new PayKassaException('A paid order invoice lifecycle cannot be changed.');
        }
    }
}
