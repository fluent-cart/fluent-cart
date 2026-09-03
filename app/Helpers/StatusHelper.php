<?php

namespace FluentCart\App\Helpers;

use FluentCart\App\Events\Order\OrderPaid;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;


class StatusHelper
{
    protected $order;

    public function __construct($order = null)
    {
        $this->order = $order;
    }

    public function setOrder($order)
    {
        $this->order = $order;
        return $this;
    }

    public function changeOrderStatus($orderStatus, $paymentStatus, $title, $slug)
    {
        $oldStatus = $this->order->status;
        if ($orderStatus === $oldStatus) {
            return $this;
        }
        if ($orderStatus === Status::ORDER_COMPLETED) {
            $this->order->completed_at = DateTime::gmtNow();
        }
        if ($paymentStatus === Status::PAYMENT_REFUNDED) {
            $this->order->refunded_at = DateTime::gmtNow();
        }
        $this->order->status = $orderStatus;
        $this->order->payment_method = $slug;
        $this->order->payment_status = $paymentStatus;
        $this->order->payment_method_title = $title;
        $this->order->save();

        $actionActivity = [
            'title'   => __('Order status updated', 'fluent-cart'),
            'content' => sprintf(
                /* translators: 1: old status, 2: new status */
                __('Order status has been updated from %1$s to %2$s', 'fluent-cart'), $oldStatus, $orderStatus)
        ];

        (new OrderStatusUpdated($this->order, $oldStatus, $orderStatus, true, $actionActivity, 'order_status'))->dispatch();

        if (in_array($orderStatus, Status::getOrderSuccessStatuses())) {
            // Without this, the cart stays reusable, gets resurrected by the
            // logged-in user lookup and permanently blocks checkout with
            // "You have already completed this order."
            $this->completeRelatedCart();
        }

        return $this;
    }

    protected function completeRelatedCart()
    {
        $relatedCart = Cart::query()->where('order_id', $this->order->id)
            ->where('stage', '!=', 'completed')
            ->first();

        if (!$relatedCart) {
            return;
        }

        $relatedCart->stage = 'completed';
        $relatedCart->completed_at = DateTime::now()->format('Y-m-d H:i:s');
        $relatedCart->save();

        do_action('fluent_cart/cart_completed', [
            'cart'  => $relatedCart,
            'order' => $this->order,
        ]);
    }

    /**
     * Backfills payment_method_title when it was never stamped at creation.
     *
     * Only changeOrderStatus() (the COD-only path) writes payment_method_title.
     * Every other gateway settles via syncOrderStatuses(), which never touched
     * it, so the column stays empty for those orders.
     */
    protected function resolvePaymentMethodTitle()
    {
        $title = $this->order->payment_method_title;
        if ($title) {
            return $title;
        }

        $slug = $this->order->payment_method;
        if (!$slug || !class_exists(GatewayManager::class)) {
            return $title;
        }

        $gateway = GatewayManager::getInstance($slug);
        if (!$gateway || !method_exists($gateway, 'getMeta')) {
            return $title;
        }

        $resolvedTitle = (string) $gateway->getMeta('title');

        return $resolvedTitle !== '' ? $resolvedTitle : $title;
    }

    public function updateTotalPaid($amount)
    {
        $this->order->total_paid = intval($amount) + intval($this->order->total_paid);
        if ($this->order->total_paid >= $this->order->total_amount) {
            $this->order->payment_status = Status::PAYMENT_PAID;
            $this->triggerPaymentStatusActions($this->order, Status::PAYMENT_PAID);
        } else if ($this->order->total_paid < $this->order->total_amount && Status::PAYMENT_PARTIALLY_PAID !== $this->order->payment_status) {
            $this->order->payment_status = Status::PAYMENT_PARTIALLY_PAID;
        }
        $this->order->save();
        return $this;
    }

