<?php

namespace FluentCartPro\App\Http\Requests;


use FluentCart\Framework\Foundation\RequestGuard;

class OrderBumpRequest extends RequestGuard
{

    public function rules(): array
    {
        return [
            'type'            => 'nullable|sanitizeText|maxLength:50',
            'status'          => 'nullable|sanitizeText|maxLength:50',
            'src_object_id'   => 'required|numeric',
            'src_object_type' => 'nullable|sanitizeText|maxLength:50',
            'title'           => 'required|sanitizeText|maxLength:194',
            'description'     => 'nullable',
            'config'          => 'nullable|array',
            'conditions'      => 'nullable|array',
            'priority'        => 'nullable|numeric|min:1',
        ];
    }

    /**
     * @return array
     */
    public function sanitize(): array
    {
        return [
            'type'            => 'sanitize_text_field',
            'status'          => 'sanitize_text_field',
            'src_object_id'   => 'intval',
            'src_object_type' => 'sanitize_text_field',
            'title'           => 'sanitize_text_field',
            'description'     => function ($value) {
                return wp_kses_post($value);
            },
            'config'          => function ($value) {
                return $value;
            },
            'conditions'      => function ($value) {
                return $value;
            },
            'priority'        => 'intval',
        ];
    }
}
