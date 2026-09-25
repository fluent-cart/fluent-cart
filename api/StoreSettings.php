<?php

namespace FluentCart\Api;

use FluentCart\App\App;
use FluentCart\App\Vite;
use FluentCart\App\CPT\Pages;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Services\OrderService;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\ThemePalette;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\StoreManagedRenewal\Services\RenewalService;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionManagementMode;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\ArrayableInterface;
use FluentCart\Framework\Support\Str;
use FluentCart\App\Services\Permission\PermissionManager;

class StoreSettings implements ArrayableInterface
{
    const CACHE_KEY = 'store_settings';
    const CACHE_GROUP = 'fluentcart';

    /**
     * @var string
     *
     * Store settings option name
     */
    protected string $optionKey = 'fluent_cart_store_settings';

    /**
     * @var array key value pair
     *
     * Store settings parsed from fields
     */
    protected array $storeSettings;

    protected static $cachedStoreSettings = null;

    public static function clearCache(): void
    {
        self::$cachedStoreSettings = null;
        wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);
    }

    public function __construct()
    {
        if (self::$cachedStoreSettings !== null) {
            $this->storeSettings = self::$cachedStoreSettings;
            return;
        }
        $defaultSettings = $this->getDefaultSettings();
        $storeSettings = get_option($this->optionKey, []);
        $settings = wp_parse_args($storeSettings, $defaultSettings);
        $this->storeSettings = $settings;
        self::$cachedStoreSettings = $this->storeSettings;
    }

    protected function getDefaultSettings(): array
    {
        $defaultSettings = [
            'store_name'                           => get_bloginfo('name'),
            'company_name'                         => '',
            'legal_registration_id'                => '',
            'seller_vat_id'                        => '',
            'seller_tax_id'                        => '',
            'note_for_user_account_creation'       => __('An user account will be created', 'fluent-cart'),
            'checkout_button_text'                 => __('Checkout', 'fluent-cart'),
            'view_cart_button_text'                => __('View Cart', 'fluent-cart'),
            'cart_button_text'                     => __('Add To Cart', 'fluent-cart'),
            'popup_button_text'                    => __('View Product', 'fluent-cart'),
            'out_of_stock_button_text'             => __('Not Available', 'fluent-cart'),
            'currency_position'                    => 'before',
            // 'thousand_separator'                   => 'comma',
            'decimal_separator'                    => 'dot',
            'checkout_method_style'                => 'logo',
            'enable_modal_checkout'                => 'no',
            'require_logged_in'                    => 'no',
            'show_cart_icon_in_nav'                => 'no',
            'show_cart_icon_in_body'               => 'yes',
            'additional_address_field'             => 'yes',
            'hide_coupon_field'                    => 'no',
            'user_account_creation_mode'           => 'all',
            'auto_login_after_account_creation'    => 'no',
            'checkout_page_id'                     => '',
            'custom_payment_page_id'               => '',
            'registration_page_id'                 => '',
            'login_page_id'                        => '',
            'cart_page_id'                         => '',
            'receipt_page_id'                      => '',
            'shop_page_id'                         => '',
            'customer_profile_page_id'             => '',
            'customer_profile_page_slug'           => '',
            'currency'                             => 'USD',
            'store_address1'                       => '',
            'store_address2'                       => '',
            'store_city'                           => '',
            'store_country'                        => '',
            'store_postcode'                       => '',
            'store_state'                          => '',
            'show_relevant_product_in_single_page' => 'yes',
            'show_relevant_product_in_modal'       => '',
            'order_mode'                           => 'test',
            'subscription_mode_guard'              => 'yes',
            'variation_view'                       => 'both',
            'variation_columns'                    => 'masonry',
            'enable_early_payment_for_installment' => 'yes',
            'subscription_management_mode'         => 'gateway_managed',
            'subscription_system_charge'           => 'no',
            'modules_settings'                     => [],
            'min_receipt_number'                   => '1',
            'inv_prefix'                           => 'INV-',
            'weight_unit'                          => 'kg',
            'dimension_unit'                       => 'cm',
            'appearance_source'                    => ColorPalette::SOURCE_DEFAULT,
            'appearance_colors'                    => [],
            // 'wordpress', not 'fluent_cart': the FluentCart patterns are
            // literals ('M j, Y'), and a literal renders a half-translated date
            // on a localized store -- a German month in English field order,
            // which a German reader misreads as day-first. Following
            // Settings > General is the only source that is correct in every
            // locale, so it is what a store gets until it chooses otherwise.
            'date_time_format_source'              => 'wordpress',
            'timezone_source'                      => 'fluent_cart'
        ];

        return apply_filters('fluent_cart/store_settings/values', $defaultSettings, []);
    }

    /**
     * @return array
     *
     * Get all store settings fields
     * @hook to use apply_filters("fluent_cart/store_setting_fields", $fields)
     */
    public function fields($params = []): array
    {

        $pages = Pages::getPages('');
        $previewLinks = [
            'shop_page_id'             => $this->getShopPage(),
            'customer_profile_page_id' => $this->getCustomerProfilePage(),
            'cart_page_id'             => $this->getCartPage(),
            'checkout_page_id'         => $this->getCheckoutPage(),
            'receipt_page_id'          => $this->getReceiptPage()
        ];
        $isProActive = App::isProActive();
        $proFeatureIcon = Vite::getAssetUrl('images/crown.svg');

        // Read-only schedule for store-managed subscription renewals. Pulled live
        // from the same map the scheduler uses, so it stays accurate under the
        // fluent_cart/renewal/advance_creation_days filter. No editable knob — the timing is
        // deliberately built-in; developers tune it via that filter.
        $invoiceScheduleMap = RenewalService::getAdvanceCreationDaysMap();
        $invoiceScheduleLabels = [
            'daily'       => __('Daily', 'fluent-cart'),
            'weekly'      => __('Weekly', 'fluent-cart'),
            'monthly'     => __('Monthly', 'fluent-cart'),
            'quarterly'   => __('Quarterly', 'fluent-cart'),
            'half_yearly' => __('Half-yearly', 'fluent-cart'),
            'yearly'      => __('Yearly', 'fluent-cart'),
        ];
        // Structured renewal-order schedule for the SubscriptionModeManager
        // component (status card + guarded edit dialog).
        $invoiceScheduleList = [];
        foreach ($invoiceScheduleLabels as $invoiceScheduleKey => $invoiceScheduleLabel) {
            if (!isset($invoiceScheduleMap[$invoiceScheduleKey])) {
                continue;
            }
            $invoiceScheduleDays = (int) $invoiceScheduleMap[$invoiceScheduleKey];
            $invoiceScheduleList[] = [
                'label' => $invoiceScheduleLabel,
                'when'  => $invoiceScheduleDays <= 0
                    ? __('on the due date', 'fluent-cart')
                    /* translators: %d: number of days before the due date */
                    : sprintf(_n('%d day before due date', '%d days before due date', $invoiceScheduleDays, 'fluent-cart'), $invoiceScheduleDays),
            ];
        }

        // Gateways declaring `system_subscription` — the only ones
        // subscription_system_charge can ever auto-charge.
        $systemChargeGateways = [];
        foreach (GatewayManager::getInstance()->all() as $systemChargeGateway) {
            if (!$systemChargeGateway->has('system_subscription') || $systemChargeGateway->isUpcoming()) {
                continue;
            }
            $systemChargeGatewayMeta = $systemChargeGateway->getMeta();
            $systemChargeGateways[] = [
                'label'  => Arr::get($systemChargeGatewayMeta, 'admin_title')
                    ?: Arr::get($systemChargeGatewayMeta, 'label')
                        ?: Arr::get($systemChargeGatewayMeta, 'title'),
                'active' => $systemChargeGateway->isEnabled(),
            ];
        }

        $fields = [
            'setting_tabs' => [
                'type'            => 'section',
                'disable_nesting' => true,
                'default_tab'     => 'store_setup',
                'hide_tab_switch' => true,
                'schema'          => [
                    'store_setup'          => [
                        'id'              => '',
                        'title'           => __('Store Setup', 'fluent-cart'),
                        'show_title'      => false,
                        'type'            => 'section',
                        'disable_nesting' => true,
                        'columns'         => [
                            'default' => 1,
                            'md'      => 1
                        ],
                        'schema'          => [
                            'name_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'      => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Store Name', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __('Enter the public name of your online store.', 'fluent-cart') . '</div>'
                                    ],
                                    "store_name" => [
                                        'wrapperClass' => 'col-span-2 flex items-center',
                                        "label"        => '',
                                        "type"         => "input",
                                        "value"        => "",
                                    ],
                                ]
                            ],

                            'hr' => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],


                            'logo_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'      => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Store Logo', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Upload your brand's logo. Recommended width: 512 pixels minimum.", 'fluent-cart') . '</div>'
                                    ],
                                    "store_logo" => [
                                        "label"    => false,
                                        "type"     => "media",
                                        "value"    => "",
                                        'multiple' => false,
                                        // if `condition_type` not specified then all condition type will be `and`

                                        // 'conditions' => [
                                        //     [
                                        //         'key' => 'store_name',
                                        //         'operator' => '==',
                                        //         'value' => [
                                        //             'accessor' => 'order_mode'
                                        //         ]
                                        //     ],
                                        // ],

                                    ],
                                ]
                            ],

                            'hr2' => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],

                            'mode_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'      => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Store Mode', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Select your store's operating mode: `Test` for setup, `Live` for real transactions.", 'fluent-cart') . '</div>'
                                    ],
                                    'order_mode' => [
                                        'wrapperClass' => 'col-span-2 flex items-center',
                                        "label"        => '',
                                        "type"         => "radio",
                                        "options"      => [
                                            [
                                                "label" => __('Live', 'fluent-cart'),
                                                "value" => 'live',
                                            ],
                                            [
                                                "label" => __('Test', 'fluent-cart'),
                                                "value" => 'test',
                                            ],
                                        ],
                                        "value"        => "live"
                                    ],
                                ]
                            ],

                            'date_time_hr' => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],

                            'address_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'              => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Store Address', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Provide your physical business address details.", 'fluent-cart') . '</div>'
                                    ],
                                    'address_input_grid' => [
                                        'wrapperClass'    => 'fct-compact-form',
                                        'type'            => 'grid',
                                        'disable_nesting' => true,
                                        'class'           => 'col-span-2',
                                        'schema'          => [
                                            'store_address_component' => [
                                                'type'         => 'component',
                                                'component'    => 'StoreSettings/AddressComponent',
                                                'wrapperClass' => 'col-span-full'
                                            ],
                                            'store_address1'          => [
                                                'type' => 'hidden',
                                            ],
                                            'store_address2'          => [
                                                'type' => 'hidden',
                                            ],
                                            'store_city'              => [
                                                'type' => 'hidden',
                                            ],
                                            'store_postcode'          => [
                                                'type' => 'hidden',
                                            ],
                                            'store_country'           => [
                                                'type' => 'hidden',
                                            ],
                                            'store_state'             => [
                                                'type' => 'hidden',
                                            ],
                                        ]
                                    ],
                                ]
                            ],


                            'settings_hr_2' => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],

                            'business_details_grid' => [
                                'type'            => 'grid',
                                'id'              => 'business_details',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'                   => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Business Details', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __('Add your legal business identity details used for store records and compliance.', 'fluent-cart') . '</div>'
                                    ],
                                    'business_details_fields' => [
                                        'type'            => 'grid',
                                        'columns'         => [
                                            'default' => 1,
                                            'md'      => 2
                                        ],
                                        'disable_nesting' => true,
                                        'wrapperClass'    => 'col-span-2',
                                        'schema'          => [
                                            'company_name'          => [
                                                'label' => __('Company Name', 'fluent-cart'),
                                                'type'  => 'input',
                                                'value' => '',
                                            ],
                                            'legal_registration_id' => [
                                                'label' => __('Legal Registration ID', 'fluent-cart'),
                                                'type'  => 'input',
                                                'value' => '',
                                            ],
                                            'seller_vat_id'         => [
                                                'label' => __('Seller VAT ID', 'fluent-cart'),
                                                'type'  => 'input',
                                                'value' => '',
                                            ],
                                            'seller_tax_id'         => [
                                                'label' => __('Seller Tax ID', 'fluent-cart'),
                                                'type'  => 'input',
                                                'value' => '',
                                            ],
                                        ]
                                    ],
                                ]
                            ],

                            'settings_hr_3' => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],

                            'currency_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'    => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Checkout Currency', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Select the primary currency for your store.", 'fluent-cart') . '</div>'
                                    ],
                                    "currency" => [
                                        'wrapperClass' => 'col-span-2 flex items-center',
                                        "label"        => '',
                                        "type"         => "select",
                                        'filterable'   => true,
                                        "options"      => CurrencySettings::getFormattedCurrencies(),
                                        "value"        => "USD"
                                    ],
                                ]
                            ],

                            'hr3' => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],

                            'decimal_separator_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'             => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Number Format', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Select the character used to separate thousands or decimals in prices.", 'fluent-cart') . '</div>'
                                    ],
                                    'decimal_separator' => [
                                        'wrapperClass' => 'col-span-2 flex items-start flex-col',
                                        "label"        => '',
                                        "type"         => "radio",
                                        "options"      => [
                                            [
                                                "label" => __('Comma & Dot (eg 10,000.00)', 'fluent-cart'),
                                                "value" => 'dot'
                                            ],
                                            [
                                                "label" => __('Dot & Comma (eg 10.000,00)', 'fluent-cart'),
                                                "value" => 'comma'
                                            ],
                                        ],
                                        "value"        => "dot"
                                    ],
                                ]
                            ],

                            'hr4' => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],

                            'currency_position_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'             => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Currency Formatting', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Select how the currency should be formatted.", 'fluent-cart') . '</div>'
                                    ],
                                    'currency_position' => [
                                        'wrapperClass' => 'col-span-2 flex items-center',
                                        "label"        => '',
                                        "type"         => "select",
                                        "options"      => [
                                            [
                                                "label" => __('Symbol before (eg: $100)', 'fluent-cart'),
                                                "value" => 'before',
                                            ],
                                            [
                                                "label" => __('Symbol after (eg: 100$)', 'fluent-cart'),
                                                "value" => 'after',
                                            ],
                                            [
                                                "label" => __('ISO before (eg: USD 100)', 'fluent-cart'),
                                                "value" => 'iso_before',
                                            ],
                                            [
                                                "label" => __('ISO after (eg: 100 USD)', 'fluent-cart'),
                                                "value" => 'iso_after',
                                            ],
                                            [
                                                "label" => __('Symbol & ISO (eg: $100 USD)', 'fluent-cart'),
                                                "value" => 'symbool_before_iso',
                                            ],
                                            [
                                                "label" => __('ISO & Symbol (eg: USD 100$)', 'fluent-cart'),
                                                "value" => 'symbool_after_iso',
                                            ],
                                            [
                                                "label" => __('ISO-Symbol (eg: USD $100)', 'fluent-cart'),
                                                "value" => 'symbool_and_iso',
                                            ],
                                        ],
                                        "value"        => "before"
                                    ],
                                ]
                            ],

                            'hr5' => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],


                            'checkout_style_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'                 => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Payment View', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Select how payment options are visually presented on checkout.", 'fluent-cart') . '</div>'
                                    ],
                                    "checkout_method_style" => [
                                        'wrapperClass' => 'col-span-2 flex items-center',
                                        "type"         => "component",
                                        'component'    => 'PaymentView',
                                        'label'        => false,
                                        "options"      => [
                                            [
                                                "label" => '',
                                                "value" => 'logo',
                                            ],
                                            [
                                                "label" => __('Label Selector', 'fluent-cart'),
                                                "value" => 'radio',
                                            ],
                                        ],
                                        "value"        => "logo"
                                    ],
                                ]
                            ],

                            'settings_hr' => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],

                            'date_time_format_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'                   => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Date & Time Format', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Which date and time format to display. `Smart` keeps FluentCart's own format; `WordPress` follows Settings &rarr; General.", 'fluent-cart') . '</div>'
                                    ],
                                    'date_time_format_source' => [
                                        'wrapperClass' => 'col-span-2 flex items-center',
                                        'label'        => '',
                                        'type'         => 'radio',
                                        'options'      => [
                                            [
                                                // Label only -- the stored value stays 'fluent_cart'.
                                                'label' => __('Smart', 'fluent-cart'),
                                                'value' => 'fluent_cart',
                                            ],
                                            [
                                                'label' => __('WordPress', 'fluent-cart'),
                                                'value' => 'wordpress',
                                            ],
                                        ],
                                        'value'        => 'fluent_cart'
                                    ],
                                ]
                            ],

                            'timezone_source_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'           => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Timezone', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Which timezone to display dates in. `Browser` shows admin and dashboard dates in the viewer's own timezone, and renders emails and invoices in the timezone captured at checkout; `WordPress` uses the site timezone from Settings &rarr; General.", 'fluent-cart') . '</div>'
                                    ],
                                    'timezone_source' => [
                                        'wrapperClass' => 'col-span-2 flex items-center',
                                        'label'        => '',
                                        'type'         => 'radio',
                                        'options'      => [
                                            [
                                                // Label only -- the stored value stays 'fluent_cart'.
                                                'label' => __('Browser', 'fluent-cart'),
                                                'value' => 'fluent_cart',
                                            ],
                                            [
                                                'label' => __('WordPress', 'fluent-cart'),
                                                'value' => 'wordpress',
                                            ],
                                        ],
                                        'value'        => 'fluent_cart'
                                    ],
                                ]
                            ],

