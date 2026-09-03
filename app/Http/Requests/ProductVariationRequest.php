<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Http\Rules\RequiredWhenRule;
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

        if (Arr::get($variantOtherInfo, 'payment_type') === 'onetime') {
            $subscriptionFields = ['trial_days', 'times', 'repeat_interval', 'billing_summary', 'manage_setup_fee', 'signup_fee', 'signup_fee_name', 'setup_fee_per_item'];
            foreach ($subscriptionFields as $field) {
                unset($data['variants']['other_info'][$field]);
            }
        }

        return $data;
    }

    /**
     * @return array
     */
    public function rules()
    {
        // total_stock / available / committed / on_hold are `INT(11) NULL DEFAULT 0`
        // (ProductVariationMigrator), so an empty counter is a legitimate stored state for
        // a variation that never tracked stock. ProductEditModel::createOrUpdatePricing()
        // posts the variation back exactly as the drawer loaded it, NULLs included, so
        // demanding a number unconditionally rejected a plain price edit on such a row.
        //
        // This cannot be expressed as `nullable|numeric`: Validator::filterExcludeables()
        // drops EVERY rule for a falsy value the moment `nullable` is present, which
        // would disarm the conditional requirement too. It cannot sit beside a
        // `required_if` string either — filterRequiredIf() discarded this closure along
        // with every other rule whenever tracking was off, leaving the guard inert. The
        // requirement is a RequiredWhenRule closure below for that reason.
        $numericWhenProvided = function ($attribute, $value) {
            if ($value === null || $value === '') {
                return null;
            }

            if (!is_numeric($value)) {
                return esc_html__('Stock quantity must be a number.', 'fluent-cart');
            }

            return null;
        };

        return [
            'variants.variation_title'  => 'required|sanitizeText|maxLength:200',
            'variants.sku'              => 'nullable|sanitizeText|maxLength:30|unique:fct_product_variations,sku' . ($this->get('variants.id') ? ',' . $this->get('variants.id') : ''),
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
            'variants.item_cost'        => [
                RequiredWhenRule::make(
                    'variants.manage_cost',
                    'true',
                    esc_html__('Item cost is required.', 'fluent-cart')
                ),
            ],
            'variants.fulfillment_type' => 'required|sanitizeText|maxLength:100',
            'variants.shipping_class'  => function ($attr, $value) {
                if ($value && !(\FluentCart\App\Models\ShippingClass::find(intval($value)))) {
                    return __('The selected shipping class does not exist.', 'fluent-cart');
                }
                return null;
            },

            'variants.manage_stock' => 'nullable|numeric',
            'variants.stock_status' => [
                RequiredWhenRule::make(
                    'variants.manage_stock',
                    '1',
                    esc_html__('Stock status is required.', 'fluent-cart')
                ),
                'sanitizeText',
                'maxLength:50',
            ],
            // Quantities are only demanded once tracking is actually on, mirroring
            // stock_status directly above.
            'variants.total_stock'  => [
                RequiredWhenRule::make(
                    'variants.manage_stock',
                    '1',
                    esc_html__('Stock quantity is required when inventory tracking is on.', 'fluent-cart')
                ),
                $numericWhenProvided,
            ],
            'variants.available'    => [
                RequiredWhenRule::make(
                    'variants.manage_stock',
                    '1',
                    esc_html__('Available quantity is required when inventory tracking is on.', 'fluent-cart')
                ),
                $numericWhenProvided,
            ],
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
            // Ledger columns the merchant never edits — they are maintained by the stock
            // listeners, so they only have to be a number when the payload carries one.
            'variants.committed'    => [$numericWhenProvided],
            'variants.on_hold'      => [$numericWhenProvided],

            'variants.serial_index' => 'nullable|numeric',

            'variants.other_info'                  => 'required|array',
            'variants.other_info.description'      => 'nullable|sanitizeTextArea|maxLength:255',
            'variants.other_info.payment_type'     => 'required|sanitizeText|in:onetime,subscription',
            'variants.other_info.times'            => [
                function ($attribute, $value) {
                    if ($this->get('variants.other_info.payment_type') !== 'subscription') {
                        return null;
                    }
                    if (!empty($value) && !is_numeric($value)) {
                        return __('Times must be a number.', 'fluent-cart');
                    }
                    return Helper::installmentTimesError($this->get('variants.other_info'));
                },
            ],
            'variants.other_info.trial_days'       => [
                function ($attribute, $value) {
                    if ($this->get('variants.other_info.payment_type') !== 'subscription') {
                        return null;
                    }
                    if (!empty($value) && !is_numeric($value)) {
                        return __('Trial days must be a number.', 'fluent-cart');
                    }
                    if (!empty($value) && $value > 365) {
                        return __('Trial period cannot exceed 365 days.', 'fluent-cart');
                    }
                    return null;
                },
            ],
            'variants.other_info.repeat_interval'  => [
                RequiredWhenRule::make(
                    'variants.other_info.payment_type',
                    'subscription',
                    esc_html__('Interval is required.', 'fluent-cart')
                ),
                'sanitizeText',
                'maxLength:100',
            ],
            'variants.other_info.billing_summary'  => 'nullable|sanitizeTextArea|maxLength:255',
            'variants.other_info.manage_setup_fee' => [
                RequiredWhenRule::make(
                    'variants.other_info.payment_type',
                    'subscription',
                    esc_html__('Setup Fee option is required.', 'fluent-cart')
                ),
                'sanitizeText',
                'maxLength:100',
            ],
            'variants.other_info.signup_fee'       => [
                RequiredWhenRule::make(
                    'variants.other_info.manage_setup_fee',
                    'yes',
                    esc_html__('Setup Fee Amount is required.', 'fluent-cart')
                ),
            ],
            'variants.other_info.signup_fee_name'  => [
                RequiredWhenRule::make(
                    'variants.other_info.manage_setup_fee',
                    'yes',
                    esc_html__('Setup Fee Name is required.', 'fluent-cart')
                ),
                'sanitizeText',
                'maxLength:100',
            ],
            'variants.other_info.package_slug'     => 'nullable|sanitizeText|maxLength:100',
            'variants.other_info.weight'           => 'nullable|numeric',
            'variants.other_info.weight_unit'      => 'nullable|sanitizeText|maxLength:10',
            'variants.other_info.length'           => 'nullable|numeric',
            'variants.other_info.width'            => 'nullable|numeric',
            'variants.other_info.height'           => 'nullable|numeric',
            'variants.other_info.tax_class'        => ['nullable', function ($attribute, $value) {
                if (empty($value)) {
                    return null;
                }

                return empty(\FluentCart\App\Models\TaxClass::query()->where('slug', sanitize_text_field($value))->first())
                    ? __('Invalid Tax Class.', 'fluent-cart')
                    : null;
            }],
            'variants.other_info.tax_exempt'       => 'nullable|sanitizeText|in:yes,no',

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
            'variants.sku.max'                   => esc_html__('SKU may not be greater than 30 characters.', 'fluent-cart'),
            'variants.sku.unique'                => esc_html__('The SKU must be unique.', 'fluent-cart'),
            'variants.item_price.required'       => esc_html__('Price is required.', 'fluent-cart'),
            'variants.item_price.numeric'        => esc_html__('Price must be a number.', 'fluent-cart'),
            'variants.item_price.min'            => esc_html__('Price must be a positive number greater than 0.', 'fluent-cart'),
            'variants.fulfillment_type.required' => esc_html__('Fulfilment Type is required.', 'fluent-cart'),

            'variants.other_info.description.max'             => esc_html__('Description may not be greater than 255 characters.', 'fluent-cart'),
            'variants.other_info.payment_type.required'       => esc_html__('Payment Type is required.', 'fluent-cart'),
            'variants.other_info.times.required_if'           => esc_html__('Times is required.', 'fluent-cart'),
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
            'variants.sku'              => 'sanitize_text_field',
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
            'variants.shipping_class'   => function ($value) {
                return $value ? intval($value) : null;
            },
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
            'variants.other_info.package_slug'     => 'sanitize_text_field',
            'variants.other_info.weight'           => 'floatval',
            'variants.other_info.weight_unit'      => 'sanitize_text_field',
            'variants.other_info.length'           => 'floatval',
            'variants.other_info.width'            => 'floatval',
            'variants.other_info.height'           => 'floatval',
            'variants.other_info.tax_class'        => 'sanitize_text_field',
            'variants.other_info.tax_exempt'       => 'sanitize_text_field',
            //'variants.other_info.purchasable'      => 'sanitize_text_field',
        ];

    }
}
