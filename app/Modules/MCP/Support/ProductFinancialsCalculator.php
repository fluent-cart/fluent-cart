<?php

namespace FluentCart\App\Modules\MCP\Support;

/**
 * Pure financial math for get-product-financials. NO WordPress, NO models, NO
 * __() — every method takes plain arrays and returns plain arrays of integer
 * cents, so the full spec §9 test matrix runs without a database. The tool
 * layer (ProductFinancialsTools) loads rows, normalizes them, calls compute(),
 * and wraps the cents through MCPHelper::money().
 *
 * PHP 7.4 safe: no null-safe, no match, no named args, no enums, no union types.
 *
 * A normalized subscription row is:
 *   [
 *     'billing_interval'  => 'monthly'|'quarterly'|'half_yearly'|'yearly'|'weekly'|'daily'|<other>,
 *     'recurring_total'   => int (cents),
 *     'bill_count'        => int,
 *     'bill_times'        => int,   // 0 => perpetual, >0 => finite
 *     'status'            => string,
 *     'next_billing_date' => 'Y-m-d H:i:s' (UTC) | null,
 *   ]
 *
 * Settlement is derived from bill_times here (single source of truth) — callers
 * never pass it, exactly as Subscription::isInstallment() decides it.
 */
class ProductFinancialsCalculator
{
    /** Average days in a Gregorian month; keeps $150/half_yearly and $300/yearly both at $25 MRR. */
    const DAYS_PER_MONTH = 30.436875;

    /** Seconds in a day (WP's DAY_IN_SECONDS may be absent when run outside WordPress). */
    const DAY_SECONDS = 86400;

    /** Statuses that never collected real money — excluded from every money figure. */
    const NON_COLLECTING = ['intended', 'pending'];

    /** Canonical status set, seeded to 0 so status_breakdown is always complete. */
    const STATUS_KEYS = [
        'active', 'trialing', 'paused', 'canceled', 'failing', 'expired',
        'expiring', 'past_due', 'intended', 'pending', 'completed',
    ];

    /**
     * Map an input interval onto the store's canonical value. Accepts the v1
     * spec aliases (semiannual, annual) for caller convenience; passes real
     * values through untouched.
     */
    public static function normalizeInterval($interval)
    {
        $interval = strtolower(trim((string) $interval));
        $aliases = [
            'semiannual'   => 'half_yearly',
            'semi_annual'  => 'half_yearly',
            'semiannually' => 'half_yearly',
            'biannual'     => 'half_yearly',
            'annual'       => 'yearly',
            'annually'     => 'yearly',
            'yearly'       => 'yearly',
            'monthly'      => 'monthly',
            'quarterly'    => 'quarterly',
            'half_yearly'  => 'half_yearly',
            'weekly'       => 'weekly',
            'daily'        => 'daily',
        ];
        return isset($aliases[$interval]) ? $aliases[$interval] : $interval;
    }

    /**
     * Split subscription rows into the ones matching $currency and the sorted,
     * de-duplicated list of OTHER currencies present. Comparison is
     * case-insensitive (gateways store 'usd'; we report 'USD'). Rows without a
     * currency are treated as the report currency (store default). Never mixes
     * currencies — the tool feeds only the kept rows to compute() and surfaces
     * the others in meta.other_currencies.
     *
     * @return array{0: array, 1: array} [keptRows, otherCurrencies]
     */
    public static function filterByCurrency(array $rows, $currency)
    {
        $target = strtoupper((string) $currency);
        $kept   = [];
        $others = [];
        foreach ($rows as $row) {
            $code = isset($row['currency']) && $row['currency'] !== ''
                ? strtoupper((string) $row['currency'])
                : $target;
            if ($code === $target) {
                $kept[] = $row;
            } elseif (!in_array($code, $others, true)) {
                $others[] = $code;
            }
        }
        sort($others);
        return [$kept, $others];
    }

    /** 'finite' (split-pay) when bill_times > 0, else 'perpetual' (open-ended). */
    public static function settlement($billTimes)
    {
        return ((int) $billTimes > 0) ? 'finite' : 'perpetual';
    }

