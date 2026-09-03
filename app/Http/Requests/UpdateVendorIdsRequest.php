<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class UpdateVendorIdsRequest extends RequestGuard
{
    public function beforeValidation()
    {
        return Arr::get($this->all(), 'data', []);
    }

    public function rules()
    {
        // Both ids are interpolated into gateway API paths (Stripe
        // subscriptions/{id}, Mollie customers/{cid}/subscriptions/{id}), so the
        // character set is constrained here rather than at each call site.
        $format = function ($attribute, $value) {
            if ($value && !preg_match('/^[a-zA-Z0-9_.-]+$/', $value)) {
                return __('Vendor IDs may only contain letters, numbers, dots, dashes and underscores.', 'fluent-cart');
            }
            return null;
        };

        return [
            'vendor_subscription_id' => ['nullable', 'sanitizeText', 'maxLength:45', 'validFormat' => $format],
            'vendor_customer_id'     => ['nullable', 'sanitizeText', 'maxLength:45', 'validFormat' => $format],
        ];
    }

    public function messages()
    {
        return [
            'vendor_subscription_id.maxLength' => esc_html__('Vendor Subscription ID cannot be longer than 45 characters.', 'fluent-cart'),
            'vendor_customer_id.maxLength'     => esc_html__('Vendor Customer ID cannot be longer than 45 characters.', 'fluent-cart'),
        ];
    }

    public function sanitize()
    {
        return [
            'vendor_subscription_id' => 'sanitize_text_field',
            'vendor_customer_id'     => 'sanitize_text_field',
        ];
    }
}