//                            'settings_hr_modal' => [
//                                'type'  => 'html',
//                                'value' => '<hr class="settings-divider">'
//                            ],
//
//                            'modal_checkout_grid' => [
//                                'type'            => 'grid',
//                                'columns'         => [
//                                    'default' => 1,
//                                    'md'      => 3
//                                ],
//                                'disable_nesting' => true,
//                                'schema'          => [
//                                    'label'                 => [
//                                        'type'  => 'html',
//                                        'value' => '<span class="setting-label">' . __('Buy Now Button Behavior', 'fluent-cart') . '</span>
//                                                            <div class="form-note">' . __("Choose how the Buy Now button behaves. Modal checkout provides a seamless experience without leaving the product page.", 'fluent-cart') . '</div>'
//                                    ],
//                                    "enable_modal_checkout" => [
//                                        'wrapperClass' => 'col-span-2 flex items-center',
//                                        "label"        => '',
//                                        "type"         => "radio",
//                                        "options"      => [
//                                            [
//                                                "label" => __('Redirect to Checkout Page', 'fluent-cart'),
//                                                "value" => 'no',
//                                            ],
//                                            [
//                                                "label" => __('Open Checkout in Modal', 'fluent-cart'),
//                                                "value" => 'yes',
//                                            ],
//                                        ],
//                                        "value"        => "no"
//                                    ],
//                                ]
//                            ],
                        ],
                    ],
