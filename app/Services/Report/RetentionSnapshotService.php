<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\Database\Migrations\RetentionSnapshotsMigrator;

class RetentionSnapshotService
{
    /**
     * Statuses to exclude from analysis (never really started)
     */
    protected array $excludedStatuses = [
        Status::SUBSCRIPTION_PENDING,
        Status::SUBSCRIPTION_INTENDED,
        'incomplete_expired',
    ];

    /**
     * Active statuses
     */
    protected array $activeStatuses = [
        Status::SUBSCRIPTION_ACTIVE,
        Status::SUBSCRIPTION_TRIALING,
    ];

    /**
     * Generate retention snapshots
     * 
     * @param int|null $productIdFilter Optional product ID to filter by
     * @param callable|null $progressCallback Optional callback for progress updates: fn(string $message, string $level = 'info')
     * @return array Result with 'success', 'message', and 'stats'
     */
    public function generate(?int $productIdFilter = null, ?callable $progressCallback = null): array
    {
        try {
            $this->log('Starting retention snapshot generation...', 'info', $progressCallback);

            // Step 1: Ensure table exists
            $this->log('Step 1: Ensuring database table exists...', 'info', $progressCallback);
            $this->ensureTableExists();
            $this->log('Table ready.', 'success', $progressCallback);

            // Step 2: Truncate existing data
            $this->log('Step 2: Clearing existing data...', 'info', $progressCallback);
            $this->truncateTable();
            $this->log('Data cleared.', 'success', $progressCallback);

            // Step 3: Get all customer-product pairs
            $this->log('Step 3: Fetching customer-product pairs...', 'info', $progressCallback);
            $pairs = $this->getCustomerProductPairs($productIdFilter);
            $totalPairs = count($pairs);
            $this->log("Found {$totalPairs} unique customer-product pairs.", 'success', $progressCallback);

            if ($totalPairs === 0) {
                $this->log('No data to process. Exiting.', 'warning', $progressCallback);
                return [
                    'success' => false,
                    'message' => __('No data to process', 'fluent-cart'),
                    'stats' => [],
                ];
            }

            // Step 4: Get date range
            $this->log('Step 4: Calculating date range...', 'info', $progressCallback);
            $dateRange = $this->getDateRange($productIdFilter);
            $this->log(
                "Date range: {$dateRange['first_month']} to {$dateRange['last_month']} ({$dateRange['total_months']} months)",
                'success',
                $progressCallback
            );

            // Step 5 & 6: Build timelines and aggregate snapshots
            $this->log('Step 5 & 6: Building timelines and aggregating snapshots...', 'info', $progressCallback);
            $inserted = $this->buildTimelinesAndAggregate($pairs, $dateRange, $progressCallback);
            $this->log("Aggregation and insertion complete. Total records: {$inserted}", 'success', $progressCallback);

            // Get stats
            $stats = $this->getStats();

            return [
                'success' => true,
                'message' => __('Retention snapshots generated successfully', 'fluent-cart'),
                'stats' => $stats,
            ];
        } catch (\Exception $e) {
            $this->log('Error: ' . $e->getMessage(), 'error', $progressCallback);
            return [
                'success' => false,
                'message' => sprintf(
                /* translators: %1$s: error message */
                    __('Error: %1$s', 'fluent-cart'),
                    $e->getMessage()
                ),
                'stats' => [],
            ];
        }
    }

    /**
     * Log a message
     */
    protected function log(string $message, string $level = 'info', ?callable $callback = null): void
    {
        if ($callback) {
            call_user_func($callback, $message, $level);
        }
    }

    /**
     * Ensure the retention snapshots table exists
     */
    protected function ensureTableExists(): void
    {
        RetentionSnapshotsMigrator::migrate();
    }

    /**
     * Truncate the snapshots table
     */
    protected function truncateTable(): void
    {
        App::db()->table('fct_retention_snapshots')->truncate();
    }

