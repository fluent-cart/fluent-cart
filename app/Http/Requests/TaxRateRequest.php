<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class TaxRateRequest extends RequestGuard
{

    public function rules()
    {
        return [
            'country'      => 'nullable|sanitizeText|maxLength:45',
            'state'        => 'nullable|sanitizeText|maxLength:45',
            'postcode'     => 'nullable|sanitizeText|maxLength:45',
            'city'         => 'nullable|sanitizeText|maxLength:45',
            'rate'         => 'nullable|numeric|min:0|max:99999',
            'name'         => 'nullable|sanitizeText|maxLength:45',
            'group'        => 'nullable|sanitizeText|maxLength:45',
            'priority'     => 'nullable|numeric|min:1',
            'is_compound'  => 'nullable|numeric|min:0',
            'for_shipping' => 'nullable|numeric|min:0',
            'for_order'    => 'nullable|numeric|min:0',
            'class_id'     => 'nullable|numeric|min:1',
        ];
    }

    public function sanitize()
    {
        return [
            'country'      => 'sanitize_text_field',
            'state'        => 'sanitize_text_field',
            'postcode'     => 'sanitize_text_field',
            'city'         => 'sanitize_text_field',
            'rate'         => 'floatval',
            'name'         => 'sanitize_text_field',
            'group'        => 'sanitize_text_field',
            'priority'     => 'intval',
            'is_compound'  => 'intval',
            'for_shipping' => 'floatval',
            'for_order'    => 'intval',
            'class_id'     => 'intval',
        ];
    }

}
