<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\App\Models\ShippingClass;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class ProductRequest extends RequestGuard
{
    /**
     * Prepare and normalize the incoming data before validation.
     *
     * This method ensures that each variant in the request payload has the necessary structure
     * expected for processing, particularly focusing on the `other_info` attribute.
     *
     * If `other_info` is missing from a variant (common during data migration scenarios),
     * this method sets default values to prevent validation or processing errors.
     *
     * It also ensures that:
     * - `fulfillment_type` is consistently applied across all variants, defaulting to 'physical'.
     * - `payment_type` is set (defaulting to 'onetime') and injected into `other_info`.
     * - `other_info` is populated with a consistent structure containing default billing and setup fee options.
     *
     * @return array The normalized request data ready for validation.
     */
    public function beforeValidation()
    {


        $data = $this->all();

        $fulfilmentType = Arr::get(
            $data,
            'detail.fulfillment_type',
            Arr::get($data, 'detail.fulfillment_type', 'physical')
        );

        $variants = Arr::wrap(
            Arr::get($data, 'variants', [])
        );

        foreach ($variants as $index => &$variant) {
            $variant['fulfillment_type'] = $variant['fulfillment_type'] ?? $fulfilmentType;

            $variant['other_info'] = Arr::wrap(Arr::get($variant, 'other_info')) ?? [];

            // get payment_type from $variant
            $paymentType = Arr::get($variant, 'other_info.payment_type', 'onetime');

            $variant['shipping_class'] = Arr::get($variant, 'shipping_class', null);

            $variant['other_info'] = [
                'payment_type'       => $paymentType,
                'times'              => Arr::get($variant, 'other_info.times', ''),
                'trial_days'         => Arr::get($variant, 'other_info.trial_days', ''),
                //'purchasable'        => Arr::get($variant, 'other_info.purchasable', ''),
                'repeat_interval'    => Arr::get($variant, 'other_info.repeat_interval', 'yearly'),
                'billing_summary'    => Arr::get($variant, 'other_info.billing_summary', ''),
                'manage_setup_fee'   => Arr::get($variant, 'other_info.manage_setup_fee', 'no'),
                'signup_fee_name'    => Arr::get($variant, 'other_info.signup_fee_name', ''),
                'signup_fee'         => Arr::get($variant, 'other_info.signup_fee', ''),
                'setup_fee_per_item' => Arr::get($variant, 'other_info.setup_fee_per_item', 'no'),
                'installment'        => Arr::get($variant, 'other_info.installment', 'no'),
            ];

            $data['variants'][$index] = $variant;
        }

        return $data;
    }

    function validateShippingClassId($attribute, $value): ?string
    {
        static $checked = [];

        if (empty($value)) {
            return null;
        }

        // Return cached result if we've already checked this value
        if (isset($checked[$value])) {
            return $checked[$value];
        }

        if (!is_numeric($value)) {
            $checked[$value] = __("Invalid Shipping Class.", 'fluent-cart');
            return $checked[$value];
        }

        if (empty(ShippingClass::query()->find($value))) {
            $checked[$value] = __("Invalid Shipping Class.", 'fluent-cart');
            return $checked[$value];
        }

        return null;

    }

    function validateTaxClassId($attribute, $value): ?string
    {
        static $checked = [];

        if (empty($value)) {
            return null;
        }

        // Return cached result if we've already checked this value
        if (isset($checked[$value])) {
            return $checked[$value];
        }

        if (!is_numeric($value)) {
            $checked[$value] = __("Invalid Tax Class.", 'fluent-cart');
            return $checked[$value];
        }

        if (empty(\FluentCart\App\Models\TaxClass::query()->find($value))) {
            $checked[$value] = __("Invalid Tax Class.", 'fluent-cart');
            return $checked[$value];
        }

        return null;

    }

    public function validatePostDate($attribute, $value): ?string
    {
        if ($this->get('post_status') !== 'future') {
            return null;
        }

        if (empty($value)) {
            return __("The post date is required when status is scheduled.", 'fluent-cart');
        }
        $currentTime = DateTime::gmtNow();
        try {
            $postDate = DateTime::anyTimeToGmt($value);
        } catch (\Exception $exception) {
            return __("The post date is invalid.", 'fluent-cart');
        }

        if ($postDate < $currentTime) {
            return sprintf(__("The post date must be in the future.", 'fluent-cart'));
        }
        return null;
    }


    /**
     * @return array
     */
    public function rules(): array
    {

        $variationType = Arr::get($this->all(), 'detail.variation_type', 'simple');
        $rules = [
            'post_title'                          => 'required|sanitizeText|maxLength:200',
            'post_excerpt'                        => [
                'nullable',
                'string',
                'validate_excerpt' => function ($attr, $value) {
                    $words = explode(' ', $value);
                    $excerpt_length = (int)apply_filters('excerpt_length', 55);
                    if (count($words) > $excerpt_length) {
                        return sprintf(
                            /* translators: %d: The maximum number of words allowed */
                            __("The excerpt field cannot contain more than %d words.", 'fluent-cart'),
                            $excerpt_length
                        );
                    }
                    return null;
                }
            ],
            'post_status'                         => ['required', 'string'],
            'post_date'                           => function ($attribute, $value) {
                return $this->validatePostDate($attribute, $value);
            },
            'comment_status'                      => 'nullable|sanitizeText|maxLength:100',
            'detail.fulfillment_type'             => 'required|sanitizeText|maxLength:100',
            'detail.variation_type'               => 'required|sanitizeText|maxLength:100',
            'detail.min_price'                    => 'nullable|numeric',
            'detail.max_price'                    => 'nullable|numeric',
            'detail.stock_availability'           => 'nullable|sanitizeText',
            'detail.manage_stock'                 => 'nullable|numeric',
            'detail.manage_downloadable'          => 'nullable|numeric',
            'detail.other_info'                   => 'nullable|array',
            'detail.other_info.group_pricing_by'  => 'nullable|sanitizeText|in:payment_type,repeat_interval,none',
            'detail.other_info.sold_individually'  => 'nullable|sanitizeText|in:yes,no',
            'detail.other_info.use_pricing_table' => 'nullable|sanitizeText',
            'detail.other_info.shipping_class'    => ['nullable', function ($attribute, $value) {
                return $this->validateShippingClassId($attribute, $value);
            }],
            'detail.other_info.tax_class'         => ['nullable', function ($attribute, $value) {
                return $this->validateTaxClassId($attribute, $value);
            }],
            'detail.other_info.active_editor'     => 'nullable|sanitizeText',
            'product_terms'                       => 'nullable|array',
            'product_terms.*'                     => 'nullable|array',
            'product_terms.*.*'                   => 'nullable|numeric',

            // 'variants' => 'required_if:post_status,publish,future',
            'variants.*.variation_title'          => 'required|sanitizeText|maxLength:200',
            'variants.*.post_id'                  => 'required|numeric',
            'variants.*.item_price'               => 'nullable|numeric|min:0',
            'variants.*.compare_price'            => [
                'nullable',
                'numeric',
                function ($attribute, $value) {
                    $index = explode('.', $attribute)[1];
                    $itemPrice = $this->get("variants.$index.item_price");
                    if (empty($itemPrice)) {
                        $itemPrice = 0;
                    }
                    if ($value !== null && $value < $itemPrice) {
                        return sprintf(__("Compare price must be greater than or equal to item price.", 'fluent-cart'));
                    }
                    return null;
                },
            ],
            'variants.*.manage_cost'              => 'nullable|sanitizeText|maxLength:10',
//            'variants.*.shipping_class'           => ['nullable', 'numeric', function ($attribute, $value) {
//                return $this->validateShippingClassId($attribute, $value);
//            }],
//            'variants.*.item_cost'                => 'required_if:variants.*.manage_cost,true',
            'variants.*.serial_index'             => 'nullable|numeric',
//            'variants.*.downloadable' => 'nullable|sanitizeText|maxLength:10',
        ];

        if ($variationType === 'simple') {
            $variantsOtherInfoRules = [

                'variants.*.other_info'                  => 'required|array',
                'variants.*.other_info.description'      => 'nullable|sanitizeTextArea|maxLength:255',
                'variants.*.other_info.payment_type'     => 'required|sanitizeText|in:onetime,subscription',
                'variants.*.other_info.times'            => 'nullable|sanitizeText|maxLength:50',
                'variants.*.other_info.trial_days'       => 'nullable|sanitizeText|maxLength:365',
                'variants.*.other_info.repeat_interval'  => 'required_if:variants.*.other_info.payment_type,subscription|sanitizeText|maxLength:100',
                'variants.*.other_info.billing_summary'  => 'nullable|sanitizeTextArea|maxLength:255',
                'variants.*.other_info.manage_setup_fee' => 'required_if:variants.*.other_info.payment_type,subscription|sanitizeText|maxLength:100',
                'variants.*.other_info.signup_fee'       => 'required_if:variants.*.other_info.manage_setup_fee,yes',
                'variants.*.other_info.signup_fee_name'  => 'required_if:variants.*.other_info.manage_setup_fee,yes|sanitizeText|maxLength:100',
            ];
            $rules = array_merge($rules, $variantsOtherInfoRules);

        }


//        $stockValidations = [
//            'variants.*.manage_stock'             => 'nullable|numeric',
//            'variants.*.stock_status'             => 'required_if:detail.manage_stock,1|sanitizeText|maxLength:50',
//            'variants.*.total_stock'              => 'required|numeric',
//            'variants.*.available'                => 'required|numeric',
//            'variants.*.committed'                => 'required|numeric',
//            'variants.*.on_hold'                  => 'required|numeric',
//        ];
//
//        if($this->get('detail.manage_stock') == 1) {
//            $rules = array_merge($rules, $stockValidations);
//        }
        return $rules;
    }

    public function afterValidation($validator): array
    {
        $data = $this->get();
        foreach (Arr::get($data, 'variants', []) as $index => &$variant) {
            if (empty($variant['item_price'])) {
                $variant['item_price'] = 0;
            }
        }
        return $data;
    }


    /**
     * @return array
     */
    public function messages(): array
    {
        $messages = [
            'post_title.required'                 => esc_html__('Title is required.', 'fluent-cart'),
            'post_title.max'                      => esc_html__('Title may not be greater than 200 characters.', 'fluent-cart'),
            'detail.fulfillment_type.required'    => esc_html__('Fulfilment Type is required.', 'fluent-cart'),
            'detail.variation_type.required'      => esc_html__('Variation Type is required.', 'fluent-cart'),
            'variants.required_if'                => esc_html__('Pricing is required when status is publish or scheduled.', 'fluent-cart'),
            'variants.*.variation_title.required' => esc_html__('Title is required.', 'fluent-cart'),
            'variants.*.variation_title.max'      => esc_html__('Title may not be greater than 200 characters.', 'fluent-cart'),
            'variants.*.item_price.required'      => esc_html__('Price is required.', 'fluent-cart'),
            'variants.*.item_price.numeric'       => esc_html__('Price must be a number.', 'fluent-cart'),
            'variants.*.item_price.min'           => esc_html__('Price must be a positive number greater than 0.', 'fluent-cart'),

        ];

        $variationType = Arr::get($this->all(), 'detail.variation_type', 'simple');
        if ($variationType === 'simple') {
            $otherInfoMessages = [
                'variants.*.stock_status.required_if'               => esc_html__('Stock status is required.', 'fluent-cart'),
                'variants.*.item_cost.required_if'                  => esc_html__('Item cost is required.', 'fluent-cart'),
                'variants.*.other_info.description.max'             => esc_html__('Description may not be greater than 255 characters.', 'fluent-cart'),
                'variants.*.other_info.payment_type.required'       => esc_html__('Payment Type is required.', 'fluent-cart'),
                'variants.*.other_info.times.required_if'           => esc_html__('Times is required.', 'fluent-cart'),
                'variants.*.other_info.repeat_interval.required_if' => esc_html__('Interval is required.', 'fluent-cart'),
                'variants.*.other_info.signup_fee.required_if'      => esc_html__('Setup Fee Amount is required.', 'fluent-cart'),
                'variants.*.other_info.signup_fee_name.required_if' => esc_html__('Setup Fee Name is required.', 'fluent-cart'),
            ];

            $messages = array_merge($messages, $otherInfoMessages);
        }

        return $messages;
    }


    /**
     * @return array
     */
    public function sanitize(): array
    {

        $sanitizer = [
            'ID'             => 'intval',
            'post_title'     => 'sanitize_text_field',
            'post_name'      => 'sanitize_text_field',
            'post_status'    => 'sanitize_text_field',
            'comment_status' => 'sanitize_text_field',
            'post_date'      => 'sanitize_text_field',
            'post_excerpt'   => 'wp_strip_all_tags',
            'post_content'   => 'wp_kses_post',

            'detail.id'                           => 'intval',
            'detail.post_id'                      => 'intval',
            'detail.fulfillment_type'             => 'sanitize_text_field',
            'detail.variation_type'               => 'sanitize_key',
            'detail.stock_availability'           => 'sanitize_key',
            'detail.manage_stock'                 => 'intval',
            'detail.manage_downloadable'          => 'intval',
            'detail.default_variation_id'         => 'intval',
            'detail.other_info.group_pricing_by'  => 'sanitize_text_field',
            'detail.other_info.sold_individually'  => 'sanitize_text_field',
            'detail.other_info.use_pricing_table' => 'sanitize_text_field',
            'detail.other_info.shipping_class'    => 'intval',
            'detail.other_info.tax_class'         => 'intval',
            'detail.other_info.active_editor'     => 'sanitize_text_field',
            'variants.*.id'                       => 'intval',
            'variants.*.rowId'                    => 'intval',
            'variants.*.post_id'                  => 'intval',
            'variants.*.variation_title'          => 'sanitize_text_field',
            'variants.*.item_price'               => 'floatval',
            'variants.*.compare_price'            => 'floatval',
            'variants.*.item_cost'                => 'floatval',

            'product_terms.*.product-categories' => 'sanitize_text_field',
            'product_terms.*.product-brands'       => 'sanitize_text_field',

            'gallery.*.id'    => 'intval',
            'gallery.*.url'   => function ($value) {
                if (empty($value)) {
                    return '';
                }

                return sanitize_url($value);
            },
            'gallery.*.title' => 'sanitize_text_field',
            'metaValue'       => function ($value) {
                return $value;
            }
        ];

        $otherInfoSanitizer = [
            'variants.*.manage_cost'    => 'sanitize_text_field',
            'variants.*.total_stock'    => 'intval',
            'variants.*.available'      => 'intval',
            'variants.*.shipping_class' => 'intval',
            'variants.*.committed'      => 'intval',
            'variants.*.on_hold'        => 'intval',
            'variants.*.manage_stock'   => 'intval',
            'variants.*.stock_status'   => 'sanitize_key',
            'variants.*.serial_index'   => 'intval',
            'variants.*.media.*.id'     => 'intval',
            'variants.*.media.*.url'    => function ($value) {
                if (empty($value)) {
                    return '';
                }

                return sanitize_url($value);
            },
            'variants.*.media.*.title'  => 'sanitize_text_field',

            'variants.*.downloadable' => 'sanitize_text_field',

            'variants.*.other_info'                  => function ($value) {
                return is_array($value) ? $value : [];
            },
            'variants.*.other_info.description'      => function ($value) {
                return $value;
            },
            'variants.*.other_info.payment_type'     => 'sanitize_text_field',
            'variants.*.other_info.times'            => 'sanitize_text_field',
            'variants.*.other_info.repeat_interval'  => 'sanitize_text_field',
            'variants.*.other_info.billing_summary'  => 'sanitize_text_field',
            'variants.*.other_info.manage_setup_fee' => 'sanitize_text_field',
            'variants.*.other_info.signup_fee'       => 'floatval',
            'variants.*.other_info.signup_fee_name'  => 'sanitize_text_field',
            'variants.*.other_info.installment'      => 'sanitize_text_field',
        ];


        $sanitizer = array_merge($sanitizer, $otherInfoSanitizer);

        return $sanitizer;
    }
}
