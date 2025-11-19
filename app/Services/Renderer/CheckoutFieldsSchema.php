<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\Resource\FrontendResource\CustomerAddressResource;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\Framework\Support\Arr;

class CheckoutFieldsSchema
{
    public static function getNameEmailFieldsSchema($cart = null, $scope = 'render')
    {
        $customerName = '';
        $customerEmail = '';
        $companyName = '';
        if ($cart && $scope === 'render') {
            $customerName = trim($cart->first_name . ' ' . $cart->last_name);
            $customerEmail = $cart->email;
            if (is_user_logged_in()) {
                $user = wp_get_current_user();
                $customerName = trim($user->first_name . ' ' . $user->last_name);
                if (empty($customerName)) {
                    $customerName = $user->display_name;
                }
                $customerEmail = $user->user_email;
            }

            $companyName = Arr::get($cart->checkout_data, 'form_data.billing_company_name', '');
        }

        $nameFields = [
            'billing_full_name' => [
                'id'           => 'billing_full_name',
                'name'         => 'billing_full_name',
                'type'         => 'text',
                'data-type'    => 'text',
                'label'        => '',
                'is_core'      => 'yes',
                'required'     => 'yes',
                'autocomplete' => 'given-name',
                'value'        => $customerName,
                'placeholder'  => esc_attr__('Full Name', 'fluent-cart'),
                'aria-label'   => esc_attr__('Your Full Name', 'fluent-cart'),
            ],
            'billing_email'     => [
                'id'           => 'billing_email',
                'name'         => 'billing_email',
                'type'         => 'text',
                'data-type'    => 'email',
                'required'     => 'yes',
                'label'        => '',
                'is_core'      => 'yes',
                'autocomplete' => 'email',
                'value'        => $customerEmail,
                'disabled'     => is_user_logged_in(),
                'placeholder'  => esc_attr__('Email address *', 'fluent-cart'),
                'aria-label'   => esc_attr__('Email address', 'fluent-cart'),
            ]
        ];

        $fieldsSchema = self::getFieldsSettings();

        $companyField = Arr::get($fieldsSchema, 'basic_info.company_name', []);

        if ($companyField && Arr::get($companyField, 'enabled', 'no') === 'yes') {

            $isRequired = Arr::get($companyField, 'required', 'no') === 'yes' ? 'yes' : '';

            $nameFields['billing_company_name'] = [
                'name'         => 'billing_company_name',
                'id'           => 'billing_company_name',
                'type'         => 'text',
                'data-type'    => 'text',
                'required'     => $isRequired,
                'label'        => '',
                'aria-label'   => esc_attr__('Company name', 'fluent-cart'),
                'placeholder'  => esc_attr__('Company name', 'fluent-cart') . ($isRequired ? ' *' : ''),
                'autocomplete' => 'organization',
                'value'        => $companyName,
            ];
        }

        $nameFields = apply_filters('fluent_cart/checkout_page_name_fields_schema', $nameFields, [
            'cart'  => $cart,
            'scope' => $scope
        ]);

        return [
            'type'   => 'section',
            'title'  => '',
            'id'     => 'billing_personal_information_section',
            'fields' => $nameFields,
        ];
    }

