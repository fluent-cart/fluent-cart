<?php

namespace FluentCart\App\Services\Report\Concerns\Subscription;

use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\App\Services\Payments\SubscriptionHelper;

trait FutureRenewals
{
    /**
     * Renewals of open-ended (recurring) plans only; installment plans have their own report.
     *
     * @param array $params currency, horizon (3|6|12 months), asOf (Y-m-d)
     */
    public function getFutureRenewals(array $params)
    {
        // The as-of day and the month buckets are the store's; renewal maths and bounds stay UTC instants
        $asOf = ReportHelper::validDay($params['asOf'] ?? null);
        $localStart = $asOf ? ReportHelper::siteDay($asOf)->startOfDay() : ReportHelper::local(DateTime::gmtNow())->startOfDay();
        $startDate = ReportHelper::toGmt($localStart);
        $horizon = (string) ($params['horizon'] ?? '');
        $horizon = in_array($horizon, ['3', '6', '12'], true) ? $horizon : '3';
        $groupBy = 'monthly';
        // The horizon's last day, then on to that month's end: the chart never ends on a few days, and the window is
        // never shorter than the horizon (3 months from 1 Nov: to 31 Jan; from 31 Jan: to 30 Apr). One engine step, so
        // short months clamp
        $horizonInterval = ['3' => 'quarterly', '6' => 'half_yearly', '12' => 'yearly'][$horizon];
        $lastDay = (new DateTime(gmdate('Y-m-d H:i:s', SubscriptionHelper::addBillingInterval($localStart->format('Y-m-d H:i:s'), $horizonInterval)), wp_timezone()))->subDay();
        $localEnd = $lastDay->endOfMonth();
        $endDate = ReportHelper::toGmt($localEnd);

        $currency = ReportCurrency::resolve($params['currency'] ?? null, true);
        $variationIds = (array) Arr::get($params, 'variationIds', []);
        $activeSubscriptions = $this->getActiveSubscriptions($currency, $variationIds);

        $projections = [];
        $totalProjected = 0;
        $totalRenewals = 0;
        $byVariation = [];
        $byInterval = [];
        $trialByInterval = [];
        $overdue = ['count' => 0, 'amount' => 0];
        $trialFirst = ['count' => 0, 'amount' => 0];

        // Step whole months from the 1st; adding a month to the 31st skips the next month
        $current = (clone $localStart)->startOfMonth();
        while ($current <= $localEnd) {
            $key = $current->format('Y-m');
            $projections[$key] = [
                'group'            => $key,
                'renewals_count'   => 0,
                'projected_amount' => 0,
                // [interval][k] => cents: the k-th renewal of a subscription in the horizon weighs rate^k (one lost
                // early loses the rest); a free trial not paid yet sits in 'trial', its first charge at k = 0
                'by_interval'      => [],
                'trial'            => [],
            ];
            $current->addMonth();
        }

        foreach ($activeSubscriptions as $subscription) {
            // Behind on its renewal already: in retry soon, not a renewal of the coming months
            if ($subscription->next_billing_date && $subscription->next_billing_date < $startDate->format('Y-m-d H:i:s')) {
                $overdue['count']++;
                $overdue['amount'] += (int) $subscription->recurring_total;
            }
            $renewalDates = $this->getRenewalDatesInPeriod($subscription, $startDate, $endDate);
            $renewals = count($renewalDates);

            if (!$renewals) {
                continue;
            }

            $projectedAmount = $renewals * $subscription->recurring_total;
            $totalProjected += $projectedAmount;
            $totalRenewals += $renewals;

            $variationId = (int) $subscription->variation_id;
            if (!isset($byVariation[$variationId])) {
                $byVariation[$variationId] = [
                    'variation_id'     => $variationId,
                    'product_id'       => (int) $subscription->product_id,
                    'product_title'    => (string) $subscription->item_name,
                    'variation_title'  => '',
                    'renewals_count'   => 0,
                    'projected_amount' => 0,
                    'by_interval'      => [],
                    'trial'            => [],
                ];
            }
            $byVariation[$variationId]['renewals_count'] += $renewals;
            $byVariation[$variationId]['projected_amount'] += $projectedAmount;

            $interval = (string) $subscription->billing_interval;
            // A free trial that has not paid yet: every charge needs the conversion first (k = 0 is the converting one)
            $trialPending = $subscription->status === Status::SUBSCRIPTION_TRIALING && (int) $subscription->trial_days > 0 && !$this->isPaidTrial($subscription);
            $bucket = $trialPending ? 'trial' : 'by_interval';
            foreach ($renewalDates as $index => $renewalDate) {
                $groupKey = $renewalDate->setTimezone(wp_timezone())->format('Y-m');
                $amount = (int) $subscription->recurring_total;
                $k = $trialPending ? $index : $index + 1;
                $projections[$groupKey]['renewals_count']++;
                $projections[$groupKey]['projected_amount'] += $amount;
                $projections[$groupKey][$bucket][$interval][$k] = ($projections[$groupKey][$bucket][$interval][$k] ?? 0) + $amount;
                $byVariation[$variationId][$bucket][$interval][$k] = ($byVariation[$variationId][$bucket][$interval][$k] ?? 0) + $amount;
                if ($trialPending) {
                    $trialByInterval[$interval][$k] = ($trialByInterval[$interval][$k] ?? 0) + $amount;
                    if ($k === 0) {
                        $trialFirst['count']++;
                        $trialFirst['amount'] += $amount;
                    }
                    continue;
                }
                $byInterval[$interval][$k] = ($byInterval[$interval][$k] ?? 0) + $amount;
            }
        }

        $windowEnd = ReportHelper::toGmt(ReportHelper::local(DateTime::gmtNow())->startOfMonth());
        $windowStart = ReportHelper::toGmt(ReportHelper::local(DateTime::gmtNow())->startOfMonth()->subMonths(12));
        $history = ['currency' => $currency, 'variationIds' => $variationIds, 'startDate' => $windowStart, 'endDate' => $windowEnd];

        return [
            'totalProjected' => $totalProjected,
            'totalRenewals'  => $totalRenewals,
            'projections'    => array_values($projections),
            // Scheduled amounts (cents) per billing interval, and the store's own rates for the slider defaults
            'by_interval'    => $byInterval,
            'trial'          => $trialByInterval,
            'trial_first'    => $trialFirst,
            'rates'          => $this->renewalRatesByInterval($history),
            'trial_rate'     => $this->trialRateByOutcome($history),
            'rate_window'    => [ReportHelper::local($windowStart)->format('Y-m-d'), ReportHelper::local($windowEnd)->subDay()->format('Y-m-d')],
            'overdue'        => $overdue,
            'retrying'       => $this->retryingRenewals($currency, $variationIds),
            'wont_renew'     => $this->endingInHorizon($currency, $variationIds, $startDate, $endDate),
            'period'         => [
                $localStart->format('Y-m-d H:i:s'),
                $localEnd->format('Y-m-d H:i:s'),
            ],
            'groupBy'      => $groupBy,
            'horizon'      => $horizon,
            'by_variation' => ($params['withVariations'] ?? true) ? $this->futureRenewalsByVariation($byVariation) : [],
        ];
    }

