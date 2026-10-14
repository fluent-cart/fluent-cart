<?php

namespace FluentCart\App\Modules\MultiCurrency\Services;

use FluentCart\App\App;
use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\Money;
use FluentCart\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Multi-currency configuration: which presentment currencies are enabled and
 * how each one's converted prices are rounded. Configuration only — rates
 * are stored by FluentCart Pro and read through ExchangeRateService::current().
 */
class MultiCurrencySettings
{
    const OPTION_KEY = 'multi_currency_settings';

    const DEFAULT_RATE_LOCK_MINUTES = 0;

    /** @var array|null */
    private static $cache = null;

    public static function defaults(): array
    {
        return [
            'enabled'           => 'no',
            'currencies'        => [],
            'rate_lock_minutes' => self::DEFAULT_RATE_LOCK_MINUTES,
            'rate_provider'     => CurrencySnapshot::SOURCE_MANUAL,
            'rate_margin'       => 0,
        ];
    }

    /**
     * Markup (percent) added to automated rates — feeds publish mid-market
     * rates with no spread, while the merchant settles at their gateway's
     * rate. Manual rates are the merchant's own number and get no markup.
     */
    public static function rateMargin(): float
    {
        return (float) Arr::get(self::get(), 'rate_margin', 0);
    }

    /**
     * Notes shown beside a provider (source credit, terms of use), keyed
     * by provider slug. Plain text.
     *
     * @return array<string, string>
     */
    public static function rateProviderNotes(): array
    {
        return (array) apply_filters('fluent_cart/currency/rate_provider_notes', []);
    }

    /**
     * Where exchange rates come from. Core ships manual entry; a provider
     * (e.g. a daily ECB feed in Pro) registers itself here and writes rates
     * to Pro's rate store with its own source slug.
     * Opt-in: a feed never replaces manual rates unless it is chosen.
     *
     * @return array<string, string> slug => label
     */
    public static function rateProviders(): array
    {
        return (array) apply_filters('fluent_cart/currency/rate_providers', [
            CurrencySnapshot::SOURCE_MANUAL => __('Enter rates manually', 'fluent-cart'),
        ]);
    }

    public static function rateProvider(): string
    {
        return (string) Arr::get(self::get(), 'rate_provider', CurrencySnapshot::SOURCE_MANUAL);
    }

    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $saved = fluent_cart_get_option(self::OPTION_KEY, [], false);

