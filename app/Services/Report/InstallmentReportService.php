<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\Framework\Support\Arr;

/**
 * Installment (fixed-term, bill_times > 0) plan health, as of one day, over all time.
 *
 * A plan's orders are its parent order plus the renewals whose parent_id
 * points at it. Money is in cents of the report currency.
 */
class InstallmentReportService extends ReportService
{
    // A paid plan still waiting for the gateway to activate it will bill, so it runs
    public const RUNNING = [
        Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING,
        Status::SUBSCRIPTION_PENDING, Status::SUBSCRIPTION_CREATED, Status::SUBSCRIPTION_AUTHENTICATED,
    ];

    // past_due is the old name of expiring; rows written before the rename still carry it
    public const OVERDUE = [Status::SUBSCRIPTION_EXPIRING, Status::SUBSCRIPTION_PAST_DUE, Status::SUBSCRIPTION_FAILING];

    // paused: can resume; upgraded: replaced by a new plan (Pro sets config.upgraded_to_sub_id).
    // Neither is lapsed or at risk, and both stay outside the completion rate.
    public const BUCKETS = ['completed', 'running', 'overdue', 'paused', 'upgraded', 'lapsed', 'refunded'];

    public const MAX_AT_RISK = 500;

    /**
     * Summary, charts and picker options. The tables load from getByVariation() and getAtRisk().
     *
     * @param array $params currency, variationIds, asOf (Y-m-d), startedFrom / startedTo (Y-m-d, plan start),
     *                      atRiskLimit (0, the default, returns no rows), withVariations (adds by_variation)
     */
    public function getReport(array $params): array
    {
        $params['currency'] = ReportCurrency::resolve(Arr::get($params, 'currency'), true);
        $asOf = ReportHelper::asOfDate(Arr::get($params, 'asOf'));
        // The plan table is the costly part: build it once and aggregate in PHP
        $plans = $this->plans($params);
        $summary = $this->summary($plans);
        $unfiltered = empty($params['variationIds']) && !Arr::get($params, 'startedFrom') && !Arr::get($params, 'startedTo');
        $optionPlans = $unfiltered ? $plans : $this->plans(array_merge($params, ['variationIds' => [], 'startedFrom' => null, 'startedTo' => null]));

        // 0 skips the list; the admin table pages through getAtRisk() instead
        $limit = min(max((int) Arr::get($params, 'atRiskLimit', 0), 0), self::MAX_AT_RISK);

        $report = [
            'as_of'        => $asOf->format('Y-m-d'),
            'currency'     => $params['currency'],
            // The applied "started between" range; null ends mean open
            'started_from' => $this->dayBound(Arr::get($params, 'startedFrom'), false),
            'started_to'   => $this->dayBound(Arr::get($params, 'startedTo'), true),
            'summary'      => $summary,
            'schedule'     => $this->schedule($plans, $asOf),
            'due_soon'     => $this->dueSoon($plans, $asOf),
            'starts'       => $this->startsByMonth($plans),
            'at_risk'      => $limit ? $this->atRisk($params, $limit) : [],
            'customers_hidden' => !Arr::get($params, 'showCustomers', true),
            // Unfiltered, so the product picker keeps every choice while one is selected
            'filter_options' => $this->variationOptions($optionPlans),
            'at_risk_count'  => $summary['overdue_count'] + $summary['lapsed_count'],
            'at_risk_amount' => $summary['overdue'] + $summary['lapsed_unpaid'],
        ];

        if (!empty($params['withVariations'])) {
            $report['by_variation'] = $this->byVariation($plans);
        }

        return $report;
    }

    /**
     * The Plans by Variation table.
     *
     * @param array $params currency, variationIds
     */
    public function getByVariation(array $params): array
    {
        $params['currency'] = ReportCurrency::resolve(Arr::get($params, 'currency'), true);

        return ['data' => $this->byVariation($this->plans($params))];
    }