    private function futureRenewalsByVariation(array $rows)
    {
        if ($rows) {
            $catalog = App::db()->table('fct_product_variations as pv')
                ->leftJoin('posts as p', 'p.ID', '=', 'pv.post_id')
                ->whereIn('pv.id', array_keys($rows))
                ->select(['pv.id', 'pv.post_id', 'p.post_title', 'pv.variation_title'])
                ->get();

            foreach ($catalog as $variation) {
                $variationId = (int) $variation->id;
                $rows[$variationId]['product_id'] = (int) $variation->post_id;
                if (is_string($variation->post_title) && $variation->post_title !== '') {
                    $rows[$variationId]['product_title'] = $variation->post_title;
                }
                $rows[$variationId]['variation_title'] = (string) $variation->variation_title;
            }
        }

        usort($rows, function ($a, $b) {
            if ($a['projected_amount'] === $b['projected_amount']) {
                return $a['variation_id'] <=> $b['variation_id'];
            }

            return $b['projected_amount'] <=> $a['projected_amount'];
        });

        return array_values($rows);
    }

    /**
     * Live recurring subscriptions that renew: active, expiring (a Stripe renewal in retry that is still live) and
     * trialing. expire_at is not read: it is not kept up to date on live subscriptions.
     */
    private function getActiveSubscriptions($currency, array $variationIds = [])
    {
        $query = ReportCurrency::subscriptionsTable(['currency' => $currency])
            ->select([
                'id',
                'parent_order_id',
                'product_id',
                'variation_id',
                'item_name',
                'recurring_total',
                'billing_interval',
                'next_billing_date',
                'status',
                'trial_days',
                'config',
            ])
            ->whereIn('status', [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_EXPIRING, Status::SUBSCRIPTION_TRIALING])
            ->where('bill_times', '<=', 0)
            ->whereNotNull('next_billing_date')
            ->when($variationIds, fn($q) => $q->whereIn('variation_id', $variationIds));

        // Projected renewal amounts are only summable within one currency
        return ReportCurrency::whereCurrencyColumn($query, 'currency', $currency)->get();
    }

