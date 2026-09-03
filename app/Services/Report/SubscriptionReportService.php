<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Report\Concerns\Subscription\FutureRenewals;

class SubscriptionReportService extends ReportService
{
    use FutureRenewals;

    public function getRetentionChart($params = [])
    {
        $customDays   = max(0, (int) Arr::get($params, 'customDays', 0));
        $variationIds = Arr::get($params, 'variationIds', []);
        $startDate    = Arr::get($params, 'startDate');
        $endDate      = Arr::get($params, 'endDate');

        $baseQuery = App::db()->query()
            ->from('fct_subscriptions as s')
            ->whereBetween('s.created_at', [$startDate, $endDate])
            ->when($variationIds, fn ($q) => $q->whereIn('s.variation_id', $variationIds));

        if ($customDays) {
            $query = $baseQuery->selectRaw("COUNT(*) AS day_{$customDays}")
                ->whereRaw('
                    DATEDIFF(
                        COALESCE(s.canceled_at, NOW()),
                        s.created_at
                    ) <= ?
                ', [$customDays]);
        } else {
            $baseQuery->selectRaw('DATEDIFF(COALESCE(s.canceled_at, NOW()), s.created_at) AS lifespan');

            $query = App::db()->query()->selectRaw('
                SUM(CASE WHEN lifespan <= 7 THEN 1 ELSE 0 END) AS day_7,
                SUM(CASE WHEN lifespan BETWEEN 8 AND 15 THEN 1 ELSE 0 END) AS day_15,
                SUM(CASE WHEN lifespan BETWEEN 16 AND 30 THEN 1 ELSE 0 END) AS day_30,
                SUM(CASE WHEN lifespan BETWEEN 31 AND 90 THEN 1 ELSE 0 END) AS day_90,
                SUM(CASE WHEN lifespan BETWEEN 91 AND 180 THEN 1 ELSE 0 END) AS day_180,
                SUM(CASE WHEN lifespan BETWEEN 181 AND 365 THEN 1 ELSE 0 END) AS day_365,
                SUM(CASE WHEN lifespan > 365 THEN 1 ELSE 0 END) AS more_than_year
            ')->fromSub($baseQuery, 'retention_data');
        }

        return $query->first();
    }

    public function getDailySignups($params = [])
    {
        return App::db()->query()
            ->selectRaw('
                DATE(s.created_at) AS trend_date, 
                COUNT(s.id) AS value
            ')
            ->from('fct_subscriptions as s')
            ->whereBetween('s.created_at', [$params['startDate'], $params['endDate']])
            ->when($params['variationIds'], fn ($q) => $q->whereIn('s.variation_id', $params['variationIds']))
            ->groupBy('trend_date')
            ->orderBy('trend_date')
            ->get();
    }

    public function getChartData(array $params)
    {
        $startDate = $params['startDate'];
        $endDate = $params['endDate'];
        $subscriptionType = $params['subscriptionType'];

        $group = ReportHelper::processGroup($startDate, $endDate, $params['groupKey']);

        $query = App::db()->query();

        if (in_array($subscriptionType, [Status::ORDER_TYPE_SUBSCRIPTION, Status::ORDER_TYPE_RENEWAL])) {
            $query->from('fct_orders as o')
                ->where('o.type', $subscriptionType)
                ->whereIn('o.status', Status::getOrderSuccessStatuses());

            $query = $this->applyFilters($query, $params);
        } else {
            $dateColumn = 'o.expire_at';

            if ($subscriptionType === Status::SUBSCRIPTION_CANCELED) {
                $dateColumn = 'o.canceled_at';
            }

            $query->from('fct_subscriptions as o')->where('o.status', $subscriptionType)
                ->whereBetween($dateColumn, [
                    $startDate->format('Y-m-d H:i:s'),
                    $endDate->format('Y-m-d H:i:s'),
                ])
                ->when($params['variationIds'], fn ($q) => $q->whereIn('o.variation_id', $params['variationIds']));
        }

        $query->selectRaw("{$group['field']}, COUNT(o.id) as count")->groupByRaw($group['by']);

        $results = $query->get();

        $keys = ['count'];
        $grouped = $this->getPeriodRange($startDate, $endDate, $group['key'], $keys);
        $totalSubscriptions = 0;

        foreach ($results as $row) {
            $grouped[$row->group] = [
                'year'  => (int) $row->year,
                'group' => $row->group,
                'count' => (int) $row->count,
            ];

            $totalSubscriptions += (int) $row->count;
        }

        return [
            'grouped'            => array_values($grouped),
            'totalSubscriptions' => $totalSubscriptions,
        ];
    }

    public function getRetentionData($params)
    {
        $startDate = $params['startDate'];
        $endDate = $params['endDate'];

        // We want to iterate month by month
        $period = new \DatePeriod(
            $startDate,
            new \DateInterval('P1M'),
            $endDate
        );

        $data = [];

        foreach ($period as $dt) {
            $monthStart = $dt->format('Y-m-01 00:00:00');
            $monthEnd = $dt->format('Y-m-t 23:59:59');
            // For labels/keys, we use the end of the month as per requirement
            $labelDate = $dt->format('Y-m-t');

            $stats = App::db()->table('fct_subscriptions')
                ->selectRaw("
                SUM(
                    CASE 
                        WHEN created_at BETWEEN ? AND ? THEN 1 
                        ELSE 0 
                    END
                ) as new_subscriptions,
                SUM(
                    CASE 
                        WHEN created_at BETWEEN ? AND ? THEN 
                            CASE 
                                WHEN billing_interval = 'monthly' THEN recurring_amount
                                WHEN billing_interval = 'yearly' THEN recurring_amount / 12
                                WHEN billing_interval = 'weekly' THEN (recurring_amount * 52) / 12
                                WHEN billing_interval = 'daily' THEN recurring_amount * 30
                                ELSE 0 
                            END
                        ELSE 0 
                    END
                ) / 100 as new_subscriptions_mrr,
                SUM(
                    CASE 
                        WHEN created_at BETWEEN ? AND ? THEN recurring_amount
                        ELSE 0 
                    END
                ) / 100 as period_gross,
                SUM(
                    CASE 
                        WHEN canceled_at BETWEEN ? AND ? THEN 1 
                        WHEN canceled_at IS NULL AND expire_at BETWEEN ? AND ? THEN 1
                        ELSE 0 
                    END
                ) as churned_subscriptions,
                SUM(
                    CASE 
                        WHEN (canceled_at BETWEEN ? AND ?) OR (canceled_at IS NULL AND expire_at BETWEEN ? AND ?) THEN 
                            CASE 
                                WHEN billing_interval = 'monthly' THEN recurring_amount
                                WHEN billing_interval = 'yearly' THEN recurring_amount / 12
                                WHEN billing_interval = 'weekly' THEN (recurring_amount * 52) / 12
                                WHEN billing_interval = 'daily' THEN recurring_amount * 30
                                ELSE 0 
                            END
                        ELSE 0 
                    END
                ) / 100 as churned_subscriptions_mrr,
                SUM(
                    CASE 
                        WHEN created_at <= ? 
                             AND (canceled_at IS NULL OR canceled_at > ?) 
                             AND (expire_at IS NULL OR expire_at > ?) 
                        THEN 1 
                        ELSE 0 
                    END
                ) as active_subscriptions,
                SUM(
                    CASE 
                        WHEN created_at <= ? 
                             AND (canceled_at IS NULL OR canceled_at > ?) 
                             AND (expire_at IS NULL OR expire_at > ?) 
                             AND recurring_amount > 0
                        THEN 1 
                        ELSE 0 
                    END
                ) as active_paid_subscriptions,
                SUM(
                    CASE 
                        WHEN created_at <= ? 
                             AND (canceled_at IS NULL OR canceled_at > ?) 
                             AND (expire_at IS NULL OR expire_at > ?) 
                             AND recurring_amount = 0
                        THEN 1 
                        ELSE 0 
                    END
                ) as active_free_subscriptions,
                SUM(
                    CASE 
                        WHEN created_at <= ? 
                             AND (canceled_at IS NULL OR canceled_at > ?) 
                             AND (expire_at IS NULL OR expire_at > ?) 
                        THEN 
                            CASE 
                                WHEN billing_interval = 'monthly' THEN recurring_amount
                                WHEN billing_interval = 'yearly' THEN recurring_amount / 12
                                WHEN billing_interval = 'weekly' THEN (recurring_amount * 52) / 12
                                WHEN billing_interval = 'daily' THEN recurring_amount * 30
                                ELSE 0 
                            END
                        ELSE 0 
                    END
                ) / 100 as mrr,
                SUM(
                    CASE 
                        WHEN created_at <= ? 
                             AND (canceled_at IS NULL OR canceled_at > ?) 
                             AND (expire_at IS NULL OR expire_at > ?) 
                        THEN 1 
                        ELSE 0 
                    END
                ) as start_subscriptions,
                SUM(
                    CASE 
                        WHEN created_at <= ? 
                             AND (canceled_at IS NULL OR canceled_at > ?) 
                             AND (expire_at IS NULL OR expire_at > ?) 
                        THEN 
                            CASE 
                                WHEN billing_interval = 'monthly' THEN recurring_amount
                                WHEN billing_interval = 'yearly' THEN recurring_amount / 12
                                WHEN billing_interval = 'weekly' THEN (recurring_amount * 52) / 12
                                WHEN billing_interval = 'daily' THEN recurring_amount * 30
                                ELSE 0 
                            END
                        ELSE 0 
                    END
                ) / 100 as start_mrr
            ", [
                    $monthStart, $monthEnd, // new_subscriptions
                    $monthStart, $monthEnd, // new_subscriptions_mrr
                    $monthStart, $monthEnd, // period_gross
                    $monthStart, $monthEnd, $monthStart, $monthEnd, // churned_subscriptions (canceled OR expired)
                    $monthStart, $monthEnd, $monthStart, $monthEnd, // churned_subscriptions_mrr (canceled OR expired)
                    $monthEnd, $monthEnd, $monthEnd, // active_subscriptions
                    $monthEnd, $monthEnd, $monthEnd, // active_paid_subscriptions
                    $monthEnd, $monthEnd, $monthEnd, // active_free_subscriptions
                    $monthEnd, $monthEnd, $monthEnd, // mrr
                    $monthStart, $monthStart, $monthStart, // start_subscriptions
                    $monthStart, $monthStart, $monthStart // start_mrr
                ])
                ->where('created_at', '<=', $monthEnd) // Optimization: Don't scan future rows
                // ->whereIn('status', Status::getValidableSubscriptionStatuses())
                ->whereNotIn('status', [Status::SUBSCRIPTION_PENDING, Status::SUBSCRIPTION_INTENDED])
                ->when(!empty($params['variationIds']), function ($q) use ($params) {
                    $q->whereIn('variation_id', $params['variationIds']);
                })
                ->first();

            $newSubscriptions = (int) $stats->new_subscriptions;
            $newSubscriptionsMrr = round((float) $stats->new_subscriptions_mrr, 2);
            $activeSubscriptions = (int) $stats->active_subscriptions;
            $startSubscriptions = (int) $stats->start_subscriptions;
            $mrr = round((float) $stats->mrr, 2);
            $startMrr = round((float) $stats->start_mrr, 2);

            // Retention Rate = ((End Count - New Count) / Start Count) * 100
            $retentionRate = 0;
            if ($startSubscriptions > 0) {
                $retainedCount = $activeSubscriptions - $newSubscriptions;
                $retainedCount = max(0, $retainedCount);
                $retentionRate = ($retainedCount / $startSubscriptions) * 100;
            }

            // MRR Retention Rate = ((End MRR - New MRR) / Start MRR) * 100
            $retentionRateMoney = 0;
            if ($startMrr > 0) {
                $retainedMrr = $mrr - $newSubscriptionsMrr;
                $retainedMrr = max(0, $retainedMrr);
                $retentionRateMoney = ($retainedMrr / $startMrr) * 100;
            }

            $data[] = [
                'day'                       => $labelDate,
                'week'                      => date('Y-W', strtotime($labelDate)),
                'group'                     => $dt->format('Y-m'),
                'year'                      => $dt->format('Y'),
                'new_subscriptions'         => $newSubscriptions,
                'new_subscriptions_mrr'     => $newSubscriptionsMrr,
                'churned_subscriptions'     => (int) $stats->churned_subscriptions,
                'churned_subscriptions_mrr' => round((float) $stats->churned_subscriptions_mrr, 2),
                'active_subscriptions'      => (string) $activeSubscriptions,
                'active_paid_subscriptions' => (string) $stats->active_paid_subscriptions,
                'active_free_subscriptions' => (string) $stats->active_free_subscriptions,
                'mrr'                       => number_format($mrr, 2, '.', ''),
                'retention_rate'            => round($retentionRate, 2),
                'retention_rate_money'      => round($retentionRateMoney, 2),
                'period_gross'              => round((float) $stats->period_gross, 2),
                'period_subscriptions'      => $newSubscriptions,
            ];
        }

        return $data;
    }

    /**
     * Get cohort retention data from pre-calculated snapshots table
     * 
     * Supports both monthly and yearly g  * - Monthly: Uses period_offset 1, 2, 3... (each month)
     * - Yearly: Aggregates cohorts by year and uses period_offset 12, 24, 36... (each year)
     * 
     * @param array $params
     * @return array
     */
    public function getCohortData($params = [])
    {
        $startDate = $params['startDate'] ?? null;
        $endDate = $params['endDate'] ?? null;
        $productIds = $params['productIds'] ?? []; // Array of product IDs (converted from variation_ids)
        $groupBy = $params['groupBy'] ?? 'month'; // month or year
        $metric = $params['metric'] ?? 'mrr';
        $maxPeriods = $params['maxPeriods'] ?? 12;

        if (!$startDate || !$endDate) {
            return [];
        }

        // Convert dates to YYYY-MM format for cohort filtering
        $startCohort = $startDate->format('Y-m');
        $endCohort = $endDate->format('Y-m');

        if ($groupBy === 'year') {
            return $this->getCohortDataByYear($startCohort, $endCohort, $productIds, $metric, $maxPeriods);
        }

        return $this->getCohortDataByMonth($startCohort, $endCohort, $productIds, $metric, $maxPeriods);
    }

    /**
     * Get cohort data grouped by month
     * 
     * @param string $startCohort
     * @param string $endCohort
     * @param array $productIds Array of product IDs to filter by (empty = all products combined)
     * @param string $metric
     * @param int $maxPeriods
     * @return array
     */
    protected function getCohortDataByMonth($startCohort, $endCohort, $productIds, $metric, $maxPeriods)
    {
        $query = App::db()->table('fct_retention_snapshots')
            ->whereBetween('cohort', [$startCohort, $endCohort])
            ->where('period_offset', '<=', $maxPeriods)
            ->orderBy('cohort', 'ASC')
            ->orderBy('period_offset', 'ASC');

        if (!empty($productIds)) {
            // Filter by specific product IDs and aggregate
            $query->selectRaw("
                cohort,
                period_offset,
                SUM(cohort_customers) as cohort_customers,
                SUM(cohort_mrr) as cohort_mrr,
                SUM(retained_customers) as retained_customers,
                SUM(retained_mrr) as retained_mrr,
                SUM(churned_customers) as churned_customers
            ")
            ->whereIn('product_id', $productIds)
            ->groupBy('cohort', 'period_offset');
        } else {
            // Use pre-aggregated "all products" data (product_id IS NULL)
            $query->select([
                'cohort',
                'period_offset',
                'cohort_customers',
                'cohort_mrr',
                'retained_customers',
                'retained_mrr',
                'churned_customers',
                'retention_rate_customers',
                'retention_rate_mrr',
            ])
            ->whereNull('product_id');
        }

        $snapshots = $query->get();

        if ($snapshots->isEmpty()) {
            return $this->emptyResponse('month', $metric, $maxPeriods);
        }

        // If we aggregated by product_ids, we need to recalculate retention rates
        if (!empty($productIds)) {
            $snapshots = $snapshots->map(function ($snap) {
                $snap->retention_rate_customers = $snap->cohort_customers > 0
                    ? round(($snap->retained_customers / $snap->cohort_customers) * 100, 2)
                    : 0;
                $snap->retention_rate_mrr = $snap->cohort_mrr > 0
                    ? round(($snap->retained_mrr / $snap->cohort_mrr) * 100, 2)
                    : 0;
                return $snap;
            });
        }

        return $this->buildCohortResponse($snapshots, 'month', $metric, $maxPeriods);
    }

    /**
     * Get cohort data grouped by year
     * 
     * For yearly view, we use period_offset = 12 as the baseline (end of year 1, before first renewal)
     * and show period_offset 24, 36, 48... as Year 1, Year 2, Year 3 (after 1st, 2nd, 3rd renewal)
     * 
     * This is because at period_offset = 12, yearly subscribers haven't had a renewal opportunity yet,
     * so they show ~100% retention. The actual churn happens after the first renewal at offset 12+.
     * 
     * @param string $startCohort
     * @param string $endCohort
     * @param array $productIds Array of product IDs to filter by (empty = all products combined)
     * @param string $metric
     * @param int $maxPeriods
     * @return array
     */
    protected function getCohortDataByYear($startCohort, $endCohort, $productIds, $metric, $maxPeriods)
    {
        // We need offsets 12, 24, 36... where 12 is baseline, 24 is Year 1, etc.
        // So max offset = (maxPeriods + 1) * 12 to include enough data
        $maxMonthOffset = ($maxPeriods + 1) * 12;

        $query = App::db()->table('fct_retention_snapshots')
            ->whereBetween('cohort', [$startCohort, $endCohort])
            ->where('period_offset', '<=', $maxMonthOffset)
            ->whereRaw('period_offset % 12 = 0')
            ->where('period_offset', '>=', 12) // Start from 12 (end of year 1)
            ->orderBy('cohort', 'ASC')
            ->orderBy('period_offset', 'ASC');

        if (!empty($productIds)) {
            // Filter by specific product IDs
            $query->select([
                'cohort',
                'period_offset',
                'cohort_customers',
                'cohort_mrr',
                'retained_customers',
                'retained_mrr',
                'churned_customers',
            ])
            ->whereIn('product_id', $productIds);
        } else {
            // Use pre-aggregated "all products" data
            $query->select([
                'cohort',
                'period_offset',
                'cohort_customers',
                'cohort_mrr',
                'retained_customers',
                'retained_mrr',
                'churned_customers',
                'retention_rate_customers',
                'retention_rate_mrr',
            ])
            ->whereNull('product_id');
        }

        $snapshots = $query->get();

        if ($snapshots->isEmpty()) {
            return $this->emptyResponse('year', $metric, $maxPeriods);
        }

        // Group by year, then by period_offset
        // period_offset 12 -> yearOffset 0 (baseline)
        // period_offset 24 -> yearOffset 1 (Year 1 - after 1st renewal)
        // period_offset 36 -> yearOffset 2 (Year 2 - after 2nd renewal)
        $yearlyData = [];
        
        foreach ($snapshots as $snapshot) {
            $cohortYear = substr($snapshot->cohort, 0, 4);
            // Shift: 12->0, 24->1, 36->2, etc.
            $yearOffset = ((int) $snapshot->period_offset / 12) - 1;
            
            if (!isset($yearlyData[$cohortYear])) {
                $yearlyData[$cohortYear] = [];
            }
            
            if (!isset($yearlyData[$cohortYear][$yearOffset])) {
                $yearlyData[$cohortYear][$yearOffset] = [
                    'cohort_customers' => 0,
                    'cohort_mrr' => 0,
                    'retained_customers' => 0,
                    'retained_mrr' => 0,
                    'churned_customers' => 0,
                ];
            }
            
            // Aggregate: sum up all monthly cohorts for this year at this offset
            $yearlyData[$cohortYear][$yearOffset]['cohort_customers'] += (int) $snapshot->cohort_customers;
            $yearlyData[$cohortYear][$yearOffset]['cohort_mrr'] += (int) $snapshot->cohort_mrr;
            $yearlyData[$cohortYear][$yearOffset]['retained_customers'] += (int) $snapshot->retained_customers;
            $yearlyData[$cohortYear][$yearOffset]['retained_mrr'] += (int) $snapshot->retained_mrr;
            $yearlyData[$cohortYear][$yearOffset]['churned_customers'] += (int) $snapshot->churned_customers;
        }

        // Convert to cohortGroups format expected by buildCohortResponseFromGroups
        $cohortGroups = [];
        
        foreach ($yearlyData as $cohortYear => $offsets) {
            $cohortGroups[$cohortYear] = [];
            
            foreach ($offsets as $yearOffset => $data) {
                // Calculate retention rates from aggregated data
                $retentionRateCustomers = $data['cohort_customers'] > 0 
                    ? round(($data['retained_customers'] / $data['cohort_customers']) * 100, 2) 
                    : 0;
                $retentionRateMrr = $data['cohort_mrr'] > 0 
                    ? round(($data['retained_mrr'] / $data['cohort_mrr']) * 100, 2) 
                    : 0;
                
                $cohortGroups[$cohortYear][] = (object) [
                    'cohort' => $cohortYear,
                    'period_offset' => $yearOffset,
                    'cohort_customers' => $data['cohort_customers'],
                    'cohort_mrr' => $data['cohort_mrr'],
                    'retained_customers' => $data['retained_customers'],
                    'retained_mrr' => $data['retained_mrr'],
                    'churned_customers' => $data['churned_customers'],
                    'retention_rate_customers' => $retentionRateCustomers,
                    'retention_rate_mrr' => $retentionRateMrr,
                ];
            }
        }

        return $this->buildCohortResponseFromGroups($cohortGroups, 'year', $metric, $maxPeriods);
    }

    /**
     * Build cohort response from raw snapshots
     */
    protected function buildCohortResponse($snapshots, $groupBy, $metric, $maxPeriods)
    {
        $cohortGroups = [];
        foreach ($snapshots as $snapshot) {
            $cohort = $snapshot->cohort;
            if (!isset($cohortGroups[$cohort])) {
                $cohortGroups[$cohort] = [];
            }
            $cohortGroups[$cohort][] = $snapshot;
        }

        return $this->buildCohortResponseFromGroups($cohortGroups, $groupBy, $metric, $maxPeriods);
    }

    /**
     * Build cohort response from grouped data
     */
    protected function buildCohortResponseFromGroups($cohortGroups, $groupBy, $metric, $maxPeriods)
    {
        $cohortData = [];
        $weightedData = [];

        foreach ($cohortGroups as $cohortPeriod => $snapshots) {
            // Get baseline from offset 0
            $baselineSnapshot = null;
            foreach ($snapshots as $snap) {
                if ((int) $snap->period_offset === 0) {
                    $baselineSnapshot = $snap;
                    break;
                }
            }

            if (!$baselineSnapshot) {
                $baselineSnapshot = $snapshots[0];
            }

            $cohortStartMrr = round((float) $baselineSnapshot->cohort_mrr / 100, 2);
            $cohortStartCount = (int) $baselineSnapshot->cohort_customers;

            $periodData = [];
            $snapshotsByOffset = [];
            
            foreach ($snapshots as $snap) {
                $snapshotsByOffset[(int) $snap->period_offset] = $snap;
            }

            $prevRetainedMrr = $cohortStartMrr;
            $prevRetainedCount = $cohortStartCount;

            for ($offset = 1; $offset <= $maxPeriods; $offset++) {
                if (!isset($snapshotsByOffset[$offset])) {
                    $periodData[] = [
                        'offset' => $offset,
                        'retained_mrr' => null,
                        'retained_count' => null,
                        'churned_mrr' => null,
                        'churned_count' => null,
                        'retention_rate_mrr' => null,
                        'retention_rate_count' => null,
                        'retention_rate_previous_mrr' => null,
                        'retention_rate_previous_count' => null,
                        'churn_rate_total_mrr' => null,
                        'churn_rate_total_count' => null,
                    ];
                    continue;
                }

                $snap = $snapshotsByOffset[$offset];
                
                $retainedMrr = round((float) $snap->retained_mrr / 100, 2);
                $retainedCount = (int) $snap->retained_customers;
                $churnedCount = (int) $snap->churned_customers;
                $churnedMrr = round($cohortStartMrr - $retainedMrr, 2);

                $retentionRateMrr = (float) $snap->retention_rate_mrr;
                $retentionRateCount = (float) $snap->retention_rate_customers;

                $retentionRatePrevMrr = $prevRetainedMrr > 0 
                    ? round(($retainedMrr / $prevRetainedMrr) * 100, 2) 
                    : 0;
                $retentionRatePrevCount = $prevRetainedCount > 0 
                    ? round(($retainedCount / $prevRetainedCount) * 100, 2) 
                    : 0;

                $churnRateTotalMrr = round(100 - $retentionRateMrr, 2);
                $churnRateTotalCount = round(100 - $retentionRateCount, 2);

                $periodData[] = [
                    'offset' => $offset,
                    'retained_mrr' => $retainedMrr,
                    'retained_count' => $retainedCount,
                    'churned_mrr' => max(0, $churnedMrr),
                    'churned_count' => $churnedCount,
                    'retention_rate_mrr' => $retentionRateMrr,
                    'retention_rate_count' => $retentionRateCount,
                    'retention_rate_previous_mrr' => $retentionRatePrevMrr,
                    'retention_rate_previous_count' => $retentionRatePrevCount,
                    'churn_rate_total_mrr' => $churnRateTotalMrr,
                    'churn_rate_total_count' => $churnRateTotalCount,
                ];

                if (!isset($weightedData[$offset])) {
                    $weightedData[$offset] = [
                        'total_start_mrr' => 0,
                        'total_start_count' => 0,
                        'total_retained_mrr' => 0,
                        'total_retained_count' => 0,
                        'total_prev_retained_mrr' => 0,
                        'total_prev_retained_count' => 0,
                    ];
                }
                $weightedData[$offset]['total_start_mrr'] += $cohortStartMrr;
                $weightedData[$offset]['total_start_count'] += $cohortStartCount;
                $weightedData[$offset]['total_retained_mrr'] += $retainedMrr;
                $weightedData[$offset]['total_retained_count'] += $retainedCount;
                $weightedData[$offset]['total_prev_retained_mrr'] += $prevRetainedMrr;
                $weightedData[$offset]['total_prev_retained_count'] += $prevRetainedCount;

                $prevRetainedMrr = $retainedMrr;
                $prevRetainedCount = $retainedCount;
            }

            $cohortData[] = [
                'cohort' => $cohortPeriod,
                'start_mrr' => $cohortStartMrr,
                'start_count' => $cohortStartCount,
                'periods' => $periodData,
            ];
        }

        $weightedAverages = $this->calculateWeightedAverages($weightedData);

        return [
            'cohorts' => $cohortData,
            'weighted_averages' => $weightedAverages,
            'group_by' => $groupBy,
            'metric' => $metric,
            'max_periods' => $maxPeriods,
        ];
    }

    /**
     * Calculate weighted averages from accumulated data
     */
    protected function calculateWeightedAverages($weightedData)
    {
        $weightedAverages = [];
        
        foreach ($weightedData as $offset => $data) {
            $avgMrr = $data['total_start_mrr'] > 0 
                ? ($data['total_retained_mrr'] / $data['total_start_mrr']) * 100 
                : 0;
            $avgCount = $data['total_start_count'] > 0 
                ? ($data['total_retained_count'] / $data['total_start_count']) * 100 
                : 0;

            $avgPrevMrr = $data['total_prev_retained_mrr'] > 0 
                ? ($data['total_retained_mrr'] / $data['total_prev_retained_mrr']) * 100 
                : 0;
            $avgPrevCount = $data['total_prev_retained_count'] > 0 
                ? ($data['total_retained_count'] / $data['total_prev_retained_count']) * 100 
                : 0;

            $avgChurnMrr = 100 - $avgMrr;
            $avgChurnCount = 100 - $avgCount;

            $weightedAverages[] = [
                'offset' => $offset,
                'weighted_avg_mrr' => round($avgMrr, 2),
                'weighted_avg_count' => round($avgCount, 2),
                'weighted_avg_prev_mrr' => round($avgPrevMrr, 2),
                'weighted_avg_prev_count' => round($avgPrevCount, 2),
                'weighted_avg_churn_mrr' => round($avgChurnMrr, 2),
                'weighted_avg_churn_count' => round($avgChurnCount, 2),
            ];
        }

        return $weightedAverages;
    }

    /**
     * Return empty response structure
     */
    protected function emptyResponse($groupBy, $metric, $maxPeriods)
    {
        return [
            'cohorts' => [],
            'weighted_averages' => [],
            'group_by' => $groupBy,
            'metric' => $metric,
            'max_periods' => $maxPeriods,
        ];
    }
}
