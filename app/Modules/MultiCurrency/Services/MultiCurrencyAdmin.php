<?php

namespace FluentCart\App\Modules\MultiCurrency\Services;

use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Services\Money;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Data the admin app needs for multi-currency outside the settings screen.
 */
class MultiCurrencyAdmin
{
    /**
     * Currencies an admin-created order can be priced in: the base, then each
     * enabled currency with its current rate and pricing rules. Empty when
     * multi-currency is off.
     *
     * @return array<int, array{code: string, sign: string, rate: string, is_base: bool, rounding?: string, charm?: string, adjustment?: string, decimals?: int}>
     */
    public static function orderCurrencies(): array
    {
        if (!MultiCurrencySettings::isEnabled()) {
            return [];
        }

        $base = BaseCurrency::get();
        $list = [[
            'code'    => $base,
            'sign'    => html_entity_decode(CurrenciesHelper::getCurrencySign($base)),
            'rate'    => CurrencySnapshot::normalizeRate(1),
            'is_base' => true,
        ]];

        foreach (MultiCurrencySettings::currencyCodes() as $code) {
            $quote = CartCurrency::freshQuote($code);
            if (!$quote) {
                continue;
            }
            $list[] = [
                'code'       => $code,
                'sign'       => html_entity_decode(CurrenciesHelper::getCurrencySign($code)),
                'rate'       => $quote['rate'],
                'is_base'    => false,
                'rounding'   => $quote['rounding'],
                'charm'      => $quote['charm'],
                'adjustment' => $quote['adjustment'],
                'decimals'   => (int) Money::getDecimals($code),
            ];
        }

        return count($list) > 1 ? $list : [];
    }
}
