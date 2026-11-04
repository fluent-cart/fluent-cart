<?php

namespace FluentCart\App\Modules\Shipping\Http\Requests;

use FluentCart\App\App;
use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Validator\ValidationException;

class ShippingZoneRequest extends RequestGuard
{

    public function beforeValidation()
    {
        $data = $this->all();
        $data['region'] = Arr::get($data, 'region', '');
        $data['order'] = Arr::get($data, 'order', '');

        return $data;
    }

    /**
     * @return array
     */
    public function rules()
    {

        return [
            'name'   => 'required|string|maxLength:192',
            'region' => function ($attr, $value) {
                if ($value === 'all') {
                    $query = \FluentCart\App\Models\ShippingZone::query()->where('region', 'all')
                        ->where(function ($q) {
                            $q->whereNull('shipping_class_id')
                              ->orWhere('shipping_class_id', 0);
                        });
                    // Exclude current zone on update
                    $currentId = Arr::get($this->all(), 'id') ?: App::getInstance()->request->get('id');
                    if ($currentId) {
                        $query->where('id', '!=', intval($currentId));
                    }
                    if ($query->first()) {
                        return __('Only one "Whole World" shipping zone is allowed.', 'fluent-cart');
                    }
                }
                return null;
            },
            'order'             => 'nullable|integer',
            'meta'              => 'nullable|array',
            'meta.countries'    => 'nullable|array',
            'meta.selection_type' => 'nullable|string|in:included,excluded',
        ];
    }

    /**
     * @return array
     */
    public function messages()
    {
        return [
            'name.required'   => esc_html__('Shipping name is required.', 'fluent-cart'),
            'name.max'        => esc_html__('Shipping name cannot exceed 192 characters.', 'fluent-cart'),
            'region.required' => esc_html__('Shipping country region is required.', 'fluent-cart')
        ];
    }

    /**
     * @return array
     */
    public function sanitize()
    {
        return [
            'name'              => 'sanitize_text_field',
            'region'            => 'sanitize_text_field',
            'order'             => 'intval',
            'meta'              => function ($value) {
                if (!is_array($value)) return [];
                $sanitized = [];
                if (isset($value['countries']) && is_array($value['countries'])) {
                    $sanitized['countries'] = array_map('sanitize_text_field', $value['countries']);
                }
                if (isset($value['selection_type'])) {
                    $sanitized['selection_type'] = sanitize_text_field($value['selection_type']);
                }
                return $sanitized;
            }
        ];
    }
}
