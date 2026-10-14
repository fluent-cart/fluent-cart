<?php

namespace FluentCart\App\Modules\MultiCurrency\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SQL fragments for deriving base-currency figures from an order's frozen
 * rate. Base values are never stored; they are ROUND(amount / rate), rounded
 * once per order so per-row values and aggregate totals agree exactly.
 * dev-docs/multi-currency/plan.md §2.5.5.
 *
 * One definition of "this order has a rate we may divide by" for every
 * consumer — customer LTV, converted reports — so they cannot disagree.
 */
class BaseAmountSql
{
    /**
     * 1 when the order's rate can be trusted for base-currency figures, else 0.
     *
     * Untrusted: snapshots marked unknown / legacy_unknown, and — for a row the
     * snapshot backfill has not reached yet — any currency other than the base.
     *
     * @param string $alias table alias with trailing dot, e.g. 'o.' or ''
     * @return array{0: string, 1: array} [sql, bindings]
     */
    public static function trustedRate(string $alias = ''): array
    {
        $source = "JSON_UNQUOTE(JSON_EXTRACT({$alias}config, '$.fx.source'))";

        $sql = "(CASE"
            . " WHEN {$source} IN (?, ?) THEN 0"
            . " WHEN {$source} IS NULL AND UPPER({$alias}currency) <> ? THEN 0"
            . " WHEN {$alias}rate IS NULL OR {$alias}rate <= 0 THEN 0"
            . " ELSE 1 END)";

        return [$sql, [
            CurrencySnapshot::SOURCE_UNKNOWN,
            CurrencySnapshot::SOURCE_LEGACY_UNKNOWN,
            BaseCurrency::get(),
        ]];
    }

    /**
     * An amount column converted to base-currency cents for one order row,
     * or 0 when the row's rate cannot be trusted.
     *
     * @param string $amountSql e.g. 'o.total_paid' or an expression
     * @param string $alias table alias with trailing dot
     * @return array{0: string, 1: array} [sql, bindings]
     */
    public static function toBase(string $amountSql, string $alias = ''): array
    {
        [$trusted, $bindings] = self::trustedRate($alias);

        return [
            "(CASE WHEN {$trusted} = 1 THEN ROUND(({$amountSql}) / {$alias}rate) ELSE 0 END)",
            $bindings,
        ];
    }
}
