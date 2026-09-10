<?php

use Al5dy\PayKassaWoo\Infrastructure\Installer;

function paykassa_upgrade_assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$had_settings = false !== get_option('woocommerce_paykassa_settings', false);
$original_settings = get_option('woocommerce_paykassa_settings', false);
$original_schema = get_option(Installer::OPTION, false);
$legacy = require __DIR__ . '/../fixtures/legacy-settings.php';

try {
    update_option('woocommerce_paykassa_settings', $legacy, false);
    update_option(Installer::OPTION, '0', false);
    Installer::migrate();

    $migrated = get_option('woocommerce_paykassa_settings', array());
    paykassa_upgrade_assert(is_array($migrated), 'Legacy gateway settings must remain an array.');
    foreach (array( 'shop_id', 'shop_password', 'testmode', 'title', 'description' ) as $key) {
        paykassa_upgrade_assert(($legacy[ $key ] ?? null) === ($migrated[ $key ] ?? null), sprintf('Legacy setting %s must survive migration.', $key));
    }
    paykassa_upgrade_assert(Installer::SCHEMA_VERSION === get_option(Installer::OPTION), 'Schema version must be updated.');
    global $wpdb;
    $table = $wpdb->prefix . 'paykassa_events';
    paykassa_upgrade_assert($table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)), 'Webhook event table must exist after migration.');
    Installer::migrate();
    paykassa_upgrade_assert($legacy === get_option('woocommerce_paykassa_settings', array()), 'Migration must be idempotent and retain credentials.');
    WP_CLI::success('PayKassa upgrade smoke: legacy settings, credentials, event schema, and repeated migration passed.');
} finally {
    if ($had_settings) {
        update_option('woocommerce_paykassa_settings', $original_settings, false);
    } else {
        delete_option('woocommerce_paykassa_settings');
    }
    if (false === $original_schema) {
        delete_option(Installer::OPTION);
    } else {
        update_option(Installer::OPTION, $original_schema, false);
    }
}
