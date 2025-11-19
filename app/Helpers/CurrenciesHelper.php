<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\CurrencySettings;
use FLuentCart\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}


class CurrenciesHelper
{
    /**
     * https://support.stripe.com/questions/which-currencies-does-stripe-support
     */
    public static function getCurrencies($code = null)
    {
        $currencies = apply_filters('fluent_cart/accepted_currencies', array(
            'AED' => __('United Arab Emirates Dirham', 'fluent-cart'),
            'AFN' => __('Afghan Afghani', 'fluent-cart'),
            'ALL' => __('Albanian Lek', 'fluent-cart'),
            'AMD' => __('Armenian Dram', 'fluent-cart'),
            'ANG' => __('Netherlands Antillean Gulden', 'fluent-cart'),
            'AOA' => __('Angolan Kwanza', 'fluent-cart'),
            'ARS' => __('Argentine Peso', 'fluent-cart'), // non amex
            'AUD' => __('Australian Dollar', 'fluent-cart'),
            'AWG' => __('Aruban Florin', 'fluent-cart'),
            'AZN' => __('Azerbaijani Manat', 'fluent-cart'),
            'BAM' => __('Bosnia & Herzegovina Convertible Mark', 'fluent-cart'),
            'BBD' => __('Barbadian Dollar', 'fluent-cart'),
            'BDT' => __('Bangladeshi Taka', 'fluent-cart'),
            'BIF' => __('Burundian Franc', 'fluent-cart'),
            'BGN' => __('Bulgarian Lev', 'fluent-cart'),
            'BMD' => __('Bermudian Dollar', 'fluent-cart'),
            'BND' => __('Brunei Dollar', 'fluent-cart'),
            'BOB' => __('Bolivian Boliviano', 'fluent-cart'),
            'BRL' => __('Brazilian Real', 'fluent-cart'),
            'BSD' => __('Bahamian Dollar', 'fluent-cart'),
            'BWP' => __('Botswana Pula', 'fluent-cart'),
            'BYN' => __('Belarusian Ruble', 'fluent-cart'),
            'BZD' => __('Belize Dollar', 'fluent-cart'),
            'CAD' => __('Canadian Dollar', 'fluent-cart'),
            'CDF' => __('Congolese Franc', 'fluent-cart'),
            'CHF' => __('Swiss Franc', 'fluent-cart'),
            'CLP' => __('Chilean Peso', 'fluent-cart'),
            'CNY' => __('Chinese Renminbi Yuan', 'fluent-cart'),
            'COP' => __('Colombian Peso', 'fluent-cart'),
            'CRC' => __('Costa Rican Colón', 'fluent-cart'),
            'CVE' => __('Cape Verdean Escudo', 'fluent-cart'),
            'CZK' => __('Czech Koruna', 'fluent-cart'),
            'DJF' => __('Djiboutian Franc', 'fluent-cart'),
            'DKK' => __('Danish Krone', 'fluent-cart'),
            'DOP' => __('Dominican Peso', 'fluent-cart'),
            'DZD' => __('Algerian Dinar', 'fluent-cart'),
            'EGP' => __('Egyptian Pound', 'fluent-cart'),
            'ETB' => __('Ethiopian Birr', 'fluent-cart'),
            'EUR' => __('Euro', 'fluent-cart'),
            'FJD' => __('Fijian Dollar', 'fluent-cart'),
            'FKP' => __('Falkland Islands Pound', 'fluent-cart'),
            'GBP' => __('British Pound', 'fluent-cart'),
            'GEL' => __('Georgian Lari', 'fluent-cart'),
            'GIP' => __('Gibraltar Pound', 'fluent-cart'),
            'GMD' => __('Gambian Dalasi', 'fluent-cart'),
            'GNF' => __('Guinean Franc', 'fluent-cart'),
            'GTQ' => __('Guatemalan Quetzal', 'fluent-cart'),
            'GYD' => __('Guyanese Dollar', 'fluent-cart'),
            'HKD' => __('Hong Kong Dollar', 'fluent-cart'),
            'HNL' => __('Honduran Lempira', 'fluent-cart'),
            'HRK' => __('Croatian Kuna', 'fluent-cart'),
            'HTG' => __('Haitian Gourde', 'fluent-cart'),
            'HUF' => __('Hungarian Forint', 'fluent-cart'),
            'IDR' => __('Indonesian Rupiah', 'fluent-cart'),
            'ILS' => __('Israeli New Sheqel', 'fluent-cart'),
            'INR' => __('Indian Rupee', 'fluent-cart'),
            'IRR'  => __('Iranian Rial', 'fluent-cart'),
            'ISK' => __('Icelandic Króna', 'fluent-cart'),
            'JMD' => __('Jamaican Dollar', 'fluent-cart'),
            'JPY' => __('Japanese Yen', 'fluent-cart'),
            'KES' => __('Kenyan Shilling', 'fluent-cart'),
            'KGS' => __('Kyrgyzstani Som', 'fluent-cart'),
            'KHR' => __('Cambodian Riel', 'fluent-cart'),
            'KMF' => __('Comorian Franc', 'fluent-cart'),
            'KRW' => __('South Korean Won', 'fluent-cart'),
            'KYD' => __('Cayman Islands Dollar', 'fluent-cart'),
            'KZT' => __('Kazakhstani Tenge', 'fluent-cart'),
            'LAK' => __('Lao Kip', 'fluent-cart'),
            'LBP' => __('Lebanese Pound', 'fluent-cart'),
            'LKR' => 'Sri Lankan Rupee',
            'LRD' => __('Liberian Dollar', 'fluent-cart'),
            'LSL' => __('Lesotho Loti', 'fluent-cart'),
            'MAD' => __('Moroccan Dirham', 'fluent-cart'),
            'MDL' => __('Moldovan Leu', 'fluent-cart'),
            'MGA' => __('Malagasy Ariary', 'fluent-cart'),
            'MKD' => __('Macedonian Denar', 'fluent-cart'),
            'MMK' => __('Myanmar Kyat', 'fluent-cart'),
            'MNT' => __('Mongolian Tögrög', 'fluent-cart'),
            'MOP' => __('Macanese Pataca', 'fluent-cart'),
            'MRO' => __('Mauritanian Ouguiya', 'fluent-cart'),
            'MUR' => __('Mauritian Rupee', 'fluent-cart'),
            'MVR' => __('Maldivian Rufiyaa', 'fluent-cart'),
            'MWK' => __('Malawian Kwacha', 'fluent-cart'),
            'MXN' => __('Mexican Peso', 'fluent-cart'),
            'MYR' => __('Malaysian Ringgit', 'fluent-cart'),
            'MZN' => __('Mozambican Metical', 'fluent-cart'),
            'NAD' => __('Namibian Dollar', 'fluent-cart'),
            'NGN' => __('Nigerian Naira', 'fluent-cart'),
            'NIO' => __('Nicaraguan Córdoba', 'fluent-cart'),
            'NOK' => __('Norwegian Krone', 'fluent-cart'),
            'NPR' => __('Nepalese Rupee', 'fluent-cart'),
            'NZD' => __('New Zealand Dollar', 'fluent-cart'),
            'PAB' => __('Panamanian Balboa', 'fluent-cart'),
            'PEN' => __('Peruvian Nuevo Sol', 'fluent-cart'),
            'PGK' => __('Papua New Guinean Kina', 'fluent-cart'),
            'PHP' => __('Philippine Peso', 'fluent-cart'),
            'PKR' => __('Pakistani Rupee', 'fluent-cart'),
            'PLN' => __('Polish Złoty', 'fluent-cart'),
            'PYG' => __('Paraguayan Guaraní', 'fluent-cart'),
            'QAR' => __('Qatari Riyal', 'fluent-cart'),
            'RON' => __('Romanian Leu', 'fluent-cart'),
            'RSD' => __('Serbian Dinar', 'fluent-cart'),
            'RUB' => __('Russian Ruble', 'fluent-cart'),
            'RWF' => __('Rwandan Franc', 'fluent-cart'),
            'SAR' => __('Saudi Riyal', 'fluent-cart'),
            'SBD' => __('Solomon Islands Dollar', 'fluent-cart'),
            'SCR' => __('Seychellois Rupee', 'fluent-cart'),
            'SEK' => __('Swedish Krona', 'fluent-cart'),
            'SGD' => __('Singapore Dollar', 'fluent-cart'),
            'SHP' => __('Saint Helenian Pound', 'fluent-cart'),
            'SLL' => __('Sierra Leonean Leone', 'fluent-cart'),
            'SOS' => __('Somali Shilling', 'fluent-cart'),
            'SRD' => __('Surinamese Dollar', 'fluent-cart'),
            'STD' => __('São Tomé and Príncipe Dobra', 'fluent-cart'),
            'SVC' => __('Salvadoran Colón', 'fluent-cart'),
            'SZL' => __('Swazi Lilangeni', 'fluent-cart'),
            'THB' => __('Thai Baht', 'fluent-cart'),
            'TJS' => __('Tajikistani Somoni', 'fluent-cart'),
            'TOP' => __('Tongan Paʻanga', 'fluent-cart'),
            'TRY' => __('Turkish Lira', 'fluent-cart'),
            'TTD' => __('Trinidad and Tobago Dollar', 'fluent-cart'),
            'TWD' => __('New Taiwan Dollar', 'fluent-cart'),
            'TZS' => __('Tanzanian Shilling', 'fluent-cart'),
            'UAH' => __('Ukrainian Hryvnia', 'fluent-cart'),
            'UGX' => __('Ugandan Shilling', 'fluent-cart'),
            'USD' => __('United States Dollar', 'fluent-cart'),
            'UYU' => __('Uruguayan Peso', 'fluent-cart'),
            'UZS' => __('Uzbekistani Som', 'fluent-cart'),
            'VND' => __('Vietnamese Đồng', 'fluent-cart'),
            'VUV' => __('Vanuatu Vatu', 'fluent-cart'),
            'WST' => __('Samoan Tala', 'fluent-cart'),
            'XAF' => __('Central African Cfa Franc', 'fluent-cart'),
            'XCD' => __('East Caribbean Dollar', 'fluent-cart'),
            'XOF' => __('West African Cfa Franc', 'fluent-cart'),
            'XPF' => __('Cfp Franc', 'fluent-cart'),
            'YER' => __('Yemeni Rial', 'fluent-cart'),
            'ZAR' => __('South African Rand', 'fluent-cart'),
            'ZMW' => __('Zambian Kwacha', 'fluent-cart'),
        ), []);

        if ($code) {
            return isset($currencies[$code]) ? $currencies[$code] : '';
        }

        return $currencies;
    }

