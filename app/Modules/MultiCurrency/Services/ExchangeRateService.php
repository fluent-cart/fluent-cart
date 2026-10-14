<?php

namespace FluentCart\App\Modules\MultiCurrency\Services;

use FluentCart\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the exchange rate base -> quote. Core stores no rates: FluentCart
 * Pro keeps them and answers fluent_cart/currency/exchange_rate.
 *
 * `rate` = units of the quote (presentment) currency per 1 unit of base.
 */
class ExchangeRateService
{
    /**
     * The rate to price in $quote right now, margin applied, or null when
     * there is none — callers must refuse the currency, never fall back to 1.
     *
     * @param string $quote presentment currency
     * @param array $context free-form (e.g. ['context' => 'cart'])
     * @return array|null {rate: string, rate_id: int|null, source: string, margin: string, effective_at: string|null}
     */
    public static function current($quote, array $context = []): ?array
    {
        $quote = strtoupper((string) $quote);
        $base = BaseCurrency::get();

        if ($quote === $base) {
            return [
                'rate'         => CurrencySnapshot::normalizeRate(1),
                'rate_id'      => null,
                'source'       => CurrencySnapshot::SOURCE_BASE,
                'margin'       => '0',
                'effective_at' => null,
            ];
        }

        /**
         * The raw rate before margin, or null for none. Pro answers from its
         * stored rates at priority 5; a live feed may supply or replace it.
         *
         * @param array|null $rate {rate, rate_id, source, margin, effective_at}
         * @param array $context base, quote, context
         */
        $rate = apply_filters('fluent_cart/currency/exchange_rate', null, [
            'base'    => $base,
            'quote'   => $quote,
            'context' => $context,
        ]);

        if (!is_array($rate) || !CurrencySnapshot::isValidRate(Arr::get($rate, 'rate'))) {
            return null;
        }

        // Merchant markup on the raw rate, in percent. The frozen order rate
        // is the rate after margin — the rate the customer actually paid.
        // Default: the store's margin on automated rates while a feed is
        // selected; none on manual ones. In manual mode the margin field is
        // hidden, so a leftover feed row must not be marked up unseen.
        if (Arr::get($rate, 'source') === CurrencySnapshot::SOURCE_MANUAL) {
            $defaultMargin = (float) Arr::get($rate, 'margin', 0);
        } elseif (MultiCurrencySettings::rateProvider() === CurrencySnapshot::SOURCE_MANUAL) {
            $defaultMargin = 0.0;
        } else {
            $defaultMargin = MultiCurrencySettings::rateMargin();
        }
        $margin = (float) apply_filters('fluent_cart/currency/fx_margin', $defaultMargin, [
            'base'  => $base,
            'quote' => $quote,
        ]);
        if ($margin) {
            $rate['rate'] = CurrencySnapshot::normalizeRate((float) $rate['rate'] * (1 + $margin / 100));
        }
        // A filtered rate or margin can round to zero or overflow DECIMAL(24,12)
        $storable = CurrencySnapshot::storableRate($rate['rate']);
        if ($storable === null) {
            return null;
        }

        $rate['margin'] = (string) $margin;
        $rate['rate'] = $storable;

        return $rate;
    }

    /**
     * Ask the rate store to load many quotes at once, so the current() calls
     * that follow read its cache instead of one query each.
     *
     * @param string[] $quotes
     */
    public static function primeRates(array $quotes): void
    {
        if (!$quotes) {
            return;
        }

        do_action('fluent_cart/currency/prime_rates', [
            'base'   => BaseCurrency::get(),
            'quotes' => array_values($quotes),
        ]);
    }

    /**
     * Drop every cached rate, e.g. after a new rate is recorded.
     */
    public static function flushCache(): void
    {
        do_action('fluent_cart/currency/rates_flushed', []);
    }
}
