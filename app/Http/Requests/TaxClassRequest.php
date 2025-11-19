<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\App\Models\TaxClass;
use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class TaxClassRequest extends RequestGuard
{

    public function rules()
    {
        $classId = intval(Arr::get($this->all(), 'id', 0));

        return [
            'title'       => 'required|sanitizeText|maxLength:192',
            'description' => 'nullable|sanitizeTextArea',
            'categories'  => 'nullable|array',
        ];
    }

    public function messages()
    {
        return [
            'title.required' => esc_html__('Tax class title is required.', 'fluent-cart'),
        ];
    }

    public function sanitize()
    {
        return [
            'title'       => 'sanitize_text_field',
            'description' => 'sanitize_text_field',
            'priority'    => 'intval',
            'categories'  => function ($value) {
                if (is_array($value)) {
                    return array_map('intval', $value);
                }
                return $value;
            }
        ];
    }

}
