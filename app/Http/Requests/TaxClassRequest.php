<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class TaxClassRequest extends RequestGuard
{

    public function rules()
    {
        return [
            'title'       => 'required|sanitizeText|maxLength:30',
            'description' => 'nullable|sanitizeTextArea',
        ];
    }

    public function messages()
    {
        return [
            'title.required'   => esc_html__('Tax class title is required.', 'fluent-cart'),
            'title.maxLength'  => esc_html__('Tax class title must be 30 characters or fewer.', 'fluent-cart'),
        ];
    }

    public function sanitize()
    {
        return [
            'title'       => 'sanitize_text_field',
            'description' => 'sanitize_text_field',
            'priority'    => 'intval',
        ];
    }

}
