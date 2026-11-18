<?php

namespace FluentCart\App\Modules\MCP\Support;

use FluentCart\App\Services\Payments\SubscriptionHelper;

/**
 * Pure forward billing projection, shared by get-product-financials (via
 * ProductFinancialsCalculator) and get-upcoming-payments. Given per-subscription
 * anchors (next_billing_date), normalized intervals and finite remaining-bill
 * caps, it walks each subscription forward from as_of to a horizon and sums the
 * expected charges into calendar buckets — separating finite (split-pay)
 * installments from open-ended recurring renewals.
 *
 * The billing rules it encodes (single source of truth for both tools):
 *   - Anchor on next_billing_date, never created_at.
 *   - Step by the subscription's own interval, month steps clamped to the month
 *     end as the billing engine does (SubscriptionHelper::addBillingInterval).
 *     A config billing_schedule wins over the interval slug, as in billing.
 *   - A finite (bill_times > 0) subscription stops after its remaining bills;
 *     a perpetual one runs to the horizon.
 *   - A finite installment already past due is still owed: it counts at as_of,
 *     as in the installments report. A perpetual past anchor moves forward.
 *
 * No models — plain arrays of integer cents, so it is unit-tested without a
 * database and both callers get identical numbers. A billing_schedule step needs
 * WordPress (SubscriptionHelper).
 *
 * PHP 7.4 safe: no null-safe, no match, no named args, no enums, no union types.
 */
class PaymentProjector
{
    /** Seconds in a day (WP's DAY_IN_SECONDS may be absent when run outside WordPress). */
    const DAY_SECONDS = 86400;

    /** Hard per-sub iteration cap (daily over 12 months ~ 366) so a bad interval/anchor can't spin. */
    const MAX_EVENTS = 4000;

    /**
     * Project a set of subscription descriptors onto calendar buckets.
     *
     * A descriptor is:
     *   [
     *     'settlement'      => 'finite'|'perpetual',
     *     'interval'        => normalized interval string (monthly|quarterly|…),
     *     'recur'           => int cents per charge,
     *     'remaining_bills' => int (finite) | -1 (perpetual/unbounded),
     *     'anchor'          => 'Y-m-d H:i:s' UTC next-bill anchor,
 *     'schedule'        => optional, SubscriptionHelper::getBillingSchedule(),
     *   ]
     *
     * @param array  $projSubs      descriptors (see above)
     * @param int    $asOfTs        unix ts — never bill before this instant
     * @param int    $horizonEndTs  unix ts — never bill after this instant
     * @param string $bucket        'day' | 'week' | 'month'
     * @param \DateTimeZone|null $tz timezone of the bucket keys (the store's, as the reports bucket); UTC when null
     * @param int|null $nowTs   unix ts of now; null treats as_of as now
     *
     * @return array{
     *   buckets: array<string, array{finite:int,recurring:int,finite_count:int,recurring_count:int}>,
     *   recurring_next_30d:int, recurring_next_90d:int, by_interval_next_30d: array<string,int>
     * } buckets are period-sorted; the next_30d/90d scalars are relative to as_of.
     */
    public static function project(array $projSubs, $asOfTs, $horizonEndTs, $bucket, $tz = null, $nowTs = null)
    {
        // A window that starts later than now: an installment due before it is not overdue
        // and not in the window, so it is left out. It still uses up one of the plan's bills.
        $startsLater = $nowTs !== null && $asOfTs > $nowTs + 86400;

        $bucket = ($bucket === 'week' || $bucket === 'day') ? $bucket : 'month';

        $cutoff30 = $asOfTs + 30 * self::DAY_SECONDS;
        $cutoff90 = $asOfTs + 90 * self::DAY_SECONDS;

        $buckets          = [];
        $recurring30      = 0;
        $recurring90      = 0;
        $byIntervalNext30 = [];

        $utc = new \DateTimeZone('UTC');

        foreach ($projSubs as $sub) {
            try {
                $date = new \DateTimeImmutable($sub['anchor'], $utc);
            } catch (\Exception $e) {
                continue;
            }
            $isFinite = ($sub['settlement'] === 'finite');
            $schedule = isset($sub['schedule']) ? $sub['schedule'] : null;

            // Never bill in the past. An open-ended plan moves its anchor up to as_of;
            // a finite plan keeps its dates, and its past-due installments count at as_of.
            $events = 0;
            while (!$isFinite && $date->getTimestamp() < $asOfTs && $events < self::MAX_EVENTS) {
                $date = self::advance($date, $sub['interval'], $schedule);
                $events++;
            }

            $count = 0;
            $limit = ($sub['remaining_bills'] < 0) ? PHP_INT_MAX : (int) $sub['remaining_bills'];

            while ($date->getTimestamp() <= $horizonEndTs && $count < $limit && $events < self::MAX_EVENTS) {
                // Due before the window and not overdue: left out, but it uses up a bill
                if ($date->getTimestamp() < $asOfTs && ($startsLater || ($nowTs !== null && $date->getTimestamp() >= $nowTs))) {
                    $date = self::advance($date, $sub['interval'], $schedule);
                    $count++;
                    $events++;
                    continue;
                }

                $ts    = max($date->getTimestamp(), $asOfTs);
                $local = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz ? $tz : $utc);
                $key   = ($bucket === 'week')
                    ? $local->format('o-\WW')
                    : (($bucket === 'day') ? $local->format('Y-m-d') : $local->format('Y-m'));

                if (!isset($buckets[$key])) {
                    $buckets[$key] = ['finite' => 0, 'recurring' => 0, 'finite_count' => 0, 'recurring_count' => 0];
                }

                if ($isFinite) {
                    $buckets[$key]['finite'] += $sub['recur'];
                    $buckets[$key]['finite_count']++;
                } else {
                    $buckets[$key]['recurring'] += $sub['recur'];
                    $buckets[$key]['recurring_count']++;
                    if ($ts <= $cutoff30) {
                        $recurring30 += $sub['recur'];
                        if (!isset($byIntervalNext30[$sub['interval']])) {
                            $byIntervalNext30[$sub['interval']] = 0;
                        }
                        $byIntervalNext30[$sub['interval']] += $sub['recur'];
                    }
                    if ($ts <= $cutoff90) {
                        $recurring90 += $sub['recur'];
                    }
                }

                $date = self::advance($date, $sub['interval'], $schedule);
                $count++;
                $events++;
            }
        }

