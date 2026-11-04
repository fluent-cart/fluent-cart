<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\App;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Sanitizer;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Payments\SubscriptionHelper;

class ReportHelper
{
    /**
     * Define the group key based on the data density between the start and end dates.
     *
     * @param \DateTime $startDate The start date as a DateTime object.
     * @param \DateTime $endDate The end date as a DateTime object.
     * @return string The group key, which can be 'daily', 'monthly', or 'yearly'.
     */

    public static function defineGroupKey($startDate, $endDate)
    {
        $diff = $endDate->diff($startDate);
        $days = $diff->days;

        if ($days <= 91) {
            return 'daily';
        } elseif ($days <= 365) {
            return 'monthly';
        } else {
            return 'yearly';
        }
    }

    /**
     * A report section cached for a few minutes, for a caller that asks for it (the Overview): figures that do not
     * follow the date range, so a repeat visit or a filter change does not recount them.
     */
    public static function remember(string $name, array $key, callable $compute, int $seconds = 600)
    {
        $transient = 'fct_report_' . $name . '_' . md5((string) wp_json_encode($key));
        $cached = get_transient($transient);
        if ($cached !== false) {
            return $cached;
        }
        $value = $compute();
        set_transient($transient, $value, $seconds);

        return $value;
    }

    /**
     * Auto grouping fixed to the requested range, before the dates move to a compared range: that range is sized to
     * the elapsed days, so grouped on its own it could turn daily against the current period's months.
     */
    public static function resolveGroupKey(array $params): array
    {
        if (empty($params['groupKey']) || $params['groupKey'] === 'default') {
            $params['groupKey'] = static::defineGroupKey($params['startDate'], $params['endDate']);
        }

        return $params;
    }

    public static function processGroup($startDate, $endDate, $groupKey = null)
    {
        if (!$groupKey || $groupKey === 'default') {
            $groupKey = static::defineGroupKey($startDate, $endDate);
        }

        switch ($groupKey) {
            case 'yearly':
                $format = '%Y';
                break;
            case 'monthly':
                $format = '%Y-%m';
                break;
            default:
                $format = '%Y-%m-%d';
                break;
        }

        // Buckets are store days, months and years, the same days the range covers
        $column = static::storeTimeSql('o.created_at', $startDate, $endDate);
        $groupBy = "DATE_FORMAT({$column}, '{$format}')";
        // Every row of a bucket shares its year; MIN() states that as an aggregate, so strict SQL mode
        // (ONLY_FULL_GROUP_BY) accepts it. 'field' therefore needs a query grouped by 'by'.
        $selection = "{$groupBy} AS `group`, MIN(YEAR({$column})) AS year";

        return [
            'key'   => $groupKey,
            'by'    => $groupBy,
            'field' => $selection,
        ];
    }

    public static function processFilters($params = [])
    {
        $storeMode = (new StoreSettings())->get('order_mode') ?: 'live';
        $mode = $params['filterMode'] ?? $storeMode;

        $filters = [
            'payment_status' => $params['paymentStatus'] ?? null,
            'status' => $params['orderStatus'] ?? null,
            'currency' => ReportCurrency::resolve($params['currency'] ?? null),
            'mode' => $mode,
        ];

        return array_filter($filters);
    }

    public static function processRequest($params = []): array
    {
        $storeMode = (new StoreSettings())->get('order_mode') ?: 'live';
        $mode = $params['filterMode'] ?? $storeMode;

        $filters = [
            'payment_status' => Arr::get($params, 'paymentStatus'),
            'status' => Arr::get($params, 'orderStatus'),
            'currency' => ReportCurrency::resolve(Arr::get($params, 'currency')),
            'mode' => $mode,
            'type' => array_filter(Arr::get($params, 'orderTypes', [])),
        ];

        $groupKey = Arr::get($params, 'groupKey', 'daily');

        return [
            'filters' => array_filter($filters),
            'startDate' => Arr::get($params, 'startDate'),
            'endDate' => Arr::get($params, 'endDate'),
            'groupKey' => $groupKey,
        ];
    }