    /**
     * Get all unique customer-product pairs
     */
    protected function getCustomerProductPairs(?int $productIdFilter = null): array
    {
        $query = App::db()->query()
            ->from('fct_subscriptions')
            ->select(['customer_id', 'product_id'])
            ->whereNotNull('customer_id')
            ->whereNotNull('product_id')
            ->where('customer_id', '>', 0)
            ->where('product_id', '>', 0)
            ->whereNotIn('status', $this->excludedStatuses)
            ->groupBy(['customer_id', 'product_id']);

        if ($productIdFilter) {
            $query->where('product_id', $productIdFilter);
        }

        return $query->get()->toArray();
    }

    /**
     * Get the date range for analysis
     */
    protected function getDateRange(?int $productIdFilter = null): array
    {
        $query = App::db()->query()
            ->from('fct_subscriptions')
            ->selectRaw('MIN(created_at) as first_date, MAX(created_at) as last_date')
            ->whereNotIn('status', $this->excludedStatuses);

        if ($productIdFilter) {
            $query->where('product_id', $productIdFilter);
        }

        $result = $query->first();

        $firstDate = new \DateTime($result->first_date);
        $lastDate = new \DateTime(); // Use today as the end date

        $firstMonth = $firstDate->format('Y-m');
        $lastMonth = $lastDate->format('Y-m');

        // Calculate total months
        $interval = $firstDate->diff($lastDate);
        $totalMonths = ($interval->y * 12) + $interval->m + 1;

        // Generate all months
        $months = [];
        $current = new \DateTime($firstMonth . '-01');
        $end = new \DateTime($lastMonth . '-01');

        while ($current <= $end) {
            $months[] = $current->format('Y-m');
            $current->modify('+1 month');
        }

        return [
            'first_month'  => $firstMonth,
            'last_month'   => $lastMonth,
            'total_months' => $totalMonths,
            'months'       => $months,
        ];
    }

    /**
     * Build customer timelines - for each customer-product pair, determine their state in each month
     */
    protected function buildCustomerTimelines(array $pairs, array $dateRange, ?callable $progressCallback = null): array
    {
        $timelines = [];

        // OPTIMIZATION: Fetch ALL subscriptions in one query, then group in memory
        $this->log('Fetching all subscriptions in one query...', 'info', $progressCallback);
        
        $allSubscriptions = App::db()->query()
            ->from('fct_subscriptions')
            ->whereNotNull('customer_id')
            ->whereNotNull('product_id')
            ->where('customer_id', '>', 0)
            ->where('product_id', '>', 0)
            ->whereNotIn('status', $this->excludedStatuses)
            ->orderBy('created_at', 'ASC')
            ->get();

        $this->log('Fetched ' . count($allSubscriptions) . ' subscriptions.', 'info', $progressCallback);

        // Fetch last payment dates for all subscriptions in one query
        $this->log('Fetching last payment dates...', 'info', $progressCallback);
        
        $lastPayments = App::db()->query()
            ->from('fct_order_transactions')
            ->select(['subscription_id', App::db()->raw('MAX(created_at) as last_payment')])
            ->whereNotNull('subscription_id')
            ->where('status', 'succeeded')
            ->groupBy('subscription_id')
            ->get();

        // Index last payments by subscription_id
        $lastPaymentMap = [];
        foreach ($lastPayments as $lp) {
            $lastPaymentMap[$lp->subscription_id] = $lp->last_payment;
        }
        unset($lastPayments);

        $this->log('Found last payment dates for ' . count($lastPaymentMap) . ' subscriptions.', 'info', $progressCallback);

        // Attach last_payment to each subscription
        foreach ($allSubscriptions as &$sub) {
            $sub->last_payment = isset($lastPaymentMap[$sub->id]) ? $lastPaymentMap[$sub->id] : null;
        }
        unset($sub);
        unset($lastPaymentMap);

        $this->log('Grouping by customer-product pairs...', 'info', $progressCallback);

        // Group subscriptions by customer_id + product_id in memory
        $grouped = [];
        foreach ($allSubscriptions as $sub) {
            $key = "{$sub->customer_id}_{$sub->product_id}";
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $sub;
        }

        // Free memory
        unset($allSubscriptions);

        $this->log('Grouped into ' . count($grouped) . ' customer-product pairs.', 'info', $progressCallback);
        $this->log('Processing timelines...', 'info', $progressCallback);

        foreach ($grouped as $key => $subscriptions) {
            if (empty($subscriptions)) {
                continue;
            }

            $firstSub = $subscriptions[0];
            $customerId = $firstSub->customer_id;
            $productId = $firstSub->product_id;

            // Determine cohort (first subscription month)
            $cohort = (new \DateTime($firstSub->created_at))->format('Y-m');

            // Build monthly state
            $monthlyState = [];
            foreach ($dateRange['months'] as $month) {
                $state = $this->getCustomerStateForMonth($subscriptions, $month);
                $monthlyState[$month] = $state;
            }

            $timelines[$key] = [
                'customer_id' => $customerId,
                'product_id'  => $productId,
                'cohort'      => $cohort,
                'monthly'     => $monthlyState,
            ];
        }

        return $timelines;
    }

