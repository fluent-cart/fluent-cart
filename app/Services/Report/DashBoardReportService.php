<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\Report\Concerns\HasRange;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\DateTime;

class DashBoardReportService extends ReportService
{
    use HasRange;

    protected $salesGrowthChart = [];

    protected $dashBoardStats = [];

    protected $totalOrders = 0;

    protected $totalPaidOrders = 0;

    protected $totalOrderItems = 0;

    protected $totalOrderValue = 0;

    protected function modifyQuery(Builder $query): Builder
    {
        return $query
            ->withCount('order_items')
            ->with('transactions')
            ->withCount([
                'transactions',
                'transactions as refund_count' => function ($query) {
                    $query->where('transaction_type', Status::TRANSACTION_TYPE_REFUND);
                }
            ])
            ->orderBy('created_at');
    }

    public function getModel(): string
    {
        return Order::class;
    }

    /**
     * Prepares report data by calculating total orders, total paid orders, total order items, and total order value.
     *
     * This method performs the following calculations:
     * - Counts the total number of orders.
     * - Counts the total number of paid orders.
     * - Calculates the total number of items across all orders.
     * - Calculates the total number of items in paid orders.
     * - Calculates the total value of paid orders.
     *
     * @return void
     */
    protected function prepareReportData(): void
    {

        $this->totalOrders = $this->data->count();
        $this->totalPaidOrders = $this->data->where('payment_status', 'paid')->count();

        $this->totalOrderItems = $this->data->where('payment_status', 'paid')->sum('order_items_count');
        $this->totalOrderValue = $this->data->where('payment_status', 'paid')->sum('subtotal');
    }

    /**
     * Get dashboard statistics for the given date range.
     *
     * @param string $previousStartDate The start date of the previous period.
     * @param string $previousEndDate The end date of the previous period.
     * @return array An array containing the dashboard statistics.
     */

