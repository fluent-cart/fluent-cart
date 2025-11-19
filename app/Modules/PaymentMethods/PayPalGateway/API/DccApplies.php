<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway\API;

class DccApplies
{
    protected string $country;
    protected string $currency;
    protected array $allowedCountryCurrencyMatrix;
    protected array $countryCardMatrix;

    /**
     * @throws \Exception
     */
    public function __construct($country, $currency)
    {
        $this->country = $country;
        $this->currency = $currency;
        if (!$this->country || !$this->currency) {
            throw new \Exception('Please set store country and currency first!');
        }
        $this->allowedCountryCurrencyMatrix = self::dccSupportedCountryCurrencyMatrix();
        $this->countryCardMatrix = self::countryCardMatrix();
    }

    public function forCountryCurrency(): bool
    {
        if (!in_array($this->country, array_keys($this->allowedCountryCurrencyMatrix), \true)) {
            return \false;
        }

        return in_array($this->currency, $this->allowedCountryCurrencyMatrix[$this->country], \true);
    }

    public static function countryCardMatrix(): array
    {
        $mastercardVisaAmex = [
            'mastercard' => [],
            'visa' => [],
            'amex' => [],
        ];

        /**
         * Empty credit card arrays mean no restriction on currency.
         * return supported cards currency
         */
        return [
            'AU' => [
                'mastercard' => [],
                'visa' => [],
                'amex' => ['AUD'],
            ],
            'AT' => $mastercardVisaAmex,
            'BE' => $mastercardVisaAmex,
            'BG' => $mastercardVisaAmex,
            'CN' => [
                'mastercard' => [],
                'visa' => [],
            ],
            'CY' => $mastercardVisaAmex,
            'CZ' => $mastercardVisaAmex,
            'DE' => $mastercardVisaAmex,
            'DK' => $mastercardVisaAmex,
            'EE' => $mastercardVisaAmex,
            'ES' => $mastercardVisaAmex,
            'FI' => $mastercardVisaAmex,
            'FR' => $mastercardVisaAmex,
            'GB' => $mastercardVisaAmex,
            'GR' => $mastercardVisaAmex,
            'HK' => $mastercardVisaAmex,
            'HU' => $mastercardVisaAmex,
            'IE' => $mastercardVisaAmex,
            'IT' => $mastercardVisaAmex,
            'US' => [
                'mastercard' => [],
                'visa' => [],
                'amex' => ['USD'],
                'discover' => ['USD'],
            ],
            'CA' => [
                'mastercard' => [],
                'visa' => [],
                'amex' => ['CAD', 'USD'],
                'jcb' => ['CAD'],
            ],
            'LI' => $mastercardVisaAmex,
            'LT' => $mastercardVisaAmex,
            'LU' => $mastercardVisaAmex,
            'LV' => $mastercardVisaAmex,
            'MT' => $mastercardVisaAmex,
            'MX' => $mastercardVisaAmex,
            'NL' => $mastercardVisaAmex,
            'NO' => $mastercardVisaAmex,
            'PL' => $mastercardVisaAmex,
            'PT' => $mastercardVisaAmex,
            'RO' => $mastercardVisaAmex,
            'SE' => $mastercardVisaAmex,
            'SI' => $mastercardVisaAmex,
            'SK' => $mastercardVisaAmex,
            'SG' => $mastercardVisaAmex,
            'JP' => [
                'mastercard' => [],
                'visa' => [],
                'amex' => ['JPY'],
                'jcb' => ['JPY'],
            ],
        ];
    }

    public static function dccSupportedCountryCurrencyMatrix(): array
    {
        $defaultCurrencies = array('AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'HKD', 'GBP', 'HUF', 'ILS', 'JPY', 'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'SGD', 'SEK', 'THB', 'TWD', 'USD');

        // Returns which countries and currency combinations can be used for DCC
        return [
            'AU' => $defaultCurrencies,
            'AT' => $defaultCurrencies,
            'BE' => $defaultCurrencies,
            'BG' => $defaultCurrencies,
            'CA' => $defaultCurrencies,
            'CN' => $defaultCurrencies,
            'CY' => $defaultCurrencies,
            'CZ' => $defaultCurrencies,
            'DK' => $defaultCurrencies,
            'EE' => $defaultCurrencies,
            'FI' => $defaultCurrencies,
            'FR' => $defaultCurrencies,
            'DE' => $defaultCurrencies,
            'GR' => $defaultCurrencies,
            'HK' => $defaultCurrencies,
            'HU' => $defaultCurrencies,
            'IE' => $defaultCurrencies,
            'IT' => $defaultCurrencies,
            'JP' => $defaultCurrencies,
            'LV' => $defaultCurrencies,
            'LI' => $defaultCurrencies,
            'LT' => $defaultCurrencies,
            'LU' => $defaultCurrencies,
            'MT' => $defaultCurrencies,
            'MX' => ['MXN'],
            'NL' => $defaultCurrencies,
            'PL' => $defaultCurrencies,
            'PT' => $defaultCurrencies,
            'RO' => $defaultCurrencies,
            'SK' => $defaultCurrencies,
            'SG' => $defaultCurrencies,
            'SI' => $defaultCurrencies,
            'ES' => $defaultCurrencies,
            'SE' => $defaultCurrencies,
            'GB' => $defaultCurrencies,
            'US' => $defaultCurrencies,
            'NO' => $defaultCurrencies,
        ];

    }
}