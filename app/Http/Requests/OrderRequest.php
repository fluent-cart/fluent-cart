<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class OrderRequest extends RequestGuard
{

    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            'status'                => 'nullable|sanitizeText|maxLength:50',
            'invoice_no'            => 'nullable|sanitizeText|maxLength:100',
            'fulfillment_type'      => 'nullable|sanitizeText|maxLength:50',
            'type'                  => 'nullable|sanitizeText|maxLength:50',
            'payment_method'        => 'nullable|sanitizeText|maxLength:50',
            'payment_method_title'  => 'nullable|sanitizeText|maxLength:50',
            'payment_status'        => 'nullable|sanitizeText|maxLength:50',
            'currency'              => 'nullable|sanitizeText|maxLength:10',
            'subtotal'              => 'numeric',
            'discount_tax'          => 'numeric',
            'manual_discount_total' => 'numeric',
            'coupon_discount_total' => 'numeric',
            'shipping_tax'          => 'numeric',
            // min/max close the silent-corruption window: 1e19 passes `numeric`
            // but wraps to a negative BIGINT through a float-to-int cast, and a
            // negative shipping charge has no meaning. The bound matches the
            // Helper::roundCent() guard (float's exact-integer range).
            'shipping_total'        => 'numeric|min:0|max:9000000000000000',
            'tax_total'             => 'numeric',
            'total_amount'          => 'numeric',
            'rate'                  => 'numeric',
            'note'                  => 'nullable|sanitizeTextArea|maxLength:5000',
            'uuid'                  => 'nullable|sanitizeText|maxLength:100',
            'ip_address'            => 'nullable|sanitizeText|maxLength:100',
            'completed_at'          => 'nullable|sanitizeText|maxLength:100',
            'refunded_at'           => 'nullable|sanitizeText|maxLength:100',
            'customer_id'           => 'required|numeric',
            'user_tz'               => 'nullable|sanitizeText|maxLength:50',

            'order_items'                   => 'required|array',
            "order_items.*.id"              => 'numeric|min:1',
            "order_items.*.order_id"        => 'numeric|min:1',
            "order_items.*.post_id"         => 'numeric|min:1',
            "order_items.*.variation_id"    => 'numeric|min:1',
            "order_items.*.object_id"       => 'numeric|min:1',
            "order_items.*.fulfillment_type" => 'nullable|sanitizeText',
            "order_items.*.payment_type"    => 'nullable|sanitizeText|maxLength:100',
            "order_items.*.quantity"        => 'numeric|min:1',
            "order_items.*.post_title"      => 'nullable|sanitizeText|maxLength:255',
            "order_items.*.title"           => 'nullable|sanitizeText|maxLength:255',
            "order_items.*.price"           => 'numeric',
            "order_items.*.unit_price"      => 'numeric',
            "order_items.*.shipping_charge" => 'nullable|numeric',
            "order_items.*.item_cost"       => 'numeric',
            "order_items.*.item_total"      => 'numeric',
            "order_items.*.tax_amount"      => 'numeric',
            "order_items.*.discount_total"  => 'numeric',
            "order_items.*.total"           => 'numeric',
            "order_items.*.line_total"      => 'numeric',
            "order_items.*.cart_index"      => 'nullable|numeric',
            "order_items.*.rate"            => 'nullable|numeric',
            "order_items.*.line_meta"       => 'nullable|array',
            "order_items.*.other_info"      => 'nullable|array',

            "discount.type"   => 'nullable|sanitizeText|maxLength:100',
            "discount.value"  => 'nullable|numeric',
            "discount.label"  => 'nullable|sanitizeText|maxLength:100',
            "discount.reason" => 'nullable|sanitizeText|maxLength:100',
            "discount.action" => 'nullable|sanitizeText|maxLength:100',

            'shipping'                => 'nullable|array',
            "shipping.*.type"         => 'nullable|sanitizeText|maxLength:100',
            "shipping.*.rate_name"    => 'nullable|sanitizeText|maxLength:100',
            "shipping.*.custom_price" => 'nullable|numeric',

            'deletedItems'        => 'nullable|array',
            'tax_behavior'        => 'nullable|numeric|min:0',
            'tax_lines'           => 'nullable|array',
            'tax_lines.*.rate_id'    => 'nullable|numeric|min:0',
            'tax_lines.*.tax_amount' => 'nullable|numeric|min:0',
            'tax_lines.*.label'      => 'nullable|sanitizeText',
            'tax_lines.*.is_compound'=> 'nullable',

            // `applied_coupon` is the admin order screen handing back, untouched, what
            // POST coupons/apply returned: a map KEYED BY COUPON CODE whose rows are
            // CouponServiceAdmin discount data (see ensureCouponExistInDiscountData()),
            // NOT fct_applied_coupons rows. AdminOrderProcessor::insertAppliedCoupons()
            // reads the code keys plus `id` and `discount` and builds its insert rows
            // from the Coupon model, so those two are the whole load-bearing contract;
            // everything else in the map is display metadata.
            //
            // The previous rules described fct_applied_coupons columns (coupon_id, code,
            // discounted_amount, stackable) that no caller has ever sent. They were inert
            // while the validator skipped absent wildcard children, and became a hard
            // 422 on every coupon order once it started materializing them.
            //
            // The per-row closure is the backstop, not decoration: whether the wildcard
            // rules below can fire at all depends on the validator materializing absent
            // children, so on its own `applied_coupon.*.id => required` is silently
            // unenforced on older framework builds. insertAppliedCoupons() subscripts
            // ['id'] unguarded, so an entry without one writes a null coupon_id.
            'applied_coupon'                    => ['nullable', 'array', function ($attribute, $value) {
                if (!is_array($value)) {
                    return null; // the `array` rule already reports this
                }

                foreach ($value as $code => $row) {
                    $couponId = is_array($row) ? Arr::get($row, 'id') : null;

                    if (!is_numeric($couponId) || (int) $couponId < 1) {
                        return sprintf(
                            /* translators: %1$s: the coupon code the admin applied to the order. */
                            __('The applied coupon "%1$s" is missing its coupon id.', 'fluent-cart'),
                            sanitize_text_field((string) $code)
                        );
                    }
                }

                return null;
            }],
            "applied_coupon.*.id"               => 'required|numeric|min:1',
            // Bounded for the same reason as shipping_total above: sanitize() routes this
            // through Helper::roundCent(), which throws outside float's exact-integer
            // range, and a negative coupon discount has no meaning.
            "applied_coupon.*.discount"         => 'required|numeric|min:0|max:9000000000000000',
            "applied_coupon.*.title"            => 'nullable|sanitizeText|maxLength:192',
            "applied_coupon.*.type"             => 'nullable|sanitizeText|maxLength:100',
            "applied_coupon.*.amount"           => 'nullable|numeric',
            "applied_coupon.*.actual_amount"    => 'nullable|numeric',
            "applied_coupon.*.unit_amount"      => 'nullable|numeric',
            "applied_coupon.*.actual_quantity"  => 'nullable|numeric',
            'trigger'                           => 'nullable|string',
        ];
    }


    /**
     * @return array
     */
    public function messages(): array
    {
        return [
            'customer_id.required' => esc_html__('Customer selection is required', 'fluent-cart'),
            'order_items.required' => esc_html__('Item selection is required', 'fluent-cart'),
        ];
    }


    /**
     * @return array
     */
    public function sanitize()
    {
        return [
            'id'                    => 'intval',
            'status'                => 'sanitize_text_field',
            'invoice_no'            => 'sanitize_text_field',
            'fulfillment_type'      => 'sanitize_text_field',
            'type'                  => 'sanitize_text_field',
            'customer_id'           => 'intval',
            'payment_method'        => 'sanitize_text_field',
            'payment_method_title'  => 'sanitize_text_field',
            'payment_status'        => 'sanitize_text_field',
            'currency'              => 'sanitize_text_field',
            'subtotal'              => 'floatval',
            'discount_tax'          => 'floatval',
            'manual_discount_total' => 'floatval',
            'coupon_discount_total' => 'floatval',
            'shipping_tax'          => 'floatval',
            // Cents column: normalize at the boundary so every consumer of this request
            // receives a whole-cent int. floatval alone let a client-computed 19.99 * 100
            // arrive as 1998.9999999999998, which any later int cast would truncate.
            //
            // Wrapped in a closure, NOT passed as [Helper::class, 'roundCent']: an array
            // value in this map is a LIST of callbacks, iterated one by one
            // (vendor/wpfluent/framework/src/WPFluent/Support/Sanitizer.php:456-464), so the
            // array-callable form would try to call Helper() as a function.
            'shipping_total'        => function ($value) {
                return Helper::roundCent($value);
            },
            'tax_total'             => 'floatval',
            'tax_behavior'          => 'intval',
            'total_amount'          => 'floatval',
            'rate'                  => 'sanitize_text_field',
            'note'                  => 'sanitize_text_field',
            'uuid'                  => 'sanitize_text_field',
            'ip_address'            => 'sanitize_text_field',
            'billing_address_id'    => 'intval',
            'shipping_address_id'   => 'intval',
            'completed_at'          => 'sanitize_text_field',
            'refunded_at'           => 'sanitize_text_field',
            'user_tz'               => 'sanitize_text_field',

            "order_items.*.id"              => 'intval',
            "order_items.*.order_id"        => 'intval',
            "order_items.*.post_id"         => 'intval',
            "order_items.*.object_id"       => 'intval',
            "order_items.*.payment_type"    => 'sanitize_text_field',
            "order_items.*.quantity"        => 'intval',
            "order_items.*.post_title"      => 'sanitize_text_field',
            "order_items.*.title"           => 'sanitize_text_field',
            "order_items.*.shipping_charge" => 'intval',
            "order_items.*.price"           => 'floatval',
            "order_items.*.unit_price"      => 'floatval',
            "order_items.*.item_cost"       => 'floatval',
            "order_items.*.item_total"      => 'floatval',
            "order_items.*.tax_amount"      => 'floatval',
            "order_items.*.discount_total"  => 'floatval',
            "order_items.*.total"           => 'floatval',
            "order_items.*.line_total"      => 'floatval',
            "order_items.*.cart_index"      => 'intval',
            "order_items.*.rate"            => 'floatval',
            "order_items.*.line_meta"       => function ($value) {
                return is_array($value) ? $value : [];
            },
            "order_items.*.other_info"      => function ($value) {
                return is_array($value) ? $value : [];
            },

            "discount.type"   => 'sanitize_text_field',
            "discount.value"  => 'floatval',
            "discount.label"  => 'sanitize_text_field',
            "discount.reason" => 'sanitize_text_field',
            "discount.action" => 'sanitize_text_field',

            "shipping.*.type"         => 'sanitize_text_field',
            "shipping.*.rate_name"    => 'sanitize_text_field',
            "shipping.*.custom_price" => 'floatval',

            "deletedItems"      => function ($value) {
                return is_array($value) ? $value : [];
            },
            "tax_lines" => function ($value) {
                return is_array($value) ? $value : [];
            },
            "tax_lines.*.rate_id"    => 'intval',
            "tax_lines.*.tax_amount" => 'intval',
            "tax_lines.*.label"      => 'sanitize_text_field',
            "tax_lines.*.is_compound"=> function ($value) {
                return (bool) $value;
            },

            // Mirrors rules(): the coupons/apply discount-data shape, keyed by coupon code.
            "applied_coupon.*.id"               => 'intval',
            // Already cents (CouponServiceAdmin rounds the distributed discount to two
            // decimals in cents) — normalize the float artifact without scaling. A bare
            // intval() here truncates, so a 9.99 discount would persist a cent short.
            "applied_coupon.*.discount"         => function ($value) {
                return Helper::roundCent($value);
            },
            "applied_coupon.*.title"            => 'sanitize_text_field',
            "applied_coupon.*.type"             => 'sanitize_text_field',
            "applied_coupon.*.amount"           => 'intval',
            "applied_coupon.*.actual_amount"    => 'floatval',
            "applied_coupon.*.unit_amount"      => 'intval',
            "applied_coupon.*.actual_quantity"  => 'intval',
            'trigger'                           => 'sanitize_text_field',
        ];

    }
}
