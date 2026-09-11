<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Webhook;

/**
 * Durable idempotency and processing lease for provider evidence.
 *
 * A reservation is intentionally not an acknowledgement. Callers must finish
 * a terminal event only after the related WooCommerce change is durable.
 */
final class WebhookEventStore
{
    private const LEASE_SECONDS = 120;

    public function event_key(string $transaction_id, string $merchant_context, string $environment): string
    {
        return hash('sha256', $merchant_context . "\0" . $environment . "\0" . $transaction_id);
    }

    public function acquire(string $transaction_id, string $hash_fingerprint, int $order_id, string $merchant_context, string $environment, string $source = EvidenceSource::WEBHOOK_INVOICE): EventReservation
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_events';
        $key = $this->event_key($transaction_id, $merchant_context, $environment);
        $token = bin2hex(random_bytes(32));
        $lease = gmdate('Y-m-d H:i:s', time() + self::LEASE_SECONDS);

        // INSERT IGNORE is used only to turn the expected unique-key collision
        // into a deterministic branch. A real DB failure is checked below and
        // returned as ERROR, never confused with a duplicate.
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (event_key, provider_transaction_id, hash_fingerprint, merchant_context, environment, order_id, event_type, source, status, owner_token, lease_expires_at, attempts, created_at)
             VALUES (%s, %s, %s, %s, %s, %d, 'payment', %s, 'received', %s, %s, 1, UTC_TIMESTAMP())",
            $key,
            $transaction_id,
            $hash_fingerprint,
            $merchant_context,
            $environment,
            $order_id,
            $source,
            $token,
            $lease
        ));
        if (false === $inserted) {
            return new EventReservation(EventReservation::ERROR, $key);
        }
        if (1 === $inserted) {
            return new EventReservation(EventReservation::ACQUIRED, $key, $token);
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT status, order_id FROM {$table} WHERE event_key = %s",
            $key
        ));
        if (!is_object($existing) || !isset($existing->status, $existing->order_id) || (int) $existing->order_id !== $order_id) {
            return new EventReservation(EventReservation::ERROR, $key);
        }

        // A worker that died before finishing can be reclaimed after its lease.
        // The status predicate serializes competing redeliveries atomically.
        $reclaimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'received', owner_token = %s, lease_expires_at = %s, attempts = attempts + 1
             WHERE event_key = %s
               AND status IN ('received', 'settling')
               AND lease_expires_at < UTC_TIMESTAMP()",
            $token,
            $lease,
            $key
        ));
        if (false === $reclaimed) {
            return new EventReservation(EventReservation::ERROR, $key);
        }
        return new EventReservation(1 === $reclaimed ? EventReservation::ACQUIRED : EventReservation::DUPLICATE, $key, 1 === $reclaimed ? $token : '');
    }

    public function begin_settlement(string $event_key, string $owner_token): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_events';
        $lease = gmdate('Y-m-d H:i:s', time() + self::LEASE_SECONDS);
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'settling', lease_expires_at = %s WHERE event_key = %s AND status = 'received' AND owner_token = %s",
            $lease,
            $event_key,
            $owner_token
        ));
    }

    /** Returns false on a failed or lost DB update; the provider must retry. */
    public function finish(string $event_key, string $owner_token, string $status, string $error_code = ''): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_events';
        return 1 === $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = %s, error_code = NULLIF(%s, ''), processed_at = UTC_TIMESTAMP(), lease_expires_at = NULL, owner_token = NULL
             WHERE event_key = %s AND status IN ('received', 'settling') AND owner_token = %s",
            $status,
            $error_code,
            $event_key,
            $owner_token
        ));
    }

    public function status(string $event_key): string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_events';
        $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE event_key = %s", $event_key));
        return is_string($status) ? $status : '';
    }
}