    public static function getAddressBaseFields($config, $scope = 'render')
    {

        $configDefaults = [
            'type'          => 'billing', // billing or shipping
            'product_type'  => 'digital', // digital or physical
            'with_shipping' => false, // only for billing type, if true and type is billing, then shipping fields will be returned without full_name field
            'full_name'     => '',
            'country'       => '',
            'address_1'     => '',
            'address_2'     => '',
            'state'         => '',
            'city'          => '',
            'postcode'      => '',
            'company_name'  => '',
            'phone'         => '',
        ];

        $config = wp_parse_args($config, $configDefaults);

        $selectedCountry = Arr::get($config, 'country');
        if (empty($selectedCountry)) {
            $HTTP_CF_IP_COUNTRY = Arr::get( App::request()->server(), 'HTTP_CF_IPCOUNTRY');
            $selectedCountry = $HTTP_CF_IP_COUNTRY ?? $selectedCountry;
        }

        $states = [];
        if (empty($config['state']) || empty($selectedCountry)) {
            $states = [
                [
                    'name'  => __('Select an option', 'fluent-cart'),
                    'value' => ''
                ]
            ];
        }

        $addressLocale = [];
        if (!empty($selectedCountry)) {
            $states = array_merge($states, LocalizationManager::getInstance()->statesOptions($selectedCountry));
            $addressLocale = LocalizationManager::getInstance()->addressLocales($selectedCountry);
        }

        $stateLabel = Arr::get($addressLocale, 'state.label', __('State', 'fluent-cart'));
        $countries = [
            [
                'name'  => __('Select a Country', 'fluent-cart'),
                'value' => ''
            ]
        ];

        $countries = array_merge($countries, Helper::getCountryList());

        $type = Arr::get($config, 'type', 'billing'); // billing or shipping

        if (!in_array($type, ['billing', 'shipping'])) {
            $type = 'billing';
        }


        if (empty($states) && !Arr::get($addressLocale, 'state.hidden')) {
            $stateInput = [
                'name'         => $type . '_state',
                'id'           => $type . '_state',
                'type'         => 'text',
                'data-type'    => 'text',
                'label'        => '',
                'required'     => 'yes',
                'autocomplete' => 'address-level2',
                'placeholder'  => $stateLabel,
                'aria-label'   => $stateLabel,
                'value'        => $config['state'],
                'wrapper_atts' => [
                    'id' => $type . '_state_wrapper'
                ]
            ];
        } else {
            $stateInput = [
                'name'         => $type . '_state',
                'id'           => $type . '_state',
                'type'         => 'select',
                'data-type'    => 'select',
                'label'        => '',
                'options'      => $states,
                'required'     => 'yes',
                'autocomplete' => 'address-level2',
                'placeholder'  => $stateLabel,
                'aria-label'   => $stateLabel,
                'value'        => $config['state'],
                'wrapper_atts' => [
                    'id' => $type . '_state_wrapper'
                ]
            ];
        }

        $fields = [
            'full_name'    => [
                'id'           => $type . '_full_name',
                'name'         => $type . '_full_name',
                'type'         => 'text',
                'data-type'    => 'text',
                'label'        => '',
                'is_core'      => 'yes',
                'required'     => 'yes',
                'autocomplete' => 'given-name',
                'value'        => $config['full_name'],
                'placeholder'  => esc_attr__('Full Name', 'fluent-cart'),
                'aria-label'   => esc_attr__('Your Full Name', 'fluent-cart'),
            ],
            'country'      => [
                'name'         => $type . '_country',
                'id'           => $type . '_country',
                'type'         => 'select',
                'options'      => $countries,
                'data-type'    => 'text',
                'label'        => '',
                'aria-label'   => esc_attr__('Country / Region', 'fluent-cart'),
                'required'     => 'yes',
                'autocomplete' => 'country',
                'placeholder'  => esc_attr__('Country / Region', 'fluent-cart'),
                'value'        => $selectedCountry,
            ],
            'address_1'    => [
                'name'         => $type . '_address_1',
                'id'           => $type . '_address_1',
                'type'         => 'text',
                'data-type'    => 'text',
                'label'        => '',
                'aria-label'   => esc_attr__('Street Address', 'fluent-cart'),
                /* translators: use local order of street name and house number. */
                'placeholder'  => esc_attr__('Street Address', 'fluent-cart'),
                'required'     => 'yes',
                'autocomplete' => 'address-line1',
                'value'        => $config['address_1'],
            ],
            'address_2'    => [
                'name'         => $type . '_address_2',
                'id'           => $type . '_address_2',
                'type'         => 'text',
                'data-type'    => 'text',
                'label'        => '',
                'aria-label'   => esc_attr__('Apt, suite, unit', 'fluent-cart'),
                'label_class'  => array(''),
                'placeholder'  => esc_attr__('Apt, suite, unit', 'fluent-cart'),
                'autocomplete' => 'address-line2',
                'value'        => $config['address_2'],
            ],
            'state'        => $stateInput,
            'city'         => [
                'name'         => $type . '_city',
                'id'           => $type . '_city',
                'type'         => 'text',
                'data-type'    => 'text',
                'label'        => '',
                'aria-label'   => esc_attr__('Town / City', 'fluent-cart'),
                'required'     => 'yes',
                'autocomplete' => 'address-level2',
                'placeholder'  => esc_attr__('Town / City', 'fluent-cart'),
                'value'        => $config['city'],
            ],
            'postcode'     => [
                'name'         => $type . '_postcode',
                'id'           => $type . '_postcode',
                'type'         => 'text',
                'data-type'    => 'text',
                'label'        => '',
                'aria-label'   => esc_attr__('Postal / ZIP Code', 'fluent-cart'),
                'required'     => 'yes',
                'validate'     => array('postcode'),
                'autocomplete' => 'postal-code',
                'placeholder'  => esc_attr__('Postcode / ZIP', 'fluent-cart'),
                'value'        => $config['postcode'],
            ],
            'phone'        => [
                'name'         => $type . '_phone',
                'id'           => $type . '_phone',
                'type'         => 'tel',
                'data-type'    => 'text',
                'label'        => '',
                'aria-label'   => esc_attr__('Phone number', 'fluent-cart'),
                'placeholder'  => esc_attr__('Phone number', 'fluent-cart'),
                'autocomplete' => 'tel',
                'value'        => $config['phone'],
            ],
            'company_name' => [
                'name'         => $type . '_company_name',
                'id'           => $type . '_company_name',
                'type'         => 'text',
                'data-type'    => 'text',
                'label'        => '',
                'aria-label'   => esc_attr__('Company name', 'fluent-cart'),
                'placeholder'  => esc_attr__('Company name', 'fluent-cart'),
                'autocomplete' => 'organization',
                'value'        => $config['company_name'],
            ],
        ];

        if ($type === 'billing') {
            unset($fields['full_name']);
        }

        $requirementsFields = [];
        if ($scope === 'render') {
            $requirementsFields = self::getCheckoutFieldsRequirements($type, $config['product_type'], $config['with_shipping']);

            $formattedFields = [];
            foreach ($fields as $key => $field) {
                $requirement = Arr::get($requirementsFields, $key, '');
                if (!$requirement) {
                    continue;
                }
                if ($requirement === 'required') {
                    $field['required'] = 'yes';
                    $field['placeholder'] = $field['placeholder'] . ' *';
                } else {
                    unset($field['required']);
                }
                $formattedFields[$key] = $field;
            }
            $fields = $formattedFields;
        }

        return apply_filters('fluent_cart/fields/address_base_fields', $fields, [
            'config'       => $config,
            'scope'        => $scope,
            'requirements' => $requirementsFields
        ]);
    }