    /**
     * Build timelines and aggregate - optimized to fetch all data once but insert in batches
     */
    protected function buildTimelinesAndAggregate(
        array $pairs,
        array $dateRange,
        ?callable $progressCallback
    ): int {
        // Fetch ALL subscriptions in one query
        $this->log('Fetching all subscriptions...', 'info', $progressCallback);
        
        $allSubscriptions = App::db()->query()
            ->from('fct_subscriptions')
            ->whereNotNull('customer_id')
            ->whereNotNull('product_id')
            ->where('customer_id', '>', 0)
            ->where('product_id', '>', 0)
            ->whereNotIn('status', $this->excludedStatuses)
            ->orderBy('created_at', 'ASC')
            ->get();

        $this->log('Fetched ' . count($allSubscriptions) . ' subscriptions.', 'info', $progressCallback);

        // Fetch last payment dates
        $this->log('Fetching last payment dates...', 'info', $progressCallback);
        
        $lastPayments = App::db()->query()
            ->from('fct_order_transactions')
            ->select(['subscription_id', App::db()->raw('MAX(created_at) as last_payment')])
            ->whereNotNull('subscription_id')
            ->where('status', 'succeeded')
            ->groupBy('subscription_id')
            ->get();

        // Index last payments by subscription_id
        $lastPaymentMap = [];
        foreach ($lastPayments as $lp) {
            $lastPaymentMap[$lp->subscription_id] = $lp->last_payment;
        }
        unset($lastPayments);

        // Attach last_payment to each subscription
        foreach ($allSubscriptions as &$sub) {
            $sub->last_payment = $lastPaymentMap[$sub->id] ?? null;
        }
        unset($sub, $lastPaymentMap);

        $this->log('Grouping by customer-product pairs...', 'info', $progressCallback);

        // Group subscriptions by customer_id + product_id
        $grouped = [];
        foreach ($allSubscriptions as $sub) {
            $key = "{$sub->customer_id}_{$sub->product_id}";
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $sub;
        }
        unset($allSubscriptions);

        $this->log('Processing timelines and aggregating...', 'info', $progressCallback);

        // Build aggregates directly without building full timelines array
        $aggregates = [];
        $processedCount = 0;
        $totalCount = count($grouped);

        foreach ($grouped as $key => $subscriptions) {
            if (empty($subscriptions)) {
                continue;
            }

            $firstSub = $subscriptions[0];
            $customerId = $firstSub->customer_id;
            $productId = $firstSub->product_id;
            $cohort = (new \DateTime($firstSub->created_at))->format('Y-m');

            // Get cohort baseline
            $cohortState = $this->getCustomerStateForMonth($subscriptions, $cohort);
            
            // Only include customers who were active at their cohort month
            if (!$cohortState['is_active']) {
                continue;
            }

            $cohortMrr = $cohortState['mrr'];
            $cohortDate = new \DateTime($cohort . '-01');

            // Track for each period from cohort onwards
            foreach ($dateRange['months'] as $period) {
                $periodDate = new \DateTime($period . '-01');

                // Only track periods from cohort month onwards
                if ($periodDate < $cohortDate) {
                    continue;
                }

                $periodState = $this->getCustomerStateForMonth($subscriptions, $period);

                // Calculate period offset
                $interval = $cohortDate->diff($periodDate);
                $periodOffset = ($interval->y * 12) + $interval->m;

                // Aggregate for specific product
                $this->addToAggregate(
                    $aggregates,
                    $cohort,
                    $period,
                    $productId,
                    $periodOffset,
                    $cohortMrr,
                    $periodState
                );

                // Aggregate for all products
                $this->addToAggregate(
                    $aggregates,
                    $cohort,
                    $period,
                    'all',
                    $periodOffset,
                    $cohortMrr,
                    $periodState
                );
            }

            $processedCount++;
            if ($processedCount % 1000 == 0) {
                $this->log("Processed {$processedCount}/{$totalCount} customer-product pairs", 'info', $progressCallback);
            }
        }
        unset($grouped);

        // Insert aggregates in batches
        $this->log('Inserting snapshots to database...', 'info', $progressCallback);
        $totalInserted = $this->insertAggregates('fct_retention_snapshots', $aggregates, $progressCallback);
        
        unset($aggregates);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        return $totalInserted;
    }

