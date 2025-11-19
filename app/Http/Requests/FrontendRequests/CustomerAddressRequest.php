<?php

namespace FluentCart\App\Http\Requests\FrontendRequests;

use FluentCart\App\App;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class CustomerAddressRequest extends RequestGuard
{

    /**
     * @return array
     */
    public function rules()
    {
        $data = $this->all();
        $type = $this->get('type') . '_';
        $prefix = $type === 'billing_' ? 'billing' : 'shipping';
        $rules = App::localization()->getValidationRule($data, $prefix);

        return array_merge($rules, [
            'type'              => 'required|sanitizeText',
            $type . 'label'     => 'required|sanitizeText|maxLength:15',
            $type . 'name'      => 'nullable|sanitizeText|maxLength:255',
            $type . 'address_1' => 'required|sanitizeText',
            $type . 'address_2' => 'nullable|sanitizeText',
            $type . 'city'      => 'required|sanitizeText|maxLength:255',
        ]);
    }

    /**
     * @return array
     */
    public function messages()
    {
        $type = $this->get('type') . '_';

        return [
            $type . 'address_1.required' => esc_html__('Address field is required.', 'fluent-cart'),
            $type . 'label.max'          => esc_html__('Label may not be greater than 15 characters.', 'fluent-cart'),
            $type . 'city.required'      => esc_html__('City field is required.', 'fluent-cart'),
            $type . 'state.required'     => esc_html__('State field is required.', 'fluent-cart'),
            $type . 'postcode.required'  => esc_html__('Postcode field is required.', 'fluent-cart'),
            $type . 'country.required'   => esc_html__('Country field is required.', 'fluent-cart'),
            $type . 'label.required'     => esc_html__('Label field is required.', 'fluent-cart')
        ];
    }

    /**
     * @return array
     */
    public function sanitize()
    {
        $type = $this->get('type') . '_';

        return [
            'type'              => 'sanitize_text_field',
            $type . 'status'    => 'sanitize_text_field',
            $type . 'label'     => 'sanitize_text_field',
            $type . 'name'      => 'sanitize_text_field',
            $type . 'address_1' => 'sanitize_text_field',
            $type . 'address_2' => 'sanitize_text_field',
            $type . 'city'      => 'sanitize_text_field',
            $type . 'state'     => 'sanitize_text_field',
            $type . 'phone'     => 'sanitize_text_field',
            $type . 'postcode'  => 'sanitize_text_field',
            $type . 'country'   => 'sanitize_text_field',
            $type . 'email'     => function ($value) {
                if(empty($value)) {
                    return '';
                }

                return sanitize_email($value);
            },
        ];
    }
}
