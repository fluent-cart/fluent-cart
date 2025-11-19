<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class UserRequest extends RequestGuard
{
    /**
     * @return array
     */
    public function rules()
    {
        return [
            'email' => 'required|email|unique:users,user_email',
            'password' => 'nullable|sanitizeText',
            'full_name' => 'required'
        ];
    }

    /**
     * @return array
     */
    public function messages()
    {
        return [
            'email.required'  => esc_html__('Email is required', 'fluent-cart'),
            'email.email'     => esc_html__('Please enter a valid email address.', 'fluent-cart'),
            'email.unique'    => esc_html__('This email is already registered.', 'fluent-cart'),
            'full_name.required' => esc_html__('Full name is required', 'fluent-cart')
        ];
    }

    /**
     * @return array
     */
    public function sanitize()
    {
        return [
            'email' => function ($value) {
                if(empty($value)) {
                    return '';
                }

                return sanitize_email($value);
            },
            'full_name' => 'sanitize_text_field',
            'password' => ''
        ];
    }
}
