<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Reconciliation;

final class ReconciliationScheduler
{
    public const HOOK = 'paykassa_reconcile';

    public function register(): void
    {
        add_action(self::HOOK, array( new ReconciliationService(), 'run' ));
        add_action('action_scheduler_init', array( $this, 'schedule' ));
    }

    /** Schedule only after WooCommerce's bundled Action Scheduler is initialized. */
    public function schedule(): void
    {
        if (function_exists('as_has_scheduled_action') && ! as_has_scheduled_action(self::HOOK, array(), 'paykassa')) {
            as_schedule_recurring_action(time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK, array(), 'paykassa');
        }
    }
}