    /**
     * Get the available locales that Stripe can use
     *
     * @return array
     */
    public static function getLocales()
    {
        return array(
            ''     => 'English (en) (default)',
            'auto' => 'Auto-detect locale',
            'zh'   => 'Simplified Chinese (zh)',
            'da'   => 'Danish (da)',
            'nl'   => 'Dutch (nl)',
            'fi'   => 'Finnish (fi)',
            'fr'   => 'French (fr)',
            'de'   => 'German (de)',
            'it'   => 'Italian (it)',
            'ja'   => 'Japanese (ja)',
            'no'   => 'Norwegian (no)',
            'es'   => 'Spanish (es)',
            'sv'   => 'Swedish (sv)',
        );
    }

    public static function getCurrencySigns()
    {
        return apply_filters('fluent_cart/global_currency_symbols', [
			'AED' => '&#x62f;.&#x625;',
			'AFN' => '&#x60b;',
			'ALL' => 'L',
			'AMD' => 'AMD',
			'ANG' => '&fnof;',
			'AOA' => 'Kz',
			'ARS' => '&#36;',
			'AUD' => '&#36;',
			'AWG' => 'Afl.',
			'AZN' => '&#8380;',
			'BAM' => 'KM',
			'BBD' => '&#36;',
			'BDT' => '&#2547;&nbsp;',
			'BGN' => '&#1083;&#1074;.',
			'BHD' => '.&#x62f;.&#x628;',
			'BIF' => 'Fr',
			'BMD' => '&#36;',
			'BND' => '&#36;',
			'BOB' => 'Bs.',
			'BRL' => '&#82;&#36;',
			'BSD' => '&#36;',
			'BTC' => '&#3647;',
			'BTN' => 'Nu.',
			'BWP' => 'P',
			'BYR' => 'Br',
			'BYN' => 'Br',
			'BZD' => '&#36;',
			'CAD' => '&#36;',
			'CDF' => 'Fr',
			'CHF' => '&#67;&#72;&#70;',
			'CLP' => '&#36;',
			'CNY' => '&yen;',
			'COP' => '&#36;',
			'CRC' => '&#x20a1;',
			'CUC' => '&#36;',
			'CUP' => '&#36;',
			'CVE' => '&#36;',
			'CZK' => '&#75;&#269;',
			'DJF' => 'Fr',
			'DKK' => 'kr.',
			'DOP' => 'RD&#36;',
			'DZD' => '&#x62f;.&#x62c;',
			'EGP' => 'EGP',
			'ERN' => 'Nfk',
			'ETB' => 'Br',
			'EUR' => '&euro;',
			'FJD' => '&#36;',
			'FKP' => '&pound;',
			'GBP' => '&pound;',
			'GEL' => '&#x20be;',
			'GGP' => '&pound;',
			'GHS' => '&#x20b5;',
			'GIP' => '&pound;',
			'GMD' => 'D',
			'GNF' => 'Fr',
			'GTQ' => 'Q',
			'GYD' => '&#36;',
			'HKD' => '&#36;',
			'HNL' => 'L',
			'HRK' => 'kn',
			'HTG' => 'G',
			'HUF' => '&#70;&#116;',
			'IDR' => 'Rp',
			'ILS' => '&#8362;',
			'IMP' => '&pound;',
			'INR' => '&#8377;',
			'IQD' => '&#x62f;.&#x639;',
			'IRR' => '&#xfdfc;',
			'IRT' => '&#x062A;&#x0648;&#x0645;&#x0627;&#x0646;',
			'ISK' => 'kr.',
			'JEP' => '&pound;',
			'JMD' => '&#36;',
			'JOD' => '&#x62f;.&#x627;',
			'JPY' => '&yen;',
			'KES' => 'KSh',
			'KGS' => '&#x441;&#x43e;&#x43c;',
			'KHR' => '&#x17db;',
			'KMF' => 'Fr',
			'KPW' => '&#x20a9;',
			'KRW' => '&#8361;',
			'KWD' => '&#x62f;.&#x643;',
			'KYD' => '&#36;',
			'KZT' => '&#8376;',
			'LAK' => '&#8365;',
			'LBP' => '&#x644;.&#x644;',
			'LKR' => '&#xdbb;&#xdd4;',
			'LRD' => '&#36;',
			'LSL' => 'L',
			'LYD' => '&#x62f;.&#x644;',
			'MAD' => '&#x62f;.&#x645;.',
			'MDL' => 'MDL',
			'MGA' => 'Ar',
			'MKD' => '&#x434;&#x435;&#x43d;',
			'MMK' => 'Ks',
			'MNT' => '&#x20ae;',
			'MOP' => 'P',
			'MRU' => 'UM',
			'MUR' => '&#x20a8;',
			'MVR' => '.&#x783;',
			'MWK' => 'MK',
			'MXN' => '&#36;',
			'MYR' => '&#82;&#77;',
			'MZN' => 'MT',
			'NAD' => 'N&#36;',
			'NGN' => '&#8358;',
			'NIO' => 'C&#36;',
			'NOK' => '&#107;&#114;',
			'NPR' => '&#8360;',
			'NZD' => '&#36;',
			'OMR' => '&#x631;.&#x639;.',
			'PAB' => 'B/.',
			'PEN' => 'S/',
			'PGK' => 'K',
			'PHP' => '&#8369;',
			'PKR' => '&#8360;',
			'PLN' => '&#122;&#322;',
			'PRB' => '&#x440;.',
			'PYG' => '&#8370;',
			'QAR' => '&#x631;.&#x642;',
			'RMB' => '&yen;',
			'RON' => 'lei',
			'RSD' => '&#1088;&#1089;&#1076;',
			'RUB' => '&#8381;',
			'RWF' => 'Fr',
			'SAR' => '&#x631;.&#x633;',
			'SBD' => '&#36;',
			'SCR' => '&#x20a8;',
			'SDG' => '&#x62c;.&#x633;.',
			'SEK' => '&#107;&#114;',
			'SGD' => '&#36;',
			'SHP' => '&pound;',
			'SLL' => 'Le',
			'SOS' => 'Sh',
			'SRD' => '&#36;',
			'SSP' => '&pound;',
			'STN' => 'Db',
			'SYP' => '&#x644;.&#x633;',
			'SZL' => 'E',
			'THB' => '&#3647;',
			'TJS' => '&#x405;&#x41c;',
			'TMT' => 'm',
			'TND' => '&#x62f;.&#x62a;',
			'TOP' => 'T&#36;',
			'TRY' => '&#8378;',
			'TTD' => '&#36;',
			'TWD' => '&#78;&#84;&#36;',
			'TZS' => 'Sh',
			'UAH' => '&#8372;',
			'UGX' => 'UGX',
			'USD' => '&#36;',
			'UYU' => '&#36;',
			'UZS' => 'UZS',
			'VEF' => 'Bs F',
			'VES' => 'Bs.',
			'VND' => '&#8363;',
			'VUV' => 'Vt',
			'WST' => 'T',
			'XAF' => 'CFA',
			'XCD' => '&#36;',
			'XOF' => 'CFA',
			'XPF' => 'XPF',
			'YER' => '&#xfdfc;',
			'ZAR' => '&#82;',
			'ZMW' => 'ZK',
		], []);
    }

