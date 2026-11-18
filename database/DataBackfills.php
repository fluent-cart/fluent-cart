<?php

namespace FluentCart\Database;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\App\Modules\MultiCurrency\Services\BaseCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\Framework\Database\Schema;
use FluentCart\Framework\Support\Arr;

/**
 * One-shot data backfills / repairs. Migrators stay schema-only.
 *
 * Delivery: nothing runs on normal requests. While a registered backfill is
 * pending, the admin app gets has_data_migrations = true and silently POSTs
 * data-backfills/run (manage_options via AdminPolicy) until the server says
 * completed. Completion is tracked per slug under the _db_migrations option
 * (fct_meta), marked only after a backfill's FINAL chunk.
 *
 * Every backfill MUST have (repairInstallmentBillTimes is the reference):
 *  - a registry entry (slug => title) with ship date and removal target
 *  - bounded chunks per request with a persisted keyset cursor (resume,
 *    never restart from id 0)
 *  - batched per-chunk lookups (whereIn) — per-row queries only for rows
 *    actually being repaired
 *  - idempotent row logic; the advisory lock below; id-keyed report merge
 *
 * Removal: delete the runner, its registry entry, and its cursor/report options.
 */
class DataBackfills
{
    /**
     * Registered backfills: slug => ['title' => ...] (title is for logging).
     */
    public static function getRegistry()
    {
        return [
            // 2026-07-03 — shipped with the installment bill_times fix (PR #2194).
            // Remove after one or two releases once affected installs have upgraded.
            'installment_payments' => [
                'title' => __('Installment Payments Backfill', 'fluent-cart'),
            ],
            // 2026-07-22 — shipped with the completed-subscription guard in
            // Subscription::cancelRemoteSubscription: the EOT flow used to stamp
            // next_billing_date with the completion time on completed rows.
            // Remove after one or two releases once affected installs have upgraded.
            'completed_next_billing_date' => [
                'title' => __('Completed Subscription Billing Date Cleanup', 'fluent-cart'),
            ],
            // 2026-07-25 — idx_order_addresses_order_id_type has been declared in
            // OrderAddressesMigrator::migrated() since 2026-06-06, but migrated()
            // only runs on ACTIVATION and a WordPress in-place update never fires
            // the activation hook, so stores that updated rather than
            // deactivated/reactivated still have fct_order_addresses with nothing
            // but its PRIMARY key. Delivered here instead of behind a DB-version
            // bump: an index is not a correctness change, so it does not warrant
            // forcing the whole version-gated block to re-run on every install.
            // Remove after one or two releases once affected installs have upgraded.
            'order_address_index' => [
                'title' => __('Order Address Index', 'fluent-cart'),
            ],
            // 2026-09-27 — multi-currency (dev-docs/multi-currency/02-database-schema.md, "Data backfills").
            // Orders that predate currency snapshots get one: legacy_base when
            // in the base currency, legacy_unknown otherwise — never a guessed
            // rate. Remove after one or two releases once installs have upgraded.
            'order_currency_snapshot' => [
                'title' => __('Order Currency Snapshot', 'fluent-cart'),
            ],
            // 2026-09-27 — copies each subscription's currency out of its
            // config JSON / parent order into the new currency column, plus the
            // parent order's snapshot that renewals carry.
            // Remove after one or two releases once installs have upgraded.
            'subscription_currency' => [
                'title' => __('Subscription Currency', 'fluent-cart'),
            ],
            // 2026-09-27 — recount customer stats per currency: purchase_value
            // becomes a per-currency map again and ltv/aov stop adding
            // different currencies together. Remove after one or two releases.
            'customer_currency_stats' => [
                'title' => __('Customer Currency Stats', 'fluent-cart'),
            ],
            // 2026-10-22 — old amount refunds stopped each line at line_total, losing the
            // tax on top, and shipping refunds were never recorded. Remove after one or
            // two releases.
            'refund_totals_repair' => [
                'title' => 'Refund Totals Repair',
            ],
            // 2026-11-11 — the WooCommerce and EDD migrators imported simple products
            // with an empty variation title, so the product editor could not save them.
            // Remove after one or two releases.
            'simple_variation_title' => [
                'title' => __('Simple Product Variation Title', 'fluent-cart'),
            ],
        ];
    }

    /**
     * @return array pending backfill slugs (registered but not completed)
     */
    public static function getPending()
    {
        $option = (array)fluent_cart_get_option('_db_migrations', [], false);
        $failures = (array)Arr::get($option, 'backfill_failures', []);
        $doneSlugs = [];

        foreach ((array)Arr::get($option, 'backfills', []) as $slug => $state) {
            if (!$state) {
                continue;
            }

            // a retired backfill gets fresh tries once the plugin version changes
            if ($state === 'failed' && Arr::get($failures, $slug . '.version') !== FLUENTCART_VERSION) {
                continue;
            }

            $doneSlugs[] = $slug;
        }

        return array_values(array_diff(array_keys(self::getRegistry()), $doneSlugs));
    }

    public static function hasPending()
    {
        return (bool)self::getPending();
    }

    /**
     * How many times the order-address index DDL may be retried before the slug
     * retires. Small on purpose: the only failures worth retrying are transient locks,
     * and each retry is an immediate re-post from the browser driver, not a page load.
     */
    const ORDER_ADDRESS_INDEX_MAX_ATTEMPTS = 3;

    /**
     * Failures in a row before a backfill is retired until the next plugin version.
     */
    const BACKFILL_MAX_FAILURES = 3;

    /**
     * What the running backfill's keyset loop did in this call: whether it
     * moved the cursor, and the last id it reached.
     */
    private static $chunkProgress = ['advanced' => false, 'last_id' => null];

    /**
     * When the running processPending() call stops starting new work, as a
     * microtime. Null outside a call.
     */
    private static $deadline = null;

