<?php

/**
 * Frozen schema-v2 fixture, before owner tokens were introduced.
 *
 * Keep this independent of Installer's current CREATE TABLE statements so a
 * missing schema-version bump remains observable in the upgrade regression.
 *
 * @return callable(string, string): array<string, string>
 */

declare(strict_types=1);

return static function (string $prefix, string $charset_collate): array {
    return array(
        'events' => "CREATE TABLE {$prefix}paykassa_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_key char(64) NOT NULL,
            provider_transaction_id varchar(191) NOT NULL,
            hash_fingerprint char(64) NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            merchant_context char(64) NOT NULL,
            environment varchar(8) NOT NULL,
            event_type varchar(32) NOT NULL,
            status varchar(32) NOT NULL,
            lease_expires_at datetime NULL,
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            source varchar(32) NOT NULL DEFAULT 'webhook',
            created_at datetime NOT NULL,
            processed_at datetime NULL,
            error_code varchar(64) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_key (event_key),
            KEY provider_context (provider_transaction_id,merchant_context,environment),
            KEY order_status (order_id,status),
            KEY created_at (created_at)
        ) {$charset_collate}",
        'invoice_locks' => "CREATE TABLE {$prefix}paykassa_invoice_locks (
            order_id bigint(20) unsigned NOT NULL,
            status varchar(16) NOT NULL,
            snapshot_hash char(64) NULL,
            lease_expires_at datetime NULL,
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (order_id),
            KEY lease_status (status,lease_expires_at)
        ) {$charset_collate}",
    );
};
