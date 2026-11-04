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

        // module_name is stored and filtered on, so it keeps the English word
        $moduleName = Str::of($eventName)->split('/\s+/')->first();

        $eventName = static::translatedActivityTitle($eventName);

        $eventInfo = $event->getEventInfoForActivity();

        $userId = '';
        $createdBy = '';

        if ($user = wp_get_current_user()) {
            $userId = $user->ID ?? '';
            $createdBy = $user->display_name ?? 'FCT-BOT';
        }

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

    /**
     * Activity titles are stored as rendered text, so they are translated here,
     * at write time. Unknown events keep the English headline.
     */
    protected static function translatedActivityTitle(string $title): string
    {
        // Keyed by the English headline guessActivityTitle() builds
        $titles = [
            'License Renewed'               => __('License Renewed', 'fluent-cart'),
            'Order Canceled'                => __('Order Canceled', 'fluent-cart'),
            'Order Created'                 => __('Order Created', 'fluent-cart'),
            'Order Deleted'                 => __('Order Deleted', 'fluent-cart'),
            'Order Paid'                    => __('Order Paid', 'fluent-cart'),
            'Order Payment Failed'          => __('Order Payment Failed', 'fluent-cart'),
            'Order Refund'                  => __('Order Refund', 'fluent-cart'),
            'Order Status Updated'          => __('Order Status Updated', 'fluent-cart'),
            'Order Updated'                 => __('Order Updated', 'fluent-cart'),
            'Renewal Order Deleted'         => __('Renewal Order Deleted', 'fluent-cart'),
            'Subscription Activated'        => __('Subscription Activated', 'fluent-cart'),
            'Subscription Canceled'         => __('Subscription Canceled', 'fluent-cart'),
            'Subscription E O T'            => __('Subscription Ended', 'fluent-cart'),
            'Subscription Paused'           => __('Subscription Paused', 'fluent-cart'),
            'Subscription Period Skipped'   => __('Subscription Period Skipped', 'fluent-cart'),
            'Subscription Reactivated'      => __('Subscription Reactivated', 'fluent-cart'),
            'Subscription Renewal Failed'   => __('Subscription Renewal Failed', 'fluent-cart'),
            'Subscription Renewed'          => __('Subscription Renewed', 'fluent-cart'),
            'Subscription Resumed'          => __('Subscription Resumed', 'fluent-cart'),
            'Subscription Updated'          => __('Subscription Updated', 'fluent-cart'),
            'Subscription Validity Expired' => __('Subscription Validity Expired', 'fluent-cart'),
            'User Created'                  => __('User Created', 'fluent-cart'),
        ];

        return Arr::get($titles, $title, $title);
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
