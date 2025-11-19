<?php

namespace FluentCart\App\Listeners;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;
use ReflectionClass;

class Activity
{
    public static function handle($event)
    {

        /**
         * @var EventDispatcher $event
         */

        if (!$event->shouldCreateActivity()) {
            return;
        }

        $model = $event->getActivityEventModel();

        $module = '';
        $moduleId = '';

        if (is_object($model)) {
            $module = get_class($model);
            $moduleId = $model->id ?? $model->ID ?? '';
        }

        $eventName = static::guessActivityTitle($event);

        $eventInfo = $event->getEventInfoForActivity();

        $userId = '';
        $createdBy = '';

        if ($user = wp_get_current_user()) {
            $userId = $user->ID ?? '';
            $createdBy = $user->display_name ?? 'FCT-BOT';
        }

        $moduleName = Str::of($eventName)->split('/\s+/')->first();

        fluent_cart_add_log(
            Arr::get($eventInfo, 'title', $eventName),
            Arr::get($eventInfo, 'content', sprintf(
                /* translators: %s is the event name */
                __('%s successfully!', 'fluent-cart'), $eventName
            )),
            Arr::get($eventInfo, 'status', 'success'),
            [
                'module_type' => Arr::get($eventInfo, 'module_name', $module),
                'module_id' => Arr::get($eventInfo, 'module_id', $moduleId),
                'module_name' => $moduleName,
                'user_id' => Arr::get($eventInfo, 'user_id', $userId),
                'created_by' => Arr::get($eventInfo, 'created_by', $createdBy),
            ]
        );
    }

    protected static function guessActivityTitle($event): string
    {
        try {
            $reflect = new ReflectionClass($event);
            return Str::of($reflect->getShortName())->headline() . '';
        } catch (\ReflectionException $e) {
            return "";
        }
    }

}
