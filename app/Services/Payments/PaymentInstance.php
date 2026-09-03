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

    /**
     * Gateway-agnostic idempotency seed for the current charge attempt. The ONLY
     * seed source for gateway idempotency keys — full contract in
     * .claude/skills/coding-rules/payment-idempotency.md.
     *
     * Stable across duplicate submissions (transaction uuid survives draft-order
     * re-submission -> gateway dedupes), fresh after an observed failure
     * (payment_attempt is bumped when a FAILED transaction is re-submitted ->
     * retries are never blocked or answered with a cached gateway error).
     *
     * @return string Empty string when no charge transaction exists — callers
     *                must skip idempotency rather than share a static key.
     */
    public function getIdempotencySeed()
    {
        if (!$this->transaction || !$this->transaction->uuid) {
            return '';
        }

        $attempt = (int) Arr::get($this->transaction->meta ?: [], 'payment_attempt', 0);

        return $this->transaction->uuid . (int) $this->transaction->total .  ($attempt ? '_r' . $attempt : '');
    }

    public function getExtraAddonAmount()
    {
        if (empty($this->subscription)) {
            return 0;
        }

        $taxBehavior = (int) $this->order->tax_behavior;

        $extraItems = $this->order->order_items->filter(function ($item) {
            return $item->payment_type !== 'subscription' && $item->payment_type !== 'signup_fee' && $item->payment_type !== 'fee';
        });

        $extraAmount = 0;
        foreach ($extraItems as $item) {
            $extraAmount += (int) $item->line_total;

            if ($taxBehavior === 1) {
                $extraAmount += (int) $item->tax_amount;
            } elseif ($taxBehavior === 3) {
                $inclusive = (bool) Arr::get($item->line_meta, 'tax_config.inclusive', false);
                if (!$inclusive) {
                    $extraAmount += (int) $item->tax_amount;
                }
            }
        }

        // shipping for physical addons (digital sub + one-time physical items)
        // skip when subscription itself is physical — shipping already in recurring_total
        // if subscription is digital, but has physical addons, we need to add shipping for those addons, because shipping is not included in recurring_total for digital subscriptions
        $subscriptionItem = $this->order->order_items->filter(function ($item) {
            return $item->payment_type === 'subscription';
        })->first();
        $subscriptionIsPhysical = $subscriptionItem && $subscriptionItem->fulfillment_type === 'physical';

        if (!$subscriptionIsPhysical) {
            $shippingCharge = (int) $this->order->shipping_total;
            if ($shippingCharge) {
                $extraAmount += $shippingCharge;

                if ($taxBehavior === 1) {
                    $extraAmount += (int) $this->order->shipping_tax;
                } elseif ($taxBehavior === 3) {
                    $storeTaxBehavior = (int) $this->order->getMeta('store_tax_behavior', 1);
                    if ($storeTaxBehavior === 1) {
                        $extraAmount += (int) $this->order->shipping_tax;
                    }
                }
            }
        }

        return $extraAmount;
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

