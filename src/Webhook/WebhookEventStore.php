<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Webhook;

final class WebhookEventStore
{
    /** Returns false if an identical provider transaction was already recorded. */
    public function reserve(string $transaction_id, string $hash_fingerprint, int $order_id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_events';
        $previous_suppression = $wpdb->suppress_errors(true);
        try {
            $result = $wpdb->query($wpdb->prepare("INSERT INTO {$table} (provider_transaction_id, hash_fingerprint, order_id, event_type, status, created_at) VALUES (%s, %s, %d, %s, %s, UTC_TIMESTAMP())", $transaction_id, $hash_fingerprint, $order_id, 'payment', 'received'));
        } finally {
            $wpdb->suppress_errors($previous_suppression);
        }
        return 1 === $result;
    }

    public function finish(string $transaction_id, string $status, string $error_code = ''): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_events';
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET status = %s, error_code = NULLIF(%s, ''), processed_at = UTC_TIMESTAMP() WHERE provider_transaction_id = %s", $status, $error_code, $transaction_id));
    }

    public function status(string $transaction_id): string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'paykassa_events';
        $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE provider_transaction_id = %s", $transaction_id));
        return is_string($status) ? $status : '';
    }
}
