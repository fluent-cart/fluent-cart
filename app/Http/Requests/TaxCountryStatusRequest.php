<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class TaxCountryStatusRequest extends RequestGuard
{
    public function rules(): array
    {
        return [
            'enabled' => 'required|numeric|min:0|max:1',
        ];
    }

    public function sanitize(): array
    {
        return [
            'enabled' => 'intval',
        ];
    }
}
