<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class EmailNotificationRequest extends RequestGuard
{

    /**
     * @return array
     */
    public function rules()
    {
        return [
            'settings.subject'         => 'required|sanitizeText|maxLength:255',
            'settings.email_body'      => 'nullable|string',
            'settings.active'          => 'nullable|sanitizeText',
            'settings.is_default_body' => 'nullable|sanitizeText',
        ];
    }

    /**
     * @return array
     */
    public function messages()
    {
        return [
            'settings.subject.required' => esc_html__('Subject field is required.', 'fluent-cart'),
        ];
    }

    /**
     * @return array
     */
    public function sanitize()
    {
        return [
            'settings.subject'         => 'sanitize_text_field',
            'settings.email_body'      => 'wp_kses_post',
            'settings.active'          => 'sanitize_text_field',
            'settings.is_default_body' => 'sanitize_text_field'
        ];
    }
}
