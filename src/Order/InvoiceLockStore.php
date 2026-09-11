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
             VALUES (%d, %s, %s, %s, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            $order_id,
            InvoiceLockStatus::PREPARING,
            $token,
            $lease
        ));
        if (false === $inserted) {
            return new InvoiceReservation(InvoiceReservation::ERROR);
        }
        if (1 === $inserted) {
            return new InvoiceReservation(InvoiceReservation::ACQUIRED, $token);
        }

        // Once the remote-create boundary has been crossed, lease expiry does
        // not prove that PayKassa failed to create an invoice. Freeze that
        // attempt as uncertain instead of ever handing it to a second worker.
        if (! $this->mark_abandoned_creation_uncertain($order_id)) {
            return new InvoiceReservation(InvoiceReservation::ERROR);
        }

        // Failed/expired work and abandoned pre-provider preparation can be
        // retried. Uncertain and created invoices are deliberately excluded.
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = %s, owner_token = %s, snapshot_hash = NULL, lease_expires_at = %s, updated_at = UTC_TIMESTAMP(), attempts = attempts + 1
             WHERE order_id = %d
               AND (status IN (%s, %s) OR (status = %s AND lease_expires_at < UTC_TIMESTAMP()))",
            InvoiceLockStatus::PREPARING,
            $token,
            $lease,
            $order_id,
            InvoiceLockStatus::FAILED,
            InvoiceLockStatus::EXPIRED,
            InvoiceLockStatus::PREPARING
        ));
        if (false === $claimed) {
            return new InvoiceReservation(InvoiceReservation::ERROR);
        }
        return 1 === $claimed ? new InvoiceReservation(InvoiceReservation::ACQUIRED, $token) : new InvoiceReservation(InvoiceReservation::BUSY);
    }

    /** Cross the point after which a crash/timeout has an ambiguous provider outcome. */
    public function begin_creation(int $order_id, string $owner_token): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_invoice_locks';
        $lease = gmdate('Y-m-d H:i:s', time() + 120);
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, lease_expires_at = %s, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status = %s AND owner_token = %s",
            InvoiceLockStatus::CREATING,
            $lease,
            $order_id,
            InvoiceLockStatus::PREPARING,
            $owner_token
        ));
    }

    public function complete(int $order_id, string $owner_token, string $snapshot_hash): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_invoice_locks';
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, snapshot_hash = %s, lease_expires_at = NULL, owner_token = NULL, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status = %s AND owner_token = %s",
            InvoiceLockStatus::CREATED,
            $snapshot_hash,
            $order_id,
            InvoiceLockStatus::CREATING,
            $owner_token
        ));
    }

    public function fail(int $order_id, string $owner_token): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_invoice_locks';
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, owner_token = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status IN (%s, %s) AND owner_token = %s",
            InvoiceLockStatus::FAILED,
            $order_id,
            InvoiceLockStatus::PREPARING,
            InvoiceLockStatus::CREATING,
            $owner_token
        ));
    }

    public function uncertain(int $order_id, string $owner_token): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_invoice_locks';
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, owner_token = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status = %s AND owner_token = %s",
            InvoiceLockStatus::UNCERTAIN,
            $order_id,
            InvoiceLockStatus::CREATING,
            $owner_token
        ));
    }

    /**
     * Recover the crash boundary where the immutable snapshot was saved but
     * final lock bookkeeping did not complete. Reusing that exact link is safe.
     */
    public function recover_created(int $order_id, string $snapshot_hash): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_invoice_locks';
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (order_id, status, owner_token, snapshot_hash, lease_expires_at, created_at, updated_at)
             VALUES (%d, %s, NULL, %s, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            $order_id,
            InvoiceLockStatus::CREATED,
            $snapshot_hash
        ));
        if (false === $inserted) {
            return false;
        }
        if (1 === $inserted) {
            return true;
        }

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, owner_token = NULL, snapshot_hash = %s, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status IN (%s, %s) AND (snapshot_hash IS NULL OR snapshot_hash = '' OR snapshot_hash = %s)",
            InvoiceLockStatus::CREATED,
            $snapshot_hash,
            $order_id,
            InvoiceLockStatus::CREATING,
            InvoiceLockStatus::UNCERTAIN,
            $snapshot_hash
        ));
        if (false === $updated) {
            return false;
        }
        if (1 === $updated) {
            return true;
        }

        $stored_hash = $wpdb->get_var($wpdb->prepare(
            "SELECT snapshot_hash FROM {$table} WHERE order_id = %d AND status = %s",
            $order_id,
            InvoiceLockStatus::CREATED
        ));
        return is_string($stored_hash) && hash_equals($stored_hash, $snapshot_hash);
    }

    public function expire_created(int $order_id, string $snapshot_hash): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_invoice_locks';
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, owner_token = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status = %s AND snapshot_hash = %s",
            InvoiceLockStatus::EXPIRED,
            $order_id,
            InvoiceLockStatus::CREATED,
            $snapshot_hash
        ));
    }

    /** Manual-only transition after the merchant confirms no usable invoice exists. */
    public function resolve_uncertain_as_failed(int $order_id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_invoice_locks';
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, owner_token = NULL, snapshot_hash = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status = %s",
            InvoiceLockStatus::FAILED,
            $order_id,
            InvoiceLockStatus::UNCERTAIN
        ));
    }

    /**
     * Convert only an expired remote-create lease to uncertain. A false return
     * means a database error; zero affected rows is a valid no-op.
     */
    public function mark_abandoned_creation_uncertain(int $order_id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_invoice_locks';
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, owner_token = NULL, lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE order_id = %d AND status = %s AND lease_expires_at < UTC_TIMESTAMP()",
            InvoiceLockStatus::UNCERTAIN,
            $order_id,
            InvoiceLockStatus::CREATING
        ));
        return false !== $updated;
    }

    public function status(int $order_id): ?string
    {
        global $wpdb;
        if (! $this->mark_abandoned_creation_uncertain($order_id)) {
            return null;
        }
        $table = $wpdb->prefix . 'paykassa_invoice_locks';
        $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE order_id = %d", $order_id));
        return is_string($status) && '' !== $status ? $status : null;
    }
}