    public function triggerPaymentStatusActions($order, $paymentStatus)
    {
        // Initial orders only (payment / subscription). Renewal invoices are owned by
        // fluent_cart/renewal_paid — dispatching OrderPaid here would also fire the
        // async fluent_cart/order_paid_done on every renewal cycle, re-running the
        // new-order emails and integration feeds. Mirrors the same guard in
        // syncOrderStatuses().
        if (Status::PAYMENT_PAID === $paymentStatus && Status::ORDER_TYPE_RENEWAL !== $order->type) {
            $transaction = OrderTransaction::query()->where('order_id', $order->id)
                ->where('status', Status::TRANSACTION_SUCCEEDED)
                ->first();
            (new OrderPaid($order, $this->order->customer, $transaction))->dispatch();
        }

        // Trigger any other payment status actions based on the payment status
    }

    public function updateTransactionData($updateData = [], $transaction = null)
    {
        if ($transaction) {
            $transaction->update($updateData);
            return $this;
        }

        OrderTransaction::query()->where('order_id', $this->order->id)
            ->where('order_type', 'payment')
            ->update($updateData);

        return $this;
    }

    public function syncOrderStatuses($latestTransaction = null)
    {
        // Change the order status depends on payment paid
        // Change the paid total depends on the order total amount
        // Change the payment status depends on the order total paid

        $successStatuses = Status::getTransactionSuccessStatuses();

        $transactionPaidTotal = OrderTransaction::query()
            ->where('order_id', $this->order->id)
            ->whereIn('status', $successStatuses)
            ->sum('total');

        $refundedTotal = OrderTransaction::query()
            ->where('order_id', $this->order->id)
            ->where('status', Status::TRANSACTION_REFUNDED)
            ->sum('total');

        $isFullyPaid = $this->order->total_amount <= ($transactionPaidTotal - $refundedTotal);

        // total_paid stays gross for a MoR order (cover invariant — see
        // Order::netAmount()); net it out here so a full refund of the actually
        // captured amount resolves to "refunded" instead of being stuck at
        // "partially_refunded" on every later idempotent resync (e.g. a Paddle webhook
        // replay for the already-succeeded transaction).
        $netPaidTotal = $this->order->netAmount($transactionPaidTotal);

        $orderPaymentStatus = $this->order->payment_status;
        if ($isFullyPaid) {
            $orderPaymentStatus = Status::PAYMENT_PAID;
        } else if ($refundedTotal && $refundedTotal >= $netPaidTotal) {
            $orderPaymentStatus = Status::PAYMENT_REFUNDED;
        } else if ($refundedTotal) {
            $orderPaymentStatus = Status::PAYMENT_PARTIALLY_REFUNDED;
        }

        $orderStatus = $this->order->status;
        if (!in_array($orderStatus, Status::getOrderSuccessStatuses())) {
            if ($orderPaymentStatus == Status::PAYMENT_PAID) {
                $orderStatus = Status::ORDER_PROCESSING;
            }
        }

        $oldOrderStatus = $this->order->status;
        $oldPaymentStatus = $this->order->payment_status;

        $paymentMethodTitle = $this->resolvePaymentMethodTitle();

        if ($orderPaymentStatus === Status::PAYMENT_REFUNDED && !$this->order->refunded_at) {
            $this->order->refunded_at = DateTime::gmtNow();
        }

        $this->order->status = $orderStatus;
        $this->order->payment_status = $orderPaymentStatus;
        $this->order->total_paid = $transactionPaidTotal;
        $this->order->total_refund = $refundedTotal;
        $this->order->payment_method_title = $paymentMethodTitle;

        // When transitioning to PAID, use an atomic UPDATE to prevent concurrent requests
        // (e.g., payment gateway webhook + browser confirmation) from both processing
        // the same payment — which would dispatch OrderPaid twice, generating duplicate
        // invoice numbers and sending duplicate emails.
        // The WHERE condition ensures only one process can claim the transition.
        if ($orderPaymentStatus == Status::PAYMENT_PAID && $oldPaymentStatus != Status::PAYMENT_PAID) {
            $claimed = Order::query()
                ->where('id', $this->order->id)
                ->where(function ($q) {
                    $q->whereNull('payment_status')
                      ->orWhere('payment_status', '!=', Status::PAYMENT_PAID);
                })
                ->update([
                    'status'               => $orderStatus,
                    'payment_status'       => $orderPaymentStatus,
                    'total_paid'           => $transactionPaidTotal,
                    'total_refund'         => $refundedTotal,
                    'payment_method_title' => $paymentMethodTitle,
                ]);

            if (!$claimed) {
                // Another process already transitioned this order to paid
                $this->order = Order::query()->where('id', $this->order->id)->first();
                return $this->order;
            }

            // Refresh in-memory model to stay in sync with the DB after query-builder update
            $this->order = Order::query()->where('id', $this->order->id)->first();
        } else {
            $this->order->save();
        }

        // Store-managed renewal invoice paid. Reached by every payment path for an
        // invoice that was created unpaid (customer pays the invoice, system auto-charge
        // settles, admin mark-as-paid, gateway confirmation) — they all converge on
        // recordManualRenewal() → syncOrderStatuses(), and the pending → paid transition
        // below is what the store-managed renewal engine listens for.
        //
        // NOT fired for gateway-managed (automatic) renewals: those go through
        // SubscriptionService::recordRenewalPayment(), which creates the child order
        // already paid and never reaches here. Both listeners on this hook
        // (RenewalService::handleRenewalPaid, SystemChargeService::cancelPendingCharge)
        // are scoped to manual/system collection, so that is by design — but it does mean
        // this is not an "any renewal was paid" hook. Use SubscriptionRenewed for that.
        //
        // Scoped to renewal+paid so initial order flow is unaffected.
        if ($this->order->type === Status::ORDER_TYPE_RENEWAL
            && $oldPaymentStatus !== $this->order->payment_status
            && $this->order->payment_status === Status::PAYMENT_PAID
        ) {
            do_action('fluent_cart/renewal_paid', ['order' => $this->order]);
        }

        if (($this->order->type === 'renewal') || ($oldPaymentStatus != $this->order->payment_status && $this->order->payment_status == Status::PAYMENT_PAID)) {
            if (!$latestTransaction) {
                $latestTransaction = OrderTransaction::query()
                    ->where('order_id', $this->order->id)
                    ->orderBy('id', 'desc')
                    ->first();
            }

            $relatedCart = Cart::query()->where('order_id', $this->order->id)
                ->where('stage', '!=', 'completed')
                ->first();

            if ($relatedCart) {
                $relatedCart->stage = 'completed';
                $relatedCart->completed_at = DateTime::now()->format('Y-m-d H:i:s');
                $relatedCart->save();

                do_action('fluent_cart/cart_completed', [
                    'cart'  => $relatedCart,
                    'order' => $this->order,
                ]);

                $onSuccessActions = Arr::get($relatedCart->checkout_data, '__on_success_actions__', []);

                if ($onSuccessActions) {
                    foreach ($onSuccessActions as $onSuccessAction) {
                        $onSuccessAction = (string)$onSuccessAction;
                        if (has_action($onSuccessAction)) {
                            do_action($onSuccessAction, [
                                'cart'        => $relatedCart,
                                'order'       => $this->order,
                                'transaction' => $latestTransaction
                            ]);
                        }
                    }
                }
            }

            if ($this->order->type !== 'renewal' && $oldPaymentStatus != $this->order->payment_status && $this->order->payment_status == Status::PAYMENT_PAID) {
                (new OrderPaid($this->order, $this->order->customer, $latestTransaction))->dispatch();
            }  
        }

        if ($oldOrderStatus != $this->order->status) {
            $actionActivity = [
                'title'   => __('Order status updated', 'fluent-cart'),
                'content' => sprintf(
                    /* translators: 1: old status, 2: new status */
                    __('Order status has been updated from %1$s to %2$s', 'fluent-cart'), $oldOrderStatus, $this->order->status)
            ];
            (new OrderStatusUpdated($this->order, $oldOrderStatus, $this->order->status, true, $actionActivity, 'order_status'))->dispatch();
        }

        $autoCompleteDigitalOrder = apply_filters('fluent_cart/order_status/auto_complete_digital_order', true, [
            'order' => $this->order,
        ]);

        // Now if it's a digital product so we will make it auto completed
        if ($this->order->fulfillment_type == 'digital' && $autoCompleteDigitalOrder
            && !$refundedTotal && ($oldOrderStatus != $this->order->status)
            && ($this->order->status != Status::ORDER_COMPLETED)
        ) {
            $oldOrderStatus = $this->order->status;
            $this->order->status = Status::ORDER_COMPLETED;
            $this->order->completed_at = DateTime::gmtNow();
            $this->order->save();

            $actionActivity = [
                'title'   => __('Order status updated', 'fluent-cart'),
                'content' => sprintf(
                    /* translators: 1: old status, 2: new status */
                    __('Order status has been updated from %1$s to %2$s', 'fluent-cart'), $oldOrderStatus, $this->order->status)
            ];

            (new OrderStatusUpdated($this->order, $oldOrderStatus, $this->order->status, true, $actionActivity, 'order_status'))->dispatch();
        }

        $this->maybeActivateManualSubscription();

        return $this->order;
    }

