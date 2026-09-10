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
        $result = $wpdb->query($wpdb->prepare("INSERT INTO {$table} (order_id, status, lease_expires_at, created_at, updated_at) VALUES (%d, %s, %s, UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status = IF(status = 'creating' AND lease_expires_at < UTC_TIMESTAMP(), 'creating', status), lease_expires_at = IF(status = 'creating' AND lease_expires_at < UTC_TIMESTAMP(), VALUES(lease_expires_at), lease_expires_at), updated_at = IF(status = 'creating' AND lease_expires_at < UTC_TIMESTAMP(), UTC_TIMESTAMP(), updated_at)", $order_id, 'creating', $lease));
        if (false === $result) {
            return new InvoiceReservation(InvoiceReservation::ERROR);
        }
        // MySQL reports 0 for an unchanged active row, 1 for INSERT and 2 for a
        // lease takeover. Only the process that changed the row owns the lease.
        return new InvoiceReservation(in_array((int) $result, array( 1, 2 ), true) ? InvoiceReservation::ACQUIRED : InvoiceReservation::BUSY);
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