    private static function getRequirementType($config, $key)
    {
        $enabled = Arr::get($config, $key . '.enabled', 'no') === 'yes';
        if (!$enabled) {
            return '';
        }

        if (Arr::get($config, $key . '.required', 'no') === 'yes') {
            return 'required';
        }

        return 'optional';
    }

    public static function getCheckoutFieldsRequirements($addressType = 'billing', $productType = 'digital', $withShipping = false) // digital or physical
    {
        $fieldsConfig = self::getFieldsSettings();


        $shippingConfig = Arr::get($fieldsConfig, 'shipping_address', []);
        $billingConfig = Arr::get($fieldsConfig, 'billing_address', []);

        $dataConfig = [
            'full_name'    => [
                'billing'  => '',
                'shipping' => 'required'
            ],
            'country'      => [
                'billing'  => self::getRequirementType($billingConfig, 'country'),
                'shipping' => self::getRequirementType($shippingConfig, 'country')
            ],
            'address_1'    => [
                'billing'  => self::getRequirementType($billingConfig, 'address_1'),
                'shipping' => self::getRequirementType($shippingConfig, 'address_1')
            ],
            'address_2'    => [
                'billing'  => self::getRequirementType($billingConfig, 'address_2'),
                'shipping' => self::getRequirementType($shippingConfig, 'address_2')
            ],
            'state'        => [
                'billing'  => self::getRequirementType($billingConfig, 'state'),
                'shipping' => self::getRequirementType($shippingConfig, 'state')
            ],
            'city'         => [
                'billing'  => self::getRequirementType($billingConfig, 'city'),
                'shipping' => self::getRequirementType($shippingConfig, 'city')
            ],
            'postcode'     => [
                'billing'  => self::getRequirementType($billingConfig, 'postcode'),
                'shipping' => self::getRequirementType($shippingConfig, 'postcode')
            ],
            'company_name' => [
                'billing'  => self::getRequirementType($billingConfig, 'company_name'),
                'shipping' => self::getRequirementType($shippingConfig, 'company_name')
            ],
            'phone'        => [
                'billing'  => self::getRequirementType($billingConfig, 'phone'),
                'shipping' => self::getRequirementType($shippingConfig, 'phone')
            ]
        ];


        if ($withShipping && $addressType == 'billing') {
            // now we have to merge the shipping fields requirements with billing fields requirements
            foreach ($dataConfig as $key => $config) {
                if ($config[$addressType] === 'required') {
                    $dataConfig[$key][$addressType] = 'required';
                } elseif ($config[$addressType] === 'optional' && $dataConfig[$key][$addressType] !== 'required') {
                    $dataConfig[$key][$addressType] = 'optional';
                } else {
                    $dataConfig[$key][$addressType] = '';
                }
            }
        }

        $formattedReturn = [];
        foreach ($dataConfig as $key => $config) {
            if (Arr::get($config, $addressType)) {
                $formattedReturn[$key] = $config[$addressType];
            }
        }

        return $formattedReturn;
    }