//                    'button_setup'           => [
//                        'title'           => __('Button Setup', 'fluent-cart'),
//                        'type'            => 'tab-pane',
//                        'disable_nesting' => true,
//                        'schema'          => [
//                            'button_setup_settings' => [
//                                'title'           => __('Button Setup', 'fluent-cart'),
//                                'type'            => 'section',
//                                'disable_nesting' => true,
//                                'columns'         => [
//                                    'default' => 1,
//                                    'md'      => 2
//                                ],
//                                'schema'          => [
//                                    "checkout_button_text" => [
//                                        "label" => __('Checkout Button Text', 'fluent-cart'),
//                                        "type"  => "input",
//                                        "value" => __('Buy now', 'fluent-cart')
//                                    ],
//                                    "cart_button_text"     => [
//                                        "label" => __('Cart Button Text', 'fluent-cart'),
//                                        "type"  => "input",
//                                        "value" => __('Add to cart', 'fluent-cart')
//                                    ],
//
//                                    "popup_button_text"        => [
//                                        "label" => __('Popup Button Text', 'fluent-cart'),
//                                        "type"  => "input",
//                                        "value" => __('View Product', 'fluent-cart')
//                                    ],
//                                    "out_of_stock_button_text" => [
//                                        "label" => __('Out of Stock Button Text', 'fluent-cart'),
//                                        "type"  => "input",
//                                        "value" => __('Out of stock', 'fluent-cart')
//                                    ],
//                                    "view_cart_button_text"    => [
//                                        "label" => __('View Cart Button Text', 'fluent-cart'),
//                                        "type"  => "input",
//                                        "value" => __('View Cart', 'fluent-cart')
//                                    ],
//
//                                    "note_for_user_account_creation" => [
//                                        "label" => __('Note for User Account Creation,', 'fluent-cart'),
//                                        "type"  => "input",
//                                        "value" => __('You have subscription product in your cart, an account will be created', 'fluent-cart')
//                                    ],
//                                ],
//                            ]
//                        ]
//                    ],
                    'pages_setup'          => [
                        'id'              => '',
                        'title'           => __('Pages Setup', 'fluent-cart'),
                        'show_title'      => false,
                        'type'            => 'section',
                        'disable_nesting' => true,
                        'columns'         => [
                            'default' => 1,
                            'md'      => 1
                        ],
                        'schema'          => [

                            'shop_page_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3,
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'        => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Select Shop Page', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Select the page that showcases all of your products.", 'fluent-cart') . '</div>'
                                    ],
                                    'shop_page_id' => [
                                        'wrapperClass' => 'col-span-2',
                                        'page_title'   => __('Shop', 'fluent-cart'),
                                        'type'         => 'component',
                                        'component'    => 'StoreSettings/PageSelector',
                                        'page_key'     => 'shop_page_id',
                                        'preview_link' => $previewLinks['shop_page_id'],
                                        'options'      => $pages,
                                        'hide_note'    => true,
                                        'value'        => '',
                                        'note'         => \FluentCart\App\Helpers\Helper::getShortcodeInstructionString(
                                            '[fluent_cart_products]',
                                            __('Products', 'fluent-cart')
                                        ),
                                    ],
                                ]
                            ],

                            'hr1' => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],

                            'customer_profile_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'                    => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Select Customer Profile Page', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Select the page where customers will manage their profile, orders, and downloads.", 'fluent-cart') . '</div>'
                                    ],
                                    'customer_profile_page_id' => [
                                        'wrapperClass' => 'col-span-2',
                                        'label'        => false,
                                        'page_title'   => __('Account', 'fluent-cart'),
                                        'type'         => 'component',
                                        'component'    => 'StoreSettings/PageSelector',
                                        'page_key'     => 'customer_profile_page_id',
                                        'preview_link' => $previewLinks['customer_profile_page_id'],
                                        'hide_note'    => true,
                                        'options'      => $pages,
                                        'value'        => '',
                                        'note'         => \FluentCart\App\Helpers\Helper::getShortcodeInstructionString(
                                            '[fluent_cart_customer_profile]',
                                            __('Account', 'fluent-cart')
                                        ),
                                    ],
                                ]
                            ],

                            'hr2' => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],

                            'cart_page_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'        => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Select Cart Page', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Select the page that will display customer's current shopping cart.", 'fluent-cart') . '</div>'
                                    ],
                                    'cart_page_id' => [
                                        'wrapperClass' => 'col-span-2',
                                        'label'        => false,
                                        'page_title'   => __('Cart', 'fluent-cart'),
                                        'type'         => 'component',
                                        'component'    => 'StoreSettings/PageSelector',
                                        'page_key'     => 'cart_page_id',
                                        'preview_link' => $previewLinks['cart_page_id'],
                                        'hide_note'    => true,
                                        'options'      => $pages,
                                        'value'        => '',
                                        'note'         => \FluentCart\App\Helpers\Helper::getShortcodeInstructionString(
                                            '[fluent_cart_cart]',
                                            __('Cart', 'fluent-cart')
                                        ),
                                    ],
                                ]
                            ],

                            'hr3' => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],

                            'receipt_page_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'           => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Select Receipt Page', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Select the page that will display order summary after a successful purchase.", 'fluent-cart') . '</div>'
                                    ],
                                    'receipt_page_id' => [
                                        'wrapperClass' => 'col-span-2',
                                        'label'        => false,
                                        'page_title'   => __('Receipt', 'fluent-cart'),
                                        'type'         => 'component',
                                        'component'    => 'StoreSettings/PageSelector',
                                        'page_key'     => 'receipt_page_id',
                                        'preview_link' => $previewLinks['receipt_page_id'],
                                        'hide_note'    => true,
                                        'options'      => $pages,
                                        'value'        => '',
                                        'note'         => \FluentCart\App\Helpers\Helper::getShortcodeInstructionString(
                                            '[fluent_cart_receipt]',
                                            __('Receipt', 'fluent-cart')
                                        ),
                                    ],
                                ]
                            ],

                            'hr4' => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],

                            'checkout_page_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'            => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Select Checkout Page', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Select the page where customers will finalize their purchase.", 'fluent-cart') . '</div>'
                                    ],
                                    'checkout_page_id' => [
                                        'wrapperClass' => 'col-span-2',
                                        'label'        => false,
                                        'page_title'   => __('Checkout', 'fluent-cart'),
                                        'type'         => 'component',
                                        'component'    => 'StoreSettings/PageSelector',
                                        'page_key'     => 'checkout_page_id',
                                        'preview_link' => $previewLinks['checkout_page_id'],
                                        'hide_note'    => true,
                                        'options'      => $pages,
                                        'value'        => '',
                                        'note'         => \FluentCart\App\Helpers\Helper::getShortcodeInstructionString(
                                            '[fluent_cart_checkout]',
                                            __('Checkout', 'fluent-cart')
                                        ),
                                    ],
                                ]
                            ]
                        ]
                    ],
                    'single_product_setup' => [
                        'title'           => __('Product Page', 'fluent-cart'),
                        'show_title'      => false,
                        'type'            => 'section',
                        'disable_nesting' => true,
                        'columns'         => [
                            'default' => 1,
                            'md'      => 1
                        ],
                        'schema'          => [
                            'product_settings_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'  => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Single Product Setup', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Control the display of relevant information.", 'fluent-cart') . '</div>'
                                    ],
                                    'fields' => [
                                        'type'            => 'grid',
                                        'columns'         => [
                                            'default' => 1,
                                            'md'      => 1
                                        ],
                                        'disable_nesting' => true,
                                        'schema'          => [
                                            "show_relevant_product_in_single_page" => [
                                                "label" => __('Show Relevant In Single Page', 'fluent-cart'),
                                                "type"  => "checkbox",
                                                "value" => "yes"
                                            ],
                                            "show_relevant_product_in_modal"       => [
                                                "label" => __('Show Relevant In Product Modal', 'fluent-cart'),
                                                "type"  => "checkbox",
                                                "value" => "no"
                                            ],
                                        ]
                                    ]

                                ]
                            ],
                            'hr'                    => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],

                            'image_zoom_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'  => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Image Zooming', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Enable Image zoom in single product.", 'fluent-cart') . '</div>'
                                    ],
                                    'fields' => [
                                        'type'            => 'grid',
                                        'columns'         => [
                                            'default' => 1,
                                            'md'      => 1
                                        ],
                                        'disable_nesting' => true,
                                        'schema'          => [
                                            "enable_image_zoom_in_single_product" => [
                                                "label" => __('Enable Zoom in Single Product', 'fluent-cart'),
                                                "type"  => "checkbox",
                                                "value" => "no"
                                            ],
                                            "enable_image_zoom_in_modal"          => [
                                                "label" => __('Enable Zoom in Modal', 'fluent-cart'),
                                                "type"  => "checkbox",
                                                "value" => "no"
                                            ],
                                        ]
                                    ]

                                ]
                            ],

                            'hr_image_zoom' => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],

                            'variation_grid'        => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'          => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Variation View', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Defines how product variations are visually presented to customers.", 'fluent-cart') . '</div>'
                                    ],
                                    "variation_view" => [
                                        'wrapperClass' => 'col-span-2 flex items-center',
                                        "label"        => false,
                                        "type"         => "select",
                                        "options"      => [
                                            [
                                                "label" => __('Image', 'fluent-cart'),
                                                "value" => 'image',
                                            ],
                                            [
                                                "label" => __('Text', 'fluent-cart'),
                                                "value" => 'text',
                                            ],
                                            [
                                                "label" => __('Image with Text', 'fluent-cart'),
                                                "value" => 'both',
                                            ],
                                        ],
                                        "value"        => ""
                                    ],

                                ]
                            ],
                            'hr2'                   => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],
                            'variation_column_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'             => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Variation Columns', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Set the column layout for how product variations are displayed within product sections.", 'fluent-cart') . '</div>'
                                    ],
                                    "variation_columns" => [
                                        'wrapperClass' => 'col-span-2 flex items-center',
                                        "label"        => false,
                                        "type"         => "select",
                                        "options"      => [
                                            [
                                                "label" => __('One Column', 'fluent-cart'),
                                                "value" => 'one',
                                            ],
                                            [
                                                "label" => __('Two Columns', 'fluent-cart'),
                                                "value" => 'two',
                                            ],
                                            [
                                                "label" => __('Three Columns', 'fluent-cart'),
                                                "value" => 'three',
                                            ],
                                            [
                                                "label" => __('Four Columns', 'fluent-cart'),
                                                "value" => 'four',
                                            ],
                                            [
                                                "label" => __('Masonry', 'fluent-cart'),
                                                "value" => 'masonry',
                                            ],
                                        ],
                                        "value"        => ""
                                    ],

                                ]
                            ],

                            'hr3'               => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],
                            'product_slug_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'        => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Product Slug', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Set Product Slug.", 'fluent-cart') . '</div>'
                                    ],
                                    "product_slug" => [
                                        'wrapperClass' => 'col-span-2 flex items-center',
                                        "label"        => false,
                                        "type"         => "input",
                                        "value"        => ""
                                    ],

                                ]
                            ],
                        ],
                    ],
                    'compliance'           => [
                        'title'           => __('Compliance', 'fluent-cart'),
                        'show_title'      => false,
                        'type'            => 'section',
                        'disable_nesting' => true,
                        'columns'         => [
                            'default' => 1,
                            'md'      => 1,
                        ],
                        'schema'          => [
                            'auto_login_after_account_creation' => [
                                'wrapperClass' => 'fct-compliance-auto-login',
                                'label'      => __('Login after account creation', 'fluent-cart'),
                                'type'       => 'radio',
                                'value'      => 'no',
                                'attributes' => [
                                    'aria-label' => __('Login after account creation', 'fluent-cart'),
                                ],
                                'options'    => [
                                    [
                                        'label' => __("Don't auto login after account creation", 'fluent-cart'),
                                        'value' => 'no',
                                    ],
                                    [
                                        'label' => __('Enable auto login after account creation', 'fluent-cart'),
                                        'value' => 'yes',
                                    ],
                                ],
                                'note'       => __('Choose whether customers are logged in automatically when FluentCart creates their account during registration or after checkout.', 'fluent-cart'),
                            ],
                        ],
                    ],
                    'cart_and_checkout'    => [
                        'title'           => __('Cart & checkout', 'fluent-cart'),
                        'show_title'      => false,
                        'type'            => 'section',
                        'disable_nesting' => true,
                        'columns'         => [
                            'default' => 1,
                            'md'      => 1
                        ],
                        'schema'          => [
                            "show_cart_icon_in_body" => [
                                "label" => __('Cart Icon In Body', 'fluent-cart'),
                                "type"  => "checkbox",
                                "value" => "yes",
                                'note'  => sprintf(
                                /* translators: %1$s: Display instruction text, %2$s: "Use this" text, %3$s: Copy to clipboard tooltip, %4$s: CSS class name, %5$s: CSS class description text */
                                    "<div class='pl-6'><p>%1\$s</p><p>%2\$s <code class='copyable-content' title='%3\$s'>%4\$s</code> %5\$s</p></div>",
                                    __('Display the cart icon in the body of the website', 'fluent-cart'),
                                    __('Use this', 'fluent-cart'),
                                    __('Copy to clipboard', 'fluent-cart'),
                                    'fcart-cart-toggle-button',
                                    __('CSS class to display the cart link/icon anywhere else.', 'fluent-cart')
                                )
                            ],
                            'hr2'                    => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],
                            "require_logged_in"      => [
                                "label"        => __('Require user to be logged in for checkout (Coming soon)', 'fluent-cart'),
                                "type"         => "checkbox",
                                "value"        => "no",
                                'note'         => "<div class='pl-6'>" . __('Enforce that customers must be logged into their account to complete a purchase.', 'fluent-cart') . "</div>",
                                'wrapperClass' => 'disabled'
                            ],
                            'hr3'                    => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],

                            'user_account_creation_mode_grid' => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'                   => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('User Account Creation Mode', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("User account will be created regardless of the mode if the order has a subscription.", 'fluent-cart') . '</div>'
                                    ],
                                    'user_account_input_grid' => [
                                        'type'            => 'grid',
                                        'disable_nesting' => true,
                                        'class'           => 'col-span-2',
                                        'schema'          => [
                                            'user_account_creation_mode' => [
                                                'wrapperClass' => 'col-span-2 flex items-center',
                                                "label"        => '',
                                                "type"         => "radio",
                                                "options"      => [
                                                    [
                                                        "label"        => __('Create User Account Automatically after payment
', 'fluent-cart'),
                                                        "value"        => 'all',
                                                        'note'         => "<div class='my-[2px] form-note'>" . __('User account will be created automatically after payment.', 'fluent-cart') . "</div>",
                                                        'option_class' => 'mb-2.5'
                                                    ],
                                                    [
                                                        "label"        => __('Give checkbox to create account on checkout page', 'fluent-cart'),
                                                        "value"        => 'user_choice',
                                                        'note'         => "<div class='my-[2px] form-note'>" . __('User will have an option to create an account during checkout.', 'fluent-cart') . "</div>",
                                                        'option_class' => 'mb-2.5'
                                                    ],
                                                    [
                                                        "label" => __('No need to create account for onetime purchases', 'fluent-cart'),
                                                        "value" => 'only_subscription',
                                                        'note'  => "<div class='my-[2px] form-note'>" . __('User account will not be created for onetime purchases.', 'fluent-cart') . "</div>",
                                                    ],
                                                ],
                                                "value"        => "all"
                                            ],
                                        ]
                                    ],
                                ]
                            ],


