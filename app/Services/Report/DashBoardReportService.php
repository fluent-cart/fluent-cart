<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\Report\Concerns\CanParseAddressField;
use FluentCart\App\Services\Report\Concerns\HasRange;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\DateTime;

class DashBoardReportService extends ReportService
{
    use HasRange, CanParseAddressField;

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

        $totalOrderItems = 0;

        foreach ($this->data as $order) {
            $totalOrderItems += count($order->order_items);
        }

        $this->totalOrderItems = $totalOrderItems;
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

        $query = "
        SELECT 
            COUNT(*) AS total_orders,
            SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_orders,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_items WHERE order_id IN (
                SELECT id FROM {$wpdb->prefix}fct_orders WHERE payment_status = 'paid' 
                AND created_at BETWEEN ? AND ?
            )) AS total_paid_order_items,
            SUM(CASE WHEN payment_status = 'paid' THEN total_paid ELSE 0 END) AS total_paid_amounts
        FROM {$wpdb->prefix}fct_orders
        WHERE created_at BETWEEN ? AND ?
        AND currency = '{$this->filters['currency']}'
    ";

        $bindings = [
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d 23:59:59'),
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d 23:59:59'),
        ];

        foreach ($bindings as $binding) {
            $binding = is_numeric($binding) ? $binding : "'$binding'";
            $queryt = preg_replace('/\?/', $binding, $query, 1);
        }

        $currentStats = App::db()->select($query, $bindings)[0];

        $bindings = [
            $previousStartDate->format('Y-m-d'),
            $previousEndDate->format('Y-m-d 23:59:59'),
            $previousStartDate->format('Y-m-d'),
            $previousEndDate->format('Y-m-d 23:59:59'),
        ];


        $previousStats = App::db()->select($query, $bindings)[0];
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
        $group = ReportHelper::processGroup(
            $params['startDate'], $params['endDate'], $params['groupKey']
        );

        $query = App::db()->table('fct_orders as o')
            ->selectRaw("{$group['field']},

                COUNT(o.id) AS orders,

                SUM(
                    o.total_paid
                    - o.total_refund
                    - o.tax_total
                    - o.shipping_tax
                ) / 100 AS net_revenue")
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
        $results = App::db()->table('fct_order_addresses as ao')
            ->select('ao.country', App::db()->raw('COUNT(ao.id) as value'))
            ->join('fct_orders as o', 'ao.order_id', '=', 'o.id')
            ->where('ao.type', 'billing')
            ->groupBy('ao.country')
            ->get();


        //$results = $wpdb->get_results($query);

        $countryLists = Helper::getCountyIsoLists();

        $other = __('Uncategorized', 'fluent-cart');
        $transformedData = $results->map(function ($item) use ($countryLists, $other) {
            $countryName = Arr::get($countryLists, $item->country, $other);
            return [
                'name'  => $countryName,
                'value' => (int)$item->value,
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

    public static function getRecentOrders()
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
                'o.created_at',
                App::db()->raw("(SELECT COUNT(*) FROM {$prefix}fct_order_items WHERE order_id = o.id) AS order_items_count"),
            ])
            ->join("fct_customers as c", 'o.customer_id', '=', 'c.id')
            ->orderBy('o.created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn($item) => (array)$item) // convert each object to array
            ->toArray();
        return [
            'recentOrders' => $recentOrders
        ];
    }

    public static function getUnfulfilledOrders()
    {
        global $wpdb;

        // Query to fetch orders that are not 'canceled', 'failed', or 'completed'
        $prefix = $wpdb->prefix;

        $unfulfilledOrders = App::db()->table("fct_orders as o")
            ->select([
                'o.id',
                'o.customer_id',
                App::db()->raw("CONCAT(c.first_name, ' ', c.last_name) AS customer_name"),
                App::db()->raw("o.total_amount / 100 AS total_amount"),
                'o.created_at',
                App::db()->raw("(SELECT COUNT(*) FROM {$prefix}fct_order_items WHERE order_id = o.id) AS order_items_count"),
            ])
            ->join("fct_customers as c", 'o.customer_id', '=', 'c.id')
            ->whereNotIn('o.status', ['canceled', 'failed', 'completed'])
            ->orderBy('o.created_at', 'desc')
            ->get()
            ->map(fn($item) => (array)$item) // convert each object to associative array
            ->toArray();


        return [
            'unfulfilledOrders' => $unfulfilledOrders
        ];
    }

    public static function getRecentActivities($groupKey)
    {
        // Build the base query
        $query = Activity::query()->select('title', 'content', 'created_at', 'created_by', 'module_name', 'module_id');

        // Apply date conditions based on the groupKey
        switch ($groupKey) {
            case 'today':
                $query->whereDate('created_at', DateTime::now()->today());
                break;
            case 'yesterday':
                $query->whereDate('created_at', DateTime::now()->subDays(1));
                break;
            case 'this_week':
                $query->whereBetween('created_at', [
                    DateTime::now()->startOfWeek(),
                    DateTime::now()->endOfWeek()
                ]);
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

    public static function getSummary()
    {

        $postsSummary = App::db()->table("posts")
            ->select([
                App::db()->raw("COUNT(*) AS total_products"),
                App::db()->raw("SUM(CASE WHEN post_status = 'draft' THEN 1 ELSE 0 END) AS draft_products"),
            ])
            ->where('post_type', 'fluent-products')
            ->first(); // single row as object


        $postsSummary = (array)$postsSummary;

        // Query to count active and expired coupons from the fct_coupons table
        $couponsSummary = App::db()->table("fct_coupons")
            ->select([
                App::db()->raw("
            SUM(
                CASE 
                    WHEN (end_date IS NULL OR end_date = '0000-00-00 00:00:00' OR end_date >= CURDATE()) 
                     AND status = 'active' 
                    THEN 1 ELSE 0 
                END
            ) AS active_coupons
        "),
                App::db()->raw("
            SUM(
                CASE 
                    WHEN (end_date < CURDATE() AND end_date != '0000-00-00 00:00:00' 
                          AND status IN ('expired', 'disabled')) 
                    THEN 1 ELSE 0 
                END
            ) AS expired_coupons
        "),
            ])
            ->first();

        $couponsSummary = (array)$couponsSummary;



        return [
            'summaryData' => [
                'total_products'  => (int)Arr::get($postsSummary, 'total_products', 0),
                'draft_products'  => (int)Arr::get($postsSummary, 'draft_products', 0),
                'active_coupons'  => (int)Arr::get($couponsSummary, 'active_coupons', 0),
                'expired_coupons' => (int)Arr::get($couponsSummary, 'expired_coupons', 0),
            ]
        ];
    }
}
