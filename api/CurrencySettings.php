<?php

namespace FluentCart\Api;

use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\Framework\Support\Arr;

class CurrencySettings
{

    /**
     * @param string $key Store settings option name
     *
     * without param it will return all currency settings | defaults
     *
     *
     * @return array|\ArrayAccess|mixed|null
     */
    public static function get(string $key = '')
    {

        $storeSettings = (new StoreSettings())->get();

        $settings = Arr::only($storeSettings, array(
            'currency',
            'locale',
            'currency_position',
            'currency_separator',
            'decimal_separator',
            'order_mode',
        ));

        $settings = [
            'currency'           => static::getValue($settings, 'currency', 'USD'),
            'locale'             => static::getValue($settings, 'locale', 'auto'),
            'currency_position'  => static::getValue($settings, 'currency_position', 'left'),
            'currency_separator' => static::getValue($settings, 'currency_separator', 'dot'),
            'decimal_separator'  => static::getValue($settings, 'decimal_separator', '.'),
            'decimal_points'     => static::getValue($settings, 'decimal_points', 0),
            'settings_type'      => static::getValue($settings, 'settings_type', 'global'),
            'order_mode'         => static::getValue($settings, 'order_mode', 'test')
        ];

        $settings['is_zero_decimal'] = CurrenciesHelper::isZeroDecimal($settings['currency']);
        $settings['currency_sign'] = html_entity_decode(CurrenciesHelper::getCurrencySign($settings['currency']));

        if ($key) {
            return Arr::get($settings, $key);
        }

        return apply_filters('fluent_cart/global_currency_setting', $settings, []);
    }

    public function getCurrency($settings = [])
    {
        return static::get('currency');
    }

    public static function getValue(array $settings, $key, $default = null)
    {
        $value = Arr::get($settings, $key, $default);
        if (empty($value)) {
            return $default;
        }
        return $value;
    }

    /**
     * @param string $currencyCode like 'USD'
     * @return bool
     *
     * check if currency is zero decimal
     */
    public function isZeroDecimal($currencyCode)
    {
        return CurrenciesHelper::isZeroDecimal($currencyCode);
    }

    public static function convertCentToUnit($amount)
    {
        $unitAmount = 0;
        if (intval($amount)) {
            $unitAmount = intval($amount) / 100;
        }
        return $unitAmount;
    }

    /**
     * @return array
     *
     * Get label value array for all currencies
     */
    public static function getFormattedCurrencies()
    {
        $currencies = CurrenciesHelper::getCurrencies();
        $formatted = array();
        foreach ($currencies as $code => $name) {
            $formatted[] = array(
                "label" => $name,
                "value" => $code,
            );
        }
        return $formatted;
    }

    public static function getPriceHtml($amount, $currencyCode = null, $showDecimal = true)
    {
        return static::getFormattedPrice($amount, $currencyCode, false, $showDecimal);
    }

    public static function getFormattedPrice($amount, $currencyCode = null, $asList = false, $showDecimal = true)
    {
        $settings = static::get();
        $decimal = $showDecimal ? 2 : 0;
        $decimalSeparatorSetting = Arr::get($settings, 'decimal_separator', 'dot');
        $decimeter = $decimalSeparatorSetting === 'dot' ? '.' : ',';
        // Thousand separator is the opposite of decimal separator
        $separator = $decimalSeparatorSetting === 'dot' ? ',' : '.';
        $position = Arr::get($settings, 'currency_position', 'before');

        if (!$currencyCode) {
            $currencyCode = Arr::get($settings, 'currency');
        }


        $sign = CurrenciesHelper::getCurrencySign($currencyCode);
        $amount = is_numeric($amount) ? $amount : 0;

        if (!CurrenciesHelper::isZeroDecimal($sign)) {
            $amount = $amount / 100;
        };

        if ($settings['is_zero_decimal']) {
            $decimal = 0;
        }

        $price = number_format($amount, $decimal, $decimeter, $separator);

        if ($asList) {
            return [
                'price'          => $price,
                'currency_sign'  => $sign,
                'currency_code'  => $currencyCode,
                'currency_title' => CurrenciesHelper::getCurrencies($currencyCode),
                'position'       => $position
            ];
        }

        return $position === 'after' ? $price . $sign : $sign . $price;
    }
}
