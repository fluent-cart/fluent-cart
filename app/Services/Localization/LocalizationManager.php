<?php

namespace FluentCart\App\Services\Localization;

/*
 * Use cases for LocalizationManager
* $localization = App::getInstance('localization');
* $countries = $localization->countries();
* $localization->countriesOptions(); //format as options
* $localization->states('BD');
* $localization->statesOptions('BD');
* $localization->phones();
* $localization->phonesOptions();
* $localization->timezones();
* $localization->timezonesOptions();
* $localization->continents();
* $localization->continents('EU');
* $localization->taxContinents('EU');
* $localization->continentsCountries('EU');
* $localization->continentsCountriesOptions('EU');
* $localization->continentFromCountry('BD');
* $localization->locales();
* $localization->locales('BD');
* $localization->localesOptions('BD');
* $localization->units();
* $localization->addressLocales('BD');
* $localization->postcode->isValid(‘NG51GJ', 'GB’)
* $localization->postcode->formatPostcode(‘NG51GJ', 'GB’)
*
*/

use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;

class LocalizationManager
{
    private static ?LocalizationManager $instance = null;

    static ?array $countries = null;
    static ?array $timeZones = null;
    static ?array $continents = null;
    static ?array $taxContinents = null;
    static ?array $locale = null;
    static ?array $addressLocales = null;
    static ?array $phones = null;
    static ?array $states = null;
    static ?array $units = null;

    private static ?PostcodeVerification $postcodeInstance = null;

    /**
     * Get the singleton instance
     */
    public static function getInstance(): LocalizationManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get all countries
     */
    public function countries(): array
    {
        if (is_array(self::$countries)) {
            return self::$countries;
        }
        self::$countries = require 'i18n/countries.php';
        return self::$countries;
    }

    public function getCountryNameByCode($countryCode)
    {

        if (!$countryCode) {
            return '';
        }

        return Arr::get($this->countries(), $countryCode, $countryCode);
    }

    /**
     * Get all timezones
     */
    public function timezones(): array
    {
        if (is_array(self::$timeZones)) {
            return self::$timeZones;
        }
        self::$timeZones = require 'i18n/country_tz.php';
        return self::$timeZones;
    }

    /**
     * Get all continents
     */
    public function continents($countryCode = null): array
    {
        if (is_array(self::$continents)) {
            if ($countryCode) {
                return self::$continents[$countryCode] ?? [];
            }
            return self::$continents;
        }
        self::$continents = require 'i18n/continents.php';
        if ($countryCode) {
            return self::$continents[$countryCode] ?? [];
        }
        return self::$continents;
    }

    /**
     * Get all continents
     */
    public function taxContinents($countryCode = null): array
    {
        if (is_array(self::$continents)) {
            if ($countryCode) {
                return self::$continents[$countryCode] ?? [];
            }
            return self::$continents;
        }
        self::$continents = require 'i18n/eu_tax_countries.php';
        if ($countryCode) {
            return self::$continents[$countryCode] ?? [];
        }
        return self::$continents;
    }

    /**
     * Get all phone codes
     */
    public function phones(): array
    {
        if (is_array(self::$phones)) {
            return self::$phones;
        }
        self::$phones = require 'i18n/phone.php';
        return self::$phones;
    }

    /**
     * Get all locale information
     */
    public function locales($countryCode = null): array
    {
        if (is_array(self::$locale)) {
            if ($countryCode) {
                return self::$locale[$countryCode] ?? [];
            }
            return self::$locale;
        }
        self::$locale = require 'i18n/locale-info.php';
        if ($countryCode) {
            return self::$locale[$countryCode] ?? [];
        }
        return self::$locale;
    }

    public function addressLocales($countryCode = null): array
    {
        if (!is_array(self::$addressLocales)) {
            self::$addressLocales = require 'i18n/address-locales.php';
        }

        if ($countryCode) {
            return self::$addressLocales[$countryCode] ?? [];
        }
        return self::$addressLocales;
    }

