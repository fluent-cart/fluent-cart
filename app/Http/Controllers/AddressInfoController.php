<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\App;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\Framework\Http\Request\Request;

class AddressInfoController extends Controller
{
    public function countriesOption(): \WP_REST_Response
    {
        $countries = LocalizationManager::getInstance()->countriesOptions();
        return $this->sendSuccess([
            'data' => $countries
        ]);
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
            'country_name'   => AddressHelper::getCountryNameByCode($countryCode),
            'states'         => $states,
            'address_locale' => $addressLocale
        ]);
    }
}
