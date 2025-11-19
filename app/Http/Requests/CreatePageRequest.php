<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class CreatePageRequest extends RequestGuard
{
    public function rules(): array
    {
        return [
            'page_name' => 'sanitizeText|required'
        ];
    }

    public function messages(): array
    {
        return [
            'page_name.required' => esc_html__('Page Name field is required.', 'fluent-cart'),
        ];
    }
}
