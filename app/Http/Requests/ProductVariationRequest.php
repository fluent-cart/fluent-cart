<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class ProductVariationRequest extends RequestGuard
{
    /**
     * Normalize variant data before validation.
     *
     * This method is primarily used to ensure that the `variants` array contains
     * the required structure, especially during data migration or when
     * optional fields like `other_info` are not provided.
     *
     * Key operations:
     * - Sets a default `fulfillment_type` (fallback to 'physical') if missing.
     * - Sets a default `payment_type` (fallback to 'onetime') if missing.
     * - Ensures `other_info` exists and assigns default billing/setup fee-related values if it's empty.
     *
     * This helps avoid issues during validation or processing by guaranteeing a consistent data structure.
     *
     * @return array The normalized data ready for validation.
     */
    public function beforeValidation()
    {
        $data = $this->all();
        $fulfilmentType = Arr::get(
            $data,
            'variants.fulfillment_type',
            Arr::get($data, 'variants.fulfillment_type', 'physical')
        );
        $paymentType = Arr::get($data, 'variants.payment_type', 'onetime');
        $manageCost = Arr::get($data, 'variants.manage_cost');
        if (empty($manageCost)) {
            $manageCost = 'false';
        }
        $data['variants']['fulfillment_type'] = $fulfilmentType;
        $data['variants']['manage_cost'] = $manageCost;

        $variantOtherInfo = Arr::wrap(Arr::get($data, 'variants.other_info'));

        // Ensure other_info is an array
        if (empty($variantOtherInfo)) {
            $variantOtherInfo = [
                'payment_type'       => $paymentType,
                'times'              => '',
                'trial_days'         => '',
                'repeat_interval'    => 'yearly',
                'billing_summary'    => '',
                'manage_setup_fee'   => 'no',
                'signup_fee_name'    => '',
                'signup_fee'         => '',
                'setup_fee_per_item' => 'no',
                //'purchasable'        => 'yes',

            ];
        }
        $data['variants']['other_info'] = $variantOtherInfo;

        return $data;
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            'variants.variation_title'  => 'required|sanitizeText|maxLength:200',
            'variants.item_price'       => 'nullable|numeric|min:0',
            'variants.compare_price'    => [
                'nullable',
                'numeric',
                function ($attribute, $value) {
                    $itemPrice = $this->get("variants.item_price");
                    if (empty($itemPrice)) {
                        $itemPrice = 0;
                    }
                    if ($value !== null && $value < $itemPrice) {
                        return sprintf(__("Compare price must be greater than or equal to item price.", 'fluent-cart'));
                    }
                    return null;
                },
            ],
            'variants.manage_cost'      => 'nullable|sanitizeText|maxLength:10',
            'variants.item_cost'        => 'required_if:variants.manage_cost,true',
            'variants.fulfillment_type' => 'required|sanitizeText|maxLength:100',

            'variants.manage_stock' => 'nullable|numeric',
            'variants.stock_status' => 'required_if:variants.manage_stock,1|sanitizeText|maxLength:50',
            'variants.total_stock'  => 'required|numeric',
            'variants.available'    => 'required|numeric',
            // 'variants.available' => [
            //     'required',
            //     'numeric',
            //     function ($attribute, $value, $fail) {
            //         if ($this->variants['stock_status'] == 'in-stock' && $value <= 0) {
            //             return __("The available stock must be greater than 0 when stock is set to in stock", 'fluent-cart');
            //         }
            //         return null;
            //     },
            // ],
            'variants.committed'    => 'required|numeric',
            'variants.on_hold'      => 'required|numeric',

            'variants.serial_index' => 'nullable|numeric',

            'variants.other_info'                  => 'required|array',
            'variants.other_info.description'      => 'nullable|sanitizeTextArea|maxLength:255',
            'variants.other_info.payment_type'     => 'required|sanitizeText|in:onetime,subscription',
            'variants.other_info.times'            => 'nullable|numeric',
            'variants.other_info.trial_days'       => 'nullable|numeric|max:365',
            'variants.other_info.repeat_interval'  => 'required_if:variants.other_info.payment_type,subscription|sanitizeText|maxLength:100',
            'variants.other_info.billing_summary'  => 'nullable|sanitizeTextArea|maxLength:255',
            'variants.other_info.manage_setup_fee' => 'required_if:variants.other_info.payment_type,subscription|sanitizeText|maxLength:100',
            'variants.other_info.signup_fee'       => 'required_if:variants.other_info.manage_setup_fee,yes',
            'variants.other_info.signup_fee_name'  => 'required_if:variants.other_info.manage_setup_fee,yes|sanitizeText|maxLength:100',

            'variants.downloadable' => 'nullable|sanitizeText|maxLength:10',
        ];
    }


    public function afterValidation($validator): array
    {

        $data = $this->get();

        $price = $data['variants']['item_price'];

        if (empty($price)) {
            $data['variants']['item_price'] = 0;
        }

        return $data;
    }


    /**
     * @return array
     */
    public function messages()
    {
        return [
            'variants.variation_title.required'  => esc_html__('Title is required.', 'fluent-cart'),
            'variants.variation_title.max'       => esc_html__('Title may not be greater than 200 characters.', 'fluent-cart'),
            'variants.item_price.required'       => esc_html__('Price is required.', 'fluent-cart'),
            'variants.item_price.numeric'        => esc_html__('Price must be a number.', 'fluent-cart'),
            'variants.item_price.min'            => esc_html__('Price must be a positive number greater than 0.', 'fluent-cart'),
            'variants.stock_status.required_if'  => esc_html__('Stock status is required.', 'fluent-cart'),
            'variants.item_cost.required_if'     => esc_html__('Item cost is required.', 'fluent-cart'),
            'variants.fulfillment_type.required' => esc_html__('Fulfilment Type is required.', 'fluent-cart'),

            'variants.other_info.description.max'             => esc_html__('Description may not be greater than 255 characters.', 'fluent-cart'),
            'variants.other_info.payment_type.required'       => esc_html__('Payment Type is required.', 'fluent-cart'),
            'variants.other_info.times.required_if'           => esc_html__('Times is required.', 'fluent-cart'),
            'variants.other_info.repeat_interval.required_if' => esc_html__('Interval is required.', 'fluent-cart'),
            'variants.other_info.signup_fee.required_if'      => esc_html__('Setup Fee Amount is required.', 'fluent-cart'),
            'variants.other_info.signup_fee_name.required_if' => esc_html__('Setup Fee Name is required.', 'fluent-cart'),
            'variants.other_info.trial_days.numeric'          => esc_html__('Trial days must be a number.', 'fluent-cart'),
            'variants.other_info.trial_days.max'              => esc_html__('Trial period cannot exceed 365 days.', 'fluent-cart'),
        ];
    }


    /**
     * @return array
     */
    public function sanitize()
    {

        return [
            'variants.id'               => 'intval',
            'variants.rowId'            => 'intval',
            'variants.post_id'          => 'intval',
            'variants.variation_title'  => 'sanitize_text_field',
            'variants.item_price'       => 'floatval',
            'variants.compare_price'    => 'floatval',
            'variants.manage_cost'      => 'sanitize_text_field',
            'variants.fulfillment_type' => 'sanitize_text_field',
            'variants.item_cost'        => 'floatval',
            'variants.total_stock'      => 'intval',
            'variants.available'        => 'intval',
            'variants.committed'        => 'intval',
            'variants.on_hold'          => 'intval',
            'variants.manage_stock'     => 'intval',
            'variants.stock_status'     => 'sanitize_key',
            'variants.serial_index'     => 'intval',
            'variants.media.*.id'       => 'intval',
            'variants.media.*.url'      => function ($value) {
                if (empty($value)) {
                    return '';
                }

                return sanitize_url($value);
            },
            'variants.media.*.title'    => 'sanitize_text_field',

            'variants.downloadable' => 'sanitize_text_field',

            'variants.other_info'                  => function ($value) {
                return is_array($value) ? $value : [];
            },
            'variants.other_info.description'      => 'sanitize_text_field',
            'variants.other_info.payment_type'     => 'sanitize_text_field',
            'variants.other_info.times'            => 'sanitize_text_field',
            'variants.other_info.trial_days'       => 'sanitize_text_field',
            'variants.other_info.repeat_interval'  => 'sanitize_text_field',
            'variants.other_info.billing_summary'  => 'sanitize_text_field',
            'variants.other_info.manage_setup_fee' => 'sanitize_text_field',
            'variants.other_info.signup_fee'       => 'floatval',
            'variants.other_info.signup_fee_name'  => 'sanitize_text_field',
            //'variants.other_info.purchasable'      => 'sanitize_text_field',
        ];

    }
}
