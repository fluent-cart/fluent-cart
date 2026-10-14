<?php

namespace FluentCart\App\Modules\MultiCurrency\Services;

use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Currency scoping for reports.
 *
 * Every report is in exactly one currency: the one requested, else the base
 * currency — never "all currencies added together". Amounts of different
 * currencies are not summable without a rate.
 * dev-docs/multi-currency/plan.md §2.4 Tier A, §13.
 */
class ReportCurrency
{
    /**
     * "All currencies, converted to the base currency" — Tier B (plan §2.4).
     * Each order is converted at its own frozen rate; orders without a
     * trustworthy rate are left out and counted.
     */
    const ALL = '__all__';

    /** Order money columns converted in the ALL mode */
    const ORDER_MONEY_COLUMNS = [
        'subtotal', 'discount_tax', 'manual_discount_total', 'coupon_discount_total', 'shipping_tax',
        'shipping_total', 'fee_total', 'tax_total', 'total_amount', 'total_paid', 'total_refund',
    ];

    const ITEM_MONEY_COLUMNS = ['unit_price', 'cost', 'subtotal', 'tax_amount', 'discount_total', 'line_total', 'refund_total'];

    const TRANSACTION_MONEY_COLUMNS = ['total'];

    const SUBSCRIPTION_MONEY_COLUMNS = ['signup_fee', 'initial_tax_total', 'recurring_amount', 'recurring_tax_total', 'recurring_total'];

    /** @var array<string, string[]> */
    private static $columns = [];

    /**
     * The currency a report is scoped to.
     *
     * @param mixed $requested currency code from the request, if any
     * @param bool $allowConverted whether this report supports the ALL mode
     */
    public static function resolve($requested = null, bool $allowConverted = false): string
    {
        if ($allowConverted && (string) $requested === self::ALL) {
            return self::ALL;
        }

        $currency = strtoupper(trim(sanitize_text_field((string) $requested)));

        if (preg_match('/^[A-Z]{3}$/', $currency)) {
            return $currency;
        }

        return BaseCurrency::get();
    }

