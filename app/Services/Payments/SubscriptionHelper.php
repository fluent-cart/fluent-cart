<?php

namespace FluentCart\App\Services\Payments;

use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

class SubscriptionHelper
{
    /*
     * @param $subscriptionModel
     * @return string|null
     *
     * */
    public static function getNextBillingDate(Subscription $subscriptionModel)
    {
        // only null case
        if ($subscriptionModel->status === Status::SUBSCRIPTION_COMPLETED || ($subscriptionModel->bill_times > 0 && $subscriptionModel->bill_count >= $subscriptionModel->bill_times)) {
            return null;
        }

        // assuming on expired we update the canceled_at, removes this comment when verified
        if ($subscriptionModel->status === Status::SUBSCRIPTION_CANCELED || $subscriptionModel->status === Status::SUBSCRIPTION_EXPIRED) {
            return $subscriptionModel->canceled_at;
        }

        // Trial handling
        if ($subscriptionModel->bill_count == 0 && !empty($subscriptionModel->trial_days)) {
            if (!empty($subscriptionModel->trial_ends_at)) {
                return $subscriptionModel->trial_ends_at;
            }
            return gmdate('Y-m-d H:i:s', strtotime($subscriptionModel->created_at . " +{$subscriptionModel->trial_days} days"));
        }

        if (!empty($subscriptionModel->next_billing_date) && strtotime($subscriptionModel->next_billing_date) > time()) {
            return $subscriptionModel->next_billing_date;
        }


        if ($subscriptionModel->bill_count == 0) {
            $baseDate = $subscriptionModel->created_at;

        } elseif (!empty($subscriptionModel->next_billing_date) && strtotime($subscriptionModel->next_billing_date) < time()) {
            $baseDate = $subscriptionModel->next_billing_date;
        } else {
            $baseDate = DateTime::gmtNow()->format('Y-m-d H:i:s');
        }

        switch (strtolower($subscriptionModel->billing_interval)) {
            case 'daily':
                $next = strtotime($baseDate . " +1 day");
                break;
            case 'weekly':
                $next = strtotime($baseDate . " +1 week");
                break;
            case 'monthly':
                $next = strtotime($baseDate . " +1 month");
                break;
            case 'quarterly':
                $next = strtotime($baseDate . " +3 months");
                break;
            case 'half_yearly':
                $next = strtotime($baseDate . " +6 months");
                break;
            case 'yearly':
                $next = strtotime($baseDate . " +1 year");
                break;
            default:
                $next = strtotime($baseDate . " +1 month"); // fallback
        }

        return gmdate('Y-m-d H:i:s', $next);
    }

    /*
     * @param $trialDays
     * @param $billTimes
     * @param $interval
     *
     * */
    public static function getSubscriptionCancelAtTimeStamp($trialDays, $billTimes, $interval)
    {
        if (!$billTimes && !$trialDays) {
            return null;
        }

        // Use the passed arguments instead of accessing non-existent $this->subscription
        if ($interval == 'daily') {
            $interval = 'day';
        }

        $interValMaps = [
            'day'     => 'days',
            'weekly'  => 'weeks',
            'monthly' => 'months',
            'yearly'  => 'years'
        ];

        if (isset($interValMaps[$interval]) && $billTimes > 0) {
            $interval = $interValMaps[$interval];
        }

        $timestamp = strtotime('+ ' . $billTimes . ' ' . $interval);

        // Add trial days if provided
        if ($trialDays > 0) {
            $timestamp = $timestamp + $trialDays * 24 * 60 * 60; // Add trial days in seconds
        }

        return $timestamp;
    }


    // can be used to catch 1 day trial loop-hole
    public static function checkTrailDaysLoopHole($subscription, $trialDays)
    {
        $billCount = Arr::get($subscription, 'bill_count');
        $billingInterval = Arr::get($subscription, 'billing_interval');
        $billingIntervalInDays = 0;
        switch ($billingInterval) {
            case 'monthly':
                $billingIntervalInDays = 30;
                break;
            case 'quarterly':
                $billingIntervalInDays = 90;
                break;
            case 'half_yearly':
                $billingIntervalInDays = 182;
                break;
            case 'yearly':
                $billingIntervalInDays = 365;
                break;
            case 'weekly':
                $billingIntervalInDays = 7;
                break;
            case 'daily':
                $billingIntervalInDays = 1;
                break;
        }

        // get the days from now to the created at date - original trial days,
        $daysSinceCreated = ceil(ceil((time() - strtotime($subscription->created_at)) / 86400)) - intval($subscription->trial_days);
        $expectedBillCount = floor($daysSinceCreated / $billingIntervalInDays);

        if ($expectedBillCount > $billCount) {
            $trialDays = 0;
        }

        return $trialDays;
    }

}
