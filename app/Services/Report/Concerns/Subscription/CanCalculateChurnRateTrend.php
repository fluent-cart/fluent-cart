<?php

namespace FluentCart\App\Services\Report\Concerns\Subscription;

use FluentCart\App\App;
use FluentCart\App\Services\DateTime\DateTime;

trait CanCalculateChurnRateTrend
{
    /**
     * Retrieves the Subscriber Churn Rate trend over a specified period,
     * aggregated by the given interval (daily, monthly, yearly).
     *
     * Churn Rate = (Number of Churned Subscribers / Number of Subscribers at Beginning of Period) * 100%
     *
     * @param string|null $period_start_date_str Optional. The start date of the period in 'Y-m-d H:i:s' format.
     * If null, uses the earliest available date from the database.
     * @param string|null $period_end_date_str Optional. The end date of the period in 'Y-m-d H:i:s' format.
     * If null, uses the latest available date from the database.
     * @param string $interval_type The aggregation interval: 'daily', 'monthly', or 'yearly'.
     * @return array An array of associative arrays, each containing 'trend_date' and 'value' (churn rate percentage).
     */
    public function get_churn_rate_trend($period_start_date_str = null, $period_end_date_str = null, $interval_type = 'monthly', $currency = null)
    {
        $start_date_obj = DateTime::anyTimeToGmt($period_start_date_str);
        $end_date_obj = DateTime::anyTimeToGmt($period_end_date_str);

        $start_sql = DateTime::anyTimeToGmt($period_start_date_str)->format('Y-m-d H:i:s');
        $end_sql = DateTime::anyTimeToGmt($period_end_date_str)->format('Y-m-d H:i:s');
        $originalTimeZone = DateTime::extractTimezone($period_start_date_str);
        $offsetMinutes = DateTime::getTimezoneOffsetMinutes($originalTimeZone->getName());


        $data = [];
        $period_intervals = []; // Stores start/end dates for each interval

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
                $end_date_obj->endOfMonth(); // End of period for churn events
                $date_format = 'Y-m';
                break;
            case 'yearly':
                $temp_date_iterator->startOfYear();
                $end_date_obj->endOfYear(); // End of period for churn events
                $date_format = 'Y';
                break;
            default:
                $temp_date_iterator->startOfMonth();
                $end_date_obj->endOfMonth();
                $date_format = 'Y-m';
                $interval_type = 'monthly';
                break;
        }

        // First pass: Collect all interval start/end dates for churn rate calculation
        $all_start_dates_of_intervals_to_fetch = [];
        $all_end_dates_of_intervals_to_fetch = [];

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

            $trend_key = $temp_date_iterator->format($date_format);
            $period_intervals[$trend_key] = [
                'start_of_period' => $interval_start_date_obj->format('Y-m-d H:i:s'),
                'end_of_period'   => $interval_end_date_obj->format('Y-m-d H:i:s')
            ];

            $all_start_dates_of_intervals_to_fetch[] = $interval_start_date_obj->format('Y-m-d H:i:s');
            $all_end_dates_of_intervals_to_fetch[] = $interval_end_date_obj->format('Y-m-d H:i:s');


            // Move to the next period
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

        // Efficiently fetch all relevant subscriptions in one go
        $overall_min_date = min($all_start_dates_of_intervals_to_fetch);
        $overall_max_date = max($all_end_dates_of_intervals_to_fetch);

        $all_relevant_subscriptions = $this->get_all_relevant_subscriptions_for_churn_rate(
            $overall_min_date, $overall_max_date, $offsetMinutes, $currency
        );

        // Initialize results array for each trend date key
        $trend_results = array_fill_keys(array_keys($period_intervals), 0.00);

