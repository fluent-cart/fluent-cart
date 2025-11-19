<?php

namespace FluentCart\App\Services\Report\Concerns\Subscription;

use FluentCart\App\App;
use FluentCart\App\Services\DateTime\DateTime;

trait FutureRenewals
{
    public function getFutureRenewals(array $params)
    {
        $startDate = (new DateTime)->startOfDay();
        $endDate = (new DateTime)->addQuarter()->endOfDay();
        // $groupBy = $params['groupBy'] ?? 'daily'; // daily or monthly
        $groupBy = 'monthly';

        $activeSubscriptions = $this->getActiveSubscriptions();
        $projections = [];
        $totalProjected = 0;
        $totalRenewals = 0;

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
        ];
    }

    /**
     * Get all active subscriptions with billing info
     */
    private function getActiveSubscriptions()
    {
        return App::db()->table('fct_subscriptions')
            ->select([
                'id',
                'recurring_total',
                'billing_interval',
                'next_billing_date',
                'status',
                'expire_at',
                'bill_times',
                'bill_count',
            ])
            ->whereIn('status', ['active', 'trialing']) // Active statuses
            ->whereNotNull('next_billing_date')
            ->where(function ($query) {
                $query->whereNull('expire_at')
                    ->orWhere('expire_at', '>', gmdate('Y-m-d H:i:s'));
            })
            ->get();
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

        // Check if subscription has limited billing cycles
        if ($subscription->bill_times > 0) {
            $remainingBills = $subscription->bill_times - $subscription->bill_count;
            if ($remainingBills <= 0) {
                return 0;
            }
        }

        $renewalCount = 0;
        $currentBilling = clone $nextBilling;
        $intervalDays = $this->getIntervalDays($subscription->billing_interval);

        while ($currentBilling <= $periodEnd) {
            if ($currentBilling >= $periodStart) {
                $renewalCount++;

                // Check if we've reached the billing limit
                if ($subscription->bill_times > 0 &&
                    ($subscription->bill_count + $renewalCount) >= $subscription->bill_times) {
                    break;
                }
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
        $intervals = [
            'daily'     => 1,
            'weekly'    => 7,
            'monthly'   => 30,
            'quarterly' => 90,
            'yearly'    => 365,
            'biweekly'  => 14,
            'bimonthly' => 60,
        ];

        return $intervals[$interval] ?? 30; // Default to monthly
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

        if ($subscription->bill_times > 0) {
            $remainingBills = $subscription->bill_times - $subscription->bill_count;
            if ($remainingBills <= 0) {
                return $renewalDates;
            }
        }

        $currentBilling = clone $nextBilling;
        $intervalDays = $this->getIntervalDays($subscription->billing_interval);
        $renewalCount = 0;

        while ($currentBilling <= $periodEnd) {
            if ($currentBilling >= $periodStart) {
                $renewalDates[] = clone $currentBilling;
                $renewalCount++;

                if ($subscription->bill_times > 0 &&
                    ($subscription->bill_count + $renewalCount) >= $subscription->bill_times) {
                    break;
                }
            }
            $currentBilling->modify("+{$intervalDays} days");
        }

        return $renewalDates;
    }
}
