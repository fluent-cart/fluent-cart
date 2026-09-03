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
            // nullable: the admin UI serializes variation line items with subtotal null
            // (cartService.js computes unit_price*quantity - cost, and productService.js
            // never sets `cost` on variation rows, so NaN JSON-encodes as null). The bare
            // numeric rule 422'd every coupon apply on such orders. CouponServiceAdmin
            // falls back to unit_price*quantity for non-numeric subtotals, keeping the
            // min/max purchase-amount enforcement this field was whitelisted for.
            "order_items.*.subtotal"  => 'nullable|numeric',
            "order_items.*.discount_total"   => 'numeric',
            "order_items.*.total"  => 'numeric',
            "order_items.*.line_total"  => 'numeric',
            "order_items.*.cart_index"  => 'nullable|numeric',
            "order_items.*.rate"  => 'nullable|numeric',
            "order_items.*.line_meta"  => 'nullable',
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
            // floatval(null) would coerce to 0.0 and silently zero the items total the
            // min/max purchase gates sum — keep null as null so the service layer can
            // recompute it from unit_price * quantity instead.
            "order_items.*.subtotal"  => function ($value) {
                return is_numeric($value) ? floatval($value) : null;
            },
            "order_items.*.discount_total"   => 'floatval',
            "order_items.*.total"  => 'floatval',
            "order_items.*.line_total"  => 'floatval',
            "order_items.*.cart_index"  => 'intval',
            "order_items.*.rate"  => 'floatval',
            "order_items.*.line_meta"  => function ($value) {
                return is_array($value) ? $value : sanitize_text_field((string) $value);
            },
            "order_items.*.other_info" => function ($value) {
                return is_array($value) ? $value : sanitize_text_field((string) $value);
            },
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
