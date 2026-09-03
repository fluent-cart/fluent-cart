<?php

namespace FluentCart\App\Services\Payments;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

class SubscriptionHelper
{
    /**
     * When money actually moved for an order, not when the row was created.
     * A pending/COD/bank-transfer order can sit for months before it is paid,
     * so `created_at` is not a safe billing anchor. Prefers the latest succeeded
     * charge's meta.settled_at (the gateway's own settlement time), falls back
     * to that transaction's created_at, then the order's completed_at, and only
     * falls back to order created_at when nothing else exists (e.g. a $0 order).
     */
    public static function resolvePaidAnchor(Order $order)
    {
        $lastCharge = OrderTransaction::query()
            ->where('order_id', $order->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->where('status', Status::TRANSACTION_SUCCEEDED)
            ->orderBy('id', 'DESC')
            ->first();

        if ($lastCharge) {
            $settledAt = Arr::get($lastCharge->meta, 'settled_at');
            if (!empty($settledAt)) {
                return $settledAt;
            }
            if (!empty($lastCharge->created_at)) {
                return $lastCharge->created_at;
            }
        }

        if (!empty($order->completed_at)) {
            return $order->completed_at;
        }

        return $order->created_at;
    }

    /*
     * @param $subscriptionModel
     * @return string|null
     *
     * */
    public static function getNextBillingDate(Subscription $subscriptionModel)
    {
        // only null case
        if ($subscriptionModel->status === Status::SUBSCRIPTION_COMPLETED || ($subscriptionModel->bill_times > 0 && $subscriptionModel->bill_count >= $subscriptionModel->bill_times)) {
            return null;
        }

        // assuming on expired we update the canceled_at, removes this comment when verified
        if ($subscriptionModel->status === Status::SUBSCRIPTION_CANCELED || $subscriptionModel->status === Status::SUBSCRIPTION_EXPIRED) {
            return $subscriptionModel->canceled_at;
        }

        // Trial handling
        if ($subscriptionModel->bill_count == 0 && !empty($subscriptionModel->trial_days)) {
            if (!empty($subscriptionModel->trial_ends_at)) {
                return $subscriptionModel->trial_ends_at;
            }
            return gmdate('Y-m-d H:i:s', strtotime($subscriptionModel->created_at . " +{$subscriptionModel->trial_days} days"));
        }

        if (!empty($subscriptionModel->next_billing_date) && strtotime($subscriptionModel->next_billing_date) > time()) {
            return $subscriptionModel->next_billing_date;
        }


        if ($subscriptionModel->bill_count == 0) {
            $parentOrder = $subscriptionModel->order;
            $baseDate    = $parentOrder ? self::resolvePaidAnchor($parentOrder) : $subscriptionModel->created_at;

        } elseif (!empty($subscriptionModel->next_billing_date) && strtotime($subscriptionModel->next_billing_date) < time()) {
            $baseDate = $subscriptionModel->next_billing_date;
        } else {
            $baseDate = DateTime::gmtNow()->format('Y-m-d H:i:s');
        }

        return gmdate('Y-m-d H:i:s', self::addBillingInterval(
            $baseDate,
            strtolower($subscriptionModel->billing_interval),
            self::getBillingSchedule($subscriptionModel)
        ));
    }

    /*
     * @param $trialDays
     * @param $billTimes
     * @param $interval
     *
     * */
    public static function getSubscriptionCancelAtTimeStamp($trialDays, $billTimes, $interval)
    {
        if (!$billTimes && !$trialDays) {
            return null;
        }

        // Use the passed arguments instead of accessing non-existent $this->subscription
        if ($interval == 'daily') {
            $interval = 'day';
        }

        $interValMaps = [
            'day'     => 'days',
            'weekly'  => 'weeks',
            'monthly' => 'months',
            'yearly'  => 'years'
        ];

        if (isset($interValMaps[$interval]) && $billTimes > 0) {
            $interval = $interValMaps[$interval];
        }

        $timestamp = strtotime('+ ' . $billTimes . ' ' . $interval);

        // Add trial days if provided
        if ($trialDays > 0) {
            $timestamp = $timestamp + $trialDays * 24 * 60 * 60; // Add trial days in seconds
        }

        return $timestamp;
    }


    // can be used to catch 1 day trial loop-hole
    public static function checkTrailDaysLoopHole($subscription, $trialDays)
    {
        $billCount = Arr::get($subscription, 'bill_count');
        $billingInterval = Arr::get($subscription, 'billing_interval');
        $billingIntervalInDays = 0;
        switch ($billingInterval) {
            case 'monthly':
                $billingIntervalInDays = 30;
                break;
            case 'quarterly':
                $billingIntervalInDays = 90;
                break;
            case 'half_yearly':
                $billingIntervalInDays = 182;
                break;
            case 'yearly':
                $billingIntervalInDays = 365;
                break;
            case 'weekly':
                $billingIntervalInDays = 7;
                break;
            case 'daily':
                $billingIntervalInDays = 1;
                break;
        }

        // get the days from now to the created at date - original trial days,
        $daysSinceCreated = ceil(ceil((time() - strtotime($subscription->created_at)) / 86400)) - intval($subscription->trial_days);
        $expectedBillCount = floor($daysSinceCreated / $billingIntervalInDays);

        if ($expectedBillCount > $billCount) {
            $trialDays = 0;
        }

        return $trialDays;
    }

    /**
     * Safely convert a date string or Unix timestamp to a GMT datetime string.
     * Returns null when the value is falsy, zero, or a negative timestamp
     * (guards against strtotime() returning false or a year-0 negative value).
     *
     * @param string|int|null $value
     * @return string|null
     */
    public static function safeTimestampToDatetime($value): ?string
    {
        if (!$value) {
            return null;
        }
        $ts = is_numeric($value) ? (int) $value : strtotime($value);
        if (!$ts || $ts <= 0) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * Whether renewal work is restricted to the store's current mode. On by
     * default; a live store deliberately flipped to test mode can turn it off
     * so live subscriptions keep billing. Fail-closed: only an explicit 'no'
     * disables — a malformed value written past the request sanitizer must
     * not silently drop staging protection.
     */
    public static function isModeGuardEnabled(): bool
    {
        return (new StoreSettings())->get('subscription_mode_guard', 'yes') !== 'no';
    }

    /**
     * Whether renewal work (invoice creation, automatic charging) may run for
     * an order of the given mode under the current store mode + guard setting.
     */
    public static function canProcessInMode(string $orderMode): bool
    {
        return !self::isModeGuardEnabled() || $orderMode === (new StoreSettings())->get('order_mode');
    }

    public static function getSubscriptionsGracePeriodDays()
    {
        $defaults = [
            'daily'       => 1,
            'weekly'      => 3,
            'monthly'     => 7,
            'quarterly'   => 15,
            'half_yearly' => 15,
            'yearly'      => 15,
        ];

        $gracePeriods = apply_filters('fluent_cart/subscription/grace_period_days', $defaults);

        if (!is_array($gracePeriods)) {
            $gracePeriods = [];
        }

        foreach ($defaults as $interval => $defaultDays) {
            $days = $gracePeriods[$interval] ?? $defaultDays;
            $gracePeriods[$interval] = is_numeric($days) ? max(0, (int) $days) : $defaultDays;
        }

        return array_intersect_key($gracePeriods, $defaults);
    }

    /**
     * Grace period (days past due before expiry) for a billing interval, resolved
     * from the per-interval grace map. Defaults to 7 for unknown intervals.
     */
    public static function getGracePeriodDaysForInterval(string $interval): int
    {
        $map = self::getSubscriptionsGracePeriodDays();

        foreach ($map as $key => $days) {
            if (strpos($interval, $key) !== false) {
                return (int) $days;
            }
        }

        return 7;
    }

    /**
     * Custom billing schedule stored in the subscription's config, or null.
     *
     * Shape: ['period' => day|week|month|year, 'interval' => N, 'anchor' => []].
     * Written by migrators for cadences the billing_interval enum cannot express
     * (every 2 weeks, every 4 months) and for calendar-synced billing (fixed day
     * of week / day of month / month of year). When present it overrides the
     * slug in addBillingInterval(); the slug itself stays a native enum value
     * (the schedule's base period) so validation, grace periods, and the UI keep
     * working — and so a site without config support bills the base period
     * rather than daily.
     *
     * Anchor keys: week → weekday (ISO 1-7); month → day (1-31, 31 = last day
     * of month); year → day + month.
     *
     * @return array|null
     */
    public static function getBillingSchedule(Subscription $subscription)
    {
        $config   = $subscription->config;
        $schedule = is_array($config) ? Arr::get($config, 'billing_schedule') : null;

        if (!is_array($schedule)) {
            return null;
        }

        $period = Arr::get($schedule, 'period');

        if (!in_array($period, ['day', 'week', 'month', 'year'], true)) {
            return null;
        }

        return [
            'period'   => $period,
            'interval' => max(1, (int) Arr::get($schedule, 'interval', 1)),
            'anchor'   => self::sanitizeScheduleAnchor($period, Arr::get($schedule, 'anchor')),
        ];
    }

    /**
     * Keep only anchor keys valid for the period and inside calendar range.
     * gmmktime() silently renormalizes out-of-range values (month 15 rolls
     * into the next year, day -3 into the previous month), so a corrupt
     * anchor value must be dropped — addSchedulePeriod() then falls back to
     * the current date part, keeping the cycle length correct.
     */
    private static function sanitizeScheduleAnchor($period, $anchor)
    {
        if (!is_array($anchor)) {
            return [];
        }

        $clean = [];

        if ($period === 'week') {
            $weekday = (int) Arr::get($anchor, 'weekday');
            if ($weekday >= 1 && $weekday <= 7) {
                $clean['weekday'] = $weekday;
            }
        }

        if ($period === 'month' || $period === 'year') {
            $day = (int) Arr::get($anchor, 'day');
            if ($day >= 1 && $day <= 31) {
                $clean['day'] = $day;
            }
        }

        if ($period === 'year') {
            $month = (int) Arr::get($anchor, 'month');
            if ($month >= 1 && $month <= 12) {
                $clean['month'] = $month;
            }
        }

        return $clean;
    }

    /**
     * Advance a GMT datetime by one whole billing cycle, calendar-accurate.
     *
     * Month-based intervals keep the day-of-month, clamping into shorter target
     * months (Jan 31 + monthly = Feb 28/29, then back to the 31st the cycle
     * after) — a flat day count (monthly = 30 days) walks a subscription's
     * billing day backwards roughly five days a year. Day/week intervals are
     * exact multiples already. Unknown intervals keep the day-count contract of
     * PaymentHelper::getIntervalDays() and its
     * `fluent_cart/subscription_interval_in_days` filter, including its
     * zero-progress edge (a filter returning 0 advances nothing, as before).
     *
     * When $schedule (see getBillingSchedule()) is given it wins over the slug:
     * the cycle is interval × period with the anchor re-applied, so a migrated
     * every-2-weeks-on-Friday subscription stays on Fridays even after a late
     * payment rebases the cycle.
     *
     * @param string|int $fromDate GMT datetime string or UTC timestamp
     * @param string $interval billing_interval slug
     * @param array|null $schedule config-defined schedule, overrides $interval
     * @return int advanced UTC timestamp
     */
    public static function addBillingInterval($fromDate, $interval, $schedule = null)
    {
        $fromTs = is_numeric($fromDate) ? (int) $fromDate : (int) strtotime($fromDate);

        if (is_array($schedule) && !empty($schedule['period'])) {
            return self::addSchedulePeriod($fromTs, $schedule);
        }

        $monthsMap = [
            Status::BILLING_MONTHLY     => 1,
            Status::BILLING_QUARTERLY   => 3,
            Status::BILLING_HALF_YEARLY => 6,
            Status::BILLING_YEARLY      => 12,
        ];

        if (isset($monthsMap[$interval])) {
            $hour  = (int) gmdate('H', $fromTs);
            $min   = (int) gmdate('i', $fromTs);
            $sec   = (int) gmdate('s', $fromTs);
            $year  = (int) gmdate('Y', $fromTs);
            $month = (int) gmdate('n', $fromTs) + $monthsMap[$interval];

            $firstOfTarget = gmmktime($hour, $min, $sec, $month, 1, $year);
            $day           = min((int) gmdate('j', $fromTs), (int) gmdate('t', $firstOfTarget));

            return gmmktime($hour, $min, $sec, $month, $day, $year);
        }

        if ($interval === Status::BILLING_DAILY) {
            return $fromTs + DAY_IN_SECONDS;
        }

        if ($interval === Status::BILLING_WEEKLY) {
            return $fromTs + (7 * DAY_IN_SECONDS);
        }

        return $fromTs + (PaymentHelper::getIntervalDays($interval) * DAY_IN_SECONDS);
    }

    /**
     * Advance by interval × period, then re-apply the anchor: week cycles snap
     * forward to the anchor weekday, month/year cycles keep the anchor day
     * clamped into short months (anchor 31 bills Feb 28, back to the 31st the
     * month after). Always moves at least one day forward.
     */
    private static function addSchedulePeriod($fromTs, array $schedule)
    {
        $n      = max(1, (int) Arr::get($schedule, 'interval', 1));
        $anchor = is_array(Arr::get($schedule, 'anchor')) ? $schedule['anchor'] : [];
        $hour   = (int) gmdate('H', $fromTs);
        $min    = (int) gmdate('i', $fromTs);
        $sec    = (int) gmdate('s', $fromTs);

        switch (Arr::get($schedule, 'period')) {
            case 'day':
                return $fromTs + ($n * DAY_IN_SECONDS);

            case 'week':
                $ts      = $fromTs + ($n * 7 * DAY_IN_SECONDS);
                $weekday = (int) Arr::get($anchor, 'weekday', 0);

                if ($weekday >= 1 && $weekday <= 7) {
                    $ts += ((($weekday - (int) gmdate('N', $ts)) + 7) % 7) * DAY_IN_SECONDS;
                }

                return $ts;

            case 'month':
                $year      = (int) gmdate('Y', $fromTs);
                $month     = (int) gmdate('n', $fromTs) + $n;
                $anchorDay = (int) Arr::get($anchor, 'day', 0) ?: (int) gmdate('j', $fromTs);

                $firstOfTarget = gmmktime($hour, $min, $sec, $month, 1, $year);
                $day           = min($anchorDay, (int) gmdate('t', $firstOfTarget));

                return gmmktime($hour, $min, $sec, $month, $day, $year);

            case 'year':
                $year        = (int) gmdate('Y', $fromTs) + $n;
                $anchorMonth = (int) Arr::get($anchor, 'month', 0) ?: (int) gmdate('n', $fromTs);
                $anchorDay   = (int) Arr::get($anchor, 'day', 0) ?: (int) gmdate('j', $fromTs);

                $firstOfTarget = gmmktime($hour, $min, $sec, $anchorMonth, 1, $year);
                $day           = min($anchorDay, (int) gmdate('t', $firstOfTarget));

                return gmmktime($hour, $min, $sec, $anchorMonth, $day, $year);
        }

        return $fromTs + DAY_IN_SECONDS;
    }

}
