<?php

namespace FluentCart\App\Hooks\Scheduler\AutoSchedules;


use FluentCart\App\Hooks\Scheduler\JobRunner;

class FiveMinuteScheduler
{
    public function register(): void
    {
        add_action('fluent_cart/scheduler/five_minutes_tasks', [$this, 'handle']);
    }

    public function handle(): void
    {
        (new JobRunner())->start();
    }
}
