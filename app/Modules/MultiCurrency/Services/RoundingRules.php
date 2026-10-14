<?php

namespace FluentCart\App\Modules\MultiCurrency\Services;

use FluentCart\App\Services\Money;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * How a converted price is rounded in its presentment currency. Rounding
 * applies to prices, never to the rate (dev-docs/multi-currency/plan.md
 * §11.5, §15.4). Per currency the admin picks a rounding step (prices round
 * up to it) and an optional charm ending (.99 / .95), as WooPayments and
 * Shopify Markets do.
 */
class RoundingRules
{
    const NONE = 'none';

    /** @var array<string, int> rule key => step in cents (×100 for every currency) */
    const STEPS = [
        'up_0_05' => 5,
        'up_0_10' => 10,
        'up_0_25' => 25,
        'up_0_50' => 50,
        'up_1'    => 100,
        'up_5'    => 500,
        'up_10'   => 1000,
        'up_50'   => 5000,
        'up_100'  => 10000,
    ];

    /** @var array<string, int> charm key => cents taken off */
    const CHARMS = [
        'minus_0_01' => 1,
        'minus_0_05' => 5,
    ];

    /**
     * @return array<string, string> rule key => label
     */
    public static function options(): array
    {
        $options = [self::NONE => __('Round to the smallest unit of the currency', 'fluent-cart')];
        foreach (self::STEPS as $key => $step) {
            $options[$key] = sprintf(
                /* translators: %1$s: a rounding step such as 0.50 or 10 */
                __('Round up to %1$s', 'fluent-cart'),
                self::label($step)
            );
        }

        return (array) apply_filters('fluent_cart/currency/rounding_rules', $options);
    }

    /**
     * @return array<string, string> charm key => label
     */
    public static function charmOptions(): array
    {
        return [
            self::NONE   => __('No charm pricing', 'fluent-cart'),
            'minus_0_01' => __('End in .99 (take off 0.01)', 'fluent-cart'),
            'minus_0_05' => __('End in .95 (take off 0.05)', 'fluent-cart'),
        ];
    }

    /**
     * Round a converted amount to the currency's smallest unit — for amounts
     * that are not prices (shipping, coupon amounts, thresholds).
     *
     * @param float|int $cents converted, not yet rounded (×100 for every currency)
     */
    public static function apply($cents, string $currency, string $rule = self::NONE): int
    {
        return self::finish(self::toSmallestUnit($cents, $currency), $cents, $currency, $rule, self::NONE);
    }

    /**
     * Round a converted product price: up to the rule's step, then the charm
     * ending. Never below zero; a price of zero stays zero.
     *
     * @param float|int $cents converted, not yet rounded (×100 for every currency)
     */
    public static function price($cents, string $currency, string $rule = self::NONE, string $charm = self::NONE): int
    {
        $cents = max(0, (float) $cents);
        $unit = self::unit($currency);

        if (isset(self::STEPS[$rule])) {
            // A zero-decimal currency cannot round to less than one whole unit
            $step = max(self::STEPS[$rule], $unit);
            // The epsilon keeps an exact multiple (3900.0000001) from rounding up a step
            $rounded = (int) (ceil($cents / $step - 1e-9) * $step);
        } else {
            $rounded = self::toSmallestUnit($cents, $currency);
        }

        // Charm endings need a minor unit, and never turn a price into zero or less
        $charmCents = self::CHARMS[$charm] ?? 0;
        if ($charmCents && $unit === 1 && $rounded > $charmCents) {
            $rounded -= $charmCents;
        }

        return self::finish($rounded, $cents, $currency, $rule, $charm);
    }

    private static function toSmallestUnit($cents, string $currency): int
    {
        $unit = self::unit($currency);

        return (int) (round((float) $cents / $unit) * $unit);
    }

    /**
     * ×100 storage: a zero-decimal currency's smallest unit is 100 stored units.
     */
    public static function unit(string $currency): int
    {
        return Money::getDecimals($currency) === 0 ? 100 : 1;
    }

    private static function finish(int $rounded, $raw, string $currency, string $rule, string $charm): int
    {
        $rounded = apply_filters('fluent_cart/currency/rounding_rule', $rounded, [
            'raw_amount' => $raw,
            'currency'   => strtoupper($currency),
            'rule'       => $rule,
            'charm'      => $charm,
        ]);

        return max(0, (int) $rounded);
    }

    private static function label(int $step): string
    {
        return $step % 100 === 0 ? (string) ($step / 100) : number_format($step / 100, 2, '.', '');
    }
}
