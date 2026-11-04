<?php

namespace FluentCart\App\Services\Email;

use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;

class SubscriptionRenewalSummary
{
    public static function make(object $subscription, object $order, array $renewalPayment = []): array
    {
        $amount = Arr::get($renewalPayment, 'amount');
        $currency = Arr::get($renewalPayment, 'currency') ?: ($subscription->currency ?? '');

        // The parent receipt includes first-payment discounts, fees and unrelated items.
        if (!is_numeric($amount)) {
            $isRenewalOrder = ($order->type ?? '') === 'renewal';
            $amount = $isRenewalOrder ? $order->total_amount : $subscription->getCurrentRenewalAmount();
            $currency = $isRenewalOrder ? ($order->currency ?? '') : ($subscription->currency ?? '');
        }

        return [
            'item_name'      => $subscription->item_name,
            'amount'         => Helper::toDecimal((int) $amount, true, strtoupper($currency)),
            'payment_method' => $subscription->getPaymentMethodText(),
        ];
    }
}