    /**
     * Build timelines and aggregate in batches to minimize memory usage
     * 
     * Instead of building ALL timelines in memory, we:
     * 1. Fetch subscriptions for a batch of customer-product pairs
     * 2. Build timelines for that batch
     * 3. Aggregate and insert to DB
     * 4. Clear memory and repeat
     */
    protected function buildAndAggregateInBatches(
        array $pairs,
        array $dateRange,
        ?callable $progressCallback,
        ?int $productIdFilter
    ): int {
        $table = 'fct_retention_snapshots';
        $batchSize = 100; // Process 100 customer-product pairs at a time
        $totalPairs = count($pairs);
        $processedPairs = 0;

        // Global aggregates accumulator (across all batches)
        $globalAggregates = [];

        // Fetch last payment dates for all subscriptions in one query (this is memory-efficient)
        $this->log('Fetching last payment dates...', 'info', $progressCallback);
        $lastPayments = App::db()->query()
            ->from('fct_order_transactions')
            ->select(['subscription_id', App::db()->raw('MAX(created_at) as last_payment')])
            ->whereNotNull('subscription_id')
            ->where('status', 'succeeded')
            ->groupBy('subscription_id')
            ->get();

        $lastPaymentMap = [];
        foreach ($lastPayments as $lp) {
            $lastPaymentMap[$lp->subscription_id] = $lp->last_payment;
        }
        unset($lastPayments);

        $this->log('Processing in batches of ' . $batchSize . ' customer-product pairs...', 'info', $progressCallback);

        // Process pairs in batches
        foreach (array_chunk($pairs, $batchSize) as $batchIndex => $batchPairs) {
            $batchNum = $batchIndex + 1;
            $this->log("Processing batch {$batchNum} ({$processedPairs}/{$totalPairs} pairs)...", 'info', $progressCallback);

            // Get customer IDs and product IDs from this batch
            $customerIds = array_unique(array_column($batchPairs, 'customer_id'));
            $productIds = array_unique(array_column($batchPairs, 'product_id'));

            // Fetch subscriptions for this batch only
            $query = App::db()->query()
                ->from('fct_subscriptions')
                ->whereIn('customer_id', $customerIds)
                ->whereIn('product_id', $productIds)
                ->whereNotNull('customer_id')
                ->whereNotNull('product_id')
                ->where('customer_id', '>', 0)
                ->where('product_id', '>', 0)
                ->whereNotIn('status', $this->excludedStatuses)
                ->orderBy('created_at', 'ASC');

            $batchSubscriptions = $query->get();

            // Attach last payment dates
            foreach ($batchSubscriptions as &$sub) {
                $sub->last_payment = $lastPaymentMap[$sub->id] ?? null;
            }
            unset($sub);

            // Group by customer-product key
            $grouped = [];
            foreach ($batchSubscriptions as $sub) {
                $key = "{$sub->customer_id}_{$sub->product_id}";
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [];
                }
                $grouped[$key][] = $sub;
            }
            unset($batchSubscriptions);

            // Build timelines for this batch
            $timelines = [];
            foreach ($grouped as $key => $subscriptions) {
                if (empty($subscriptions)) {
                    continue;
                }

                $firstSub = $subscriptions[0];
                $customerId = $firstSub->customer_id;
                $productId = $firstSub->product_id;
                $cohort = (new \DateTime($firstSub->created_at))->format('Y-m');

                // Build monthly state
                $monthlyState = [];
                foreach ($dateRange['months'] as $month) {
                    $state = $this->getCustomerStateForMonth($subscriptions, $month);
                    $monthlyState[$month] = $state;
                }

                $timelines[$key] = [
                    'customer_id' => $customerId,
                    'product_id'  => $productId,
                    'cohort'      => $cohort,
                    'monthly'     => $monthlyState,
                ];
            }
            unset($grouped);

            // Aggregate into global aggregates (don't insert yet)
            $this->accumulateAggregates($globalAggregates, $timelines, $dateRange);
            $processedPairs += count($batchPairs);

            $this->log("Batch {$batchNum} complete. Processed {$processedPairs}/{$totalPairs} pairs", 'info', $progressCallback);

            // Clear memory
            unset($timelines);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        // Now insert all aggregates in batches
        $this->log('All batches processed. Inserting snapshots to database...', 'info', $progressCallback);
        $totalInserted = $this->insertAggregates($table, $globalAggregates, $progressCallback);
        
        unset($globalAggregates);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        return $totalInserted;
    }