    /**
     * Currencies that have orders in the range, most orders first.
     *
     * @return array<int, array{currency: string, order_count: int, min_created_at: string|null}>
     */
    public static function orderCurrencies($startDate = null, $endDate = null): array
    {
        $rows = Order::query()
            ->select('currency')
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('MIN(created_at) as min_created_at')
            ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
                $query->whereBetween('created_at', [$startDate, $endDate]);
            })
            ->groupBy('currency')
            ->orderBy('order_count', 'desc')
            ->orderBy('currency', 'asc')
            ->get();

        $currencies = [];
        foreach ($rows as $row) {
            $code = strtoupper((string) $row->currency);
            if ($code === '') {
                continue;
            }

            // Codes stored in mixed case are the same currency
            if (isset($currencies[$code])) {
                $currencies[$code]['order_count'] += (int) $row->order_count;
                $currencies[$code]['min_created_at'] = min($currencies[$code]['min_created_at'], $row->min_created_at);
                continue;
            }

            $currencies[$code] = [
                'currency'       => $code,
                'order_count'    => (int) $row->order_count,
                'min_created_at' => $row->min_created_at,
            ];
        }

        uasort($currencies, function ($a, $b) {
            return $b['order_count'] <=> $a['order_count'] ?: strcmp($a['currency'], $b['currency']);
        });

        return array_values($currencies);
    }

    public static function isConverted(array $filters): bool
    {
        return Arr::get($filters, 'currency') === self::ALL;
    }

    /**
     * `fct_orders as o` — or, in the ALL mode, a derived table of the same
     * shape whose money columns are already in base-currency cents
     * (ROUND(amount / rate), once per order and column) and which holds
     * only orders with a trustworthy rate. Every existing SUM(o.total_paid)
     * then reads base currency unchanged.
     *
     * @return \FluentCart\Framework\Database\Query\Builder
     */
    public static function ordersTable(array $filters)
    {
        if (!self::isConverted($filters)) {
            return App::db()->table('fct_orders as o');
        }

        return App::db()->query()->fromSub(self::convertedOrders(), 'o');
    }

    /**
     * ->from('fct_orders as o') for a builder that is already started.
     *
     * @param \FluentCart\Framework\Database\Query\Builder $query
     */
    public static function fromOrders($query, array $filters)
    {
        return self::isConverted($filters)
            ? $query->fromSub(self::convertedOrders(), 'o')
            : $query->from('fct_orders as o');
    }

    /**
     * ->join('fct_order_items as oi', 'oi.order_id', '=', 'o.id')
     *
     * @param \FluentCart\Framework\Database\Query\Builder $query
     */
    public static function joinOrderItems($query, array $filters)
    {
        return self::isConverted($filters)
            ? $query->joinSub(self::convertedChildren('fct_order_items', self::ITEM_MONEY_COLUMNS), 'oi', 'oi.order_id', '=', 'o.id')
            : $query->join('fct_order_items as oi', 'oi.order_id', '=', 'o.id');
    }

    /**
     * ->join('fct_order_transactions as ot', 'o.id', '=', 'ot.order_id')
     *
     * @param \FluentCart\Framework\Database\Query\Builder $query
     */
    public static function joinTransactions($query, array $filters)
    {
        return self::isConverted($filters)
            ? $query->joinSub(self::convertedChildren('fct_order_transactions', self::TRANSACTION_MONEY_COLUMNS), 'ot', 'o.id', '=', 'ot.order_id')
            : $query->join('fct_order_transactions as ot', 'o.id', '=', 'ot.order_id');
    }

    /**
     * `fct_subscriptions [as $alias]` — or, in the ALL mode, a derived table
     * whose recurring amounts are in base-currency cents at each
     * subscription's own frozen rate (config.fx.rate), holding only
     * subscriptions with a trustworthy rate. MRR and churn SQL then reads
     * base currency unchanged.
     *
     * @return \FluentCart\Framework\Database\Query\Builder
     */
    public static function subscriptionsTable(array $filters, ?string $alias = null)
    {
        if (!self::isConverted($filters)) {
            return App::db()->table('fct_subscriptions' . ($alias ? ' as ' . $alias : ''));
        }

        return App::db()->query()->fromSub(self::convertedSubscriptions(), $alias ?: 'subs');
    }

    /**
     * ->from('fct_subscriptions as $alias') for a builder that is already started.
     *
     * @param \FluentCart\Framework\Database\Query\Builder $query
     */
    public static function fromSubscriptions($query, array $filters, string $alias)
    {
        return self::isConverted($filters)
            ? $query->fromSub(self::convertedSubscriptions(), $alias)
            : $query->from('fct_subscriptions as ' . $alias);
    }

    /**
     * @return \FluentCart\Framework\Database\Query\Builder
     */
    private static function convertedSubscriptions()
    {
        $base = esc_sql(BaseCurrency::get());
        $source = "JSON_UNQUOTE(JSON_EXTRACT(`config`, '$.fx.source'))";
        $storedRate = "CAST(JSON_UNQUOTE(JSON_EXTRACT(`config`, '$.fx.rate')) AS DECIMAL(24,12))";
        // A row the backfill has not reached is trusted only in the base currency
        $rate = "(CASE WHEN {$source} IS NULL AND UPPER(`currency`) IN ('', '{$base}') THEN 1 ELSE {$storedRate} END)";

        $select = [];
        foreach (self::columns('fct_subscriptions') as $column) {
            if (in_array($column, self::SUBSCRIPTION_MONEY_COLUMNS, true)) {
                $select[] = App::db()->raw("ROUND(`{$column}` / {$rate}) AS `{$column}`");
            } elseif ($column === 'currency') {
                $select[] = App::db()->raw("'{$base}' AS `currency`");
            } else {
                $select[] = $column;
            }
        }

        return App::db()->table('fct_subscriptions')
            ->select($select)
            ->whereRaw("(({$source} IS NULL AND UPPER(`currency`) IN ('', '{$base}')) OR ({$source} NOT IN (?, ?) AND {$storedRate} > 0))", [
                CurrencySnapshot::SOURCE_UNKNOWN,
                CurrencySnapshot::SOURCE_LEGACY_UNKNOWN,
            ]);
    }

    /**
     * Orders in range the ALL mode leaves out for want of a trustworthy rate.
     */
    public static function excludedOrderCount($startDate = null, $endDate = null): int
    {
        [$trusted, $bindings] = BaseAmountSql::trustedRate();

        return (int) Order::query()
            ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
                $query->whereBetween('created_at', [$startDate, $endDate]);
            })
            ->whereRaw("{$trusted} = 0", $bindings)
            ->count();
    }

    /**
     * @return \FluentCart\Framework\Database\Query\Builder
     */
    private static function convertedOrders()
    {
        $select = [];
        foreach (self::columns('fct_orders') as $column) {
            if (in_array($column, self::ORDER_MONEY_COLUMNS, true)) {
                $select[] = App::db()->raw("ROUND(`{$column}` / `rate`) AS `{$column}`");
            } elseif ($column === 'currency') {
                // Every amount in this derived table is in the base currency
                $select[] = App::db()->raw("'" . esc_sql(BaseCurrency::get()) . "' AS `currency`");
            } else {
                $select[] = $column;
            }
        }

        [$trusted, $bindings] = BaseAmountSql::trustedRate();

        return App::db()->table('fct_orders')->select($select)->whereRaw("{$trusted} = 1", $bindings);
    }

    /**
     * Order items / transactions with money columns converted at their
     * order's rate, restricted to orders with a trustworthy rate.
     *
     * @return \FluentCart\Framework\Database\Query\Builder
     */
    private static function convertedChildren(string $table, array $moneyColumns)
    {
        $select = [];
        foreach (self::columns($table) as $column) {
            $select[] = in_array($column, $moneyColumns, true)
                ? App::db()->raw("ROUND(`c`.`{$column}` / `po`.`rate`) AS `{$column}`")
                : 'c.' . $column;
        }

        [$trusted, $bindings] = BaseAmountSql::trustedRate('po.');

        return App::db()->table($table . ' as c')
            ->join('fct_orders as po', 'po.id', '=', 'c.order_id')
            ->select($select)
            ->whereRaw("{$trusted} = 1", $bindings);
    }

    /**
     * @return string[]
     */
    private static function columns(string $table): array
    {
        if (!isset(self::$columns[$table])) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            self::$columns[$table] = (array) $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $wpdb->prefix . $table));
        }

        return self::$columns[$table];
    }

    /**
     * Scope a subscription (or retention snapshot) query to one currency.
     *
     * Rows written before the currency column existed carry '' until their
     * backfill runs; they were created on a single-currency store, so they
     * count as the base currency rather than vanishing from every report.
     *
     * @param \FluentCart\Framework\Database\Query\Builder|\FluentCart\Framework\Database\Orm\Builder $query
     * @param string $column e.g. 's.currency'
     * @param string $currency
     */
    public static function whereCurrencyColumn($query, string $column, string $currency)
    {
        // The ALL mode reads a converted derived table: no currency predicate
        if ($currency === self::ALL) {
            return $query;
        }

        $currency = strtoupper($currency);

        return $query->where(function ($q) use ($column, $currency) {
            $q->where($column, $currency);

            if (BaseCurrency::is($currency)) {
                $q->orWhere($column, '');
            }
        });
    }
}
