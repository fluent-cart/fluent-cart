<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\App\Models\TaxClass;
use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class TaxRateRequest extends RequestGuard
{

    public function rules()
    {
        return [
            'country'      => 'nullable|sanitizeText|maxLength:45',
            'state'        => 'nullable|sanitizeText|maxLength:45',
            'postcode'     => 'nullable|sanitizeText|maxLength:45',
            'city'         => 'nullable|sanitizeText|maxLength:45',
            'rate'         => 'nullable|sanitizeText|maxLength:45',
            'name'         => 'nullable|sanitizeText|maxLength:45',
            'group'        => 'nullable|sanitizeText|maxLength:45',
            'priority'     => 'nullable|numeric|min:1',
            'is_compound'  => 'nullable|numeric|min:0',
            'for_shipping' => 'nullable|numeric|min:0',
            'for_order'    => 'nullable|numeric|min:0',
            'class_id'     => 'required|min:0',
        ];
    }

    public function sanitize()
    {
        return [
            'country'      => 'sanitize_text_field',
            'state'        => 'sanitize_text_field',
            'postcode'     => 'sanitize_text_field',
            'city'         => 'sanitize_text_field',
            'rate'         => 'sanitize_text_field',
            'name'         => 'sanitize_text_field',
            'group'        => 'sanitize_text_field',
            'priority'     => 'intval',
            'is_compound'  => 'intval',
            'for_shipping' => 'intval',
            'for_order'    => 'intval',
            'class_id'    => 'intval',
        ];
    }

}
