<?php

namespace FluentCart\App\Hooks\Scheduler\AutoSchedules;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ScheduledAction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Email\StoreDigestService;

class HourlyScheduler
{
    private $startTimeStamp = null;

    public function register(): void
    {
        add_action('fluent_cart/scheduler/hourly_tasks', [$this, 'handle'], 10);

        // On-demand digest trigger (WP-CLI / debugging): do_action('fluent_cart/store_digest/send', 'daily')
        add_action('fluent_cart/store_digest/send', function ($frequency) {
            StoreDigestService::sendDigest((string) $frequency);
        }, 10, 1);
    }

    public function handle()
    {
        $this->startTimeStamp = time();

        // hourly tasks, remove all completed tasks
        $this->removeCompleteTasks();

        $this->checkAndExpireSubscriptions();

        // Store digest (daily/weekly/monthly): evaluated each hour against the configured send time.
        StoreDigestService::runDueDigests();
    }


    private function removeCompleteTasks()
    {
        ScheduledAction::query()->where('status', Status::SCHEDULE_COMPLETED)
            ->limit(5000)
            ->delete();
    }

    private function checkAndExpireSubscriptions()
    {
        if ((time() - $this->startTimeStamp) > 60) {
            return;
        }

        Subscription::checkAndExpireSubscriptions();
    }
}
