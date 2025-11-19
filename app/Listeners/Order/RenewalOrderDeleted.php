<?php

namespace FluentCart\App\Listeners\Order;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;

class RenewalOrderDeleted
{
    public static function handle(\FluentCart\App\Events\Order\RenewalOrderDeleted $event)
    {
        if ($event->order->customer_id) {
            $customer = Customer::query()->where('id', $event->order->customer_id)->first();
            if (!empty($customer)) {
                $customer->recountStat();
            }
        }

        $subscriptionModel = Subscription::query()->where('parent_order_id', $event->order->parent_id)->first();

        if ($subscriptionModel) {
            SubscriptionService::syncSubscriptionStates($subscriptionModel, []);
        }

    }

}