    /**
     * @param array $params
     * @param string[] $additional
     * @param bool $allowConverted opt-in: true only from a report that
     *        implements the "All currencies (in base)" mode (it reads
     *        ReportCurrency::ordersTable()). Every other report reads that
     *        selection as the base currency (ReportCurrency::resolve()).
     */
    public static function processParams($params = [], $additional = [], bool $allowConverted = false): array
    {
        $params = static::sanitizeParams($params);
        
        // Reports count days in the WordPress timezone: the picked calendar day counts, not the sender's offset.
        // The maths below runs in that zone; the end converts to UTC, because columns are GMT and the query
        // builder binds a date without its zone.
        $startDate = static::siteDay(Arr::get($params, 'startDate'))->startOfDay();
        $endDate = static::siteDay(Arr::get($params, 'endDate'))->endOfDay();
        // A saved preset means "relative to today": the sent dates may be from the day the tab was opened
        $preset = static::presetRange((string) Arr::get($params, 'rangeKey'), $startDate);
        if ($preset) {
            list($startDate, $endDate) = $preset;
        }
        
        $compareType = Arr::get($params, 'compareType');
        $compareDate = Arr::get($params, 'compareDate');
        $comparePeriod = null;

        if ($compareType && $compareDate) {
            $comparePeriod = static::getCompareRange(
                $compareType,
                ['startDate' => $startDate, 'endDate' => $endDate],
                $compareDate
            );
        }
        $startDate = static::toGmt($startDate);
        $endDate = static::toGmt($endDate);
        if ($comparePeriod) {
            $comparePeriod = [static::toGmt($comparePeriod[0]), static::toGmt($comparePeriod[1])];
        }

        $attributes = [
            'startDate'     => $startDate,
            'endDate'       => $endDate,
            'groupKey'      => Arr::get($params, 'groupKey'),
            // One currency per report — the requested one, else the base
            // currency — or, where the report supports it, every currency
            // converted to the base. Never all currencies summed unconverted.
            'currency'      => ReportCurrency::resolve(Arr::get($params, 'currency'), $allowConverted),
            'paymentMode'   => Arr::get($params, 'filterMode'),
            'variationIds'  => Arr::get($params, 'variation_ids', []),
            'comparePeriod' => $comparePeriod
        ];

        foreach ($additional as $key) {
            $value = Arr::get($params, $key);

            if ($key === 'orderStatus') {
                if (!$value || in_array('all', $value)) {
                    $value = [Status::ORDER_ON_HOLD, Status::ORDER_FAILED];
                }
            } elseif ($key === 'paymentStatus') {
                $value = Status::getReportStatuses();
            } elseif ($key === 'subscriptionType') {
                $value = $value ?: Status::ORDER_TYPE_SUBSCRIPTION;
            }

            $attributes[$key] = $value;
        }

        return $attributes;
    }

    /**
     * Rows of $table (joined on order_id) for orders in the report range and currency, starting from the
     * orders so MySQL reads them by their date index instead of scanning $table. A superset of the
     * report's orders; the outer query filters.
     *
     * @param array $params startDate, endDate, currency
     */
    public static function rangeOrderRows(string $table, string $alias, array $params)
    {
        return App::db()->table('fct_orders as ro')
            ->join("{$table} as {$alias}", "{$alias}.order_id", '=', 'ro.id')
            ->whereBetween('ro.created_at', [$params['startDate'], $params['endDate']])
            ->when(!empty($params['currency']) && $params['currency'] !== ReportCurrency::ALL, fn($q) =>
                $q->where('ro.currency', $params['currency'])
            );
    }

    /**
     * Left join one address of $type per order as $alias. Checkout retries left some orders with
     * several identical address rows; joining all of them counted the order once per row.
     * The latest row wins.
     *
     * @param \FluentCart\Framework\Database\Query\Builder $query reads `o` (orders)
     */
    public static function joinOrderAddress($query, string $type, string $alias = 'a', string $orderColumn = 'o.id')
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fct_order_addresses';

