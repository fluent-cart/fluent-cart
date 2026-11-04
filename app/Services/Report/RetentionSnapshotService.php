<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\Modules\MultiCurrency\Services\BaseCurrency;
use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Database\Migrations\RetentionSnapshotsMigrator;
use FluentCart\Framework\Database\Schema;
use FluentCart\App\Services\Payments\SubscriptionHelper;

class RetentionSnapshotService
{
    /**
     * Active statuses
     */
    // The live statuses of SubscriptionReportService::leftAtSql(): their expire_at is not kept up to date
    protected array $activeStatuses = [
        Status::SUBSCRIPTION_ACTIVE,
        Status::SUBSCRIPTION_EXPIRING,
        Status::SUBSCRIPTION_TRIALING,
    ];

    /** @var array<int, string> last paid renewal (GMT) by parent order id: when a canceled plan's paid term ends */
    protected array $lastPaidDates = [];

    /** @var array<int, int>|null price paid now by simulated-trial subscription id, in cents; read once per request */
    protected $paidNow = null;

    protected ?\DateTime $now = null;

    const LOCK_OPTION = 'fluent_cart_retention_snapshot_lock';
    // Each step renews it; a build left behind (a closed tab) frees the lock after this long
    const LOCK_SECONDS = 300;
    const BUILD_TABLE = 'fct_retention_snapshots_build';
    /** Set when the snapshot rules changed (a plugin update): the Cohorts page rebuilds on its next visit */
    const STALE_OPTION = 'fluent_cart_retention_snapshots_stale';

    /** @var int customer ids per build step */
    protected $customersPerStep = 2000;

    /**
     * The whole build in one go, for WP-CLI and tests. The admin page runs start() and step() itself, one short
     * request at a time.
     *
     * @param callable|null $progressCallback fn(string $message, string $level = 'info')
     * @return array success, message, stats (and code when it could not run)
     */
    public function generate(?callable $progressCallback = null): array
    {
        $started = $this->start();
        if (empty($started['success'])) {
            $this->log($started['message'], 'warning', $progressCallback);

            return $started;
        }

        do {
            $step = $this->step($started['token']);
            if (empty($step['success'])) {
                $this->log($step['message'], 'error', $progressCallback);

                return $step;
            }
            $this->log("Step {$step['step']} of {$step['steps']} done.", 'info', $progressCallback);
        } while (empty($step['done']));

        return $step;
    }

    /**
     * Start a build: take the lock, plan the steps and empty the build table. The published snapshots stay in place
     * until the last step.
     */
    public function start(): array
    {
        $this->ensureTableExists();
        $plan = $this->planBuild();
        // One build at a time: two would write every row twice
        if (!$this->acquireLock($plan)) {
            return [
                'success' => false,
                'code'    => 'already_running',
                'message' => __('A snapshot rebuild is already running. Try again in a few minutes.', 'fluent-cart'),
                'stats'   => [],
            ];
        }

        $this->prepareBuildTable();

        return ['success' => true, 'step' => 0, 'steps' => $plan['steps'], 'token' => $plan['token']];
    }

    /**
     * Run the next step of the started build: one range of customers into the build table, or, after the last range,
     * publish the build in place of the old snapshots.
     */
    public function step(string $token = ''): array
    {
        $raw = $this->readLockRow();
        $state = $raw === null ? null : maybe_unserialize($raw);
        if (!is_array($state) || !isset($state['next_id'])) {
            return [
                'success' => false,
                'code'    => 'no_build',
                'message' => __('No snapshot rebuild is running. Start it again.', 'fluent-cart'),
            ];
        }
        $running = [
            'success' => false,
            'code'    => 'already_running',
            'message' => __('A snapshot rebuild is already running. Try again in a few minutes.', 'fluent-cart'),
        ];
        if (!hash_equals((string) ($state['token'] ?? ''), $token) || !empty($state['busy'])) {
            return $running;
        }

        // Claim the build before working: a second request with the same token must not build the same range twice
        $state['busy'] = 1;
        $state['expires'] = time() + self::LOCK_SECONDS;
        $claimed = maybe_serialize($state);
        if (!$this->swapLockRow($raw, $claimed)) {
            return $running;
        }

        try {
            if ($state['months'] && $state['next_id'] <= $state['last_id']) {
                $to = $state['next_id'] + $state['per_step'] - 1;
                $this->buildRange($state['next_id'], $to, $state['months']);
                $state['next_id'] = $to + 1;
                $state['step']++;
                $state['busy'] = 0;
                // A build that took over an expired lock keeps its own state
                if (!$this->swapLockRow($claimed, maybe_serialize($state))) {
                    return $running;
                }

                return ['success' => true, 'done' => false, 'step' => $state['step'], 'steps' => $state['steps']];
            }

            $this->publish($state['months']);
        } catch (\Throwable $e) {
            delete_option(self::LOCK_OPTION);

            return [
                'success' => false,
                'code'    => 'failed',
                'message' => sprintf(
                    /* translators: %1$s: error message */
                    __('Error: %1$s', 'fluent-cart'),
                    $e->getMessage()
                ),
            ];
        }

        delete_option(self::LOCK_OPTION);
        delete_option(self::STALE_OPTION);

        return [
            'success' => true,
            'done'    => true,
            'step'    => $state['steps'],
            'steps'   => $state['steps'],
            'message' => __('Retention snapshots generated successfully', 'fluent-cart'),
            'stats'   => $this->getStats(),
        ];
    }