    /**
     * How many months one billing cycle spans, for MRR normalization. Returns
     * null for an unknown interval so it is excluded from MRR (but still counted
     * and still projected onto the calendar by its day cadence).
     */
    public static function intervalInMonths($interval)
    {
        switch (self::normalizeInterval($interval)) {
            case 'monthly':
                return 1.0;
            case 'quarterly':
                return 3.0;
            case 'half_yearly':
                return 6.0;
            case 'yearly':
                return 12.0;
            case 'weekly':
                return 7.0 / self::DAYS_PER_MONTH;
            case 'daily':
                return 1.0 / self::DAYS_PER_MONTH;
            default:
                return null;
        }
    }

    /** Normalized monthly run-rate contribution in cents (float). 0 for unknown intervals. */
    public static function mrrContributionCents($recurringTotal, $interval)
    {
        $months = self::intervalInMonths($interval);
        if ($months === null || $months <= 0) {
            return 0.0;
        }
        return (float) $recurringTotal / $months;
    }

    /**
     * Full financial rollup. See class doc for the row shape. $opts:
     *   as_of            'Y-m-d H:i:s' UTC (default now)
     *   horizon_months   3|6|12 (default 12)
     *   bucket           'month'|'week' (default month)
     *   forward_statuses array of statuses that feed forward metrics, or ['all']
     *   include_schedule bool — emit payment_schedule[] (30d/90d scalars always computed)
     *   one_time         ['gross'=>int,'refunds'=>int,'units'=>int,'orders'=>int] | null
     *
     * Returns all money as integer cents; the tool wraps it through money().
     */
    public static function compute(array $subscriptions, array $opts = [])
    {
        $asOf     = isset($opts['as_of']) ? $opts['as_of'] : gmdate('Y-m-d H:i:s');
        $horizon  = isset($opts['horizon_months']) ? (int) $opts['horizon_months'] : 12;
        $bucket   = (isset($opts['bucket']) && $opts['bucket'] === 'week') ? 'week' : 'month';
        $forward  = isset($opts['forward_statuses']) ? (array) $opts['forward_statuses'] : ['active'];
        $withSchedule = !empty($opts['include_schedule']);
        $oneTime  = isset($opts['one_time']) && is_array($opts['one_time']) ? $opts['one_time'] : null;

        $asOfTs = strtotime($asOf . ' UTC');
        if ($asOfTs === false) {
            $asOfTs = time();
        }

        $feedsForward = function ($status) use ($forward) {
            if (in_array('all', $forward, true)) {
                return true;
            }
            return in_array($status, $forward, true);
        };

        // ---- Status breakdown (ALWAYS the full picture) ----
        $statusBreakdown = array_fill_keys(self::STATUS_KEYS, 0);
        foreach ($subscriptions as $sub) {
            $st = isset($sub['status']) ? (string) $sub['status'] : '';
            if (!isset($statusBreakdown[$st])) {
                $statusBreakdown[$st] = 0;
            }
            $statusBreakdown[$st]++;
        }

        // ---- Accumulators ----
        $finite = [
            'count' => 0, 'collected' => 0, 'remaining' => 0, 'contract' => 0,
            'remaining_installments' => 0, 'completion_sum' => 0.0, 'completion_n' => 0, 'by_interval' => [],
        ];
        $recurring = [
            'count' => 0, 'collected' => 0, 'mrr' => 0.0, 'by_interval' => [],
        ];

        $projSubs = []; // rows eligible for the forward calendar projection

        foreach ($subscriptions as $sub) {
            $status    = isset($sub['status']) ? (string) $sub['status'] : '';
            $interval  = self::normalizeInterval(isset($sub['billing_interval']) ? $sub['billing_interval'] : '');
            $recur     = (int) (isset($sub['recurring_total']) ? $sub['recurring_total'] : 0);
            $billCount = (int) (isset($sub['bill_count']) ? $sub['bill_count'] : 0);
            $billTimes = (int) (isset($sub['bill_times']) ? $sub['bill_times'] : 0);
            $settle    = self::settlement($billTimes);

            $collects   = !in_array($status, self::NON_COLLECTING, true);
            $isForward  = $feedsForward($status);

            // Lifetime-to-date collected: every real (non-intended/pending) sub.
            if ($collects) {
                if ($settle === 'finite') {
                    $finite['collected'] += $recur * $billCount;
                } else {
                    $recurring['collected'] += $recur * $billCount;
                }
            }

            // Forward commitments / run-rate: only status-filtered subs.
            if ($isForward) {
                if ($settle === 'finite') {
                    $finite['count']++;
                    $installmentsLeft = max(0, $billTimes - $billCount);
                    $remaining = $recur * $installmentsLeft;
                    $finite['remaining'] += $remaining;
                    $finite['remaining_installments'] += $installmentsLeft;
                    $finite['contract']  += $recur * $billTimes;
                    if ($billTimes > 0) {
                        $finite['completion_sum'] += min(1.0, $billCount / $billTimes);
                        $finite['completion_n']++;
                    }
                    if (!isset($finite['by_interval'][$interval])) {
                        $finite['by_interval'][$interval] = ['count' => 0, 'remaining' => 0];
                    }
                    $finite['by_interval'][$interval]['count']++;
                    $finite['by_interval'][$interval]['remaining'] += $remaining;
                } else {
                    $recurring['count']++;
                    $mrrC = self::mrrContributionCents($recur, $interval);
                    $recurring['mrr'] += $mrrC;
                    if (!isset($recurring['by_interval'][$interval])) {
                        $recurring['by_interval'][$interval] = [
                            'count' => 0, 'recurring_total_sum' => 0, 'mrr' => 0.0, 'next_30d' => 0,
                        ];
                    }
                    $recurring['by_interval'][$interval]['count']++;
                    $recurring['by_interval'][$interval]['recurring_total_sum'] += $recur;
                    $recurring['by_interval'][$interval]['mrr'] += $mrrC;
                }

                // Eligible for projection if it has a real next-bill anchor.
                $anchor = isset($sub['next_billing_date']) ? $sub['next_billing_date'] : null;
                if ($anchor !== null && $anchor !== '' && strpos((string) $anchor, '0000-00-00') !== 0) {
                    $projSubs[] = [
                        'settlement'  => $settle,
                        'interval'    => $interval,
                        'recur'       => $recur,
                        'remaining_bills' => ($settle === 'finite') ? max(0, $billTimes - $billCount) : -1,
                        'anchor'      => (string) $anchor,
                    ];
                }
            }
        }

        // ---- Forward calendar projection ----
        $projection = self::project($projSubs, $asOfTs, $horizon, $bucket);

        // Fold recurring next_30d back into per-interval buckets.
        foreach ($projection['by_interval_next_30d'] as $iv => $cents) {
            if (isset($recurring['by_interval'][$iv])) {
                $recurring['by_interval'][$iv]['next_30d'] = $cents;
            }
        }

        $hasPerpetual = $recurring['count'] > 0;

        $mrrCents = (int) round($recurring['mrr']);

        // ---- Assemble the finite block ----
        $finiteOut = [
            'count'                  => $finite['count'],
            'collected_to_date'      => (int) $finite['collected'],
            'scheduled_remaining'    => (int) $finite['remaining'],
            'remaining_installments' => (int) $finite['remaining_installments'],
            'total_contract_value'   => (int) $finite['contract'],
            'avg_completion'         => $finite['completion_n'] > 0
                ? round($finite['completion_sum'] / $finite['completion_n'], 4) : 0,
            'by_interval'            => [],
        ];
        foreach ($finite['by_interval'] as $iv => $b) {
            $finiteOut['by_interval'][$iv] = [
                'count'               => $b['count'],
                'scheduled_remaining' => (int) $b['remaining'],
            ];
        }

        // ---- Assemble the recurring block ----
        $recurringOut = [
            'count'              => $recurring['count'],
            'collected_to_date'  => (int) $recurring['collected'],
            'mrr'                => $mrrCents,
            'arr'                => $mrrCents * 12,
            'by_interval'        => [],
            'next_30d_scheduled' => (int) $projection['recurring_next_30d'],
            'next_90d_scheduled' => (int) $projection['recurring_next_90d'],
        ];
        foreach ($recurring['by_interval'] as $iv => $b) {
            $recurringOut['by_interval'][$iv] = [
                'count'               => $b['count'],
                'recurring_total_sum' => (int) $b['recurring_total_sum'],
                'mrr'                 => (int) round($b['mrr']),
                'next_30d_scheduled'  => (int) $b['next_30d'],
            ];
        }

        // ---- One-time block (cents; window already applied by the caller) ----
        $oneTimeOut = null;
        $oneTimeNet = 0;
        if ($oneTime !== null) {
            $gross   = (int) (isset($oneTime['gross']) ? $oneTime['gross'] : 0);
            $refunds = (int) (isset($oneTime['refunds']) ? $oneTime['refunds'] : 0);
            $orders  = (int) (isset($oneTime['orders']) ? $oneTime['orders'] : 0);
            $oneTimeNet = $gross - $refunds;
            $oneTimeOut = [
                'units'           => (int) (isset($oneTime['units']) ? $oneTime['units'] : 0),
                'orders'          => $orders,
                'gross_collected' => $gross,
                'refunds'         => $refunds,
                'net_collected'   => $oneTimeNet,
                'aov'             => $orders > 0 ? (int) round($gross / $orders) : 0,
            ];
        }

        // ---- Headline totals ----
        $collectedToDate = $oneTimeNet + (int) $finite['collected'] + (int) $recurring['collected'];
        $committedFinite = (int) $finite['remaining'];

        $notes = [];
        if ($hasPerpetual) {
            $totalContracted = null;
            $notes[] = 'total_contracted is null because this product has perpetual (open-ended) subscriptions; use recurring.mrr / recurring.arr and payment_schedule instead.';
        } else {
            $totalContracted = $collectedToDate + $committedFinite;
        }

        $totals = [
            'collected_to_date' => $collectedToDate,
            'committed_finite'  => $committedFinite,
            'total_contracted'  => $totalContracted,
            'mrr'               => $mrrCents,
            'arr'               => $mrrCents * 12,
        ];

        return [
            'one_time'         => $oneTimeOut,
            'subscriptions'    => [
                'finite'           => $finiteOut,
                'recurring'        => $recurringOut,
                'status_breakdown' => $statusBreakdown,
            ],
            'payment_schedule' => $withSchedule ? $projection['schedule'] : null,
            'totals'           => $totals,
            'has_perpetual'    => $hasPerpetual,
            'meta_notes'       => $notes,
        ];
    }