    /**
     * Run pending backfills within this request's budget. Called from the
     * data-backfills/run REST endpoint — the admin app re-calls while the
     * returned status is 'running'.
     *
     * One time budget covers the whole call: after the batch that passes it,
     * no further batch and no further backfill starts.
     *
     * A backfill that throws keeps its cursor and returns 'stalled': the admin
     * app stops, and the next page load retries from the cursor. Later backfills
     * wait behind it until it passes or is retired.
     *
     * @return array ['status' => completed|running|stalled|locked, 'completed' => [], 'pending' => []]
     */
    public static function processPending()
    {
        $pending = self::getPending();

        if (!$pending) {
            return ['status' => 'completed', 'completed' => [], 'pending' => []];
        }

        if (!self::acquireBackfillLock()) {
            // another request/tab is already on it — let that one finish
            return ['status' => 'locked', 'completed' => [], 'pending' => $pending];
        }

        $completedNow = [];
        $stalled = false;
        self::$deadline = microtime(true) + max(0, (int)apply_filters('fluent_cart/data_backfills/max_seconds', 15));

        try {
            foreach ($pending as $slug) {
                self::$chunkProgress = ['advanced' => false, 'last_id' => null];

                try {
                    $finished = self::runBackfill($slug);
                } catch (\Throwable $e) {
                    if (self::recordFailure($slug, $e->getMessage())) {
                        if (self::deadlinePassed()) {
                            break;
                        }

                        continue; // retired — later backfills no longer wait behind it
                    }

                    $stalled = true;
                    break;
                }

                self::clearFailure($slug);

                if (!$finished) {
                    break; // request budget spent — the next call resumes from the cursor
                }

                self::markCompleted($slug);
                $completedNow[] = $slug;

                if (self::deadlinePassed()) {
                    break;
                }
            }
        } finally {
            self::$deadline = null;
            self::releaseBackfillLock();
        }

        $stillPending = self::getPending();

        if ($stalled) {
            $status = 'stalled';
        } else {
            $status = $stillPending ? 'running' : 'completed';
        }

        return [
            'status'    => $status,
            'completed' => $completedNow,
            'pending'   => $stillPending,
        ];
    }

    private static function deadlinePassed()
    {
        return self::$deadline !== null && microtime(true) >= self::$deadline;
    }

    /**
     * Count a failed call against the backfill. A call that moved the cursor
     * before failing, or the first failure on a new plugin version, starts the
     * count again.
     *
     * @param string $slug
     * @param string $error
     * @return bool true when the backfill reached the cap and was retired
     */
    private static function recordFailure($slug, $error)
    {
        $option = (array)fluent_cart_get_option('_db_migrations', [], false);
        $backfills = (array)Arr::get($option, 'backfills', []);
        $failures = (array)Arr::get($option, 'backfill_failures', []);
        $previous = (array)Arr::get($failures, $slug, []);

        $continues = !Arr::get(self::$chunkProgress, 'advanced') && Arr::get($previous, 'version') === FLUENTCART_VERSION;
        $count = $continues ? (int)Arr::get($previous, 'count', 0) + 1 : 1;
        $error = mb_substr((string)$error, 0, 500);
        $retired = $count >= self::BACKFILL_MAX_FAILURES;

        $failures[$slug] = [
            'count'   => $count,
            'error'   => $error,
            'version' => FLUENTCART_VERSION,
        ];

        if ($retired) {
            $backfills[$slug] = 'failed';
        } else {
            unset($backfills[$slug]);
        }

        $option['backfills'] = $backfills;
        $option['backfill_failures'] = $failures;

        fluent_cart_update_option('_db_migrations', $option);

        if (!$retired) {
            return false;
        }

        $title = Arr::get(self::getRegistry(), $slug . '.title', $slug);
        $lastId = Arr::get(self::$chunkProgress, 'last_id');

        fluent_cart_add_log(
            /* translators: 1: data backfill title */
            sprintf(__('%1$s failed', 'fluent-cart'), $title),
            sprintf(
                /* translators: 1: data backfill slug, 2: number of failed attempts, 3: last processed row id, 4: error message */
                __('Data backfill "%1$s" failed %2$d times in a row and was stopped until the next plugin update. Last processed id: %3$s. Error: %4$s', 'fluent-cart'),
                $slug,
                $count,
                $lastId === null ? '-' : $lastId,
                $error
            ),
            'warning',
            [
                'module_name' => 'activity',
                'module_id'   => 0
            ]
        );

        return true;
    }

    private static function clearFailure($slug)
    {
        $option = (array)fluent_cart_get_option('_db_migrations', [], false);
        $failures = (array)Arr::get($option, 'backfill_failures', []);

        if (!isset($failures[$slug])) {
            return;
        }

        unset($failures[$slug]);
        $option['backfill_failures'] = $failures;

        if (Arr::get($option, 'backfills.' . $slug) === 'failed') {
            unset($option['backfills'][$slug]);
        }

        fluent_cart_update_option('_db_migrations', $option);
    }

    /**
     * @return bool true when the backfill finished; false = budget spent, more remains
     */
    private static function runBackfill($slug)
    {
        if ($slug === 'installment_payments') {
            return self::repairInstallmentBillTimes();
        }

        if ($slug === 'completed_next_billing_date') {
            return self::clearCompletedNextBillingDates();
        }

        if ($slug === 'order_address_index') {
            return self::ensureOrderAddressIndex();
        }

        if ($slug === 'order_currency_snapshot') {
            return self::backfillOrderCurrencySnapshots();
        }

        if ($slug === 'subscription_currency') {
            return self::backfillSubscriptionCurrency();
        }

        if ($slug === 'customer_currency_stats') {
            return self::recountCustomerCurrencyStats();
        }

        if ($slug === 'refund_totals_repair') {
            return self::repairRefundTotals();
        }

        if ($slug === 'simple_variation_title') {
            return self::fillSimpleVariationTitles();
        }

        // registered slug without a runner — mark done so it can't wedge the
        // queue, but leave a trace since this is a programming error
        fluent_cart_add_log(
            'Data backfill has no runner',
            'Backfill "' . $slug . '" is registered but has no runner. Marked completed to unblock the queue.',
            'warning',
            [
                'module_name' => 'activity',
                'module_id'   => 0
            ]
        );

        return true;
    }

    private static function markCompleted($slug)
    {
        $option = (array)fluent_cart_get_option('_db_migrations', [], false);
        $backfills = (array)Arr::get($option, 'backfills', []);
        $backfills[$slug] = 'yes';
        $option['backfills'] = $backfills;

        fluent_cart_update_option('_db_migrations', $option);

        $title = Arr::get(self::getRegistry(), $slug . '.title', $slug);

        fluent_cart_add_log(
            /* translators: 1: data backfill title */
            sprintf(__('%1$s completed', 'fluent-cart'), $title),
            /* translators: 1: data backfill slug */
            sprintf(__('Data backfill "%1$s" finished and was marked completed.', 'fluent-cart'), $slug),
            'info',
            [
                'module_name' => 'activity',
                'module_id'   => 0
            ]
        );
    }