    /**
     * Get all states/provinces
     */
    public function states($countryCode = null): array
    {
        if (is_array(self::$states)) {
            if ($countryCode) {
                return self::$states[$countryCode] ?? [];
            }
            return self::$states;
        }
        self::$states = require 'i18n/states.php';
        if ($countryCode) {
            return self::$states[$countryCode] ?? [];
        }
        return self::$states;
    }

    public function getStateNameByCode($stateCode, $countryCode = null)
    {
        if (empty($countryCode)) {
            $states = $this->states();
        } else {
            $states = Arr::get($this->states(), $countryCode);
            if ($states) {
                $states = [
                    $countryCode => $states
                ];
            } else {
                $states = [];
            }
        }

        $collection = new Collection($states);
        $flat = $collection->flatMap(function ($items) {
            return $items;
        });
        return $flat->get($stateCode, $stateCode);
    }

    /**
     * Get all measurement units
     */
    public function units(): array
    {
        if (is_array(self::$units)) {
            return self::$units;
        }
        self::$units = require 'i18n/units.php';
        return self::$units;
    }

    /**
     * Convert array to options format
     */
    private function toOptions(array $items, bool $includeCountries = false): array
    {
        $options = [];
        foreach ($items as $code => $item) {
            $option = [
                'value' => $code,
                'name'  => is_array($item) ? ($item['name'] ?? $code) : $item,
            ];

            if ($includeCountries && isset($item['countries'])) {
                $option['options'] = $item['countries'];
            }

            $options[] = $option;
        }
        return $options;
    }

    /**
     * Get country ISO lists
     */
    public function countryIsoList(): array
    {
        return $this->countries();
    }

    /**
     * Get countries as options
     */
    public function countriesOptions(): array
    {
        return $this->toOptions($this->countries());
    }

    /**
     * Get phone codes as options
     */
    public function phonesOptions(): array
    {
        return $this->toOptions($this->phones());
    }

    /**
     * Get continents as options
     */
    public function continentsOptions(): array
    {
        $continents = $this->continents();
        $options = [];
        foreach ($continents as $code => $continent) {
            $options[] = [
                'value'   => $code,
                'name'    => $continent,
                'options' => $continent['countries'] ?? []
            ];
        }
        return $options;
    }

    public function continentsCountries(string $continentCode): array
    {
        $continents = $this->continents($continentCode);
        $contentsCountries = $continents['countries'] ?? [];
        $countries = [];
        foreach ($contentsCountries as $continent) {
            $countries[$continent] = $this->countries()[$continent] ?? $continent;
        }
        return $countries;
    }

    public function continentsCountriesOptions(string $continentCode): array
    {
        $continents = $this->continents($continentCode);
        $contentsCountries = [];
        $countries = [];
        if ($continentCode) {
            $contentsCountries = $continents['countries'] ?? [];
        }
        foreach ($contentsCountries as $continent) {
            $countries[] = [
                'value' => $continent,
                'name'  => $this->countries()[$continent] ?? $continent,
            ];
        }
        return $countries;
    }

    public function timeZonesOptions(): array
    {
        return $this->toOptions($this->timezones(), true);
    }

    public function localesOptions(): array
    {
        $locales = $this->locales();
        $options = [];
        foreach ($locales as $code => $localesInfo) {
            $options[] = [
                'value' => $localesInfo,
                'name'  => $code,
            ];
        }
        return $options;
    }

    public function statesOptions($countryCode = null): array
    {
        if ($countryCode) {
            return $this->countryStatesOptions($countryCode);
        }
        return array_map(function ($states) {
            return [
                'options' => $states
            ];
        }, $this->states());
    }

    /**
     * Guess country code from timezone
     */
    public function guessCountryTimezone(string $timezone): ?string
    {
        $timezoneMap = $this->timezones();

        // Check for exact matches first
        if (isset($timezoneMap[$timezone])) {
            return $timezoneMap[$timezone];
        }

        // Check for partial matches
        foreach ($timezoneMap as $prefix => $countryCode) {
            if (strpos($timezone, $prefix) === 0) {
                return $countryCode;
            }
        }

        return null;
    }

