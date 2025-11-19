<?php

namespace FluentCart\App\Hooks\Scheduler;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ScheduledAction;
use FluentCart\App\Modules\Integrations\GlobalNotificationHandler;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

class JobRunner
{
    protected static $instance = null;
    protected $actionIds = [];
    protected $actions = [];
    protected $startedAt;

    public function __construct()
    {
        $this->startedAt = DateTime::gmtNow();
    }

    public function async($hook, $scheduleActionData)
    {
        $data = array_merge([
            'status'     => 'pending'
        ], $scheduleActionData);
        $queueId = $this->addQueue($data);

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action($hook, [ 'scheduled_action_id' => $queueId ], 'fluent-cart');
        }
    }

    public function start($filters = []): void
    {
        $jobs = ScheduledAction::query()->where('status', 'pending')
            ->where('retry_count', '<', 5)
            ->where('scheduled_at', '<=', $this->startedAt)
            ->where($filters)
            ->orderBy('scheduled_at', 'asc')
            ->limit(100)
            ->get();

        foreach ($jobs as $job) {
            $this->runScheduler($job);
            $timeDiff = DateTime::gmtNow()->getTimestamp() - $this->startedAt->getTimestamp();
            if ($timeDiff > 30) {
                // If we have been running for more than 30 seconds, stop processing
                break;
            }
        }
    }

    public function runScheduler(ScheduledAction $job): void
    {
        if ($job->group === 'integration') {
            (new GlobalNotificationHandler())->processIntegrationAction($job->id);
            $job->update(['status' => Status::SCHEDULE_COMPLETED]);
        }
    }

    public function addQueue(array $data)
    {
        if (!isset($data['status'])) {
            $data['status'] = Status::SCHEDULE_PENDING;
        }

        if (!isset($data['retry_count'])) {
            $data['retry_count'] = 0;
        }

        $data['created_at'] = current_time('mysql');
        return ScheduledAction::query()->insertGetId($data);
    }
}
