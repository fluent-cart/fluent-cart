<?php

namespace FluentCart\App\Services\Report\Concerns\Subscription;

use FluentCart\App\App;
use FluentCart\App\Services\DateTime\DateTime;

trait CanCalculateSubscriptionCountTrend
{
    /**
     * Retrieves the active subscription count trend over a specified period,
     * aggregated by the given interval (daily, monthly, yearly).
     *
     * @param string|null $period_start_date_str Optional. The start date of the period in 'Y-m-d H:i:s' format.
     * If null, uses the earliest available date from the database.
     * @param string|null $period_end_date_str Optional. The end date of the period in 'Y-m-d H:i:s' format.
     * If null, uses the latest available date from the database.
     * @param string $interval_type The aggregation interval: 'daily', 'monthly', or 'yearly'.
     * @return array An array of associative arrays, each containing 'trend_date' (formatted based on interval)
     * and 'value' (total active subscription count for that interval).
     */
    public function get_subscription_count_trend($period_start_date_str = null, $period_end_date_str = null, $interval_type = 'monthly', $currency = null)
    {
        $start_date_obj = DateTime::anyTimeToGmt($period_start_date_str);
        $end_date_obj = DateTime::anyTimeToGmt($period_end_date_str);
        $originalTimeZone = DateTime::extractTimezone($period_start_date_str);
        $offsetMinutes = DateTime::getTimezoneOffsetMinutes($originalTimeZone->getName());

        $data = [];
        $snapshot_dates_to_fetch = [];

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
            $snapshot_date = clone $temp_date_iterator;
            switch ($interval_type) {
                case 'daily':
                    $snapshot_date->endOfDay();
                    break;
                case 'monthly':
                    $snapshot_date->endOfMonth();
                    break;
                case 'yearly':
                    $snapshot_date->endOfYear();
                    break;
            }
            $snapshot_dates_to_fetch[$temp_date_iterator->format($date_format)] = $snapshot_date->format('Y-m-d H:i:s');

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

        $subscription_counts_by_snapshot_date = $this->get_total_subscription_counts_for_multiple_dates(array_values($snapshot_dates_to_fetch), $offsetMinutes, $currency);

        $current_date_iterator = clone $start_date_obj;
        switch ($interval_type) {
            case 'daily':
                $current_date_iterator->startOfDay();
                break;
            case 'monthly':
                $current_date_iterator->startOfMonth();
                break;
            case 'yearly':
                $current_date_iterator->startOfYear();
                break;
        }

        while ($current_date_iterator <= $end_date_obj) {
            $trend_date_key = $current_date_iterator->format($date_format);
            $snapshot_date_string = $snapshot_dates_to_fetch[$trend_date_key];

            $count_value = isset($subscription_counts_by_snapshot_date[$snapshot_date_string])
                ? (int)$subscription_counts_by_snapshot_date[$snapshot_date_string]
                : 0;

            $data[] = [
                'trend_date' => $trend_date_key,
                'value'      => $count_value
            ];

            switch ($interval_type) {
                case 'daily':
                    $current_date_iterator->addDays(1);
                    break;
                case 'monthly':
                    $current_date_iterator->addMonth();
                    break;
                case 'yearly':
                    $current_date_iterator->addYear();
                    break;
            }
        }
        return $data;
    }

    public function get_daily_subscription_count_trend($period_start_date_str = null, $period_end_date_str = null, $currency = null)
    {
        return $this->get_subscription_count_trend($period_start_date_str, $period_end_date_str, 'daily', $currency);
    }

    public function get_monthly_subscription_count_trend($period_start_date_str = null, $period_end_date_str = null, $currency = null)
    {
        return $this->get_subscription_count_trend($period_start_date_str, $period_end_date_str, 'monthly', $currency);
    }

    public function get_yearly_subscription_count_trend($period_start_date_str = null, $period_end_date_str = null, $currency = null)
    {
        return $this->get_subscription_count_trend($period_start_date_str, $period_end_date_str, 'yearly', $currency);
    }

