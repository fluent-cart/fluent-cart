<?php

namespace FluentCart\App\Modules\MultiCurrency\Services;

use FluentCart\Api\StoreSettings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The store's base (accounting) currency — the one currency the books are kept
 * in and product prices are authored in.
 *
 * Deliberately NOT read through CurrencySettings::get(): that path carries the
 * fluent_cart/global_currency_setting filter, which a storefront may use to
 * present a different currency per request. The base currency must never vary
 * by visitor. dev-docs/multi-currency/plan.md §12.1.
 */
class BaseCurrency
{
    public static function get(): string
    {
        $currency = (new StoreSettings())->get('currency', 'USD');

        return strtoupper(trim((string) $currency)) ?: 'USD';
    }

    public static function is($currency): bool
    {
        return strcasecmp(trim((string) $currency), self::get()) === 0;
    }
}