    /**
     * Get continent code for a country
     */
    public function continentFromCountry(string $countryCode): string
    {
        $countryCode = trim(strtoupper($countryCode));
        $continents = $this->continents();
        $continents_and_codes = wp_list_pluck($continents, 'countries');
        foreach ($continents_and_codes as $continentCode => $countries) {
            if (false !== array_search($countryCode, $countries, true)) {
                return $continentCode;
            }
        }
        return '';
    }

    /**
     * Get calling code for a country
     */
    public function callingCode(string $countryCode): string
    {
        $calling_codes = $this->phones();
        return $calling_codes[$countryCode] ?? '';
    }

    /**
     * Get locale for a country
     */
    public function localeForCountry(string $countryCode): string
    {
        $locales = $this->locales();
        return $locales[$countryCode] ?? '';
    }

    /**
     * Get states for a country
     */
    public function countryStates(string $countryCode): array
    {
        $states = $this->states();
        return Arr::get($states, $countryCode, []);
    }

    /**
     * Get states for a country as options
     */
    public function countryStatesOptions(string $countryCode): array
    {
        $states = $this->countryStates($countryCode);
        $options = [];
        foreach ($states as $code => $state) {
            $options[] = [
                'value' => $code,
                'name'  => $state,
            ];
        }
        return apply_filters("fluent_cart/country_state_options", $options, [
            'countryCode' => $countryCode,
        ]);
    }

    // Static proxy methods for backward compatibility
    public static function getCountries(): array
    {
        return self::getInstance()->countries();
    }

    public static function getTimeZones(): array
    {
        return self::getInstance()->timezones();
    }

    public static function getContinents(): array
    {
        return self::getInstance()->continents();
    }

    public static function getPhones(): array
    {
        return self::getInstance()->phones();
    }

    public static function getLocale($countryCode = null): array
    {
        return self::getInstance()->locales($countryCode);
    }

    public static function getStates(): array
    {
        return self::getInstance()->states();
    }

    public static function getUnits(): array
    {
        return self::getInstance()->units();
    }

    public static function getCountyIsoLists(): array
    {
        return self::getInstance()->countryIsoList();
    }

    public static function guessCountryFromTimezone(string $timezone): ?string
    {
        return self::getInstance()->guessCountryTimezone($timezone);
    }

    public static function getCountryCallingCode($countryCode): string
    {
        return self::getInstance()->callingCode($countryCode);
    }

    public static function getCountryStates($countryCode): array
    {
        return self::getInstance()->countryStates($countryCode);
    }

    public static function getAddressLocales($countryCode): array
    {
        return self::getInstance()->addressLocales($countryCode);
    }

    public static function isValidCountryCode($code)
    {
        $countries = self::getInstance()->countries();
//        dd($countries);
    }

    public static function getCountryInfoFromRequest($timezone, $countryCode): array
    {
        if (!$countryCode && $timezone) {
            $countryCode = self::guessCountryFromTimezone($timezone);
        }

        if (!$countryCode) {
            return [
                'country_code'   => '',
                'states'         => [],
                'address_locale' => []
            ];
        }

        $states = self::getInstance()->statesOptions($countryCode);
        $addressLocale = self::getInstance()->addressLocales($countryCode);

        return [
            'country_code'   => $countryCode,
            'states'         => $states,
            'address_locale' => $addressLocale
        ];
    }

    /**
     * Magic method to access properties
     */
    public function __get($name)
    {
        if ($name === 'postcode') {
            return $this->getPostcodeInstance();
        }

        return null;
    }

    /**
     * Get postcode verification instance
     */
    private function getPostcodeInstance(): PostcodeVerification
    {
        if (self::$postcodeInstance === null) {
            self::$postcodeInstance = new PostcodeVerification();
        }
        return self::$postcodeInstance;
    }

    /**
     * Static method to get postcode verification instance
     */
    public static function postcode()
    {
        return self::getInstance()->getPostcodeInstance();
    }