    /**
     * Every started plan with its bucket, in one query.
     */
    private function plans(array $params)
    {
        return App::db()->query()
            ->fromSub($this->bucketedQuery($params), 'b')
            ->select(['b.bucket', 'b.payments', 'b.collected', 'b.left_amount', 'b.started_at', 'b.plan_variation_id',
                'b.recurring_total', 'b.bill_times', 'b.bill_count', 'b.next_billing_date', 'b.billing_interval', 'b.billing_schedule',
                'b.installments_left'])
            ->get();
    }

    /**
     * Totals and bucket counts shared by the summary, each variation row and each start month.
     */
    private function aggregate($plans): array
    {
        $row = array_merge(
            ['plans_started' => 0, 'payments' => 0, 'collected' => 0, 'scheduled' => 0, 'overdue_amount' => 0, 'lapsed_amount' => 0],
            array_fill_keys(array_map(function ($bucket) {
                return $bucket . '_count';
            }, self::BUCKETS), 0)
        );
        $owed = ['running' => 'scheduled', 'overdue' => 'overdue_amount', 'lapsed' => 'lapsed_amount'];

        foreach ($plans as $plan) {
            $row['plans_started']++;
            $row['payments'] += (int) $plan->payments;
            $row['collected'] += (int) $plan->collected;
            $row[$plan->bucket . '_count']++;
            if (isset($owed[$plan->bucket])) {
                $row[$owed[$plan->bucket]] += (int) $plan->left_amount;
            }
        }

        return $row;
    }

    private function summary($plans): array
    {
        $row = $this->aggregate($plans);

        $summary = [
            'plans_started'   => $row['plans_started'],
            'payments'        => $row['payments'],
            'collected'       => $row['collected'],
            'scheduled'       => $row['scheduled'],
            'overdue'         => $row['overdue_amount'],
            'lapsed_unpaid'   => $row['lapsed_amount'],
            'completion_rate' => 0,
            'collection_rate' => null,
        ];
        foreach (self::BUCKETS as $bucket) {
            $summary[$bucket . '_count'] = $row[$bucket . '_count'];
        }

        // Of the payments after the first (every scheduled payment is one), the share ended plans paid; completed and
        // lapsed only, as a refunded plan gave its money back. The default rate for the projected schedule
        $due = 0;
        $paid = 0;
        foreach ($plans as $plan) {
            if (in_array($plan->bucket, ['completed', 'lapsed'], true) && (int) $plan->bill_times > 1) {
                $due += (int) $plan->bill_times - 1;
                // bill_count: one renewal can collect several missed cycles
                $paid += $plan->bucket === 'completed' ? (int) $plan->bill_times - 1 : max(0, min((int) $plan->bill_count, (int) $plan->bill_times) - 1);
            }
        }
        $summary['collection_rate'] = $due ? round($paid / $due * 100, 1) : null;

        // Only plans that have ended: running and overdue plans can still complete
        $ended = $summary['completed_count'] + $summary['lapsed_count'] + $summary['refunded_count'];
        if ($ended) {
            $summary['completion_rate'] = round($summary['completed_count'] / $ended * 100, 2);
        }

        return $summary;
    }

