<?php

namespace FluentCart\App\Modules\Shipping\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class ShippingClassRequest extends RequestGuard
{

    public function beforeValidation()
    {
        $data = $this->all();

        $data['per_item'] = (string)(Arr::get($data, 'per_item', 0)) === '1' ? 1 : 0;
        return $data;
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            'name' => 'required|string|maxLength:192',
            'cost' => 'required|numeric|min:0',
            'type' => 'required|string|in:fixed,percentage',
        ];
    }

    /**
     * @return array
     */
    public function messages()
    {
        return [
            'name.required' => esc_html__('Shipping class name is required.', 'fluent-cart'),
            'cost.required' => esc_html__('Shipping class cost is required.', 'fluent-cart'),
            'type.required' => esc_html__('Shipping class type is required.', 'fluent-cart')
        ];
    }

    /**
     * @return array
     */
    public function sanitize()
    {
        return [
            'name'     => 'sanitize_text_field',
            'cost'     => 'sanitize_text_field',
            'type'     => 'sanitize_text_field',
            'per_item' => 'intval'
        ];
    }

}