    /**
     * Accumulate timelines into global aggregates array
     * This merges data from multiple batches without creating duplicates
     */
    protected function accumulateAggregates(array &$globalAggregates, array $timelines, array $dateRange): void
    {
        foreach ($timelines as $timeline) {
            $cohort = $timeline['cohort'];
            $productId = $timeline['product_id'];
            $monthly = $timeline['monthly'];

            // Get cohort baseline (state at cohort month)
            $cohortState = $monthly[$cohort] ?? ['is_active' => false, 'mrr' => 0];

            // Only include customers who were active at their cohort month
            if (!$cohortState['is_active']) {
                continue;
            }

            $cohortMrr = $cohortState['mrr'];

            // Track for each period from cohort onwards
            $cohortDate = new \DateTime($cohort . '-01');

            foreach ($dateRange['months'] as $period) {
                $periodDate = new \DateTime($period . '-01');

                // Only track periods from cohort month onwards
                if ($periodDate < $cohortDate) {
                    continue;
                }

                $periodState = $monthly[$period] ?? ['is_active' => false, 'mrr' => 0];

                // Calculate period offset
                $interval = $cohortDate->diff($periodDate);
                $periodOffset = ($interval->y * 12) + $interval->m;

                // Aggregate for specific product
                $this->addToAggregate(
                    $globalAggregates,
                    $cohort,
                    $period,
                    $productId,
                    $periodOffset,
                    $cohortMrr,
                    $periodState
                );

                // Aggregate for all products (product_id = NULL)
                $this->addToAggregate(
                    $globalAggregates,
                    $cohort,
                    $period,
                    'all', // Will be converted to NULL
                    $periodOffset,
                    $cohortMrr,
                    $periodState
                );
            }
        }
    }