    /**
     * Apply fct_order_addresses' declared indexes on installs that never ran the
     * activation hook.
     *
     * The DDL itself is NOT written here — Migrators stay the single home for
     * schema, so this delegates to OrderAddressesMigrator::migrated(), which owns
     * idx_order_addresses_order_id_type and whose addIndexIfNotExists is a no-op
     * where activation already applied it. This runner only supplies the delivery
     * the activation hook missed.
     *
     * No cursor: this is one DDL statement, not a row scan. MySQL builds a
     * secondary index online (5.6+), so it does not lock the table for writes.
     *
     * The outcome is CHECKED, not assumed. addIndexIfNotExists() returns void and
     * routes through $wpdb->query(), which returns false on a failed DDL rather than
     * throwing — so a lock timeout, or a denied ALTER on a restricted grant, would
     * otherwise let this report success and retire the slug permanently with no index
     * and no trace.
     *
     * Failure is retried a BOUNDED number of times rather than by returning false
     * indefinitely. In this queue false means "budget spent, resume me", and the
     * browser driver in resources/admin/bootstrap/app.js re-posts immediately while the
     * status stays 'running' — up to 100 times per page load. An unfixable failure
     * (no ALTER grant) returned as false would therefore fire 100 doomed ALTERs and
     * write 100 log rows on every admin page load. A few attempts are enough to ride
     * out a transient lock; past that the slug retires with one clear warning, and the
     * index is still declared in the migrator so a later activation re-applies it.
     *
     * @return bool true when the index exists, the table does not, or the attempt
     *              budget is spent; false only to earn one more retry
     */
    private static function ensureOrderAddressIndex()
    {
        $table = Migrations\OrderAddressesMigrator::$tableName;

        // Nothing to index and nothing to retry — a fresh install creates the table
        // with the index already in getSqlSchema().
        if (!Schema::hasTable($table)) {
            return true;
        }

        Migrations\OrderAddressesMigrator::migrated();

        if (Migrations\OrderAddressesMigrator::hasOrderIdTypeIndex()) {
            return true;
        }

        $cursorKey = '_fluent_cart_order_address_index_attempts';
        $attempts  = (int) fluent_cart_get_option($cursorKey, 0, false) + 1;
        fluent_cart_update_option($cursorKey, $attempts);

        if ($attempts < self::ORDER_ADDRESS_INDEX_MAX_ATTEMPTS) {
            return false;
        }

        fluent_cart_add_log(
            'Order address index backfill gave up',
            'Could not create ' . Migrations\OrderAddressesMigrator::ORDER_ID_TYPE_INDEX
            . ' on ' . $table . ' after ' . $attempts . ' attempts. Check that the database'
            . ' user has ALTER permission. Order address lookups will still work, only'
            . ' slower; the index is re-applied on the next plugin activation. NOTE: the'
            . ' "' . Arr::get(self::getRegistry(), 'order_address_index.title', 'Order Address Index')
            . ' completed" entry logged straight after this one means this backfill'
            . ' STOPPED RETRYING, not that the index was created — the shared queue logs'
            . ' that line for every slug it retires. This warning is the real outcome.',
            'warning',
            [
                'module_name' => 'activity',
                'module_id'   => 0,
            ]
        );

        return true;
    }

