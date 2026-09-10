<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Reconciliation;

final class ReconciliationScheduler
{
    public const HOOK = 'paykassa_reconcile';
    public const CONTINUE_HOOK = 'paykassa_reconcile_continue';

    public function register(): void
    {
        add_action(self::HOOK, array($this, 'run'));
        add_action(self::CONTINUE_HOOK, array($this, 'run'));
        add_action('action_scheduler_init', array( $this, 'schedule' ));
        add_action('action_scheduler_completed_action', array($this, 'after_action'));
    }

    public function run(): void
    {
        $result = (new ReconciliationService())->run();
        if (! function_exists('as_schedule_single_action') || ! in_array($result['status'], array('in_progress', 'failed', 'backoff', 'busy'), true)) {
            return;
        }
        $job = get_option(ReconciliationService::JOB_OPTION, array());
        $retry_at = is_array($job) && isset($job['retry_at']) && is_int($job['retry_at']) ? $job['retry_at'] : time() + 300;
        as_schedule_single_action(max(time() + 60, $retry_at), self::CONTINUE_HOOK, array(), 'paykassa', true);
    }

    /** Schedule after completion: AS uniqueness includes the currently running action. */
    public function after_action(int $action_id): void
    {
        $action = \ActionScheduler::store()->fetch_action($action_id);
        $settings = get_option('woocommerce_paykassa_settings', array());
        if (! in_array($action->get_hook(), array(self::HOOK, self::CONTINUE_HOOK), true) || 'paykassa' !== $action->get_group() || ! is_array($settings) || 'yes' !== ($settings['reconciliation_enabled'] ?? 'no')) {
            return;
        }
        $job = get_option(ReconciliationService::JOB_OPTION, array());
        if (is_array($job) && isset($job['retry_at']) && is_int($job['retry_at'])) {
            as_schedule_single_action(max(time() + 60, $job['retry_at']), self::CONTINUE_HOOK, array(), 'paykassa', true);
        }
    }

    public static function unschedule(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, array(), 'paykassa');
            as_unschedule_all_actions(self::CONTINUE_HOOK, array(), 'paykassa');
        }
    }

    /** Schedule only after WooCommerce's bundled Action Scheduler is initialized. */
    public function schedule(): void
    {
        $settings = get_option('woocommerce_paykassa_settings', array());
        $enabled = is_array($settings) && 'yes' === ($settings['reconciliation_enabled'] ?? 'no');
        if (! $enabled) {
            self::unschedule();
            return;
        }
        if (function_exists('as_has_scheduled_action') && ! as_has_scheduled_action(self::HOOK, array(), 'paykassa')) {
            as_schedule_recurring_action(time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK, array(), 'paykassa');
        }
    }
}