    /** A trialing subscription that already paid: a discounted first cycle, or a plan an upgrade moved to */
    private function isPaidTrial($subscription): bool
    {
        $config = is_array($subscription->config) ? $subscription->config : json_decode((string) $subscription->config, true);

        return is_array($config) && (($config['is_trial_days_simulated'] ?? '') === 'yes' || (int) ($config['upgraded_from_sub_id'] ?? 0) > 0);
    }

    /**
     * Renewal rate per billing interval over the history window: renewals paid / (paid + lost), the Retention page's
     * rule (a lost renewal on its paid term's end). Null for an interval with no renewal due in the window.
     */
    private function renewalRatesByInterval(array $params): array
    {
        $rates = [];
        foreach ($this->renewalCountsByInterval($params) as $interval => $count) {
            $due = $count['paid'] + $count['lost'];
            $rates[$interval] = $due ? round($count['paid'] / $due * 100, 1) : null;
        }

        return $rates;
    }

    /**
     * Renewals paid and lost per billing interval in the window, by the rule of renewalRatesByInterval()
     *
     * @return array<string, array{paid: int, lost: int}>
     */
    protected function renewalCountsByInterval(array $params): array
    {
        $start = $params['startDate']->format('Y-m-d H:i:s');
        $end = $params['endDate']->format('Y-m-d H:i:s');
        $scope = function ($query, $prefix) use ($params) {
            $query->where($prefix . 'bill_times', '<=', 0)
                ->when(!empty($params['variationIds']), fn($q) => $q->whereIn($prefix . 'variation_id', $params['variationIds']));

            return ReportCurrency::whereCurrencyColumn($query, $prefix . 'currency', $params['currency']);
        };

        $paid = $scope(ReportCurrency::subscriptionsTable($params, 'rs')
            ->join('fct_orders as ro', 'ro.parent_id', '=', 'rs.parent_order_id')
            ->where('ro.type', Status::ORDER_TYPE_RENEWAL)
            ->whereIn('ro.payment_status', Status::getReportStatuses())
            ->whereRaw('COALESCE(ro.completed_at, ro.created_at) BETWEEN ? AND ?', [$start, $end])
            ->selectRaw('rs.billing_interval, COUNT(*) AS n')
            ->groupBy('rs.billing_interval'), 'rs.')
            ->get();

        $ended = $scope(ReportCurrency::subscriptionsTable($params, 'us')
            ->leftJoinSub(self::lastPaidRenewalQuery(), 'lr', 'lr.parent_id', '=', 'us.parent_order_id')
            ->whereIn('us.status', [Status::SUBSCRIPTION_CANCELED, Status::SUBSCRIPTION_EXPIRED, Status::SUBSCRIPTION_PAUSED])
            ->when($this->upgradedAwayIds($params), fn($q) => $q->whereNotIn('us.id', $this->upgradedAwayIds($params)))
            // With a product filter, a move to another of the picked plans keeps the customer
            ->when($this->movedWithinIds($params), fn($q) => $q->whereNotIn('us.id', $this->movedWithinIds($params)))
            ->whereRaw('NOT (us.trial_days > 0 AND NOT (' . self::simulatedTrialSql('us.') . ') AND lr.paid_at IS NULL)')
            ->selectRaw('us.billing_interval, ' . self::termEndSql('us.') . ' AS term_end'), 'us.');
        $lost = App::db()->query()->fromSub($ended, 'e')
            ->whereBetween('e.term_end', [$start, $end])
            ->selectRaw('e.billing_interval, COUNT(*) AS n')
            ->groupBy('e.billing_interval')
            ->get();

        $counts = [];
        foreach ($paid as $row) {
            $counts[$row->billing_interval] = ['paid' => (int) $row->n, 'lost' => 0];
        }
        foreach ($lost as $row) {
            $counts[$row->billing_interval] = ['paid' => $counts[$row->billing_interval]['paid'] ?? 0, 'lost' => (int) $row->n];
        }

        return $counts;
    }

