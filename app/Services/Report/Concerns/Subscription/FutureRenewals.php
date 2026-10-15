<?php

namespace FluentCart\App\Services\Report\Concerns\Subscription;

use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\App;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentHelper;

trait FutureRenewals
{
    public function getFutureRenewals(array $params)
    {
        $startDate = (new DateTime)->startOfDay();
        $endDate = (new DateTime)->addQuarter()->endOfDay();
        // $groupBy = $params['groupBy'] ?? 'daily'; // daily or monthly
        $groupBy = 'monthly';

        $activeSubscriptions = $this->getActiveSubscriptions(ReportCurrency::resolve($params['currency'] ?? null, true));
        $projections = [];
        $totalProjected = 0;
        $totalRenewals = 0;
        $byVariation = [];

        // Initialize grouped data structure
        $current = clone $startDate;
        while ($current <= $endDate) {
            $key = $groupBy === 'daily'
                ? $current->format('Y-m-d')
                : $current->format('Y-m');

            if (!isset($projections[$key])) {
                $projections[$key] = [
                    'group'            => $key,
                    'renewals_count'   => 0,
                    'projected_amount' => 0,
                ];
            }

            if ($groupBy === 'daily') {
                $current->addDay();
            } else {
                $current->addMonth();
            }
        }

        foreach ($activeSubscriptions as $subscription) {
            $renewals = $this->calculateRenewalsInPeriod(
                $subscription,
                $startDate,
                $endDate
            );

            $projectedAmount = $renewals * $subscription->recurring_total;
            $totalProjected += $projectedAmount;

            if ($renewals > 0) {
                $variationId = (int) $subscription->variation_id;
                if (!isset($byVariation[$variationId])) {
                    $byVariation[$variationId] = [
                        'variation_id'     => $variationId,
                        'product_id'       => (int) $subscription->product_id,
                        'product_title'    => (string) $subscription->item_name,
                        'variation_title'  => '',
                        'renewals_count'   => 0,
                        'projected_amount' => 0,
                    ];
                }
                $byVariation[$variationId]['renewals_count'] += $renewals;
                $byVariation[$variationId]['projected_amount'] += $projectedAmount;
            }

            // Get renewal dates for this subscription to group them
            $renewalDates = $this->getRenewalDatesInPeriod($subscription, $startDate, $endDate);

            foreach ($renewalDates as $renewalDate) {
                $groupKey = $groupBy === 'daily'
                    ? $renewalDate->format('Y-m-d')
                    : $renewalDate->format('Y-m');

                if (isset($projections[$groupKey])) {
                    $projections[$groupKey]['renewals_count']++;
                    $totalRenewals++;
                    $projections[$groupKey]['projected_amount'] += $subscription->recurring_total;
                }
            }
        }

        return [
            'totalProjected' => $totalProjected,
            'totalRenewals'  => $totalRenewals,
            'projections'    => array_values($projections),
            'period'         => [
                $startDate->format('Y-m-d H:i:s'),
                $endDate->format('Y-m-d H:i:s'),
            ],
            'groupBy' => $groupBy,
            'by_variation' => $this->futureRenewalsByVariation($byVariation),
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
     * Get all active subscriptions with billing info
     */
    private function getActiveSubscriptions($currency)
    {
        $query = ReportCurrency::subscriptionsTable(['currency' => $currency])
            ->select([
                'id',
                'product_id',
                'variation_id',
                'item_name',
                'recurring_total',
                'billing_interval',
                'next_billing_date',
                'status',
                'expire_at',
            ])
            ->whereIn('status', ['active', 'trialing']) // Active statuses
            ->where('bill_times', '<=', 0)
            ->whereNotNull('next_billing_date')
            ->where(function ($query) {
                $query->whereNull('expire_at')
                    ->orWhere('expire_at', '>', gmdate('Y-m-d H:i:s'));
            });

        // Projected renewal amounts are only summable within one currency
        return ReportCurrency::whereCurrencyColumn($query, 'currency', $currency)->get();
    }

    /**
     * Calculate how many renewals will occur for a subscription in the given period
     */
    private function calculateRenewalsInPeriod($subscription, $startDate, $endDate)
    {
        if (!$subscription->next_billing_date) {
            return 0;
        }

        $nextBilling = new DateTime($subscription->next_billing_date);
        $periodStart = new DateTime($startDate);
        $periodEnd = new DateTime($endDate);

        // If next billing is after the end period, no renewals
        if ($nextBilling > $periodEnd) {
            return 0;
        }

        $renewalCount = 0;
        $currentBilling = clone $nextBilling;
        $intervalDays = $this->getIntervalDays($subscription->billing_interval);

        if ($intervalDays < 1) { // unresolved interval would never advance the loop
            return 0;
        }

        while ($currentBilling <= $periodEnd) {
            if ($currentBilling >= $periodStart) {
                $renewalCount++;
            }
            $currentBilling->modify("+{$intervalDays} days");
        }

        return $renewalCount;
    }

    /**
     * Convert billing interval to days
     */
    private function getIntervalDays($interval)
    {
        return PaymentHelper::getIntervalDays($interval);
    }

    /**
     * Get actual renewal dates for a subscription within the period
     */
    private function getRenewalDatesInPeriod($subscription, $startDate, $endDate)
    {
        $renewalDates = [];

        if (!$subscription->next_billing_date) {
            return $renewalDates;
        }

        $nextBilling = new DateTime($subscription->next_billing_date);
        $periodStart = new DateTime($startDate);
        $periodEnd = new DateTime($endDate);

        if ($nextBilling > $periodEnd) {
            return $renewalDates;
        }

        $currentBilling = clone $nextBilling;
        $intervalDays = $this->getIntervalDays($subscription->billing_interval);

        if ($intervalDays < 1) { // unresolved interval would never advance the loop
            return $renewalDates;
        }

        while ($currentBilling <= $periodEnd) {
            if ($currentBilling >= $periodStart) {
                $renewalDates[] = clone $currentBilling;
            }
            $currentBilling->modify("+{$intervalDays} days");
        }

        return $renewalDates;
    }
}