    /**
     * Fetches total active subscription counts for multiple snapshot dates efficiently.
     * Assumes $this->wpdb and $this->table_subscriptions are available in the class using this trait.
     *
     * @param array $snapshot_dates An array of snapshot dates in 'Y-m-d H:i:s' GMT format.
     * @return array An associative array where keys are snapshot dates ('Y-m-d H:i:s') and values are the total count.
     */
    protected function get_total_subscription_counts_for_multiple_dates(array $snapshot_dates, $offsetMinutes = 0, $currency = null): array
    {
        if (empty($snapshot_dates)) {
            return [];
        }

        $table_subscriptions ='fct_subscriptions';;
        $table_orders = 'fct_orders';

        $unique_snapshot_gmt_strings = array_unique($snapshot_dates);
        $counts_data = array_fill_keys($unique_snapshot_gmt_strings, 0);

        $min_snapshot_date_gmt = min($unique_snapshot_gmt_strings);
        $max_snapshot_date_gmt = max($unique_snapshot_gmt_strings);

        $subscriptions_query = App::db()->table($table_subscriptions . ' as s')
            ->select('s.id')
            ->selectRaw('s.created_at + INTERVAL ? MINUTE as created_at', [$offsetMinutes])
            ->selectRaw('s.expire_at + INTERVAL ? MINUTE as expire_at', [$offsetMinutes])
            ->selectRaw('s.canceled_at + INTERVAL ? MINUTE as canceled_at', [$offsetMinutes])
            ->select('s.status')
            ->join($table_orders . ' as o', 's.parent_order_id', '=', 'o.id')
            ->whereIn('s.status', ['active', 'trialling', 'pending'])
            ->whereRaw('(s.created_at <= ? AND (s.expire_at IS NULL OR s.expire_at >= ?) AND (s.canceled_at IS NULL OR s.canceled_at > ?))', [
                $max_snapshot_date_gmt,
                $min_snapshot_date_gmt,
                $min_snapshot_date_gmt
            ]);

            if (!empty($currency)) {
                $subscriptions_query->where('o.currency', $currency);
            }

// Execute query
        $subscriptions = $subscriptions_query->get()->toArray();


        foreach ($unique_snapshot_gmt_strings as $snapshot_gmt_str) {
            $snapshot_date_obj = DateTime::parse($snapshot_gmt_str);
            $current_count_for_snapshot = 0;

            foreach ($subscriptions as $subscription) {
                $sub_created_at_obj = DateTime::parse($subscription['created_at']);
                $sub_expire_at_obj = null;
                if (!empty($subscription['expire_at'])) {
                    $sub_expire_at_obj = DateTime::parse($subscription['expire_at']);
                }
                $sub_canceled_at_obj = null;
                if (!empty($subscription['canceled_at'])) {
                    $sub_canceled_at_obj = DateTime::parse($subscription['canceled_at']);
                }

                $is_active_at_snapshot = false;
                if (!in_array($subscription['status'], ['active', 'trialling', 'pending'])) {
                    continue;
                }

                if ($sub_created_at_obj <= $snapshot_date_obj) {
                    $not_expired = ($sub_expire_at_obj === null || $sub_expire_at_obj >= $snapshot_date_obj);
                    $not_cancelled = ($sub_canceled_at_obj === null || $sub_canceled_at_obj > $snapshot_date_obj);

                    if ($not_expired && $not_cancelled) {
                        $is_active_at_snapshot = true;
                    }
                }

                if ($is_active_at_snapshot) {
                    $current_count_for_snapshot++;
                }
            }
            $counts_data[$snapshot_gmt_str] = $current_count_for_snapshot;
        }

        return $counts_data;
    }

    protected function get_total_subscription_count(string $snapshot_date, $currency = null): int
    {
        $results = $this->get_total_subscription_counts_for_multiple_dates([$snapshot_date], 0, $currency);
        return isset($results[$snapshot_date]) ? (int)$results[$snapshot_date] : 0;
    }
}
