<?php

namespace FluentCart\App\Hooks\Handlers;

class DeactivationHandler
{
    public function handle()
    {
        if (function_exists('as_unschedule_action')) {
            as_unschedule_action('fluent_cart/scheduler/five_minutes_tasks');
        }

        wp_clear_scheduled_hook('fluent_cart/scheduler/five_minutes_tasks');
        wp_clear_scheduled_hook('fluent_cart/scheduler/hourly_tasks');
        wp_clear_scheduled_hook('fluent_cart/scheduler/daily_tasks');
    }
}