    private function maybeActivateManualSubscription()
    {
        // Initial subscription activation only. Renewal payments are owned by
        // RenewalService::handleRenewalPaid (hooked on fluent_cart/renewal_paid),
        // which sets the cadence-preserving next_billing_date (anchored to due_date).
        // Running this on renewals would overwrite that with guessNextBillingDate()
        // (order created_at + interval), pulling the date earlier by the advance window
        // every cycle, and could flip a paused/canceled subscription back to active.
        if ($this->order->type !== 'subscription') {
            return;
        }

        if ($this->order->payment_status !== Status::PAYMENT_PAID) {
            return;
        }

        $subscription = Subscription::query()
            ->where('parent_order_id', $this->order->id)
            ->whereIn('collection_method', ['manual', 'system'])
            ->first();

        if (!$subscription) {
            return;
        }

        $oldStatus = $subscription->status;

        // Initial activation only: syncOrderStatuses can run again on an already-paid
        // parent order (admin "Sync statuses", webhook redelivery). Without this guard
        // a paused/canceled/completed subscription would be forced back to active and
        // its next_billing_date/trial window reset.
        if (!in_array($oldStatus, [Status::SUBSCRIPTION_PENDING, Status::SUBSCRIPTION_INTENDED])) {
            return;
        }

        $isTrialDaysSimulated = Arr::get($subscription->config, 'is_trial_days_simulated', 'no') === 'yes';
        $hasActualTrial = $subscription->trial_days > 0 && !$isTrialDaysSimulated;

        if ($hasActualTrial) {
            // Trial runs from activation, not order placement — a delayed payment
            // (COD, bank transfer) must not consume the trial before it starts.
            $trialEndsAt = gmdate('Y-m-d H:i:s', time() + ((int) $subscription->trial_days * DAY_IN_SECONDS));
            $updateData = [
                'status'            => Status::SUBSCRIPTION_TRIALING,
                'trial_ends_at'     => $trialEndsAt,
                'next_billing_date' => $trialEndsAt,
            ];
        } else {
            $updateData = [
                'status'            => Status::SUBSCRIPTION_ACTIVE,
                'next_billing_date' => $subscription->guessNextBillingDate(true),
            ];
        }

        $subscription = SubscriptionService::syncSubscriptionStates($subscription, $updateData);

        if (in_array($oldStatus, [Status::SUBSCRIPTION_PENDING, Status::SUBSCRIPTION_INTENDED])
            && in_array($subscription->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])
        ) {
            (new SubscriptionActivated($subscription, $this->order, $this->order->customer))->dispatch();
        }
    }
}
