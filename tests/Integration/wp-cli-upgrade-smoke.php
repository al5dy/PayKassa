<?php

use Al5dy\PayKassaWoo\Infrastructure\Installer;
use Al5dy\PayKassaWoo\Infrastructure\DatabaseMutex;
use Al5dy\PayKassaWoo\Gateway\PayKassaGateway;
use Al5dy\PayKassaWoo\PayKassa\SciCredentialStore;

function paykassa_upgrade_assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$had_settings = false !== get_option('woocommerce_paykassa_settings', false);
$original_settings = get_option('woocommerce_paykassa_settings', false);
$original_schema = get_option(Installer::OPTION, false);
$original_currency = get_option('woocommerce_currency', false);
$original_credential_profiles = get_option(SciCredentialStore::OPTION, false);
$original_settings_migration = get_option(Installer::SETTINGS_MIGRATION_OPTION, false);
$original_retention_error = get_option(Installer::CREDENTIAL_RETENTION_ERROR, false);
$legacy = require __DIR__ . '/../fixtures/legacy-settings.php';

try {
    update_option('woocommerce_currency', 'USD', false);
    delete_option('woocommerce_paykassa_settings');
    $fresh_fields = (new PayKassaGateway())->get_form_fields();
    paykassa_upgrade_assert(array('USD') === ($fresh_fields['accepted_order_currencies']['default'] ?? null), 'Fresh USD installation must default Accepted WooCommerce currencies to USD.');
    $legacy['enabled_systems'] = 'tron_trc20';
    update_option('woocommerce_paykassa_settings', $legacy, false);
    update_option(Installer::OPTION, '0', false);
    delete_option(Installer::SETTINGS_MIGRATION_OPTION);
    Installer::migrate();

    $migrated = get_option('woocommerce_paykassa_settings', array());
    paykassa_upgrade_assert(is_array($migrated), 'Legacy gateway settings must remain an array.');
    foreach (array( 'shop_id', 'shop_password', 'testmode', 'title', 'description' ) as $key) {
        paykassa_upgrade_assert(($legacy[ $key ] ?? null) === ($migrated[ $key ] ?? null), sprintf('Legacy setting %s must survive migration.', $key));
    }
    paykassa_upgrade_assert(array('USD') === ($migrated['accepted_order_currencies'] ?? null), 'USD store currency must migrate to the explicit accepted order currency list.');
    paykassa_upgrade_assert(array('tron_trc20:USDT') === ($migrated['enabled_payment_directions'] ?? null), 'Legacy enabled_systems must migrate to explicit payment directions.');
    $legacy_credential_context = SciCredentialStore::legacy_context((string) $legacy['shop_id'], 'yes' === ($legacy['testmode'] ?? 'no'));
    $legacy_credential_profile = (new SciCredentialStore())->settings_for_context($legacy_credential_context);
    paykassa_upgrade_assert(is_array($legacy_credential_profile) && $legacy['shop_password'] === $legacy_credential_profile['shop_password'], 'Upgrade must retain the current SCI secret under the legacy snapshot context before any future credential rotation.');
    paykassa_upgrade_assert((new PayKassaGateway())->is_available(), 'A correctly configured upgraded USD store must retain PayKassa gateway availability.');
    paykassa_upgrade_assert(Installer::SCHEMA_VERSION === get_option(Installer::OPTION), 'Schema version must be updated.');
    paykassa_upgrade_assert(! Installer::gateway_settings_migration_required(), 'Successful settings upgrade must persist its independent migration version.');
    global $wpdb;
    $table = $wpdb->prefix . 'paykassa_events';
    paykassa_upgrade_assert($table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)), 'Webhook event table must exist after migration.');
    Installer::migrate();
    paykassa_upgrade_assert($migrated === get_option('woocommerce_paykassa_settings', array()), 'Migration must be idempotent and retain normalized settings.');
    $ordinary_settings_reads = 0;
    $settings_read_observer = static function ($value) use (&$ordinary_settings_reads) {
        ++$ordinary_settings_reads;
        return $value;
    };
    add_filter('pre_option_woocommerce_paykassa_settings', $settings_read_observer);
    Installer::migrate_gateway_settings();
    remove_filter('pre_option_woocommerce_paykassa_settings', $settings_read_observer);
    paykassa_upgrade_assert(0 === $ordinary_settings_reads, 'A current settings migration marker must skip reading and normalizing gateway settings on ordinary requests.');
    update_option(Installer::SETTINGS_MIGRATION_OPTION, '2', true);
    paykassa_upgrade_assert(! Installer::gateway_settings_migration_required(), 'An older plugin must not run a settings migration backwards over a future marker.');
    update_option(Installer::SETTINGS_MIGRATION_OPTION, Installer::SETTINGS_MIGRATION_VERSION, true);

    $credential_mutex = new DatabaseMutex();
    paykassa_upgrade_assert($credential_mutex->acquire('sci-credential-store'), 'Credential fast-path fixture must hold the global credential mutex.');
    try {
        $retained_context = (new SciCredentialStore())->retain($migrated, true);
        paykassa_upgrade_assert(SciCredentialStore::context((string) $legacy['shop_id'], (string) $legacy['shop_password'], false) === $retained_context, 'An identical retained profile must remain available without acquiring the busy credential mutex.');
    } finally {
        $credential_mutex->release();
    }

    // Exact partial-refactor state observed in a real upgraded store: the new
    // direction key was an empty placeholder and the accepted-currency field
    // contained the store currency plus the complete old generated crypto
    // default. This is migration debris, not a merchant opt-in to every asset.
    $intermediate_crypto_default = array(
        'BTC',
        'ETH',
        'LTC',
        'DOGE',
        'DASH',
        'BCH',
        'XRP',
        'TRX',
        'XLM',
        'BNB',
        'USDT',
        'USDC',
        'ADA',
        'EOS',
        'SHIB',
        'TON',
    );
    $partial_refactor = array_replace($legacy, array(
        'accepted_order_currencies' => array_merge(array('USD'), $intermediate_crypto_default),
        'enabled_payment_directions' => array(),
        'enabled_systems' => 'tron_trc20',
    ));
    update_option('woocommerce_currency', 'USD', false);
    update_option('woocommerce_paykassa_settings', $partial_refactor, false);
    delete_option(Installer::SETTINGS_MIGRATION_OPTION);
    Installer::migrate_gateway_settings();
    $partial_migrated = get_option('woocommerce_paykassa_settings', array());
    paykassa_upgrade_assert(array('USD') === ($partial_migrated['accepted_order_currencies'] ?? null), 'The generated crypto currency default plus USD must collapse to the current USD store currency.');
    paykassa_upgrade_assert(array('tron_trc20:USDT') === ($partial_migrated['enabled_payment_directions'] ?? null), 'An empty new direction placeholder must migrate the non-empty legacy enabled_systems value.');
    paykassa_upgrade_assert(! array_key_exists('enabled_systems', $partial_migrated), 'Migration must consume the legacy system setting so fallback cannot remain dynamic.');
    paykassa_upgrade_assert((new PayKassaGateway())->is_available(), 'The exact upgraded USD-store fixture must retain gateway availability.');
    Installer::migrate_gateway_settings();
    paykassa_upgrade_assert($partial_migrated === get_option('woocommerce_paykassa_settings', array()), 'The repaired partial-refactor settings must remain unchanged on repeated migration.');

    // A smaller selection cannot be the complete generated default and must
    // remain untouched even while its legacy direction setting is consumed.
    $custom_partial_refactor = array_replace($legacy, array(
        'accepted_order_currencies' => array('USD', 'BTC', 'ETH', 'LTC'),
        'enabled_payment_directions' => array(),
        'enabled_systems' => 'tron_trc20',
    ));
    update_option('woocommerce_paykassa_settings', $custom_partial_refactor, false);
    delete_option(Installer::SETTINGS_MIGRATION_OPTION);
    Installer::migrate_gateway_settings();
    $custom_migrated = get_option('woocommerce_paykassa_settings', array());
    paykassa_upgrade_assert(array('USD', 'BTC', 'ETH', 'LTC') === ($custom_migrated['accepted_order_currencies'] ?? null), 'A genuine custom accepted-currency selection must survive partial-refactor migration.');
    paykassa_upgrade_assert(array('tron_trc20:USDT') === ($custom_migrated['enabled_payment_directions'] ?? null), 'Custom accepted currencies must not prevent legacy direction migration.');
    paykassa_upgrade_assert(! array_key_exists('enabled_systems', $custom_migrated), 'Custom-list migration must consume the legacy system setting.');

    // After legacy data has been consumed, an explicit empty current list is
    // merchant intent and must remain fail-closed on every later request.
    $partial_migrated['enabled_payment_directions'] = array();
    update_option('woocommerce_paykassa_settings', $partial_migrated, false);
    Installer::migrate_gateway_settings();
    $explicitly_disabled = get_option('woocommerce_paykassa_settings', array());
    paykassa_upgrade_assert(array() === ($explicitly_disabled['enabled_payment_directions'] ?? null), 'A post-migration explicit empty direction list must stay disabled.');
    paykassa_upgrade_assert(! (new PayKassaGateway())->is_available(), 'Consumed legacy settings must not re-enable intentionally disabled payment directions.');

    $blank_legacy = $legacy;
    unset($blank_legacy['enabled_systems']);
    unset($blank_legacy['accepted_order_currencies'], $blank_legacy['enabled_payment_directions']);
    update_option('woocommerce_currency', 'EUR', false);
    update_option('woocommerce_paykassa_settings', $blank_legacy, false);
    delete_option(Installer::SETTINGS_MIGRATION_OPTION);
    Installer::migrate_gateway_settings();
    $blank_migrated = get_option('woocommerce_paykassa_settings', array());
    paykassa_upgrade_assert(array('EUR') === ($blank_migrated['accepted_order_currencies'] ?? null), 'EUR store currency must migrate to the explicit accepted order currency list.');
    paykassa_upgrade_assert(count((new \Al5dy\PayKassaWoo\PayKassa\PaymentSystemRegistry())->directions()) === count($blank_migrated['enabled_payment_directions'] ?? array()), 'Blank legacy enabled_systems must freeze all currently known payment directions.');
    paykassa_upgrade_assert((new PayKassaGateway())->is_available(), 'A correctly configured upgraded EUR store must retain PayKassa gateway availability.');

    update_option('woocommerce_currency', 'BYN', false);
    unset($blank_migrated['accepted_order_currencies'], $blank_migrated['enabled_payment_directions']);
    update_option('woocommerce_paykassa_settings', $blank_migrated, false);
    delete_option(Installer::SETTINGS_MIGRATION_OPTION);
    Installer::migrate_gateway_settings();
    paykassa_upgrade_assert(! (new PayKassaGateway())->is_available() && null !== Installer::settings_configuration_problem(), 'Unsupported store currency must be unavailable with a clear administrator configuration warning.');

    // A failed credential write must never falsely mark settings migration as
    // complete. The next request retries and clears the non-secret warning.
    $retry_settings = array_replace($migrated, array(
        'shop_id' => 'migration-retry-merchant',
        'shop_password' => 'migration-retry-secret',
    ));
    update_option('woocommerce_currency', 'USD', false);
    update_option('woocommerce_paykassa_settings', $retry_settings, false);
    delete_option(Installer::SETTINGS_MIGRATION_OPTION);
    delete_option(Installer::CREDENTIAL_RETENTION_ERROR);
    $migration_mutex = new DatabaseMutex();
    paykassa_upgrade_assert($migration_mutex->acquire('sci-credential-store'), 'Migration retry fixture must hold the credential mutex.');
    try {
        Installer::migrate_gateway_settings();
    } finally {
        $migration_mutex->release();
    }
    paykassa_upgrade_assert(Installer::gateway_settings_migration_required(), 'A busy credential store must leave settings migration retryable.');
    paykassa_upgrade_assert(false !== get_option(Installer::CREDENTIAL_RETENTION_ERROR, false), 'A failed credential retention attempt must expose a non-secret administrator warning marker.');
    Installer::migrate_gateway_settings();
    paykassa_upgrade_assert(! Installer::gateway_settings_migration_required(), 'Settings migration must complete after credential storage becomes available.');
    paykassa_upgrade_assert(false === get_option(Installer::CREDENTIAL_RETENTION_ERROR, false), 'Successful retry must clear the credential retention warning marker.');
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
    if (false === $original_currency) {
        delete_option('woocommerce_currency');
    } else {
        update_option('woocommerce_currency', $original_currency, false);
    }
    if (false === $original_credential_profiles) {
        delete_option(SciCredentialStore::OPTION);
    } else {
        update_option(SciCredentialStore::OPTION, $original_credential_profiles, false);
    }
    if (false === $original_settings_migration) {
        delete_option(Installer::SETTINGS_MIGRATION_OPTION);
    } else {
        update_option(Installer::SETTINGS_MIGRATION_OPTION, $original_settings_migration, true);
    }
    if (false === $original_retention_error) {
        delete_option(Installer::CREDENTIAL_RETENTION_ERROR);
    } else {
        update_option(Installer::CREDENTIAL_RETENTION_ERROR, $original_retention_error, false);
    }
}
