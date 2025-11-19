<?php

namespace FluentCart\App\Modules\Shipping\Http\Controllers\Frontend;

use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class ShippingFrontendController extends Controller
{
    public function getAvailableShippingMethods(Request $request)
    {

        $timezone = sanitize_text_field($request->get('timezone'));
        $state = sanitize_text_field($request->get('state'));

        if ($timezone) {
            $countryCode = LocalizationManager::guessCountryFromTimezone($timezone);
        } else {
            $countryCode = sanitize_text_field($request->get('country_code'));
        }


        if (!$countryCode) {
            return [
                'status'  => false,
                'message' => __('Country code is required', 'fluent-cart')
            ];
        }

        $availableShippingMethods = ShippingMethod::applicableToCountry($countryCode, $state)->get();

        if (!$availableShippingMethods || $availableShippingMethods->isEmpty()) {
            $settingView = '<div class="fct-empty-state">'
                . esc_html__('No shipping methods available for this address.', 'fluent-cart');

            if (current_user_can('manage_options')) {
                $settingsPageUrl = admin_url('admin.php?page=fluent-cart#/settings/shipping');

                $settingsLink = '<a href="' . esc_url($settingsPageUrl??'') . '" target="_blank">' . esc_html__('Activate from settings.', 'fluent-cart') . '</a>';

                $settingView .= ' ' . $settingsLink;
            }

            $settingView .= '</div>';


            return [
                'status'       => false,
                'country_code' => $countryCode,
                'view'         => $settingView
            ];
        }

        return [
            'available_shipping_methods' => $availableShippingMethods,
            'country_code'               => $countryCode
        ];
    }

    public function getShippingMethodsListView(Request $request)
    {
        $availableShippingMethods = $this->getAvailableShippingMethods($request);
        $shippingMethods = Arr::get($availableShippingMethods, 'available_shipping_methods');
        $countryCode = Arr::get($availableShippingMethods, 'country_code');
        $status = Arr::get($availableShippingMethods, 'status');

        if ($status === false) {
            return $availableShippingMethods;
        }

        ob_start();
        do_action('fluent_cart/views/checkout_page_shipping_method_list', [
            'shipping_methods' => $shippingMethods
        ]);
        $content = ob_get_clean();

        return $this->sendSuccess(
            [
                'status'       => true,
                'view'         => $content,
                'country_code' => $countryCode,
            ]
        );

    }

    public function getCountryInfo(Request $request): \WP_REST_Response
    {
        $timezone = sanitize_text_field($request->get('timezone'));

        if ($timezone) {
            $countryCode = LocalizationManager::guessCountryFromTimezone($timezone);
        } else {
            $countryCode = sanitize_text_field($request->get('country_code'));
        }

        $states = LocalizationManager::getInstance()->statesOptions($countryCode);
        $addressLocale = LocalizationManager::getInstance()->addressLocales($countryCode);

        return $this->sendSuccess([
            'country_code'   => $countryCode,
            'states'         => $states,
            'address_locale' => $addressLocale
        ]);
    }
}
