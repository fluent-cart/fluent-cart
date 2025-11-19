<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\App\App;
use FluentCart\App\Models\Customer;
use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class CustomerRequest extends RequestGuard
{

    public function beforeValidation()
    {
        $data = $this->all();
        $data['notes'] = Arr::get($data, 'notes', '');
        return $data;
    }

    /**
     * @return array
     */
    public function rules()
    {
        $validationRules = [];

        $customerId = intval(Arr::get($this->all(), 'id', 0));

        return array_merge($validationRules, [
            'full_name' => 'required|sanitizeText|maxLength:255',
            'city'      => 'nullable|sanitizeText',
            'email'     => ['required', 'sanitizeText', 'email', 'maxLength:255', 'exist' =>function ($attribute, $value) use ($customerId) {

                if (empty($value)) {
                    return null;
                }
                $value = sanitize_email($value);
                $customer = Customer::query()
                    ->when($customerId, function ($query) use ($customerId) {
                        $query->where('id', '!=', $customerId);
                    })
                    ->where('email', $value)->first();
                if ($customer) {
                    return __('Email already exists.', 'fluent-cart');
                }
                return null;
            }],
        ]);
    }

    /**
     * @return array
     */
    public function messages()
    {
        return [
            'full_name.required' => esc_html__('Full Name field is required.', 'fluent-cart'),
            'email.required'     => esc_html__('Email field is required.', 'fluent-cart'),
            'email.email'        => esc_html__('Email must be a valid email address.', 'fluent-cart'),
        ];
    }

    /**
     * @return array
     */
    public function sanitize()
    {
        return [
            'user_id'       => 'intval',
            'email'         => function ($value) {
                if (empty($value)) {
                    return '';
                }

                return sanitize_email($value);
            },
            'full_name'     => 'sanitize_text_field',
            'status'        => 'sanitize_text_field',
            'aov'           => 'sanitize_text_field',
            'notes'         => 'sanitize_text_field',
            'country'       => 'sanitize_text_field',
            'city'          => 'sanitize_text_field',
            'state'         => 'sanitize_text_field',
            'postcode'      => 'sanitize_text_field',
            'username'      => 'sanitize_text_field',
            'user_nicename' => 'sanitize_text_field',
            'display_name'  => 'sanitize_text_field',
            'user_url'      => function ($value) {
                if (empty($value)) {
                    return '';
                }

                return sanitize_url($value);
            },
            'wp_user'       => 'sanitize_text_field',
        ];
    }
}