    /**
     * The lock row as stored, read past the option cache: another request may have changed it
     */
    protected function readLockRow()
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION));
    }

    /**
     * Replace the lock row only if it still holds $expected (compare-and-swap)
     */
    protected function swapLockRow(string $expected, string $new): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $new,
            self::LOCK_OPTION,
            $expected
        ));
        wp_cache_delete(self::LOCK_OPTION, 'options');

        return (int) $wpdb->rows_affected === 1;
    }

    /**
     * An options-table lock (option_name is unique, so one add_option() wins), not a database lock: those are not
     * available on every host. It holds the build plan and expires, so a build left behind never blocks the next one.
     */
    protected function acquireLock(array $plan): bool
    {
        global $wpdb;
        $plan['expires'] = time() + self::LOCK_SECONDS;
        // add_option() checks the cache first, so two requests could both add it. One INSERT IGNORE succeeds once.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::LOCK_OPTION,
            maybe_serialize($plan)
        ));
        $taken = (int) $wpdb->rows_affected === 1;

        if (!$taken) {
            $heldValue = $this->readLockRow();
            $held = maybe_unserialize($heldValue);
            if ($heldValue === null || (int) (is_array($held) ? ($held['expires'] ?? 0) : $held) > time()) {
                return false;
            }
            // Expired: take it over only if no other request changed it since we read it
            $taken = $this->swapLockRow($heldValue, maybe_serialize($plan));
        }

        wp_cache_delete(self::LOCK_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');

        return $taken;
    }

    /**
     * The customer id range, its steps and the cohort months, fixed for the whole build
     */
    protected function planBuild(): array
    {
        $bounds = $this->subscriptionScope()->selectRaw('MIN(customer_id) AS first_id, MAX(customer_id) AS last_id')->first();
        $first = (int) ($bounds->first_id ?? 0);
        $last = (int) ($bounds->last_id ?? 0);
        $perStep = max(1, (int) $this->customersPerStep);
        $ranges = $first > 0 ? (int) ceil(($last - $first + 1) / $perStep) : 0;

        return [
            'months'   => $first > 0 ? $this->getDateRange()['months'] : [],
            'next_id'  => $first,
            'last_id'  => $last,
            'per_step' => $perStep,
            'step'     => 0,
            // Only the request that started the build may step it
            'token'    => wp_generate_password(20, false),
            // The ranges, then publishing
            'steps'    => $ranges + 1,
        ];
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
        // Only when missing (a store updated since 1.3.20): DDL commits any open transaction, and updates already run
        // the column changes
        if (!Schema::hasTable('fct_retention_snapshots')) {
            RetentionSnapshotsMigrator::migrate();
        }
    }

    /**
     * The build table: one row per customer range and snapshot, MRR kept as twelve times the monthly cents so the
     * ranges add up exactly; no unique key, as every range writes the same snapshot keys
     */
    protected function prepareBuildTable(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . self::BUILD_TABLE;
        // Created once; emptied with DELETE, not TRUNCATE: DDL would commit any open transaction
        if (Schema::hasTable(self::BUILD_TABLE)) {
            App::db()->table(self::BUILD_TABLE)->delete();

            return;
        }
        $collate = $wpdb->get_charset_collate();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("CREATE TABLE `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `cohort` VARCHAR(7) NOT NULL,
            `period` VARCHAR(7) NOT NULL,
            `product_id` BIGINT(20) UNSIGNED NULL,
            `currency` VARCHAR(10) NOT NULL DEFAULT '',
            `billing_interval` VARCHAR(20) NOT NULL DEFAULT '',
            `rebuy_adjusted` TINYINT(1) NOT NULL DEFAULT 0,
            `period_offset` INT UNSIGNED NOT NULL DEFAULT 0,
            `cohort_customers` INT UNSIGNED NOT NULL DEFAULT 0,
            `cohort_x12` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `retained_customers` INT UNSIGNED NOT NULL DEFAULT 0,
            `retained_x12` BIGINT UNSIGNED NOT NULL DEFAULT 0
        ) {$collate}");
    }

    /**
     * Subscriptions that count in cohorts: a customer and a product, no installment plan
     */
    protected function subscriptionScope()
    {
        return App::db()->query()
            ->from('fct_subscriptions')
            ->where('customer_id', '>', 0)
            ->where('product_id', '>', 0)
            ->whereNotIn('status', SubscriptionReportService::RETENTION_EXCLUDED_STATUSES)
            ->where('bill_times', '<=', 0);
    }

    /**
     * Get the date range for analysis
     */
    protected function getDateRange(): array
    {
        $result = $this->subscriptionScope()->selectRaw('MIN(created_at) as first_date')->first();

        // Cohort months are store months, like every report; the stored dates are GMT
        $firstDate = (new \DateTime($result->first_date, new \DateTimeZone('UTC')))->setTimezone(wp_timezone());
        $lastDate = new \DateTime('now', wp_timezone()); // Use today as the end date

        $months = [];
        $current = new \DateTime($firstDate->format('Y-m') . '-01');
        $end = new \DateTime($lastDate->format('Y-m') . '-01');
        while ($current <= $end) {
            $months[] = $current->format('Y-m');
            $current->modify('+1 month');
        }

        return ['months' => $months];
    }

    /**
     * One step: the customers in [$from, $to] into the build table, as recorded and with rebuys counted as kept
     */
    protected function buildRange(int $from, int $to, array $months): void
    {
        $columns = ['id', 'customer_id', 'product_id', 'parent_order_id', 'currency', 'status', 'billing_interval', 'recurring_total',
            'created_at', 'canceled_at', 'expire_at', 'next_billing_date', 'updated_at', 'trial_days', 'config'];
        $subscriptions = $this->subscriptionScope()
            ->select($columns)
            ->whereBetween('customer_id', [$from, $to])
            ->orderBy('customer_id', 'ASC')
            ->orderBy('created_at', 'ASC')
            ->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        // Renewals of this step's subscriptions only, so a step's work does not grow with the store
        $parentIds = array_values(array_unique(array_filter(array_map('intval', $subscriptions->pluck('parent_order_id')->all()))));
        $firstPaid = $this->firstPaidRenewals($parentIds);
        $lastPaid = $this->paidRenewalDates('MAX', false, $parentIds);
        foreach ([0, 1] as $rebuyAdjusted) {
            $aggregates = $this->aggregate($subscriptions, $months, null, $firstPaid, (bool) $rebuyAdjusted, $lastPaid);
            foreach ($aggregates as $currency => $currencyAggregates) {
                $this->insertBuildRows($currencyAggregates, $months, (string) $currency, $rebuyAdjusted);
            }
        }
    }

    /**
     * When each subscription's first renewal was paid, by parent order: a real free trial becomes a customer then
     *
     * @return array<int, string> parent order id => GMT date
     */
    protected function firstPaidRenewals(?array $parentIds = null): array
    {
        return $this->paidRenewalDates('MIN', true, $parentIds);
    }

    /**
     * @param string $pick MIN (the first paid renewal) or MAX (the last)
     * @param int[]|null $parentIds only these parent orders; null for the whole store
     * @return array<int, string> parent order id => GMT date
     */
    protected function paidRenewalDates(string $pick, bool $tookMoney = false, ?array $parentIds = null): array
    {
        $pick = $pick === 'MAX' ? 'MAX' : 'MIN';
        if ($parentIds === []) {
            return [];
        }
        $rows = App::db()->table('fct_orders')
            ->when($parentIds !== null, fn($q) => $q->whereIn('parent_id', $parentIds))
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->whereIn('payment_status', Status::getReportStatuses())
            // A trial joins a cohort at a renewal that took money, not one marked paid at 0
            ->when($tookMoney, fn($q) => $q->where('total_paid', '>', 0))
            ->selectRaw("parent_id, {$pick}(COALESCE(completed_at, created_at)) AS paid_at")
            ->groupBy('parent_id')
            ->get();

        $paid = [];
        foreach ($rows as $row) {
            $paid[(int) $row->parent_id] = (string) $row->paid_at;
        }

        return $paid;
    }

    /**
     * Customer cohorts per currency, product ('all' or an id) and billing interval ('' for all).
     *
     * A customer joins the cohort of the month they first paid and is retained in a later month while any of their
     * subscriptions is running at its end; a customer who leaves and comes back counts again from the return.
     *
     * @param iterable $allSubscriptions subscription rows, sorted by customer
     * @param string[] $months store months (Y-m), oldest first
     * @param array<int, string>|null $firstPaid first paid renewal per parent order, for real free trials
     * @return array [currency][product][interval][cohort index] => ['size', 'mrr0', 'count' => [index => delta], 'mrr' => [index => delta]]
     */
    protected function aggregate($allSubscriptions, array $months, ?callable $progressCallback = null, ?array $firstPaid = null, bool $rebuyAdjusted = false, ?array $lastPaid = null): array
    {
        $lastPaid = $lastPaid ?? [];
        $this->lastPaidDates = $lastPaid;
        // A discounted first year counts at the price paid now, as on the subscription health cards
        if ($this->paidNow === null) {
            $this->paidNow = SubscriptionReportService::make()->paidNowBySubscription();
        }
        $firstPaid = $firstPaid ?? [];
        $baseCurrency = BaseCurrency::get();
        $index = array_flip($months);
        $count = count($months);
        $timezone = wp_timezone();
        $now = $this->getNow();
        $monthIndex = function (\DateTime $date) use ($index, $count, $timezone, $months) {
            $month = (clone $date)->setTimezone($timezone)->format('Y-m');
            if (isset($index[$month])) {
                return $index[$month];
            }

            return $month < $months[0] ? 0 : $count;
        };

        $aggregates = [];
        $customer = null;
        $spans = [];
        $customers = 0;
        $flush = function () use (&$aggregates, &$spans, &$customers, $count, $progressCallback, $rebuyAdjusted) {
            if ($spans) {
                $this->addCustomer($aggregates, $rebuyAdjusted ? $this->bridgeRebuys($spans) : $spans, $count);
                if (++$customers % 1000 === 0) {
                    $this->log("Processed {$customers} customers", 'info', $progressCallback);
                }
            }
            $spans = [];
        };

        foreach ($allSubscriptions as $sub) {
            if ($sub->customer_id !== $customer) {
                $flush();
                $customer = $sub->customer_id;
            }

            $start = new \DateTime($sub->created_at, new \DateTimeZone('UTC'));
            // A real free trial was no customer until it paid; one that never paid joins no cohort
            if ((int) ($sub->trial_days ?? 0) > 0 && !$this->isSimulatedTrial($sub)) {
                if (empty($firstPaid[(int) $sub->parent_order_id])) {
                    continue;
                }
                $start = new \DateTime($firstPaid[(int) $sub->parent_order_id], new \DateTimeZone('UTC'));
            }

            // Running at a month's end until it ended; an end still ahead (a retry due later) is no end yet
            $end = $this->getSubscriptionEndDate($sub);
            if ($end && $end > $now) {
                $end = null;
            }

            $span = [
                'currency' => strtoupper((string) ($sub->currency ?? '')) ?: $baseCurrency,
                'product'  => (int) $sub->product_id,
                'interval' => (string) ($sub->billing_interval ?? ''),
                'from'     => $monthIndex($start),
                'to'       => $end ? $monthIndex($end) : $count, // exclusive: not running at the end of that month
                'mrr'      => $this->mrrTimes12($sub),
            ];
            if ($rebuyAdjusted) {
                $span['started'] = $start->getTimestamp();
                $span['term_end'] = null;
                if (in_array($sub->status, [Status::SUBSCRIPTION_CANCELED, Status::SUBSCRIPTION_EXPIRED], true)) {
                    $paidAt = !empty($lastPaid[(int) $sub->parent_order_id])
                        ? max($start, new \DateTime($lastPaid[(int) $sub->parent_order_id], new \DateTimeZone('UTC')))
                        : clone $start;
                    $span['term_end'] = $this->addInterval($paidAt, (string) ($sub->billing_interval ?? ''), $sub)->getTimestamp();
                }
            }
            $spans[] = $span;
        }
        $flush();

        return $aggregates;
    }

    /**
     * One customer into every group they belong to: the store, each of their products, each billing interval
     */
    protected function addCustomer(array &$aggregates, array $spans, int $count): void
    {
        $groups = [];
        foreach ($spans as $span) {
            foreach ([['all', ''], ['all', $span['interval']], [$span['product'], ''], [$span['product'], $span['interval']]] as [$product, $interval]) {
                $groups[$span['currency'] . '|' . $product . '|' . $interval][] = $span;
            }
        }

        foreach ($groups as $key => $members) {
            [$currency, $product, $interval] = explode('|', $key, 3);
            $cohort = min(array_column($members, 'from'));
            $cell = &$aggregates[$currency][$product][$interval][$cohort];
            if ($cell === null) {
                $cell = ['size' => 0, 'mrr0' => 0, 'count' => [], 'mrr' => []];
            }
            $cell['size']++;

            // MRR: every running subscription adds its own; the cohort's start is what began in the cohort month
            foreach ($members as $span) {
                if ($span['from'] === $cohort) {
                    $cell['mrr0'] += $span['mrr'];
                }
                if ($span['to'] > $span['from']) {
                    $cell['mrr'][$span['from']] = ($cell['mrr'][$span['from']] ?? 0) + $span['mrr'];
                    $cell['mrr'][$span['to']] = ($cell['mrr'][$span['to']] ?? 0) - $span['mrr'];
                }
            }

            // Count: the customer once per month, over the union of their subscriptions' running months
            usort($members, function ($a, $b) {
                return $a['from'] <=> $b['from'];
            });
            $from = null;
            $to = null;
            foreach ($members as $span) {
                if ($span['to'] <= $span['from']) {
                    continue;
                }
                if ($from !== null && $span['from'] <= $to) {
                    $to = max($to, $span['to']);
                    continue;
                }
                if ($from !== null) {
                    $cell['count'][$from] = ($cell['count'][$from] ?? 0) + 1;
                    $cell['count'][$to] = ($cell['count'][$to] ?? 0) - 1;
                }
                $from = $span['from'];
                $to = $span['to'];
            }
            if ($from !== null) {
                $cell['count'][$from] = ($cell['count'][$from] ?? 0) + 1;
                $cell['count'][$to] = ($cell['count'][$to] ?? 0) - 1;
            }
            unset($cell);
        }
    }

    /**
     * A customer's ended subscription runs on until the same product, in the same currency, was bought again within
     * the rebuy window of its paid term's end: the customer never really left
     */
    protected function bridgeRebuys(array $spans): array
    {
        $window = SubscriptionReportService::REBUY_WINDOW_DAYS * DAY_IN_SECONDS;
        foreach ($spans as $i => $span) {
            if (empty($span['term_end'])) {
                continue;
            }
            foreach ($spans as $j => $other) {
                if ($i !== $j && $other['product'] === $span['product'] && $other['currency'] === $span['currency']
                    && abs($other['started'] - $span['term_end']) <= $window) {
                    $spans[$i]['to'] = max($spans[$i]['to'], $other['from']);
                }
            }
        }

        return $spans;
    }

    protected function addInterval(\DateTime $date, string $interval, $sub = null): \DateTime
    {
        // A custom billing schedule (every 4 months; plans migrated from WooCommerce) wins over the slug
        $schedule = $sub ? $this->billingSchedule($sub) : null;
        if ($schedule) {
            return (new \DateTime('@' . SubscriptionHelper::addBillingInterval($date->getTimestamp(), $interval, $schedule)))
                ->setTimezone($date->getTimezone());
        }

        $months = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12];
        if (!isset($months[$interval])) {
            return (clone $date)->modify($interval === 'weekly' ? '+1 week' : '+1 day');
        }

        // As MySQL's + INTERVAL n MONTH: a day the target month lacks becomes its last day (31 Jan + 1 month = 28 Feb)
        $next = (clone $date)->setDate((int) $date->format('Y'), (int) $date->format('n') + $months[$interval], 1);

        return $next->setDate((int) $next->format('Y'), (int) $next->format('n'), min((int) $date->format('j'), (int) $next->format('t')));
    }

    protected function isSimulatedTrial($sub): bool
    {
        $config = is_array($sub->config ?? null) ? $sub->config : json_decode((string) ($sub->config ?? ''), true);

        return is_array($config) && ($config['is_trial_days_simulated'] ?? '') === 'yes';
    }

    /**
     * One customer range's snapshots into the build table
     */
    protected function insertBuildRows(array $aggregates, array $months, string $currency, int $rebuyAdjusted): void
    {
        foreach (array_chunk($this->buildRows($aggregates, $months, $currency, $rebuyAdjusted), 2000) as $rows) {
            $this->insertBatch(self::BUILD_TABLE, $rows);
        }
    }

    /**
     * Snapshot rows of one customer range: running retained counts and MRR per period, MRR still as twelve times the
     * monthly cents so ranges add up exactly
     */
    protected function buildRows(array $aggregates, array $months, string $currency, int $rebuyAdjusted): array
    {
        $rows = [];
        $count = count($months);
        foreach ($aggregates as $product => $intervals) {
            foreach ($intervals as $interval => $cohorts) {
                foreach ($cohorts as $cohort => $cell) {
                    $retained = 0;
                    $retainedX12 = 0;
                    for ($k = $cohort; $k < $count; $k++) {
                        $retained += $cell['count'][$k] ?? 0;
                        $retainedX12 += $cell['mrr'][$k] ?? 0;
                        $rows[] = [
                            'cohort'             => $months[$cohort],
                            'period'             => $months[$k],
                            'product_id'         => $product === 'all' ? null : (int) $product,
                            'currency'           => $currency,
                            'billing_interval'   => (string) $interval,
                            'rebuy_adjusted'     => $rebuyAdjusted,
                            'period_offset'      => $k - $cohort,
                            'cohort_customers'   => $cell['size'],
                            'cohort_x12'         => $cell['mrr0'],
                            'retained_customers' => $retained,
                            'retained_x12'       => $retainedX12,
                        ];
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * The last step: add up the ranges and replace the published snapshots
     */
    protected function publish(array $months): void
    {
        // Until the last row is in, the page must rebuild on its next visit: a request that dies here leaves the table part-filled
        update_option(self::STALE_OPTION, 1, false);
        App::db()->table('fct_retention_snapshots')->delete();

        // A year of periods per read bounds memory with few scans of the unindexed build table; store-wide
        // rounding never crosses periods
        $dimensions = ['cohort', 'period', 'product_id', 'currency', 'billing_interval', 'rebuy_adjusted'];
        foreach (array_chunk($months, 12) as $periods) {
            $groups = App::db()->table(self::BUILD_TABLE)
                ->selectRaw(implode(', ', $dimensions) . ', MAX(period_offset) AS period_offset, SUM(cohort_customers) AS cohort_customers,
                    SUM(cohort_x12) AS cohort_x12, SUM(retained_customers) AS retained_customers, SUM(retained_x12) AS retained_x12')
                ->whereIn('period', $periods)
                ->groupBy($dimensions)
                ->get()
                ->map(function ($row) {
                    return (array) $row;
                })
                ->all();
            foreach (array_chunk($this->publishedRows($groups), 2000) as $rows) {
                $this->insertBatch('fct_retention_snapshots', $rows);
            }
        }
        App::db()->table(self::BUILD_TABLE)->delete();
    }

    /**
     * Published snapshot rows from build rows that are already one per snapshot key: MRR turned into cents once, and
     * the store-wide rows of a month rounded to add up to its total rounded once (largest remainder), which equals
     * the health MRR card for the current month
     */
    protected function publishedRows(array $groups): array
    {
        $storeX12 = [];
        foreach ($groups as $index => $row) {
            if ($row['product_id'] === null && (string) $row['billing_interval'] === '') {
                $storeX12[$row['currency'] . '|' . $row['rebuy_adjusted'] . '|' . $row['period']][$index] = (int) $row['retained_x12'];
            }
        }
        $rounded = [];
        foreach ($storeX12 as $rows) {
            $target = intdiv(array_sum($rows) + 6, 12);
            $remainders = [];
            foreach ($rows as $index => $times12) {
                $rounded[$index] = intdiv($times12, 12);
                $remainders[$index] = $times12 % 12;
            }
            arsort($remainders);
            $extra = $target - array_sum(array_intersect_key($rounded, $rows));
            foreach (array_keys($remainders) as $index) {
                if ($extra-- <= 0) {
                    break;
                }
                $rounded[$index]++;
            }
        }

        $generatedAt = DateTime::gmtNow()->format('Y-m-d H:i:s');
        $snapshots = [];
        foreach ($groups as $index => $row) {
            $size = (int) $row['cohort_customers'];
            $retained = (int) $row['retained_customers'];
            $cohortMrr = intdiv((int) $row['cohort_x12'] + 6, 12);
            $retainedMrr = $rounded[$index] ?? intdiv((int) $row['retained_x12'] + 6, 12);
            $snapshots[] = [
                'cohort'                   => $row['cohort'],
                'period'                   => $row['period'],
                'product_id'               => $row['product_id'] === null ? null : (int) $row['product_id'],
                'currency'                 => $row['currency'],
                'billing_interval'         => (string) $row['billing_interval'],
                'rebuy_adjusted'           => (int) $row['rebuy_adjusted'],
                'period_offset'            => (int) $row['period_offset'],
                'cohort_customers'         => $size,
                'cohort_mrr'               => $cohortMrr,
                'retained_customers'       => $retained,
                'retained_mrr'             => $retainedMrr,
                'new_customers'            => 0,
                'churned_customers'        => $size - $retained,
                'retention_rate_customers' => $size ? round($retained / $size * 100, 2) : 0,
                'retention_rate_mrr'       => $cohortMrr > 0 ? round($retainedMrr / $cohortMrr * 100, 2) : 0,
                'created_at'               => $generatedAt,
                'updated_at'               => $generatedAt,
            ];
        }

        return $snapshots;
    }

    /**
     * Determine customer's state for a given month
     */
    protected function getCustomerStateForMonth($subscriptions, string $month): array
    {
        // The store month's end; PHP compares it with the GMT dates as instants
        $monthStart = new \DateTime($month . '-01', wp_timezone());
        $monthEnd = (clone $monthStart)->modify('last day of this month')->setTime(23, 59, 59);

        // Every period, the cohort month included, counts who is subscribed at its end (now for the month still
        // running): the month-end snapshot that MRR and retention reports use, and the Retention table's rule
        $now = $this->getNow();
        $pointInTime = $monthEnd > $now ? $now : $monthEnd;

        $isActive = false;
        $mrrX12 = 0;

        foreach ($subscriptions as $sub) {
            $createdAt = new \DateTime($sub->created_at);

            // Skip subscriptions created after this month
            if ($createdAt > $monthEnd) {
                continue;
            }

            if ($this->wasSubscriptionActiveAt($sub, $pointInTime)) {
                $isActive = true;
                // Twelve times the monthly cents, summed over the pair's subscriptions; divided once on insert
                $mrrX12 += $this->mrrTimes12($sub);
            }
        }

        return [
            'is_active' => $isActive,
            'mrr'       => intdiv($mrrX12 + 6, 12),
            'mrr_x12'   => $mrrX12,
        ];
    }

    protected function getNow(): \DateTime
    {
        if (!$this->now) {
            $this->now = new \DateTime(DateTime::gmtNow()->format('Y-m-d H:i:s'));
        }

        return $this->now;
    }

    protected function wasSubscriptionActiveAt($sub, \DateTime $at): bool
    {
        if (new \DateTime($sub->created_at) > $at) {
            return false;
        }

        $endDate = $this->getSubscriptionEndDate($sub);

        return !$endDate || $endDate > $at;
    }

    /**
     * Get the effective end date of a subscription
     * Returns null if subscription is still ongoing
     */
    protected function getSubscriptionEndDate($sub): ?\DateTime
    {
        // Same rule as SubscriptionReportService::leftAtSql(), except that a custom billing schedule sets the paid
        // term here; the SQL reads only the interval slug
        $date = function ($value) {
            return (empty($value) || $value === '0000-00-00 00:00:00') ? null : new \DateTime($value);
        };
        $earliest = function (array $dates) {
            $dates = array_filter($dates);

            return $dates ? min($dates) : null;
        };

        if (in_array($sub->status, $this->activeStatuses, true)) {
            return null;
        }
        if (in_array($sub->status, [Status::SUBSCRIPTION_FAILING, Status::SUBSCRIPTION_PAST_DUE], true)) {
            return $date($sub->next_billing_date ?? null) ?: $date($sub->updated_at ?? null);
        }
        if ($sub->status === Status::SUBSCRIPTION_PAUSED) {
            return $date($sub->updated_at ?? null);
        }

        $ended = $earliest([$date($sub->canceled_at ?? null), $date($sub->expire_at ?? null)]);
        if ($sub->status === Status::SUBSCRIPTION_CANCELED && !$date($sub->canceled_at ?? null)) {
            $ended = $earliest([$date($sub->updated_at ?? null), $date($sub->expire_at ?? null)]);
        }

        // An ended subscription with no date at all left when it was last updated, never "still active"
        $ended = $ended ?: $date($sub->updated_at ?? null);

        // A cancel keeps the paid term (SubscriptionReportService::keepsAccessSql): it leaves when that term ends,
        // by its custom billing schedule when it has one
        if ($sub->status === Status::SUBSCRIPTION_CANCELED && $this->keepsAccess($sub)) {
            $paid = !empty($this->lastPaidDates[(int) ($sub->parent_order_id ?? 0)])
                ? max(new \DateTime($sub->created_at), new \DateTime($this->lastPaidDates[(int) $sub->parent_order_id]))
                : new \DateTime($sub->created_at);
            $termEnd = $this->addInterval($paid, (string) ($sub->billing_interval ?? ''), $sub);
            $ended = $ended && $ended > $termEnd ? $ended : $termEnd;
        }

        return $ended;
    }

    /**
     * The subscription's custom billing schedule, or null. Read only when its config names one: most plans have none.
     */
    protected function billingSchedule($sub)
    {
        $config = $sub->config ?? null;
        if (!$config || (is_string($config) && strpos($config, 'billing_schedule') === false)) {
            return null;
        }

        return ReportHelper::billingScheduleFromConfig($config);
    }

    protected function keepsAccess($sub): bool
    {
        $config = is_array($sub->config ?? null) ? $sub->config : json_decode((string) ($sub->config ?? ''), true);
        $reason = is_array($config) ? (string) ($config['cancellation_reason'] ?? '') : '';
        // Replaced by an upgrade (with or without a reason stored): the new plan carries on
        if (in_array($reason, ['refunded', 'fraudulent', 'upgraded_to_new_plan'], true) || (int) ($config['upgraded_to_sub_id'] ?? 0) > 0) {
            return false;
        }

        // A free trial that never paid had no paid term
        return !((int) ($sub->trial_days ?? 0) > 0 && !$this->isSimulatedTrial($sub) && empty($this->lastPaidDates[(int) ($sub->parent_order_id ?? 0)]));
    }

    /**
     * Twelve times the monthly MRR in cents: SubscriptionReportService::mrrTimes12Sql() for one subscription.
     */
    protected function mrrTimes12($sub): int
    {
        $cycles = [
            'monthly' => 12, 'quarterly' => 4, 'half_yearly' => 2, 'yearly' => 1, 'weekly' => 52, 'daily' => 365,
        ];
        $perYear = $cycles[$sub->billing_interval ?? ''] ?? 0;

        // A plan reached by an upgrade counts at its full price: its prorated first cycle is the upgrade credit
        $config = in_array($sub->status, [Status::SUBSCRIPTION_TRIALING, Status::SUBSCRIPTION_CANCELED], true)
            ? (is_array($sub->config ?? null) ? $sub->config : json_decode((string) ($sub->config ?? ''), true)) : null;
        if (is_array($config) && (int) ($config['upgraded_from_sub_id'] ?? 0) > 0) {
            return (int) ($sub->recurring_total ?? $sub->recurring_amount ?? 0) * $perYear;
        }
        if ($sub->status === Status::SUBSCRIPTION_TRIALING) {
            // A real free trial is not MRR; a simulated one counts at the price paid now
            return (int) ($this->paidNow[(int) $sub->id] ?? 0) * $perYear;
        }
        // A canceled discounted first cycle stays at the price paid until it ends
        if ($sub->status === Status::SUBSCRIPTION_CANCELED && isset($this->paidNow[(int) $sub->id])) {
            return (int) $this->paidNow[(int) $sub->id] * $perYear;
        }

        return (int) ($sub->recurring_total ?? $sub->recurring_amount ?? 0) * $perYear;
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
