<?php

namespace FluentCart\App\Services\Report\Concerns\Subscription;

use FluentCart\App\Services\DateTime\DateTime;

trait CanCalculateMrr
{
    // /**
    //  * Retrieves the Monthly Recurring Revenue (MRR) trend over a specified period,
    //  * aggregated by the given interval (daily, monthly, yearly).
    //  *
    //  * @param string|null $period_start_date_str Optional. The start date of the period in 'Y-m-d H:i:s' format.
    //  * If null, uses the earliest available date from the database.
    //  * @param string|null $period_end_date_str Optional. The end date of the period in 'Y-m-d H:i:s' format.
    //  * If null, uses the latest available date from the database.
    //  * @param string $interval_type The aggregation interval: 'daily', 'monthly', or 'yearly'.
    //  * @return array An array of associative arrays, each containing 'trend_date' (formatted based on interval)
    //  * and 'value' (total MRR for that interval).
    //  */
    // public function get_mrr_trend($period_start_date_str = null, $period_end_date_str = null, $interval_type = 'monthly', $currency = null)
    // {
    //     $start_date_obj = DateTime::anyTimeToGmt($period_start_date_str ?: (defined('static::db_min_date') ? static::$db_min_date : '2000-01-01 00:00:00'));
    //     $end_date_obj = DateTime::anyTimeToGmt($period_end_date_str ?: (defined('static::db_max_date') ? static::$db_max_date : gmdate('Y-m-d H:i:s')));

    //     $data = [];
    //     $snapshot_dates_to_fetch = [];

    //     $temp_date_iterator = clone $start_date_obj;

    //     $date_format = 'Y-m-d';
    //     switch ($interval_type) {
    //         case 'daily':
    //             $temp_date_iterator->startOfDay();
    //             $end_date_obj->endOfDay();
    //             $date_format = 'Y-m-d';
    //             break;
    //         case 'monthly':
    //             $temp_date_iterator->startOfMonth();
    //             $end_date_obj->endOfMonth();
    //             $date_format = 'Y-m';
    //             break;
    //         case 'yearly':
    //             $temp_date_iterator->startOfYear();
    //             $end_date_obj->endOfYear();
    //             $date_format = 'Y';
    //             break;
    //         default:
    //             $temp_date_iterator->startOfMonth();
    //             $end_date_obj->endOfMonth();
    //             $date_format = 'Y-m';
    //             $interval_type = 'monthly';
    //             break;
    //     }

    //     while ($temp_date_iterator <= $end_date_obj) {
    //         $snapshot_date = clone $temp_date_iterator;
    //         switch ($interval_type) {
    //             case 'daily':
    //                 $snapshot_date->endOfDay();
    //                 break;
    //             case 'monthly':
    //                 $snapshot_date->endOfMonth();
    //                 break;
    //             case 'yearly':
    //                 $snapshot_date->endOfYear();
    //                 break;
    //         }
    //         $snapshot_dates_to_fetch[$temp_date_iterator->format($date_format)] = $snapshot_date->format('Y-m-d H:i:s');

    //         switch ($interval_type) {
    //             case 'daily':
    //                 $temp_date_iterator->addDays(1);
    //                 break;
    //             case 'monthly':
    //                 $temp_date_iterator->addMonth();
    //                 break;
    //             case 'yearly':
    //                 $temp_date_iterator->addYear();
    //                 break;
    //         }
    //     }

    //     $mrr_values_by_snapshot_date = $this->get_total_mrr_for_multiple_dates(array_values($snapshot_dates_to_fetch), $currency);

    //     $current_date_iterator = clone $start_date_obj;
    //     switch ($interval_type) {
    //         case 'daily':
    //             $current_date_iterator->startOfDay();
    //             break;
    //         case 'monthly':
    //             $current_date_iterator->startOfMonth();
    //             break;
    //         case 'yearly':
    //             $current_date_iterator->startOfYear();
    //             break;
    //     }

    //     while ($current_date_iterator <= $end_date_obj) {
    //         $trend_date_key = $current_date_iterator->format($date_format);
    //         $snapshot_date_string = $snapshot_dates_to_fetch[$trend_date_key];

    //         $mrr_value = isset($mrr_values_by_snapshot_date[$snapshot_date_string])
    //                      ? (float) $mrr_values_by_snapshot_date[$snapshot_date_string]
    //                      : 0.00;

    //         $data[] = [
    //             'trend_date' => $trend_date_key,
    //             'value'      => $mrr_value
    //         ];

    //         switch ($interval_type) {
    //             case 'daily':
    //                 $current_date_iterator->addDays(1);
    //                 break;
    //             case 'monthly':
    //                 $current_date_iterator->addMonth();
    //                 break;
    //             case 'yearly':
    //                 $current_date_iterator->addYear();
    //                 break;
    //         }
    //     }
    //     return $data;
    // }

    // public function get_daily_total_mrr_trend($period_start_date_str = null, $period_end_date_str = null, $currency = null)
    // {
    //     return $this->get_mrr_trend($period_start_date_str, $period_end_date_str, 'daily', $currency);
    // }

    // public function get_monthly_total_mrr_trend($period_start_date_str = null, $period_end_date_str = null, $currency = null)
    // {
    //     return $this->get_mrr_trend($period_start_date_str, $period_end_date_str, 'monthly', $currency);
    // }

    // public function get_yearly_total_mrr_trend($period_start_date_str = null, $period_end_date_str = null, $currency = null)
    // {
    //     return $this->get_mrr_trend($period_start_date_str, $period_end_date_str, 'yearly', $currency);
    // }

