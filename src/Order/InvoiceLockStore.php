<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Order;

final class InvoiceLockStore
{
    public function acquire(int $order_id): InvoiceReservation
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_invoice_locks';
        $lease = gmdate('Y-m-d H:i:s', time() + 120);
        $result = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$table} (order_id, status, lease_expires_at, created_at, updated_at) VALUES (%d, %s, %s, UTC_TIMESTAMP(), UTC_TIMESTAMP())", $order_id, 'creating', $lease));
        if (false === $result) {
            return new InvoiceReservation(InvoiceReservation::ERROR);
        }
        if (1 === $result) {
            return new InvoiceReservation(InvoiceReservation::ACQUIRED);
        }

        // Do not combine this compare-and-swap with INSERT ... ON DUPLICATE KEY:
        // MySQL evaluates assignments from left to right, which makes a lease
        // takeover unnecessarily subtle. A conditional UPDATE gives the caller a
        // clear ownership signal and never steals a live reservation.
        $reclaimed = $wpdb->query($wpdb->prepare("UPDATE {$table} SET lease_expires_at = %s, updated_at = UTC_TIMESTAMP(), attempts = attempts + 1 WHERE order_id = %d AND status = 'creating' AND lease_expires_at < UTC_TIMESTAMP()", $lease, $order_id));
        if (false === $reclaimed) {
            return new InvoiceReservation(InvoiceReservation::ERROR);
        }
        return new InvoiceReservation(1 === $reclaimed ? InvoiceReservation::ACQUIRED : InvoiceReservation::BUSY);
    }

    public function complete(int $order_id, string $snapshot_hash): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_invoice_locks';
        return 1 === $wpdb->query($wpdb->prepare("UPDATE {$table} SET status = %s, snapshot_hash = %s, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP() WHERE order_id = %d AND status = 'creating'", 'created', $snapshot_hash, $order_id));
    }

    public function fail(int $order_id): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_invoice_locks';
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET status = %s, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP() WHERE order_id = %d", 'failed', $order_id));
    }
}
