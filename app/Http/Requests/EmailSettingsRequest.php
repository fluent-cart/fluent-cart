<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class EmailSettingsRequest extends RequestGuard
{
    public function rules(): array
    {
        return [
            'from_name'         => 'required|sanitizeText|maxLength:255',
            'from_email'        => 'required|email|maxLength:255',
            'reply_to_name'     => 'nullable|sanitizeText|maxLength:255',
            'reply_to_email'    => 'nullable|email|maxLength:255',
            'email_footer'      => 'nullable|string',
            'admin_email'       => 'required|string',
            'show_email_footer' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'from_name.required'   => __('From Name field is required.', 'fluent-cart'),
            'from_email.required'  => __('From Email field is required.', 'fluent-cart'),
            'from_email.email'     => __('From Email must be a valid email address.', 'fluent-cart'),
            'reply_to_email.email' => __('Reply To Email must be a valid email address.', 'fluent-cart'),
            'admin_email.required' => __('Admin Email field is required.', 'fluent-cart')
        ];
    }

    public function sanitize(): array
    {
        return [
            'from_name'         => 'sanitize_text_field',
            'from_email'        => function ($value) {
                if(empty($value)) {
                    return '';
                }

                return sanitize_email($value);
            },
            'reply_to_name'     => 'sanitize_text_field',
            'reply_to_email'    => function ($value) {
                if(empty($value)) {
                    return '';
                }

                return sanitize_email($value);
            },
            'email_footer'      => 'wp_kses_post',
            'admin_email'       => 'sanitize_text_field',
            'show_email_footer' => 'sanitize_text_field'
        ];
    }
}