    public function getZipCodeValidationRule($countryCode): array
    {

        if (!$countryCode) {
            return ['string', 'maxLength:192'];
        }
        $locale = $this->addressLocales($countryCode);

        $isZipcodeRequired = Arr::get($locale, 'postcode.required', true) !== false;
        if (!$isZipcodeRequired) {
            return ['string', 'maxLength:192'];
        }
        return ['required', 'string', 'maxLength:192'];
    }

    public function getValidationRule($data, $prefix = '', $defaultRoles = []): array
    {
        $prefix = $prefix ? $prefix . '_' : '';
        $countries = $this->countries();
        $country = Arr::get($data, $prefix . 'country');
        $addressLocale = $this->addressLocales($country);
        $stateRequired = (string)(Arr::get($addressLocale, 'state.hidden', '')) !== '1';

        if (Arr::get($addressLocale, 'state.required', true) === false) {
            $stateRequired = false;
        }

        $states = empty($country) ? [] : $this->countryStates($country);

        $defaultCountryRoles = Arr::wrap(Arr::get($defaultRoles, 'country', []));
        $defaultStateRoles = Arr::wrap(Arr::get($defaultRoles, 'state', []));
        $defaultPostcodeRoles = Arr::wrap(Arr::get($defaultRoles, 'postcode', []));

        // Get current field structure based on prefix (billing/shipping)
        $checkoutHelper = CartCheckoutHelper::make();
        $fields = $prefix === 'billing_'
            ? $checkoutHelper->getBillingAddressFields()
            : $checkoutHelper->getShippingAddressFields();

        $addressSchema = Arr::get($fields, 'address_section.schema', []);

        $rules = [];

        if (isset($addressSchema['country'])) {
            $rules[$prefix . 'country'] = array_merge($defaultCountryRoles, [
                static function ($attribute, $value) use ($countries) {
                    $isValid = Arr::has($countries, $value);
                    if (!$isValid) {
                        return __('Invalid country code.', 'fluent-cart');
                    }
                }
            ]);
        }

        if (isset($addressSchema['state'])) {
            $rules[$prefix . 'state'] = array_merge($defaultStateRoles, [
                'required' => static function ($attribute, $value) use ($stateRequired) {

                    if ($stateRequired && !$value) {
                        return __('State is required.', 'fluent-cart');
                    }
                    return null;
                },
                'invalid'  => static function ($attribute, $value) use ($data, $country, $states) {

                    if (!is_array($states)) {
                        return null;
                    }

                    if (empty($states)) {
                        return null;
                    }
                    if ($country && $value) {
                        $isValid = Arr::has($states, $value);
                        if (!$isValid) {
                            return __('Invalid state code.', 'fluent-cart');
                        }
                    }

                    return null;
                }
            ]);
        }

        if (isset($cityZipSchema['postcode'])) {
            $rules[$prefix . 'postcode'] = array_merge(
                $defaultPostcodeRoles,
                $this->getZipCodeValidationRule($country)
            );
        }

        return $rules;

//        return [
//            $prefix . 'country'  => array_merge($defaultCountryRoles,
//                [
//                    static function ($attribute, $value) use ($countries) {
//                        $isValid = Arr::has($countries, $value);
//                        if (!$isValid) {
//                            return __('Invalid country code.', 'fluent-cart');
//                        }
//                    }
//                ]
//            ),
//            $prefix . 'state'    => array_merge($defaultStateRoles,
//                [
//                    static function ($attribute, $value) use ($stateRequired) {
//                        if ($stateRequired && !$value) {
//                            return __('State is required.', 'fluent-cart');
//                        }
//                    },
//                    static function ($attribute, $value) use ($data, $country, $states) {
//                        if ($country && $value) {
//                            $isValid = Arr::has($states, $value);
//                            if (!$isValid) {
//                                return __('Invalid state code 2.', 'fluent-cart');
//                            }
//                        }
//                    }
//                ]
//            ),
//            $prefix . 'postcode' => array_merge(
//                $defaultPostcodeRoles,
//                $this->getZipCodeValidationRule($country)
//            ),
//        ];
    }

}
