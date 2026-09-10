<?php

declare(strict_types=1);

use Al5dy\PayKassaWoo\Infrastructure\Installer;
use Al5dy\PayKassaWoo\Order\InvoiceLockStore;
use Al5dy\PayKassaWoo\Plugin;
use Al5dy\PayKassaWoo\Webhook\WebhookEventStore;

// This test replaces the plugin tables with historical fixtures. It may only
// run on the disposable database created by scripts/test-integration.sh.
if (! defined('PAYKASSA_TEST_DATABASE') || true !== PAYKASSA_TEST_DATABASE || ! preg_match('/^paykassa_test_[a-z0-9]+$/', DB_NAME)) {
    throw new RuntimeException('Schema regression requires a disposable PayKassa test database.');
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

/** @return array{events:array,invoice_locks:array} */
$rows = static function (): array {
    global $wpdb;
    return array(
        'events' => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paykassa_events ORDER BY id", ARRAY_A),
        'invoice_locks' => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paykassa_invoice_locks ORDER BY order_id", ARRAY_A),
    );
};

$seed_v2 = static function () use ($assert): void {
    global $wpdb;
    $fixture = require __DIR__ . '/../fixtures/schema-v2.php';
    // Exact, plugin-owned tables in the validated disposable test database.
    foreach (array('paykassa_events', 'paykassa_invoice_locks') as $suffix) {
        $assert(false !== $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$suffix}"), 'Cannot reset disposable plugin table.');
    }
    foreach ($fixture($wpdb->prefix, $wpdb->get_charset_collate()) as $sql) {
        $assert(false !== $wpdb->query($sql), 'Cannot create historical schema-v2 fixture.');
    }
    update_option(Installer::OPTION, '2', false);
    update_option('woocommerce_paykassa_settings', require __DIR__ . '/../fixtures/legacy-settings.php', false);

    foreach (array(101 => 'received', 102 => 'processed') as $id => $status) {
        $assert(1 === $wpdb->insert($wpdb->prefix . 'paykassa_events', array(
            'event_key' => hash('sha256', 'schema-v2-event-' . $id),
            'provider_transaction_id' => 'schema-v2-transaction-' . $id,
            'hash_fingerprint' => hash('sha256', 'schema-v2-fingerprint-' . $id),
            'order_id' => $id,
            'merchant_context' => hash('sha256', 'schema-v2-merchant'),
            'environment' => 'test',
            'event_type' => 'payment',
            'status' => $status,
            'lease_expires_at' => 'received' === $status ? '2026-01-01 00:02:00' : null,
            'attempts' => 2,
            'source' => 'webhook',
            'created_at' => '2026-01-01 00:00:00',
            'processed_at' => 'processed' === $status ? '2026-01-01 00:01:00' : null,
            'error_code' => null,
        )), 'Cannot seed historical event.');
        $assert(1 === $wpdb->insert($wpdb->prefix . 'paykassa_invoice_locks', array(
            'order_id' => $id,
            'status' => 'received' === $status ? 'creating' : 'created',
            'snapshot_hash' => 'processed' === $status ? hash('sha256', 'schema-v2-snapshot') : null,
            'lease_expires_at' => 'received' === $status ? '2026-01-01 00:02:00' : null,
            'attempts' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:01:00',
        )), 'Cannot seed historical invoice reservation.');
    }
    foreach (array('paykassa_events', 'paykassa_invoice_locks') as $suffix) {
        $assert(! in_array('owner_token', $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}{$suffix}"), true), 'Fixture must actually omit owner_token.');
    }
    $assert('2' === get_option(Installer::OPTION), 'Fixture must start at schema version 2.');
    $assert(! Installer::schema_is_valid(), 'Schema-v2 fixture must be incompatible with token-based runtime SQL.');
};

/** @param array<string, array> $old_rows */
$assert_preserved = static function (array $old_rows, array $old_settings) use ($assert, $rows): void {
    $records_by_table = $rows();
    foreach ($records_by_table as $table => $records) {
        foreach ($records as $index => $record) {
            $assert(array_key_exists('owner_token', $record) && null === $record['owner_token'], 'Upgraded historical rows must have a nullable owner token.');
            unset($records_by_table[$table][$index]['owner_token']);
        }
    }
    $assert($old_rows === $records_by_table, 'Migration must preserve historical events, transaction identity, snapshots, leases and timestamps.');
    $assert($old_settings === get_option('woocommerce_paykassa_settings'), 'Migration must preserve the existing merchant settings and credentials.');
};

global $wpdb;
$seed_v2();
$old_rows = $rows();
$old_settings = get_option('woocommerce_paykassa_settings');

// This is the ordinary file-update path: do not call migrate() directly here.
// With the buggy version constant (2), register() skips the required DDL.
(new Plugin())->register();
$assert('3' === get_option(Installer::OPTION), 'A normal plugin update must automatically upgrade stored schema 2 to 3.');
$assert(Installer::schema_is_valid(), 'Both tables and their required indexes must verify after automatic upgrade.');
$assert_preserved($old_rows, $old_settings);

foreach (array('paykassa_events', 'paykassa_invoice_locks') as $suffix) {
    $column = $wpdb->get_row("SHOW COLUMNS FROM {$wpdb->prefix}{$suffix} LIKE 'owner_token'", ARRAY_A);
    $assert(is_array($column) && 'char(64)' === $column['Type'] && 'YES' === $column['Null'], 'owner_token must be char(64) NULL in each table.');
}

// Exercise the SQL that failed after file-only upgrades on the old schema.
$locks = new InvoiceLockStore();
$lock = $locks->acquire(103);
$assert($lock->acquired() && 64 === strlen($lock->owner_token), 'New invoice reservation must persist its owner token after upgrade.');
$assert($locks->complete(103, $lock->owner_token, hash('sha256', 'new-snapshot')), 'Token-guarded invoice completion must work after upgrade.');
$events = new WebhookEventStore();
$event = $events->acquire('schema-v3-transaction', hash('sha256', 'new-fingerprint'), 103, hash('sha256', 'schema-v2-merchant'), 'test');
$assert($event->acquired() && 64 === strlen($event->owner_token), 'New event must persist its owner token after upgrade.');
$assert($events->begin_settlement($event->event_key, $event->owner_token), 'Token-guarded event settlement must work after upgrade.');
$assert($events->finish($event->event_key, $event->owner_token, 'processed'), 'Token-guarded event finalization must work after upgrade.');

$upgraded_rows = $rows();
Installer::migrate();
$assert($upgraded_rows === $rows(), 'Repeated migration must not change records or tokens.');
// Some v2 installations already received the token columns through manual
// migration. Their option must still advance safely without resetting rows.
update_option(Installer::OPTION, '2', false);
(new Plugin())->register();
$assert('3' === get_option(Installer::OPTION) && $upgraded_rows === $rows(), 'Already applied token columns with stored version 2 must also upgrade safely.');
$ddl_count = 0;
$observe_ddl = static function (string $query) use (&$ddl_count): string {
    if (preg_match('/^\s*(?:CREATE|ALTER|DROP)\s+TABLE\b/i', $query)) {
        ++$ddl_count;
    }
    return $query;
};
add_filter('query', $observe_ddl);
try {
    (new Plugin())->register();
} finally {
    remove_filter('query', $observe_ddl);
}
$assert(0 === $ddl_count, 'An already upgraded request must not rerun DDL.');

// Fail the second table's ALTER statement with a real MySQL error. Verify that
// a partial migration keeps version 2 and that ordinary bootstrap retries it.
$seed_v2();
$old_rows = $rows();
$failures = 0;
$fail_owner_column = static function (string $query) use ($wpdb, &$failures): string {
    if (str_starts_with($query, 'ALTER TABLE ' . $wpdb->prefix . 'paykassa_invoice_locks ADD COLUMN owner_token')) {
        ++$failures;
        return "ALTER TABLE {$wpdb->prefix}paykassa_invoice_locks ADD COLUMN order_id bigint(20) unsigned";
    }
    return $query;
};
$previous_suppression = $wpdb->suppress_errors(true);
add_filter('query', $fail_owner_column);
try {
    (new Plugin())->register();
} finally {
    remove_filter('query', $fail_owner_column);
    $wpdb->suppress_errors($previous_suppression);
}
$assert(1 === $failures, 'Fault injection must hit the real dbDelta owner-token ALTER.');
$assert('2' === get_option(Installer::OPTION), 'Failed schema verification must never mark version 3 installed.');
$assert(! Installer::schema_is_valid(), 'Partial schema must remain invalid until retried.');
(new Plugin())->register();
$assert('3' === get_option(Installer::OPTION) && Installer::schema_is_valid(), 'Next plugin registration must recover the partially applied migration.');
$assert_preserved($old_rows, $old_settings);

// Leave the throwaway site usable for the ordinary payment smoke afterwards.
delete_option('woocommerce_paykassa_settings');
WP_CLI::success('Schema v2 -> v3: automatic upgrade, both owner_token columns, data preservation, token SQL, idempotency and failed-DDL retry passed.');
