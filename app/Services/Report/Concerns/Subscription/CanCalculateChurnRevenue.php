<?php

namespace FluentCart\App\Services\Report\Concerns\Subscription;

use FluentCart\App\App;
use FluentCart\App\Services\DateTime\DateTime;

trait CanCalculateChurnRevenue
{
    /**
     * Retrieves the Churn Revenue trend over a specified period,
     * aggregated by the given interval (daily, monthly, yearly).
     *
     * @param string|null $period_start_date_str Optional. The start date of the period in 'Y-m-d H:i:s' format.
     * If null, uses the earliest available date from the database.
     * @param string|null $period_end_date_str Optional. The end date of the period in 'Y-m-d H:i:s' format.
     * If null, uses the latest available date from the database.
     * @param string $interval_type The aggregation interval: 'daily', 'monthly', or 'yearly'.
     * @return array An array of associative arrays, each containing 'trend_date' (formatted based on interval)
     * and 'value' (total Churn Revenue for that interval).
     */
    public function get_churn_revenue_trend($period_start_date_str = null, $period_end_date_str = null, $interval_type = 'monthly', $currency = null)
    {
        $start_date_obj = DateTime::anyTimeToGmt($period_start_date_str ?: (defined('static::db_min_date') ? static::$db_min_date : '2000-01-01 00:00:00'));
        $end_date_obj = DateTime::anyTimeToGmt($period_end_date_str ?: (defined('static::db_max_date') ? static::$db_max_date : gmdate('Y-m-d H:i:s')));

        $data = [];
        $period_intervals = [];

        $temp_date_iterator = clone $start_date_obj;

        $date_format = 'Y-m-d';
        switch ($interval_type) {
            case 'daily':
                $temp_date_iterator->startOfDay();
                $end_date_obj->endOfDay();
                $date_format = 'Y-m-d';
                break;
            case 'monthly':
                $temp_date_iterator->startOfMonth();
                $end_date_obj->endOfMonth();
                $date_format = 'Y-m';
                break;
            case 'yearly':
                $temp_date_iterator->startOfYear();
                $end_date_obj->endOfYear();
                $date_format = 'Y';
                break;
            default:
                $temp_date_iterator->startOfMonth();
                $end_date_obj->endOfMonth();
                $date_format = 'Y-m';
                $interval_type = 'monthly';
                break;
        }

        while ($temp_date_iterator <= $end_date_obj) {
            $interval_start_date_obj = clone $temp_date_iterator;
            $interval_end_date_obj = clone $temp_date_iterator;

            switch ($interval_type) {
                case 'daily':
                    $interval_start_date_obj->startOfDay();
                    $interval_end_date_obj->endOfDay();
                    break;
                case 'monthly':
                    $interval_start_date_obj->startOfMonth();
                    $interval_end_date_obj->endOfMonth();
                    break;
                case 'yearly':
                    $interval_start_date_obj->startOfYear();
                    $interval_end_date_obj->endOfYear();
                    break;
            }

            $period_intervals[$temp_date_iterator->format($date_format)] = [
                'start' => $interval_start_date_obj->format('Y-m-d H:i:s'),
                'end'   => $interval_end_date_obj->format('Y-m-d H:i:s')
            ];

            switch ($interval_type) {
                case 'daily':
                    $temp_date_iterator->addDays(1);
                    break;
                case 'monthly':
                    $temp_date_iterator->addMonth();
                    break;
                case 'yearly':
                    $temp_date_iterator->addYear();
                    break;
            }
        }

        $churn_revenue_events = $this->get_churn_revenue_events_in_range(
            min(array_column($period_intervals, 'start')),
            max(array_column($period_intervals, 'end')),
            $currency
        );

        $trend_results = array_fill_keys(array_keys($period_intervals), 0.00);

        foreach ($churn_revenue_events as $event) {
            $churn_date_obj = DateTime::anyTimeToGmt($event['churn_date']);
            $churn_amount = (float) $event['normalized_mrr'];

            foreach ($period_intervals as $trend_date_key => $interval_dates) {
                $interval_start_obj = DateTime::anyTimeToGmt($interval_dates['start']);
                $interval_end_obj = DateTime::anyTimeToGmt($interval_dates['end']);

                if ($churn_date_obj >= $interval_start_obj && $churn_date_obj <= $interval_end_obj) {
                    $trend_results[$trend_date_key] += $churn_amount;
                    break;
                }
            }
        }

        foreach ($period_intervals as $trend_date_key => $dates) {
            $data[] = [
                'trend_date' => $trend_date_key,
                'value'      => $trend_results[$trend_date_key]
            ];
        }

        return $data;
    }

