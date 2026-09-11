<?php

declare(strict_types=1);

// Uninstall is deliberately conservative: financial audit data is retained by default.
defined('WP_UNINSTALL_PLUGIN') || exit;

if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('paykassa_reconcile', array(), 'paykassa');
    as_unschedule_all_actions('paykassa_reconcile_continue', array(), 'paykassa');
}

$settings = get_option('woocommerce_paykassa_settings', array());
if (! is_array($settings) || 'yes' !== ($settings['delete_data_on_uninstall'] ?? 'no')) {
    return;
}

delete_option('woocommerce_paykassa_settings');
delete_option('paykassa_sci_credential_profiles');
delete_option('paykassa_sci_credential_retention_error');
delete_option('paykassa_gateway_settings_migration_version');
delete_option('paykassa_schema_version');
delete_option('paykassa_last_reconciliation');
delete_option('paykassa_last_reconciliation_error');
delete_option('paykassa_reconciliation_job');
delete_option('paykassa_reconciliation_report');
delete_option('paykassa_last_history_scan');
delete_transient('paykassa_crypto_systems_v1');

global $wpdb;

// Dynamic recovery cursors and short-lived administrator notices are stored
// under plugin-owned prefixes. Resolve their exact names first so WordPress can
// invalidate the corresponding option/object-cache entries via delete_option().
$dynamic_option_prefixes = array(
    'paykassa_reconciliation_through_',
    '_transient_paykassa_connection_test_',
    '_transient_timeout_paykassa_connection_test_',
    '_transient_paykassa_invoice_lifecycle_',
    '_transient_timeout_paykassa_invoice_lifecycle_',
);
foreach ($dynamic_option_prefixes as $option_prefix) {
    $option_names = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like($option_prefix) . '%'
        )
    );
    if (! is_array($option_names)) {
        continue;
    }
    foreach ($option_names as $option_name) {
        if (is_string($option_name) && str_starts_with($option_name, $option_prefix)) {
            delete_option($option_name);
        }
    }
}

// Table names are generated exclusively from the trusted WordPress prefix.
foreach (array('paykassa_events', 'paykassa_invoice_locks') as $table_suffix) {
    $table = $wpdb->prefix . $table_suffix;
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}