        return self::$cache = self::normalize(is_array($saved) ? $saved : []);
    }

    /**
     * @param array $input enabled, currencies[{code, rounding}], rate_lock_minutes
     * @return array|\WP_Error the saved settings
     */
    public static function save(array $input)
    {
        $settings = self::normalize($input);

        $requested = array_map(function ($row) {
            return strtoupper((string) Arr::get($row, 'code', ''));
        }, (array) Arr::get($input, 'currencies', []));
        $accepted = array_column($settings['currencies'], 'code');
        $rejected = array_values(array_diff(array_filter($requested), $accepted));

        if ($rejected) {
            return new \WP_Error('invalid_currency', sprintf(
                /* translators: %1$s: comma-separated currency codes */
                __('These currencies cannot be enabled: %1$s. A presentment currency must be a supported currency, different from the base currency, and not a three-decimal currency.', 'fluent-cart'),
                implode(', ', $rejected)
            ));
        }

        fluent_cart_update_option(self::OPTION_KEY, $settings);
        self::$cache = null;

        do_action('fluent_cart/currency/settings_saved', [
            'settings' => self::get(),
        ]);

        return self::get();
    }

    /**
     * Whether the storefront prices in other currencies: switched on, with a
     * currency, and available (Pro). The one gate every storefront path uses.
     */
    public static function isEnabled(): bool
    {
        return self::isSwitchedOn() && self::isAvailable();
    }

    /**
     * The merchant's saved switch, with or without Pro. The base-currency
     * lock follows it: stored foreign orders are measured in that base.
     */
    public static function isSwitchedOn(): bool
    {
        return Arr::get(self::get(), 'enabled') === 'yes' && !empty(self::get()['currencies']);
    }

    /**
     * Multi-currency is a Pro feature. Without it the storefront offers and
     * places new orders in the base currency only; stored orders keep theirs.
     */
    public static function isAvailable(): bool
    {
        /**
         * Whether multi-currency may run on the storefront.
         *
         * @param bool $available true while FluentCart Pro is active
         */
        return (bool) apply_filters('fluent_cart/currency/multi_currency_available', App::isProActive());
    }

    /**
     * Presentment currency codes the storefront may offer, base excluded.
     *
     * @return string[]
     */
    public static function currencyCodes(): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        $codes = self::isEnabled() ? array_column(self::get()['currencies'], 'code') : [];

        return array_values(array_unique(array_map('strtoupper', (array) apply_filters(
            'fluent_cart/currency/enabled_currencies',
            $codes,
            ['base_currency' => BaseCurrency::get()]
        ))));
    }

    public static function isOffered($currency): bool
    {
        return in_array(strtoupper((string) $currency), self::currencyCodes(), true);
    }

    public static function roundingRule($currency): string
    {
        return self::pricingRules($currency)['rounding'];
    }

    /**
     * How prices in $currency are derived from the converted base price:
     * a % adjustment, then rounding up to a step, then a charm ending
     * (plan §15.4). Applies to product prices only, never to shipping or
     * coupon amounts.
     *
     * @return array{rounding: string, charm: string, adjustment: string}
     */
    public static function pricingRules($currency): array
    {
        foreach (self::get()['currencies'] as $row) {
            if ($row['code'] === strtoupper((string) $currency)) {
                return [
                    'rounding'   => (string) Arr::get($row, 'rounding', RoundingRules::NONE),
                    'charm'      => (string) Arr::get($row, 'charm', RoundingRules::NONE),
                    'adjustment' => self::formatAdjustment(Arr::get($row, 'adjustment', 0)),
                ];
            }
        }

        return ['rounding' => RoundingRules::NONE, 'charm' => RoundingRules::NONE, 'adjustment' => '0'];
    }

    /**
     * A % adjustment kept as a short decimal string ("5", "-2.5") so quotes
     * and snapshots compare equal however it was entered.
     *
     * @param mixed $value
     */
    public static function formatAdjustment($value): string
    {
        $value = round(min(max((float) $value, -50), 100), 2);
        if (abs($value) < 0.005) {
            return '0';
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    public static function rateLockSeconds(): int
    {
        $minutes = (int) apply_filters(
            'fluent_cart/currency/rate_lock_ttl',
            (int) Arr::get(self::get(), 'rate_lock_minutes', self::DEFAULT_RATE_LOCK_MINUTES)
        );

        return max(0, $minutes) * MINUTE_IN_SECONDS;
    }

    /**
     * Changing the base currency would re-label every historical amount, so
     * it is refused once orders exist while multi-currency is on (plan §10,
     * invariant #7).
     *
     * @return true|\WP_Error
     */
    public static function guardBaseCurrencyChange($current, $requested)
    {
        $current = strtoupper((string) $current);
        $requested = strtoupper((string) $requested);

        if ($requested === '' || $requested === $current) {
            return true;
        }

        if (self::isBaseCurrencyLocked()) {
            return new \WP_Error('base_currency_locked', __('The store currency is the base currency every exchange rate and report is measured in, so it cannot be changed while multi-currency is enabled and orders exist.', 'fluent-cart'));
        }

        return true;
    }

    public static function isBaseCurrencyLocked(): bool
    {
        return self::isSwitchedOn() && Order::query()->exists();
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    private static function normalize(array $input): array
    {
        $defaults = self::defaults();
        $base = BaseCurrency::get();
        $supported = CurrenciesHelper::getCurrencies();
        $roundingOptions = RoundingRules::options();

        $currencies = [];
        foreach ((array) Arr::get($input, 'currencies', []) as $row) {
            $code = strtoupper(sanitize_text_field((string) Arr::get($row, 'code', '')));
            if ($code === '' || $code === $base || !isset($supported[$code]) || Money::isThreeDecimal($code) || isset($currencies[$code])) {
                continue;
            }

            $rounding = sanitize_key((string) Arr::get($row, 'rounding', RoundingRules::NONE));
            $charm = sanitize_key((string) Arr::get($row, 'charm', RoundingRules::NONE));
            $currencies[$code] = [
                'code'       => $code,
                'rounding'   => isset($roundingOptions[$rounding]) ? $rounding : RoundingRules::NONE,
                'charm'      => isset(RoundingRules::charmOptions()[$charm]) ? $charm : RoundingRules::NONE,
                'adjustment' => self::formatAdjustment(Arr::get($row, 'adjustment', 0)),
            ];
        }

        $lockMinutes = (int) Arr::get($input, 'rate_lock_minutes', $defaults['rate_lock_minutes']);

        $provider = sanitize_key((string) Arr::get($input, 'rate_provider', $defaults['rate_provider']));

        return [
            'enabled'           => Arr::get($input, 'enabled') === 'yes' ? 'yes' : 'no',
            'currencies'        => array_values($currencies),
            'rate_lock_minutes' => min(max($lockMinutes, 0), 1440),
            'rate_provider'     => isset(self::rateProviders()[$provider]) ? $provider : $defaults['rate_provider'],
            'rate_margin'       => round(min(max((float) Arr::get($input, 'rate_margin', $defaults['rate_margin']), 0), 10), 2),
        ];
    }
}