    /**
     * Insert aggregates into database in batches
     */
    protected function insertAggregates(string $table, array $aggregates, ?callable $progressCallback): int
    {
        $snapshots = [];
        $batchInsertThreshold = 10000;
        $totalInserted = 0;

        // Convert aggregates to final snapshot format and insert in batches
        foreach ($aggregates as $cohort => $periods) {
            foreach ($periods as $period => $products) {
                foreach ($products as $productKey => $data) {
                    $productId = $productKey === 'all' ? null : $productKey;

                    $retentionRateCustomers = $data['cohort_customers'] > 0
                        ? round(($data['retained_customers'] / $data['cohort_customers']) * 100, 2)
                        : 0;

                    $retentionRateMrr = $data['cohort_mrr'] > 0
                        ? round(($data['retained_mrr'] / $data['cohort_mrr']) * 100, 2)
                        : 0;

                    $snapshots[] = [
                        'cohort'                    => $cohort,
                        'period'                    => $period,
                        'product_id'                => $productId,
                        'period_offset'             => $data['period_offset'],
                        'cohort_customers'          => $data['cohort_customers'],
                        'cohort_mrr'                => $data['cohort_mrr'],
                        'retained_customers'        => $data['retained_customers'],
                        'retained_mrr'              => $data['retained_mrr'],
                        'new_customers'             => 0,
                        'churned_customers'         => $data['cohort_customers'] - $data['retained_customers'],
                        'retention_rate_customers'  => $retentionRateCustomers,
                        'retention_rate_mrr'        => $retentionRateMrr,
                        'created_at'                => current_time('mysql'),
                        'updated_at'                => current_time('mysql'),
                    ];

                    // Batch insert when threshold is reached
                    if (count($snapshots) >= $batchInsertThreshold) {
                        $inserted = $this->insertBatch($table, $snapshots);
                        $totalInserted += $inserted;
                        $this->log("Inserted batch of {$inserted} records (total: {$totalInserted})", 'info', $progressCallback);
                        
                        // Clear memory
                        $snapshots = [];
                        if (function_exists('gc_collect_cycles')) {
                            gc_collect_cycles();
                        }
                    }
                }
            }
        }

        // Insert remaining snapshots
        if (!empty($snapshots)) {
            $inserted = $this->insertBatch($table, $snapshots);
            $totalInserted += $inserted;
            $this->log("Inserted final batch of {$inserted} records", 'info', $progressCallback);
        }

        return $totalInserted;
    }

    /**
     * Determine customer's state for a given month
     */
    protected function getCustomerStateForMonth($subscriptions, string $month): array
    {
        $monthStart = new \DateTime($month . '-01');
        $monthEnd = (clone $monthStart)->modify('last day of this month')->setTime(23, 59, 59);

        $isActive = false;
        $mrr = 0;

        foreach ($subscriptions as $sub) {
            $createdAt = new \DateTime($sub->created_at);

            // Skip subscriptions created after this month
            if ($createdAt > $monthEnd) {
                continue;
            }

            // Check if subscription was active during this month
            $wasActiveThisMonth = $this->wasSubscriptionActiveInMonth($sub, $monthStart, $monthEnd);

            if ($wasActiveThisMonth) {
                $isActive = true;
                // Use the subscription's recurring amount as MRR (normalized to monthly)
                $mrr = max($mrr, $this->normalizeToMonthlyMrr($sub));
            }
        }

        return [
            'is_active' => $isActive,
            'mrr'       => $mrr,
        ];
    }

    /**
     * Check if a subscription was active during a given month
     * 
     * A subscription is considered active in a month if:
     * - It was created on or before the end of that month, AND
     * - It had not ended before the start of that month
     * 
     * For ended subscriptions, we use the END date (expire_at, or canceled_at + billing period)
     * For active subscriptions, we check if they existed during that month
     */
    protected function wasSubscriptionActiveInMonth($sub, \DateTime $monthStart, \DateTime $monthEnd): bool
    {
        $createdAt = new \DateTime($sub->created_at);

        // Subscription must have started by end of month
        if ($createdAt > $monthEnd) {
            return false;
        }

        // Determine the subscription's effective end date
        $endDate = $this->getSubscriptionEndDate($sub);

        // If no end date, subscription is still ongoing
        // But we need to check if it was created by this month
        if (!$endDate) {
            return $createdAt <= $monthEnd;
        }

        // Subscription was active in this month if:
        // - It started on or before the end of this month, AND
        // - It ended on or after the start of this month
        return $createdAt <= $monthEnd && $endDate >= $monthStart;
    }