    // /**
    //  * Fetches MRR for multiple snapshot dates efficiently.
    //  * Assumes $this->wpdb and $this->table_subscriptions are available in the class using this trait.
    //  *
    //  * @param array $snapshot_dates An array of snapshot dates in 'Y-m-d H:i:s' GMT format.
    //  * @return array An associative array where keys are snapshot dates ('Y-m-d H:i:s') and values are the total MRR.
    //  */
    // protected function get_total_mrr_for_multiple_dates(array $snapshot_dates, $currency = null): array
    // {
    //     if (empty($snapshot_dates)) {
    //         return [];
    //     }

    //     global $wpdb;
    //     $table_subscriptions = $wpdb->prefix . 'fct_subscriptions';
    //     $table_orders = $wpdb->prefix . 'fct_orders';

    //     $unique_snapshot_gmt_strings = array_unique($snapshot_dates);
    //     $mrr_data = array_fill_keys($unique_snapshot_gmt_strings, 0.00);

    //     $min_snapshot_date_gmt = min($unique_snapshot_gmt_strings);
    //     $max_snapshot_date_gmt = max($unique_snapshot_gmt_strings);

    //     // Fetch subscriptions using accurate column names from SubscriptionsMigrator.php
    //     // Columns: recurring_amount, billing_interval, created_at, expire_at, canceled_at, status
    //     $currency_filter = '';
    //     if (!empty($currency)) {
    //         $currency_filter = $wpdb->prepare(" AND o.currency = %s", esc_sql($currency));
    //     }
    //     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    //     $subscriptions = $wpdb->get_results(
    //         $wpdb->prepare(
    //             "SELECT
    //                 s.id,
    //                 s.recurring_amount,
    //                 s.billing_interval,
    //                 s.created_at,   -- Subscription start date
    //                 s.expire_at,    -- End of fixed term/trial
    //                 s.canceled_at,  -- Explicit cancellation date
    //                 s.status
    //             FROM {$table_subscriptions} s
    //             INNER JOIN {$table_orders} o ON s.parent_order_id = o.id
    //             WHERE s.status IN (%s, %s, %s) -- Consider 'active', 'trialling', 'pending' for potential MRR
    //             AND (
    //                 s.created_at <= %s -- Subscription started on or before the latest snapshot
    //                 AND (
    //                     s.expire_at IS NULL OR s.expire_at >= %s -- Hasn't expired before the earliest snapshot
    //                 )
    //                 AND (
    //                     s.canceled_at IS NULL OR s.canceled_at > %s -- Not cancelled before or on the earliest snapshot
    //                 )
    //             )
    //         {$currency_filter}",
    //             'active', 'trialling', 'pending', // Adjust statuses that contribute to MRR
    //             $max_snapshot_date_gmt,
    //             $min_snapshot_date_gmt,
    //             $min_snapshot_date_gmt
    //         ),
    //         ARRAY_A
    //     );

    //     foreach ($unique_snapshot_gmt_strings as $snapshot_gmt_str) {
    //         $snapshot_date_obj = DateTime::anyTimeToGmt($snapshot_gmt_str);
    //         $current_mrr_for_snapshot = 0.00;

    //         foreach ($subscriptions as $subscription) {
    //             // Use recurring_amount for MRR
    //             if (empty($subscription['recurring_amount']) || empty($subscription['billing_interval'])) {
    //                 continue;
    //             }

    //             $sub_created_at_obj = DateTime::anyTimeToGmt($subscription['created_at']);
    //             $sub_expire_at_obj = null;
    //             if (!empty($subscription['expire_at'])) {
    //                 $sub_expire_at_obj = DateTime::anyTimeToGmt($subscription['expire_at']);
    //             }
    //             $sub_canceled_at_obj = null;
    //             if (!empty($subscription['canceled_at'])) {
    //                 $sub_canceled_at_obj = DateTime::anyTimeToGmt($subscription['canceled_at']);
    //             }

    //             $is_active_at_snapshot = false;

    //             // Check active statuses
    //             if (!in_array($subscription['status'], ['active', 'trialling', 'pending'])) {
    //                 continue;
    //             }

    //             // Check if created_at is on or before snapshot_date
    //             if ($sub_created_at_obj <= $snapshot_date_obj) {
    //                 // Check if not expired before snapshot_date
    //                 $not_expired = ($sub_expire_at_obj === null || $sub_expire_at_obj >= $snapshot_date_obj);

    //                 // Check if not cancelled before or on snapshot_date
    //                 $not_cancelled = ($sub_canceled_at_obj === null || $sub_canceled_at_obj > $snapshot_date_obj);

    //                 if ($not_expired && $not_cancelled) {
    //                     $is_active_at_snapshot = true;
    //                 }
    //             }

    //             if ($is_active_at_snapshot) {
    //                 $monthly_recurring_amount = (float) $subscription['recurring_amount'];
    //                 // Normalize to monthly recurring revenue
    //                 switch ($subscription['billing_interval']) {
    //                     case 'yearly':
    //                         $monthly_recurring_amount /= 12;
    //                         break;
    //                     case 'weekly':
    //                         $monthly_recurring_amount = ($monthly_recurring_amount * 52) / 12;
    //                         break;
    //                     case 'daily':
    //                         $monthly_recurring_amount = ($monthly_recurring_amount * 365) / 12;
    //                         break;
    //                     case 'monthly':
    //                         break;
    //                 }
    //                 $current_mrr_for_snapshot += $monthly_recurring_amount;
    //             }
    //         }
    //         $mrr_data[$snapshot_gmt_str] = $current_mrr_for_snapshot;
    //     }

    //     return $mrr_data;
    // }

    // protected function get_total_mrr(string $snapshot_date, $currency = null): float
    // {
    //     $results = $this->get_total_mrr_for_multiple_dates([$snapshot_date], $currency);
    //     return isset($results[$snapshot_date]) ? (float) $results[$snapshot_date] : 0.00;
    // }
}
