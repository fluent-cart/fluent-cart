<?php

namespace FluentCart\App\Services\ShortCodeParser\Parsers;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\DateTime\DateFormatter;
use FluentCart\Framework\Support\Arr;

class SubscriptionParser extends BaseParser
{
    private $subscription;

    /**
     * Only used as the timezone context for DateFormatter — the order carries the
     * user_tz captured at checkout. Null when the shortcode runs without one, in
     * which case DateFormatter falls back to UTC.
     */
    private $order;

    public function __construct($data)
    {
        $this->subscription = Arr::get($data, 'subscription');
        $this->order = Arr::get($data, 'order');
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
                return esc_html($subscription->display_item_name);
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
                    ? esc_html(DateFormatter::format($subscription->next_billing_date, false, $this->order))
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
                    ? esc_html(DateFormatter::format($subscription->expire_at, false, $this->order))
                    : __('Never', 'fluent-cart');
            default:
                return Arr::get((array) $subscription, $accessor, '');
        }
    }
}