//                            "allow_checkout_signup"    => [
//                                "label" => __('Allow customers to create account during one-time checkout', 'fluent-cart'),
//                                "type"  => "checkbox",
//                                "value" => "no",
//                                'note'  => "<div class='pl-6'>" . __('Provide an option for customers to create a user account during a single, non-recurring checkout process.', 'fluent-cart') . "</div>"
//                            ],
//                    "force_ssl" => [
//                        "label" => __('Force page to SSL (https)', 'fluent-cart'),
//                        "type" => "checkbox",
//                        "value" => "no"
//                    ],
                            'hr5'                             => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],
                            "hide_coupon_field"               => [
                                "label" => __('Hide coupon field on checkout', 'fluent-cart'),
                                "type"  => "checkbox",
                                "value" => "no",
                                'note'  => "<div class='pl-6'>" . __('Hide the coupon code input field on the checkout page.', 'fluent-cart') . "</div>"
                            ],
                            'hr6'                             => [
                                'type'  => 'html',
                                'value' => '<hr class="settings-divider">'
                            ],
                            'order_invoice_grid'              => [
                                'type'            => 'grid',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'              => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Receipt Settings', 'fluent-cart') . '</span>
                                                            <div class="form-note">' . __("Setup your receipt.", 'fluent-cart') . '</div>'
                                    ],
                                    'invoice_input_grid' => [
                                        'type'            => 'grid',
                                        'disable_nesting' => true,
                                        'class'           => 'col-span-2',
                                        'schema'          => [
                                            "min_receipt_number" => [
                                                'wrapperClass' => 'col-span-2 flex flex-col',
                                                "label"        => __('Minimum Receipt Number', 'fluent-cart'),
                                                "type"         => "input",
                                                "value"        => "",
                                                'note'         => sprintf(
                                                    "<div class=''>%s</div>",
                                                    sprintf(
                                                    /* translators: %s is the next receipt number */
                                                        __('Next Receipt Number: %s', 'fluent-cart'),
                                                        OrderService::getNextReceiptNumber()
                                                    )
                                                ),

                                            ],
                                            "inv_prefix"         => [
                                                'wrapperClass' => 'col-span-2 flex flex-col',
                                                "label"        => __('Receipt Prefix', 'fluent-cart'),
                                                "type"         => "input",
                                                "value"        => "",
                                            ],
                                        ]
                                    ],
                                ]
                            ],
                        ]
                    ],
                    'subscriptions_setup'  => [
                        'title'           => __('Subscriptions', 'fluent-cart'),
                        'show_title'      => false,
                        'type'            => 'section',
                        'disable_nesting' => true,
                        'columns'         => [
                            'default' => 1,
                            'md'      => 1
                        ],
                        'schema'          => [
                            'subscription_early_payment_grid' => [
                                'type'            => 'grid',
                                'wrapperClass'    => 'items-start mb-6',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'  => [
                                        'type'  => 'html',
                                        'value' => '<span class="setting-label">' . __('Early Payment', 'fluent-cart') . (!$isProActive ? ' <img src="' . esc_url($proFeatureIcon) . '" alt="' . esc_attr__('Pro feature', 'fluent-cart') . '" class="pro-feature-icon" style="margin-left: 6px; width: 14px; height: 14px; display: inline-block; vertical-align: text-top;" />' : '') . '</span>
                                                            <div class="form-note">' . __('Let customers pay remaining installments before their due date.', 'fluent-cart') . '</div>'
                                    ],
                                    'fields' => [
                                        'type'            => 'grid',
                                        'columns'         => [
                                            'default' => 1,
                                            'md'      => 1
                                        ],
                                        'disable_nesting' => true,
                                        'class'           => 'col-span-2',
                                        'schema'          => [
                                            'enable_early_payment_for_installment' => [
                                                'label'        => __('Enable early payment for installment', 'fluent-cart'),
                                                'type'         => 'checkbox',
                                                'value'        => 'yes',
                                                'disabled'     => !$isProActive,
                                                'wrapperClass' => !$isProActive ? 'disabled' : '',
                                                'note'         => !$isProActive
                                                    ? "<div class='pl-6'>" . __('This is a FluentCart Pro feature.', 'fluent-cart') . "</div>"
                                                    : "<div class='pl-6'>" . __('Allow customers to pay remaining installments early from subscription screens.', 'fluent-cart') . "</div>"
                                            ]
                                        ]
                                    ]
                                ]
                            ],
                            'subscription_management_mode_grid' => [
                                'type'            => 'grid',
                                'wrapperClass'    => 'items-start',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'  => [
                                        'type'  => 'html',
                                        'value' => sprintf(
                                        /* translators: 1: setting label, 2: setting description */
                                            '<span class="setting-label">%1$s</span>
                                                            <div class="form-note">%2$s</div>',
                                            __('Renewal Billing', 'fluent-cart'),
                                            __('Choose how recurring subscription payments are collected.', 'fluent-cart')
                                        )
                                    ],
                                    'fields' => [
                                        'type'            => 'grid',
                                        'columns'         => [
                                            'default' => 1,
                                            'md'      => 1
                                        ],
                                        'disable_nesting' => true,
                                        'class'           => 'col-span-2',
                                        'schema'          => [
                                            // Compact, guarded control: shows only the CURRENT mode with a
                                            // short how-it-works summary and a Change button. Editing goes
                                            // through a disclaimer confirm + dialog so the store-wide
                                            // billing decision can never be flipped by a stray click.
                                            'subscription_management_mode' => [
                                                'label'           => false,
                                                'component'       => 'StoreSettings/SubscriptionModeManager',
                                                'disable_nesting' => true,
                                                'value'           => 'gateway_managed',
                                                // Read-only renewal-order schedule, built live from the
                                                // same map the scheduler uses (filterable).
                                                'schedule_items'  => $invoiceScheduleList,
                                                // Which gateways auto-charge is actually possible on.
                                                'system_charge_gateways' => $systemChargeGateways,
                                            ],
                                            // Keep these in the form payload — the component above writes
                                            // them; without a schema entry the keys would drop out of the
                                            // saved values.
                                            'subscription_system_charge'   => [
                                                'type'  => 'hidden',
                                                'value' => 'no',
                                            ],
                                            'subscription_manual_fallback' => [
                                                'type'  => 'hidden',
                                                'value' => 'no',
                                            ],
                                        ]
                                    ]
                                ]
                            ],
                            'subscription_mode_guard_grid' => [
                                'type'            => 'grid',
                                'wrapperClass'    => 'items-start mt-6',
                                'columns'         => [
                                    'default' => 1,
                                    'md'      => 3
                                ],
                                'disable_nesting' => true,
                                'schema'          => [
                                    'label'  => [
                                        'type'  => 'html',
                                        'value' => sprintf(
                                        /* translators: 1: setting label, 2: setting description */
                                            '<span class="setting-label">%1$s</span>
                                                            <div class="form-note">%2$s</div>',
                                            __('Staging Protection', 'fluent-cart'),
                                            __('Prevent staging or test copies of your store from billing real customers.', 'fluent-cart')
                                        )
                                    ],
                                    'fields' => [
                                        'type'            => 'grid',
                                        'columns'         => [
                                            'default' => 1,
                                            'md'      => 1
                                        ],
                                        'disable_nesting' => true,
                                        'class'           => 'col-span-2',
                                        'schema'          => [
                                            'subscription_mode_guard' => [
                                                'label' => __('Don\'t bill live subscriptions from this site while it is in test mode', 'fluent-cart'),
                                                'type'  => 'checkbox',
                                                'value' => 'yes',
                                                'note'  => sprintf(
                                                    /* translators: 1: the setting's explanatory note text */
                                                    "<div class='pl-6'>%1\$s</div>",
                                                    __('In test mode, this site won\'t invoice, charge, or email live subscriptions — so a staging copy can never double-bill customers. Billing from this site resumes when it is live again.', 'fluent-cart')
                                                )
                                            ],
                                        ]
                                    ]
                                ]
                            ],
                        ]
                    ],
                    'appearance'           => $this->getAppearanceSchema(),
                ]
            ],
        ];


        // Only show weight/dimension unit fields to users with shipping-sensitive permission
        if (PermissionManager::hasPermission(['store/sensitive'])) {
            $storeSchema = &$fields['setting_tabs']['schema']['store_setup']['schema'];

            $storeSchema['weight_unit_grid_divider'] = [
                'type'  => 'html',
                'value' => '<hr class="settings-divider">'
            ];

            $storeSchema['weight_unit_grid'] = [
                'type'            => 'grid',
                'columns'         => ['default' => 1, 'md' => 3],
                'disable_nesting' => true,
                'schema'          => [
                    'label'       => [
                        'type'  => 'html',
                        'value' => '<span class="setting-label">' . __('Weight Unit', 'fluent-cart') . '</span>
                                    <div class="form-note">' . __('Unit used for product weight measurements.', 'fluent-cart') . '</div>'
                    ],
                    'weight_unit' => [
                        'wrapperClass' => 'col-span-2 flex items-center',
                        'label'        => '',
                        'type'         => 'select',
                        'options'      => [
                            ['label' => __('kg', 'fluent-cart'), 'value' => 'kg'],
                            ['label' => __('g', 'fluent-cart'), 'value' => 'g'],
                            ['label' => __('lbs', 'fluent-cart'), 'value' => 'lbs'],
                            ['label' => __('oz', 'fluent-cart'), 'value' => 'oz'],
                        ],
                        'value'        => 'kg'
                    ],
                ]
            ];
            $storeSchema['dimension_unit_grid'] = [
                'type'            => 'grid',
                'columns'         => ['default' => 1, 'md' => 3],
                'disable_nesting' => true,
                'schema'          => [
                    'label'          => [
                        'type'  => 'html',
                        'value' => '<span class="setting-label">' . __('Dimension Unit', 'fluent-cart') . '</span>
                                    <div class="form-note">' . __('Unit used for product dimension measurements.', 'fluent-cart') . '</div>'
                    ],
                    'dimension_unit' => [
                        'wrapperClass' => 'col-span-2 flex items-center',
                        'label'        => '',
                        'type'         => 'select',
                        'options'      => [
                            ['label' => __('cm', 'fluent-cart'), 'value' => 'cm'],
                            ['label' => __('mm', 'fluent-cart'), 'value' => 'mm'],
                            ['label' => __('in', 'fluent-cart'), 'value' => 'in'],
                            ['label' => __('m', 'fluent-cart'), 'value' => 'm'],
                        ],
                        'value'        => 'cm'
                    ],
                ]
            ];
        }

        return apply_filters("fluent_cart/store_settings/fields", $fields, []);
    }

    public function isModuleTabEnabled(): bool
    {
        $fields = $this->fields();
        return isset($fields['setting_tabs']['schema']['modules_tab']);
    }

    /**
     * @param string|null $key like stripe or paypal
     * All store settings if key is not provided
     * @return mixed
     *
     */

    public function get($key = null, $default = null)
    {
        if (empty($key)) {
            return $this->storeSettings;
        }

        if (is_array($key)) {
            $data = Arr::only($this->storeSettings, $key);
        } else {
            $data = Arr::get($this->storeSettings, $key);
        }

        return empty($data) ? $default : $data;
    }

    public function moduleSettings(): array
    {
        $settings = $this->get('modules_settings', []);
        return Arr::wrap($settings);
    }

    /**
     * @param array $settings like Stripe or PayPal settings array
     * Save store settings
     * @return array
     *
     */
    public function save(array $settings)
    {
        $prevSettings = get_option($this->optionKey, []);
        $prevSettings = wp_parse_args($prevSettings, $this->getDefaultSettings());

        $settings = wp_parse_args($settings, $prevSettings);

        $customerProfilePageId = Arr::get($settings, 'customer_profile_page_id', 0);
        if ($customerProfilePageId) {
            $settings['customer_profile_page_slug'] = $this->getCustomerDashboardPageSlug($customerProfilePageId, false);
        }

        update_option($this->optionKey, $settings, true);
        $this->storeSettings = $settings;
        self::$cachedStoreSettings = $this->storeSettings;
        wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);

        $isSlugChanged = Arr::get($prevSettings, 'product_slug') !== Arr::get($settings, 'product_slug');
        $isAccountPageChanged = Arr::get($prevSettings, 'customer_profile_page_slug') !== Arr::get($settings, 'customer_profile_page_slug');

        if ($isSlugChanged || $isAccountPageChanged) {
            flush_rewrite_rules();
            delete_option('rewrite_rules');
        }

        return $this->storeSettings;
    }

    public function set(string $key, $value)
    {
        return $this->save([
            $key => $value
        ]);
    }

    public function getCurrency()
    {
        return $this->get('currency', 'USD');
    }

    public function getCurrencySymbol()
    {
        $currency = $this->getCurrency();
        return CurrenciesHelper::getCurrencySign($currency);
    }

    public function isCheckoutPage(): bool
    {
        global $post;
        if (!$post instanceof \WP_Post) {
            return false;
        }
        $pageId = $this->getCheckoutPageId();
        return intval($pageId) === intval($post->ID);
    }

    public function getCheckoutPageId()
    {
        return Arr::get($this->storeSettings, 'checkout_page_id');
    }

    public function getPagesSettings(): array
    {
        return Arr::only(
            $this->storeSettings,
            [
                'checkout_page_id',
                'custom_payment_page_id',
                'registration_page_id',
                'login_page_id',
                'cart_page_id',
                'receipt_page_id',
                'shop_page_id',
                'customer_profile_page_id',
                'customer_profile_page_slug',
            ]
        );
    }

    public function getCheckoutPage(): string
    {
        if ($pageID = $this->getCheckoutPageId()) {
            return $this->getPageLink($pageID);
        }

        return '';
    }


    public function getCustomerProfilePageId()
    {
        return Arr::get($this->storeSettings, 'customer_profile_page_id');
    }

    public function getCustomerProfilePage(): string
    {
        if ($pageId = $this->getCustomerProfilePageId()) {
            return $this->getPageLink($pageId);
        }
        return '';
    }


    public function getCartPageId()
    {
        return Arr::get($this->storeSettings, 'cart_page_id');
    }

    public function getCartPage(): string
    {
        if ($pageId = $this->getCartPageId()) {
            if (Pages::isPage($pageId)) {
                return $this->getPageLink($pageId);
            }
        }
        return '';
    }

    public function getShopPageId()
    {
        return Arr::get($this->storeSettings, 'shop_page_id');
    }

    public function getShopPage(): string
    {
        if ($pageId = $this->getShopPageId()) {
            if (Pages::isPage($pageId)) {
                return $this->getPageLink($pageId);
            }
        }

        return '';
    }

    public function getReceiptPageId()
    {
        return Arr::get($this->storeSettings, 'receipt_page_id');
    }

    public function getReceiptPage(): string
    {
        if ($pageId = $this->getReceiptPageId()) {
            if (Pages::isPage($pageId)) {
                return $this->getPageLink($pageId);
            }
        }
        return home_url();
    }


    public function getBuyButtonText(): string
    {
        return esc_html(Arr::get($this->storeSettings, 'checkout_button_text', __('Buy now', 'fluent-cart')));
    }

    public function getViewCartButtonText(): string
    {
        return esc_html(Arr::get($this->storeSettings, 'view_cart_button_text', __('View Cart', 'fluent-cart')));
    }

    /**
     * @return string
     *
     * Get cart button text
     */
    public function getCartButtonText(): string
    {
        return esc_html(Arr::get($this->storeSettings, 'cart_button_text', __('Add To Cart', 'fluent-cart')));
    }

    public function toArray(): array
    {
        return $this->storeSettings;
    }

    /**
     * @return string
     *
     * Get the base address1 for the store.
     */
    public function getBaseAddressLine1()
    {
        return Arr::get($this->storeSettings, 'store_address1');
    }

    public function getFormattedFullAddress()
    {
        $addressParts = [
            trim(Arr::get($this->storeSettings, 'store_address1') ?? ''),
            trim(Arr::get($this->storeSettings, 'store_address2') ?? ''),
            trim(Arr::get($this->storeSettings, 'store_city') ?? ''),
            trim(AddressHelper::getStateNameByCode(
                Arr::get($this->storeSettings, 'store_state'),
                Arr::get($this->storeSettings, 'store_country')
            )),
            trim(Arr::get($this->storeSettings, 'store_postcode') ?? ''),
            trim(AddressHelper::getCountryNameByCode(Arr::get($this->storeSettings, 'store_country')))
        ];

        // Filter out empty or null parts
        $addressParts = array_filter($addressParts, function ($part) {
            return $part !== '';
        });

        // Join parts with comma and space
        $addressString = implode(', ', $addressParts);
        return $addressString;
    }

    /**
     * @return string
     *
     * Get the base address2 for the store.
     */
    public function getBaseAddressLine2()
    {
        return Arr::get($this->storeSettings, 'store_address2');
    }

    /**
     * @return string
     *
     * Get the base country for the store.
     */
    public function getBaseCountry()
    {
        return Arr::get($this->storeSettings, 'store_country');
    }

    /**
     * @return string
     *
     * Get the base state for the store.
     */
    public function getBaseState()
    {
        return Arr::get($this->storeSettings, 'store_state');
    }

    /**
     * @return string
     *
     * Get the base city for the store.
     */
    public function getBaseCity()
    {
        return Arr::get($this->storeSettings, 'store_city');
    }

    /**
     * @return string
     *
     * Get the base postcode for the store.
     */
    public function getBasePostcode()
    {
        return Arr::get($this->storeSettings, 'store_postcode');
    }

    public function getInvoicePrefix()
    {
        // @todo: make this dynamic from settings
        return $this->get('inv_prefix', '');
    }

    public function getInvoiceSuffix(): string
    {
        return '';
    }

    public function getCustomerDashboardPageSlug($pageId = null, $cached = true)
    {
        $slug = Arr::get($this->storeSettings, 'customer_profile_page_slug', '');

        if ($cached && $slug) {
            return $slug;
        }

        if (!$pageId) {
            $pageId = Arr::get($this->storeSettings, 'customer_profile_page_id');
        }

        if (!$pageId || !get_post($pageId)) {
            return $slug;
        }

        $url = get_page_link($pageId);
        $url = rtrim($url, '/');

        if (!$url) {
            return $slug;
        }

        return trim(str_replace(home_url('/'), '', $url), '/');
    }

    /**
     * The Appearance settings tab.
     *
     * Built from the ColorPalette registry rather than written out by hand, so
     * a colour added to the registry becomes settable here, sanitised on save
     * and written to the storefront without three separate edits.
     *
     * @return array
     */
    protected function getAppearanceSchema(): array
    {
        // Plain text — the component renders these as text nodes, so no markup
        // and no escaping here or the entities would show up verbatim.
        $sourceOptions = [
            [
                'label' => __("FluentCart's own colors", 'fluent-cart'),
                'value' => ColorPalette::SOURCE_DEFAULT,
                'icon'  => 'PaletteLine',
                'note'  => __('The storefront keeps the colors it ships with.', 'fluent-cart'),
            ],
            [
                'label' => __('Inherit from the active theme', 'fluent-cart'),
                'value' => ColorPalette::SOURCE_THEME,
                'icon'  => 'PaintLine',
                'note'  => sprintf(
                    '%1$s %2$s',
                    __('The storefront palette is rebuilt from your theme, and follows it when the theme changes.', 'fluent-cart'),
                    ThemePalette::sourceLabel()
                ),
            ],
            [
                'label' => __('Customize', 'fluent-cart'),
                'value' => ColorPalette::SOURCE_CUSTOM,
                'icon'  => 'PaletteLine',
                'note'  => __('Pick the colors yourself. Only the ones you set are written to the storefront.', 'fluent-cart'),
            ],
        ];

        $sourceHeading = [
            'label' => __('Where colors come from', 'fluent-cart'),
            'note'  => __('Storefront colors cascade from a small set of globals, so changing one here updates every page that uses it.', 'fluent-cart'),
        ];

        return [
            'title'           => __('Appearance', 'fluent-cart'),
            'show_title'      => false,
            'type'            => 'section',
            'wrapperClass'    => 'fct-appearance-component-section',
            'disable_nesting' => true,
            'columns'         => [
                'default' => 1,
                'md'      => 1,
            ],
            'schema'          => [
                'appearance_component' => [
                    'type'           => 'component',
                    'component'      => 'StoreSettings/AppearanceComponent',
                    'wrapperClass'   => 'col-span-full',
                    'label'          => false,
                    // Rendered by the component itself, so the heading sits
                    // inside .fct-appearance-component with the controls it
                    // describes rather than as a sibling html field.
                    'heading'        => $sourceHeading,
                    'source_options' => $sourceOptions,
                    'custom_source'  => ColorPalette::SOURCE_CUSTOM,
                    'color_groups'   => $this->getAppearanceColorGroups(),
                    'preview'        => $this->getAppearancePreviewData(),
                ],
                'appearance_source'    => [
                    'type'  => 'hidden',
                    'value' => ColorPalette::SOURCE_DEFAULT,
                ],
                'appearance_colors'    => [
                    'type'  => 'hidden',
                    'value' => (object)[],
                ],
            ],
        ];
    }

    /**
     * What the storefront preview needs to paint itself.
     *
     * The preview is drawn from semantic roles rather than settings keys, so
     * one mock card covers all three sources: `customize` reads the pickers the
     * owner has filled in, and the other two are resolved here because their
     * colors only exist server side — `inherit_from_theme` is mixed out of
     * theme.json by ThemePalette and nothing about it reaches the browser.
     *
     * @return array
     */
    protected function getAppearancePreviewData(): array
    {
        // No colour is sent with a slot. appearance.scss already writes every
        // preview surface as `var(--fct-pv-x, <fallback>)`, so the mock has the
        // same single source of truth the storefront does: a slot the component
        // cannot resolve simply goes undeclared and the stylesheet's fallback
        // applies.
        return [
            'slots'        => $this->getAppearancePreviewSlots(),
            'theme_source' => ColorPalette::SOURCE_THEME,
            'theme_roles'  => $this->getAppearanceThemeRoles(),
            'notes'        => [
                ColorPalette::SOURCE_THEME  => $this->getAppearanceThemeNote(),
                ColorPalette::SOURCE_CUSTOM => __('Set only the colors you want to change — anything you leave unset keeps FluentCart\'s own default.', 'fluent-cart'),
            ],
        ];
    }

    /**
     * Which custom property the preview paints each surface with.
     *
     * Keyed by settings key, not by role. Four separate globals claim the
     * `accent` role — active text, the brand background and both active
     * borders — so collapsing the preview to roles would let one picker drive
     * surfaces the storefront keeps apart, and leave the others with no visible
     * effect at all. `role` is carried only for theme inheritance, which
     * genuinely is role-based: FrontendTheme::getThemeColors() gives every key
     * sharing a role the same colour, so the preview collapsing there is the
     * storefront's own behaviour rather than a shortcut.
     *
     * A slot with no `key` is a surface no global controls; it follows the
     * role's default and cannot be edited.
     *
     * @return array
     */
    protected function getAppearancePreviewSlots(): array
    {
        return [
            ['var' => 'surface', 'role' => 'surface', 'key' => 'card_bg_color'],
            ['var' => 'surface-mute', 'role' => 'surface_mute'],
            ['var' => 'surface-alt', 'role' => 'surface_alt', 'key' => 'secondary_bg_color'],
            ['var' => 'text', 'role' => 'text', 'key' => 'primary_text_color'],
            ['var' => 'text-muted', 'role' => 'text_muted', 'key' => 'secondary_text_color'],
            ['var' => 'text-placeholder', 'role' => 'text_placeholder', 'key' => 'input_placeholder_text_color'],
            ['var' => 'input-bg', 'role' => 'surface', 'key' => 'input_bg_color'],
            ['var' => 'input-text', 'role' => 'text', 'key' => 'input_text_color'],
            ['var' => 'input-disabled-bg', 'role' => 'surface_mute', 'key' => 'input_disabled_bg_color'],
            ['var' => 'accent-text', 'role' => 'accent', 'key' => 'primary_active_text_color'],
            ['var' => 'active-border', 'role' => 'accent', 'key' => 'active_border_color'],
            ['var' => 'border', 'role' => 'border', 'key' => 'border_color'],
            ['var' => 'divider', 'role' => 'divider', 'key' => 'divider_color'],
            ['var' => 'button-bg', 'role' => 'button_bg', 'key' => 'btn_bg_color'],
            ['var' => 'button-text', 'role' => 'button_text', 'key' => 'btn_text_color'],
            ['var' => 'secondary-button-bg', 'role' => 'surface', 'key' => 'secondary_btn_bg_color'],
            ['var' => 'secondary-button-text', 'role' => 'text', 'key' => 'secondary_btn_text_color'],
            ['var' => 'secondary-button-border', 'role' => 'border', 'key' => 'secondary_btn_border_color'],
            ['var' => 'secondary-button-hover-bg', 'role' => 'surface_mute', 'key' => 'secondary_btn_hover_bg_color'],
        ];
    }

    /**
     * Why the theme preview may not be the whole truth.
     *
     * Themes such as Astra publish their palette as `var(--ast-global-color-0)`
     * rather than as colors. FrontendTheme writes those references straight
     * through and the browser resolves them on the storefront, but the property
     * is not declared in wp-admin, so the preview cannot show them. Saying so
     * beats showing FluentCart's colors and letting the owner believe that is
     * what inheriting will look like.
     *
     * @return string Empty when the preview is faithful.
     */
    protected function getAppearanceThemeNote(): string
    {
        if (!ThemePalette::hasUsableSource()) {
            return __('The active theme publishes nothing to inherit, so the storefront keeps FluentCart\'s own colors.', 'fluent-cart');
        }

        foreach (ThemePalette::resolve() as $value) {
            if (!sanitize_hex_color((string)$value)) {
                return __('This theme publishes its palette as CSS variables. The storefront reads them, but they cannot be resolved here — the preview shows FluentCart\'s colors in their place.', 'fluent-cart');
            }
        }

        return '';
    }

    /**
     * The theme's colors for the preview, role by role.
     *
     * Only roles that resolved to a real color are returned — the preview
     * falls back to each slot's own default for the rest.
     *
     * @return array Role => hex.
     */
    protected function getAppearanceThemeRoles(): array
    {
        // With nothing to inherit, FrontendTheme writes no declarations at all,
        // so the storefront keeps FluentCart's colors — and so must the
        // preview, or it would promise a change that never happens.
        if (!ThemePalette::hasUsableSource()) {
            return [];
        }

        $roles = [];

        foreach (ThemePalette::resolve() as $role => $value) {
            // A theme that publishes `var(--x)` is written to the storefront
            // verbatim and resolved by the browser there, but that property is
            // not declared in wp-admin. Unpreviewable, so leave it out and let
            // the slot fall back to its own default.
            $hex = sanitize_hex_color((string)$value);

            if ($hex) {
                $roles[$role] = $hex;
            }
        }

        return $roles;
    }

    /**
     * The colour knobs, grouped the way the registry groups them, as data the
     * appearance component renders its pickers from.
     *
     * @return array
     */
    protected function getAppearanceColorGroups(): array
    {
        $groups = [];

        foreach (ColorPalette::groups() as $groupKey => $groupLabel) {
            $globals = ColorPalette::globalsFor($groupKey);

            if (!$globals) {
                continue;
            }

            $fields = [];

            foreach ($globals as $key => $definition) {
                $fields[] = [
                    'key'     => $key,
                    'label'   => Arr::get($definition, 'label', $key),
                    'note'    => $this->getAppearanceFieldNote($definition),
                    'default' => (string)Arr::get($definition, 'default', ''),
                ];
            }

            $groups[] = [
                'key'    => $groupKey,
                'label'  => $groupLabel,
                'fields' => $fields,
            ];
        }

        return $groups;
    }

    /**
     * The hint under one colour picker: what it drives, and the value it
     * falls back to when left empty.
     *
     * @param array $definition
     * @return string
     */
    protected function getAppearanceFieldNote(array $definition): string
    {
        $usage = (string)Arr::get($definition, 'note', '');
        $default = (string)Arr::get($definition, 'default', '');

        if ($default === '') {
            return $usage;
        }

        if ($usage === '') {
            /* translators: %1$s: the hex colour FluentCart falls back to */
            return sprintf(__('Default: %1$s', 'fluent-cart'), $default);
        }

        /* translators: 1: what the colour is used for, 2: the hex colour FluentCart falls back to */
        return sprintf(__('%1$s Default: %2$s', 'fluent-cart'), $usage, $default);
    }

    /**
     * Get theme colors as CSS variable string.
     *
     * @return string
     */
    public function getThemeColors(): string
    {
        $themeSetup = $this->get('theme_setup');
        $colors = '';

        if (!empty($themeSetup) && is_array($themeSetup)) {
            foreach ($themeSetup as $key => $value) {
                if (!empty($value)) {
                    $safeKey = preg_replace('/[^a-zA-Z0-9\-_]/', '', $key);
                    $safeValue = preg_replace('/[;\{\}]/', '', $value);
                    $colors .= "$safeKey: $safeValue;";
                }
            }
        }

        return $colors;
    }

    public function getPageLink($pageId = null): string
    {
        if (!$pageId || !get_post($pageId)) {
            return '';
        }

        $link = get_page_link($pageId);

        if (!empty($link)) {
            return Str::endsWith($link, '/') ? $link : $link . '/';
        }
        return '';
    }

}
