<?php

namespace FluentCart\App\Modules\Shipping\Http\Controllers\Frontend;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Http\Controllers\Controller;
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

        // The same merged, currency-converted list checkout prices against, quoted for
        // the visitor's cart when there is one.
        $cart = CartHelper::getCart();

        return AddressHelper::getAvailableShippingMethodLists([
            'country_code' => $countryCode,
            'state'        => $state,
            // Public route: live carriers quote only a session cart that ships.
            'zones_only'   => !$cart || !$cart->requireShipping(),
        ]);
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