        // The latest row per order, looked up through the (order_id, type) index for the report's orders only.
        // $orderColumn is trusted SQL, e.g. o.parent_id for a renewal's parent order.
        return $query->leftJoin('fct_order_addresses as ' . $alias, function ($join) use ($alias, $type, $table, $orderColumn) {
            $join->on($alias . '.order_id', '=', $orderColumn)
                ->where($alias . '.type', '=', $type)
                ->whereRaw("{$alias}.id = (SELECT MAX(latest.id) FROM {$table} latest WHERE latest.order_id = {$orderColumn} AND latest.type = ?)", [$type]);
        });
    }

    /**
     * The order's sale type for reports. An installment plan (bill_times > 0) ends, so it is not recurring: its first
     * payment is 'installment' and its later payments 'installment_payment'. Otherwise the stored order type.
     *
     * @param string $alias trusted SQL prefix of the orders table
     */
    public static function saleTypeSql(string $alias = 'o.'): string
    {
        global $wpdb;
        // A plan points at its first order; renewals point at that order through parent_id
        $plans = "SELECT parent_order_id FROM {$wpdb->prefix}fct_subscriptions WHERE bill_times > 0";

        return "(CASE WHEN {$alias}type = 'payment' THEN 'payment'"
            . " WHEN COALESCE(NULLIF({$alias}parent_id, 0), {$alias}id) IN ({$plans})"
            . " THEN (CASE WHEN {$alias}type = 'renewal' THEN 'installment_payment' ELSE 'installment' END)"
            . " ELSE {$alias}type END)";
    }

    /**
     * Labels of the sale types from saleTypeSql().
     */
    public static function saleTypeLabels(): array
    {
        return [
            'payment'             => __('One-time', 'fluent-cart'),
            'subscription'        => __('New subscription', 'fluent-cart'),
            'renewal'             => __('Renewal', 'fluent-cart'),
            'installment'         => __('Installment sign-up', 'fluent-cart'),
            'installment_payment' => __('Installment payment', 'fluent-cart'),
        ];
    }

    /**
     * The calendar day and time of a sent date, read in the site timezone (the sender's offset is dropped).
     */
    public static function siteDay($value): DateTime
    {
        return new DateTime((new DateTime($value))->format('Y-m-d H:i:s'), wp_timezone());
    }

    /**
     * A copy of a report date in the site timezone, for labels and calendar maths.
     */
    public static function local(\DateTimeInterface $date): DateTime
    {
        return (new DateTime('@' . $date->getTimestamp()))->setTimezone(wp_timezone());
    }

    /**
     * Whole store months from $start to $end, plus one. The diff runs on UTC copies of the local wall-clock times,
     * because before PHP 8.1 DateTime::diff() across a daylight-saving change in one zone is off by an hour.
     */
    public static function monthSpan(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        $utc = new \DateTimeZone('UTC');
        // End to start, as before: PHP's whole-month count depends on the direction near month ends
        $diff = (new \DateTime(static::local($end)->format('Y-m-d H:i:s'), $utc))
            ->diff(new \DateTime(static::local($start)->format('Y-m-d H:i:s'), $utc));

        return $diff->y * 12 + $diff->m + 1;
    }

    /**
     * A copy of a report date in UTC: GMT columns compare against it once the builder drops the zone.
     */
    public static function toGmt(\DateTimeInterface $date): DateTime
    {
        return (new DateTime('@' . $date->getTimestamp()))->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * The days of a date-menu preset, counted from today in the site timezone (report days are store days).
     * All time keeps the sent start, the first order date. Null for a custom range or an unknown key.
     *
     * @return DateTime[]|null [start, end]
     */
    public static function presetRange(string $key, DateTime $sentStart): ?array
    {
        $today = (new DateTime('now', $sentStart->getTimezone()))->startOfDay();
        $starts = [
            'today'          => $today->copy(),
            'last_7'         => $today->copy()->modify('-6 days'),
            'last_30'        => $today->copy()->modify('-29 days'),
            'this_month'     => $today->copy()->startOfMonth(),
            'last_month'     => $today->copy()->modify('first day of last month'),
            'last_12_months' => $today->copy()->modify('first day of -11 months'),
            'year_to_date'   => $today->copy()->startOfYear(),
            'all_time'       => $sentStart->copy(),
        ];
        if (!isset($starts[$key])) {
            return null;
        }
        $end = $today->copy();
        if ($key === 'this_month') {
            $end->endOfMonth();
        } elseif ($key === 'last_month') {
            $end->modify('last day of last month');
        }

        return [$starts[$key]->startOfDay(), $end->endOfDay()];
    }

    /**
     * SQL for a GMT $column as store-local time. Each row gets the offset of its own date, so a daylight-saving
     * change inside the range moves no hours. MySQL time zone tables are often not loaded, so PHP gives the offsets.
     *
     * @param string $column trusted SQL, e.g. o.created_at
     */
    public static function storeTimeSql(string $column, $startDate, $endDate): string
    {
        $timezone = wp_timezone();
        // Callers without processParams may pass store-local strings
        $startDate = $startDate instanceof \DateTimeInterface ? $startDate : new DateTime($startDate, $timezone);
        $endDate = $endDate instanceof \DateTimeInterface ? $endDate : new DateTime($endDate, $timezone);
        // A manual UTC offset (+06:00) has no transitions
        $transitions = $timezone->getTransitions($startDate->getTimestamp(), $endDate->getTimestamp()) ?: [];
        if (count($transitions) < 2) {
            return "{$column} + INTERVAL " . (int) ($timezone->getOffset($endDate) / 60) . ' MINUTE';
        }

        // Newest change first: most report rows are recent, so they stop at the first WHEN
        $case = 'CASE';
        for ($i = count($transitions) - 1; $i >= 1; $i--) {
            $case .= " WHEN {$column} >= '" . gmdate('Y-m-d H:i:s', $transitions[$i]['ts']) . "' THEN " . (int) ($transitions[$i]['offset'] / 60);
        }

        return "{$column} + INTERVAL ({$case} ELSE " . (int) ($transitions[0]['offset'] / 60) . ' END) MINUTE';
    }

    /**
     * SQL: the order `o` is its customer's first purchase. A renewal is never one; an earlier paid purchase in any
     * currency makes it a returning customer.
     */
    public static function firstPurchaseSql(array $params): string
    {
        global $wpdb;
        $statuses = array_map(function ($status) {
            return "'" . esc_sql((string) $status) . "'";
        }, (array) Arr::get($params, 'paymentStatus', []));
        $paid = $statuses ? ' AND e.payment_status IN (' . implode(', ', $statuses) . ')' : '';
        $renewal = Status::ORDER_TYPE_RENEWAL;

        return "(o.type <> '{$renewal}' AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}fct_orders e WHERE e.customer_id = o.customer_id
            AND e.total_paid > 0 AND e.type <> '{$renewal}'{$paid} AND (e.created_at < o.created_at OR (e.created_at = o.created_at AND e.id < o.id))))";
    }

    /**
     * Whitelist a groupKey value used to build raw SQL (SELECT/GROUP BY clauses)
     * so an unrecognized or missing value never reaches the query builder.
     *
     * @param mixed $value
     * @return string
     */
    public static function sanitizeGroupKey($value)
    {
        $acceptedValues = ['billing_country', 'shipping_country', 'payment_method', 'payment_status', 'default', 'daily', 'monthly', 'yearly'];
        return in_array($value, $acceptedValues) ? $value : 'payment_method';
    }

    // Refunds are tax-inclusive and never reduce the order's stored tax, so the refunded share of tax is already in total_refund
    public static function unrefundedTaxSql(string $alias = 'o.'): string
    {
        $tax = "({$alias}tax_total + {$alias}shipping_tax)";

        return "({$tax} - CASE WHEN {$alias}total_paid > 0"
            . " THEN ROUND({$tax} * LEAST({$alias}total_refund, {$alias}total_paid) / {$alias}total_paid)"
            . " ELSE 0 END)";
    }

    /**
     * Customer names and emails in report lists need customer access: report access alone covers the figures only
     */
    public static function canSeeCustomers(): bool
    {
        return \FluentCart\App\Services\Permission\PermissionManager::hasPermission('customers/view');
    }

    public static function netRevenueSql(string $alias = 'o.'): string
    {
        return "({$alias}total_paid - {$alias}total_refund - " . self::unrefundedTaxSql($alias) . ")";
    }

    /**
     * Joins what net sales need (`srm`, the store's record of refunded shipping) to a query on `o`.
     * Reads only the records of these customers' orders (ids, or a query or closure that selects them), of the
     * renewals of these parent orders (same forms), or of the range's orders (params startDate and endDate); with
     * none, every order's.
     */
    public static function joinShippingRefund($query, array $params = [], $customers = [], $parents = null)
    {
        // One row per order: the meta table has no unique key, so a duplicate must not count an order twice.
        // Only the needed orders: the meta index starts with order_id, so a meta_key filter alone reads every row
        $scoped = !empty($customers) || $parents !== null || isset($params['startDate'], $params['endDate']);
        $recorded = App::db()->table('fct_order_meta')
            ->selectRaw('order_id, MAX(CAST(meta_value AS DECIMAL(20, 0))) AS shipping_refunded')
            ->where('meta_key', 'shipping_refunded_amount')
            ->when($scoped, function ($q) use ($params, $customers, $parents) {
                $q->whereIn('order_id', function ($in) use ($params, $customers, $parents) {
                    $in->select('ro.id')->from('fct_orders as ro');
                    if (!empty($customers)) {
                        $in->whereIn('ro.customer_id', $customers);
                    } elseif ($parents !== null) {
                        $in->whereIn('ro.parent_id', $parents);
                    } else {
                        $in->whereBetween('ro.created_at', [$params['startDate'], $params['endDate']]);
                    }
                });
            })
            ->groupBy('order_id');

        return $query->leftJoinSub($recorded, 'srm', 'srm.order_id', '=', 'o.id');
    }

    /**
     * One order's net sales (cents), as the Revenue report counts them: what was paid, less refunds and the
     * tax, shipping and fees the store kept. Needs joinShippingRefund().
     */
    public static function netSalesSql(array $params): string
    {
        return self::refundSplitSql($params)['net_sales'];
    }

    /**
     * One order's refund split into its parts, so every line of the breakdown adds up:
     * - shipping refunded (with its tax, as Order::shippingChargedTotal counts it): the store's record or the part of
     *   the refund above the items (refunds cover items first), whichever is larger; its tax share goes back with it
     * - fees refunded: what is left above items and shipping
     * - goods tax refunded: the tax share of the refunded items
     * - sales refunded: the refunded items without their tax
     * Needs joinShippingRefund(). Cents; the meta is in order currency, so the "All currencies" mode converts it.
     */
    public static function refundSplitSql(array $params): array
    {
        $meta = ReportCurrency::isConverted($params) ? 'ROUND(srm.shipping_refunded / o.rate)' : 'srm.shipping_refunded';
        // Shipping tax is part of charged shipping unless prices include tax
        $shippingCharged = '(CASE WHEN o.tax_behavior = 2 THEN o.shipping_total ELSE o.shipping_total + o.shipping_tax END)';
        $items = "(o.total_paid - {$shippingCharged} - o.fee_total)";
        $overflow = "GREATEST(0, o.total_refund - {$items})";
        // The record can lag (the store skips it when a fee is present), so a refund larger than the items still
        // reaches shipping: the larger of the two, capped at charged shipping
        $shipping = "LEAST({$shippingCharged}, GREATEST(COALESCE({$meta}, 0), {$overflow}))";
        $shippingTax = "(CASE WHEN {$shippingCharged} > 0 THEN ROUND(o.shipping_tax * {$shipping} / {$shippingCharged}) ELSE 0 END)";
        $fees = "LEAST(o.fee_total, GREATEST(0, {$overflow} - {$shipping}))";
        $itemRefund = "GREATEST(0, o.total_refund - {$shipping} - {$fees})";
        $goodsTax = "(CASE WHEN {$items} > 0 THEN ROUND(o.tax_total * LEAST({$itemRefund}, {$items}) / {$items}) ELSE 0 END)";
        $taxKept = "(o.tax_total + o.shipping_tax - {$goodsTax} - {$shippingTax})";
        $shippingKept = "({$shippingCharged} - o.shipping_tax - ({$shipping} - {$shippingTax}))";
        $feesKept = "(o.fee_total - {$fees})";

        return [
            'shipping_kept'  => $shippingKept,
            'fees_kept'      => $feesKept,
            'tax_kept'       => $taxKept,
            'sales_refunded' => "({$itemRefund} - {$goodsTax})",
            'net_sales'      => "(o.total_paid - o.total_refund - {$taxKept} - {$shippingKept} - {$feesKept})",
        ];
    }

    /**
     * Variation ids grouped by product for a report's product selector; a deleted variation keeps its id.
     */
    public static function variationOptions(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (!$ids) {
            return [];
        }

        $catalog = App::db()->table('fct_product_variations as pv')
            ->leftJoin('posts as p', 'p.ID', '=', 'pv.post_id')
            ->whereIn('pv.id', $ids)
            ->orderBy('p.post_title')
            ->orderBy('pv.serial_index')
            ->orderBy('pv.id')
            ->select(['pv.id', 'pv.post_id', 'pv.variation_title', 'p.post_title'])
            ->get()
            ->keyBy('id');

        // A deleted variation still has plans; keep it pickable under its id
        $rows = $catalog->values()->all();
        foreach (array_diff(array_map('intval', $ids), array_map('intval', $catalog->keys()->all())) as $missing) {
            $rows[] = (object) [
                'id'              => $missing,
                'post_id'         => 0,
                'variation_title' => '#' . $missing,
                'post_title'      => __('Deleted product', 'fluent-cart'),
            ];
        }

        $products = [];
        foreach ($rows as $variation) {
            $productId = (int) $variation->post_id;
            if (!isset($products[$productId])) {
                $products[$productId] = [
                    'product_id'    => $productId,
                    'product_title' => (string) $variation->post_title,
                    'variations'    => [],
                ];
            }
            $products[$productId]['variations'][] = [
                'id'    => (int) $variation->id,
                'title' => (string) $variation->variation_title,
            ];
        }

        return array_values($products);
    }

    /**
     * Start of the "as of" day in UTC; today unless a valid Y-m-d is given.
     * A frozen data snapshot passes its own date so dated buckets stay put.
     */
    public static function asOfDate($value = null): DateTime
    {
        $value = static::validDay($value);

        // Midnight of the store's day, as every report counts days
        return (new DateTime($value ?: 'now', wp_timezone()))->startOfDay();
    }

    /**
     * Billing dates from a first due date, stepped exactly as the renewal engine steps them:
     * SubscriptionHelper::addBillingInterval() added to each previous due date, with a config
     * billing_schedule (every 2 weeks, anchor days) winning over the slug.
     *
     * @param string|\DateTimeInterface $firstDate GMT
     * @param string $interval billing_interval
     * @param int $count dates to return at most
     * @param \DateTimeInterface|null $until last date allowed, inclusive
     * @param array|null $schedule see SubscriptionHelper::getBillingSchedule()
     * @return \DateTimeImmutable[] UTC
     */
    public static function billingDates($firstDate, string $interval, int $count, $until = null, $schedule = null): array
    {
        $ts = $firstDate instanceof \DateTimeInterface ? $firstDate->getTimestamp() : (int) strtotime((string) $firstDate . ' UTC');
        $untilTs = $until ? $until->getTimestamp() : null;

        $dates = [];
        for ($k = 0; $k < $count; $k++) {
            if ($untilTs !== null && $ts > $untilTs) {
                break;
            }

            $dates[] = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('UTC'));

            $next = SubscriptionHelper::addBillingInterval($ts, strtolower($interval), $schedule);
            if ($next <= $ts) {
                break; // an interval that does not advance (unknown slug filtered to 0 days)
            }
            $ts = $next;
        }

        return $dates;
    }

    /**
     * The validated billing_schedule from a raw subscription config, or null.
     *
     * @param string|array|null $config fct_subscriptions.config
     */
    public static function billingScheduleFromConfig($config)
    {
        $subscription = new Subscription();
        $subscription->setRawAttributes(['config' => is_array($config) ? wp_json_encode($config) : $config]);

        return SubscriptionHelper::getBillingSchedule($subscription);
    }

    /**
     * 'Y-m-d' when it names a real calendar day, else null. strtotime() rolls Feb 31 into March.
     */
    public static function validDay($value)
    {
        $value = sanitize_text_field((string) $value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return $value;
    }

    protected static function sanitizeParams($params)
    {
        $rules = [
            'startDate'        => 'sanitize_text_field',
            'endDate'          => 'sanitize_text_field',
            'compareType'      => 'sanitize_text_field',
            'compareDate'      => 'sanitize_text_field',
            'groupKey'         => function ($value) {
                return static::sanitizeGroupKey($value);
            },
            'currency'         => 'sanitize_text_field',
            'filterMode'       => 'sanitize_text_field',
            'storeMode'        => 'sanitize_text_field',
            'variation_ids.*'  => 'intval',
            'customDays'       => 'intval',
            'subscriptionType' => 'sanitize_text_field',
            'orderStatus.*'    => 'sanitize_text_field',
            'orderTypes.*'     => 'sanitize_text_field',
            'filter_type'      => 'sanitize_text_field',
            'rangeKey'         => 'sanitize_key',
        ];

        /**
         * Report params whose shape this plugin does not own.
         *
         * `advanced_filters` deliberately has no rule above. Its payload describes
         * filter conditions, and only whoever consumes it knows what shape is
         * valid — so only they can sanitize it without mangling it (running
         * sanitize_text_field() over a JSON blob eats everything after the first
         * '<'). A consumer pushes its own rule in here, and Sanitizer::sanitize()
         * leaves any key with no rule untouched.
         *
         * @param array $rules  Sanitization rules, keyed like $params.
         * @param array $params The raw, unsanitized params.
         */
        $rules = apply_filters('fluent_cart/report/sanitize_params_rules', $rules, $params);

        return Sanitizer::sanitize($params, is_array($rules) ? $rules : []);
    }

    /**
     * @param string $type
     * @param array $compareRange
     * @param array $currentRange
     * @return array|\DateTime[]|false
     * @throws \Exception
     */
    public static function getCompareRange($type, $currentRange, $compareDate = null)
    {
        $currentStartDate = $currentRange['startDate'];
        $currentendDate = static::clampToToday($currentStartDate, $currentRange['endDate']);

        $diffDays = $currentendDate->diff($currentStartDate)->days;

        if ($type == 'previous_period') {
            return [
                $currentStartDate->copy()->subDays($diffDays + 1),
                $currentStartDate->copy()->subDays(1)->endOfDay()
            ];
        } else if ($type == 'previous_month') {
            return static::shiftedRange($currentStartDate->copy()->subMonths(1), $diffDays, $currentStartDate);
        } else if ($type == 'previous_quarter') {
            return static::shiftedRange($currentStartDate->copy()->subMonths(3), $diffDays, $currentStartDate);
        } else if ($type == 'previous_year') {
            return static::shiftedRange($currentStartDate->copy()->subYears(1), $diffDays, $currentStartDate);
        } else if ($type == 'custom' && $compareDate) {
            $compareDate = (new DateTime((new DateTime($compareDate))->format('Y-m-d'), $currentStartDate->getTimezone()))->startOfDay();
            return [
                $compareDate->copy(),
                $compareDate->addDays($diffDays)->endOfDay(),
            ];
        } else {
            return false;
        }
    }

    public static function formatCompareRange($comparePeriod)
    {
        if (!$comparePeriod) {
            return null;
        }

        return [
            'start' => static::local($comparePeriod[0])->format('Y-m-d H:i:s'),
            'end'   => static::local($comparePeriod[1])->format('Y-m-d H:i:s'),
        ];
    }

    public static function formatCompareRanges($params)
    {
        $ranges = [];

        foreach (['previous_period', 'previous_month', 'previous_quarter', 'previous_year'] as $type) {
            $ranges[$type] = static::formatCompareRange(static::getCompareRange($type, [
                'startDate' => static::local($params['startDate']),
                'endDate'   => static::local($params['endDate']),
            ]));
        }

        return $ranges;
    }

    // Presets such as This Month end in the future; only the elapsed days
    // have data, so the compare range is sized to them.
    protected static function clampToToday($startDate, $endDate)
    {
        $today = (new DateTime('now', $endDate->getTimezone()))->endOfDay();

        if ($endDate <= $today || $startDate > $today) {
            return $endDate;
        }

        return $today;
    }

    // A shifted compare range keeps the primary length but never reaches
    // into the primary range (a 31-day month shifted back one month would).
    protected static function shiftedRange($fromDate, $diffDays, $currentStartDate)
    {
        $toDate = $fromDate->copy()->addDays($diffDays)->endOfDay();
        $dayBefore = $currentStartDate->copy()->subDays(1)->endOfDay();

        return [$fromDate, $toDate < $dayBefore ? $toDate : $dayBefore];
    }
}