    /**
     * Get the effective end date of a subscription
     * Returns null if subscription is still ongoing
     * 
     * We use last_payment + billing_interval as the effective end date.
     * This represents when the subscription actually stopped providing revenue.
     */
    protected function getSubscriptionEndDate($sub): ?\DateTime
    {
        // If subscription is currently active/trialing, it's still ongoing
        if (in_array($sub->status, $this->activeStatuses)) {
            return null;
        }

        // For non-active subscriptions, use last_payment + billing_interval as end date
        // This is the most accurate indicator of when the customer stopped paying
        if (!empty($sub->last_payment) && $sub->last_payment !== '0000-00-00 00:00:00') {
            $lastPayment = new \DateTime($sub->last_payment);
            $interval = $this->getBillingIntervalMonths($sub->billing_interval ?? 'yearly');
            return (clone $lastPayment)->modify("+{$interval} months");
        }

        // Fallback: if no payment history, use created_at + billing_interval
        // (they paid at creation)
        $createdAt = new \DateTime($sub->created_at);
        $interval = $this->getBillingIntervalMonths($sub->billing_interval ?? 'yearly');
        return (clone $createdAt)->modify("+{$interval} months");
    }

    /**
     * Get billing interval in months
     */
    protected function getBillingIntervalMonths(string $interval): int
    {
        switch ($interval) {
            case 'yearly':
            case 'annual':
                return 12;
            case 'half_yearly':
                return 6;
            case 'quarterly':
                return 3;
            case 'weekly':
                return 1; // Approximate to 1 month
            case 'daily':
                return 1; // Approximate to 1 month
            default:
                return 1; // monthly
        }
    }

    /**
     * Normalize recurring amount to monthly MRR
     */
    protected function normalizeToMonthlyMrr($sub): int
    {
        $amount = (int) ($sub->recurring_amount ?? 0);
        $interval = $sub->billing_interval ?? 'monthly';

        switch ($interval) {
            case 'yearly':
            case 'annual':
                return (int) round($amount / 12);
            case 'half_yearly':
                return (int) round($amount / 6);
            case 'quarterly':
                return (int) round($amount / 3);
            case 'weekly':
                return (int) round($amount * 4.33);
            case 'daily':
                return (int) round($amount * 30);
            default:
                return $amount; // monthly
        }
    }

    /**
     * Aggregate timelines into cohort snapshots
     * Returns the number of snapshots inserted (not the snapshots themselves to save memory)
     */
    protected function aggregateSnapshots(array $timelines, array $dateRange, ?callable $progressCallback = null): int
    {
        $table = 'fct_retention_snapshots';
        
        $snapshots = [];
        $batchInsertThreshold = 10000; // Insert every 10k records to manage memory
        $totalInserted = 0;

        // Initialize snapshot structure
        // We need: by cohort, by period, by product_id (and NULL for all)
        $aggregates = [];

        foreach ($timelines as $timeline) {
            $cohort = $timeline['cohort'];
            $productId = $timeline['product_id'];
            $monthly = $timeline['monthly'];

            // Get cohort baseline (state at cohort month)
            $cohortState = $monthly[$cohort] ?? ['is_active' => false, 'mrr' => 0];

            // Only include customers who were active at their cohort month
            if (!$cohortState['is_active']) {
                continue;
            }

            $cohortMrr = $cohortState['mrr'];

            // Track for each period from cohort onwards
            $periodOffset = 0;
            $cohortDate = new \DateTime($cohort . '-01');

            foreach ($dateRange['months'] as $period) {
                $periodDate = new \DateTime($period . '-01');

                // Only track periods from cohort month onwards
                if ($periodDate < $cohortDate) {
                    continue;
                }

                $periodState = $monthly[$period] ?? ['is_active' => false, 'mrr' => 0];

                // Calculate period offset
                $interval = $cohortDate->diff($periodDate);
                $periodOffset = ($interval->y * 12) + $interval->m;

                // Aggregate for specific product
                $this->addToAggregate(
                    $aggregates,
                    $cohort,
                    $period,
                    $productId,
                    $periodOffset,
                    $cohortMrr,
                    $periodState
                );

                // Aggregate for all products (product_id = NULL)
                $this->addToAggregate(
                    $aggregates,
                    $cohort,
                    $period,
                    'all', // Will be converted to NULL
                    $periodOffset,
                    $cohortMrr,
                    $periodState
                );
            }
        }

        // Convert aggregates to final snapshot format and insert in batches
        foreach ($aggregates as $cohort => $periods) {
            foreach ($periods as $period => $products) {
                foreach ($products as $productKey => $data) {
                    $productId = $productKey === 'all' ? null : $productKey;

                    $retentionRateCustomers = $data['cohort_customers'] > 0
                        ? round(($data['retained_customers'] / $data['cohort_customers']) * 100, 2)
                        : 0;

                    $retentionRateMrr = $data['cohort_mrr'] > 0
                        ? round(($data['retained_mrr'] / $data['cohort_mrr']) * 100, 2)
                        : 0;

                    $snapshots[] = [
                        'cohort'                    => $cohort,
                        'period'                    => $period,
                        'product_id'                => $productId,
                        'period_offset'             => $data['period_offset'],
                        'cohort_customers'          => $data['cohort_customers'],
                        'cohort_mrr'                => $data['cohort_mrr'],
                        'retained_customers'        => $data['retained_customers'],
                        'retained_mrr'              => $data['retained_mrr'],
                        'new_customers'             => 0, // Can be calculated separately if needed
                        'churned_customers'         => $data['cohort_customers'] - $data['retained_customers'],
                        'retention_rate_customers'  => $retentionRateCustomers,
                        'retention_rate_mrr'        => $retentionRateMrr,
                        'created_at'                => current_time('mysql'),
                        'updated_at'                => current_time('mysql'),
                    ];

                    // Batch insert when threshold is reached
                    if (count($snapshots) >= $batchInsertThreshold) {
                        $inserted = $this->insertBatch($table, $snapshots);
                        $totalInserted += $inserted;
                        $this->log("Inserted batch of {$inserted} records (total: {$totalInserted})", 'info', $progressCallback);
                        
                        // Clear memory
                        $snapshots = [];
                        if (function_exists('gc_collect_cycles')) {
                            gc_collect_cycles();
                        }
                    }
                }
            }
        }

        // Insert remaining snapshots
        if (!empty($snapshots)) {
            $inserted = $this->insertBatch($table, $snapshots);
            $totalInserted += $inserted;
            $this->log("Inserted final batch of {$inserted} records", 'info', $progressCallback);
        }

        return $totalInserted;
    }

