<?php

namespace FluentCart\App\Hooks\Scheduler\AutoSchedules;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ScheduledAction;

class HourlyScheduler
{
    public function register(): void
    {
        add_action('fluent_cart/scheduler/hourly_tasks', [$this, 'handle'], 10);
    }

    public function handle()
    {
        // hourly tasks, remove all completed tasks
        $this->removeCompleteTasks();

    }


    private function removeCompleteTasks()
    {
        ScheduledAction::query()->where('status', Status::SCHEDULE_COMPLETED)
            ->limit(5000)
            ->delete();
    }
}
