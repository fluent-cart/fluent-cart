<?php

namespace FluentCart\App\Modules\MCP\Support;

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
 *   - Step by the subscription's own interval.
 *   - A finite (bill_times > 0) subscription stops after its remaining bills;
 *     a perpetual one runs to the horizon.
 *
 * No WordPress, no models — plain arrays of integer cents, so it is unit-tested
 * without a database and both callers get identical numbers.
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
     *   ]
     *
     * @param array  $projSubs      descriptors (see above)
     * @param int    $asOfTs        unix ts — never bill before this instant
     * @param int    $horizonEndTs  unix ts — never bill after this instant
     * @param string $bucket        'day' | 'week' | 'month'
     *
     * @return array{
     *   buckets: array<string, array{finite:int,recurring:int,finite_count:int,recurring_count:int}>,
     *   recurring_next_30d:int, recurring_next_90d:int, by_interval_next_30d: array<string,int>
     * } buckets are period-sorted; the next_30d/90d scalars are relative to as_of.
     */
    public static function project(array $projSubs, $asOfTs, $horizonEndTs, $bucket)
    {
        $bucket = ($bucket === 'week' || $bucket === 'day') ? $bucket : 'month';

        $cutoff30 = $asOfTs + 30 * self::DAY_SECONDS;
        $cutoff90 = $asOfTs + 90 * self::DAY_SECONDS;

        $buckets          = [];
        $recurring30      = 0;
        $recurring90      = 0;
        $byIntervalNext30 = [];

        $utc = new \DateTimeZone('UTC');

        foreach ($projSubs as $sub) {
            $modifier = self::advanceModifier($sub['interval']);
            try {
                $date = new \DateTimeImmutable($sub['anchor'], $utc);
            } catch (\Exception $e) {
                continue;
            }
            // Never bill in the past: fast-forward the anchor up to as_of.
            $events = 0;
            while ($date->getTimestamp() < $asOfTs && $events < self::MAX_EVENTS) {
                $date = $date->modify($modifier);
                $events++;
            }

            $count    = 0;
            $limit    = ($sub['remaining_bills'] < 0) ? PHP_INT_MAX : (int) $sub['remaining_bills'];
            $isFinite = ($sub['settlement'] === 'finite');

            while ($date->getTimestamp() <= $horizonEndTs && $count < $limit && $events < self::MAX_EVENTS) {
                $ts  = $date->getTimestamp();
                $key = ($bucket === 'week')
                    ? $date->format('o-\WW')
                    : (($bucket === 'day') ? $date->format('Y-m-d') : $date->format('Y-m'));

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

                $date = $date->modify($modifier);
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
