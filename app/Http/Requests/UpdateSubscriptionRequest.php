<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class UpdateSubscriptionRequest extends RequestGuard
{
    public function beforeValidation()
    {
        return Arr::get($this->all(), 'data', []);
    }

    public function rules()
    {
        $allowedStatuses = [
            Status::SUBSCRIPTION_ACTIVE,
            Status::SUBSCRIPTION_PAUSED,
            Status::SUBSCRIPTION_TRIALING,
            Status::SUBSCRIPTION_CANCELED,
            Status::SUBSCRIPTION_EXPIRED,
            Status::SUBSCRIPTION_COMPLETED,
            Status::SUBSCRIPTION_PAST_DUE,
        ];

        return [
            'recurring_total'   => 'nullable|numeric|min:0',
            'bill_times'        => 'nullable|numeric|min:0',
            'billing_interval'  => 'nullable|sanitizeText|maxLength:100',
            'status'            => ['required', 'sanitizeText', 'validStatus' => function ($attribute, $value) use ($allowedStatuses) {
                if (!in_array($value, $allowedStatuses, true)) {
                    return __('Invalid subscription status.', 'fluent-cart');
                }
                return null;
            }],
            'next_billing_date' => ['nullable', 'sanitizeText', 'maxLength:25', 'validDate' => function ($attribute, $value) {
                if ($value && strtotime($value) === false) {
                    return __('Invalid date format for next billing date.', 'fluent-cart');
                }
                return null;
            }],
        ];
    }

    public function messages()
    {
        return [
            'recurring_total.numeric' => esc_html__('Recurring total must be a number.', 'fluent-cart'),
            'recurring_total.min'     => esc_html__('Recurring total cannot be negative.', 'fluent-cart'),
            'bill_times.numeric'      => esc_html__('Bill times must be a number.', 'fluent-cart'),
            'bill_times.min'          => esc_html__('Bill times cannot be negative.', 'fluent-cart'),
            'status.required'         => esc_html__('Subscription status is required.', 'fluent-cart'),
        ];
    }

    public function sanitize()
    {
        return [
            'recurring_total'   => 'floatval',
            'bill_times'        => 'intval',
            'billing_interval'  => 'sanitize_text_field',
            'status'            => 'sanitize_text_field',
            'next_billing_date' => 'sanitize_text_field',
        ];
    }
}
