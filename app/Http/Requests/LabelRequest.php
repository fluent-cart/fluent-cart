<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class LabelRequest extends RequestGuard
{

    /**
     * @return array
     */
    public function rules()
    {
        return [
            'value' => 'required|sanitizeText|unique:fct_label,value',
        ];
    }

    /**
     * @return array
     */
    public function messages()
    {
        return [
            'value.required' => esc_html__('Label field is required.', 'fluent-cart'),
            'value.unique'   => esc_html__('Label must be unique.', 'fluent-cart'),
        ];
    }

    /**
     * @return array
     */
    public function sanitize()
    {
        return [
            'id'           => 'intval',
            'value'        => 'sanitize_text_field',
            'bind_to_type' => 'sanitize_text_field',
            'bind_to_id'   => 'sanitize_text_field',
        ];
    }
}
