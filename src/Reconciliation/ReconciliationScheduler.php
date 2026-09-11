<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Reconciliation;

use Al5dy\PayKassaWoo\Infrastructure\DatabaseMutex;
use Al5dy\PayKassaWoo\Infrastructure\Logger;
use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;

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
        $this->schedule_unique(self::CONTINUE_HOOK, max(time() + 60, $retry_at));
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
            $this->schedule_unique(self::CONTINUE_HOOK, max(time() + 60, $job['retry_at']));
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
        if (function_exists('as_has_scheduled_action')) {
            $this->schedule_unique(self::HOOK, time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS);
        }
    }

    /** Older AS hybrid stores do not implement save_unique_action(). */
    private function schedule_unique(string $hook, int $timestamp, int $interval = 0): void
    {
        $mutex = new DatabaseMutex();
        try {
            if (! $mutex->acquire('reconciliation-schedule')) {
                return;
            }
            try {
                $settings = get_option('woocommerce_paykassa_settings', array());
                if (! is_array($settings) || 'yes' !== ($settings['reconciliation_enabled'] ?? 'no') || as_has_scheduled_action($hook, array(), 'paykassa')) {
                    return;
                }
                $action_id = $interval > 0
                    ? as_schedule_recurring_action($timestamp, $interval, $hook, array(), 'paykassa', true)
                    : as_schedule_single_action($timestamp, $hook, array(), 'paykassa', true);
                if (0 === $action_id) {
                    throw new PayKassaException('Could not schedule recovery action.');
                }
            } finally {
                $mutex->release();
            }
        } catch (\Throwable $exception) {
            // Scheduling failure must not break ordinary checkout bootstrap.
            update_option('paykassa_last_reconciliation_error', gmdate('c'), false);
            (new Logger())->log('error', 'reconciliation_scheduling_failed', array('error_code' => Logger::fingerprint($exception->getMessage())));
        }
    }
}
