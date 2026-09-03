<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\App\App;
use FluentCart\App\Services\Renderer\CheckoutFieldsSchema;
use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class CustomerAddressRequest extends RequestGuard
{
    public function beforeValidation()
    {
        $data = $this->all();
        $data['status'] = Arr::get($data, 'status', 'active');
        $data['is_primary'] = Arr::get($data, 'is_primary', 0);
        return $data;
    }

    /**
     * @return array
     */
    public function rules()
    {
        $validationRules = App::localization()->getValidationRule($this->all(), null, [
            'country' => 'required|sanitizeText'
        ]);

        $rules = array_merge($validationRules, [
            'label'      => 'nullable|sanitizeText|maxLength:15',
            'name'       => 'required|sanitizeText|maxLength:255',
            'email'      => 'required|sanitizeText|email|maxLength:255',
            'address_1'  => 'required|sanitizeText',
            'address_2'  => 'nullable|sanitizeText',
            'city'       => 'required|sanitizeText|maxLength:255',
            'is_primary' => 'nullable|numeric',
            'company_name' => 'nullable|sanitizeText|maxLength:255',
            'vat_number' => 'nullable|sanitizeText|maxLength:255',
            'legal_registration_id' => 'nullable|sanitizeText|maxLength:255',
        ]);

        if (Arr::get($this->all(), 'type') === 'billing') {
            if (CheckoutFieldsSchema::isCompanyNameEnabled() && CheckoutFieldsSchema::isCompanyNameRequired()) {
                $rules['company_name'] = 'required|sanitizeText|maxLength:255';
            }

            if (CheckoutFieldsSchema::isVatNumberEnabled() && CheckoutFieldsSchema::isVatNumberRequired()) {
                $rules['vat_number'] = 'required|sanitizeText|maxLength:255';
            }

            if (CheckoutFieldsSchema::isLegalRegistrationIdEnabled() && CheckoutFieldsSchema::isLegalRegistrationIdRequired()) {
                $rules['legal_registration_id'] = 'required|sanitizeText|maxLength:255';
            }
        }

        return $rules;
    }

    /**
     * @return array
     */
    public function messages()
    {
        return [
            'name.required'      => esc_html__('Name field is required.', 'fluent-cart'),
            'email.required'     => esc_html__('Email field is required.', 'fluent-cart'),
            'email.email'        => esc_html__('Email must be a valid email address.', 'fluent-cart'),
            'address_1.required' => esc_html__('Street Address field is required.', 'fluent-cart'),
            'city.required'      => esc_html__('City field is required.', 'fluent-cart'),
            'postcode.required'  => esc_html__('Postcode field is required.', 'fluent-cart'),
            'country.required'   => esc_html__('Country field is required.', 'fluent-cart'),
            'state.required'     => esc_html__('State field is required.', 'fluent-cart'),
            'label.max'          => esc_html__('Label may not be greater than 15 characters.', 'fluent-cart'),
            'company_name.required' => esc_html__('Company Name field is required.', 'fluent-cart'),
            'vat_number.required' => esc_html__('VAT/GST/Tax Number field is required.', 'fluent-cart'),
            'legal_registration_id.required' => esc_html__('Legal Registration ID field is required.', 'fluent-cart'),
        ];
    }

    /**
     * @return array
     */
    public function sanitize()
    {
        return [
            'label'      => 'sanitize_text_field',
            'type'       => 'sanitize_text_field',
            'status'     => 'sanitize_text_field',
            'name'       => 'sanitize_text_field',
            'address_1'  => 'sanitize_text_field',
            'address_2'  => 'sanitize_text_field',
            'city'       => 'sanitize_text_field',
            'state'      => 'sanitize_text_field',
            'phone'      => 'sanitize_text_field',
            'postcode'   => 'sanitize_text_field',
            'country'    => 'sanitize_text_field',
            'email'      => function ($value) {
                if(empty($value)) {
                    return '';
                }

                return sanitize_email($value);
            },
            'is_primary' => 'intval',
            'company_name' => 'sanitize_text_field',
            'vat_number' => 'sanitize_text_field',
            'legal_registration_id' => 'sanitize_text_field',
        ];
    }
}