    /**
     * Repair installment subscriptions whose bill_times was stored decremented
     * by the old discount/simulated-trial checkout (completion then fired one
     * installment early and canceled the remote subscription).
     *
     * Restores bill_times from the parent order item's other_info.times,
     * backfills billed_cycles_offset for fully-free first cycles ($0 order),
     * and recomputes bill_count with the same formula syncSubscriptionStates
     * uses. Only rows matching the exact bug signature (bill_times == times - 1)
     * are touched — which also makes re-runs idempotent. bill_times = 0 rows
     * (unlimited) are excluded; a times=1 product sold with a discount landed
     * there and stays unrepaired (accepted trade-off).
     *
     * @return bool true when the scan reached the end of the table
     */
    private static function repairInstallmentBillTimes()
    {
        $chunkSize = 500;
        $maxChunksPerRun = 5;
        $maxRepairsPerRun = 500;
        $chunksProcessed = 0;
        $lastId = (int)fluent_cart_get_option('_fluent_cart_installment_repair_cursor', 0, false);
        $offsetIds = [];
        $underCollectedIds = [];
        $anomalousIds = [];
        $repairedRows = [];

        do {
            $subscriptions = Subscription::query()
                ->where('id', '>', $lastId)
                ->where('bill_times', '>', 0)
                ->where('config', 'LIKE', '%is_trial_days_simulated%')
                ->orderBy('id', 'ASC')
                ->limit($chunkSize)
                ->get();

            if ($subscriptions->isEmpty()) {
                break;
            }

            // batched lookups — two queries per chunk, not per subscription
            $orderIds = [];
            foreach ($subscriptions as $subscription) {
                $orderIds[$subscription->parent_order_id] = $subscription->parent_order_id;
            }

            $itemsByOrderId = [];
            $orderItems = OrderItem::query()
                ->whereIn('order_id', array_values($orderIds))
                ->where('payment_type', 'subscription')
                ->get();
            foreach ($orderItems as $item) {
                $itemsByOrderId[$item->order_id][] = $item;
            }

            $ordersById = [];
            $parentOrders = Order::query()->whereIn('id', array_values($orderIds))->get();
            foreach ($parentOrders as $order) {
                $ordersById[$order->id] = $order;
            }

            $subscriptionIds = [];
            foreach ($subscriptions as $subscription) {
                $subscriptionIds[] = $subscription->id;
            }

            // batched per chunk, not per repaired row — grouped charge counts
            $billsCountBySubscriptionId = [];
            $transactionCounts = OrderTransaction::query()
                ->selectRaw('subscription_id, COUNT(*) as total')
                ->whereIn('subscription_id', $subscriptionIds)
                ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                ->where('status', Status::TRANSACTION_SUCCEEDED)
                ->where('total', '>', 0)
                ->groupBy('subscription_id')
                ->get();
            foreach ($transactionCounts as $row) {
                $billsCountBySubscriptionId[(int)$row->subscription_id] = (int)$row->total;
            }

            $earlyPaymentHistoryBySubscriptionId = [];
            $earlyPaymentMetaRows = SubscriptionMeta::query()
                ->whereIn('subscription_id', $subscriptionIds)
                ->where('meta_key', 'early_payment_history')
                ->get();
            foreach ($earlyPaymentMetaRows as $metaRow) {
                $earlyPaymentHistoryBySubscriptionId[(int)$metaRow->subscription_id] = $metaRow->meta_value;
            }

            foreach ($subscriptions as $subscription) {
                $lastId = $subscription->id;

                if (Arr::get($subscription->config, 'is_trial_days_simulated', 'no') !== 'yes') {
                    continue;
                }

                $orderSubscriptionItems = Arr::get($itemsByOrderId, $subscription->parent_order_id, []);

                $orderItem = null;
                foreach ($orderSubscriptionItems as $candidateItem) {
                    if ((int)$candidateItem->object_id === (int)$subscription->variation_id) {
                        $orderItem = $candidateItem;
                        break;
                    }
                }

                // fallback only when unambiguous — on a multi-subscription order the
                // wrong item's times could overwrite this subscription's count
                if (!$orderItem && count($orderSubscriptionItems) === 1) {
                    $orderItem = $orderSubscriptionItems[0];
                }

                if (!$orderItem) {
                    continue;
                }

                $originalTimes = (int)Arr::get((array)$orderItem->other_info, 'times', 0);

                // more than one below the sold count = the old admin flow's double
                // decrement OR a deliberate reduction — can't tell apart, surface only
                if ($originalTimes > 1 && (int)$subscription->bill_times < $originalTimes - 1) {
                    $anomalousIds[] = $subscription->id;

                    fluent_cart_add_log(
                        'Installment subscription needs manual review',
                        'Subscription #' . $subscription->id . ' has bill_times ' . (int)$subscription->bill_times
                        . ' but its order item was sold with ' . $originalTimes . ' installments. This does not match '
                        . 'the known miscount signature (exactly one less), so it was not auto-repaired. '
                        . 'Verify the intended installment count and adjust manually if needed.',
                        'warning',
                        [
                            'module_name' => 'subscription',
                            'module_id'   => $subscription->id
                        ]
                    );

                    continue;
                }

                // exact bug signature only — everything else (already repaired,
                // method-switch flag, deliberate adjustment) stays untouched
                if ($originalTimes < 1 || (int)$subscription->bill_times !== $originalTimes - 1) {
                    continue;
                }

                $parentOrder = Arr::get($ordersById, $subscription->parent_order_id);

                if (!$parentOrder) {
                    // can't tell a free first cycle from a paid one without the order
                    continue;
                }

                $isFreeFirstCycle = !(int)$parentOrder->total_amount && !(int)$subscription->signup_fee;
                if ($isFreeFirstCycle) {
                    $subscription->updateMeta('billed_cycles_offset', 1);
                    $offsetIds[] = $subscription->id;
                }

                $billsCount = Arr::get($billsCountBySubscriptionId, $subscription->id, 0);

                $earlyPaymentHistory = Arr::get($earlyPaymentHistoryBySubscriptionId, $subscription->id, []);
                foreach ((array)$earlyPaymentHistory as $earlyPayment) {
                    $paidCount = (int)Arr::get($earlyPayment, 'count', 1);
                    if ($paidCount > 1) {
                        $billsCount += ($paidCount - 1);
                    }
                }

                $billsCount += $isFreeFirstCycle ? 1 : 0;

                // before/after audit trail — the overwrite is otherwise irreversible
                $repairedRows[$subscription->id] = [
                    'status_before'     => $subscription->status,
                    'bill_times_before' => (int)$subscription->bill_times,
                    'bill_count_before' => (int)$subscription->bill_count,
                    'bill_times_after'  => $originalTimes,
                    'bill_count_after'  => $billsCount,
                ];

                $isUnderCollected = $subscription->status === Status::SUBSCRIPTION_COMPLETED
                    && $billsCount < $originalTimes;

                if ($isUnderCollected) {
                    // falsely completed (remote already canceled): back to active with a
                    // restored next_billing_date so the hourly expiry cron expires it
                    // through the production transition (events fire there, not here);
                    // the customer can then renew/reactivate to pay the remainder
                    $subscription->status = Status::SUBSCRIPTION_ACTIVE;
                    $subscription->next_billing_date = $subscription->guessNextBillingDate();
                }

                $subscription->bill_times = $originalTimes;
                $subscription->bill_count = $billsCount;
                $subscription->save();

                if ($isUnderCollected) {
                    $underCollectedIds[] = $subscription->id;

                    fluent_cart_add_log(
                        'Installment subscription under-collected',
                        'Subscription #' . $subscription->id . ' was completed early due to a bill_times miscount ('
                        . $billsCount . ' of ' . $originalTimes . ' installments collected) when a discount was applied '
                        . 'at checkout, and its remote subscription was canceled. Status set back to active; the hourly '
                        . 'expiry check will mark it expired, after which the customer can renew/reactivate to pay the '
                        . 'remaining installment(s).',
                        'warning',
                        [
                            'module_name' => 'subscription',
                            'module_id'   => $subscription->id
                        ]
                    );
                }
            }

            // cursor after every chunk — a timeout resumes here, never from id 0
            fluent_cart_update_option('_fluent_cart_installment_repair_cursor', $lastId);
            self::$chunkProgress = ['advanced' => true, 'last_id' => $lastId];
            $chunksProcessed++;

            // chunks bound the scan, repairs bound the heavy per-row work
            $budgetSpent = $chunksProcessed >= $maxChunksPerRun
                || count($repairedRows) >= $maxRepairsPerRun
                || self::deadlinePassed();

            if ($subscriptions->count() >= $chunkSize && $budgetSpent) {
                self::mergeRepairReport($repairedRows, $offsetIds, $underCollectedIds, $anomalousIds);

                return false;
            }
        } while ($subscriptions->count() >= $chunkSize);

        $report = self::mergeRepairReport($repairedRows, $offsetIds, $underCollectedIds, $anomalousIds);

        if ($report) {
            fluent_cart_add_log(
                'Installment bill_times repair completed',
                'Restored bill_times on ' . (int)Arr::get($report, 'restored_count', 0)
                . ' subscription(s), backfilled free-first-cycle offset on ' . (int)Arr::get($report, 'offset_count', 0)
                . ', flagged ' . count((array)Arr::get($report, 'under_collected_ids', [])) . ' under-collected and '
                . count((array)Arr::get($report, 'anomalous_ids', [])) . ' for manual review (see individual warning logs).',
                'info',
                [
                    'module_name' => 'subscription',
                    'module_id'   => 0
                ]
            );
        }

        // done — the cursor has no further use
        Meta::query()
            ->where('object_type', 'option')
            ->where('meta_key', '_fluent_cart_installment_repair_cursor')
            ->delete();

        return true;
    }