    /**
     * Project future charges onto calendar buckets. Returns:
     *   schedule                 [ {period, finite_installments, recurring_renewals, total_expected} ]
     *   recurring_next_30d/90d   int cents of recurring renewals within 30/90 days of as_of
     *   by_interval_next_30d     [ interval => int cents ] recurring renewals within 30d
     */
    private static function project(array $projSubs, $asOfTs, $horizonMonths, $bucket)
    {
        $horizonEndTs = strtotime('+' . (int) $horizonMonths . ' months', $asOfTs);
        if ($horizonEndTs === false) {
            $horizonEndTs = $asOfTs;
        }

        // The walk itself lives in the shared PaymentProjector so get-upcoming-
        // payments projects on the exact same rules (anchor on next_billing_date,
        // step by interval, stop finite at remaining bills).
        $p = PaymentProjector::project($projSubs, $asOfTs, $horizonEndTs, $bucket);

        $schedule = [];
        foreach ($p['buckets'] as $period => $b) {
            $schedule[] = [
                'period'              => $period,
                'finite_installments' => (int) $b['finite'],
                'recurring_renewals'  => (int) $b['recurring'],
                'total_expected'      => (int) ($b['finite'] + $b['recurring']),
            ];
        }

        return [
            'schedule'             => $schedule,
            'recurring_next_30d'   => $p['recurring_next_30d'],
            'recurring_next_90d'   => $p['recurring_next_90d'],
            'by_interval_next_30d' => $p['by_interval_next_30d'],
        ];
    }
}