    public static function getFieldsSchemaConfig()
    {
        return [
            'basic_info'       => [
                'full_name'    => [
                    'label'     => __('Full Name', 'fluent-cart'),
                    'type'      => 'text',
                    'can_alter' => 'no',
                ],
                'email'        => [
                    'label'     => __('Email Address', 'fluent-cart'),
                    'type'      => 'email',
                    'can_alter' => 'no',
                ],
                'company_name' => [
                    'label'     => __('Company', 'fluent-cart'),
                    'type'      => 'text',
                    'can_alter' => 'yes',
                ]
            ],
            'billing_address'  => [
                'country'   => [
                    'label'     => __('Country', 'fluent-cart'),
                    'type'      => 'text',
                    'can_alter' => 'yes',
                ],
                'state'     => [
                    'label'     => __('State', 'fluent-cart'),
                    'type'      => 'text',
                    'can_alter' => 'yes',
                ],
                'address_1' => [
                    'label'     => __('Street Address', 'fluent-cart'),
                    'type'      => 'text',
                    'can_alter' => 'yes',
                ],
                'address_2' => [
                    'label'     => __('Apt, suite, unit', 'fluent-cart'),
                    'type'      => 'text',
                    'can_alter' => 'yes',
                ],
                'city'      => [
                    'label'     => __('City', 'fluent-cart'),
                    'type'      => 'text',
                    'can_alter' => 'yes',
                ],
                'postcode'  => [
                    'label'     => __('Post Code', 'fluent-cart'),
                    'type'      => 'text',
                    'can_alter' => 'yes',
                ],
                'phone'     => [
                    'label'     => __('Phone', 'fluent-cart'),
                    'type'      => 'text',
                    'can_alter' => 'yes',
                ]
            ],
            'shipping_address' => [
                'full_name' => [
                    'label'     => __('Full Name', 'fluent-cart'),
                    'type'      => 'text',
                    'can_alter' => 'no',
                ],
                'country'   => [
                    'label'     => __('Country', 'fluent-cart'),
                    'type'      => 'text',
                    'can_alter' => 'yes',
                ],
                'state'     => [
                    'label'     => __('State', 'fluent-cart'),
                    'type'      => 'text',
                    'can_alter' => 'yes',
                ],
                'address_1' => [
                    'label'     => __('Street Address', 'fluent-cart'),
                    'type'      => 'text',
                    'can_alter' => 'yes',
                ],
                'address_2' => [
                    'label'     => __('Apt, suite, unit', 'fluent-cart'),
                    'type'      => 'text',
                    'can_alter' => 'yes',
                ],
                'city'      => [
                    'label'     => __('City', 'fluent-cart'),
                    'type'      => 'text',
                    'can_alter' => 'yes',
                ],
                'postcode'  => [
                    'label'     => __('Post Code', 'fluent-cart'),
                    'type'      => 'text',
                    'can_alter' => 'yes',
                ],
                'phone'     => [
                    'label'     => __('Phone', 'fluent-cart'),
                    'type'      => 'text',
                    'can_alter' => 'yes',
                ]
            ],
            'agree_terms'      => [
                'label'     => __('Agree Terms and Conditions', 'fluent-cart'),
                'type'      => 'checkbox',
                'can_alter' => 'yes',
            ]
        ];
    }

