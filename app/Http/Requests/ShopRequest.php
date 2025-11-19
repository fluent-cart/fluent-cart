<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class ShopRequest extends RequestGuard
{

    /**
     * @return array
     */
    public function rules()
    {
        return [];
    }


    /**
     * @return array
     */
    public function messages()
    {
        return [];
    }


    /**
     * @return array
     */
    public function sanitize()
    {
        return [];
    }
}