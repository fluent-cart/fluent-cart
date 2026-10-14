<?php

namespace FluentCart\App\Modules\MultiCurrency\Services;

use FluentCart\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base-currency cents <-> presentment-currency cents at a given quote.
 *
 * A quote is {currency, rate, rounding, charm, adjustment, ...}. Amounts
 * that are not prices (shipping, coupon amounts) convert with toQuote():
 * rate, then the currency's smallest unit. Product prices convert with
 * toPrice(): rate, the % adjustment, rounding up to the step, the charm
 * ending (plan §2.5.1, §15.4). Amounts stay ×100 for every currency.
 */
class PriceConverter
{
    /**
     * @param int|float $baseCents
     * @param array $quote {currency, rate, rounding}
     */
    public static function toQuote($baseCents, array $quote): int
    {
        $baseCents = (float) $baseCents;

        if (self::isBaseQuote($quote)) {
            return (int) round($baseCents);
        }

        return RoundingRules::apply(
            $baseCents * (float) $quote['rate'],
            (string) $quote['currency'],
            (string) Arr::get($quote, 'rounding', RoundingRules::NONE)
        );
    }

    /**
     * An amount already in the quote's currency (a share of a converted
     * line) rounded to that currency's smallest unit, as toQuote() rounds.
     *
     * @param int|float $cents
     */
    public static function toQuoteUnit($cents, array $quote): int
    {
        if (self::isBaseQuote($quote)) {
            return (int) round((float) $cents);
        }

        return RoundingRules::apply(
            (float) $cents,
            (string) $quote['currency'],
            (string) Arr::get($quote, 'rounding', RoundingRules::NONE)
        );
    }

    /**
     * The quote currency's smallest unit in stored cents: 100 for a
     * zero-decimal currency, 1 otherwise.
     */
    public static function unit(array $quote): int
    {
        return self::isBaseQuote($quote) ? 1 : RoundingRules::unit((string) $quote['currency']);
    }

    /**
     * A product price (or signup fee) in the quote's currency, with the
     * currency's pricing rules applied.
     *
     * @param int|float $baseCents
     * @param array $quote {currency, rate, rounding, charm, adjustment}
     */
    public static function toPrice($baseCents, array $quote): int
    {
        $baseCents = (float) $baseCents;

        if (self::isBaseQuote($quote)) {
            return (int) round($baseCents);
        }

        return RoundingRules::price(
            $baseCents * (float) $quote['rate'] * self::adjustmentFactor($quote),
            (string) $quote['currency'],
            (string) Arr::get($quote, 'rounding', RoundingRules::NONE),
            (string) Arr::get($quote, 'charm', RoundingRules::NONE)
        );
    }

    /**
     * An amount already in one currency (e.g. a prorate credit the customer
     * paid in GBP) as an amount in another: back to base at $from's rate —
     * the order's frozen rate — then out at $to's. Plain conversion; pricing
     * rules are for product prices, not credits. The result is in $to's
     * smallest unit (whole yen), also when the currency does not change.
     *
     * Null when the amount would have to change currency but $from's rate
     * cannot be trusted (unknown / legacy_unknown, or no usable rate): that
     * rate 1 is a currency label, not a rate, so converting at it would be
     * a 1:1 guess. The caller keeps the amount in its own currency or
     * refuses (CurrencySnapshot::hasTrustedRate(), BaseAmountSql::trustedRate()).
     *
     * @param int|float $cents in $from's currency
     * @param array $from quote or snapshot the amount was priced at
     * @param array $to quote to express it in
     */
    public static function between($cents, array $from, array $to): ?int
    {
        if (strcasecmp((string) Arr::get($from, 'currency'), (string) Arr::get($to, 'currency')) === 0) {
            return self::toQuoteUnit($cents, $to);
        }

        if (!self::canConvertFrom($from) || !self::canConvertFrom($to)) {
            return null;
        }

        $baseCents = BaseCurrency::is(Arr::get($from, 'currency', '')) ? (float) $cents : (float) $cents / (float) $from['rate'];

        return self::toQuote($baseCents, $to);
    }

