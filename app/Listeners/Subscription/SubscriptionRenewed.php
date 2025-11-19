<?php

namespace FluentCart\App\Listeners\Subscription;


use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\AuthService;
use FluentCart\Framework\Support\Arr;

class SubscriptionRenewed
{
    public static function handle(\FluentCart\App\Events\Subscription\SubscriptionRenewed $event)
    {
        if ($event->customer) {
            $event->customer->recountStat();
        }
    }

}
