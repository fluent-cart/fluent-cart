<?php

namespace FluentCart\App\Modules\Shipping\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class ShippingMethodRequest extends RequestGuard
{

    public function beforeValidation()
    {
        $data = $this->all();
        $data['is_enabled'] = (string)(Arr::get($data, 'is_enabled', 0)) === '1' ? 1 : 0;
        return $data;
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            'zone_id'  => 'required',
            'title'    => 'required|string|maxLength:192',
            'amount'   => 'nullable',
            'type'     => 'required|string|maxLength:192',
            'settings' => 'nullable|array',
            'states'   => 'nullable|array'
        ];
    }

    /**
     * @return array
     */
    public function messages()
    {
        return [
            'zone_id.required' => esc_html__('Shipping zone is required.', 'fluent-cart'),
            'title.required'   => esc_html__('Shipping method title is required.', 'fluent-cart'),
            'type.required'    => esc_html__('Shipping method type is required.', 'fluent-cart')
        ];
    }

    /**
     * @return array
     */
    public function sanitize()
    {
        return [
            'zone_id'                    => 'intval',
            'title'                      => 'sanitize_text_field',
            'type'                       => 'sanitize_text_field',
            'amount'                     => 'sanitize_text_field',
            'is_enabled'                 => 'sanitize_text_field',
            'method_id'                  => 'intval',
            'states'                     => function ($value) {
                if (is_array($value)) {
                    return array_map('sanitize_text_field', $value);
                }
                return $value;
            },
            'settings.configure_rate'    => 'sanitize_text_field',
            'settings.class_aggregation' => 'sanitize_text_field',
            'meta'                       => function ($value) {
                if (!is_array($value)) {
                    return [];
                }

                $sanitized = [];
                foreach ($value as $key => $val) {
                    if (is_string($val)) {
                        $sanitized[$key] = sanitize_text_field($val);
                    }
                }
                return $sanitized;
            }
        ];
    }

}
