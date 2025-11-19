<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class CartRequest extends RequestGuard
{
    /**
     * @return array
     */
    public function rules()
    {
        return [
            'customer_id' => 'integer',
            'user_id' => 'integer',
            'order_id' => 'integer',
            'cart_hash' => 'sanitizeText',
            'checkout_data' => 'sanitizeTextArea|maxLength:255',
            'cart_data' => 'sanitizeTextArea',
            'first_name' => 'sanitizeText|maxLength:255',
            'last_name' => 'sanitizeText|maxLength:255',
            'email' => 'sanitizeText|maxLength:255',
            'stage' => 'sanitizeText|maxLength:30',
            'cart_group' => 'sanitizeText|maxLength:30',
        ];
    }

    /**
     *
     * @return array
     */
    public function messages()
    {
        return [
            'customer_id' => esc_html__('Customer Id must be a number.', 'fluent-cart'),
            'user_id' => esc_html__('User Id must be a number.', 'fluent-cart'),
        ];
    }


    /**
     *
     * @return array
     */
    public function sanitize()
    {
        return [
            'first_name' => 'sanitize_text_field',
            'last_name' => 'sanitize_text_field',
            'email' => function ($value) {
                if(empty($value)) {
                    return '';
                }

                return sanitize_email($value);
            },
        ];
    }
}

