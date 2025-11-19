<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\Api\StoreSettings;
use FluentCart\Database\DBMigrator;

class ActivationHandler
{
    public StoreSettings $storeSettings;

    public function handle($network_wide = false)
    {
        DBMigrator::migrateUp($network_wide);
        $this->storeSettings = new StoreSettings();
        $this->dispatch();

        $this->registerWpCron();
    }

    public function dispatch()
    {

    }

    public function registerWpCron()
    {
        $fiveMinutesHook = 'fluent_cart/scheduler/five_minutes_tasks';
        $hourlyHook = 'fluent_cart/scheduler/hourly_tasks';
        $dailyHook = 'fluent_cart/scheduler/daily_tasks';

        if (function_exists('as_schedule_recurring_action')) {
            as_schedule_recurring_action(time(), (60 * 5), $fiveMinutesHook, [], 'fluent-cart', true);
            as_schedule_recurring_action(time(), (60 * 60), $hourlyHook, [], 'fluent-cart', true);
            as_schedule_recurring_action(time(), (60 * 60 * 24), $dailyHook, [], 'fluent-cart', true);
        }
    }
}