        ksort($buckets);

        return [
            'buckets'              => $buckets,
            'recurring_next_30d'   => $recurring30,
            'recurring_next_90d'   => $recurring90,
            'by_interval_next_30d' => $byIntervalNext30,
        ];
    }

    /**
     * One billing cycle later. Month-based intervals keep the day of month and clamp it into a
     * shorter month (Jan 31 -> Feb 28), as SubscriptionHelper::addBillingInterval() bills;
     * a plain "+1 month" would roll Jan 31 into March and leave February empty.
     */
    public static function advance(\DateTimeImmutable $date, $interval, $schedule = null)
    {
        // A config billing_schedule (every 2 weeks, anchor days) wins over the slug, as in billing
        if ($schedule) {
            return $date->setTimestamp(SubscriptionHelper::addBillingInterval($date->getTimestamp(), $interval, $schedule));
        }

        $months = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12];
        if (!isset($months[$interval])) {
            return $date->modify(self::advanceModifier($interval));
        }

        $target = $date->modify('first day of this month')->modify('+' . $months[$interval] . ' months');
        $day    = min((int) $date->format('j'), (int) $target->format('t'));

        return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), $day);
    }

    /**
     * DateInterval-style modifier to advance one cycle. Calendar months for
     * monthly+, days for weekly/daily, a 30-day fallback for unknowns so an
     * unrecognized interval still projects rather than looping forever. Expects a
     * value already run through ProductFinancialsCalculator::normalizeInterval.
     */
    public static function advanceModifier($interval)
    {
        switch ($interval) {
            case 'monthly':
                return '+1 month';
            case 'quarterly':
                return '+3 months';
            case 'half_yearly':
                return '+6 months';
            case 'yearly':
                return '+1 year';
            case 'weekly':
                return '+7 days';
            case 'daily':
                return '+1 day';
            default:
                return '+30 days';
        }
    }
}