    /**
     * One row per started plan: at least one order paid (or partly refunded) with money taken.
     */
    private function planQuery(array $params)
    {
        $paid = "'" . Status::PAYMENT_PAID . "', '" . Status::PAYMENT_PARTIALLY_REFUNDED . "'";

        // Renewals carry parent_id = the plan's parent order; the parent order itself has none
        $query = ReportCurrency::joinSubscriptions(
            ReportCurrency::ordersTable($params), $params, 's', 'COALESCE(NULLIF(o.parent_id, 0), o.id)'
        )->leftJoinSub($this->parentLinesQuery($params), 'pl', 'pl.order_id', '=', 'o.id');

        // A parent order can also hold ordinary items (admin-created orders). Credit the plan with its own
        // lines only: payment shared by line amount, refunds as recorded on its lines when any line records
        // one. Renewals and plan-only parent orders count in full, as before.
        // Other lines present: share even when the plan's own lines are worth 0 (free or trial plan line)
        $mixed = 'pl.order_id IS NOT NULL AND pl.all_sum > pl.plan_sum';
        $paidPart = "(CASE WHEN {$mixed} THEN ROUND(o.total_paid * pl.plan_sum / pl.all_sum) ELSE o.total_paid END)";
        $refundPart = "(CASE WHEN {$mixed} THEN (CASE WHEN pl.any_refund > 0 THEN LEAST(pl.plan_refund, o.total_refund)
            ELSE ROUND(o.total_refund * pl.plan_sum / pl.all_sum) END) ELSE o.total_refund END)";

        $columns = ['uuid', 'customer_id', 'product_id', 'variation_id', 'parent_order_id', 'status', 'bill_times', 'bill_count',
            'recurring_total', 'next_billing_date', 'billing_interval'];
        // MAX(): the ALL mode groups a derived table, where MySQL cannot see these depend on s.id
        $select = ['s.id AS id'];
        foreach ($columns as $column) {
            $select[] = "MAX(s.{$column}) AS {$column}";
        }

        $query->selectRaw(implode(', ', $select) . ",
                SUM({$paidPart} - {$refundPart}) AS collected,
                SUM(CASE WHEN o.payment_status IN ({$paid}) AND {$paidPart} > 0 THEN 1 ELSE 0 END) AS payments,
                MIN(CASE WHEN o.payment_status IN ({$paid}) AND {$paidPart} > 0 THEN o.created_at END) AS started_at,
                SUM(CASE WHEN {$refundPart} > 0 THEN 1 ELSE 0 END) AS refunded_orders,
                MAX(CASE WHEN JSON_EXTRACT(s.config, '$.upgraded_to_sub_id') IS NOT NULL THEN 1 ELSE 0 END) AS upgraded,
                MAX(JSON_EXTRACT(s.config, '$.billing_schedule')) AS billing_schedule
            ")
            ->where('o.total_paid', '>', 0)
            ->where('s.bill_times', '>', 0)
            ->where('s.status', '!=', Status::SUBSCRIPTION_INTENDED)
            ->groupBy('s.id')
            ->havingRaw('payments > 0');

        // "Started between": the plan's first paid order, inclusive of both store days
        $from = $this->gmtBound(Arr::get($params, 'startedFrom'), false);
        $to = $this->gmtBound(Arr::get($params, 'startedTo'), true);
        if ($from) {
            $query->havingRaw('started_at >= ?', [$from]);
        }
        if ($to) {
            $query->havingRaw('started_at <= ?', [$to]);
        }

        ReportCurrency::whereCurrencyColumn($query, 's.currency', $params['currency']);

        return $query;
    }

    /**
     * Per parent order of an installment plan: plan line (subscription, signup fee) and all-line sums.
     */
    private function parentLinesQuery(array $params)
    {
        $items = ReportCurrency::isConverted($params)
            ? App::db()->query()->fromSub(ReportCurrency::convertedOrderItems(), 'i')
            : App::db()->table('fct_order_items as i');
        $planLine = "i.payment_type IN ('subscription', 'signup_fee')";
        // A trial plan line charges nothing on its first order (OrderItem::chargedTotal()); a parent order is one
        $trialDays = "CAST(JSON_UNQUOTE(JSON_EXTRACT(i.other_info, '$.trial_days')) AS SIGNED)";
        $weight = "(CASE WHEN i.payment_type = 'subscription' AND {$trialDays} > 0 THEN 0 ELSE i.line_total END)";

        return $items
            ->selectRaw("i.order_id,
                SUM(CASE WHEN {$planLine} THEN {$weight} ELSE 0 END) AS plan_sum,
                SUM({$weight}) AS all_sum,
                SUM(CASE WHEN {$planLine} THEN i.refund_total ELSE 0 END) AS plan_refund,
                SUM(i.refund_total) AS any_refund")
            ->whereIn('i.order_id', function ($q) {
                $q->select('parent_order_id')->from('fct_subscriptions')->where('bill_times', '>', 0);
            })
            ->groupBy('i.order_id');
    }

    /**
     * Plans with their bucket and what is still owed.
     */
    private function bucketedQuery(array $params)
    {
        $left = 'GREATEST(0, CAST(p.bill_times AS SIGNED) - CAST(p.bill_count AS SIGNED))';
        $running = $this->sqlList(self::RUNNING);
        $overdue = $this->sqlList(self::OVERDUE);
        $completed = Status::SUBSCRIPTION_COMPLETED;

        // The price the plan was sold at: its parent order's subscription line. An upgrade or
        // switch later rewrites s.variation_id, which would file the plan under the new price.
        $soldLines = App::db()->table('fct_order_items')
            ->selectRaw('order_id, MIN(object_id) AS variation_id, COUNT(*) AS line_count')
            ->where('payment_type', 'subscription')
            ->whereIn('order_id', function ($q) {
                $q->select('parent_order_id')->from('fct_subscriptions')->where('bill_times', '>', 0);
            })
            ->groupBy('order_id');
        // Two subscription lines on one order cannot be told apart; keep the plan's own variation
        $planVariation = 'CASE WHEN sl.line_count = 1 THEN sl.variation_id ELSE p.variation_id END';

        return App::db()->query()
            ->fromSub($this->planQuery($params), 'p')
            ->leftJoinSub($soldLines, 'sl', 'sl.order_id', '=', 'p.parent_order_id')
            ->when(!empty($params['variationIds']), fn($q) => $q->whereIn(App::db()->raw($planVariation), $params['variationIds']))
            ->select('p.*')
            ->selectRaw("{$planVariation} AS plan_variation_id")
            ->selectRaw("{$left} AS installments_left")
            ->selectRaw("{$left} * CAST(p.recurring_total AS SIGNED) AS left_amount")
            ->selectRaw("(CASE
                WHEN p.status = '{$completed}' OR {$left} = 0 THEN 'completed'
                WHEN p.status IN ({$running}) THEN 'running'
                WHEN p.status IN ({$overdue}) THEN 'overdue'
                WHEN p.upgraded = 1 THEN 'upgraded'
                WHEN p.status = '" . Status::SUBSCRIPTION_PAUSED . "' THEN 'paused'
                WHEN p.refunded_orders > 0 THEN 'refunded'
                ELSE 'lapsed'
            END) AS bucket");
    }

    private function byVariation($plans): array
    {
        $groups = [];
        foreach ($plans as $plan) {
            $groups[(int) $plan->plan_variation_id][] = $plan;
        }

        if (!$groups) {
            return [];
        }

        $catalog = App::db()->table('fct_product_variations as pv')
            ->leftJoin('posts as p', 'p.ID', '=', 'pv.post_id')
            ->whereIn('pv.id', array_keys($groups))
            ->selectRaw("pv.id, pv.post_id, p.post_title, pv.variation_title, pv.payment_type,
                JSON_UNQUOTE(JSON_EXTRACT(pv.other_info, '$.times')) AS times")
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ($groups as $variationId => $group) {
            $row = $this->aggregate($group);
            $prices = array_map(function ($plan) {
                return (int) $plan->recurring_total;
            }, $group);
            $billTimes = array_map(function ($plan) {
                return (int) $plan->bill_times;
            }, $group);
            $variation = $catalog->get($variationId);
            $item = [
                'variation_id'    => $variationId,
                'product_id'      => $variation ? (int) $variation->post_id : 0,
                'product_title'   => $variation ? (string) $variation->post_title : '',
                'variation_title' => $variation ? (string) $variation->variation_title : '',
                // Terms the plans were actually sold on; one price can carry several over time
                'min_price'       => min($prices),
                'max_price'       => max($prices),
                'min_bill_times'  => min($billTimes),
                'max_bill_times'  => max($billTimes),
                // false: an installment plan sold on a price not set up as one (a custom plan)
                'is_installment_price' => $variation && $variation->payment_type === 'subscription' && (int) $variation->times > 0,
                'plans_started'   => $row['plans_started'],
                'payments'        => $row['payments'],
                'collected'       => $row['collected'],
                'scheduled'       => $row['scheduled'],
                'overdue_amount'  => $row['overdue_amount'],
                'lapsed_amount'   => $row['lapsed_amount'],
            ];
            foreach (self::BUCKETS as $bucket) {
                $item[$bucket . '_count'] = $row[$bucket . '_count'];
            }
            $item['total'] = $item['collected'] + $item['scheduled'];
            $result[] = $item;
        }

        usort($result, function ($a, $b) {
            return $b['total'] <=> $a['total'] ?: $a['variation_id'] <=> $b['variation_id'];
        });

        return $result;
    }

    /**
     * Plans by the store month of their first paid order, split by how they stand today.
     */
    private function startsByMonth($plans): array
    {
        $groups = [];
        foreach ($plans as $plan) {
            $groups[ReportHelper::local(new \DateTime((string) $plan->started_at, new \DateTimeZone('UTC')))->format('Y-m')][] = $plan;
        }
        ksort($groups);

        $empty = array_merge(['plans_started' => 0, 'collected' => 0], array_fill_keys(array_map(function ($bucket) {
            return $bucket . '_count';
        }, self::BUCKETS), 0));

        $months = [];
        foreach ($groups as $month => $group) {
            $months[$month] = array_merge(['month' => (string) $month], array_intersect_key($this->aggregate($group), $empty));
        }

        if (!$months) {
            return [];
        }

        // One bar per month, quiet months included
        $cursor = new \DateTimeImmutable(array_key_first($months) . '-01', new \DateTimeZone('UTC'));
        $last = array_key_last($months);
        $result = [];
        while ($cursor->format('Y-m') <= $last) {
            $key = $cursor->format('Y-m');
            $result[] = isset($months[$key]) ? $months[$key] : array_merge(['month' => $key], $empty);
            $cursor = $cursor->modify('first day of next month');
        }

        return $result;
    }

    /**
     * Products and their variations that have started installment plans, for the product picker.
     */
    private function variationOptions($plans): array
    {
        return ReportHelper::variationOptions(array_map(function ($plan) {
            return (int) $plan->plan_variation_id;
        }, $plans->all()));
    }

    /**
     * Month by month until the last installment of running and overdue plans.
     * A payment due before the as-of day is shown in the as-of month.
     */
    private function schedule($plans, $asOf): array
    {
        $plans = $plans->filter(function ($plan) {
            return in_array($plan->bucket, ['running', 'overdue'], true);
        });

        $firstMonth = $asOf->format('Y-m');
        $months = [];

        foreach ($plans as $plan) {
            $first = $plan->next_billing_date ?: ReportHelper::toGmt($asOf)->format('Y-m-d H:i:s');
            $schedule = $plan->billing_schedule
                ? ReportHelper::billingScheduleFromConfig('{"billing_schedule":' . $plan->billing_schedule . '}')
                : null;
            $dates = ReportHelper::billingDates($first, (string) $plan->billing_interval, (int) $plan->installments_left, null, $schedule);

            // Unknown interval: the remaining payments still count, in the first month
            $missing = (int) $plan->installments_left - count($dates);

            foreach ($dates as $index => $date) {
                // The store's month, as every report buckets
                $month = max($date->setTimezone(wp_timezone())->format('Y-m'), $firstMonth);
                $times = $index === 0 ? 1 + $missing : 1;
                $this->addToMonth($months, $month, $plan, $times);
            }
        }

        ksort($months);

        if ($months) {
            // Fill gaps so the chart has every month up to the last installment
            $cursor = new \DateTimeImmutable($firstMonth . '-01', new \DateTimeZone('UTC'));
            $last = array_key_last($months);
            while ($cursor->format('Y-m') <= $last) {
                $key = $cursor->format('Y-m');
                if (!isset($months[$key])) {
                    $months[$key] = ['month' => $key, 'payments' => 0, 'amount' => 0, 'scheduled' => 0, 'overdue' => 0];
                }
                $cursor = $cursor->modify('first day of next month');
            }
            ksort($months);
        }

        return array_values($months);
    }

    /**
     * Installment payments of running and overdue plans due by 30 days from the as-of day, with those already past due
     */
    private function dueSoon($plans, $asOf): array
    {
        // 30 store days from the as-of midnight; a payment already past due counts, as in the schedule
        $until = (new \DateTimeImmutable('@' . $asOf->getTimestamp()))->setTimezone(wp_timezone())->modify('+30 days');
        $first = ReportHelper::toGmt($asOf)->format('Y-m-d H:i:s');
        $from = new \DateTimeImmutable('@' . $asOf->getTimestamp());
        $result = ['count' => 0, 'amount' => 0, 'overdue' => ['count' => 0, 'amount' => 0]];

        foreach ($plans as $plan) {
            if (!in_array($plan->bucket, ['running', 'overdue'], true)) {
                continue;
            }
            $schedule = $plan->billing_schedule
                ? ReportHelper::billingScheduleFromConfig('{"billing_schedule":' . $plan->billing_schedule . '}')
                : null;
            foreach (ReportHelper::billingDates($plan->next_billing_date ?: $first, (string) $plan->billing_interval, (int) $plan->installments_left, $until, $schedule) as $date) {
                if ($date < $until) {
                    $result['count']++;
                    $result['amount'] += (int) $plan->recurring_total;
                    if ($date < $from) {
                        $result['overdue']['count']++;
                        $result['overdue']['amount'] += (int) $plan->recurring_total;
                    }
                }
            }
        }

        return $result;
    }

    private function addToMonth(array &$months, string $month, $plan, int $times)
    {
        if (!isset($months[$month])) {
            $months[$month] = ['month' => $month, 'payments' => 0, 'amount' => 0, 'scheduled' => 0, 'overdue' => 0];
        }

        $amount = $times * (int) $plan->recurring_total;
        $months[$month]['payments'] += $times;
        $months[$month]['amount'] += $amount;
        $months[$month][$plan->bucket === 'running' ? 'scheduled' : 'overdue'] += $amount;
    }

    /**
     * Overdue and lapsed plans, largest balance first.
     */
    /**
     * One page of overdue and lapsed plans for the admin table.
     *
     * @param array $params currency, variationIds, search (name or email), bucket (overdue|lapsed), page, perPage
     */
    public function getAtRisk(array $params): array
    {
        $params['currency'] = ReportCurrency::resolve(Arr::get($params, 'currency'), true);
        $perPage = min(max((int) Arr::get($params, 'perPage', 20), 1), 150); // the shared pager offers up to 150
        $page = max((int) Arr::get($params, 'page', 1), 1);

        $total = (int) $this->atRiskQuery($params)->count();

        return [
            'data'     => $this->atRiskRows($this->atRiskQuery($params), $perPage, ($page - 1) * $perPage, (bool) Arr::get($params, 'showCustomers', true)),
            'customers_hidden' => !Arr::get($params, 'showCustomers', true),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    private function atRiskQuery(array $params)
    {
        $bucket = Arr::get($params, 'bucket');
        $buckets = in_array($bucket, ['overdue', 'lapsed'], true) ? [$bucket] : ['overdue', 'lapsed'];
        // Without customer access the search would reveal who the customers are
        $search = Arr::get($params, 'showCustomers', true) ? trim(sanitize_text_field((string) Arr::get($params, 'search', ''))) : '';

        return App::db()->query()
            ->fromSub($this->bucketedQuery($params), 'b')
            ->leftJoin('fct_customers as c', 'c.id', '=', 'b.customer_id')
            ->whereIn('b.bucket', $buckets)
            ->when($search !== '', function ($q) use ($search) {
                global $wpdb;
                $like = '%' . $wpdb->esc_like($search) . '%';
                $q->where(function ($q) use ($like) {
                    $q->where('c.email', 'LIKE', $like)
                        ->orWhereRaw("CONCAT(c.first_name, ' ', c.last_name) LIKE ?", [$like]);
                });
            });
    }

    private function atRisk(array $params, int $limit): array
    {
        return $this->atRiskRows($this->atRiskQuery($params), $limit, 0, (bool) Arr::get($params, 'showCustomers', true));
    }

    private function atRiskRows($query, int $limit, int $offset = 0, bool $showCustomers = true): array
    {
        $rows = $query
            ->leftJoin('fct_product_variations as pv', 'pv.id', '=', 'b.plan_variation_id')
            ->leftJoin('posts as p', 'p.ID', '=', 'pv.post_id')
            ->select([
                'b.id', 'b.uuid', 'b.status', 'b.bucket', 'b.customer_id', 'b.plan_variation_id', 'b.payments',
                'b.bill_times', 'b.bill_count', 'b.installments_left', 'b.left_amount', 'b.collected',
                'b.next_billing_date', 'c.first_name', 'c.last_name', 'c.email', 'p.post_title', 'pv.variation_title',
            ])
            ->orderBy('b.left_amount', 'desc')
            ->orderBy('b.id', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get();

        // Pro decides who may reactivate; the model owns that rule
        $urls = [];
        $subscriptions = Subscription::query()->whereIn('id', $rows->pluck('id')->all())->get();
        foreach ($subscriptions as $subscription) {
            $urls[$subscription->id] = $subscription->getReactivateUrl();
        }

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'subscription_id'   => (int) $row->id,
                'customer_id'       => (int) $row->customer_id,
                'customer_name'     => $showCustomers ? trim($row->first_name . ' ' . $row->last_name) : '',
                'email'             => $showCustomers ? (string) $row->email : '',
                'variation_id'      => (int) $row->plan_variation_id,
                'product_title'     => (string) $row->post_title,
                'variation_title'   => (string) $row->variation_title,
                'status'            => (string) $row->status,
                'bucket'            => (string) $row->bucket,
                'payments'          => (int) $row->payments,
                'bill_times'        => (int) $row->bill_times,
                // Installments paid; one payoff order can cover several, so this is not the order count
                'bill_count'        => (int) $row->bill_count,
                'installments_left' => (int) $row->installments_left,
                'collected'         => (int) $row->collected,
                'left_amount'       => (int) $row->left_amount,
                'next_billing_date' => $row->next_billing_date,
                'reactivate_url'    => Arr::get($urls, $row->id, ''),
            ];
        }

        return $result;
    }

    /**
     * 'Y-m-d' to the first or last second of that day, or null when empty or invalid.
     */
    private function dayBound($value, bool $endOfDay)
    {
        $value = ReportHelper::validDay($value);

        return $value ? $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00') : null;
    }

    /**
     * A store day's bound as UTC, for comparing with GMT order dates.
     */
    private function gmtBound($value, bool $endOfDay)
    {
        $bound = $this->dayBound($value, $endOfDay);

        return $bound ? ReportHelper::toGmt(ReportHelper::siteDay($bound))->format('Y-m-d H:i:s') : null;
    }

    private function sqlList(array $values): string
    {
        return "'" . implode("', '", array_map('esc_sql', $values)) . "'";
    }
}