    public function getDashBoardStats($startDate, $endDate, $previousStartDate, $previousEndDate)
    {
        global $wpdb;

        $currency = $this->filters['currency'] ?? null;
        $currencyFilter = $currency ? 'AND currency = ?' : '';
        // The report statuses, as Revenue counts them: a partly or fully refunded order still took its payment
        $paid = "'" . implode("', '", array_map('esc_sql', Status::getReportStatuses())) . "'";

        $query = "
        SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN payment_status IN ({$paid}) THEN 1 ELSE 0 END) AS paid_orders,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_items WHERE order_id IN (
                SELECT id FROM {$wpdb->prefix}fct_orders WHERE payment_status IN ({$paid})
                AND created_at BETWEEN ? AND ?
                {$currencyFilter}
            )) AS total_paid_order_items,
            SUM(CASE WHEN payment_status IN ({$paid}) THEN total_paid ELSE 0 END) AS total_paid_amounts
        FROM {$wpdb->prefix}fct_orders
        WHERE created_at BETWEEN ? AND ?
        {$currencyFilter}
    ";

        // The bounds are store-day instants; GMT columns compare against their UTC time
        $bindings = function ($from, $to) use ($currency) {
            $range = [ReportHelper::toGmt($from)->format('Y-m-d H:i:s'), ReportHelper::toGmt($to)->format('Y-m-d H:i:s')];
            $currencyBinding = $currency ? [$currency] : [];

            return array_merge($range, $currencyBinding, $range, $currencyBinding);
        };

        $currentStats = App::db()->select($query, $bindings($startDate, $endDate))[0];
        $previousStats = App::db()->select($query, $bindings($previousStartDate, $previousEndDate))[0];
        $this->dashBoardStats = [
            'total_orders'           => [
                'title'         => __('All Orders', 'fluent-cart'),
                'icon'          => 'AllOrdersIcon',
                'current_count' => (int)$currentStats->total_orders ?? 0,
                'compare_count' => (int)$previousStats->total_orders ?? 0,
            ],
            'paid_orders'            => [
                'title'         => __('Paid Orders', 'fluent-cart'),
                'icon'          => 'Money',
                'current_count' => (int)$currentStats->paid_orders ?? 0,
                'compare_count' => (int)$previousStats->paid_orders ?? 0,
            ],
            'total_paid_order_items' => [
                'title'         => __('Paid Order Items', 'fluent-cart'),
                'icon'          => 'OrderItemsIcon',
                'current_count' => (int)$currentStats->total_paid_order_items ?? 0,
                'compare_count' => (int)$previousStats->total_paid_order_items ?? 0,
            ],
            'total_paid_amounts'     => [
                'title'         => __('Order Value (Paid)', 'fluent-cart'),
                'icon'          => 'OrderValueIcon',
                'current_count' => (int)$currentStats->total_paid_amounts ?? 0,
                'compare_count' => (int)$previousStats->total_paid_amounts ?? 0,
                'is_cents'      => true,
            ],
        ];

        return ['dashBoardStats' => $this->dashBoardStats];
    }

    public function getSalesGrowthChart(array $params)
    {
        $params = $this->clampParamsToToday($params);
        $group = ReportHelper::processGroup(
            $params['startDate'], $params['endDate'], $params['groupKey']
        );

        $netSql = ReportHelper::netSalesSql($params);

        $query = ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), $params)
            ->selectRaw("{$group['field']},

                COUNT(o.id) AS orders,

                SUM({$netSql}) / 100 AS net_revenue")
            ->groupByRaw($group['by'])
            ->orderByRaw($group['by']);

        $query = $this->applyFilters($query, $params);

        $result = $query->get();

        $keys = ['orders', 'net_revenue'];
        $groups = $this->getPeriodRange(
            $params['startDate'], $params['endDate'], $group['key'], $keys
        );

        foreach ($result as $item) {
            $groups[$item->group] = [
                'year'        => $item->year,
                'group'       => $item->group,
                'orders'      => (int)$item->orders,
                'net_revenue' => (float)$item->net_revenue,
            ];
        }

        return array_values($groups);
    }

    public function getCountryHeatMap()
    {
        // One billing address per order, so an order with retry duplicates counts once
        $results = ReportHelper::joinOrderAddress(App::db()->table('fct_orders as o'), 'billing', 'ao')
            ->select('ao.country', App::db()->raw('COUNT(o.id) as value'))
            ->whereNotNull('ao.id')
            ->groupBy('ao.country')
            ->get();

        //$results = $wpdb->get_results($query);

        $countryLists = Helper::getCountyIsoLists();

        $other = __('Uncategorized', 'fluent-cart');
        $transformedData = $results->map(function ($item) use ($countryLists, $other) {
            $countryCode = strtoupper((string)$item->country);
            $isKnown = isset($countryLists[$countryCode]);

            // `name` is the translated label; the map matches on the locale-stable `country_code`.
            return [
                'country_code' => $isKnown ? $countryCode : null,
                'name'         => $isKnown ? $countryLists[$countryCode] : $other,
                'value'        => (int)$item->value,
            ];
        })->toArray();

        // sort by value
        usort($transformedData, function ($a, $b) {
            return $a['value'] <=> $b['value'];
        });

        return [
            'countryHeatMap' => $transformedData,
        ];
    }

    public static function getRecentOrders(bool $showCustomers = true)
    {

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static query with no variables
        //$recentOrders = $wpdb->get_results($query, ARRAY_A);
        global $wpdb;
        $prefix = $wpdb->prefix;
        $recentOrders = App::db()->table("fct_orders as o")
            ->select([
                'o.id',
                'o.customer_id',
                App::db()->raw("CONCAT(c.first_name, ' ', c.last_name) AS customer_name"),
                App::db()->raw("(o.total_amount) / 100 as total_amount"),
                'o.currency',
                'o.created_at',
                App::db()->raw("(SELECT COUNT(*) FROM {$prefix}fct_order_items WHERE order_id = o.id) AS order_items_count"),
            ])
            ->join("fct_customers as c", 'o.customer_id', '=', 'c.id')
            ->orderBy('o.created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($item) use ($showCustomers) {
                $item = (array) $item;
                if (!$showCustomers) {
                    $item['customer_name'] = '';
                }

                return $item;
            })
            ->toArray();
        return [
            'recentOrders'     => $recentOrders,
            'customers_hidden' => !$showCustomers,
        ];
    }

    public static function getRecentActivities($groupKey)
    {
        // Build the base query
        $query = Activity::query()->select('title', 'content', 'created_at', 'created_by', 'module_name', 'module_id');

        // Apply date conditions based on the groupKey
        switch ($groupKey) {
            // Store days, compared as UTC against the GMT column
            case 'today':
                $today = ReportHelper::local(DateTime::now());
                $query->whereBetween('created_at', self::gmtBounds($today->copy()->startOfDay(), $today->copy()->endOfDay()));
                break;
            case 'yesterday':
                $yesterday = ReportHelper::local(DateTime::now())->subDays(1);
                $query->whereBetween('created_at', self::gmtBounds($yesterday->copy()->startOfDay(), $yesterday->copy()->endOfDay()));
                break;
            case 'this_week':
                $week = ReportHelper::local(DateTime::now());
                $query->whereBetween('created_at', self::gmtBounds($week->copy()->startOfWeek(), $week->copy()->endOfWeek()));
                break;
            case 'all':
            default:
                // No date filter for 'all'
                break;
        }

        // Execute the query
        $recentActivities = $query->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->toArray();

        return [
            'recentActivities' => $recentActivities
        ];
    }

    private static function gmtBounds($from, $to): array
    {
        return [ReportHelper::toGmt($from)->format('Y-m-d H:i:s'), ReportHelper::toGmt($to)->format('Y-m-d H:i:s')];
    }

}