    /**
     * Clear the stale next_billing_date the pre-guard EOT flow stamped onto
     * completed subscriptions (cancelRemoteSubscription used to run its
     * effective_from=immediately assignment on completed rows too). Completed
     * subscriptions never bill again, so any non-null value here is the bug
     * signature — which also makes re-runs idempotent: cleared rows no longer
     * match the scan.
     *
     * @return bool true when the scan reached the end of the table
     */
    private static function clearCompletedNextBillingDates()
    {
        // Filterable so the chunk/budget boundary is testable with small
        // tables; production keeps the defaults.
        $budget = apply_filters('fluent_cart/data_backfills/chunk_budget', [
            'chunk_size'         => 500,
            'max_chunks_per_run' => 10,
        ], ['slug' => 'completed_next_billing_date']);

        $chunkSize = max(1, (int)Arr::get($budget, 'chunk_size', 500));
        $maxChunksPerRun = max(1, (int)Arr::get($budget, 'max_chunks_per_run', 10));
        $chunksProcessed = 0;
        $lastId = (int)fluent_cart_get_option('_fluent_cart_completed_billing_date_cursor', 0, false);
        $clearedIds = [];

        do {
            $rows = Subscription::query()
                ->select(['id'])
                ->where('id', '>', $lastId)
                ->where('status', Status::SUBSCRIPTION_COMPLETED)
                ->whereNotNull('next_billing_date')
                ->orderBy('id', 'ASC')
                ->limit($chunkSize)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $ids = [];
            foreach ($rows as $row) {
                $lastId = $row->id;
                $ids[] = $row->id;
            }

            // Fires between selection and write — a selected row CAN legitimately
            // change state here (reactivation, gateway resync); the UPDATE below
            // must re-check status so it never clears a live schedule.
            do_action('fluent_cart/data_backfills/chunk_selected', [
                'slug' => 'completed_next_billing_date',
                'ids'  => $ids,
            ]);

            // status re-checked in the UPDATE so a row that changed between the
            // scan and the write can't lose a legitimate billing date
            Subscription::query()
                ->whereIn('id', $ids)
                ->where('status', Status::SUBSCRIPTION_COMPLETED)
                ->update(['next_billing_date' => null]);

            // Report only rows the guarded UPDATE actually cleared — every
            // selected id had a non-null date, so post-update null + completed
            // is the cleared signature; a row the guard skipped keeps its date.
            $clearedRows = Subscription::query()
                ->select(['id'])
                ->whereIn('id', $ids)
                ->where('status', Status::SUBSCRIPTION_COMPLETED)
                ->whereNull('next_billing_date')
                ->get();
            foreach ($clearedRows as $clearedRow) {
                $clearedIds[] = $clearedRow->id;
            }

            // cursor after every chunk — a timeout resumes here, never from id 0
            fluent_cart_update_option('_fluent_cart_completed_billing_date_cursor', $lastId);
            self::$chunkProgress = ['advanced' => true, 'last_id' => $lastId];
            $chunksProcessed++;

            $budgetSpent = $chunksProcessed >= $maxChunksPerRun || self::deadlinePassed();

            if ($rows->count() >= $chunkSize && $budgetSpent) {
                self::mergeClearedBillingDateReport($clearedIds);

                return false;
            }
        } while ($rows->count() >= $chunkSize);

        $report = self::mergeClearedBillingDateReport($clearedIds);

        if ($report) {
            fluent_cart_add_log(
                'Completed subscription billing date cleanup completed',
                'Cleared the stale next_billing_date on ' . (int)Arr::get($report, 'cleared_count', 0)
                . ' completed subscription(s).',
                'info',
                [
                    'module_name' => 'subscription',
                    'module_id'   => 0
                ]
            );
        }

        // done — the cursor has no further use
        Meta::query()
            ->where('object_type', 'option')
            ->where('meta_key', '_fluent_cart_completed_billing_date_cursor')
            ->delete();

        return true;
    }

    /**
     * Give every order that predates currency snapshots a config.fx block.
     * Idempotent: only rows without one are selected. Amounts and the rate
     * column are never touched.
     *
     * @return bool true when done; false = budget spent, more remains
     */
    private static function backfillOrderCurrencySnapshots()
    {
        return self::runKeysetChunks('order_currency_snapshot', '_fluent_cart_order_fx_cursor', function ($lastId, $chunkSize) {
            return Order::query()
                ->select(['id', 'currency', 'rate', 'config', 'created_at', 'updated_at'])
                ->where('id', '>', $lastId)
                ->whereRaw("JSON_EXTRACT(config, '$.fx') IS NULL")
                ->orderBy('id', 'ASC')
                ->limit($chunkSize)
                ->get();
        }, function ($rows) {
            $written = true;
            foreach ($rows as $order) {
                $snapshot = CurrencySnapshot::legacy($order->currency, $order->rate, $order->created_at);
                $written = self::setSnapshot('fct_orders', $order->id, $snapshot);
                if ($written === false) {
                    break;
                }
            }

            return $written !== false;
        });
    }

