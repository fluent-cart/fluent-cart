<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Report\RevenueReportService;

class OverviewReportController extends Controller
{
    public function getOverview(Request $request): \WP_REST_Response
    {
        $raw = (array) $request->get('params', []);
        $converted = ReportCurrency::resolve(Arr::get($raw, 'currency'), true);

        // The Overview page reads only the 12-month net sales; other callers get every series, as before
        if (!empty($raw['net_sales_only'])) {
            $months = function () use ($converted) {
                return $this->netSalesMonths($converted);
            };

            // It does not follow the date range, so the Overview may take it from a short cache
            return $this->sendSuccess(['data' => [
                'net_sales_months' => !empty($raw['cached']) ? ReportHelper::remember('net_sales_months', [$converted], $months) : $months(),
            ]]);
        }

        // We need 24 months of data to compare the last 12 months. Store months: the wall-clock bounds label the
        // months, and the queries compare their UTC time against the GMT column
        $site = wp_timezone();
        $start = (new \DateTime('first day of 30 months ago', $site))->format('Y-m-01 00:00:00');
        $end = (new \DateTime('last day of this month', $site))->format('Y-m-t 23:59:59');

        $currency = ReportCurrency::resolve($request->get('params.currency'));

        $orderStats = $this->getMonthToMonthStats($start, $end, $currency);

        $grossData = $this->generateComparesMonths($orderStats['gross']);
        $netData = $this->generateComparesMonths($orderStats['net']);

        $countryWiseStats = $this->getCountryWiseStatsImproved(
            (new \DateTime('first day of 11 months ago', $site))->format('Y-m-01 00:00:00'), $end, 5, $currency
        );

        return $this->sendSuccess([
            'data' => [
                'gross_revenue'           => $grossData,
                'gross_revenue_quarterly' => $this->calculateQuaterlyGrowth($orderStats['gross']),
                'net_revenue'             => $netData,
                'net_revenue_quarterly'   => $this->calculateQuaterlyGrowth($orderStats['net']),
                'gross_summary'           => $this->calculateOverallSummary($grossData),
                'net_summary'             => $this->calculateOverallSummary($netData),
                'top_country_net'         => $countryWiseStats['net'],
                'top_country_gross'       => $countryWiseStats['gross'],
                // All currencies counts converted orders, as the Net sales card does; the older series stay in one currency
                'net_sales_months'        => $this->netSalesMonths(ReportCurrency::resolve($request->get('params.currency'), true)),
            ],
        ]);
    }

    /**
     * The last 24 store months of net sales, counted as the Revenue report counts them. The range is set here, not
     * by processParams(), so it holds on every license.
     */
    private function netSalesMonths($currency): array
    {
        $site = wp_timezone();
        $groups = RevenueReportService::make()->getRevenueData([
            'startDate'     => ReportHelper::toGmt((new DateTime('first day of 23 months ago', $site))->startOfDay()),
            'endDate'       => ReportHelper::toGmt((new DateTime('last day of this month', $site))->endOfDay()),
            'currency'      => $currency,
            'paymentStatus' => Status::getReportStatuses(),
            'groupKey'      => 'monthly',
        ])['groups'];

        return array_map(function ($group) {
            $group = (array) $group;

            return ['group' => (string) $group['group'], 'net_sales' => round((float) $group['net_sales'], 2)];
        }, array_values($groups));
    }

