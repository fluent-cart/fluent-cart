<?php

namespace FluentCart\App\Listeners\Subscription;

class SubscriptionRenewalFailed
{
    public static function handle(\FluentCart\App\Events\Subscription\SubscriptionRenewalFailed $event)
    {
        if ($event->order) {
            $event->order->addLog(
                __('Renewal Payment Failed', 'fluent-cart'),
                sprintf(
                    /* translators: %1$s is the gateway failure reason */
                    __('Automatic renewal charge failed: %1$s', 'fluent-cart'),
                    $event->error
                ),
                'error'
            );
        }

        if ($event->customer) {
            $event->customer->recountStat();
        }
    }

}
