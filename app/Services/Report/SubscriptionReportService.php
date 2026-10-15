<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Report\Concerns\Subscription\FutureRenewals;

class SubscriptionReportService extends ReportService
{
    use FutureRenewals;

    // Subscriptions that never started; every Retention-tab query leaves them out.
    const RETENTION_EXCLUDED_STATUSES = [
        Status::SUBSCRIPTION_PENDING,
        Status::SUBSCRIPTION_INTENDED,
        'incomplete_expired',
    ];

    public function getRetentionChart($params = [])
    {
        $customDays   = max(0, (int) Arr::get($params, 'customDays', 0));
        $variationIds = Arr::get($params, 'variationIds', []);
        $startDate    = Arr::get($params, 'startDate');
        $endDate      = Arr::get($params, 'endDate');

        $baseQuery = ReportCurrency::fromSubscriptions(App::db()->query(), $params, 's')
            ->whereBetween('s.created_at', [$startDate, $endDate])
            ->where('s.bill_times', '<=', 0)
            ->whereNotIn('s.status', self::RETENTION_EXCLUDED_STATUSES)
            ->when($variationIds, fn ($q) => $q->whereIn('s.variation_id', $variationIds));

        ReportCurrency::whereCurrencyColumn($baseQuery, 's.currency', ReportCurrency::resolve(Arr::get($params, 'currency'), true));

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
        $query = ReportCurrency::fromSubscriptions(App::db()->query(), $params, 's')
            ->selectRaw('
                DATE(s.created_at) AS trend_date, 
                COUNT(s.id) AS value
            ')
            ->whereBetween('s.created_at', [$params['startDate'], $params['endDate']])
            ->where('s.bill_times', '<=', 0)
            ->whereNotIn('s.status', self::RETENTION_EXCLUDED_STATUSES)
            ->when($params['variationIds'], fn ($q) => $q->whereIn('s.variation_id', $params['variationIds']))
            ->groupBy('trend_date')
            ->orderBy('trend_date');

        return ReportCurrency::whereCurrencyColumn($query, 's.currency', ReportCurrency::resolve(Arr::get($params, 'currency'), true))
            ->get();
    }

    public function getChartData(array $params)
    {
        $params = $this->clampParamsToToday($params);
        $startDate = $params['startDate'];
        $endDate = $params['endDate'];
        $subscriptionType = $params['subscriptionType'];

        $group = ReportHelper::processGroup($startDate, $endDate, $params['groupKey']);

        $query = App::db()->query();

        if (in_array($subscriptionType, [Status::ORDER_TYPE_SUBSCRIPTION, Status::ORDER_TYPE_RENEWAL])) {
            // Installment charges are renewal orders too; a renewal reaches its plan through the parent order
            $parentOrderColumn = $subscriptionType === Status::ORDER_TYPE_RENEWAL ? 'o.parent_id' : 'o.id';

            $query->from('fct_orders as o')
                ->where('o.type', $subscriptionType)
                ->whereIn('o.status', Status::getOrderSuccessStatuses())
                ->whereExists(fn ($q) =>
                    $q->selectRaw('1')
                      ->from('fct_subscriptions as s')
                      ->whereRaw("s.parent_order_id = {$parentOrderColumn}")
                      ->where('s.bill_times', '<=', 0)
                );

            $query = $this->applyFilters($query, $params);
        } else {
            $dateColumn = 'o.expire_at';

            if ($subscriptionType === Status::SUBSCRIPTION_CANCELED) {
                $dateColumn = 'o.canceled_at';
            }

            // Alias o is the subscriptions table in this branch
            ReportCurrency::fromSubscriptions($query, $params, 'o')->where('o.status', $subscriptionType)
                ->where('o.bill_times', '<=', 0)
                ->whereBetween($dateColumn, [
                    $startDate->format('Y-m-d H:i:s'),
                    $endDate->format('Y-m-d H:i:s'),
                ])
                ->when($params['variationIds'], fn ($q) => $q->whereIn('o.variation_id', $params['variationIds']));

            ReportCurrency::whereCurrencyColumn($query, 'o.currency', ReportCurrency::resolve(Arr::get($params, 'currency'), true));
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
        $params = $this->clampParamsToToday($params);
        $startDate = $params['startDate'];
        $endDate = $params['endDate'];

        if ($startDate > $endDate) {
            return [];
        }

        $rangeStart = $startDate->format('Y-m-d H:i:s');
        $rangeEnd = $endDate->format('Y-m-d H:i:s');
        $lastMonth = $endDate->format('Y-m');

        $data = [];

        // The first and last month count only what falls inside the requested range.
        for ($dt = $startDate->copy()->startOfMonth(); $dt->format('Y-m') <= $lastMonth; $dt->addMonth()) {
            $monthStart = max($dt->format('Y-m-01 00:00:00'), $rangeStart);
            $monthEnd = min($dt->format('Y-m-t 23:59:59'), $rangeEnd);
            // For labels/keys, we use the end of the month as per requirement
            $labelDate = $dt->format('Y-m-t');

            // A subscription leaves at the earlier of its cancellation and expiry; both NULL means it never left.
            $leftAt = 'LEAST(COALESCE(canceled_at, expire_at), COALESCE(expire_at, canceled_at))';
            $inStart = "created_at < ? AND ({$leftAt} IS NULL OR {$leftAt} >= ?)";
            $inEnd = "created_at <= ? AND ({$leftAt} IS NULL OR {$leftAt} > ?)";
            $isNew = 'created_at BETWEEN ? AND ?';
            $leftByEnd = "{$leftAt} IS NOT NULL AND {$leftAt} <= ?";
            $mrrAmount = "
                    CASE
                        WHEN billing_interval = 'monthly' THEN recurring_amount
                        WHEN billing_interval = 'yearly' THEN recurring_amount / 12
                        WHEN billing_interval = 'weekly' THEN (recurring_amount * 52) / 12
                        WHEN billing_interval = 'daily' THEN recurring_amount * 30
                        ELSE 0
                    END";

            // Churn is measured against the starting base only, so retention % + churn % = 100;
            // sign-ups that also left inside the month are reported apart as churned_new_subscriptions.
            $statsQuery = ReportCurrency::subscriptionsTable($params)
                ->selectRaw("
                SUM(CASE WHEN {$isNew} THEN 1 ELSE 0 END) as new_subscriptions,
                SUM(CASE WHEN {$isNew} THEN {$mrrAmount} ELSE 0 END) / 100 as new_subscriptions_mrr,
                SUM(CASE WHEN {$isNew} THEN recurring_amount ELSE 0 END) / 100 as period_gross,
                SUM(CASE WHEN {$inStart} AND {$leftByEnd} THEN 1 ELSE 0 END) as churned_subscriptions,
                SUM(CASE WHEN {$inStart} AND {$leftByEnd} THEN {$mrrAmount} ELSE 0 END) / 100 as churned_subscriptions_mrr,
                SUM(CASE WHEN {$isNew} AND {$leftByEnd} THEN 1 ELSE 0 END) as churned_new_subscriptions,
                SUM(CASE WHEN {$inEnd} THEN 1 ELSE 0 END) as active_subscriptions,
                SUM(CASE WHEN {$inEnd} AND recurring_amount > 0 THEN 1 ELSE 0 END) as active_paid_subscriptions,
                SUM(CASE WHEN {$inEnd} AND recurring_amount = 0 THEN 1 ELSE 0 END) as active_free_subscriptions,
                SUM(CASE WHEN {$inEnd} THEN {$mrrAmount} ELSE 0 END) / 100 as mrr,
                SUM(CASE WHEN {$inStart} THEN 1 ELSE 0 END) as start_subscriptions,
                SUM(CASE WHEN {$inStart} THEN {$mrrAmount} ELSE 0 END) / 100 as start_mrr
            ", [
                    $monthStart, $monthEnd, // new_subscriptions
                    $monthStart, $monthEnd, // new_subscriptions_mrr
                    $monthStart, $monthEnd, // period_gross
                    $monthStart, $monthStart, $monthEnd, // churned_subscriptions
                    $monthStart, $monthStart, $monthEnd, // churned_subscriptions_mrr
                    $monthStart, $monthEnd, $monthEnd, // churned_new_subscriptions
                    $monthEnd, $monthEnd, // active_subscriptions
                    $monthEnd, $monthEnd, // active_paid_subscriptions
                    $monthEnd, $monthEnd, // active_free_subscriptions
                    $monthEnd, $monthEnd, // mrr
                    $monthStart, $monthStart, // start_subscriptions
                    $monthStart, $monthStart, // start_mrr
                ])
                ->where('created_at', '<=', $monthEnd) // Optimization: Don't scan future rows
                ->whereNotIn('status', self::RETENTION_EXCLUDED_STATUSES)
                ->where('bill_times', '<=', 0)
                ->when(!empty($params['variationIds']), function ($q) use ($params) {
                    $q->whereIn('variation_id', $params['variationIds']);
                });

            // MRR and churn are only summable within one currency
            $stats = ReportCurrency::whereCurrencyColumn($statsQuery, 'currency', ReportCurrency::resolve(Arr::get($params, 'currency'), true))
                ->first();

            $newSubscriptions = (int) $stats->new_subscriptions;
            $newSubscriptionsMrr = round((float) $stats->new_subscriptions_mrr, 2);
            $activeSubscriptions = (int) $stats->active_subscriptions;
            $startSubscriptions = (int) $stats->start_subscriptions;
            $churnedSubscriptions = (int) $stats->churned_subscriptions;
            $churnedMrr = round((float) $stats->churned_subscriptions_mrr, 2);
            $mrr = round((float) $stats->mrr, 2);
            $startMrr = round((float) $stats->start_mrr, 2);

            // Retained = the starting base still active at month end; new sign-ups are not part of it.
            $retainedCount = $startSubscriptions - $churnedSubscriptions;
            $retainedMrr = max(0, round($startMrr - $churnedMrr, 2));
            $retentionRate = $startSubscriptions > 0 ? ($retainedCount / $startSubscriptions) * 100 : 0;
            $retentionRateMoney = $startMrr > 0 ? ($retainedMrr / $startMrr) * 100 : 0;

            $data[] = [
                'day'                       => $labelDate,
                'week'                      => date('Y-W', strtotime($labelDate)),
                'group'                     => $dt->format('Y-m'),
                'year'                      => $dt->format('Y'),
                'new_subscriptions'         => $newSubscriptions,
                'new_subscriptions_mrr'     => $newSubscriptionsMrr,
                'churned_subscriptions'     => $churnedSubscriptions,
                'churned_subscriptions_mrr' => $churnedMrr,
                'churned_new_subscriptions' => (int) $stats->churned_new_subscriptions,
                'retained_subscriptions'    => $retainedCount,
                'retained_mrr'              => number_format($retainedMrr, 2, '.', ''),
                'active_subscriptions'      => (string) $activeSubscriptions,
                'active_paid_subscriptions' => (string) $stats->active_paid_subscriptions,
                'active_free_subscriptions' => (string) $stats->active_free_subscriptions,
                'mrr'                       => number_format($mrr, 2, '.', ''),
                'start_subscriptions'       => $startSubscriptions,
                'start_mrr'                 => number_format($startMrr, 2, '.', ''),
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
     * - Yearly: Start sums each year's monthly cohorts at period_offset 0; Year 1, 2, 3... read 24, 36, 48...
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

        $currency = ReportCurrency::resolve($params['currency'] ?? null);

        if ($groupBy === 'year') {
            $data = $this->getCohortDataByYear($startCohort, $endCohort, $productIds, $metric, $maxPeriods, $currency);
        } else {
            $data = $this->getCohortDataByMonth($startCohort, $endCohort, $productIds, $metric, $maxPeriods, $currency);
        }

        $data['generated_at'] = App::db()->table('fct_retention_snapshots')->max('created_at') ?: null;

        return $data;
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
    protected function getCohortDataByMonth($startCohort, $endCohort, $productIds, $metric, $maxPeriods, $currency = null)
    {
        $query = App::db()->table('fct_retention_snapshots')
            ->whereBetween('cohort', [$startCohort, $endCohort])
            ->where('period_offset', '<=', $maxPeriods)
            ->orderBy('cohort', 'ASC')
            ->orderBy('period_offset', 'ASC');

        ReportCurrency::whereCurrencyColumn($query, 'currency', $currency ?: ReportCurrency::resolve());

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
     * A year's Start is every monthly cohort of that year at period_offset 0. Year 1, 2, 3...
     * read period_offset 24, 36, 48... (after the 1st, 2nd, 3rd renewal), because at offset 12
     * yearly subscribers have not had a renewal opportunity yet and show ~100% retention.
     *
     * @param string $startCohort
     * @param string $endCohort
     * @param array $productIds Array of product IDs to filter by (empty = all products combined)
     * @param string $metric
     * @param int $maxPeriods
     * @return array
     */
    protected function getCohortDataByYear($startCohort, $endCohort, $productIds, $metric, $maxPeriods, $currency = null)
    {
        $maxMonthOffset = ($maxPeriods + 1) * 12;

        $query = App::db()->table('fct_retention_snapshots')
            ->select([
                'cohort',
                'period_offset',
                'cohort_customers',
                'cohort_mrr',
                'retained_customers',
                'retained_mrr',
            ])
            ->whereBetween('cohort', [$startCohort, $endCohort])
            ->where('period_offset', '<=', $maxMonthOffset)
            ->whereRaw('period_offset % 12 = 0')
            ->where('period_offset', '!=', 12)
            ->orderBy('cohort', 'ASC')
            ->orderBy('period_offset', 'ASC');

        ReportCurrency::whereCurrencyColumn($query, 'currency', $currency ?: ReportCurrency::resolve());

        if (!empty($productIds)) {
            $query->whereIn('product_id', $productIds);
        } else {
            $query->whereNull('product_id');
        }

        $snapshots = $query->get();

        if ($snapshots->isEmpty()) {
            return $this->emptyResponse('year', $metric, $maxPeriods);
        }

        // [year][period_offset][monthly cohort] => sums across product rows
        $yearlyData = [];
        foreach ($snapshots as $snapshot) {
            $cohortYear = substr($snapshot->cohort, 0, 4);
            $periodOffset = (int) $snapshot->period_offset;
            $monthlyCohort = $snapshot->cohort;

            if (!isset($yearlyData[$cohortYear][$periodOffset][$monthlyCohort])) {
                $yearlyData[$cohortYear][$periodOffset][$monthlyCohort] = [
                    'cohort_customers'   => 0,
                    'cohort_mrr'         => 0,
                    'retained_customers' => 0,
                    'retained_mrr'       => 0,
                ];
            }

            $row = &$yearlyData[$cohortYear][$periodOffset][$monthlyCohort];
            $row['cohort_customers'] += (int) $snapshot->cohort_customers;
            $row['cohort_mrr'] += (int) $snapshot->cohort_mrr;
            $row['retained_customers'] += (int) $snapshot->retained_customers;
            $row['retained_mrr'] += (int) $snapshot->retained_mrr;
            unset($row);
        }

        $cohortGroups = [];

        foreach ($yearlyData as $cohortYear => $offsets) {
            if (empty($offsets[0])) {
                continue;
            }

            $start = $this->sumMonthlyCohorts($offsets[0]);
            $cohortGroups[$cohortYear] = [
                (object) [
                    'cohort'           => $cohortYear,
                    'period_offset'    => 0,
                    'cohort_customers' => $start['cohort_customers'],
                    'cohort_mrr'       => $start['cohort_mrr'],
                ],
            ];

            for ($yearOffset = 1; $yearOffset <= $maxPeriods; $yearOffset++) {
                $periodOffset = ($yearOffset + 1) * 12;
                if (empty($offsets[$periodOffset])) {
                    continue;
                }

                // Only cohorts that reached this offset count, in the cell and in its previous year
                $matured = $offsets[$periodOffset];
                $cell = $this->sumMonthlyCohorts($matured);

                $previous = ['retained_customers' => $cell['cohort_customers'], 'retained_mrr' => $cell['cohort_mrr']];
                if ($yearOffset > 1) {
                    $previousRows = array_intersect_key(
                        isset($offsets[$periodOffset - 12]) ? $offsets[$periodOffset - 12] : [],
                        $matured
                    );
                    $previous = $this->sumMonthlyCohorts($previousRows);
                }

                $cohortGroups[$cohortYear][] = (object) [
                    'cohort'                   => $cohortYear,
                    'period_offset'            => $yearOffset,
                    'cohort_customers'         => $cell['cohort_customers'],
                    'cohort_mrr'               => $cell['cohort_mrr'],
                    'retained_customers'       => $cell['retained_customers'],
                    'retained_mrr'             => $cell['retained_mrr'],
                    'churned_customers'        => max(0, $cell['cohort_customers'] - $cell['retained_customers']),
                    'retention_rate_customers' => $cell['cohort_customers'] > 0
                        ? round(($cell['retained_customers'] / $cell['cohort_customers']) * 100, 2)
                        : 0,
                    'retention_rate_mrr'       => $cell['cohort_mrr'] > 0
                        ? round(($cell['retained_mrr'] / $cell['cohort_mrr']) * 100, 2)
                        : 0,
                    'prev_retained_customers'  => $previous['retained_customers'],
                    'prev_retained_mrr'        => $previous['retained_mrr'],
                    'matured_cohorts'          => count($matured),
                    'total_cohorts'            => count($offsets[0]),
                ];
            }
        }

        if (empty($cohortGroups)) {
            return $this->emptyResponse('year', $metric, $maxPeriods);
        }

        return $this->buildCohortResponseFromGroups($cohortGroups, 'year', $metric, $maxPeriods);
    }

    protected function sumMonthlyCohorts($rows)
    {
        $sum = ['cohort_customers' => 0, 'cohort_mrr' => 0, 'retained_customers' => 0, 'retained_mrr' => 0];
        foreach ($rows as $row) {
            foreach ($sum as $key => $value) {
                $sum[$key] += $row[$key];
            }
        }

        return $sum;
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

                $baseMrr = $cohortStartMrr;
                $baseCount = $cohortStartCount;
                $cellPrevMrr = $prevRetainedMrr;
                $cellPrevCount = $prevRetainedCount;
                $coverage = [];

                // Year cells cover only the monthly cohorts that reached this offset
                if (isset($snap->matured_cohorts)) {
                    $baseMrr = round((float) $snap->cohort_mrr / 100, 2);
                    $baseCount = (int) $snap->cohort_customers;
                    $cellPrevMrr = round((float) $snap->prev_retained_mrr / 100, 2);
                    $cellPrevCount = (int) $snap->prev_retained_customers;
                    $coverage = [
                        'base_mrr' => $baseMrr,
                        'base_count' => $baseCount,
                        'partial' => $snap->matured_cohorts < $snap->total_cohorts,
                        'matured_cohorts' => $snap->matured_cohorts,
                        'total_cohorts' => $snap->total_cohorts,
                    ];
                }

                $churnedMrr = round($baseMrr - $retainedMrr, 2);

                $retentionRateMrr = (float) $snap->retention_rate_mrr;
                $retentionRateCount = (float) $snap->retention_rate_customers;

                $retentionRatePrevMrr = $cellPrevMrr > 0
                    ? round(($retainedMrr / $cellPrevMrr) * 100, 2)
                    : 0;
                $retentionRatePrevCount = $cellPrevCount > 0
                    ? round(($retainedCount / $cellPrevCount) * 100, 2)
                    : 0;

                $churnRateTotalMrr = round(100 - $retentionRateMrr, 2);
                $churnRateTotalCount = round(100 - $retentionRateCount, 2);

                $periodData[] = array_merge([
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
                ], $coverage);

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
                $weightedData[$offset]['total_start_mrr'] += $baseMrr;
                $weightedData[$offset]['total_start_count'] += $baseCount;
                $weightedData[$offset]['total_retained_mrr'] += $retainedMrr;
                $weightedData[$offset]['total_retained_count'] += $retainedCount;
                $weightedData[$offset]['total_prev_retained_mrr'] += $cellPrevMrr;
                $weightedData[$offset]['total_prev_retained_count'] += $cellPrevCount;

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
