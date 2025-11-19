<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\App\App;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Http\Controllers\Controller;

class OverviewReportController extends Controller
{
    public function getOverview(Request $request): \WP_REST_Response
    {
        // We need 24 hours of data to compare the last 12 months
        $start = gmdate('Y-m-01 00:00:00', strtotime('first day of 30 months ago'));
        $end = gmdate('Y-m-t 23:59:59', strtotime('last day of this month'));

        $currency = $request->get('params.currency');
        if (empty($currency)) {
            $currency = (new StoreSettings)->getCurrency();
        }

        $orderStats = $this->getMonthToMonthStats($start, $end, $currency);

        $grossData = $this->generateComparesMonths($orderStats['gross']);
        $netData = $this->generateComparesMonths($orderStats['net']);

        $countryWiseStats = $this->getCountryWiseStatsImproved(
            gmdate('Y-m-01 00:00:00', strtotime('first day of 11 months ago')), $end, 5, $currency
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
            ],
        ]);
    }

    protected function getMonthToMonthStats($start, $end, $currency = null)
    {
        $paymentStatuses = [
            Status::PAYMENT_PAID,
            Status::PAYMENT_PARTIALLY_PAID,
            Status::PAYMENT_REFUNDED,
            Status::PAYMENT_PARTIALLY_REFUNDED,
        ];

        $orders = App::db()->table('fct_orders')
            ->selectRaw("
                DATE_FORMAT(created_at, '%Y-%m') AS month,
                
                SUM(total_paid) AS gross,

                SUM(total_paid - total_refund - tax_total - shipping_tax) AS net
            ")
            ->whereIn('payment_status', $paymentStatuses)
            ->whereBetween('created_at', [$start, $end])
            // ->where('currency', $currency)
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return $this->fillData([$start, $end], $orders);
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
        $YoYGrowth = $TotalRevenuePrev ? number_format((($TotalRevenue - $TotalRevenuePrev) / $TotalRevenuePrev) * 100, 2, '.', '') : 100;

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

        $net_revenue_column = 'SUM(o.total_paid - o.total_refund - o.tax_total - o.shipping_tax) AS net_revenue';
        $gross_revenue_column = 'SUM(o.total_paid) AS gross_revenue';

        $currency_filter = '';
        if ($currency) {
            $currency_filter = $wpdb->prepare(' AND o.currency = %s', esc_sql($currency));
        }

        $top_countries_query = "WITH monthly_country_revenue AS (
            SELECT 
                DATE_FORMAT(o.created_at, '%Y-%m') AS month,
                a.country,
                $net_revenue_column,
                $gross_revenue_column,
                RANK() OVER (PARTITION BY DATE_FORMAT(o.created_at, '%Y-%m') ORDER BY SUM(o.total_paid - o.total_refund) DESC) AS revenue_rank
            FROM {$wpdb->prefix}fct_orders o
            INNER JOIN {$wpdb->prefix}fct_order_addresses a ON o.id = a.order_id
            WHERE 
                o.payment_status IN ('paid', 'partially-paid', 'refunded', 'partially-refunded')
                AND o.created_at >= ?
                AND o.created_at < ?
                AND a.type = 'billing'
                AND a.country IS NOT NULL
                AND a.country != ''
                $currency_filter
            GROUP BY DATE_FORMAT(o.created_at, '%Y-%m'), a.country
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

        $top_countries_results = App::db()->select(App::db()->raw($top_countries_query), [$start_date, $end_date]);

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