        // Calculate churn rate for each interval
        foreach ($period_intervals as $trend_date_key => $interval_dates) {
            $start_of_period_obj = DateTime::parse($interval_dates['start_of_period']);
            $end_of_period_obj = DateTime::parse($interval_dates['end_of_period']);

            $subscribers_at_beginning = 0;
            $churned_subscribers_in_period = 0;

            foreach ($all_relevant_subscriptions as $subscription) {
                $sub_created_at_obj = DateTime::parse($subscription['created_at']);
                $sub_expire_at_obj = null;
                if (!empty($subscription['expire_at'])) {
                    $sub_expire_at_obj = DateTime::parse($subscription['expire_at']);
                }
                $sub_canceled_at_obj = null;
                if (!empty($subscription['canceled_at'])) {
                    $sub_canceled_at_obj = DateTime::parse($subscription['canceled_at']);
                }

                // Determine if active at the START of the period
                $is_active_at_beginning = false;
                if (in_array($subscription['status'], ['active', 'trialling', 'pending'])) { // Check original status types
                    if ($sub_created_at_obj <= $start_of_period_obj) {
                        $not_expired_at_beginning = ($sub_expire_at_obj === null || $sub_expire_at_obj >= $start_of_period_obj);
                        $not_cancelled_at_beginning = ($sub_canceled_at_obj === null || $sub_canceled_at_obj > $start_of_period_obj); // Use > for active on the exact start date

                        if ($not_expired_at_beginning && $not_cancelled_at_beginning) {
                            $is_active_at_beginning = true;
                        }
                    }
                }

                if ($is_active_at_beginning) {
                    $subscribers_at_beginning++;
                }

                // Determine if churned WITHIN this period
                $is_churned_in_period = false;
                if (in_array($subscription['status'], ['cancelled', 'expired', 'failed'])) { // Check churn status types
                    $churn_date_obj = null;
                    if (!empty($subscription['canceled_at'])) {
                        $churn_date_obj = $sub_canceled_at_obj;
                    } elseif (!empty($subscription['expire_at']) && $subscription['status'] === 'expired') {
                        $churn_date_obj = $sub_expire_at_obj;
                    }
                    // Add other conditions for 'failed' if they map to a specific date column

                    if ($churn_date_obj && $churn_date_obj >= $start_of_period_obj && $churn_date_obj <= $end_of_period_obj) {
                        $churned_subscribers_in_period++;
                    }
                }
            }

            $churn_rate = 0.00;
            if ($subscribers_at_beginning > 0) {
                $churn_rate = ($churned_subscribers_in_period / $subscribers_at_beginning) * 100;
            }
            $trend_results[$trend_date_key] = round($churn_rate, 2); // Round to 2 decimal places
        }


        // Populate the final data array
        foreach ($period_intervals as $trend_date_key => $dates) {
            $data[] = [
                'trend_date' => $trend_date_key,
                'value'      => $trend_results[$trend_date_key]
            ];
        }

        return $data;
    }

    public function get_daily_churn_rate_trend($period_start_date_str = null, $period_end_date_str = null)
    {
        return $this->get_churn_rate_trend($period_start_date_str, $period_end_date_str, 'daily');
    }

    public function get_monthly_churn_rate_trend($period_start_date_str = null, $period_end_date_str = null, $currency = null)
    {
        return $this->get_churn_rate_trend($period_start_date_str, $period_end_date_str, $currency);
    }

    public function get_yearly_churn_rate_trend($period_start_date_str = null, $period_end_date_str = null)
    {
        return $this->get_churn_rate_trend($period_start_date_str, $period_end_date_str, 'yearly');
    }

    /**
     * Fetches all relevant subscriptions that could potentially be active or churned
     * within the overall date range for churn rate calculation.
     * Assumes $this->wpdb and $this->table_subscriptions are available.
     *
     * @param string $overall_min_date The earliest date in the entire reporting range.
     * @param string $overall_max_date The latest date in the entire reporting range.
     * @return array An array of subscription records.
     */
    protected function get_all_relevant_subscriptions_for_churn_rate(string $overall_min_date, string $overall_max_date, $offsetMinutes = 0, $currency = null): array
    {
        global $wpdb;
        $table_subscriptions = 'fct_subscriptions';
        $table_orders = 'fct_orders';


        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared with placeholders
        $subscriptions_query = App::db()->table($table_subscriptions . ' as s')
            ->select('s.id')
            ->selectRaw('s.created_at + INTERVAL ? MINUTE as created_at', [$offsetMinutes])
            ->selectRaw('s.expire_at + INTERVAL ? MINUTE as expire_at', [$offsetMinutes])
            ->selectRaw('s.canceled_at + INTERVAL ? MINUTE as canceled_at', [$offsetMinutes])
            ->select('s.status')
            ->join($table_orders . ' as o', 's.parent_order_id', '=', 'o.id')
            ->where(function($query) use ($overall_min_date, $overall_max_date) {
                $query->where(function($q) use ($overall_min_date, $overall_max_date) {
                    $q->where('s.created_at', '<=', $overall_max_date)
                        ->where(function($sub) use ($overall_min_date) {
                            $sub->whereNull('s.expire_at')
                                ->orWhere('s.expire_at', '>=', $overall_min_date);
                        })
                        ->where(function($sub) use ($overall_min_date) {
                            $sub->whereNull('s.canceled_at')
                                ->orWhere('s.canceled_at', '>', $overall_min_date);
                        });
                })
                    ->orWhere(function($q) use ($overall_min_date, $overall_max_date) {
                        $q->where('s.created_at', '>=', $overall_min_date)
                            ->where('s.created_at', '<=', $overall_max_date);
                    });
            });

// Optional currency filter
        if (!empty($currency)) {
            $subscriptions_query->where('o.currency', $currency);
        }

// Execute query
        $subscriptions = $subscriptions_query->get()->toArray();


        return $subscriptions;
    }
}
