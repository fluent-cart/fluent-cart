<?php

namespace FluentCart\App\Modules\MultiCurrency\Services;

use FluentCart\App\Helpers\Helper;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Catalogue prices for display. Product prices are stored once, in the base
 * currency; every storefront read that shows one goes through here so the
 * shown price equals the price the cart will charge (plan §12.2).
 * Identity whenever the visitor is in the base currency.
 */
class StorefrontPrice
{
    /**
     * A product price in the visitor's currency, with the currency's pricing
     * rules (adjustment, rounding, charm ending) applied.
     *
     * @param int|float|string $baseCents
     */
    public static function amount($baseCents): int
    {
        if (!is_numeric($baseCents)) {
            return 0;
        }

        return PriceConverter::toPrice($baseCents, CartCurrency::currentQuote());
    }

    /**
     * An amount that is not a product price — shipping, a fee — in the
     * visitor's currency: converted, rounded to the smallest unit only.
     *
     * @param int|float|string $baseCents
     */
    public static function fee($baseCents): int
    {
        if (!is_numeric($baseCents)) {
            return 0;
        }

        return PriceConverter::toQuote($baseCents, CartCurrency::currentQuote());
    }

    /**
     * Formatted price in the visitor's currency.
     *
     * @param int|float|string $baseCents
     */
    public static function format($baseCents, bool $withCurrency = true): string
    {
        return (string) Helper::toDecimal(self::amount($baseCents), $withCurrency, CurrencyContext::current());
    }

    /**
     * A shopper-entered price range (presentment cents) as the base-currency
     * range for SQL: every product shown inside it, and no other.
     *
     * @param int|float|string $fromCents
     * @param int|float|string $toCents
     * @return int[] [from, to] in base cents
     */
    public static function rangeToBase($fromCents, $toCents): array
    {
        $quote = CartCurrency::currentQuote();

        return [
            PriceConverter::priceBoundToBase($fromCents, $quote, false),
            PriceConverter::priceBoundToBase($toCents, $quote, true),
        ];
    }
}
