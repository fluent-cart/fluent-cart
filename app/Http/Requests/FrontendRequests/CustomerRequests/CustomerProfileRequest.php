<?php

namespace FluentCart\App\Http\Requests\FrontendRequests\CustomerRequests;

use FluentCart\App\Services\Renderer\CheckoutFieldsSchema;
use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class CustomerProfileRequest extends RequestGuard
{

    public function beforeValidation()
    {
        $data = $this->all();
        if (empty(Arr::get($data, 'status'))) {
            $data['status'] = 'active';
        }
        if (empty(Arr::get($data, 'is_primary'))) {
            $data['is_primary'] = 0;
        }
        return $data;
    }

    /**
     * @return array
     */
    public function rules()
    {
        $rules = [
            'label'                => 'nullable|sanitizeText|maxLength:15',
            'name'                 => 'required|sanitizeText|maxLength:255',
            'address_1'            => 'nullable|sanitizeTextArea',
            'address_2'            => 'nullable|sanitizeTextArea',
            'city'                 => 'nullable|sanitizeText|maxLength:255',
            'state'                => 'nullable|sanitizeText|maxLength:255',
            'postcode'             => 'required|sanitizeText',
            'country'              => 'required|sanitizeText',
            'first_name'           => 'sanitizeText|maxLength:255',
            'last_name'            => 'sanitizeText|maxLength:255',
            'email'                => 'required|email',
            'current_password'     => 'sanitizeText|nullable',
            'new_password'         => 'sanitizeText|nullable',
            'confirm_new_password' => 'sanitizeText|nullable',
            'company_name'         => 'nullable|sanitizeText|maxLength:255',
            'vat_number'           => 'nullable|sanitizeText|maxLength:255',
            'legal_registration_id' => 'nullable|sanitizeText|maxLength:255',
        ];

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
            'name.required' => esc_html__('Name field is required.', 'fluent-cart'),
            'address_1.required' => esc_html__('Street Address is required.', 'fluent-cart'),
            'city.required'      => esc_html__('City field is required.', 'fluent-cart'),
            'postcode.required'  => esc_html__('Postcode field is required.', 'fluent-cart'),
            'country.required'   => esc_html__('Country field is required.', 'fluent-cart'),
            'email.required'     => esc_html__('Email field is required.', 'fluent-cart'),
            'company_name.required' => esc_html__('Company Name field is required.', 'fluent-cart'),
            'vat_number.required' => esc_html__('VAT/GST/Tax Number field is required.', 'fluent-cart'),
            'legal_registration_id.required' => esc_html__('Legal Registration ID field is required.', 'fluent-cart')
        ];
    }

    /**
     * @return array
     */
    public function sanitize()
    {
        return [
            'label'                => 'sanitize_text_field',
            'type'                 => 'sanitize_text_field',
            'name'                 => 'sanitize_text_field',
            'first_name'           => 'sanitize_text_field',
            'last_name'            => 'sanitize_text_field',
            'new_password'         => '',
            'current_password'     => '',
            'confirm_new_password' => '',
            'address_1'            => 'sanitize_text_field',
            'address_2'            => 'sanitize_text_field',
            'city'                 => 'sanitize_text_field',
            'state'                => 'sanitize_text_field',
            'phone'                => 'sanitize_text_field',
            'postcode'             => 'sanitize_text_field',
            'country'              => 'sanitize_text_field',
            'email'                => function ($value) {
                if(empty($value)) {
                    return '';
                }

                return sanitize_email($value);
            },
            'status'               => 'sanitize_text_field',
            'userId'               => 'sanitize_text_field',
            'customerId'           => 'sanitize_text_field',
            'company_name'         => 'sanitize_text_field',
            'vat_number'           => 'sanitize_text_field',
            'legal_registration_id' => 'sanitize_text_field',

            'address.type'       => 'sanitize_text_field',
            'address.name'       => 'sanitize_text_field',
            'address.address_1'  => 'sanitize_text_field',
            'address.address_2'  => 'sanitize_text_field',
            'address.city'       => 'sanitize_text_field',
            'address.state'      => 'sanitize_text_field',
            'address.phone'      => 'sanitize_text_field',
            'address.postcode'   => 'sanitize_text_field',
            'address.country'    => 'sanitize_text_field',
            'address.email'      => function ($value) {
                if(empty($value)) {
                    return '';
                }

                return sanitize_email($value);
            },
            'address.is_primary' => 'sanitize_text_field',
            'address.status'     => 'sanitize_text_field',
            'address.company_name' => 'sanitize_text_field',
            'address.vat_number' => 'sanitize_text_field',
            'address.legal_registration_id' => 'sanitize_text_field',
        ];
    }
}