    public static function getFieldsSettings()
    {
        $defaults = [
            'basic_info'       => [
                'full_name'    => [
                    'required' => 'yes',
                    'enabled'  => 'yes'
                ],
                'email'        => [
                    'required' => 'yes',
                    'enabled'  => 'yes'
                ],
                'company_name' => [
                    'required' => 'no',
                    'enabled'  => 'no'
                ]
            ],
            'billing_address'  => [
                'country'   => [
                    'required' => 'yes',
                    'enabled'  => 'yes'
                ],
                'state'     => [
                    'required' => 'yes',
                    'enabled'  => 'yes'
                ],
                'address_1' => [
                    'required' => 'yes',
                    'enabled'  => 'yes'
                ],
                'address_2' => [
                    'required' => 'no',
                    'enabled'  => 'yes'
                ],
                'city'      => [
                    'required' => 'yes',
                    'enabled'  => 'yes'
                ],
                'postcode'  => [
                    'required' => 'yes',
                    'enabled'  => 'yes'
                ],
                'phone'     => [
                    'required' => 'no',
                    'enabled'  => 'no'
                ]
            ],
            'shipping_address' => [
                'full_name' => [
                    'required' => 'yes',
                    'enabled'  => 'yes',
                ],
                'country'   => [
                    'required' => 'yes',
                    'enabled'  => 'yes'
                ],
                'state'     => [
                    'required' => 'yes',
                    'enabled'  => 'yes'
                ],
                'address_1' => [
                    'required' => 'yes',
                    'enabled'  => 'yes'
                ],
                'address_2' => [
                    'required' => 'no',
                    'enabled'  => 'yes'
                ],
                'city'      => [
                    'required' => 'yes',
                    'enabled'  => 'yes'
                ],
                'postcode'  => [
                    'required' => 'yes',
                    'enabled'  => 'yes'
                ],
                'phone'     => [
                    'required' => 'no',
                    'enabled'  => 'no'
                ]
            ],
            'agree_terms'      => [
                'required' => 'no',
                'enabled'  => 'no',
                'text'     => __('I agree to the terms and conditions.', 'fluent-cart')
            ]
        ];

        $savedConfig = fluent_cart_get_option('_fc_checkout_fields', []);

        if (!$savedConfig || !is_array($savedConfig)) {
            return $defaults;
        }

        foreach ($defaults as $key => $default) {
            $defaults[$key] = wp_parse_args(Arr::get($savedConfig, $key, []), $default);
        }

        return $defaults;
    }

    public static function isTermsRequired(): bool
    {
        $fieldSettings = self::getFieldsSettings();
        return Arr::get($fieldSettings, 'agree_terms.enabled') === 'yes'
            && Arr::get($fieldSettings, 'agree_terms.required') === 'yes';
    }

    public static function isTermsVisible(): bool
    {
        $fieldSettings = self::getFieldsSettings();
        return Arr::get($fieldSettings, 'agree_terms.enabled') === 'yes';
    }

    public static function getTermsText()
    {
        $text = Arr::get(self::getFieldsSettings(), 'agree_terms.text', '');

        if (!$text) {
            $text = __('I agree to the terms and conditions.', 'fluent-cart');
        }

        return $text;
    }

}
