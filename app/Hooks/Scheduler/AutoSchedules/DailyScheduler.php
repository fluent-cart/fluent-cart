<?php

namespace FluentCart\App\Hooks\Scheduler\AutoSchedules;

use FluentCart\App\Hooks\Scheduler\JobRunner;

class DailyScheduler
{
    public function register(): void
    {
        add_action('fluent_cart/scheduler/daily_tasks', [$this, 'handle']);
    }

    public function handle()
    {
        // do your daily tasks here
    }

}