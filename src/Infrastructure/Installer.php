<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Infrastructure;

use Al5dy\PayKassaWoo\PayKassa\CurrencyRegistry;
use Al5dy\PayKassaWoo\PayKassa\PaymentSystemRegistry;

final class Installer
{
    // Version 3 adds owner_token to both event and invoice reservations.
    public const SCHEMA_VERSION = '3';
    public const OPTION = 'paykassa_schema_version';
    private const SETTINGS_OPTION = 'woocommerce_paykassa_settings';

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
        self::migrate_gateway_settings();
        if (! self::schema_is_valid()) {
            throw new \RuntimeException('PayKassa database migration verification failed.');
        }
        update_option(self::OPTION, self::SCHEMA_VERSION, false);
    }

    /**
     * Converts pre-fiat gateway settings exactly once into the explicit
     * currency/direction model. Runtime code must never infer old semantics.
     */
    public static function migrate_gateway_settings(): void
    {
        $settings = get_option(self::SETTINGS_OPTION, false);
        if (! is_array($settings)) {
            return;
        }
        $changed = false;
        $currency_registry = new CurrencyRegistry();
        if (! array_key_exists('accepted_order_currencies', $settings)) {
            $currency = self::store_currency();
            $settings['accepted_order_currencies'] = $currency_registry->supports_quote($currency) ? array($currency) : array();
            $changed = true;
        } else {
            $currencies = self::string_list($settings['accepted_order_currencies']);
            $currencies = array_values(array_unique(array_filter(array_map('strtoupper', $currencies), array($currency_registry, 'supports_quote'))));
            if ($currencies !== $settings['accepted_order_currencies']) {
                $settings['accepted_order_currencies'] = $currencies;
                $changed = true;
            }
        }

        $registry = new PaymentSystemRegistry();
        if (! array_key_exists('enabled_payment_directions', $settings)) {
            $legacy_systems = self::string_list($settings['enabled_systems'] ?? '');
            $directions = array();
            foreach ($registry->directions() as $key => $direction) {
                if (array() === $legacy_systems || in_array($direction['system_key'], $legacy_systems, true)) {
                    $directions[] = $key;
                }
            }
            $settings['enabled_payment_directions'] = $directions;
            $changed = true;
        } else {
            $directions = self::normalise_directions(self::string_list($settings['enabled_payment_directions']), $registry);
            if ($directions !== $settings['enabled_payment_directions']) {
                $settings['enabled_payment_directions'] = $directions;
                $changed = true;
            }
        }
        if ($changed) {
            update_option(self::SETTINGS_OPTION, $settings, false);
        }
    }

    /** @return string[] */
    public static function default_order_currencies(): array
    {
        $currency = self::store_currency();
        return (new CurrencyRegistry())->supports_quote($currency) ? array($currency) : array();
    }

    /** @return string|null A safe, merchant-actionable configuration warning. */
    public static function settings_configuration_problem(): ?string
    {
        $settings = get_option(self::SETTINGS_OPTION, false);
        if (! is_array($settings) || 'yes' !== ($settings['enabled'] ?? 'no')) {
            return null;
        }
        $currencies = self::string_list($settings['accepted_order_currencies'] ?? array());
        if (array() === $currencies) {
            return __('PayKassa is disabled because this store currency is not supported by the configured PayKassa conversion currencies. Choose a supported Accepted WooCommerce currency in the gateway settings.', 'paykassa');
        }
        $directions = self::string_list($settings['enabled_payment_directions'] ?? array());
        if (array() === $directions) {
            return __('PayKassa is disabled because no crypto payment methods are enabled. Choose at least one Enabled crypto payment method in the gateway settings.', 'paykassa');
        }
        return null;
    }

    private static function store_currency(): string
    {
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : get_option('woocommerce_currency', '');
        return strtoupper(is_string($currency) ? $currency : '');
    }

    /** @param mixed $value @return string[] */
    private static function string_list($value): array
    {
        $items = is_array($value) ? $value : explode(',', is_string($value) ? $value : '');
        $items = array_filter(array_map(static fn ($item): string => is_string($item) ? trim($item) : '', $items));
        return array_values(array_unique($items));
    }

    /** @param string[] $items @return string[] */
    private static function normalise_directions(array $items, PaymentSystemRegistry $registry): array
    {
        $directions = array();
        foreach ($items as $item) {
            $direction = $registry->direction($item);
            if (is_array($direction)) {
                $directions[] = $direction['system_key'] . ':' . $direction['currency'];
            }
        }
        return array_values(array_unique($directions));
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