    /**
     * Free trials whose outcome fell in the history window: converted = first paid renewal in it, lost = canceled or
     * expired in it without a paid renewal. Null when none ended there.
     */
    private function trialRateByOutcome(array $params)
    {
        $start = $params['startDate']->format('Y-m-d H:i:s');
        $end = $params['endDate']->format('Y-m-d H:i:s');
        $firstPaid = App::db()->table('fct_orders')
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->whereIn('payment_status', Status::getReportStatuses())
            ->selectRaw('parent_id, MIN(COALESCE(completed_at, created_at)) AS paid_at')
            ->groupBy('parent_id');
        $query = ReportCurrency::subscriptionsTable($params, 't')
            ->leftJoinSub($firstPaid, 'fp', 'fp.parent_id', '=', 't.parent_order_id')
            ->where('t.bill_times', '<=', 0)
            ->where('t.trial_days', '>', 0)
            ->whereRaw('NOT (' . self::simulatedTrialSql('t.') . ')')
            // A trial changed to another plan did not end: the customer stayed
            ->when($this->upgradedAwayIds($params), fn($q) => $q->whereNotIn('t.id', $this->upgradedAwayIds($params)))
            ->when(!empty($params['variationIds']), fn($q) => $q->whereIn('t.variation_id', $params['variationIds']))
            ->selectRaw("SUM(fp.paid_at BETWEEN ? AND ?) AS converted,
                SUM(fp.paid_at IS NULL AND t.status IN ('" . Status::SUBSCRIPTION_CANCELED . "', '" . Status::SUBSCRIPTION_EXPIRED . "')
                    AND COALESCE(t.canceled_at, t.updated_at) BETWEEN ? AND ?) AS lost", [$start, $end, $start, $end]);
        $row = ReportCurrency::whereCurrencyColumn($query, 't.currency', $params['currency'])->first();
        $ended = (int) $row->converted + (int) $row->lost;

        return $ended ? round((int) $row->converted / $ended * 100, 1) : null;
    }

    /** Renewals in payment retry (failing, past due): may still come in */
    private function retryingRenewals($currency, array $variationIds): array
    {
        $row = ReportCurrency::whereCurrencyColumn(ReportCurrency::subscriptionsTable(['currency' => $currency])
            ->whereIn('status', [Status::SUBSCRIPTION_FAILING, Status::SUBSCRIPTION_PAST_DUE])
            ->where('bill_times', '<=', 0)
            ->when($variationIds, fn($q) => $q->whereIn('variation_id', $variationIds))
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(recurring_total), 0) AS amount'), 'currency', $currency)
            ->first();

        return ['count' => (int) $row->n, 'amount' => (int) $row->amount];
    }

    /**
     * Canceled subscriptions still in their paid term that end inside the horizon: renewals that will not happen
     * unless the customer comes back
     */
    private function endingInHorizon($currency, array $variationIds, $startDate, $endDate): array
    {
        $params = ['currency' => $currency];
        $query = ReportCurrency::subscriptionsTable($params, 's')
            ->leftJoinSub(self::lastPaidRenewalQuery(), 'lr', 'lr.parent_id', '=', 's.parent_order_id')
            ->where('s.bill_times', '<=', 0)
            ->when($variationIds, fn($q) => $q->whereIn('s.variation_id', $variationIds))
            ->whereRaw(self::keepsAccessSql('s.'))
            ->selectRaw(self::termEndSql('s.') . ' AS term_end, s.recurring_total');
        $rows = App::db()->query()->fromSub(ReportCurrency::whereCurrencyColumn($query, 's.currency', $currency), 'c')
            ->whereBetween('c.term_end', [$startDate->format('Y-m-d H:i:s'), $endDate->format('Y-m-d H:i:s')])
            ->get();

        $result = ['count' => 0, 'amount' => 0, 'by_month' => []];
        foreach ($rows as $row) {
            $month = (new \DateTime((string) $row->term_end, new \DateTimeZone('UTC')))->setTimezone(wp_timezone())->format('Y-m');
            $result['count']++;
            $result['amount'] += (int) $row->recurring_total;
            $result['by_month'][$month] = ($result['by_month'][$month] ?? 0) + (int) $row->recurring_total;
        }

        return $result;
    }

    /**
     * Billing dates inside the period
     *
     * @return \DateTimeImmutable[]
     */
    private function getRenewalDatesInPeriod($subscription, $startDate, $endDate)
    {
        if (!$subscription->next_billing_date) {
            return [];
        }

        $periodStart = new \DateTimeImmutable($startDate->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
        $periodEnd = new \DateTimeImmutable($endDate->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));

        // Count from the earlier of the first billing date and the period start, so dates a plan is
        // already behind on cannot use up the budget; billingDates() stops at the period end
        $first = new \DateTimeImmutable((string) $subscription->next_billing_date, new \DateTimeZone('UTC'));
        $count = (int) min($first, $periodStart)->diff($periodEnd)->days + 2;
        $schedule = ReportHelper::billingScheduleFromConfig($subscription->config);
        $dates = ReportHelper::billingDates($subscription->next_billing_date, (string) $subscription->billing_interval, $count, $periodEnd, $schedule);

        // An unknown interval yields one date, so the plan renews once, as before
        return array_values(array_filter($dates, function ($date) use ($periodStart) {
            return $date >= $periodStart;
        }));
    }
}
