<?php

namespace FluentCart\App\Modules\Subscriptions\Services;

use FluentCart\App\App;
use FluentCart\App\Events\Subscription\SubscriptionCanceled;
use FluentCart\App\Events\Subscription\SubscriptionEOT;
use FluentCart\App\Events\Subscription\SubscriptionPaused;
use FluentCart\App\Events\Subscription\SubscriptionPeriodSkipped;
use FluentCart\App\Events\Subscription\SubscriptionReactivated;
use FluentCart\App\Events\Subscription\SubscriptionRenewed;
use FluentCart\App\Events\Subscription\SubscriptionResumed;
use FluentCart\App\Events\Subscription\SubscriptionUpdated;
use FluentCart\App\Events\Subscription\SubscriptionValidityExpired;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTaxRate;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\StoreManagedRenewal\Services\RenewalService;
use WP_Error;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;

class SubscriptionService
{
    /**
     * Record a gateway-managed (automatic collection) renewal payment.
     *
     * Called from gateway recurring-charge webhooks — Stripe invoice.paid, PayPal IPN,
     * Mollie, Paddle, Authorize.Net. Creates the renewal child order ALREADY PAID
     * (payment_status = paid, total_paid = total) in a single insert.
     *
     * Because there is no pending → paid transition and syncOrderStatuses() is never
     * called on the new order, this path does NOT fire fluent_cart/renewal_paid. That
     * is intentional: both listeners on that hook (RenewalService::handleRenewalPaid,
     * SystemChargeService::cancelPendingCharge) are scoped to manual/system collection,
     * and for automatic subscriptions the gateway owns next_billing_date. The renewal is
     * announced here by dispatching SubscriptionRenewed instead — the one event that
     * covers both renewal paths. Anything that must react to every renewal regardless of
     * collection method belongs on SubscriptionRenewed, not on renewal_paid.
     *
     * Exception: when a pending/scheduled invoice already exists for this subscription,
     * this method delegates to recordManualRenewal() below, which DOES go through
     * syncOrderStatuses() and therefore does fire renewal_paid.
     *
     * @param array $transactionData
     * @param Subscription|null $subscriptionModel
     * @param array $subscriptionUpdateArgs
     * @return OrderTransaction|\WP_Error
     */
    public static function recordRenewalPayment($transactionData, $subscriptionModel = null, $subscriptionUpdateArgs = [])
    {
        if (!$subscriptionModel) {
            $subscriptionModel = Subscription::query()->find($transactionData['subscription_id']);
        }

        if (!$subscriptionModel) {
            return new \WP_Error('subscription_not_found', __('Subscription not found.', 'fluent-cart'));
        }

        $vendorTransactionId = $transactionData['vendor_charge_id'] ?? null;

        global $wpdb;
        $lockName = null;

        if ($vendorTransactionId) {
            $lockName = 'fc_webhook_' . $vendorTransactionId;
            $acquired = (bool) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $lockName));
            if (!$acquired) {
                return new \WP_Error('lock_failed', __('Duplicate webhook processing in progress.', 'fluent-cart'));
            }
            if (OrderTransaction::query()
                ->where('vendor_charge_id', $vendorTransactionId)
                ->where('status', '!=', Status::TRANSACTION_FAILED)
                ->exists()) {
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lockName));
                return new \WP_Error('transaction_exists', __('This transaction already exists for this subscription.', 'fluent-cart'));
            }
        }

        $parentOrder = $subscriptionModel->order;

        if (!$parentOrder) {
            return new \WP_Error('parent_order_not_found', __('Parent order not found for this subscription.', 'fluent-cart'));
        }

        // If a pending manual invoice exists for this subscription, process it instead of
        // creating a new renewal order. This handles the case where a manual renewal invoice
        // was paid via a gateway that converts the subscription to automatic (e.g. Stripe),
        // and the actual charge fires as a subscription_cycle webhook after a deferred period.
        $existingInvoice = Order::query()
            ->where('parent_id', $parentOrder->id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->whereIn('payment_status', [Status::PAYMENT_PENDING, Status::PAYMENT_SCHEDULED])
            ->first();

        if ($existingInvoice) {
            $existingTransaction = OrderTransaction::query()
                ->where('order_id', $existingInvoice->id)
                ->where(function ($query) use ($vendorTransactionId) {
                    $query->where('status', Status::TRANSACTION_PENDING)
                        ->orWhere(function ($query) use ($vendorTransactionId) {
                            // A failed row only stands in for the invoice if it's the same
                            // PaymentIntent being retried — otherwise it's an unrelated attempt.
                            $query->where('status', Status::TRANSACTION_FAILED)
                                ->where('vendor_charge_id', $vendorTransactionId);
                        });
                })
                ->orderBy('id', 'DESC')
                ->first();

            if ($existingTransaction) {
                // Gateways that know the exact remote charge time pass it as
                // meta.settled_at; carry it onto the pending invoice's transaction
                // (empty-only, same contract as the model hook's fallback stamp).
                $settledAt = Arr::get($transactionData, 'meta.settled_at');
                if ($settledAt && empty($existingTransaction->meta['settled_at'])) {
                    $existingTransaction->meta = array_merge($existingTransaction->meta, [
                        'settled_at' => $settledAt
                    ]);
                }

                $transactionUpdateData = array_filter([
                    'total'               => $transactionData['total'] ?? $existingTransaction->total,
                    'status'              => Status::TRANSACTION_SUCCEEDED,
                    'payment_method'      => $transactionData['payment_method'] ?? $existingTransaction->payment_method,
                    'vendor_charge_id'    => $transactionData['vendor_charge_id'] ?? null,
                    'card_last_4'         => $transactionData['card_last_4'] ?? '',
                    'card_brand'          => $transactionData['card_brand'] ?? '',
                    'payment_method_type' => $transactionData['payment_method_type'] ?? '',
                ]);
                $existingTransaction->update($transactionUpdateData);
                $existingTransaction = OrderTransaction::query()->find($existingTransaction->id);

                $billingInfo = $subscriptionModel->getMeta('active_payment_method', []) ?: [];

                static::recordManualRenewal($subscriptionModel, $existingTransaction, [
                    'billing_info'      => $billingInfo,
                    'subscription_args' => $subscriptionUpdateArgs,
                ]);

                if ($lockName) {
                    $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lockName));
                }

                return $existingTransaction;
            }
        }

        $transactionDefaults = [
            'order_id' => $parentOrder->id,
            'subscription_id' => $subscriptionModel->id,
            'order_type' => Status::ORDER_TYPE_RENEWAL,
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'payment_method' => $subscriptionModel->current_payment_method,
            'payment_mode' => $parentOrder->mode,
            'status' => Status::TRANSACTION_SUCCEEDED,
            'currency' => $parentOrder->currency,
            'total' => $subscriptionModel->recurring_total,
            'meta' => Arr::get($transactionData, 'meta', [])
        ];

        $transactionData = wp_parse_args($transactionData, $transactionDefaults);

        $createdAt = Arr::get($transactionData, 'created_at', DateTime::now()->format('Y-m-d H:i:s'));

        // Let's create the order item first
        $variation = $subscriptionModel->variation;
        $product = $subscriptionModel->product;

        $parentOrderItem = OrderItem::query()
            ->where('order_id', $parentOrder->id)
            ->where('payment_type', Status::ORDER_TYPE_SUBSCRIPTION)
            ->first();

        $taxTotal = Arr::get($transactionData, 'tax_total', 0);
        if (!$taxTotal && $subscriptionModel->recurring_tax_total) {
            $taxTotal = $subscriptionModel->recurring_tax_total;
        }
        
        // A subscription item may be inclusive even when the parent order is mixed (behavior=3).
        // Check the per-item line_meta to determine the actual inclusion for this item.
        $isItemInclusive = $parentOrder->tax_behavior === 2
            || ($parentOrder->tax_behavior === 3 && $parentOrderItem !== null
                && (bool) Arr::get((array) $parentOrderItem->line_meta, 'tax_config.inclusive', false));

        if (!$taxTotal && $isItemInclusive && $parentOrderItem) {
            $taxTotal = (int) Arr::get($parentOrderItem->other_info, 'recurring_tax', 0);
        }

        $subtotal = $transactionData['total'];
        if ($taxTotal) {
            $subtotal = $transactionData['total'] - $taxTotal;
        }

        $orderItem = [
            'post_id' => $subscriptionModel->product_id,
            'object_id' => $subscriptionModel->variation_id,
            'payment_type' => Status::ORDER_TYPE_SUBSCRIPTION,
            'post_title' => $product && $product->post_title ? $product->post_title : $subscriptionModel->item_name,
            'title' => $product && $variation ? $variation->variation_title : '',
            'quantity' => 1,
            'fulfillment_type' => $parentOrderItem ? $parentOrderItem->fulfillment_type : 'digital',
            'unit_price' => $subtotal,
            'subtotal' => $subtotal,
            'tax_amount' => $taxTotal,
            'line_total' => $transactionData['total'],
            'line_meta' => [],
            'other_info' => []
        ];

        $bundleItemIds = Arr::get($parentOrderItem->line_meta, 'bundle_item_ids', []);

        $isBundleOrder = false;
        if ($bundleItemIds) {
            $isBundleOrder = true;
            $orderItem['line_meta'] = array_merge(
                $orderItem['line_meta'],
                [
                    'bundle_item_ids' => $bundleItemIds
                ]
            );
        }

        $fulfillmentType = $orderItem['fulfillment_type'];

        $wpdb->query('START TRANSACTION');

        // Let's create the order first
        $childOrderData = [
            'parent_id' => $parentOrder->id,
            'fulfillment_type' => $fulfillmentType,
            'status' => $fulfillmentType === 'physical' ? Status::ORDER_PROCESSING : Status::ORDER_COMPLETED,
            'type' => Status::ORDER_TYPE_RENEWAL,
            'mode' => $transactionData['payment_mode'],
            'shipping_status' => $fulfillmentType === 'physical' ? Status::SHIPPING_UNSHIPPED : '',
            'customer_id' => $subscriptionModel->customer_id,
            'payment_method' => $transactionData['payment_method'],
            'payment_status' => $transactionData['status'] === Status::TRANSACTION_SUCCEEDED ? Status::PAYMENT_PAID : Status::PAYMENT_PENDING,
            'currency' => $transactionData['currency'],
            'tax_behavior' => $parentOrder->tax_behavior,
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total_amount' => $transactionData['total'],
            'total_paid' => $transactionData['status'] === Status::TRANSACTION_SUCCEEDED ? $transactionData['total'] : 0,
            'completed_at' => DateTime::now()->format('Y-m-d H:i:s'),
            'created_at' => $createdAt,
            'config' => []
        ];

        try {
        $childOrder = Order::query()->create($childOrderData);

        if (!$childOrder) {
            throw new \RuntimeException(__('Failed to create child order for the subscription renewal.', 'fluent-cart'));
        }

        $billingAddress = $parentOrder->billing_address;
        $shippingAddress = $parentOrder->shipping_address;

        $customer = $parentOrder->customer;

        $fullName = '';
        $email = '';
        $firstName = '';
        $lastName = '';
        if ($customer) {
            $fullName = $customer->first_name . ' ' . $customer->last_name;
            $email = $customer->email;
            $firstName = $customer->first_name;
            $lastName = $customer->last_name;
        }

        $billingAddressData = $billingAddress ? [
            'type' => 'billing',
            'full_name' => $fullName,
            'address_1' => $billingAddress->address_1,
            'address_2' => $billingAddress->address_2,
            'city' => $billingAddress->city,
            'state' => $billingAddress->state,
            'postcode' => $billingAddress->postcode,
            'country' => $billingAddress->country,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName
        ] : [];

        $shippingAddressData = $shippingAddress ? [
            'type' => 'shipping',
            'full_name' => $fullName,
            'address_1' => $shippingAddress->address_1,
            'address_2' => $shippingAddress->address_2,
            'city' => $shippingAddress->city,
            'state' => $shippingAddress->state,
            'postcode' => $shippingAddress->postcode,
            'country' => $shippingAddress->country,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName
        ] : [];

        \FluentCart\App\Helpers\AddressHelper::insertOrderAddresses(
            $childOrder->id,
            $billingAddressData,
            $shippingAddressData
        );

        \FluentCart\App\Helpers\AddressHelper::copyOrderAddressMeta($childOrder->id, 'billing', $billingAddress);
        \FluentCart\App\Helpers\AddressHelper::copyOrderAddressMeta($childOrder->id, 'shipping', $shippingAddress);

        // Copy tax ID meta from parent order if exists
        $parentTaxId = $parentOrder->getMeta('tax_id', '');
        if ($parentTaxId) {
            $childOrder->updateMeta('tax_id', $parentTaxId);
        }

        // Copy order tax rates from parent order
        $parentTaxRates = $parentOrder->orderTaxRates;
        foreach ($parentTaxRates as $taxRate) {
            OrderTaxRate::query()->create([
                'order_id'    => $childOrder->id,
                'tax_rate_id' => $taxRate->tax_rate_id,
                'shipping_tax' => $taxRate->shipping_tax,
                'order_tax'   => $taxRate->order_tax,
                'total_tax'   => $taxRate->total_tax,
                'meta'        => $taxRate->meta,
            ]);
        }

        //  Create Order Item
        $orderItem['order_id'] = $childOrder->id;
        $orderItem['created_at'] = $createdAt;
        OrderItem::query()->create($orderItem);

        // let's create the transaction
        $transactionData['order_id'] = $childOrder->id;

        $createdTransaction = OrderTransaction::query()->create($transactionData);

        $subscriptionModel = self::syncSubscriptionStates($subscriptionModel, $subscriptionUpdateArgs);

        $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($lockName) {
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lockName));
            }
            return new \WP_Error('renewal_failed', $e->getMessage());
        }

        (new SubscriptionRenewed($subscriptionModel, $childOrder, $parentOrder, $childOrder->customer))->dispatch();

        return $createdTransaction;
    }

    /**
     * @param $subscriptionModel
     * @param $subscriptionUpdateArgs
     *      - next_billing_date - You must provide this if you want to update the next billing date.
     * *    - Accepts all other filliable attributes of the Subscription model.
     * @return mixed
     */
    public static function syncSubscriptionStates(Subscription $subscriptionModel, $subscriptionUpdateArgs = [], $expectedStatus = null)
    {
        $billsCount = $subscriptionModel->calculateBillCount();

        $subscriptionUpdateArgs['bill_count'] = $billsCount;
        $billTimes = $subscriptionModel->bill_times;
        $oldStatus = $subscriptionModel->status;

        $subscriptionUpdateArgs['bill_count'] = $billsCount;
        $isEot = $billTimes > 0 && $billsCount >= $billTimes;

        if ($isEot) {
            $subscriptionUpdateArgs['status'] = 'completed';
            $subscriptionUpdateArgs['next_billing_date'] = NULL;
            $subscriptionUpdateArgs['canceled_at'] = NULL;
        } else if (!$subscriptionModel->next_billing_date && empty($subscriptionUpdateArgs['next_billing_date'])) {
            $subscriptionUpdateArgs['next_billing_date'] = $subscriptionModel->guessNextBillingDate();
        }

        if (Arr::get($subscriptionUpdateArgs, 'status') === Status::SUBSCRIPTION_ACTIVE) {
            $subscriptionUpdateArgs['recurring_total'] = Arr::get($subscriptionUpdateArgs, 'recurring_total', $subscriptionModel->recurring_total);
        }

        $givenSubscriptionStatus = Arr::get($subscriptionUpdateArgs, 'status');
        if ($givenSubscriptionStatus === Status::SUBSCRIPTION_CANCELED && empty($subscriptionUpdateArgs['canceled_at'])) {
            $subscriptionUpdateArgs['canceled_at'] = gmdate('Y-m-d H:i:s');
        }

        $subscriptionModel->fill($subscriptionUpdateArgs);
        $dirtyData = $subscriptionModel->getDirty();

        if ($expectedStatus !== null) {
            // Compare-and-swap: only write if the row still holds the expected status, so a
            // concurrent transition (e.g. a renewal payment reactivating a past_due row) is
            // never clobbered. On a lost race, skip all side effects below.
            $writable = $dirtyData;
            unset($writable['meta']);

            $affected = Subscription::query()
                ->where('id', $subscriptionModel->id)
                ->where('status', $expectedStatus)
                ->update($writable);

            if (!$affected) {
                return null;
            }

            $subscriptionModel->syncOriginal();
        } else {
            $subscriptionModel->save();
        }

        $meta = array_filter(Arr::get($subscriptionUpdateArgs, 'meta', []));

        foreach ($meta as $key => $value) {
            $subscriptionModel->updateMeta($key, $value);
        }

        // The gateway on file just changed (a renewal invoice paid through a different
        // gateway, an admin edit). `system` is only meaningful while that gateway can
        // token-charge, so re-derive it — otherwise the subscription keeps claiming
        // auto-charge against a gateway that will refuse every attempt.
        if (isset($dirtyData['current_payment_method'])) {
            SystemChargeService::reconcileGatewayCapability($subscriptionModel);
        }

        // validity_expired_at should only exist when status IS expired
        if ($subscriptionModel->status !== Status::SUBSCRIPTION_EXPIRED) {
            $subscriptionModel->deleteMeta('validity_expired_at');
        }

        if ($oldStatus === $subscriptionModel->status) {
            if ($dirtyData) {
                do_action('fluent_cart/subscription/data_updated', [
                    'subscription' => $subscriptionModel,
                    'updated_data' => $dirtyData
                ]);
            }

            return $subscriptionModel; // No change in status
        }

        if ($isEot) {
            (new SubscriptionEOT($subscriptionModel, $subscriptionModel->order))->dispatch();
        }

        do_action('fluent_cart/payments/subscription_status_changed', [
            'subscription' => $subscriptionModel,
            'order' => $subscriptionModel->order,
            'customer' => $subscriptionModel->customer,
            'old_status' => $oldStatus,
            'new_status' => $subscriptionModel->status
        ]);

        /**
         * lists of hooks for this action
         * fluent_cart/payments/subscription_canceled
         * fluent_cart/payments/subscription_active
         * fluent_cart/payments/subscription_paused
         * fluent_cart/payments/subscription_expired
         * fluent_cart/payments/subscription_failing
         * fluent_cart/payments/subscription_expiring
         * fluent_cart/payments/subscription_completed
         **/
        do_action('fluent_cart/payments/subscription_' . $subscriptionModel->status, [
            'subscription' => $subscriptionModel,
            'order' => $subscriptionModel->order,
            'customer' => $subscriptionModel->customer,
            'old_status' => $oldStatus,
            'new_status' => $subscriptionModel->status
        ]);

        // Gateway-originated cancel (webhook) reaches only the raw status bus above;
        // route it through the chokepoint so void + native event fire like every
        // other path. The old_status === status early return keeps this once-only.
        if ($subscriptionModel->status === Status::SUBSCRIPTION_CANCELED) {
            self::finalizeCancellation(
                $subscriptionModel,
                Arr::get($subscriptionUpdateArgs, 'reason', __('Canceled at gateway', 'fluent-cart'))
            );
        }

        // note: we needed this event, currently being used in integrations
        if ($subscriptionModel->status === Status::SUBSCRIPTION_EXPIRED) {
            $subscriptionModel->updateMeta('validity_expired_at', DateTime::now()->format('Y-m-d H:i:s'));
            (new SubscriptionValidityExpired($subscriptionModel,$subscriptionModel->order,$subscriptionModel->customer))->dispatch();
        }

        if ($subscriptionModel->status === Status::SUBSCRIPTION_ACTIVE &&
            in_array($oldStatus, [Status::SUBSCRIPTION_CANCELED, Status::SUBSCRIPTION_EXPIRED])) {
            (new SubscriptionReactivated($subscriptionModel, $subscriptionModel->order, $subscriptionModel->customer, $oldStatus))->dispatch();
        }

        if ($subscriptionModel->status === Status::SUBSCRIPTION_PAUSED && $oldStatus === Status::SUBSCRIPTION_ACTIVE) {
            self::dispatchStatusEvent($subscriptionModel, 'paused', ['old_status' => $oldStatus]);
        }

        if ($subscriptionModel->status === Status::SUBSCRIPTION_ACTIVE && $oldStatus === Status::SUBSCRIPTION_PAUSED) {
            self::dispatchStatusEvent($subscriptionModel, 'resumed', ['old_status' => $oldStatus]);
        }

        return $subscriptionModel;
    }


    /**
     *
     * Use this method when you are reactivating a expired subscription manually by creating order, transaction etc.
     * Make sure you already handle your transaction statuses!
     *
     * @param \FluentCart\App\Models\Subscription $subscriptionModel
     * @param \FluentCart\App\Models\OrderTransaction $transaction
     * @param $args
     * @return mixed
     */
    public static function recordManualRenewal(Subscription $subscriptionModel, OrderTransaction $transaction, $args = [])
    {
        $renewalOrder = $transaction->order;

        // payment_status and total_paid are deliberately NOT set here — every caller has
        // already marked the transaction succeeded, and syncOrderStatuses() below derives
        // both from the transactions and claims the pending → paid transition atomically.
        // Pre-setting them destroyed that transition, which (a) suppressed
        // fluent_cart/renewal_paid, so RenewalService::handleRenewalPaid() never
        // advanced next_billing_date (the customer was re-invoiced forever), and
        // (b) bypassed the atomic claim that stops a webhook and a browser confirmation
        // from both processing the same renewal payment.
        $orderUpdateData = [
            'status' => $renewalOrder->fulfillment_type === 'physical' ? Status::ORDER_PROCESSING : Status::ORDER_COMPLETED,
            'type' => Status::ORDER_TYPE_RENEWAL,
            'payment_method' => $transaction->payment_method,
            'completed_at' => DateTime::now()->format('Y-m-d H:i:s')
        ];

        $renewalOrder->fill($orderUpdateData);
        $renewalOrder->save();

        if ($billingInfo = Arr::get($args, 'billing_info', [])) {
            $subscriptionModel->updateMeta('active_payment_method', $billingInfo);
        }

        $updateData = wp_parse_args(Arr::get($args, 'subscription_args', []), [
            'status' => Status::SUBSCRIPTION_ACTIVE,
            'current_payment_method' => $transaction->payment_method,
        ]);

        $subscriptionModel = self::syncSubscriptionStates($subscriptionModel, $updateData);

        (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);

        // Single-event contract for renewal processing — exactly one owner per
        // subscription type, so SubscriptionRenewed fires exactly once:
        //
        //   store-billed (manual/system) → RenewalService::handleRenewalPaid(),
        //       reached through the fluent_cart/renewal_paid hook that
        //       syncOrderStatuses() fires above. It advances next_billing_date,
        //       derives bill_count / EOT, and dispatches the event.
        //   gateway-billed (automatic)   → here. The invoice engine does not handle
        //       these, so this is their only dispatch point.
        //
        // Keyed on the collection method rather than on the `renewal_processed`
        // marker: handleRenewalPaid() stamps that marker before its EOT early-return,
        // so a marker check would swallow the event on a final installment.
        if ($transaction->total > 0 && !$subscriptionModel->usesRenewalEngine()) {
            (new SubscriptionRenewed($subscriptionModel, $renewalOrder, $subscriptionModel->order, $renewalOrder->customer))->dispatch();
        }

        return $subscriptionModel;
    }

    /**
     * Single dispatch point for subscription lifecycle status events.
     *
     * Every confirmed transition — manual local update, gateway sync response, or
     * gateway webhook/confirmation — routes through here so the first-class event
     * (and the hook it fires) happens exactly once, whatever path caused the change.
     *
     * @param Subscription $subscription
     * @param string $event One of: paused, resumed, updated, period_skipped
     * @param array $context order, customer, old_status, reason, updates, changes,
     *                       old_next_billing_date, new_next_billing_date
     * @return void
     */
    public static function dispatchStatusEvent(Subscription $subscription, string $event, array $context = [])
    {
        $order     = Arr::get($context, 'order') ?: $subscription->order;
        $customer  = Arr::get($context, 'customer') ?: ($order ? $order->customer : null);
        $oldStatus = Arr::get($context, 'old_status');
        $reason    = (string) Arr::get($context, 'reason', '');

        switch ($event) {
            case 'paused':
                (new SubscriptionPaused($subscription, $order, $customer, $oldStatus, $reason))->dispatch();
                break;
            case 'resumed':
                (new SubscriptionResumed($subscription, $order, $customer, $oldStatus, $reason))->dispatch();
                break;
            case 'updated':
                (new SubscriptionUpdated($subscription, $order, $customer, Arr::get($context, 'updates', []), Arr::get($context, 'changes', [])))->dispatch();
                break;
            case 'period_skipped':
                (new SubscriptionPeriodSkipped($subscription, $order, $customer, Arr::get($context, 'old_next_billing_date'), Arr::get($context, 'new_next_billing_date')))->dispatch();
                break;
        }
    }

    /**
     * Pause a subscription
     *
     * For manual subscriptions, this just updates the local status.
     * For automatic subscriptions, delegates to the gateway.
     *
     * @param Subscription $subscription
     * @param string $reason
     * @return true|\WP_Error
     */
    public static function pauseSubscription(Subscription $subscription, $reason = '')
    {
        if (!$subscription->canPause()) {
            return new \WP_Error(
                'cannot_pause',
                __('This subscription cannot be paused.', 'fluent-cart')
            );
        }

        // Store-billed (manual/system) subscriptions: local status update.
        if ($subscription->usesRenewalEngine()) {
            $oldStatus = $subscription->status;
            $subscription->status = Status::SUBSCRIPTION_PAUSED;
            $subscription->save();

            self::voidPendingRenewals(
                $subscription,
                'Subscription paused; open renewal order voided.'
            );

            $subscription->addLog(
                'Subscription paused',
                $reason ?: __('Subscription paused manually', 'fluent-cart'),
                'info'
            );

            // Fires fluent_cart/subscription_paused once, with the original
            // subscription/reason keys plus order/customer/old_status.
            self::dispatchStatusEvent($subscription, 'paused', [
                'old_status' => $oldStatus,
                'reason'     => $reason,
            ]);

            return true;
        }

        // Automatic subscriptions: delegate to gateway
        $gateway = App::gateway($subscription->current_payment_method);

        if (!$gateway || !in_array('pause_subscription', $gateway->supportedFeatures)) {
            return new \WP_Error(
                'unsupported_pause',
                __('Current payment method does not support pausing.', 'fluent-cart')
            );
        }

        if (method_exists($gateway->subscriptions, 'pause')) {
            return $gateway->subscriptions->pause($subscription, $reason);
        }

        return new \WP_Error(
            'unsupported_pause',
            __('Current payment method does not support pausing.', 'fluent-cart')
        );
    }

    /**
     * Resume a paused subscription
     *
     * For manual subscriptions, this updates status back to active.
     * For automatic subscriptions, delegates to the gateway.
     *
     * @param Subscription $subscription
     * @param string $reason
     * @return true|\WP_Error
     */
    public static function resumeSubscription(Subscription $subscription, $reason = '')
    {
        if (!$subscription->canResume()) {
            return new \WP_Error(
                'cannot_resume',
                __('This subscription cannot be resumed.', 'fluent-cart')
            );
        }

        // Store-billed (manual/system) subscriptions: local status update.
        if ($subscription->usesRenewalEngine()) {
            $oldStatus = $subscription->status;
            $subscription->status = Status::SUBSCRIPTION_ACTIVE;
            $subscription->save();

            SystemChargeService::restoreScheduledChargesForSubscription($subscription);

            $subscription->addLog(
                'Subscription resumed',
                $reason ?: __('Subscription resumed manually', 'fluent-cart'),
                'info'
            );

            // Fires fluent_cart/subscription_resumed once, with the original
            // subscription/reason keys plus order/customer/old_status.
            self::dispatchStatusEvent($subscription, 'resumed', [
                'old_status' => $oldStatus,
                'reason'     => $reason,
            ]);

            return true;
        }

        // Automatic subscriptions: delegate to gateway
        $gateway = App::gateway($subscription->current_payment_method);

        if (!$gateway || !in_array('resume_subscription', $gateway->supportedFeatures)) {
            return new \WP_Error(
                'unsupported_resume',
                __('Current payment method does not support resuming.', 'fluent-cart')
            );
        }

        if (method_exists($gateway->subscriptions, 'resume')) {
            return $gateway->subscriptions->resume($subscription, $reason);
        }

        return new \WP_Error(
            'unsupported_resume',
            __('Current payment method does not support resuming.', 'fluent-cart')
        );
    }

    /**
     * Reactivate a canceled/expired store-billed (manual or system) subscription locally —
     * no gateway/checkout involved. Voids any pending renewal invoice from the missed
     * period and advances next_billing_date so the overdue scanner doesn't immediately
     * re-flag it. Shared by the admin reactivate endpoint and the customer-dashboard
     * future-dated reactivation short-circuit.
     *
     * @param Subscription $subscription
     * @return Subscription|\WP_Error
     */
    public static function reactivateSubscriptionLocally(Subscription $subscription)
    {
        if (!$subscription->usesRenewalEngine()) {
            return new \WP_Error(
                'unsupported_local_reactivation',
                __('This subscription must be reactivated through its payment gateway.', 'fluent-cart')
            );
        }

        if (!$subscription->canReactivate()) {
            return new \WP_Error(
                'cannot_reactivate',
                __('This subscription cannot be reactivated.', 'fluent-cart')
            );
        }

        global $wpdb;

        $wpdb->query('START TRANSACTION');

        try {
            // Lock subscription before orders — skipNextPeriod locks in this order too;
            // diverging risks a deadlock on the same rows.
            $locked = Subscription::query()
                ->where('id', $subscription->id)
                ->lockForUpdate()
                ->first();

            // Re-check under the lock: canReactivate() ran on pre-lock state.
            $subscription->fill([
                'status'            => $locked ? $locked->status : $subscription->status,
                'next_billing_date' => $locked ? $locked->next_billing_date : $subscription->next_billing_date,
            ]);

            if (!$locked || !$subscription->canReactivate()) {
                $wpdb->query('ROLLBACK');
                return new \WP_Error(
                    'cannot_reactivate',
                    __('This subscription cannot be reactivated.', 'fluent-cart')
                );
            }

            $oldStatus = $subscription->status;

            $pendingOrderIds = Order::query()
                ->where('type', Status::ORDER_TYPE_RENEWAL)
                ->whereIn('payment_status', [Status::PAYMENT_PENDING, Status::PAYMENT_SCHEDULED])
                ->where('parent_id', $subscription->parent_order_id)
                ->pluck('id');

            if ($pendingOrderIds->isNotEmpty()) {
                // Re-assert payment_status at mutation time — a webhook may have paid
                // this order between the select above and this update.
                Order::query()
                    ->whereIn('id', $pendingOrderIds)
                    ->whereIn('payment_status', [Status::PAYMENT_PENDING, Status::PAYMENT_SCHEDULED])
                    ->update([
                        'status'         => Status::ORDER_CANCELED,
                        'payment_status' => Status::PAYMENT_FAILED,
                    ]);

                // Only fail transactions for orders actually voided above — a
                // paid-in-the-race order is excluded by the update's payment_status
                // predicate, so it must be excluded here too.
                $voidedOrderIds = Order::query()
                    ->whereIn('id', $pendingOrderIds)
                    ->where('status', Status::ORDER_CANCELED)
                    ->where('payment_status', Status::PAYMENT_FAILED)
                    ->pluck('id');

                if ($voidedOrderIds->isNotEmpty()) {
                    OrderTransaction::query()
                        ->whereIn('order_id', $voidedOrderIds)
                        ->where('status', Status::TRANSACTION_PENDING)
                        ->update(['status' => Status::TRANSACTION_FAILED]);
                }
            }

            // Overdue date must not survive reactivation, or it lands instantly due.
            if ($subscription->next_billing_date && strtotime($subscription->next_billing_date) <= time()) {
                $advancedDate = RenewalService::computeSkippedDate($subscription);
                if ($advancedDate) {
                    $subscription->fill(['next_billing_date' => $advancedDate]);
                }
            }

            // Not syncSubscriptionStates: its EOT check would flip status to completed.
            $reactivationData = [
                'status'      => Status::SUBSCRIPTION_ACTIVE,
                'canceled_at' => null,
            ];

            // Guess can itself be in the past (empty date + old last order) — advance past now.
            if (empty($subscription->next_billing_date)
                || strtotime($subscription->next_billing_date) <= time()) {
                $guessedTs = strtotime($subscription->guessNextBillingDate());
                $intervalDays = PaymentHelper::getIntervalDays($subscription->billing_interval);
                if ($intervalDays > 0) {
                    while ($guessedTs <= time()) {
                        $guessedTs += $intervalDays * DAY_IN_SECONDS;
                    }
                }
                $reactivationData['next_billing_date'] = gmdate('Y-m-d H:i:s', $guessedTs);
            }

            $subscription->fill($reactivationData)->save();

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');

            return new \WP_Error('reactivation_failed', $e->getMessage());
        }

        do_action('fluent_cart/payments/subscription_status_changed', [
            'subscription' => $subscription,
            'order'        => $subscription->order,
            'customer'     => $subscription->customer,
            'old_status'   => $oldStatus,
            'new_status'   => Status::SUBSCRIPTION_ACTIVE,
        ]);

        do_action('fluent_cart/payments/subscription_active', [
            'subscription' => $subscription,
            'order'        => $subscription->order,
            'customer'     => $subscription->customer,
            'old_status'   => $oldStatus,
            'new_status'   => Status::SUBSCRIPTION_ACTIVE,
        ]);

        if (in_array($oldStatus, [Status::SUBSCRIPTION_CANCELED, Status::SUBSCRIPTION_EXPIRED])) {
            (new SubscriptionReactivated($subscription, $subscription->order, $subscription->customer, $oldStatus))->dispatch();
        }

        do_action('fluent_cart/subscription/reactivated_locally', $subscription);

        return $subscription;
    }

    /**
     * Update subscription details (for manual subscriptions)
     *
     * Allowed fields for manual subscriptions:
     * - recurring_total: Update the next invoice/payment amount (in cents)
     * - bill_times: Update the number of billing cycles (0 = unlimited)
     * - billing_interval: Change billing frequency (daily, weekly, monthly, etc.)
     * - expire_at: Update expiration date
     * - trial_days: Update trial period
     * - next_billing_date: Update next billing date
     *
     * @param Subscription $subscription
     * @param array $data
     * @return true|\WP_Error
     */
    public static function updateSubscription(Subscription $subscription, array $data)
    {
        if (!$subscription->usesRenewalEngine()) {
            return new \WP_Error(
                'cannot_update_automatic',
                __('Only store-billed (manual or auto-charge) subscriptions can be updated directly.', 'fluent-cart')
            );
        }

        $allowedFields = [
            'recurring_total',
            'bill_times',
            'billing_interval',
            'next_billing_date',
            'status'
        ];

        $updates = [];
        $changes = [];

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowedFields)) {
                continue;
            }

            // Normalize recurring_total from frontend decimal to cents before comparison
            if ($key === 'recurring_total') {
                $value = (int) round(floatval($value) * 100);
            }

            $oldValue = $subscription->{$key};

            // DB attributes come back as strings — normalize numeric fields on both
            // sides or the strict compare below always reports a change.
            if (in_array($key, ['recurring_total', 'bill_times'])) {
                $oldValue = (int) $oldValue;
                $value = intval($value);
            }

            if ($oldValue === $value) {
                continue;
            }

            // Validate and convert numeric fields
            if (in_array($key, ['recurring_total', 'bill_times'])) {
                $value = $key === 'bill_times' ? intval($value) : $value; // recurring_total already converted above
                if ($key === 'bill_times' && $value < 0) {
                    return new \WP_Error(
                        'invalid_value',
                        __('bill_times cannot be negative.', 'fluent-cart')
                    );
                }
                if ($key === 'bill_times' && $value > 0 && $value < $subscription->bill_count) {
                    return new \WP_Error(
                        'invalid_value',
                        sprintf(
                            __('bill_times cannot be less than the number of payments already made (%d).', 'fluent-cart'),
                            $subscription->bill_count
                        )
                    );
                }
                if ($key === 'recurring_total' && $value < 0) {
                    return new \WP_Error(
                        'invalid_value',
                        __('recurring_total cannot be negative.', 'fluent-cart')
                    );
                }

                if ($key === 'recurring_total') {
                    // Keep recurring_amount in sync: total minus existing tax
                    $recurringAmount = $value - ($subscription->recurring_tax_total ?? 0);
                    if ($recurringAmount < 0) {
                        return new \WP_Error(
                            'invalid_value',
                            __('recurring_total cannot be less than the existing tax total.', 'fluent-cart')
                        );
                    }
                    $updates['recurring_amount'] = $recurringAmount;
                }
            }

            if ($key === 'billing_interval') {
                $validIntervals = apply_filters('fluent_cart/subscription/allowed_intervals', ['daily', 'weekly', 'monthly', 'quarterly', 'half_yearly', 'yearly'], [
                    'subscription' => $subscription,
                    'current_interval' => $subscription->billing_interval,
                    'new_interval' => $value
                ]);
                
                if (!in_array($value, $validIntervals)) {
                    return new \WP_Error(
                        'invalid_interval',
                        __('Invalid billing interval.', 'fluent-cart')
                    );
                }

                $incomingDate = isset($data['next_billing_date']) ? $data['next_billing_date'] : null;
                $adminChangedDate = $incomingDate && $incomingDate !== $subscription->next_billing_date;
                if (!$adminChangedDate && $subscription->next_billing_date) {
                    $oldInterval = $subscription->billing_interval;
                    $subscription->billing_interval = $value;
                    $updates['next_billing_date'] = $subscription->guessNextBillingDate(true);
                    $subscription->billing_interval = $oldInterval;
                }
            }

            if ($key === 'status') {
                $validStatuses = [
                    Status::SUBSCRIPTION_ACTIVE,
                    Status::SUBSCRIPTION_PAUSED,
                    Status::SUBSCRIPTION_TRIALING,
                    Status::SUBSCRIPTION_CANCELED,
                    Status::SUBSCRIPTION_EXPIRED,
                    Status::SUBSCRIPTION_COMPLETED,
                    Status::SUBSCRIPTION_PAST_DUE
                ];
                if (!in_array($value, $validStatuses)) {
                    return new \WP_Error(
                        'invalid_status',
                        __('Invalid subscription status.', 'fluent-cart')
                    );
                }

                // Sync companion fields; syncSubscriptionStates is intentionally
                // not used here because its EOT check recalculates bill_count and
                // can silently override the admin's explicit status choice.
                if ($value === Status::SUBSCRIPTION_CANCELED && empty($subscription->canceled_at)) {
                    $updates['canceled_at'] = gmdate('Y-m-d H:i:s');
                } elseif (in_array($value, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
                    $updates['canceled_at'] = null;
                    if (empty($subscription->next_billing_date) && empty($data['next_billing_date'])) {
                        $updates['next_billing_date'] = $subscription->guessNextBillingDate();
                    }
                } elseif (in_array($value, [Status::SUBSCRIPTION_COMPLETED, Status::SUBSCRIPTION_EXPIRED])) {
                    $updates['next_billing_date'] = null;
                }

                $terminalStatuses = [
                    Status::SUBSCRIPTION_CANCELED,
                    Status::SUBSCRIPTION_COMPLETED,
                    Status::SUBSCRIPTION_EXPIRED,
                ];
                if (in_array($value, $terminalStatuses)) {
                    self::voidPendingRenewals(
                        $subscription,
                        sprintf('Subscription marked as %s by admin.', $value)
                    );
                }

                // Same contract as pauseSubscription(): pausing retires the open
                // invoice (and its queued system charge) so nothing collects while
                // the subscription is paused.
                if ($value === Status::SUBSCRIPTION_PAUSED && $subscription->status !== Status::SUBSCRIPTION_PAUSED) {
                    self::voidPendingRenewals(
                        $subscription,
                        __('Subscription paused; open renewal order voided.', 'fluent-cart')
                    );
                }
            }

            $updates[$key] = $value;
            $logOld = $key === 'recurring_total' ? number_format($oldValue / 100, 2) : $oldValue;
            $logNew = $key === 'recurring_total' ? number_format($value / 100, 2) : $value;
            $changes[] = sprintf('%s: %s → %s', $key, $logOld, $logNew);
        }

        if (empty($updates)) {
            return new \WP_Error(
                'no_changes',
                __('No changes detected.', 'fluent-cart')
            );
        }

        $oldStatus = $subscription->status;

        foreach ($updates as $key => $value) {
            $subscription->{$key} = $value;
        }

        $subscription->save();

        // Sync pending renewal invoice if amount or due date changed
        $amountChanged      = isset($updates['recurring_total']);
        $dueDateChanged     = isset($updates['next_billing_date']);

        if ($amountChanged || $dueDateChanged) {
            $pendingInvoice = Order::query()
                ->where('parent_id', $subscription->parent_order_id)
                ->where('type', 'renewal')
                ->whereIn('payment_status', [Status::PAYMENT_PENDING, Status::PAYMENT_SCHEDULED])
                ->first();

            if ($pendingInvoice) {
                if ($amountChanged) {
                    $newTotal   = $subscription->recurring_total;
                    $newTax     = $subscription->recurring_tax_total;
                    $newSubtotal = $subscription->recurring_amount;

                    $pendingInvoice->subtotal     = $newSubtotal;
                    $pendingInvoice->tax_total    = $newTax;
                    $pendingInvoice->total_amount = $newTotal;
                    $pendingInvoice->save();

                    // Same unit_price convention as RenewalService::createRenewalOrders():
                    // gross per-unit for inclusive tax (behavior 2), net otherwise — the
                    // re-pay checkout feeds unit_price back as item_price, so a net value
                    // on an inclusive-tax invoice would drop the included tax.
                    $unitPriceBase = $pendingInvoice->tax_behavior == 2 ? $newTotal : $newSubtotal;

                    OrderItem::query()
                        ->where('order_id', $pendingInvoice->id)
                        ->update([
                            'subtotal'   => $newSubtotal,
                            'tax_amount' => $newTax,
                            'line_total' => $newTotal,
                            'unit_price' => $subscription->quantity > 1
                                ? (int) round($unitPriceBase / $subscription->quantity)
                                : $unitPriceBase,
                        ]);

                    OrderTransaction::query()
                        ->where('order_id', $pendingInvoice->id)
                        ->where('status', Status::TRANSACTION_PENDING)
                        ->update(['total' => $newTotal]);
                }

                if ($dueDateChanged) {
                    $pendingInvoice->updateMeta('due_date', $subscription->next_billing_date);

                    if ($subscription->isSystem()) {
                        SystemChargeService::unscheduleCharges($pendingInvoice);
                        SystemChargeService::scheduleCharge($pendingInvoice, $subscription);
                    }
                }

                $pendingInvoice->addLog(
                    'Renewal order updated by subscription edit',
                    'Pending renewal order synced after admin edited subscription details.',
                    'info'
                );
            }
        }

        $subscription->addLog(
            'Subscription updated',
            sprintf('Admin updated: %s', implode(', ', $changes)),
            'info'
        );

        // Fires fluent_cart/subscription_updated once, with the original
        // subscription/updates/changes keys plus order/customer.
        self::dispatchStatusEvent($subscription, 'updated', [
            'updates' => $updates,
            'changes' => $changes,
        ]);

        // Same contract as syncSubscriptionStates()'s no-status-change branch —
        // Pro's license-extension listener only reacts to this hook.
        do_action('fluent_cart/subscription/data_updated', [
            'subscription' => $subscription,
            'updated_data' => $updates
        ]);

        if ($oldStatus !== $subscription->status) {
            do_action('fluent_cart/payments/subscription_status_changed', [
                'subscription' => $subscription,
                'order'        => $subscription->order,
                'customer'     => $subscription->customer,
                'old_status'   => $oldStatus,
                'new_status'   => $subscription->status,
            ]);

            do_action('fluent_cart/payments/subscription_' . $subscription->status, [
                'subscription' => $subscription,
                'order'        => $subscription->order,
                'customer'     => $subscription->customer,
                'old_status'   => $oldStatus,
                'new_status'   => $subscription->status,
            ]);

            if ($subscription->status === Status::SUBSCRIPTION_EXPIRED) {
                $subscription->updateMeta('validity_expired_at', DateTime::now()->format('Y-m-d H:i:s'));
                (new SubscriptionValidityExpired($subscription, $subscription->order, $subscription->customer))->dispatch();
            }

            if ($subscription->status === Status::SUBSCRIPTION_COMPLETED) {
                (new SubscriptionEOT($subscription, $subscription->order))->dispatch();
            }

            // Event was the only missing cancel side-effect on the edit path; the
            // event now drives both email and reminder-clear.
            if ($subscription->status === Status::SUBSCRIPTION_CANCELED) {
                self::finalizeCancellation($subscription, __('Canceled by admin edit', 'fluent-cart'));
            }

            if ($subscription->status === Status::SUBSCRIPTION_ACTIVE &&
                in_array($oldStatus, [Status::SUBSCRIPTION_CANCELED, Status::SUBSCRIPTION_EXPIRED])) {
                (new SubscriptionReactivated($subscription, $subscription->order, $subscription->customer, $oldStatus))->dispatch();
            }

            // Same contract as pauseSubscription()/resumeSubscription(): fire the
            // dedicated pause/resume events, and re-queue any system charge that was
            // skipped while paused so a resumed subscription cannot strand a
            // payment_scheduled invoice.
            if ($subscription->status === Status::SUBSCRIPTION_PAUSED && $oldStatus === Status::SUBSCRIPTION_ACTIVE) {
                self::dispatchStatusEvent($subscription, 'paused', ['old_status' => $oldStatus]);
            }

            if ($subscription->status === Status::SUBSCRIPTION_ACTIVE && $oldStatus === Status::SUBSCRIPTION_PAUSED) {
                SystemChargeService::restoreScheduledChargesForSubscription($subscription);
                self::dispatchStatusEvent($subscription, 'resumed', ['old_status' => $oldStatus]);
            }
        }

        if (isset($updates['bill_times']) && !isset($updates['status'])) {
            $preSyncStatus = $subscription->status;
            self::syncSubscriptionStates($subscription, []);
            if ($subscription->status === Status::SUBSCRIPTION_COMPLETED
                && $preSyncStatus !== Status::SUBSCRIPTION_COMPLETED
            ) {
                self::voidPendingRenewals(
                    $subscription,
                    __('Subscription completed — billing times reached.', 'fluent-cart')
                );
            }
        }

        return true;
    }

    /**
     * Correct the gateway identifiers on an automatic subscription.
     *
     * Deliberately separate from updateSubscription(): nothing here touches
     * billing state, so no renewal is voided, no invoice re-synced and no
     * status event dispatched. Only the two identifier columns move.
     *
     * @param array $data vendor_subscription_id and/or vendor_customer_id
     * @return true|\WP_Error
     */
    public static function updateVendorIds(Subscription $subscription, array $data)
    {
        if (!$subscription->canEditVendorIds()) {
            return new \WP_Error(
                'cannot_edit_vendor_ids',
                __('Vendor IDs can only be edited on an active gateway-billed subscription.', 'fluent-cart')
            );
        }

        $updates = [];
        $changes = [];

        foreach (['vendor_subscription_id', 'vendor_customer_id'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = trim((string) $data[$field]);
            $oldValue = (string) $subscription->{$field};

            if ($oldValue === $value) {
                continue;
            }

            $updates[$field] = $value;
            $changes[] = sprintf(
                '%1$s: %2$s → %3$s',
                $field,
                $oldValue !== '' ? $oldValue : '(none)',
                $value !== '' ? $value : '(none)'
            );
        }

        if (empty($updates)) {
            return new \WP_Error(
                'no_changes',
                __('No changes detected.', 'fluent-cart')
            );
        }

        // fct_subscriptions indexes vendor_subscription_id but does not enforce
        // uniqueness, and every gateway IPN resolves its subscription through
        // that column — a duplicate would silently route webhooks into the wrong
        // row. A gateway never reissues an id inside its own account, so the
        // collision that matters is same-gateway.
        //
        // Claim it with one statement rather than SELECT-then-save: the anti-join
        // makes "nobody else holds this id" part of the UPDATE itself, so two
        // concurrent edits racing for the same id cannot both pass the check.
        // Zero affected rows means the other one won.
        if (!empty($updates['vendor_subscription_id'])) {
            if (!self::claimVendorSubscriptionId($subscription, $updates)) {
                return new \WP_Error(
                    'vendor_subscription_id_taken',
                    __('Another subscription on this payment method is already using this Vendor Subscription ID.', 'fluent-cart')
                );
            }

            $subscription->fill($updates)->syncOriginal();
        } else {
            $subscription->fill($updates)->save();
        }

        $subscription->addLog(
            'Vendor IDs updated',
            sprintf('Admin updated: %s', implode(', ', $changes)),
            'info'
        );

        return true;
    }

    /**
     * Write the vendor identifiers only if no other subscription on the same
     * payment method already holds the incoming vendor_subscription_id.
     *
     * The anti-join makes the check part of the write, so the check-then-write
     * window a separate SELECT would leave open does not exist.
     *
     * @return bool false when another row already holds the id
     */
    private static function claimVendorSubscriptionId(Subscription $subscription, array $updates): bool
    {
        $newId  = $updates['vendor_subscription_id'];
        $method = (string) $subscription->current_payment_method;

        $values = ['s.vendor_subscription_id' => $newId];

        if (array_key_exists('vendor_customer_id', $updates)) {
            $values['s.vendor_customer_id'] = $updates['vendor_customer_id'];
        }

        $values['s.updated_at'] = DateTime::gmtNow()->format('Y-m-d H:i:s');

        $affected = Subscription::query()
            ->getConnection()
            ->table('fct_subscriptions as s')
            ->leftJoin('fct_subscriptions as o', function ($join) use ($newId, $method) {
                $join->on('o.id', '<>', 's.id')
                    ->where('o.vendor_subscription_id', '=', $newId)
                    ->where('o.current_payment_method', '=', $method);
            })
            ->where('s.id', $subscription->id)
            ->whereNull('o.id')
            ->update($values);

        return (int) $affected > 0;
    }

    /**
     * Single cancellation chokepoint. Voids open renewals and dispatches the
     * SubscriptionCanceled event so email + reminder-clear (both listen on the
     * event hook) fire once, regardless of which cancel path ran.
     *
     * @param bool $dispatchEvent fire SubscriptionCanceled (email/reminder-clear/automations)
     */
    public static function finalizeCancellation(Subscription $subscription, string $reason = '', bool $dispatchEvent = true): void
    {
        self::voidPendingRenewals($subscription, $reason ?: __('Subscription canceled', 'fluent-cart'));

        if ($dispatchEvent) {
            (new SubscriptionCanceled($subscription, $subscription->order, $subscription->customer, $reason))->dispatch();
        }
    }

    /**
     * Void all pending renewal invoices for a subscription.
     * Sets order status to canceled and payment_status to failed.
     */
    public static function voidPendingRenewals(Subscription $subscription, string $reason = ''): void
    {
        $pendingInvoices = Order::query()
            ->where('parent_id', $subscription->parent_order_id)
            ->where('type', 'renewal')
            ->whereIn('payment_status', [Status::PAYMENT_PENDING, Status::PAYMENT_SCHEDULED])
            ->get();

        foreach ($pendingInvoices as $invoice) {
            if ($subscription->isSystem()) {
                SystemChargeService::unscheduleCharges($invoice);

                $chargeState = $subscription->getMeta('system_charge_state', []) ?: [];
                if ((int) Arr::get($chargeState, 'order_id') === (int) $invoice->id) {
                    $subscription->deleteMeta('system_charge_state');
                }
            }

            // Re-assert payment_status at mutation time — a webhook may have paid this
            // invoice between the select above and this update (same pattern as
            // reactivateSubscriptionLocally). A raced-paid invoice is left untouched.
            $voided = Order::query()
                ->where('id', $invoice->id)
                ->whereIn('payment_status', [Status::PAYMENT_PENDING, Status::PAYMENT_SCHEDULED])
                ->update([
                    'status'         => Status::ORDER_CANCELED,
                    'payment_status' => Status::PAYMENT_FAILED,
                ]);

            if (!$voided) {
                continue;
            }

            OrderTransaction::query()
                ->where('order_id', $invoice->id)
                ->where('status', Status::TRANSACTION_PENDING)
                ->update(['status' => Status::TRANSACTION_FAILED]);

            $invoice->addLog(
                'Renewal order voided',
                $reason ?: 'Renewal order voided automatically.',
                'info'
            );
        }
    }
}
