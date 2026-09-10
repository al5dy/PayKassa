<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Reconciliation;

use Al5dy\PayKassaWoo\Infrastructure\Logger;
use Al5dy\PayKassaWoo\PayKassa\PayKassaClientFactory;
use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;

/** Safely checks API availability/history; it never settles an order from an undocumented history payload. */
final class ReconciliationService
{
    public function run(): void
    {
        $settings = get_option('woocommerce_paykassa_settings', array());
        $settings = is_array($settings) ? $settings : array();
        if ('yes' !== ( $settings['reconciliation_enabled'] ?? 'no' ) || '' === ( $settings['api_id'] ?? '' ) || '' === ( $settings['api_password'] ?? '' )) {
            return;
        }
        if (get_transient('paykassa_reconciliation_lock')) {
            return;
        }
        set_transient('paykassa_reconciliation_lock', '1', 10 * MINUTE_IN_SECONDS);
        try {
            ( new PayKassaClientFactory() )->api($settings)->history((string) $settings['shop_id'], gmdate('c', time() - DAY_IN_SECONDS), gmdate('c'));
            update_option('paykassa_last_reconciliation', gmdate('c'), false);
        } catch (PayKassaException $exception) {
            update_option('paykassa_last_reconciliation_error', gmdate('c'), false);
            ( new Logger() )->log('warning', 'reconciliation_failed', array( 'error_code' => Logger::fingerprint($exception->getMessage()) ));
        }
        delete_transient('paykassa_reconciliation_lock');
    }
}
