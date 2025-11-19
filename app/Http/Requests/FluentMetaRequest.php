<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class FluentMetaRequest extends RequestGuard
{

    /**
     * @return array
     */
    public function rules()
    {


        $rulesMap = [
            'store_setup' => [
                'store_name'    => 'required|sanitizeText|maxLength:200',
                'store_country' => 'required|sanitizeText|maxLength:200',
            ]
        ];

        $rules = Arr::get($rulesMap, $this->get('settings_name'), []);
//        $rules = [
//            'object_id'   => 'integer|min:1',
//            'object_type' => 'nullable|sanitizeText|max:50',
//            'meta_key'    => 'nullable|sanitizeText|max:50',
//            'meta_value'  => 'nullable|sanitizeText',
//            'store_name'  => 'required|sanitizeText|max:200',
//            'store_country' => 'required|sanitizeText|max:200',
//        ];
        return apply_filters('fluent_cart/store_settings/rules', $rules);
    }


    /**
     * @return array
     */
    public function messages(): array
    {
        return [
            'store_name.required'    => esc_html__('Store name is required.', 'fluent-cart'),
            'store_country.required' => esc_html__('Store country is required.', 'fluent-cart'),
        ];
    }


    /**
     * @return array
     */
    public function sanitize(): array
    {
        $sanitizer = [
            'object_type'                          => 'sanitize_text_field',
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_key'                             => 'sanitize_text_field',
            'type'                                 => 'sanitize_text_field',
            'default'                              => 'sanitize_text_field',
            'store_name'                           => 'sanitize_text_field',
            'store_logo'                           => function ($value) {
                if (!is_array($value)) {
                    return '';
                }
                return [
                    'id'    => sanitize_text_field(Arr::get($value, 'id')),
                    'url'   => sanitize_url(Arr::get($value, 'url')),
                    'title' => sanitize_text_field(Arr::get($value, 'title')),
                ];
            },
            'checkout_button_text'                 => 'sanitize_text_field',
            'view_cart_button_text'                => 'sanitize_text_field',
            'note_for_user_account_creation'       => 'sanitize_text_field',
            'cart_button_text'                     => 'sanitize_text_field',
            'popup_button_text'                    => 'sanitize_text_field',
            'out_of_stock_button_text'             => 'sanitize_text_field',
            'thousand_separator'                   => 'sanitize_text_field',
            'decimal_separator'                    => 'sanitize_text_field',
            'currency_position'                    => 'sanitize_text_field',
            'checkout_method_style'                => 'sanitize_text_field',
            'require_logged_in'                    => 'sanitize_text_field',
            'show_cart_icon_in_nav'                => 'sanitize_text_field',
            'show_cart_icon_in_body'               => 'sanitize_text_field',
            'additional_address_field'             => 'sanitize_text_field',
            'hide_coupon_field'                    => 'sanitize_text_field',
            'user_account_creation_mode'           => 'sanitize_text_field',
            'force_ssl'                            => 'sanitize_text_field',
            'checkout_page_id'                     => 'intval',
            'cart_page_id'                         => 'intval',
            'receipt_page_id'                      => 'intval',
            'shop_page_id'                         => 'intval',
            'customer_profile_page_id'             => 'intval',
            'customer_profile_page_slug'           => 'sanitize_text_field',
            'registration_page_id'                 => 'intval',
            'login_page_id'                        => 'intval',
            'plugin_activated_once'                => 'sanitize_text_field',
            'rest_route'                           => 'sanitize_text_field',
            'currency'                             => 'sanitize_text_field',
            'store_address1'                       => 'sanitize_text_field',
            'store_address2'                       => 'sanitize_text_field',
            'store_city'                           => 'sanitize_text_field',
            'store_country'                        => 'sanitize_text_field',
            'store_postcode'                       => 'sanitize_text_field',
            'store_state'                          => 'sanitize_text_field',
            'template_settings_checkout_page_mode' => 'sanitize_text_field',
            'show_relevant_product_in_single_page' => 'sanitize_text_field',
            'show_relevant_product_in_modal'       => 'sanitize_text_field',
            'order_mode'                           => 'sanitize_text_field',
            'variation_view'                       => 'sanitize_text_field',
            'variation_columns'                    => 'sanitize_text_field',
            'modules_settings'                     => 'sanitize_text_field',
            'product_slug'                         => 'sanitize_text_field',
            'min_receipt_number'                   => 'sanitize_text_field',
            'inv_prefix'                           => 'sanitize_text_field',
            'enable_image_zoom_in_single_product'  => 'sanitize_text_field',
            'enable_image_zoom_in_modal'           => 'sanitize_text_field',
            'theme_setup'                          => function ($value) {
                if (!is_array($value)) {
                    return [];
                }
                $data = [];

                foreach ($value as $key => $val) {
                    $data[sanitize_text_field($key)] = sanitize_hex_color($val);
                }

                return $data;
            },

        ];

        return apply_filters('fluent_cart/store_settings/sanitizer', $sanitizer);
    }
}
