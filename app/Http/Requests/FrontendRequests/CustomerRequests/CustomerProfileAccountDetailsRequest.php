<?php

namespace FluentCart\App\Http\Requests\FrontendRequests\CustomerRequests;

use FluentCart\Framework\Foundation\RequestGuard;

class CustomerProfileAccountDetailsRequest extends RequestGuard
{

    /**
     * @return array
     */
    public function rules()
    {

        return [
            'first_name'           => 'sanitizeText|maxLength:255',
            'last_name'            => 'sanitizeText|maxLength:255',
            'email'                => 'required|email',
            'current_password'     => 'sanitizeText|nullable',
            'new_password'         => 'sanitizeText|nullable',
            'confirm_new_password' => 'sanitizeText|nullable',
        ];
    }

    /**
     * @return array
     */
    public function messages()
    {
        return [
            'email.required' => esc_html__('Email field is required.', 'fluent-cart')
        ];
    }

    /**
     * @return array
     */
    public function sanitize()
    {
        return [
            'first_name'           => 'sanitize_text_field',
            'last_name'            => 'sanitize_text_field',
            'email'                => function ($value) {
                if(empty($value)) {
                    return '';
                }

                return sanitize_email($value);
            },
            'new_password'         => '',
            'current_password'     => '',
            'confirm_new_password' => ''
        ];
    }
}
