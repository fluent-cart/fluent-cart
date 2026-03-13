<?php

namespace FluentCart\App\Services\ShortCodeParser\Parsers;

use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;

class SubscriptionParser extends BaseParser
{
    private $subscription;

    public function __construct($data)
    {
        $this->subscription = Arr::get($data, 'subscription');
        parent::__construct($data);
    }

    public function parse($accessor = null, $code = null, $transformer = null): ?string
    {
        $subscription = $this->subscription;

        if (!$subscription) {
            return '';
        }

        switch ($accessor) {
            case 'sl':
                return (string) (isset($subscription->sl) ? $subscription->sl : '');
            case 'item_name':
                return esc_html($subscription->item_name);
            case 'status':
                return esc_html($subscription->status);
            case 'billing_interval':
                return esc_html($subscription->billing_interval);
            case 'recurring_amount':
                return $subscription->recurring_amount ? (string) ($subscription->recurring_amount / 100) : '0';
            case 'recurring_amount_formatted':
                return esc_html(Helper::toDecimal($subscription->recurring_amount));
            case 'payment_info':
                return wp_kses_post($subscription->payment_info);
            case 'next_billing_date':
                return $subscription->next_billing_date
                    ? esc_html(date('M j, Y', strtotime($subscription->next_billing_date)))
                    : __('N/A', 'fluent-cart');
            case 'bill_times':
                return $subscription->bill_times
                    ? esc_html($subscription->bill_times)
                    : __('Unlimited', 'fluent-cart');
            case 'bill_count':
                return esc_html($subscription->bill_count);
            case 'trial_days':
                return esc_html($subscription->trial_days ?: '0');
            case 'expire_at':
                return $subscription->expire_at
                    ? esc_html(date('M j, Y', strtotime($subscription->expire_at)))
                    : __('Never', 'fluent-cart');
            default:
                return Arr::get((array) $subscription, $accessor, '');
        }
    }
}
