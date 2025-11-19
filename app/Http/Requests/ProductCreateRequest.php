<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\App\Models\ShippingClass;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class ProductCreateRequest extends RequestGuard
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


    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            'post_title'              => 'required|sanitizeText|maxLength:200',
            'post_status'             => ['nullable', 'string'],
            'detail.fulfillment_type' => ['sanitizeText', 'maxLength:100', function ($attribute, $value) {
                if (!in_array($value, ['physical', 'digital'])) {
                    return __('Invalid fulfillment type.', 'fluent-cart');
                }
                return null;
            }],
        ];
    }


    /**
     * @return array
     */
    public function messages(): array
    {
        return [
            'post_title.required'              => esc_html__('Title is required.', 'fluent-cart'),
            'post_title.max'                   => esc_html__('Title may not be greater than 200 characters.', 'fluent-cart'),
            'detail.fulfillment_type.required' => esc_html__('Fulfilment Type is required.', 'fluent-cart'),
        ];
    }


    /**
     * @return array
     */
    public function sanitize(): array
    {

        return [
            'post_title'              => 'sanitize_text_field',
            'post_status'             => 'sanitize_text_field',
            'detail.fulfillment_type' => function ($value) {
                if (empty($value)) {
                    return 'digital';
                }
                return sanitize_text_field($value);
            },
        ];

    }
}
