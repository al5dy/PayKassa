<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Order;

final class InvoiceLockStore
{
    public function acquire(int $order_id): InvoiceReservation
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_invoice_locks';
        $token = bin2hex(random_bytes(32));
        $lease = gmdate('Y-m-d H:i:s', time() + 120);
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (order_id, status, owner_token, lease_expires_at, created_at, updated_at)
             VALUES (%d, 'creating', %s, %s, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            $order_id,
            $token,
            $lease
        ));
        if (false === $inserted) {
            return new InvoiceReservation(InvoiceReservation::ERROR);
        }
        if (1 === $inserted) {
            return new InvoiceReservation(InvoiceReservation::ACQUIRED, $token);
        }

        // Failed pre-flight work can be retried. An ambiguous remote create is
        // stored as uncertain and deliberately never reclaimed automatically.
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'creating', owner_token = %s, lease_expires_at = %s, updated_at = UTC_TIMESTAMP(), attempts = attempts + 1
             WHERE order_id = %d
               AND (status = 'failed' OR (status = 'creating' AND lease_expires_at < UTC_TIMESTAMP()))",
            $token,
            $lease,
            $order_id
        ));
        if (false === $claimed) {
            return new InvoiceReservation(InvoiceReservation::ERROR);
        }
        return 1 === $claimed ? new InvoiceReservation(InvoiceReservation::ACQUIRED, $token) : new InvoiceReservation(InvoiceReservation::BUSY);
    }

    public function complete(int $order_id, string $owner_token, string $snapshot_hash): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_invoice_locks';
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'created', snapshot_hash = %s, lease_expires_at = NULL, owner_token = NULL, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status = 'creating' AND owner_token = %s",
            $snapshot_hash,
            $order_id,
            $owner_token
        ));
    }

    public function fail(int $order_id, string $owner_token): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_invoice_locks';
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'failed', owner_token = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status = 'creating' AND owner_token = %s",
            $order_id,
            $owner_token
        ));
    }

    public function uncertain(int $order_id, string $owner_token): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_invoice_locks';
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'uncertain', lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status = 'creating' AND owner_token = %s",
            $order_id,
            $owner_token
        ));
    }
}