    /**
     * Add data to aggregate array
     */
    protected function addToAggregate(
        array &$aggregates,
        string $cohort,
        string $period,
        $productId,
        int $periodOffset,
        int $cohortMrr,
        array $periodState
    ): void {
        if (!isset($aggregates[$cohort][$period][$productId])) {
            $aggregates[$cohort][$period][$productId] = [
                'period_offset'      => $periodOffset,
                'cohort_customers'   => 0,
                'cohort_mrr'         => 0,
                'retained_customers' => 0,
                'retained_mrr'       => 0,
            ];
        }

        // Always increment cohort baseline
        $aggregates[$cohort][$period][$productId]['cohort_customers']++;
        $aggregates[$cohort][$period][$productId]['cohort_mrr'] += $cohortMrr;

        // Increment retained if active in this period
        if ($periodState['is_active']) {
            $aggregates[$cohort][$period][$productId]['retained_customers']++;
            $aggregates[$cohort][$period][$productId]['retained_mrr'] += $periodState['mrr'];
        }
    }

    /**
     * Insert a batch of records
     */
    protected function insertBatch(string $table, array $batch): int
    {
        if (empty($batch)) {
            return 0;
        }

        App::db()->table($table)->insert($batch);

        return count($batch);
    }

    /**
     * Get statistics from the generated snapshots
     */
    public function getStats(): array
    {
        $stats = App::db()->table('fct_retention_snapshots')
            ->selectRaw('COUNT(*) as total_records')
            ->selectRaw('COUNT(DISTINCT cohort) as unique_cohorts')
            ->selectRaw('COUNT(DISTINCT period) as unique_periods')
            ->selectRaw('COUNT(DISTINCT product_id) as unique_products')
            ->first();

        return [
            'total_records' => (int) ($stats->total_records ?? 0),
            'unique_cohorts' => (int) ($stats->unique_cohorts ?? 0),
            'unique_periods' => (int) ($stats->unique_periods ?? 0),
            'unique_products' => (int) (($stats->unique_products ?? 0) + 1), // Include 'all'
        ];
    }
}