    /**
     * Whether amounts priced at $quote can move to or from the base
     * currency: the base currency itself, or a foreign one at a real,
     * trusted rate.
     */
    private static function canConvertFrom(array $quote): bool
    {
        if (BaseCurrency::is(Arr::get($quote, 'currency', ''))) {
            return true;
        }

        return CurrencySnapshot::isValidRate(Arr::get($quote, 'rate')) && CurrencySnapshot::hasTrustedRate($quote);
    }

    /**
     * The quote's pricing rules as one comparable string — a cart item priced
     * under other rules is re-priced (plan §11.6).
     */
    public static function rulesKey(array $quote): string
    {
        return implode('|', [
            (string) Arr::get($quote, 'rounding', RoundingRules::NONE),
            (string) Arr::get($quote, 'charm', RoundingRules::NONE),
            MultiCurrencySettings::formatAdjustment(Arr::get($quote, 'adjustment', 0)),
        ]);
    }

    /**
     * Presentment cents back to base cents — for turning a shopper's price
     * filter bound into a base-currency SQL bound. Never used for money that
     * is charged.
     *
     * @param int|float $cents
     */
    public static function toBase($cents, array $quote): int
    {
        if (self::isBaseQuote($quote)) {
            return (int) round((float) $cents);
        }

        return (int) round((float) $cents / ((float) $quote['rate'] * self::adjustmentFactor($quote)));
    }

    private static function adjustmentFactor(array $quote): float
    {
        return 1 + ((float) MultiCurrencySettings::formatAdjustment(Arr::get($quote, 'adjustment', 0)) / 100);
    }

    /**
     * A shopper's price filter bound as the base bound that keeps exactly the
     * products shown inside it. Rounding gives several base prices one shown
     * price, so dividing by the rate cuts some of them off (£39.33 is both
     * $49.78 and $49.79 at 0.79); the price never decreases, so a binary
     * search finds the edge. Bounds are compared with the shown price —
     * toPrice(), with the currency's pricing rules — which never decreases
     * either (rounding up and a fixed charm ending keep the order).
     *
     * @param int|float $cents bound in the quote's currency
     * @param bool $upper true: the highest base price shown at or below $cents;
     *                    false: the lowest base price shown at or above it
     */
    public static function priceBoundToBase($cents, array $quote, bool $upper): int
    {
        $cents = (float) $cents;

        if (self::isBaseQuote($quote)) {
            return (int) round($cents);
        }

        // The first base price past the bound — shown above it (upper) or at
        // or above it (lower)
        $isPast = function ($baseCents) use ($cents, $quote, $upper) {
            $price = self::toPrice($baseCents, $quote);

            return $upper ? $price > $cents : $price >= $cents;
        };

        $low = 0;
        $high = max(1, self::toBase($cents, $quote) * 2);
        for ($tries = 0; !$isPast($high) && $tries < 40; $tries++) {
            $high *= 2;
        }

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($isPast($mid)) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }

        return $upper ? max(0, $low - 1) : $low;
    }

    public static function isBaseQuote(array $quote): bool
    {
        return BaseCurrency::is(Arr::get($quote, 'currency', ''))
            || !CurrencySnapshot::isValidRate(Arr::get($quote, 'rate'));
    }

    public static function baseQuote(): array
    {
        return [
            'currency'  => BaseCurrency::get(),
            'rate'      => CurrencySnapshot::normalizeRate(1),
            'rate_id'   => null,
            'source'    => CurrencySnapshot::SOURCE_BASE,
            'margin'    => '0',
            'rounding'   => RoundingRules::NONE,
            'charm'      => RoundingRules::NONE,
            'adjustment' => '0',
            'locked_at'  => null,
        ];
    }
}