    public function get_daily_churn_revenue_trend($period_start_date_str = null, $period_end_date_str = null, $currency = null)
    {
        return $this->get_churn_revenue_trend($period_start_date_str, $period_end_date_str, 'daily', $currency);
    }

    public function get_monthly_churn_revenue_trend($period_start_date_str = null, $period_end_date_str = null, $currency = null)
    {
        return $this->get_churn_revenue_trend($period_start_date_str, $period_end_date_str, 'monthly', $currency);
    }

    public function get_yearly_churn_revenue_trend($period_start_date_str = null, $period_end_date_str = null, $currency = null)
    {
        return $this->get_churn_revenue_trend($period_start_date_str, $period_end_date_str, 'yearly', $currency);
    }

    /**
     * Fetches churn revenue events within a given date range efficiently.
     * Assumes $this->wpdb and $this->table_subscriptions are available in the class using this trait.
     *
     * @param string $range_start_gmt The overall start date of the range (GMT).
     * @param string $range_end_gmt The overall end date of the range (GMT).
     * @return array An array of associative arrays, each containing 'churn_date' and 'normalized_mrr'.
     */
    protected function get_churn_revenue_events_in_range(string $range_start_gmt, string $range_end_gmt, $currency = null): array
    {

        $table_subscriptions = 'fct_subscriptions';
        $churned_subscriptions_query = App::db()->table($table_subscriptions)
            ->select('id', 'recurring_amount', 'billing_interval', 'status')
            ->selectRaw('COALESCE(canceled_at, expire_at) as churn_date')
            ->whereIn('status', ['cancelled', 'expired', 'failed'])
            ->whereRaw('(COALESCE(canceled_at, expire_at) >= ? AND COALESCE(canceled_at, expire_at) <= ?)', [$range_start_gmt, $range_end_gmt]);
            if ($currency) {
                $churned_subscriptions_query->where('currency', $currency);
            }

            $churned_subscriptions = $churned_subscriptions_query->get()->toArray();


        $churn_events = [];
        foreach ($churned_subscriptions as $subscription) {
            // Use recurring_amount for MRR
            if (empty($subscription['recurring_amount']) || empty($subscription['billing_interval']) || empty($subscription['churn_date'])) {
                continue;
            }

            $monthly_recurring_amount = (float) $subscription['recurring_amount'];
            // Normalize to monthly recurring revenue
            switch ($subscription['billing_interval']) {
                case 'year':
                    $monthly_recurring_amount /= 12;
                    break;
                case 'week':
                    $monthly_recurring_amount = ($monthly_recurring_amount * 52) / 12;
                    break;
                case 'day':
                    $monthly_recurring_amount = ($monthly_recurring_amount * 365) / 12;
                    break;
                case 'month':
                    break;
            }

            $churn_events[] = [
                'churn_date'     => $subscription['churn_date'],
                'normalized_mrr' => $monthly_recurring_amount,
            ];
        }

        return $churn_events;
    }

    protected function get_total_churn_revenue(string $churn_date, $currency = null): float
    {
        $start_of_day = DateTime::anyTimeToGmt($churn_date)->startOfDay()->format('Y-m-d H:i:s');
        $end_of_day = DateTime::anyTimeToGmt($churn_date)->endOfDay()->format('Y-m-d H:i:s');

        $events = $this->get_churn_revenue_events_in_range($start_of_day, $end_of_day, $currency);
        $total_churn_revenue = 0.00;
        foreach ($events as $event) {
            $total_churn_revenue += $event['normalized_mrr'];
        }
        return $total_churn_revenue;
    }
}
