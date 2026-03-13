<?php

namespace FluentCart\App\Modules\PaymentMethods\Cod;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;

class CodSubscriptions extends AbstractSubscriptionModule
{
    public function cancel($vendorSubscriptionId, $args = [])
    {
        return [
            'status'      => Status::SUBSCRIPTION_CANCELED,
            'canceled_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)
    {
        // No remote subscription to sync for offline payments
        return $subscriptionModel;
    }
}
