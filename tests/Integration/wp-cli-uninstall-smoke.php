<?php

declare(strict_types=1);

function paykassa_uninstall_assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

global $wpdb;
$uninstall_file = WP_PLUGIN_DIR . '/paykassa/uninstall.php';
paykassa_uninstall_assert(is_file($uninstall_file), 'Installed PayKassa ZIP must contain uninstall.php.');

$fixed_options = array(
    'paykassa_sci_credential_profiles',
    'paykassa_sci_credential_retention_error',
    'paykassa_gateway_settings_migration_version',
    'paykassa_schema_version',
    'paykassa_last_reconciliation',
    'paykassa_last_reconciliation_error',
    'paykassa_reconciliation_job',
    'paykassa_reconciliation_report',
    'paykassa_last_history_scan',
);
$cursor_options = array(
    'paykassa_reconciliation_through_' . str_repeat('a', 64),
    'paykassa_reconciliation_through_' . str_repeat('b', 64),
);
$tables = array(
    $wpdb->prefix . 'paykassa_events',
    $wpdb->prefix . 'paykassa_invoice_locks',
);
$unrelated_option = 'paykassa_reconciliation_throughout_' . str_repeat('c', 64);

foreach ($fixed_options as $option) {
    update_option($option, 'uninstall-fixture', false);
}
foreach ($cursor_options as $option) {
    update_option($option, '2026-09-11T00:00:00+00:00', false);
}
set_transient('paykassa_crypto_systems_v1', array('fixture' => true), DAY_IN_SECONDS);
set_transient('paykassa_connection_test_123', 'connected', DAY_IN_SECONDS);
set_transient('paykassa_invoice_lifecycle_123_456', 'fixture', DAY_IN_SECONDS);
update_option($unrelated_option, 'must-survive', false);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    define('WP_UNINSTALL_PLUGIN', true);
}

update_option('woocommerce_paykassa_settings', array('delete_data_on_uninstall' => 'no'), false);
include $uninstall_file;
paykassa_uninstall_assert(false !== get_option($fixed_options[0], false), 'Opt-out uninstall must preserve plugin options.');
paykassa_uninstall_assert(false !== get_option($cursor_options[0], false), 'Opt-out uninstall must preserve dynamic recovery cursors.');
foreach ($tables as $table) {
    paykassa_uninstall_assert($table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))), 'Opt-out uninstall must preserve PayKassa tables.');
}

update_option('woocommerce_paykassa_settings', array('delete_data_on_uninstall' => 'yes'), false);
include $uninstall_file;
paykassa_uninstall_assert(false === get_option('woocommerce_paykassa_settings', false), 'Opt-in uninstall must delete gateway settings.');
foreach (array_merge($fixed_options, $cursor_options) as $option) {
    paykassa_uninstall_assert(false === get_option($option, false), 'Opt-in uninstall must delete option ' . $option . '.');
}
paykassa_uninstall_assert(false === get_transient('paykassa_crypto_systems_v1'), 'Opt-in uninstall must delete the payment-system cache transient.');
paykassa_uninstall_assert(false === get_transient('paykassa_connection_test_123'), 'Opt-in uninstall must delete dynamic connection-test transients.');
paykassa_uninstall_assert(false === get_transient('paykassa_invoice_lifecycle_123_456'), 'Opt-in uninstall must delete dynamic invoice-lifecycle transients.');
paykassa_uninstall_assert('must-survive' === get_option($unrelated_option), 'Cleanup must not delete a similarly named non-PayKassa option.');
foreach ($tables as $table) {
    paykassa_uninstall_assert(null === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))), 'Opt-in uninstall must drop table ' . $table . '.');
}
delete_option($unrelated_option);

WP_CLI::success('PayKassa uninstall smoke: opt-out preservation and complete opt-in settings, cursor, transient, event and invoice-lock cleanup passed.');
