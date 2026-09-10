<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Infrastructure;

final class Installer
{
    // Version 3 adds owner_token to both event and invoice reservations.
    public const SCHEMA_VERSION = '3';
    public const OPTION = 'paykassa_schema_version';

    public static function activate(): void
    {
        self::migrate();
    }

    public static function migrate(): void
    {
        global $wpdb;
        $table           = $wpdb->prefix . 'paykassa_events';
        $invoice_table   = $wpdb->prefix . 'paykassa_invoice_locks';
        $charset_collate = $wpdb->get_charset_collate();
        $sql             = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		event_key char(64) NOT NULL,
		provider_transaction_id varchar(191) NOT NULL,
		hash_fingerprint char(64) NOT NULL,
		order_id bigint(20) unsigned NOT NULL,
		merchant_context char(64) NOT NULL,
		environment varchar(8) NOT NULL,
		event_type varchar(32) NOT NULL,
		status varchar(32) NOT NULL,
		owner_token char(64) NULL,
			lease_expires_at datetime NULL,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
		source varchar(32) NOT NULL DEFAULT 'webhook',
			created_at datetime NOT NULL,
			processed_at datetime NULL,
			error_code varchar(64) NULL,
			PRIMARY KEY  (id),
		UNIQUE KEY event_key (event_key),
		KEY provider_context (provider_transaction_id,merchant_context,environment),
			KEY order_status (order_id,status),
			KEY created_at (created_at)
		) {$charset_collate};";
        $invoice_sql = "CREATE TABLE {$invoice_table} (
		order_id bigint(20) unsigned NOT NULL,
		status varchar(16) NOT NULL,
		owner_token char(64) NULL,
		snapshot_hash char(64) NULL,
		lease_expires_at datetime NULL,
		attempts smallint(5) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (order_id),
		KEY lease_status (status,lease_expires_at)
		) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        dbDelta($invoice_sql);
        self::upgrade_legacy_transaction_index($table);
        if (! self::schema_is_valid()) {
            throw new \RuntimeException('PayKassa database migration verification failed.');
        }
        update_option(self::OPTION, self::SCHEMA_VERSION, false);
    }

    public static function schema_is_valid(): bool
    {
        global $wpdb;
        $events = $wpdb->prefix . 'paykassa_events';
        $locks = $wpdb->prefix . 'paykassa_invoice_locks';
        $events_found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $events));
        $locks_found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $locks));
        if ($events !== $events_found || $locks !== $locks_found) {
            return false;
        }
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$events}", 0);
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$events}", 'ARRAY_A');
        $lock_columns = $wpdb->get_col("SHOW COLUMNS FROM {$locks}", 0);
        if (! is_array($columns) || ! is_array($lock_columns) || ! is_array($indexes) || array_diff(array( 'event_key', 'lease_expires_at', 'attempts', 'merchant_context', 'environment', 'source', 'owner_token' ), $columns) || array_diff(array( 'order_id', 'status', 'lease_expires_at', 'attempts', 'owner_token' ), $lock_columns)) {
            return false;
        }
        $has_event_key = false;
        foreach ($indexes as $index) {
            if ('event_key' === ($index['Key_name'] ?? '') && '0' === (string) ($index['Non_unique'] ?? '1')) {
                $has_event_key = true;
            }
            if ('provider_transaction_id' === ($index['Column_name'] ?? '') && '0' === (string) ($index['Non_unique'] ?? '1') && 'event_key' !== ($index['Key_name'] ?? '')) {
                return false;
            }
        }
        return $has_event_key;
    }

    private static function upgrade_legacy_transaction_index(string $table): void
    {
        global $wpdb;
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}", 'ARRAY_A');
        if (! is_array($indexes)) {
            return;
        }
        foreach ($indexes as $index) {
            $name = isset($index['Key_name']) ? (string) $index['Key_name'] : '';
            if ('event_key' !== $name && 'provider_transaction_id' === ($index['Column_name'] ?? '') && '0' === (string) ($index['Non_unique'] ?? '1')) {
                // The table and index name are obtained from MySQL metadata; no
                // request data is interpolated. This migration permits the same
                // provider transaction value in distinct merchant/test contexts.
                $wpdb->query("ALTER TABLE {$table} DROP INDEX `{$name}`");
            }
        }
    }
}
