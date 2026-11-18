<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
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

    /**
     * When a subscription left: never while its status is live. expire_at is not kept up to date on live
     * subscriptions (stores migrated from EDD keep the first expiration), so it counts only after they end.
     * A failing or past-due subscription left when the unpaid renewal fell due; a canceled one with no
     * cancel date, when it was last updated.
     */
    private static function leftAtSql(string $prefix = ''): string
    {
        $p = $prefix;
        $canceledAt = "(CASE WHEN {$p}canceled_at IS NULL THEN LEAST({$p}updated_at, COALESCE({$p}expire_at, {$p}updated_at))
            ELSE LEAST(COALESCE({$p}canceled_at, {$p}expire_at), COALESCE({$p}expire_at, {$p}canceled_at)) END)";

        // A cancel takes effect at once in FluentCart, but the customer keeps what they paid for: like Stripe Billing,
        // the subscription leaves when its paid term ends (needs `lr`, lastPaidRenewalQuery, joined); an end still
        // ahead keeps it live until then
        return "(CASE
            WHEN {$p}status IN ('" . Status::SUBSCRIPTION_ACTIVE . "', '" . Status::SUBSCRIPTION_EXPIRING . "', '" . Status::SUBSCRIPTION_TRIALING . "') THEN NULL
            WHEN {$p}status IN ('" . Status::SUBSCRIPTION_FAILING . "', '" . Status::SUBSCRIPTION_PAST_DUE . "') THEN COALESCE({$p}next_billing_date, {$p}updated_at)
            WHEN {$p}status = '" . Status::SUBSCRIPTION_PAUSED . "' THEN {$p}updated_at
            WHEN " . self::keepsAccessSql($p) . " THEN GREATEST({$canceledAt}, " . self::termEndSql($p) . ")
            WHEN {$p}status = '" . Status::SUBSCRIPTION_CANCELED . "' THEN {$canceledAt}
            ELSE LEAST(COALESCE({$p}canceled_at, {$p}expire_at), COALESCE({$p}expire_at, {$p}canceled_at))
        END)";
    }

    /**
     * A canceled subscription that keeps its paid term: not refunded, not fraud, not replaced by an upgrade, and not a
     * free trial that never paid (needs `lr` joined)
     */
    private static function keepsAccessSql(string $p = ''): string
    {
        return "({$p}status = '" . Status::SUBSCRIPTION_CANCELED . "'
            AND NOT ({$p}trial_days > 0 AND lr.paid_at IS NULL AND NOT (" . self::simulatedTrialSql($p) . "))
            AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$p}config, '$.cancellation_reason')), '') NOT IN ('refunded', 'fraudulent', 'upgraded_to_new_plan')
            AND NOT " . self::upgradedAwaySql($p) . ')';
    }

    /**
     * Billing cycles a year: an amount per cycle times this is twelve times its monthly amount.
     */
    public static function cyclesPerYearSql(string $prefix = ''): string
    {
        return "(CASE {$prefix}billing_interval
            WHEN 'monthly' THEN 12 WHEN 'quarterly' THEN 4 WHEN 'half_yearly' THEN 2 WHEN 'yearly' THEN 1
            WHEN 'weekly' THEN 52 WHEN 'daily' THEN 365 ELSE 0
        END)";
    }

    /**
     * Twelve times the monthly MRR amount in cents: the renewal price, except while trialing. A simulated
     * trial (a paid, discounted first cycle) counts at the price paid now; a real free trial counts 0.
     *
     * @param string $paidNow alias of the paidNowQuery() join
     */
    private static function mrrTimes12Sql(string $prefix = '', string $paidNow = 'pn'): string
    {
        // A plan reached by an upgrade counts at its full price: its prorated first cycle is the upgrade credit, a one-off.
        // A canceled discounted first cycle (paid_now joined) stays at the price paid until it ends
        return "(CASE WHEN {$prefix}status IN ('" . Status::SUBSCRIPTION_TRIALING . "', '" . Status::SUBSCRIPTION_CANCELED . "') AND " . self::movedToSql($prefix) . " THEN {$prefix}recurring_total
            WHEN {$prefix}status = '" . Status::SUBSCRIPTION_TRIALING . "' THEN COALESCE({$paidNow}.paid_now, 0)
            WHEN {$prefix}status = '" . Status::SUBSCRIPTION_CANCELED . "' AND {$paidNow}.paid_now IS NOT NULL THEN {$paidNow}.paid_now
            ELSE {$prefix}recurring_total END) * " . self::cyclesPerYearSql($prefix);
    }

    /** A subscription an upgrade moved to (Pro stores the plan it replaced) */
    private static function movedToSql(string $prefix = ''): string
    {
        return "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$prefix}config, '$.upgraded_from_sub_id')), '0') AS UNSIGNED) > 0";
    }

    protected static function simulatedTrialSql(string $prefix = ''): string
    {
        return "COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$prefix}config, '$.is_trial_days_simulated')), '') = 'yes'";
    }

    /**
     * Per simulated-trial subscription: its share of what its parent order paid, by line amount.
     * One row per subscription id; join it on the subscription's id as `pn`.
     */
    private function paidNowQuery(array $params, bool $withCanceledFirstCycles = false, bool $renewalsJoined = false)
    {
        // A discounted first cycle: trialing, and for the live figures also canceled before any renewal was paid (it
        // keeps that paid cycle)
        $statuses = $withCanceledFirstCycles ? [Status::SUBSCRIPTION_TRIALING, Status::SUBSCRIPTION_CANCELED] : [Status::SUBSCRIPTION_TRIALING];
        $planLine = "i.object_id = ps.variation_id";
        // Converted as ReportCurrency::convertedOrderItems() does: each line at its order's rate, rounded
        $lineTotal = ReportCurrency::isConverted($params) ? 'ROUND(i.line_total / po.rate)' : 'i.line_total';

        // One pass: each subscription's parent order lines by the order_id index, not two grouped reads of every
        // parent order
        return ReportCurrency::subscriptionsTable($params, 'ps')
            ->joinSub(ReportCurrency::ordersTable($params), 'po', 'po.id', '=', 'ps.parent_order_id')
            ->leftJoin('fct_order_items as i', 'i.order_id', '=', 'ps.parent_order_id')
            // Paid renewals of the canceled discounted cycles only: a narrow read, this runs in several queries.
            // joinLivePaidNow() reads them from its `lr` instead
            ->when($withCanceledFirstCycles && !$renewalsJoined, fn($q) => $q->leftJoinSub(App::db()->table('fct_orders')
                ->where('type', Status::ORDER_TYPE_RENEWAL)
                ->whereIn('payment_status', Status::getReportStatuses())
                ->whereIn('parent_id', function ($q) {
                    $q->select('parent_order_id')->from('fct_subscriptions')
                        ->where('status', Status::SUBSCRIPTION_CANCELED)
                        ->whereRaw(self::simulatedTrialSql());
                })
                ->selectRaw('parent_id, MAX(created_at) AS paid_at')
                ->groupBy('parent_id'), 'pr', 'pr.parent_id', '=', 'ps.parent_order_id'))
            ->whereIn('ps.status', $statuses)
            ->whereRaw(self::simulatedTrialSql('ps.'))
            ->when($withCanceledFirstCycles && !$renewalsJoined, fn($q) => $q->where(fn($q) => $q->where('ps.status', Status::SUBSCRIPTION_TRIALING)->orWhereNull('pr.paid_at')))
            ->groupBy('ps.id')
            // No matching line (a changed plan): the whole payment is the plan's
            ->selectRaw("ps.id AS sub_id, MAX(ps.status) AS sub_status, CASE WHEN SUM({$lineTotal}) > 0 AND MAX({$planLine}) = 1
                THEN ROUND(MAX(po.total_paid) * SUM(CASE WHEN {$planLine} AND i.payment_type IN ('subscription', 'signup_fee') THEN {$lineTotal} ELSE 0 END) / SUM({$lineTotal}))
                ELSE MAX(po.total_paid) END AS paid_now");
    }

    /**
     * Joins `lr` (lastPaidRenewalQuery) and the live paid-now prices as `pn`. A canceled discounted first cycle keeps its
     * paid price only while no renewal was paid: `lr` answers that, so the paid-now read skips its own renewal scan.
     */
    private function joinLivePaidNow($query, array $params, string $id, string $parentOrderId)
    {
        return $query
            ->leftJoinSub(self::lastPaidRenewalQuery(), 'lr', 'lr.parent_id', '=', $parentOrderId)
            ->leftJoinSub($this->paidNowQuery($params, true, true), 'pn', function ($join) use ($id) {
                $join->on('pn.sub_id', '=', $id)
                    ->whereRaw("(pn.sub_status = '" . Status::SUBSCRIPTION_TRIALING . "' OR lr.paid_at IS NULL)");
            });
    }

    /**
     * Price paid now for every simulated-trial subscription, in its own currency: [subscription id => cents].
     */
    public function paidNowBySubscription(): array
    {
        return $this->paidNowQuery(['currency' => ''], true)->pluck('paid_now', 'sub_id')->map(function ($cents) {
            return (int) $cents;
        })->all();
    }

    /**
     * Subscription health now, by status: MRR, the discounted first cycles inside it, what they add when they
     * renew, real trials, failing and paused. Open-ended subscriptions only; the date range does not apply.
     *
     * @param array $params currency, variationIds
     */
    public function getHealth(array $params): array
    {
        return $this->healthFromRows($this->healthRows($params, false)->keyBy('kind'));
    }

    /**
     * getHealth() for several variation sets from one read: rows per kind and variation, summed per set
     *
     * @param array<int|string, int[]> $scopes variation IDs per key
     * @return array<int|string, array> getHealth() per key
     */
    public function getHealthByScopes(array $params, array $scopes): array
    {
        $all = array_values(array_unique(array_merge([], ...array_values($scopes))));
        $rows = $this->healthRows(array_merge($params, ['variationIds' => $all ?: [0]]), true);

        $result = [];
        foreach ($scopes as $key => $ids) {
            $ids = array_flip($ids);
            $sums = [];
            foreach ($rows as $row) {
                if (!isset($ids[(int) $row->variation_id])) {
                    continue;
                }
                $sum = $sums[$row->kind] ?? (object) ['subscriptions' => 0, 'renewal_x12' => 0, 'paid_x12' => 0, 'mrr_x12' => 0, 'paying' => 0];
                foreach (['subscriptions', 'renewal_x12', 'paid_x12', 'mrr_x12', 'paying'] as $column) {
                    $sum->{$column} += (int) $row->{$column};
                }
                $sums[$row->kind] = $sum;
            }
            $result[$key] = $this->healthFromRows(new \FluentCart\Framework\Support\Collection($sums));
        }

        return $result;
    }

    /**
     * @param bool $byVariation group by variation too, for getHealthByScopes()
     */
    private function healthRows(array $params, bool $byVariation)
    {
        $kind = "(CASE
            WHEN hs.status = '" . Status::SUBSCRIPTION_CANCELED . "' THEN 'canceling'
            WHEN hs.status IN ('" . Status::SUBSCRIPTION_ACTIVE . "', '" . Status::SUBSCRIPTION_EXPIRING . "') THEN 'active'
            WHEN hs.status = '" . Status::SUBSCRIPTION_TRIALING . "' AND " . self::movedToSql('hs.') . " THEN 'active'
            WHEN hs.status = '" . Status::SUBSCRIPTION_TRIALING . "' AND " . self::simulatedTrialSql('hs.') . " THEN 'discounted'
            WHEN hs.status = '" . Status::SUBSCRIPTION_TRIALING . "' THEN 'trial'
            WHEN hs.status = '" . Status::SUBSCRIPTION_PAUSED . "' THEN 'paused'
            ELSE 'failing'
        END)";
        $cycles = self::cyclesPerYearSql('hs.');

        // Canceled but still in the paid term: live until it ends, shown apart as ending
        $now = DateTime::gmtNow()->format('Y-m-d H:i:s');
        $query = $this->joinLivePaidNow(ReportCurrency::subscriptionsTable($params, 'hs'), $params, 'hs.id', 'hs.parent_order_id')
            ->where('hs.bill_times', '<=', 0)
            ->where(function ($q) use ($now) {
                $q->whereIn('hs.status', [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_EXPIRING, Status::SUBSCRIPTION_TRIALING,
                    Status::SUBSCRIPTION_FAILING, Status::SUBSCRIPTION_PAST_DUE, Status::SUBSCRIPTION_PAUSED])
                    ->orWhereRaw(self::keepsAccessSql('hs.') . ' AND ' . self::termEndSql('hs.') . ' > ?', [$now]);
            })
            ->when(!empty($params['variationIds']), fn($q) => $q->whereIn('hs.variation_id', $params['variationIds']))
            ->selectRaw(($byVariation ? 'hs.variation_id, ' : '') . "{$kind} AS kind, COUNT(*) AS subscriptions,
                SUM(hs.recurring_total * {$cycles}) AS renewal_x12,
                SUM(COALESCE(pn.paid_now, 0) * {$cycles}) AS paid_x12,
                SUM(" . self::mrrTimes12Sql('hs.') . ") AS mrr_x12,
                SUM(" . self::mrrTimes12Sql('hs.') . ' > 0) AS paying')
            ->when($byVariation, fn($q) => $q->groupBy('hs.variation_id', 'kind'), fn($q) => $q->groupBy('kind'));

        return ReportCurrency::whereCurrencyColumn($query, 'hs.currency', ReportCurrency::resolve(Arr::get($params, 'currency'), true))
            ->get();
    }

    /**
     * @param \FluentCart\Framework\Support\Collection $rows health rows keyed by kind
     */
    private function healthFromRows($rows): array
    {

        $get = function ($kind, $column) use ($rows) {
            return $rows->has($kind) ? (int) $rows->get($kind)->{$column} : 0;
        };

        $mrrX12 = $get('active', 'renewal_x12') + $get('discounted', 'paid_x12') + $get('canceling', 'mrr_x12');
        $paying = $get('active', 'paying') + $get('discounted', 'paying') + $get('canceling', 'paying');
        $mrr = self::mrrFromTimes12($mrrX12);

        return [
            'mrr'            => $mrr,
            'subscriptions'  => $get('active', 'subscriptions') + $get('discounted', 'subscriptions') + $get('canceling', 'subscriptions'),
            // Canceled, still paid up: in MRR until the term ends; what leaves unless the customer comes back
            'canceling'      => ['count' => $get('canceling', 'subscriptions'), 'mrr' => self::mrrFromTimes12($get('canceling', 'mrr_x12'))],
            'arpu'           => $paying ? round($mrr / $paying, 2) : 0,
            'active'         => ['count' => $get('active', 'subscriptions'), 'mrr' => self::mrrFromTimes12($get('active', 'renewal_x12'))],
            'discounted'     => [
                'count'       => $get('discounted', 'subscriptions'),
                'mrr'         => self::mrrFromTimes12($get('discounted', 'paid_x12')),
                'renewal_mrr' => self::mrrFromTimes12($get('discounted', 'renewal_x12')),
                // What MRR gains when these first cycles renew at full price; the two shown figures' difference
                'uplift'      => round(self::mrrFromTimes12($get('discounted', 'renewal_x12')) - self::mrrFromTimes12($get('discounted', 'paid_x12')), 2),
            ],
            'trial'          => [
                'count'       => $get('trial', 'subscriptions'),
                'renewal_mrr' => self::mrrFromTimes12($get('trial', 'renewal_x12')),
            ],
            'failing'        => ['count' => $get('failing', 'subscriptions'), 'mrr' => self::mrrFromTimes12($get('failing', 'renewal_x12'))],
            'paused'         => ['count' => $get('paused', 'subscriptions'), 'mrr' => self::mrrFromTimes12($get('paused', 'renewal_x12'))],
        ];
    }

    /**
     * A product's subscriptions for the Products page detail: live count and MRR now (getHealth()), and its renewal
     * rate in the range, renewals paid of those due, the Future Renewals rule. Pass the product's variationIds.
     */
    public function productSubscriptionSummary(array $params, $health = null): array
    {
        // Product Compare passes each item's health from one getHealthByScopes() read
        $health = $health ?: $this->getHealth($params);
        // A term ending later in the range has not been lost yet
        $now = DateTime::gmtNow();
        if (!empty($params['endDate']) && $params['endDate'] > $now) {
            $params['endDate'] = $now;
        }
        $paid = 0;
        $lost = 0;
        foreach ($this->renewalCountsByInterval($params) as $count) {
            $paid += $count['paid'];
            $lost += $count['lost'];
        }

        return [
            'subscriptions' => $health['subscriptions'],
            'mrr'           => $health['mrr'],
            'canceling'     => $health['canceling'],
            'failing'       => $health['failing'],
            'renewals_paid' => $paid,
            'renewals_lost' => $lost,
            'renewal_rate'  => ($paid + $lost) ? round($paid / ($paid + $lost) * 100, 1) : null,
        ];
    }

    /** Days around a paid term's end in which buying the same product again is a rebuy, not a new customer */
    const REBUY_WINDOW_DAYS = 30;

    /** Churn reasons, from the status a subscription left with (the store keeps no reason for an expiry) */
    const CHURN_REASONS = ['canceled', 'payment_failed', 'retrying', 'paused', 'completed'];

    public function getRetentionData($params)
    {
        $params = $this->clampParamsToToday($params);
        $startDate = $params['startDate'];
        $endDate = $params['endDate'];

        if ($startDate > $endDate) {
            return [];
        }

        // Months, or years: with annual plans a year is the period an owner reads retention in
        $yearly = Arr::get($params, 'period') === 'year';
        $interval = sanitize_key((string) Arr::get($params, 'billingInterval', ''));
        $now = DateTime::gmtNow();

        // Bounds compare as UTC (GMT columns); periods are the store's
        $rangeStart = $startDate->format('Y-m-d H:i:s');
        $rangeEnd = $endDate->format('Y-m-d H:i:s');
        $localStart = ReportHelper::local($startDate);
        $localEnd = ReportHelper::local($endDate);
        $keyFormat = $yearly ? 'Y' : 'Y-m';
        $first = $localStart->format($keyFormat);
        $last = $localEnd->format($keyFormat);

        $periods = [];
        $dt = $yearly ? $localStart->copy()->startOfYear() : $localStart->copy()->startOfMonth();
        for (; $dt->format($keyFormat) <= $last; $yearly ? $dt->addYear() : $dt->addMonth()) {
            $periods[] = $dt->copy();
        }
        $count = count($periods);

        $leftAt = self::leftAtSql();
        // Twelve times the monthly amount, in whole cents: grouped sums of a division are rounded per group,
        // so the division by 12 waits for each period's total
        $mrrTimes12 = self::mrrTimes12Sql();
        // Period index of a date inside the range: -1 before it, $count after it. Each period is compared only
        // with its own bounds (the range ends clip the first and last), so one scan grouped by the period a
        // subscription started and the period it left gives every period's figures.
        $index = function ($column) use ($first, $count, $startDate, $endDate, $yearly) {
            $local = ReportHelper::storeTimeSql("({$column})", $startDate, $endDate);
            $offset = $yearly
                ? "YEAR({$local}) - " . (int) $first
                : "PERIOD_DIFF(DATE_FORMAT({$local}, '%Y%m'), " . (int) str_replace('-', '', $first) . ')';

            return "CASE WHEN {$column} < ? THEN -1 WHEN {$column} > ? THEN {$count} ELSE {$offset} END";
        };
        $never = $count + 1;
        $reason = "CASE WHEN status = '" . Status::SUBSCRIPTION_CANCELED . "' THEN 'canceled'
            WHEN status = '" . Status::SUBSCRIPTION_PAUSED . "' THEN 'paused'
            WHEN status = '" . Status::SUBSCRIPTION_COMPLETED . "' THEN 'completed'
            WHEN status IN ('" . Status::SUBSCRIPTION_FAILING . "', '" . Status::SUBSCRIPTION_PAST_DUE . "') THEN 'retrying'
            ELSE 'payment_failed' END";

        $scope = function ($query, $prefix = '') use ($params, $interval) {
            $query->whereNotIn($prefix . 'status', self::RETENTION_EXCLUDED_STATUSES)
                ->where($prefix . 'bill_times', '<=', 0)
                ->when($interval !== '', fn($q) => $q->where($prefix . 'billing_interval', $interval))
                ->when(!empty($params['variationIds']), fn($q) => $q->whereIn($prefix . 'variation_id', $params['variationIds']));

            // MRR and churn are only summable within one currency
            return ReportCurrency::whereCurrencyColumn($query, $prefix . 'currency', ReportCurrency::resolve(Arr::get($params, 'currency'), true));
        };

        // A plan moved to by an upgrade runs from the replaced plan's start; the replaced one is left out
        $created = 'COALESCE(upf.from_created, created_at)';
        // Each row's start, end and MRR once, in a derived table (the LIMIT keeps MySQL from folding the expressions
        // back into the period index, which repeats its column per DST change): the end reads JSON for canceled rows
        // Month-end MRR as the health cards count it: a canceled discounted first cycle at what it paid
        $rows = $scope($this->joinLivePaidNow(ReportCurrency::subscriptionsTable($params), $params, 'id', 'parent_order_id')
            ->leftJoinSub($this->upgradeOriginsQuery($params), 'upf', 'upf.to_id', '=', 'id')
            ->when($this->upgradedAwayIds($params), fn($q) => $q->whereNotIn('id', $this->upgradedAwayIds($params)))
            ->selectRaw("{$created} AS c_at, {$leftAt} AS l_at, status, recurring_amount, {$mrrTimes12} AS x12")
            ->whereRaw("{$created} <= ?", [$rangeEnd])
            ->limit(PHP_INT_MAX));
        $groups = App::db()->query()->fromSub($rows, 'm')
            ->selectRaw($index('m.c_at') . ' AS created_index, '
                . "CASE WHEN m.l_at IS NULL THEN {$never} ELSE " . $index('m.l_at') . " END AS left_index,
                CASE WHEN m.l_at IS NULL THEN '' ELSE {$reason} END AS reason,
                COUNT(*) AS subscriptions,
                SUM(m.recurring_amount > 0) AS paid,
                SUM(m.recurring_amount = 0) AS free,
                SUM(m.x12) AS mrr_x12,
                SUM(m.recurring_amount) AS gross", [$rangeStart, $rangeEnd, $rangeStart, $rangeEnd])
            ->groupBy('created_index', 'left_index', 'reason')
            ->get();

        // Paid renewals of these subscriptions, each payment once (a renewal links by its parent order). Dated when
        // paid: a store-billed renewal invoice is created days before it falls due
        $paidStatuses = Status::getReportStatuses();
        $paidAt = 'COALESCE(ro.completed_at, ro.created_at)';
        $renewed = $scope(ReportCurrency::subscriptionsTable($params, 'rs')
            ->join('fct_orders as ro', 'ro.parent_id', '=', 'rs.parent_order_id')
            ->where('ro.type', Status::ORDER_TYPE_RENEWAL)
            ->whereIn('ro.payment_status', $paidStatuses)
            ->whereRaw("{$paidAt} BETWEEN ? AND ?", [$rangeStart, $rangeEnd])
            ->selectRaw($index($paidAt) . ' AS period_index, COUNT(*) AS subscriptions',
                [$rangeStart, $rangeEnd])
            ->groupBy('period_index'), 'rs.')
            ->get();

        $keys = ['new_subscriptions', 'new_subscriptions_mrr', 'period_gross', 'churned_subscriptions', 'churned_subscriptions_mrr',
            'churned_new_subscriptions', 'active_subscriptions', 'active_paid_subscriptions', 'active_free_subscriptions', 'mrr',
            'start_subscriptions', 'start_mrr'];
        foreach (self::CHURN_REASONS as $churnReason) {
            $keys[] = 'left_' . $churnReason;
        }
        $totals = array_fill(0, $count + 1, array_fill_keys($keys, 0));
        // Periods [$from, $to] get $values; a difference array keeps it one step per group
        $spread = function ($from, $to, array $values) use (&$totals, $count) {
            $from = max(0, $from);
            $to = min($count - 1, $to);
            if ($from > $to) {
                return;
            }
            foreach ($values as $key => $value) {
                $totals[$from][$key] += $value;
                $totals[$to + 1][$key] -= $value;
            }
        };

        foreach ($groups as $group) {
            $created = (int) $group->created_index;
            $left = (int) $group->left_index;
            $subscriptions = (int) $group->subscriptions;
            $paid = (int) $group->paid;
            $free = (int) $group->free;
            $mrr = (int) $group->mrr_x12;

            // Started in the period
            $spread($created, $created, [
                'new_subscriptions'         => $subscriptions,
                'new_subscriptions_mrr'     => $mrr,
                'period_gross'              => (int) $group->gross,
                'churned_new_subscriptions' => $left <= $created ? $subscriptions : 0,
            ]);
            // In the starting base: started before the period, not left before it
            $spread($created + 1, $left, ['start_subscriptions' => $subscriptions, 'start_mrr' => $mrr]);
            // Left in the period from the starting base
            if ($left > $created) {
                $spread($left, $left, ['churned_subscriptions' => $subscriptions, 'churned_subscriptions_mrr' => $mrr]);
            }
            // Every subscription that left in the period, by reason
            if (in_array($group->reason, self::CHURN_REASONS, true)) {
                $spread($left, $left, ['left_' . $group->reason => $subscriptions]);
            }
            // Active at period end: started by then, not left by then
            $spread($created, $left - 1, [
                'active_subscriptions'      => $subscriptions,
                'active_paid_subscriptions' => $paid,
                'active_free_subscriptions' => $free,
                'mrr'                       => $mrr,
            ]);
        }

        $this->addUpgradeHistory($spread, $params, $scope, $mrrTimes12, $count, $first, $yearly);

        // Renewals that fell due and were not paid, counted on the due date (the end of the last paid term), as
        // Recurly and Chargebee count them: an annual plan canceled in June is a lost renewal when its year ends.
        // Failing and past-due ones are still retrying; a real free trial that never paid had no renewal.
        $lastRenewal = self::lastPaidRenewalQuery();
        $termEnd = self::termEndSql('us.');
        $retrying = [Status::SUBSCRIPTION_FAILING, Status::SUBSCRIPTION_PAST_DUE];
        $ended = $scope(ReportCurrency::subscriptionsTable($params, 'us')
            ->whereIn('us.status', [Status::SUBSCRIPTION_CANCELED, Status::SUBSCRIPTION_EXPIRED, Status::SUBSCRIPTION_PAUSED,
                Status::SUBSCRIPTION_FAILING, Status::SUBSCRIPTION_PAST_DUE])
            ->where('us.created_at', '<=', $rangeEnd)
            ->when($this->upgradedAwayIds($params), fn($q) => $q->whereNotIn('us.id', $this->upgradedAwayIds($params)))
            ->leftJoinSub($lastRenewal, 'lr', 'lr.parent_id', '=', 'us.parent_order_id')
            ->whereRaw('NOT (us.trial_days > 0 AND NOT (' . self::simulatedTrialSql('us.') . ') AND lr.paid_at IS NULL)')
            ->selectRaw("{$termEnd} AS term_end, us.status IN ('" . implode("', '", $retrying) . "') AS is_retrying"), 'us.');
        // The term end once per row in a derived table, then bucketed
        $unpaid = App::db()->query()->fromSub($ended, 'te')
            ->whereBetween('te.term_end', [$rangeStart, $rangeEnd])
            ->selectRaw($index('te.term_end') . ' AS period_index, SUM(te.is_retrying) AS retrying, COUNT(*) AS subscriptions', [$rangeStart, $rangeEnd])
            ->groupBy('period_index')
            ->get();

        $renewedBy = [];
        foreach ($renewed as $row) {
            $renewedBy[(int) $row->period_index] = (int) $row->subscriptions;
        }
        $unpaidBy = [];
        foreach ($unpaid as $row) {
            $unpaidBy[(int) $row->period_index] = ['lost' => (int) $row->subscriptions - (int) $row->retrying, 'retrying' => (int) $row->retrying];
        }

        $data = [];
        $running = array_fill_keys($keys, 0);
        foreach ($periods as $i => $dt) {
            foreach ($keys as $key) {
                $running[$key] += $totals[$i][$key];
            }
            $stats = (object) $running;
            // Labels use the period's last day
            $periodEnd = $yearly ? $dt->copy()->endOfYear() : $dt->copy()->endOfMonth();
            $stillRunning = ReportHelper::toGmt($periodEnd) > $now;
            $labelDate = $periodEnd->format('Y-m-d');

            $newSubscriptions = (int) $stats->new_subscriptions;
            $newSubscriptionsMrr = self::mrrFromTimes12($stats->new_subscriptions_mrr);
            $activeSubscriptions = (int) $stats->active_subscriptions;
            $startSubscriptions = (int) $stats->start_subscriptions;
            $churnedSubscriptions = (int) $stats->churned_subscriptions;
            $churnedMrr = self::mrrFromTimes12($stats->churned_subscriptions_mrr);
            $mrr = self::mrrFromTimes12($stats->mrr);
            $startMrr = self::mrrFromTimes12($stats->start_mrr);

            // Retained = the starting base still active at period end; new sign-ups are not part of it.
            $retainedCount = $startSubscriptions - $churnedSubscriptions;
            $retainedMrr = max(0, round($startMrr - $churnedMrr, 2));
            $retentionRate = $startSubscriptions > 0 ? ($retainedCount / $startSubscriptions) * 100 : 0;
            $retentionRateMoney = $startMrr > 0 ? ($retainedMrr / $startMrr) * 100 : 0;

            $leftBy = [];
            foreach (self::CHURN_REASONS as $churnReason) {
                $leftBy[$churnReason] = (int) $stats->{'left_' . $churnReason};
            }
            // Renewal rate = paid / (paid + lost) of the renewals due; retrying ones count once they settle
            $renewals = $renewedBy[$i] ?? 0;
            $lost = $unpaidBy[$i]['lost'] ?? 0;

            $data[] = [
                'day'                       => $labelDate,
                // "o" is the ISO year the week belongs to: Dec 31 can fall in week 01 of the next year
                'week'                      => date('o-W', strtotime($labelDate)),
                'group'                     => $dt->format($keyFormat),
                'year'                      => $dt->format('Y'),
                // Only a whole, ended period compares with others: not one still running, nor one the range cuts at either end
                'is_complete'               => !$stillRunning && $dt->getTimestamp() >= $localStart->getTimestamp()
                    && $periodEnd->getTimestamp() <= $localEnd->getTimestamp(),
                'is_running'                => $stillRunning,
                'new_subscriptions'         => $newSubscriptions,
                'new_subscriptions_mrr'     => $newSubscriptionsMrr,
                'churned_subscriptions'     => $churnedSubscriptions,
                'churned_subscriptions_mrr' => $churnedMrr,
                'churned_new_subscriptions' => (int) $stats->churned_new_subscriptions,
                'left_by_reason'            => $leftBy,
                'renewals_paid'             => $renewals,
                'renewals_lost'             => $lost,
                'renewals_retrying'         => $unpaidBy[$i]['retrying'] ?? 0,
                'renewal_rate'              => ($renewals + $lost) ? round($renewals / ($renewals + $lost) * 100, 2) : null,
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
                'period_gross'              => round($stats->period_gross / 100, 2),
                'period_subscriptions'      => $newSubscriptions,
            ];
        }

        return $data;
    }

    /**
     * The last paid renewal per parent order (join as `lr`), from one grouped scan: a correlated lookup repeats per use
     */
    protected static function lastPaidRenewalQuery()
    {
        return App::db()->table('fct_orders')
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->whereIn('payment_status', Status::getReportStatuses())
            ->selectRaw('parent_id, MAX(COALESCE(completed_at, created_at)) AS paid_at')
            ->groupBy('parent_id');
    }

    /**
     * A subscription the customer moved away from by an upgrade or downgrade: the plan they moved to carries on
     */
    private static function upgradedAwaySql(string $prefix = ''): string
    {
        // The text match first: parsing every row's JSON would slow each report query down
        return "({$prefix}config LIKE '%upgraded_to_sub_id%'
            AND CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$prefix}config, '$.upgraded_to_sub_id')), '0') AS UNSIGNED) > 0)";
    }

    /** @var int[]|null subscriptions moved away from by an upgrade, read once per request */
    private $upgradedAway = null;

    /**
     * Ids of the subscriptions moved away from by an upgrade: few, so a primary-key NOT IN is cheaper than reading
     * every row's config in each query
     */
    protected function upgradedAwayIds(array $params = []): array
    {
        // A product or interval filter reads plans: a change to another plan leaves one and joins the other
        if (!empty($params['variationIds']) || (string) Arr::get($params, 'billingInterval', '') !== '') {
            return [];
        }
        if ($this->upgradedAway === null) {
            $this->upgradedAway = array_map('intval', App::db()->table('fct_subscriptions')
                ->whereRaw(self::upgradedAwaySql())
                ->pluck('id')
                ->toArray());
        }

        return $this->upgradedAway;
    }

    /** @var array<string, int[]> subscriptions moved by an upgrade to another plan of the same variation set */
    private $movedWithin = [];

    /**
     * Ids of the subscriptions in the variationIds set moved away from by an upgrade to a plan also in the set:
     * for that set the customer stays. Empty without a variation filter (upgradedAwayIds() covers that case).
     */
    protected function movedWithinIds(array $params): array
    {
        $ids = array_values(array_unique(array_map('intval', (array) Arr::get($params, 'variationIds', []))));
        if (!$ids) {
            return [];
        }
        sort($ids);
        $key = implode(',', $ids);
        if (!isset($this->movedWithin[$key])) {
            $target = "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(mf.config, '$.upgraded_to_sub_id')), '0') AS UNSIGNED)";
            $this->movedWithin[$key] = array_map('intval', App::db()->table('fct_subscriptions as mf')
                ->join('fct_subscriptions as mt', 'mt.id', '=', App::db()->raw($target))
                ->whereRaw(self::upgradedAwaySql('mf.'))
                ->whereIn('mf.variation_id', $ids)
                ->whereIn('mt.variation_id', $ids)
                ->pluck('mf.id')
                ->toArray());
        }

        return $this->movedWithin[$key];
    }

    private function withoutUpgradedAway($query, string $column, array $params)
    {
        $ids = $this->upgradedAwayIds($params);

        return $ids ? $query->whereNotIn($column, $ids) : $query;
    }

    /**
     * For each subscription moved to by an upgrade (join as `upf` on upf.to_id), the first plan of the line: its start
     * (from_created) and its MRR x12 (from_x12). The moved-to plan runs from there and counts as new at that price;
     * each change adds only its difference as expansion or contraction.
     */
    private function upgradeOriginsQuery(array $params)
    {
        $ids = $this->upgradedAwayIds($params);
        $price = function ($alias) {
            return self::cyclesPerYearSql($alias . '.') . " * {$alias}.recurring_total";
        };
        $query = ReportCurrency::subscriptionsTable($params, 'r')
            ->when($ids, fn($q) => $q->whereIn('r.id', $ids), fn($q) => $q->whereRaw('1 = 0'));

        // Each plan moved away from joins the first plan of its line, however many changes back
        $roots = $this->upgradeChains($ids)['root'];
        $first = 'NULL';
        if ($roots) {
            $first = 'CASE r.id';
            foreach ($roots as $id => $root) {
                $first .= ' WHEN ' . (int) $id . ' THEN ' . (int) $root;
            }
            $first .= ' END';
        }

        return $this->joinSubscriptions($query, $params, 'rp', function ($join) use ($first) {
            $join->whereRaw("rp.id = {$first}");
        })
            ->selectRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(r.config, '$.upgraded_to_sub_id')) AS UNSIGNED) AS to_id,
                MIN(COALESCE(rp.created_at, r.created_at)) AS from_created,
                MAX(COALESCE({$price('rp')}, {$price('r')})) AS from_x12")
            ->groupBy('to_id');
    }

    /**
     * A line of plans counts once, from its first plan, at the price of its last plan (getRetentionData()). While an
     * earlier plan ran, its own price was the MRR: add the difference for those months, so the history matches the
     * Subscriptions page (new at the first price, each change as its difference).
     */
    private function addUpgradeHistory(callable $spread, array $params, callable $scope, string $mrrTimes12, int $count, string $first, bool $yearly): void
    {
        $away = $this->upgradedAwayIds($params);
        if (!$away) {
            return;
        }
        $chains = $this->upgradeChains($away);
        $last = [];
        foreach ($chains['root'] as $id => $root) {
            $plan = $id;
            for ($guard = 0; isset($chains['next'][$plan]) && in_array($plan, $away, true) && $guard < 100; $guard++) {
                $plan = $chains['next'][$plan];
            }
            $last[$id] = $plan;
        }

        // Each plan's start and MRR as the retention rows read them; the last plan only where it is counted
        $plans = [];
        foreach ($scope($this->joinLivePaidNow(ReportCurrency::subscriptionsTable($params), $params, 'id', 'parent_order_id')
            ->whereIn('id', array_values(array_unique(array_merge(array_keys($chains['root']), array_values($chains['next'])))))
            ->selectRaw("id, created_at, {$mrrTimes12} AS x12"))
            ->get() as $row) {
            $plans[(int) $row->id] = $row;
        }

        $startOfRange = $params['startDate']->format('Y-m-d H:i:s');
        $endOfRange = $params['endDate']->format('Y-m-d H:i:s');
        $index = function (string $gmt) use ($startOfRange, $endOfRange, $count, $first, $yearly) {
            if ($gmt < $startOfRange) {
                return -1;
            }
            if ($gmt > $endOfRange) {
                return $count;
            }
            $local = ReportHelper::local(new \DateTime($gmt, new \DateTimeZone('UTC')));
            if ($yearly) {
                return (int) $local->format('Y') - (int) $first;
            }
            [$year, $month] = array_map('intval', explode('-', $first));

            return ((int) $local->format('Y') * 12 + (int) $local->format('n')) - ($year * 12 + $month);
        };

        foreach ($chains['root'] as $id => $root) {
            $next = $chains['next'][$id] ?? 0;
            if (!isset($plans[$id], $plans[$next], $plans[$last[$id]])) {
                continue;
            }
            $diff = (int) $plans[$id]->x12 - (int) $plans[$last[$id]]->x12;
            if ($diff === 0) {
                continue;
            }
            $from = $index((string) $plans[$id]->created_at);
            $to = $index((string) $plans[$next]->created_at);
            $spread($from, $to - 1, ['mrr' => $diff]);
            $spread($from + 1, $to, ['start_mrr' => $diff]);
            if ($root === $id) {
                $spread($from, $from, ['new_subscriptions_mrr' => $diff]);
            }
        }
    }

    /** @var array<string, array> upgrade chains of upgradedAwayIds(), read once per request */
    private $chains = [];

    /**
     * The upgrade lines of the plans moved away from: each one's first plan (root) and the plan it moved to (next).
     * Walked in PHP: the links are few, and MySQL 5.7 has no recursive query.
     *
     * @param int[] $ids subscriptions moved away from
     * @return array{root: array<int, int>, next: array<int, int>}
     */
    private function upgradeChains(array $ids): array
    {
        $key = implode(',', $ids);
        if (isset($this->chains[$key])) {
            return $this->chains[$key];
        }

        $from = [];
        $next = [];
        if ($ids) {
            foreach (App::db()->table('fct_subscriptions')->whereIn('id', $ids)
                ->selectRaw("id, CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(config, '$.upgraded_from_sub_id')), '0') AS UNSIGNED) AS from_id,
                    CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(config, '$.upgraded_to_sub_id')), '0') AS UNSIGNED) AS to_id")
                ->get() as $row) {
                $from[(int) $row->id] = (int) $row->from_id;
                $next[(int) $row->id] = (int) $row->to_id;
            }
        }

        $root = [];
        foreach (array_keys($from) as $id) {
            $first = $id;
            // A plan's predecessor moved away to it; a broken or circular link stops the walk
            for ($guard = 0; isset($from[$first], $next[$from[$first]]) && $guard < 100; $guard++) {
                $first = $from[$first];
            }
            $root[$id] = $first;
        }

        return $this->chains[$key] = ['root' => $root, 'next' => $next];
    }

    /** Expansion and contraction (MRR x12) of the plan changes read by planChanges() */
    const PLAN_CHANGE_SUMS = "COALESCE(SUM(GREATEST(pc_new - pc_old, 0)), 0) AS expansion_x12, COALESCE(SUM(GREATEST(pc_old - pc_new, 0)), 0) AS contraction_x12";

    /**
     * Every plan change (a plan that moved on again since included), dated by the moved-to plan's start, with the new
     * and the replaced plan's MRR x12 as pc_new / pc_old: a derived table to sum. Null when there are none to read.
     */
    private function planChanges(array $params)
    {
        if (!$this->upgradedAwayIds($params)) {
            return null;
        }
        $movedTo = App::db()->query()->fromSub($this->upgradeOriginsQuery($params), 'x')->pluck('to_id')->toArray();
        if (!$movedTo) {
            return null;
        }
        $changes = ReportCurrency::subscriptionsTable($params, 's')
            ->whereIn('s.id', $movedTo)
            ->where('s.bill_times', '<=', 0)
            ->whereNotIn('s.status', self::RETENTION_EXCLUDED_STATUSES);
        $changes = $this->joinSubscriptions($changes, $params, 'uo', function ($join) {
            $join->whereRaw("uo.id = CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.config, '$.upgraded_from_sub_id')), '0') AS UNSIGNED)");
        }, 'inner')
            // Both plans at their full price: the upgrade credit (a prorated first cycle) is a one-off, not an MRR change
            // Signed: the prices are unsigned columns, and one plan less the other can be negative
            ->selectRaw('s.created_at, CAST(' . self::cyclesPerYearSql('s.') . ' * s.recurring_total AS SIGNED) AS pc_new, CAST('
                . self::cyclesPerYearSql('uo.') . ' * uo.recurring_total AS SIGNED) AS pc_old');
        $changes = ReportCurrency::whereCurrencyColumn($changes, 's.currency', ReportCurrency::resolve(Arr::get($params, 'currency'), true));

        return App::db()->query()->fromSub($changes, 's');
    }

    /**
     * Join another subscriptions row: the converted table in the "All currencies" mode, so prices compare
     */
    private function joinSubscriptions($query, array $params, string $alias, \Closure $on, string $type = 'left')
    {
        if (ReportCurrency::isConverted($params)) {
            return $query->joinSub(ReportCurrency::subscriptionsTable($params), $alias, $on, null, null, $type);
        }

        return $query->join('fct_subscriptions as ' . $alias, $on, null, null, $type);
    }

    /**
     * When the paid term ends: the last payment (joined `lr`, else the start) plus one billing interval
     */
    protected static function termEndSql(string $prefix): string
    {
        $lastPaid = "GREATEST({$prefix}created_at, COALESCE(lr.paid_at, {$prefix}created_at))";

        return "(CASE {$prefix}billing_interval
            WHEN 'daily' THEN {$lastPaid} + INTERVAL 1 DAY WHEN 'weekly' THEN {$lastPaid} + INTERVAL 1 WEEK
            WHEN 'monthly' THEN {$lastPaid} + INTERVAL 1 MONTH WHEN 'quarterly' THEN {$lastPaid} + INTERVAL 3 MONTH
            WHEN 'half_yearly' THEN {$lastPaid} + INTERVAL 6 MONTH WHEN 'yearly' THEN {$lastPaid} + INTERVAL 1 YEAR END)";
    }

    /**
     * Billing intervals of the store's recurring subscriptions, most used first, for the interval switch.
     */
    public function retentionIntervals(array $params): array
    {
        $rows = ReportCurrency::whereCurrencyColumn(ReportCurrency::subscriptionsTable($params)
            ->whereNotIn('status', self::RETENTION_EXCLUDED_STATUSES)
            ->where('bill_times', '<=', 0)
            ->when(!empty($params['variationIds']), fn($q) => $q->whereIn('variation_id', $params['variationIds']))
            ->selectRaw('billing_interval, COUNT(*) AS subscriptions')
            ->groupBy('billing_interval'), 'currency', ReportCurrency::resolve(Arr::get($params, 'currency'), true))
            ->orderByDesc('subscriptions')
            ->get();

        return array_values(array_filter(array_map(fn($row) => (string) $row->billing_interval, $rows->all())));
    }

    /**
     * MRR in the store currency from twelve times the monthly cents, rounded once to the cent, half up.
     * Integer maths, so a half cent never depends on float representation.
     */
    public static function mrrFromTimes12(int $times12): float
    {
        $cents = $times12 >= 0 ? intdiv($times12 + 6, 12) : -intdiv(-$times12 + 6, 12);

        return $cents / 100;
    }

    /**
     * Get cohort retention data from pre-calculated snapshots table
     * 
     * Supports both monthly and yearly g  * - Monthly: Uses period_offset 1, 2, 3... (each month)
     * - Yearly: Start sums each year's monthly cohorts at period_offset 0; Year 1, 2, 3... read 12, 24, 36...
     * 
     * @param array $params
     * @return array
     */
    /** @var string billing interval of the cohort rows read, '' for all */
    protected $cohortInterval = '';

    /** @var int 1 to read the rows that count cancel-and-rebuy as kept */
    protected $cohortRebuyAdjusted = 0;

    /**
     * The Cohorts page from its request params: every cohort, not the date range.
     *
     * @param bool $full allow the MRR metric, a billing interval and rebuy-adjusted retention
     */
    public function cohortReport(array $requestParams, bool $full): array
    {
        // No converted mode: the retention snapshots are kept per currency
        $params = ReportHelper::processParams($requestParams);
        
        // Supported groupBy: 'month' or 'year' (week not supported with current snapshots)
        $groupBy = $requestParams['groupBy'] ?? 'year';
        if (!in_array($groupBy, ['month', 'year'])) {
            $groupBy = 'year';
        }
        
        $params['groupBy'] = $groupBy;
        // The page shows every cohort, not the report date range
        unset($params['startDate'], $params['endDate']);
        $params['metric'] = $full ? ($requestParams['metric'] ?? 'subscribers') : 'subscribers';
        $params['billingInterval'] = $full ? ($requestParams['billingInterval'] ?? '') : '';
        $params['rebuyAdjusted'] = $full && !empty($requestParams['rebuyAdjusted']) && $requestParams['rebuyAdjusted'] !== 'false';
        
        // Convert variation_ids to product_ids for snapshot filtering
        // The snapshot table stores product_id, not variation_id
        $productIds = [];
        if (!empty($params['variationIds'])) {
            $productIds = App::db()->table('fct_product_variations')
                ->whereIn('id', $params['variationIds'])
                ->distinct()
                ->pluck('post_id')
                ->toArray();
        }
        $params['productIds'] = $productIds;
        
        // Calculate maxPeriods based on groupBy
        if ($groupBy === 'year') {
            // At least 8 years; more when the first cohort is older
            $first = (string) App::db()->table('fct_retention_snapshots')->min('cohort');
            $span = $first ? (int) current_time('Y') - (int) substr($first, 0, 4) + 1 : 0;
            $params['maxPeriods'] = max(8, $span);
        } else {
            // Two years of months: the 1st and 2nd yearly renewals both show
            $params['maxPeriods'] = 24;
        }

        // The metric served, which may differ from the one asked for
        return $this->getCohortData($params) + [
            'metric'         => $params['metric'],
            'full'           => $full,
            // The snapshot rules changed in an update: the page rebuilds the snapshots
            'rebuild_needed' => (bool) get_option(RetentionSnapshotService::STALE_OPTION),
        ];
    }

    /**
     * Variants that have a started subscription (not an installment plan), grouped by product: the subscription
     * pages' product selector
     */
    public function subscriptionVariations(): array
    {
        return ReportHelper::variationOptions(App::db()->table('fct_subscriptions')
            ->where('status', '!=', Status::SUBSCRIPTION_INTENDED)
            // These pages leave installment plans out
            ->where('bill_times', '<=', 0)
            ->where('variation_id', '>', 0)
            ->distinct()
            ->pluck('variation_id')
            ->toArray());
    }

    public function getCohortData($params = [])
    {
        $startDate = $params['startDate'] ?? null;
        $endDate = $params['endDate'] ?? null;
        $productIds = $params['productIds'] ?? []; // Array of product IDs (converted from variation_ids)
        $groupBy = $params['groupBy'] ?? 'month'; // month or year
        $metric = $params['metric'] ?? 'mrr';
        $maxPeriods = $params['maxPeriods'] ?? 12;

        if ($startDate && $endDate) {
            $startCohort = ReportHelper::local($startDate)->format('Y-m');
            $endCohort = ReportHelper::local($endDate)->format('Y-m');
        } else {
            // No dates: every year, or the last 24 months
            $now = ReportHelper::local(DateTime::gmtNow());
            $endCohort = $now->format('Y-m');
            $startCohort = $groupBy === 'year' ? '0000-01' : $now->modify('first day of this month')->modify('-23 months')->format('Y-m');
        }

        $currency = ReportCurrency::resolve($params['currency'] ?? null);
        $this->cohortInterval = sanitize_key((string) ($params['billingInterval'] ?? ''));
        $this->cohortRebuyAdjusted = !empty($params['rebuyAdjusted']) ? 1 : 0;

        if ($groupBy === 'year') {
            $data = $this->getCohortDataByYear($startCohort, $endCohort, $productIds, $metric, $maxPeriods, $currency);
        } else {
            $data = $this->getCohortDataByMonth($startCohort, $endCohort, $productIds, $metric, $maxPeriods, $currency);
        }

        // Per mode: snapshots built before the rebuy rows existed hold none, and the page then asks for a new build
        $data['generated_at'] = App::db()->table('fct_retention_snapshots')->where('rebuy_adjusted', $this->cohortRebuyAdjusted)->max('created_at') ?: null;
        // The intervals the snapshots hold for this currency, for the interval switch
        $data['intervals'] = ReportCurrency::whereCurrencyColumn(App::db()->table('fct_retention_snapshots')
            ->where('billing_interval', '!=', '')
            ->where('rebuy_adjusted', 0)
            ->whereNull('product_id')
            ->where('period_offset', 0), 'currency', $currency)
            ->distinct()
            ->pluck('billing_interval')
            ->toArray();

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
            ->where('billing_interval', $this->cohortInterval)
            ->where('rebuy_adjusted', $this->cohortRebuyAdjusted)
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
     * A year's Start is every monthly cohort of that year at period_offset 0. Year 1, 2, 3... read period_offset
     * 12, 24, 36...: the end of the month the 1st, 2nd, 3rd yearly renewal fell due.
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
        $maxMonthOffset = $maxPeriods * 12;

        $query = App::db()->table('fct_retention_snapshots')
            ->select([
                'cohort',
                'period_offset',
                'cohort_customers',
                'cohort_mrr',
                'retained_customers',
                'retained_mrr',
            ])
            ->where('billing_interval', $this->cohortInterval)
            ->where('rebuy_adjusted', $this->cohortRebuyAdjusted)
            ->whereBetween('cohort', [$startCohort, $endCohort])
            ->where('period_offset', '<=', $maxMonthOffset)
            ->whereRaw('period_offset % 12 = 0')
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
                $periodOffset = $yearOffset * 12;
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

            // A monthly cohort's cell for the store's current month is still running
            $runningOffset = null;
            if ($groupBy === 'month' && preg_match('/^(\d{4})-(\d{2})$/', (string) $cohortPeriod, $start)) {
                $today = new \DateTime('now', wp_timezone());
                $runningOffset = ((int) $today->format('Y') * 12 + (int) $today->format('n')) - ((int) $start[1] * 12 + (int) $start[2]);
            }

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

                if ($offset === $runningOffset) {
                    $coverage['partial'] = true;
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

    /**
     * The Subscriptions page: movement in the period (new, churned and churn rate, net change, new / churned / net
     * MRR, renewals paid and failed), a movement chart, MRR by plan and billing interval now, and real free-trial
     * conversion. Installment plans stay out; "left" is the Retention page's rule (leftAtSql).
     */
    public function getOverview(array $params, bool $withNow = true): array
    {
        $start = $params['startDate']->format('Y-m-d H:i:s');
        $end = $params['endDate']->format('Y-m-d H:i:s');

        // One grouped read serves the period figures and the movement chart
        $groups = $this->movementGroups($params);
        $inRange = function ($bucket) {
            return $bucket !== null && $bucket !== '<' && $bucket !== '>';
        };
        $period = (object) ['new_count' => 0, 'new_x12' => 0, 'churned' => 0, 'churned_x12' => 0, 'churned_base' => 0, 'churned_new' => 0, 'active_at_start' => 0];
        foreach ($groups as $row) {
            $count = (int) $row->subscriptions;
            if ($inRange($row->s_bucket)) {
                $period->new_count += $count;
                $period->new_x12 += (int) $row->first_x12;
            }
            if ($inRange($row->l_bucket)) {
                $period->churned += $count;
                $period->churned_x12 += (int) $row->x12;
                $period->churned_base += $row->s_bucket === '<' ? $count : 0;
                $period->churned_new += $inRange($row->s_bucket) ? $count : 0;
            }
            // Started before the period and not gone before it
            if ($row->s_bucket === '<' && $row->l_bucket !== '<') {
                $period->active_at_start += $count;
            }
        }

        // Subscription renewals only (sale type): installment payments are not renewals
        $renewals = function ($statuses, $amount) use ($params) {
            return $this->applyFilters(ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), $params), array_merge($params, [
                'orderTypes' => [Status::ORDER_TYPE_RENEWAL], 'paymentStatus' => $statuses,
            ]))->selectRaw("COUNT(*) AS orders, COALESCE(SUM({$amount}), 0) AS amount")->first();
        };
        // Net sales, as the Revenue report's Renewal row
        $paid = $renewals(Status::getReportStatuses(), ReportHelper::netSalesSql($params));
        // A declined renewal leaves a failed charge transaction (the order stays pending), as on the Payment Methods page
        $failedAmount = ReportCurrency::isConverted($params) ? 'ROUND(t.total / NULLIF(t.rate, 0))' : 't.total';
        // From the report's orders table: in the "All currencies" mode it holds only orders with a trusted rate
        $failed = ReportCurrency::ordersTable($params)
            ->join('fct_order_transactions as t', 't.order_id', '=', 'o.id')
            ->when(!empty($params['variationIds']), fn($q) => $q->whereExists(fn($q) =>
                $q->selectRaw('1')->from('fct_order_items as oi')->whereRaw('oi.order_id = o.id')->whereIn('oi.object_id', $params['variationIds'])
            ))
            ->where('t.transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->where('t.status', Status::TRANSACTION_FAILED)
            ->whereBetween('t.created_at', [$start, $end])
            ->whereRaw(ReportHelper::saleTypeSql() . " = 'renewal'")
            ->when(!empty($params['currency']) && $params['currency'] !== ReportCurrency::ALL, fn($q) => $q->where('o.currency', $params['currency']))
            ->selectRaw("COUNT(*) AS orders, COALESCE(SUM({$failedAmount}), 0) AS amount")
            ->first();

        $changes = $this->planChanges($params);
        $moves = !$changes ? (object) ['expansion_x12' => 0, 'contraction_x12' => 0] : $changes
            ->whereBetween('s.created_at', [$start, $end])
            ->selectRaw(self::PLAN_CHANGE_SUMS)
            ->first();

        $activeAtStart = (int) $period->active_at_start;
        $newMrr = self::mrrFromTimes12((int) $period->new_x12);
        $churnedMrr = self::mrrFromTimes12((int) $period->churned_x12);
        $expansionMrr = self::mrrFromTimes12((int) $moves->expansion_x12);
        $contractionMrr = self::mrrFromTimes12((int) $moves->contraction_x12);

        return [
            'period'          => [
                'new'             => (int) $period->new_count,
                'churned'         => (int) $period->churned,
                // Started and ended in the period: the Retention page leaves these out of its churned count
                'churned_new'     => (int) $period->churned_new,
                'active_at_start' => $activeAtStart,
                // Churn of the base that was active when the period began, as on the Retention page
                'churn_rate'      => $activeAtStart ? round((int) $period->churned_base / $activeAtStart * 100, 2) : null,
                'net_change'      => (int) $period->new_count - (int) $period->churned,
                'new_mrr'         => $newMrr,
                'churned_mrr'     => $churnedMrr,
                // Upgrades and downgrades in the period; net MRR change = new - churned + expansion - contraction
                'expansion_mrr'   => $expansionMrr,
                'contraction_mrr' => $contractionMrr,
                'net_mrr'         => round($newMrr - $churnedMrr + $expansionMrr - $contractionMrr, 2),
                'renewals'        => (int) $paid->orders,
                'renewal_amount'  => (float) $paid->amount / 100,
                'failed_renewals' => (int) $failed->orders,
                'failed_amount'   => (float) $failed->amount / 100,
            ],
            'chart'           => $this->movementChart($params, $groups),
        ] + ($withNow ? $this->mrrNow($params) + ['trials' => $this->trialConversion($params, $start, $end)] : []);
    }

    /**
     * Recurring subscriptions in the report currency and products, with the paid-now join MRR needs.
     */
    private function overviewSubscriptions(array $params, bool $withPaidTerm = false, bool $live = false)
    {
        $query = ReportCurrency::subscriptionsTable($params, 's');
        if ($live && $withPaidTerm) {
            // Live figures price a canceled discounted first cycle at what it paid; `lr` also gives the paid term's end
            $this->joinLivePaidNow($query, $params, 's.id', 's.parent_order_id');
        } else {
            $query->leftJoinSub($this->paidNowQuery($params, $live), 'pn', 'pn.sub_id', '=', 's.id')
                // When a canceled plan's paid term ends (leftAtSql, keepsAccessSql): only where those are read
                ->when($withPaidTerm, fn($q) => $q->leftJoinSub(self::lastPaidRenewalQuery(), 'lr', 'lr.parent_id', '=', 's.parent_order_id'));
        }
        $query->where('s.bill_times', '<=', 0)
            ->whereNotIn('s.status', self::RETENTION_EXCLUDED_STATUSES)
            ->when(!empty($params['variationIds']), fn($q) => $q->whereIn('s.variation_id', $params['variationIds']));

        $this->withoutUpgradedAway($query, 's.id', $params);

        return ReportCurrency::whereCurrencyColumn($query, 's.currency', ReportCurrency::resolve(Arr::get($params, 'currency'), true));
    }

    /**
     * Subscriptions that count in movement. A real free trial becomes a subscriber when it first pays (as Stripe Billing
     * counts it): one that never paid shows only in trial conversion.
     */
    private function movingSubscriptions(array $params, bool $withPaidTerm = false)
    {
        // A plan moved to by an upgrade starts with the plan it replaced (startedAtSql reads `upf`)
        // A canceled discounted first cycle at the price it paid, as live MRR and the Retention page count it
        return $this->overviewSubscriptions($params, $withPaidTerm, true)
            ->leftJoinSub($this->upgradeOriginsQuery($params), 'upf', 'upf.to_id', '=', 's.id')
            ->whereRaw($this->startedAtSql() . ' IS NOT NULL');
    }

    // When the subscription started to pay: the first paid renewal for a real free trial, else its creation
    // A real free trial starts when its first renewal that took money was paid (a renewal paid at 0 is no payment),
    // as Cohorts and Retention date it
    private function startedAtSql(): string
    {
        global $wpdb;
        $paid = "'" . implode("', '", array_map('esc_sql', Status::getReportStatuses())) . "'";

        return '(CASE WHEN s.trial_days > 0 AND NOT (' . self::simulatedTrialSql('s.') . ")
            THEN (SELECT MIN(COALESCE(tr.completed_at, tr.created_at)) FROM {$wpdb->prefix}fct_orders tr WHERE tr.parent_id = s.parent_order_id
                AND tr.type = '" . Status::ORDER_TYPE_RENEWAL . "' AND tr.payment_status IN ({$paid}) AND tr.total_paid > 0)
            ELSE COALESCE(upf.from_created, s.created_at) END)";
    }

    protected function paidRenewalSql(): string
    {
        global $wpdb;
        $paid = "'" . implode("', '", array_map('esc_sql', Status::getReportStatuses())) . "'";

        // A renewal marked paid at 0 is an order, not a payment
        return "EXISTS (SELECT 1 FROM {$wpdb->prefix}fct_orders tr WHERE tr.parent_id = s.parent_order_id
            AND tr.type = '" . Status::ORDER_TYPE_RENEWAL . "' AND tr.payment_status IN ({$paid}) AND tr.total_paid > 0)";
    }

    /**
     * Subscriptions in movement grouped by the bucket they started in and the bucket they left in: the store-time
     * chart bucket, '<' before the range, '>' after it, NULL while still live. Each row's start, end and MRR come from
     * a derived table read once (the LIMIT keeps MySQL from folding the expressions back in: the end reads JSON).
     */
    private function movementGroups(array $params)
    {
        $group = ReportHelper::processGroup($params['startDate'], $params['endDate'], $params['groupKey'] ?? null);
        $formats = ['yearly' => '%Y', 'monthly' => '%Y-%m', 'daily' => '%Y-%m-%d'];
        $format = $formats[$group['key']] ?? '%Y-%m-%d';
        $start = $params['startDate']->format('Y-m-d H:i:s');
        $end = $params['endDate']->format('Y-m-d H:i:s');
        $bucket = function ($column) use ($params, $format) {
            return "CASE WHEN {$column} IS NULL THEN NULL WHEN {$column} < ? THEN '<' WHEN {$column} > ? THEN '>'
                ELSE DATE_FORMAT(" . ReportHelper::storeTimeSql($column, $params['startDate'], $params['endDate']) . ", '{$format}') END";
        };
        $x12 = self::mrrTimes12Sql('s.');
        $rows = $this->movingSubscriptions($params, true)
            ->selectRaw($this->startedAtSql() . ' AS started_at, ' . self::leftAtSql('s.') . " AS left_at, {$x12} AS x12, COALESCE(upf.from_x12, {$x12}) AS first_x12")
            ->limit(PHP_INT_MAX);

        return App::db()->query()->fromSub($rows, 'm')
            ->selectRaw($bucket('m.started_at') . ' AS s_bucket, ' . $bucket('m.left_at') . ' AS l_bucket,
                COUNT(*) AS subscriptions, SUM(m.x12) AS x12, SUM(m.first_x12) AS first_x12', [$start, $end, $start, $end])
            ->groupBy('s_bucket', 'l_bucket')
            ->get();
    }

    /**
     * New and churned subscriptions (and their MRR) per period, in store time, from movementGroups()
     */
    private function movementChart(array $params, $groups): array
    {
        $group = ReportHelper::processGroup($params['startDate'], $params['endDate'], $params['groupKey'] ?? null);
        $formats = ['yearly' => '%Y', 'monthly' => '%Y-%m', 'daily' => '%Y-%m-%d'];
        $format = $formats[$group['key']] ?? '%Y-%m-%d';
        $bucket = function ($column) use ($params, $format) {
            return 'DATE_FORMAT(' . ReportHelper::storeTimeSql($column, $params['startDate'], $params['endDate']) . ", '{$format}')";
        };
        $period = [$params['startDate']->format('Y-m-d H:i:s'), $params['endDate']->format('Y-m-d H:i:s')];

        $range = $this->getPeriodRange($params['startDate'], $params['endDate'], $group['key'], ['new', 'churned', 'new_mrr', 'churned_mrr', 'expansion_mrr', 'contraction_mrr']);
        $sums = [];
        foreach ($groups as $row) {
            // New at the first plan's price; later plan changes are expansion or contraction
            if (isset($range[$row->s_bucket])) {
                $sums[$row->s_bucket]['new'] = ($sums[$row->s_bucket]['new'] ?? 0) + (int) $row->subscriptions;
                $sums[$row->s_bucket]['new_x12'] = ($sums[$row->s_bucket]['new_x12'] ?? 0) + (int) $row->first_x12;
            }
            if ($row->l_bucket !== null && isset($range[$row->l_bucket])) {
                $sums[$row->l_bucket]['churned'] = ($sums[$row->l_bucket]['churned'] ?? 0) + (int) $row->subscriptions;
                $sums[$row->l_bucket]['churned_x12'] = ($sums[$row->l_bucket]['churned_x12'] ?? 0) + (int) $row->x12;
            }
        }
        foreach ($sums as $key => $sum) {
            $range[$key]['new'] = $sum['new'] ?? 0;
            $range[$key]['new_mrr'] = self::mrrFromTimes12($sum['new_x12'] ?? 0);
            $range[$key]['churned'] = $sum['churned'] ?? 0;
            $range[$key]['churned_mrr'] = self::mrrFromTimes12($sum['churned_x12'] ?? 0);
        }

        // Plan changes, so the chart's net matches the Net MRR card
        $changes = $this->planChanges($params);
        if ($changes) {
            foreach ($changes->whereBetween('s.created_at', $period)->selectRaw($bucket('s.created_at') . ' AS bucket, ' . self::PLAN_CHANGE_SUMS)->groupBy('bucket')->get() as $row) {
                if (isset($range[$row->bucket])) {
                    $range[$row->bucket]['expansion_mrr'] = self::mrrFromTimes12((int) $row->expansion_x12);
                    $range[$row->bucket]['contraction_mrr'] = self::mrrFromTimes12((int) $row->contraction_x12);
                }
            }
        }

        return array_values($range);
    }

    /**
     * MRR now (open-ended subscriptions, as the health cards count it) by plan (top 10) and by billing interval, from
     * one scan grouped by both; real free trials add 0.
     */
    private function mrrNow(array $params): array
    {
        $rows = $this->overviewSubscriptions($params, true, true)
            // Live as the health cards count it: a canceled plan still in its paid term too
            ->where(function ($q) {
                $q->whereIn('s.status', [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_EXPIRING, Status::SUBSCRIPTION_TRIALING])
                    ->orWhereRaw(self::keepsAccessSql('s.') . ' AND ' . self::termEndSql('s.') . ' > ?', [DateTime::gmtNow()->format('Y-m-d H:i:s')]);
            })
            ->leftJoin('posts as mp', 'mp.ID', '=', 's.product_id')
            ->leftJoin('fct_product_variations as mv', 'mv.id', '=', 's.variation_id')
            ->selectRaw('s.variation_id, s.billing_interval, MAX(COALESCE(mp.post_title, s.item_name)) AS label, MAX(mv.variation_title) AS variation,
                COUNT(*) AS subscriptions, SUM(' . self::mrrTimes12Sql('s.') . ') AS x12')
            ->groupBy('s.variation_id', 's.billing_interval')
            ->get();

        $plans = [];
        $intervals = [];
        foreach ($rows as $row) {
            $plan = (string) $row->variation_id;
            if (!isset($plans[$plan])) {
                $plans[$plan] = ['key' => $plan, 'label' => (string) $row->label, 'variation' => (string) $row->variation, 'subscriptions' => 0, 'x12' => 0];
            }
            $plans[$plan]['subscriptions'] += (int) $row->subscriptions;
            $plans[$plan]['x12'] += (int) $row->x12;
            $interval = (string) $row->billing_interval;
            if (!isset($intervals[$interval])) {
                $intervals[$interval] = ['key' => $interval, 'label' => $interval, 'variation' => '', 'subscriptions' => 0, 'x12' => 0];
            }
            $intervals[$interval]['subscriptions'] += (int) $row->subscriptions;
            $intervals[$interval]['x12'] += (int) $row->x12;
        }
        $finish = function (array $list, int $limit = 0) {
            usort($list, function ($a, $b) {
                return $b['x12'] <=> $a['x12'] ?: strcmp($a['key'], $b['key']);
            });
            // The rest as one row, so the split still adds up to MRR
            if ($limit && count($list) > $limit) {
                $rest = array_slice($list, $limit);
                $list = array_slice($list, 0, $limit);
                $list[] = ['key' => 'other', 'label' => __('Other plans', 'fluent-cart'), 'variation' => '',
                    'subscriptions' => array_sum(array_column($rest, 'subscriptions')), 'x12' => array_sum(array_column($rest, 'x12'))];
            }

            $total = (int) round(self::mrrFromTimes12((int) array_sum(array_column($list, 'x12'))) * 100);
            $list = array_map(function ($item) {
                $item['mrr'] = self::mrrFromTimes12($item['x12']);
                unset($item['x12']);

                return $item;
            }, $list);
            // Rows round one by one; the top row takes the remainder, so the split equals MRR to the cent
            if ($list) {
                $cents = (int) round(array_sum(array_column($list, 'mrr')) * 100);
                $list[0]['mrr'] = round($list[0]['mrr'] + ($total - $cents) / 100, 2);
            }

            return $list;
        };

        return ['mrr_by_plan' => $finish(array_values($plans), 10), 'mrr_by_interval' => $finish(array_values($intervals))];
    }

    /**
     * Real free trials (not a paid, discounted first cycle) started in the period: converted once a renewal was paid,
     * lost when it left trialing without paying (canceled, expired, failing, paused), still in trial while trialing.
     */
    private function trialConversion(array $params, string $start, string $end): array
    {
        $converted = $this->paidRenewalSql();
        // Every trial not paid and no longer trialing ended unpaid: canceled, expired, failing, paused
        $lost = "s.status <> '" . Status::SUBSCRIPTION_TRIALING . "'";

        $row = $this->overviewSubscriptions($params)
            ->where('s.trial_days', '>', 0)
            ->whereRaw('NOT (' . self::simulatedTrialSql('s.') . ')')
            ->whereBetween('s.created_at', [$start, $end])
            ->selectRaw("COUNT(*) AS started, SUM({$converted}) AS converted,
                SUM(NOT {$converted} AND {$lost}) AS lost,
                SUM(NOT {$converted} AND s.status = '" . Status::SUBSCRIPTION_TRIALING . "') AS in_trial")
            ->first();

        $converted = (int) $row->converted;
        $lostCount = (int) $row->lost;

        return [
            'started'         => (int) $row->started,
            'converted'       => $converted,
            'lost'            => $lostCount,
            'in_trial'        => (int) $row->in_trial,
            // Of the trials that ended
            'conversion_rate' => ($converted + $lostCount) ? round($converted / ($converted + $lostCount) * 100, 2) : null,
        ];
    }
}