    /**
     * Fill fct_subscriptions.currency (from config.currency, else the parent
     * order) and copy the parent order's snapshot into config.fx.
     *
     * @return bool true when done; false = budget spent, more remains
     */
    private static function backfillSubscriptionCurrency()
    {
        return self::runKeysetChunks('subscription_currency', '_fluent_cart_subscription_currency_cursor', function ($lastId, $chunkSize) {
            return Subscription::query()
                ->select(['id', 'parent_order_id', 'currency', 'config', 'created_at', 'updated_at'])
                ->where('id', '>', $lastId)
                ->where('currency', '')
                ->orderBy('id', 'ASC')
                ->limit($chunkSize)
                ->get();
        }, function ($rows) {
            $parentOrders = Order::query()
                ->select(['id', 'currency', 'rate', 'config', 'created_at'])
                ->whereIn('id', $rows->pluck('parent_order_id')->filter()->unique()->values()->toArray())
                ->get()
                ->keyBy('id');

            foreach ($rows as $subscription) {
                $config = is_array($subscription->config) ? $subscription->config : [];
                $parentOrder = $parentOrders->get($subscription->parent_order_id);

                $currency = Arr::get($config, 'currency') ?: ($parentOrder ? $parentOrder->currency : '');
                $currency = strtoupper((string) ($currency ?: BaseCurrency::get()));

                $snapshot = CurrencySnapshot::isSnapshot(Arr::get($config, 'fx'))
                    ? null
                    : (CurrencySnapshot::parentSnapshot($parentOrder, $currency) ?: CurrencySnapshot::legacy($currency, 1, $subscription->created_at));

                if (self::setSnapshot('fct_subscriptions', $subscription->id, $snapshot, $currency) === false) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Recount every customer's stats with the per-currency recountStat().
     * One grouped query per customer — every customer is being repaired.
     *
     * @return bool true when done; false = budget spent, more remains
     */
    private static function recountCustomerCurrencyStats()
    {
        return self::runKeysetChunks('customer_currency_stats', '_fluent_cart_customer_currency_stats_cursor', function ($lastId, $chunkSize) {
            return Customer::query()
                ->where('id', '>', $lastId)
                ->orderBy('id', 'ASC')
                ->limit($chunkSize)
                ->get();
        }, function ($customers) {
            foreach ($customers as $customer) {
                $customer->recountStat();
            }
        }, ['chunk_size' => 200, 'max_chunks_per_run' => 5]);
    }

    /**
     * Record only the refund split that is certain. When the order is nothing but its lines and
     * shipping, R − shipping must have gone to the lines and R − lines to shipping; anything in
     * between stays unassigned. Orders without tax or shipping lost nothing and are never read.
     *
     * @return bool true when done; false = budget spent or a refund lock was busy
     */
    private static function repairRefundTotals()
    {
        return self::runKeysetChunks('refund_totals_repair', '_fluent_cart_refund_totals_repair_cursor', function ($lastId, $chunkSize) {
            return Order::query()
                ->where('id', '>', $lastId)
                ->where('total_refund', '>', 0)
                ->where(function ($query) {
                    $query->where('tax_total', '>', 0)
                        ->orWhere('shipping_total', '>', 0)
                        ->orWhere('shipping_tax', '>', 0);
                })
                ->orderBy('id', 'ASC')
                ->limit($chunkSize)
                ->get();
        }, function ($orders) {
            $orders->load(['order_items', 'orderTaxRates', 'transactions']);

            $shippingRefunded = OrderMeta::query()
                ->whereIn('order_id', $orders->pluck('id')->toArray())
                ->where('meta_key', 'shipping_refunded_amount')
                ->orderBy('id', 'DESC')
                ->pluck('meta_value', 'order_id')
                ->toArray();

            $results = [];
            $finished = true;
            foreach ($orders as $order) {
                $orderId = (int) $order->id;
                $plan = self::planRefundTotals($order, (int) Arr::get($shippingRefunded, $orderId, 0));
                if ($plan && (Arr::get($plan, 'lines') || Arr::get($plan, 'shipping'))) {
                    $plan = self::applyRefundTotals($orderId);
                }

                if ($plan === false) {
                    $finished = false;
                    break;
                }

                $results[$orderId] = $plan;
            }

            self::mergeRefundTotalsReport($results);

            return $finished;
        }, ['chunk_size' => 200, 'max_chunks_per_run' => 5]);
    }

    /**
     * Writes under the order's refund lock, re-reading everything inside it, so a refund
     * being recorded right now is either fully seen or not seen at all.
     *
     * @return array|false|null applied plan, false when the lock is busy,
     *                          null when the order is no longer certain or a write lost a race
     */
    private static function applyRefundTotals($orderId)
    {
        global $wpdb;

        $lock = Schema::isSqlite() ? null : 'fct_refund_' . $orderId;
        if ($lock) {
            $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 0));
            if ($acquired === null) {
                return null;
            }
            if ((string) $acquired !== '1') {
                return false;
            }
        }

        try {
            $order = Order::query()->with(['order_items', 'orderTaxRates', 'transactions'])->find($orderId);
            if (!$order) {
                return null;
            }

            $shippingMeta = OrderMeta::query()
                ->where('order_id', $orderId)
                ->where('meta_key', 'shipping_refunded_amount')
                ->first();
            $plan = self::planRefundTotals($order, $shippingMeta ? (int) $shippingMeta->meta_value : 0);
            if (!$plan) {
                return $plan;
            }

            // compare-and-set: Pro gateways record some refunds without the lock
            $missed = false;
            foreach ((array) Arr::get($plan, 'lines', []) as $itemId => $change) {
                $written = OrderItem::query()
                    ->where('id', $itemId)
                    ->where('refund_total', Arr::get($change, 0))
                    ->update(['refund_total' => Arr::get($change, 1)]);
                if (!$written) {
                    unset($plan['lines'][$itemId]);
                    $missed = true;
                }
            }

            if (Arr::get($plan, 'shipping')) {
                list(, $new, $full) = Arr::get($plan, 'shipping');
                if ($shippingMeta) {
                    $written = OrderMeta::query()
                        ->where('id', $shippingMeta->id)
                        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                        ->where('meta_value', (string) Arr::get($shippingMeta->getAttributes(), 'meta_value'))
                        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                        ->update(['meta_value' => (string) $new]);
                    if (!$written) {
                        $plan['shipping'] = null;
                        $missed = true;
                    }
                } else {
                    $order->updateMeta('shipping_refunded_amount', $new);
                }

                if (Arr::get($plan, 'shipping') && $full) {
                    $order->updateMeta('shipping_refunded', '1');
                }
            }

            return $missed ? null : $plan;
        } finally {
            if ($lock) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
    }

    /**
     * @param int $shippingRefunded cents already recorded as shipping refunded
     * @return array|null ['lines' => [item id => [old, new]], 'shipping' => [old, new, full]|null],
     *                    null when the order's money can't be told apart for certain
     */
    private static function planRefundTotals(Order $order, $shippingRefunded)
    {
        $total = (int) $order->total_amount;
        $refunded = (int) $order->total_refund;
        $shippingCharged = $order->shippingChargedTotal();

        $charged = [];
        foreach ($order->order_items as $item) {
            /** @var OrderItem $item */
            $charged[(int) $item->id] = (int) $item->chargedTotal($order);
        }
        $linesCharged = array_sum($charged);

        // fees, order discounts, credits and trials put money outside lines and shipping
        if ($linesCharged + $shippingCharged !== $total || $refunded > $total) {
            return null;
        }

        $paid = 0;
        $refundRows = 0;
        $lastRefundAt = 0;
        foreach ($order->transactions as $transaction) {
            if ($transaction->transaction_type === Status::TRANSACTION_TYPE_CHARGE
                && in_array($transaction->status, Status::getTransactionSuccessStatuses(), true)) {
                $paid += (int) $transaction->total;
            } elseif ($transaction->transaction_type === Status::TRANSACTION_TYPE_REFUND
                && $transaction->status === Status::TRANSACTION_REFUNDED
                && Arr::get((array) $transaction->meta, 'local_sync') !== 'pending') {
                $refundRows += (int) $transaction->total;
            }
            if ($transaction->transaction_type === Status::TRANSACTION_TYPE_REFUND) {
                $lastRefundAt = max($lastRefundAt, (int) strtotime((string) $transaction->created_at));
            }
        }

        // items edited after payment, or total_refund counting rows that are not refunds
        if ($paid !== $total || $refundRows !== $refunded) {
            return null;
        }

        // Pro webhook refunds save total_refund before spreading lines, outside the lock
        if ($lastRefundAt > time() - 600) {
            return null;
        }

        $lineMoney = $refunded - $shippingCharged;
        $lines = [];
        foreach ($order->order_items as $item) {
            $itemId = (int) $item->id;
            $old = (int) $item->refund_total;
            $itemCharged = (int) Arr::get($charged, $itemId, 0);
            $certain = max(0, min($itemCharged, $lineMoney - ($linesCharged - $itemCharged)));

            if ($old > $itemCharged) {
                // the line's real share is anywhere from $certain up; only a full refund pins it
                if ($certain < $itemCharged) {
                    return null;
                }
                $lines[$itemId] = [$old, $itemCharged];
            } elseif ($old < $certain) {
                $lines[$itemId] = [$old, $certain];
            }
        }

        $shippingCertain = max(0, min($shippingCharged, $refunded - $linesCharged));
        $shipping = $shippingCertain > $shippingRefunded
            ? [$shippingRefunded, $shippingCertain, $shippingCertain >= $shippingCharged]
            : null;

        return ['lines' => $lines, 'shipping' => $shipping];
    }

    /**
     * Counts every order once: a chunk replayed after a busy lock re-reads orders already counted.
     *
     * @param array $results order id => applied plan, or null when skipped
     */
    private static function mergeRefundTotalsReport(array $results)
    {
        $report = (array) fluent_cart_get_option('_fluent_cart_refund_totals_repair_report', [], false);
        $countedThrough = (int) Arr::get($report, 'counted_through', 0);

        $repairedIds = (array) Arr::get($report, 'repaired_order_ids', []);
        $skippedIds = (array) Arr::get($report, 'skipped_order_ids', []);
        $counts = [
            'repaired_count'   => (int) Arr::get($report, 'repaired_count', 0),
            'lines_changed'    => (int) Arr::get($report, 'lines_changed', 0),
            'shipping_written' => (int) Arr::get($report, 'shipping_written', 0),
            'skipped_count'    => (int) Arr::get($report, 'skipped_count', 0),
        ];
        $changed = false;

        foreach ($results as $orderId => $plan) {
            if ($orderId <= $countedThrough) {
                continue;
            }
            $countedThrough = $orderId;

            if ($plan === null) {
                $counts['skipped_count']++;
                if (count($skippedIds) < 200) {
                    $skippedIds[] = $orderId;
                }
                $changed = true;
                continue;
            }

            if (!Arr::get($plan, 'lines') && !Arr::get($plan, 'shipping')) {
                continue;
            }

            $counts['repaired_count']++;
            $counts['lines_changed'] += count((array) Arr::get($plan, 'lines', []));
            $counts['shipping_written'] += Arr::get($plan, 'shipping') ? 1 : 0;
            if (count($repairedIds) < 200) {
                $repairedIds[] = $orderId;
            }
            $changed = true;
        }

        if (!$changed) {
            return;
        }

        fluent_cart_update_option('_fluent_cart_refund_totals_repair_report', array_merge($counts, [
            'repaired_at'        => gmdate('Y-m-d H:i:s'),
            'counted_through'    => $countedThrough,
            'repaired_order_ids' => $repairedIds,
            'skipped_order_ids'  => $skippedIds,
        ]));
    }

    /**
     * A simple product's variation title is its product title. variation_title is
     * VARCHAR(192), so a longer product title is cut to fit.
     *
     * @return bool true when done; false = budget spent or a write failed, more remains
     */
    private static function fillSimpleVariationTitles()
    {
        return self::runKeysetChunks('simple_variation_title', '_fluent_cart_simple_variation_title_cursor', function ($lastId, $chunkSize) {
            return ProductVariation::query()
                ->select(['id'])
                ->where('id', '>', $lastId)
                ->whereRaw("TRIM(variation_title) = ''")
                ->orderBy('id', 'ASC')
                ->limit($chunkSize)
                ->get();
        }, function ($rows) {
            global $wpdb;

            $variations = $wpdb->prefix . Migrations\ProductVariationMigrator::$tableName;
            $details    = $wpdb->prefix . Migrations\ProductDetailsMigrator::$tableName;
            $ids        = array_map('intval', $rows->pluck('id')->toArray());
            $idList     = implode(',', array_fill(0, count($ids), '%d'));

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and %d placeholders are fixed above
            return $wpdb->query($wpdb->prepare(
                "UPDATE {$variations} v
                INNER JOIN {$details} d ON d.post_id = v.post_id
                INNER JOIN {$wpdb->posts} p ON p.ID = v.post_id
                SET v.variation_title = LEFT(p.post_title, 192)
                WHERE v.id IN ({$idList})
                AND d.variation_type = %s
                AND TRIM(v.variation_title) = %s
                AND TRIM(p.post_title) <> %s",
                array_merge($ids, ['simple', '', ''])
            )) !== false;
        });
    }

    /**
     * Write a backfill snapshot in one statement. The chunk was read without
     * a lock, and a cancel or gateway update can commit in between: JSON_SET
     * changes only the fx key, so other config keys survive, and updated_at
     * is not written, so it is neither bumped nor reverted. A row that gained
     * a snapshot (or a currency) meanwhile is left alone.
     *
     * @param string $table fct_orders or fct_subscriptions
     * @param int $id
     * @param array|null $snapshot null to leave config.fx as it is
     * @param string|null $currency subscriptions only: fills an empty currency column
     * @return int|false false when the write failed
     */
    private static function setSnapshot($table, $id, $snapshot, $currency = null)
    {
        global $wpdb;
        $table = $wpdb->prefix . $table;

        $sets = [];
        $args = [];
        if ($snapshot !== null) {
            // A config that is not a JSON object (empty, '[]', invalid) starts from {}
            $sets[] = "config = IF(JSON_VALID(config),"
                . " IF(JSON_EXTRACT(config, '$.fx') IS NULL, JSON_SET(IF(JSON_TYPE(config) = 'OBJECT', config, '{}'), '$.fx', JSON_EXTRACT(%s, '$')), config),"
                . " JSON_OBJECT('fx', JSON_EXTRACT(%s, '$')))";
            $args[] = wp_json_encode($snapshot);
            $args[] = wp_json_encode($snapshot);
        }
        if ($currency !== null) {
            $sets[] = "currency = IF(currency = '', %s, currency)";
            $args[] = $currency;
        }
        if (!$sets) {
            return 0;
        }

        $args[] = (int) $id;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and SET clauses are fixed above
        return $wpdb->query($wpdb->prepare("UPDATE {$table} SET " . implode(', ', $sets) . ' WHERE id = %d', $args));
    }

    /**
     * Shared keyset-cursor loop for the currency backfills: bounded chunks
     * per request, cursor persisted after every chunk so a timeout resumes
     * instead of restarting, cursor deleted on completion.
     *
     * @param string $slug
     * @param string $cursorKey fct_meta option holding the last processed id
     * @param callable $fetch fn(int $lastId, int $chunkSize): Collection ordered by id
     * @param callable $apply fn(Collection $rows): mixed — false keeps the cursor on this chunk
     * @param array $defaultBudget chunk_size / max_chunks_per_run
     * @return bool true when done; false = budget spent or the chunk has to wait, more remains
     * @throws \RuntimeException when a chunk's write failed with a database error
     */
    private static function runKeysetChunks($slug, $cursorKey, callable $fetch, callable $apply, array $defaultBudget = [])
    {
        global $wpdb;

        $budget = apply_filters('fluent_cart/data_backfills/chunk_budget', wp_parse_args($defaultBudget, [
            'chunk_size'         => 500,
            'max_chunks_per_run' => 10,
        ]), ['slug' => $slug]);

        $chunkSize = max(1, (int)Arr::get($budget, 'chunk_size', 500));
        $maxChunksPerRun = max(1, (int)Arr::get($budget, 'max_chunks_per_run', 10));
        $chunksProcessed = 0;
        $lastId = (int)fluent_cart_get_option($cursorKey, 0, false);
        self::$chunkProgress['last_id'] = $lastId;

        do {
            $rows = $fetch($lastId, $chunkSize);

            if ($rows->isEmpty()) {
                break;
            }

            // The cursor stays on this chunk, so the next run retries these rows
            if ($apply($rows) === false) {
                // no database error means the chunk is only waiting (a row locked elsewhere)
                if ($wpdb->last_error) {
                    throw new \RuntimeException($wpdb->last_error);
                }

                return false;
            }

            $lastId = (int)$rows->last()->id;
            fluent_cart_update_option($cursorKey, $lastId);
            self::$chunkProgress = ['advanced' => true, 'last_id' => $lastId];
            $chunksProcessed++;

            $budgetSpent = $chunksProcessed >= $maxChunksPerRun || self::deadlinePassed();

            if ($rows->count() >= $chunkSize && $budgetSpent) {
                return false;
            }
        } while ($rows->count() >= $chunkSize);

        Meta::query()
            ->where('object_type', 'option')
            ->where('meta_key', $cursorKey)
            ->delete();

        return true;
    }

    /**
     * Accumulate cleared ids into the report option across partial runs —
     * id-keyed + deduped, so a replayed chunk can't double-count a subscription.
     *
     * @return array|null merged report, or null when nothing was ever cleared
     */
    private static function mergeClearedBillingDateReport($clearedIds)
    {
        $report = (array)fluent_cart_get_option('_fluent_cart_completed_billing_date_report', [], false);

        if (!$clearedIds && !$report) {
            return null;
        }

        $report = [
            'repaired_at' => gmdate('Y-m-d H:i:s'),
            'cleared_ids' => array_values(array_unique(array_merge(
                (array)Arr::get($report, 'cleared_ids', []),
                $clearedIds
            ))),
        ];
        $report['cleared_count'] = count($report['cleared_ids']);

        fluent_cart_update_option('_fluent_cart_completed_billing_date_report', $report);

        return $report;
    }

    /**
     * Accumulate results into the report option across partial runs — rows are
     * keyed by subscription id, so a replayed chunk can't duplicate entries.
     *
     * @return array|null merged report, or null when nothing was ever repaired
     */
    private static function mergeRepairReport($repairedRows, $offsetIds, $underCollectedIds, $anomalousIds)
    {
        $report = (array)fluent_cart_get_option('_fluent_cart_installment_repair_report', [], false);

        if (!$repairedRows && !$anomalousIds && !$report) {
            return null;
        }

        $report = [
            'repaired_at'         => gmdate('Y-m-d H:i:s'),
            'rows'                => $repairedRows + (array)Arr::get($report, 'rows', []),
            // id-keyed + deduped, not a running sum — a re-scanned/replayed chunk
            // (concurrent runs, advisory lock fail-open) can't double-count a subscription
            'offset_ids'          => array_values(array_unique(array_merge(
                (array)Arr::get($report, 'offset_ids', []),
                $offsetIds
            ))),
            'under_collected_ids' => array_values(array_unique(array_merge(
                (array)Arr::get($report, 'under_collected_ids', []),
                $underCollectedIds
            ))),
            'anomalous_ids'       => array_values(array_unique(array_merge(
                (array)Arr::get($report, 'anomalous_ids', []),
                $anomalousIds
            ))),
        ];
        $report['restored_count'] = count($report['rows']);
        $report['offset_count'] = count($report['offset_ids']);

        fluent_cart_update_option('_fluent_cart_installment_repair_report', $report);

        return $report;
    }

    /**
     * Advisory lock — own name, so backfills never contend with schema migrations.
     */
    private static function acquireBackfillLock()
    {
        global $wpdb;

        if (Schema::isSqlite()) {
            // GET_LOCK is MySQL-only; SQLite has no concurrent writers.
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $acquired = $wpdb->get_var($wpdb->prepare(
            "SELECT GET_LOCK(%s, 0)",
            self::getBackfillLockName()
        ));

        // NULL means the server could not create the lock — fail open so a
        // locking hiccup can never block backfills entirely.
        return $acquired === null || (string)$acquired === '1';
    }

    private static function releaseBackfillLock()
    {
        global $wpdb;

        if (Schema::isSqlite()) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "SELECT RELEASE_LOCK(%s)",
            self::getBackfillLockName()
        ));
    }

    private static function getBackfillLockName()
    {
        global $wpdb;

        // GET_LOCK names are server-wide; scope to this site's DB and prefix
        // so two WordPress installs on one MySQL server can't block each other.
        $dbName = defined('DB_NAME') ? DB_NAME : '';

        return 'fct_db_backfill_' . md5($dbName . '|' . $wpdb->prefix);
    }
}