    public static function getCurrencySign($currency = 'USD')
    {
        $currency = strtoupper($currency);
        $symbols = static::getCurrencySigns();
        return $symbols[$currency] ?? '';
    }

    public static function getCurrencyWithSign($currencies = [])
    {
        $currencyWithSign = [];

        foreach ($currencies as $currency) {
            $currencyWithSign[$currency] = self::getCurrencySign($currency);
        }

        return $currencyWithSign;
    }

    public static function zeroDecimalCurrencies()
    {
        return apply_filters('fluent_cart/zero_decimal_currencies', array(
            'BIF' => esc_html__('Burundian Franc', 'fluent-cart'),
            'CLP' => esc_html__('Chilean Peso', 'fluent-cart'),
            'DJF' => esc_html__('Djiboutian Franc', 'fluent-cart'),
            'GNF' => esc_html__('Guinean Franc', 'fluent-cart'),
            'JPY' => esc_html__('Japanese Yen', 'fluent-cart'),
            'KMF' => esc_html__('Comorian Franc', 'fluent-cart'),
            'KRW' => esc_html__('South Korean Won', 'fluent-cart'),
            'MGA' => esc_html__('Malagasy Ariary', 'fluent-cart'),
            'PYG' => esc_html__('Paraguayan Guaraní', 'fluent-cart'),
            'RWF' => esc_html__('Rwandan Franc', 'fluent-cart'),
            'VND' => esc_html__('Vietnamese Dong', 'fluent-cart'),
            'VUV' => esc_html__('Vanuatu Vatu', 'fluent-cart'),
            'XAF' => esc_html__('Central African Cfa Franc', 'fluent-cart'),
            'XOF' => esc_html__('West African Cfa Franc', 'fluent-cart'),
            'XPF' => esc_html__('Cfp Franc', 'fluent-cart'),
        ), []);
    }

    public static function isZeroDecimal($currencyCode): bool
    {
        $currencyCode = strtoupper($currencyCode);
        $zeroDecimals = self::zeroDecimalCurrencies();
        return isset($zeroDecimals[$currencyCode]);
    }

    public static function updateCurrencyArray($oldCurrencies = [], $amount = 0, $currency = ''): array
    {
        if (!Arr::exists($oldCurrencies, $currency)) {
            $oldCurrencies[$currency] = [
                'currency' => $currency,
                'total'    => 0,
                'count'    => 0,
                'sign'     => '$'
            ];
        }

        $oldCurrencies[$currency]['total'] += $amount;
        $oldCurrencies[$currency]['count']++;
        $oldCurrencies[$currency]['sign'] = static::getCurrencySign($currency);

        return $oldCurrencies;
    }

    public static function centsToDecimal($cents, $currency = null)
    {

        if (!$currency) {
            $currency = CurrencySettings::get('currency');
        }

        if (self::isZeroDecimal($currency)) {
            return $cents;
        }

        return number_format($cents / 100, 2, '.', '');
    }
}
