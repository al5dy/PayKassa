<?php
/** Uninstall is deliberately conservative: financial audit data is retained by default. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'woocommerce_paykassa_settings', array() );
if ( ! is_array( $settings ) || 'yes' !== ( $settings['delete_data_on_uninstall'] ?? 'no' ) ) {
	return;
}

delete_option( 'woocommerce_paykassa_settings' );
delete_option( 'paykassa_schema_version' );
delete_option( 'paykassa_last_reconciliation' );
delete_option( 'paykassa_last_reconciliation_error' );
global $wpdb;
$table = $wpdb->prefix . 'paykassa_events';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // Table name is generated exclusively from the WordPress prefix.
