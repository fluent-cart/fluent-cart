<?php

namespace FluentCart\App\Http\Requests\FrontendRequests;

use FluentCart\Framework\Foundation\RequestGuard;

class CouponRequest extends RequestGuard
{

    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            'order_uuid'   => 'nullable|sanitizeText|maxLength:100',
            'id' => 'nullable|numeric',
            'coupon_code' => 'required|sanitizeText',
            'order_items' => 'required|array',
            "order_items.*.id"    => 'numeric|min:1',
            "order_items.*.order_id"    => 'numeric|min:1',
            "order_items.*.post_id"   => 'numeric|min:1',
            "order_items.*.variation_id"   => 'numeric|min:1',
            "order_items.*.type" => 'nullable|sanitizeText|maxLength:100',
            "order_items.*.quantity"    => 'numeric|min:1',
            "order_items.*.title"   => 'nullable|sanitizeText|maxLength:100',
            "order_items.*.price"  => 'numeric',
            "order_items.*.unit_price"  => 'numeric',
            "order_items.*.item_cost"  => 'numeric',
            "order_items.*.item_total"  => 'numeric',
            "order_items.*.tax_amount"   => 'numeric',
            "order_items.*.discount_total"   => 'numeric',
            "order_items.*.total"  => 'numeric',
            "order_items.*.line_total"  => 'numeric',
            "order_items.*.cart_index"  => 'nullable|numeric',
            "order_items.*.rate"  => 'nullable|numeric',
            "order_items.*.line_meta"  => 'nullable|sanitizeTextArea',
            "order_items.*.other_info" => 'nullable|array',
            'applied_coupons' => 'nullable|array',

            'customer_email' => 'nullable|sanitizeText|email',

        ];
    }

    /**
     * @return array
     */
    public function messages(): array
    {
        return [
            'coupon_code.required' => esc_html__('Coupon code is required', 'fluent-cart'),
            'order_items.required' => esc_html__('Item selection is required', 'fluent-cart'),
        ];
    }

    /**
     * @return array
     */
    public function sanitize(): array
    {
        return [
            'order_uuid'   => 'sanitize_text_field',
            'id' => 'intval',
            'coupon_code' => 'sanitize_text_field',
            "order_items.*.id"    => 'intval',
            "order_items.*.order_id"    => 'intval',
            "order_items.*.post_id"   => 'intval',
            "order_items.*.variation_id"   => 'intval',
            "order_items.*.type" => 'sanitize_text_field',
            "order_items.*.quantity"    => 'intval',
            "order_items.*.title"   => 'sanitize_text_field',
            "order_items.*.price"  => 'floatval',
            "order_items.*.unit_price"  => 'floatval',
            "order_items.*.item_cost"  => 'floatval',
            "order_items.*.item_total"  => 'floatval',
            "order_items.*.tax_amount"   => 'floatval',
            "order_items.*.discount_total"   => 'floatval',
            "order_items.*.total"  => 'floatval',
            "order_items.*.line_total"  => 'floatval',
            "order_items.*.cart_index"  => 'intval',
            "order_items.*.rate"  => 'floatval',
            "order_items.*.line_meta"  => 'sanitize_text_field',
            "order_items.*.other_info" => 'sanitize_text_field',
            'applied_coupons.*' => 'intval',

            'customer_email' => function ($value) {
                if(empty($value)) {
                    return '';
                }

                return sanitize_email($value);
            },
        ];
    }
}
