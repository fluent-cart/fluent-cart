<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class OrderEditRequest extends RequestGuard
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
            'shipping_total'        => 'numeric',
            'tax_total'             => 'numeric',
            'total_amount'          => 'numeric',
            'rate'                  => 'numeric',
            'note'                  => 'nullable|sanitizeTextArea|maxLength:5000',
            'uuid'                  => 'nullable|sanitizeText|maxLength:100',
            'ip_address'            => 'nullable|sanitizeText|maxLength:100',
            'completed_at'          => 'nullable|sanitizeText|maxLength:100',
            'refunded_at'           => 'nullable|sanitizeText|maxLength:100',

            'order_items'                   => 'required|array',
            "order_items.*.id"              => 'numeric|min:1',
            "order_items.*.order_id"        => 'numeric|min:1',
            "order_items.*.post_id"         => 'numeric|min:1',
            "order_items.*.object_id"       => 'numeric|min:1',
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

            'deletedItems' => 'nullable|array',

            'applied_coupon'                       => 'nullable|array',
            "applied_coupon.*.id"                  => 'nullable|numeric|min:1',
            "applied_coupon.*.order_id"            => 'nullable|numeric|min:1',
            "applied_coupon.*.coupon_id"           => 'required|numeric|min:1',
            //"applied_coupon.*.title"               => 'required|string|max:100',
            "applied_coupon.*.code"                => 'required|sanitizeText|maxLength:100',
            //"applied_coupon.*.status"              => 'required|string|max:100',
            //"applied_coupon.*.type"                => 'required|string|max:100',
            "applied_coupon.*.amount"              => 'nullable|numeric',
            "applied_coupon.*.discounted_amount"   => 'required|numeric',
            "applied_coupon.*.discount"            => 'nullable|numeric',
            "applied_coupon.*.stackable"           => 'required|numeric',
            "applied_coupon.*.priority"            => 'nullable|numeric',
            "applied_coupon.*.max_uses"            => 'nullable|numeric',
            "applied_coupon.*.use_count"           => 'nullable|numeric',
            "applied_coupon.*.max_per_customer"    => 'nullable|numeric|min:1',
            "applied_coupon.*.min_purchase_amount" => 'nullable|numeric',
            "applied_coupon.*.max_discount_amount" => 'nullable|numeric',
            "applied_coupon.*.notes"               => 'nullable|sanitizeTextArea|maxLength:100',
            'trigger'                              => 'nullable|string',
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
            'shipping_total'        => 'floatval',
            'tax_total'             => 'floatval',
            'total_amount'          => 'floatval',
            'rate'                  => 'sanitize_text_field',
            'note'                  => 'sanitize_text_field',
            'uuid'                  => 'sanitize_text_field',
            'ip_address'            => 'sanitize_text_field',
            'billing_address_id'    => 'intval',
            'shipping_address_id'   => 'intval',
            'completed_at'          => 'sanitize_text_field',
            'refunded_at'           => 'sanitize_text_field',


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
            "order_items.*.line_meta"       => 'sanitize_text_field',
            "order_items.*.other_info"      => 'sanitize_text_field',

            "discount.type"   => 'sanitize_text_field',
            "discount.value"  => 'floatval',
            "discount.label"  => 'sanitize_text_field',
            "discount.reason" => 'sanitize_text_field',
            "discount.action" => 'sanitize_text_field',

            "shipping.*.type"         => 'sanitize_text_field',
            "shipping.*.rate_name"    => 'sanitize_text_field',
            "shipping.*.custom_price" => 'floatval',

            "customer.*.id"             => 'intval',
            "customer.*.user_id"        => 'intval',
            "customer.*.contact_id"     => 'intval',
            "customer.*.email"          => function ($value) {
                if(empty($value)) {
                    return '';
                }

                return sanitize_email($value);
            },
            "customer.*.first_name"     => 'sanitize_text_field',
            "customer.*.last_name"      => 'sanitize_text_field',
            "customer.*.status"         => 'sanitize_text_field',
            "customer.*.purchase_value" => 'floatval',
            "customer.*.purchase_count" => 'intval',
            "customer.*.country"        => 'sanitize_text_field',
            "customer.*.city"           => 'sanitize_text_field',
            "customer.*.state"          => 'sanitize_text_field',
            "customer.*.postcode"       => 'sanitize_text_field',
            "customer.*.uuid"           => 'sanitize_text_field',
            "customer.*.full_name"      => 'sanitize_text_field',

            "deletedItems"      => function ($value) {
                return is_array($value) ? $value : [];
            },

            "applied_coupon.*.id"                  => 'intval',
            "applied_coupon.*.order_id"            => 'intval',
            "applied_coupon.*.coupon_id"           => 'intval',
            "applied_coupon.*.title"               => 'sanitize_text_field',
            "applied_coupon.*.discount"            => 'intval',
            "applied_coupon.*.code"                => 'sanitize_text_field',
            "applied_coupon.*.status"              => 'sanitize_text_field',
            "applied_coupon.*.type"                => 'sanitize_text_field',
            "applied_coupon.*.amount"              => 'intval',
            "applied_coupon.*.discounted_amount"   => 'intval',
            "applied_coupon.*.stackable"           => 'intval',
            "applied_coupon.*.priority"            => 'intval',
            "applied_coupon.*.max_uses"            => 'intval',
            "applied_coupon.*.use_count"           => 'intval',
            "applied_coupon.*.max_per_customer"    => 'intval',
            "applied_coupon.*.min_purchase_amount" => 'intval',
            "applied_coupon.*.max_discount_amount" => 'intval',
            "applied_coupon.*.notes"               => 'sanitize_text_field',
            'trigger'                              => 'sanitize_text_field',
        ];

    }
}
