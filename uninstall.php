<?php
/** Uninstall is deliberately conservative: financial audit data is retained by default. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'paykassa_reconcile', array(), 'paykassa' );
	as_unschedule_all_actions( 'paykassa_reconcile_continue', array(), 'paykassa' );
}

$settings = get_option( 'woocommerce_paykassa_settings', array() );
if ( ! is_array( $settings ) || 'yes' !== ( $settings['delete_data_on_uninstall'] ?? 'no' ) ) {
	return;
}

delete_option( 'woocommerce_paykassa_settings' );
delete_option( 'paykassa_sci_credential_profiles' );
delete_option( 'paykassa_sci_credential_retention_error' );
delete_option( 'paykassa_schema_version' );
delete_option( 'paykassa_last_reconciliation' );
delete_option( 'paykassa_last_reconciliation_error' );
delete_option( 'paykassa_reconciliation_job' );
delete_option( 'paykassa_reconciliation_report' );
delete_option( 'paykassa_last_history_scan' );
global $wpdb;
$table = $wpdb->prefix . 'paykassa_events';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // Table name is generated exclusively from the WordPress prefix.
