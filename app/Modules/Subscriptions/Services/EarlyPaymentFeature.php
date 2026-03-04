<?php

namespace FluentCart\App\Modules\Subscriptions\Services;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;

class EarlyPaymentFeature
{
    public static function isEnabled(): bool
    {
        $isEnabledBySettings = App::storeSettings()->get('enable_early_payment_for_installment', 'yes') === 'yes';
        $isEnabled = apply_filters('fluent_cart/subscription/early_payment_enabled', $isEnabledBySettings);

        return App::isProActive() && (bool) $isEnabled;
    }

    public static function canPay(Subscription $subscription): bool
    {
        $canPay = self::isEnabled()
            && $subscription->bill_times > 0
            && $subscription->bill_count < $subscription->bill_times
            && in_array($subscription->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING], true);

        return (bool) apply_filters('fluent_cart/subscription/can_early_pay', $canPay, [
            'subscription' => $subscription
        ]);
    }
}
