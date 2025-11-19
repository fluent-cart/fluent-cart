<?php

namespace FluentCart\App\Services\Payments;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

class PaymentInstance
{

    /**
     * @var Order
     */
    public $order;

    public $transaction;

    public $subscription;

    public $paymentType;

    public function __construct(Order $order)
    {
        $this->order = $order;
        $this->setupData();
    }

    public function setupData()
    {
        $this->transaction = OrderTransaction::query()
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->where('order_id', $this->order->id)
            ->latest()
            ->first();

        $this->subscription = null;

        if ($this->order->type === 'subscription') {
            $this->subscription = Subscription::query()
                ->where('parent_order_id', $this->order->id)
                ->first();
        } else if ($this->order->type === Status::ORDER_TYPE_RENEWAL) {
            $this->subscription = Subscription::query()
                ->where('parent_order_id', $this->order->parent_id)
                ->first();
        }

    }

    public function setTransaction(OrderTransaction $transaction)
    {
        $this->transaction = $transaction;
        return $this;
    }

    public function getExtraAddonAmount()
    {
        return 0;
    }


    public function getSubscriptionCancelAtTimeStamp()
    {
        if (!$this->subscription) {
            return null;
        }
        
        $billTimes = $this->subscription->getRequiredBillTimes();

        if ($billTimes <= 0) {
            return null;
        }

        $interval = $this->subscription->billing_interval;


        if ($interval == 'daily') {
            $interval = 'day';
        }

        $interValMaps = [
            'day'         => 'days',
            'weekly'      => 'weeks',
            'monthly'     => 'months',
            'quarterly'   => 'months',
            'half_yearly' => 'months',
            'yearly'      => 'years'
        ];

        if (isset($interValMaps[$interval]) && $billTimes > 0) {
            $interval = $interValMaps[$interval];
            
            // Adjust billTimes for quarterly and half_yearly
            if ($this->subscription->billing_interval === 'quarterly') {
                $billTimes = $billTimes * 3; // Convert to months
            } elseif ($this->subscription->billing_interval === 'half_yearly') {
                $billTimes = $billTimes * 6; // Convert to months
            }
        }


        $timestamp = strtotime('+ ' . $billTimes . ' ' . $interval);

        if (!$this->subscription->bill_count && $this->subscription->trial_days) {
            $timestamp = $timestamp + $this->subscription->trial_days * 24 * 60 * 60; // Add trial days in seconds
        }

        return $timestamp;
    }
}