    protected function getMonthToMonthStats($start, $end, $currency = null)
    {
        $paymentStatuses = [
            Status::PAYMENT_PAID,
            Status::PAYMENT_PARTIALLY_PAID,
            Status::PAYMENT_REFUNDED,
            Status::PAYMENT_PARTIALLY_REFUNDED,
        ];

        $netSql = ReportHelper::netRevenueSql('');
        [$from, $to] = $this->gmtRange($start, $end);
        $month = "DATE_FORMAT(" . ReportHelper::storeTimeSql('created_at', $from, $to) . ", '%Y-%m')";

        $orders = App::db()->table('fct_orders')
            ->selectRaw("
                {$month} AS month,
                
                SUM(total_paid) AS gross,

                SUM({$netSql}) AS net
            ")
            ->whereIn('payment_status', $paymentStatuses)
            ->whereBetween('created_at', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->where('currency', ReportCurrency::resolve($currency))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return $this->fillData([$start, $end], $orders);
    }

    /**
     * Store wall-clock bounds as UTC instants, for comparing with GMT columns.
     *
     * @return \DateTimeInterface[]
     */
    private function gmtRange(string $start, string $end): array
    {
        return [ReportHelper::toGmt(ReportHelper::siteDay($start)), ReportHelper::toGmt(ReportHelper::siteDay($end))];
    }

    private function fillData($dateRange, $results)
    {
        $gross = [];
        $net = [];

        $startDate = new \DateTime($dateRange[0]);
        $endDate = new \DateTime($dateRange[1]);
        $interval = new \DateInterval('P1M'); // 1 month interval
        $period = new \DatePeriod($startDate, $interval, $endDate);

        foreach ($period as $date) {
            $formattedDate = $date->format('Y-m');

            // Initialize all months to 0
            $gross[$formattedDate] = 0;
            $net[$formattedDate] = 0;
        }
        // Fill in the results
        foreach ($results as $row) {
            $month = $row->month;
            if (isset($gross[$month])) {
                $gross[$month] = (int) $row->gross;
                $net[$month] = (int) $row->net;
            }
        }

        return [
            'gross' => $gross,
            'net'   => $net,
        ];
    }

    private function generateComparesMonths($results)
    {
        // Get the last 12 months (May 2024 to May 2025)
        $last_12_months = \array_slice($results, -12, 12, true);

        $result = [];

        foreach ($last_12_months as $month => $current_value) {
            // Extract year and month
            [$current_year, $month_num] = explode('-', $month);
            // Calculate previous year's month (e.g., 2025-05 -> 2024-05)
            $prev_year = $current_year - 1;
            $prev_month = sprintf('%d-%02d', $prev_year, $month_num);

            // Get previous year's value if it exists
            $prev_value = isset($results[$prev_month]) ? $results[$prev_month] : null;

            // Calculate YoY growth if previous value exists
            $yy_growth = null;
            if ($prev_value !== null && $prev_value != 0) {
                $yy_growth = number_format((($current_value - $prev_value) / $prev_value) * 100, 2, '.', '');
            }

            // Build the result array for this month
            $result[$month] = [
                'current'    => $current_value,
                'prev'       => $prev_value,
                'yoy_growth' => $yy_growth,
            ];
        }

        return $result;
    }

    private function calculateOverallSummary($data)
    {
        $TotalRevenue = array_sum(array_column($data, 'current'));
        $TotalRevenuePrev = array_sum(array_column($data, 'prev'));
        $YoYGrowth = $TotalRevenuePrev ? number_format((($TotalRevenue - $TotalRevenuePrev) / $TotalRevenuePrev) * 100, 2, '.', '') : null;

        return [
            'total'      => $TotalRevenue,
            'total_prev' => $TotalRevenuePrev,
            'yoy_growth' => $YoYGrowth,
        ];
    }

    private function calculateQuaterlyGrowth($data)
    {
        $getQuarterName = function ($date) {
            $month = (int) substr($date, 5, 2);
            $year = substr($date, 0, 4);
            $quarter = ceil($month / 3);

            return "Q$quarter-$year";
        };

        $allQuarters = [];

        foreach ($data as $date => $amount) {
            $quarter = $getQuarterName($date);
            if (!isset($allQuarters[$quarter])) {
                $allQuarters[$quarter] = 0;
            }
            $allQuarters[$quarter] += $amount;
        }

        // get the last 4 quarters
        $last4Quarters = array_slice($allQuarters, -4, 4, true);
        $formattedLast4Quarters = [];
        foreach ($last4Quarters as $quarter => $amount) {
            [$q, $year] = explode('-', $quarter);
            $prevYearQuarter = $q . '-' . ($year - 1);
            $prevQAmount = isset($allQuarters[$prevYearQuarter]) ? $allQuarters[$prevYearQuarter] : 0;
            $formattedLast4Quarters[$quarter] = [
                'current'   => $amount,
                'prev_year' => $prevQAmount,
                'yy_growth' => $prevQAmount ? number_format((($amount - $prevQAmount) / $prevQAmount) * 100, 2, '.', '') : null,
            ];
        }

        return $formattedLast4Quarters;
    }

    protected function getCountryWiseStatsImproved($start_date, $end_date, $limit = 5, $currency = null)
    {
        global $wpdb;

        $net_revenue_column = 'SUM(' . ReportHelper::netRevenueSql() . ') AS net_revenue';
        $gross_revenue_column = 'SUM(o.total_paid) AS gross_revenue';

        $currencyFilter = $currency ? 'AND o.currency = ?' : '';
        $statuses = Status::getReportStatuses();
        $statusPlaceholders = implode(', ', array_fill(0, count($statuses), '?'));

        [$from, $to] = $this->gmtRange($start_date, $end_date);
        $month = "DATE_FORMAT(" . ReportHelper::storeTimeSql('o.created_at', $from, $to) . ", '%Y-%m')";

        $top_countries_query = "WITH monthly_country_revenue AS (
            SELECT
                {$month} AS month,
                a.country,
                $net_revenue_column,
                $gross_revenue_column,
                RANK() OVER (PARTITION BY {$month} ORDER BY SUM(o.total_paid - o.total_refund) DESC) AS revenue_rank
            FROM {$wpdb->prefix}fct_orders o
            INNER JOIN {$wpdb->prefix}fct_order_addresses a ON a.order_id = o.id AND a.id = (
                SELECT MAX(latest.id) FROM {$wpdb->prefix}fct_order_addresses latest WHERE latest.order_id = o.id AND latest.type = 'billing'
            )
            WHERE
                o.payment_status IN ({$statusPlaceholders})
                AND o.created_at >= ?
                AND o.created_at < ?
                AND a.type = 'billing'
                AND a.country IS NOT NULL
                AND a.country != ''
                {$currencyFilter}
            GROUP BY {$month}, a.country
        )
        SELECT
            month,
            country,
            net_revenue,
            gross_revenue,
            revenue_rank
        FROM monthly_country_revenue
        WHERE revenue_rank <= 5
        ORDER BY month, revenue_rank";

        $bindings = array_merge($statuses, [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
        if ($currency) {
            $bindings[] = $currency;
        }

        $top_countries_results = App::db()->select(App::db()->raw($top_countries_query), $bindings);

        $grossByMonth = [];
        $netByMonth = [];

        $startDate = new \DateTime($start_date);
        $endDate = new \DateTime($end_date);
        $interval = new \DateInterval('P1M'); // 1 month interval
        $period = new \DatePeriod($startDate, $interval, $endDate);
        foreach ($period as $date) {
            $formattedDate = $date->format('Y-m');

            $grossByMonth[$formattedDate] = [];
            $netByMonth[$formattedDate] = [];
        }

        $grossByCountries = [];
        $netByCountries = [];

        foreach ($top_countries_results as $result) {
            $arrKey = $result->month;
            if (!isset($grossByMonth[$arrKey])) {
                continue; // Skip if the month key does not exist
            }

            $netRevenue = (int) $result->net_revenue;
            $grossRevenue = (int) $result->gross_revenue;

            if (!isset($grossByCountries[$result->country])) {
                $grossByCountries[$result->country] = 0;
                $netByCountries[$result->country] = 0;
            }

            $grossByCountries[$result->country] += $grossRevenue;
            $netByCountries[$result->country] += $netRevenue;

            $grossByMonth[$arrKey][$result->country] = $grossRevenue;
            $netByMonth[$arrKey][$result->country] = $netRevenue;
        }

        // sort by revenue in descending order $byCountries
        arsort($grossByCountries);
        arsort($netByCountries);

        $grossByCountries = array_slice($grossByCountries, 0, $limit, true);
        $netByCountries = array_slice($netByCountries, 0, $limit, true);

        foreach ($grossByMonth as $month => $countries) {
            // Sort countries by revenue in descending order
            arsort($countries);
            // Limit to top $limit countries
            $grossByMonth[$month] = array_slice($countries, 0, $limit, true);

            arsort($netByMonth[$month]);
            $netByMonth[$month] = array_slice($netByMonth[$month], 0, $limit, true);
        }

        return [
            'gross' => [
                'by_month'     => $grossByMonth,
                'by_countries' => $grossByCountries,
            ],
            'net' => [
                'by_month'     => $netByMonth,
                'by_countries' => $netByCountries,
            ],
        ];
    }
}
