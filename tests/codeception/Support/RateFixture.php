<?php

namespace Tests\Support;

/**
 * Exchange rates for core suites, supplied through core's rate filter the way
 * FluentCart Pro's rate store answers it. Core keeps no rates table.
 */
class RateFixture
{
    const FILTER = 'fluent_cart/currency/exchange_rate';

    /** @var array<string, array> quote => the rate the filter returns */
    private static $rates = [];

    /** @var int */
    private static $nextId = 1;

    /**
     * The rate base -> $quote from now on, replacing any earlier one.
     */
    public static function set(string $quote, string $rate, string $source = 'manual'): void
    {
        // Hooks are restored after each test, so a missing callback means a new test
        if (has_filter(self::FILTER, [self::class, 'answer']) === false) {
            self::$rates = [];
            add_filter(self::FILTER, [self::class, 'answer'], 5, 2);
        }

        self::$rates[strtoupper($quote)] = [
            'rate'         => $rate,
            'rate_id'      => self::$nextId++,
            'source'       => $source,
            'margin'       => '0',
            'effective_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public static function clear(): void
    {
        self::$rates = [];
        remove_filter(self::FILTER, [self::class, 'answer'], 5);
    }

    /**
     * @param array|null $rate
     * @param array $context base, quote, context
     * @return array|null
     */
    public static function answer($rate, $context = [])
    {
        if (is_array($rate)) {
            return $rate;
        }

        return self::$rates[strtoupper((string) ($context['quote'] ?? ''))] ?? $rate;
    }
}
