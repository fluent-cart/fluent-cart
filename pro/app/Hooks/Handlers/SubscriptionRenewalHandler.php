<?php

namespace FluentCartPro\App\Hooks\Handlers;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;

class SubscriptionRenewalHandler
{
    public function register()
    {

        // url: /?fluent-cart=reactivate-subscription&subscription_hash=2e326b7585e71ee7e71ce92f08ab5f0d
        add_action('fluent_cart_action_reactivate-subscription', [$this, 'handleSubscriptionReactivationRedirect'], 10, 1);
        add_action('fluent_cart/subscription_reactivation_order_created', [$this, 'handleSubscriptionReactivationOrderCreated'], 10, 1);
        add_action('fluent_cart/subscription_renewal_cart_completed', [$this, 'handleSubscriptionRenewalCartCompleted'], 10, 1);

    }

    public function handleSubscriptionReactivationOrderCreated($data)
    {
        $cartModel = Arr::get($data, 'cart', null);
        $order = Arr::get($data, 'order', null);
        if (!$cartModel || !$order) {
            return;
        }

        $renewData = Arr::get($cartModel->checkout_data, 'renew_data', []);
        $subscriptionHash = Arr::get($renewData, 'subscription_hash', '');

        if (empty($subscriptionHash)) {
            return;
        }
        $subscription = Subscription::query()->where('uuid', $subscriptionHash)->first();

        if (!$subscription) {
            return;
        }

        $order->parent_id = $subscription->parent_order_id;
        $order->type = Status::ORDER_TYPE_RENEWAL;

        $config = $order->config;
        $config['old_vendor_subscription_id'] = Arr::get($renewData, 'old_vendor_subscription_id', '');
        $config['old_payment_method'] = Arr::get($renewData, 'old_payment_method', '');

        $order->config = $config;

        $order->save();

        $currentRenewalAmount = Arr::get($renewData, 'new_recurring_amount', 0);

        if ($order->tax_behavior == '1') {
            $currentRenewalAmount += Arr::get($cartModel->checkout_data, 'tax_data.tax_total', 0);
        }

        $subscriptionConfig = $subscription->config;

        if ($currentRenewalAmount != $subscription->recurring_total) {
            $subscriptionConfig['current_renewal_amount'] = $currentRenewalAmount;
        } else {
            unset($subscriptionConfig['current_renewal_amount']);
        }

        $subscriptionConfig['reactivation_order_id'] = $order->id;
        $subscription->config = $subscriptionConfig;
        $subscription->save();

        // Messy solution to remove the subscription items from the order
        Subscription::query()->where('parent_order_id', $order->id)->delete();

        OrderTransaction::query()->where('order_id', $order->id)
            ->update([
                'order_type' => Status::ORDER_TYPE_RENEWAL,
                'subscription_id' => $subscription->id
            ]);

        $order->addLog('Subscription reactivation order created.', 'Customer intended to reactivate the subscription', 'info');
    }

    public function handleSubscriptionRenewalCartCompleted($data)
    {
        $cart = Arr::get($data, 'cart', null);
        $order = Arr::get($data, 'order', null);

        if (!$cart || !$order || !$order->parent_id) {
            return;
        }

        $parentSubscription = Subscription::query()
            ->where('parent_order_id', $order->parent_id)
            ->first();

        $oldPaymentMethod = Arr::get($order->config, 'old_payment_method', '');
        $oldVendorSubscriptionId = Arr::get($order->config, 'old_vendor_subscription_id', '');

        if (
            $parentSubscription->current_payment_method != $oldPaymentMethod ||
            $parentSubscription->vendor_subscription_id != $oldVendorSubscriptionId
        ) {
            // we have a new verndor subscription ID or payment method, so let's cancel the old subscription
            $gateway = App::gateway($oldPaymentMethod);
            if ($gateway && $gateway->has('subscriptions')) {
                $result = $gateway->subscriptions->cancel($oldVendorSubscriptionId, [
                    'mode' => $order->mode
                ]);

                if (is_wp_error($result)) {
                    $order->addLog(
                        'OLD Subscription cancellation failed for renewal - ' . $oldPaymentMethod,
                        'Failed to cancel the old subscription with ID: ' . $oldVendorSubscriptionId . '. Error: ' . $result->get_error_message(),
                        'error'
                    );
                } else {
                    $order->addLog(
                        'Old Subscription cancelled successfully - ' . $oldPaymentMethod,
                        'Old subscription with ID: ' . $oldVendorSubscriptionId . ' has been cancelled.',
                        'info'
                    );
                }
            }
        }

        $newRecurringAmount = Arr::get($cart->checkout_data, 'renew_data.new_recurring_amount', 0);
        $oldRecurringAmount = Arr::get($cart->checkout_data, 'renew_data.old_recurring_amount', 0);

        if ($newRecurringAmount == $oldRecurringAmount || $newRecurringAmount <= 0) {
            return;
        }

        if ($parentSubscription) {
            $parentSubscription->recurring_amount = (int)$newRecurringAmount;
            $parentSubscription->recurring_total = (int)$newRecurringAmount;
            $parentSubscription->save();
        }

    }

    public function handleSubscriptionReactivationRedirect($data = [])
    {
        $subscriptionUID = $data['subscription_hash'] ?? '';

        if (empty($subscriptionUID)) {
            $this->showError(__('Subscription UID is required.', 'fluent-cart-pro'));
        }

        $subscription = Subscription::query()->where('uuid', $subscriptionUID)->first();

        if (!$subscription) {
            $this->showError(__('Subscription not found.', 'fluent-cart-pro'));
        }

        if ($subscription->recurring_amount <= 0) {
            $this->showError(__('The recurring amount for this subscription is not valid.', 'fluent-cart-pro'));
        }

        if (!$subscription->canReactive()) {
            $this->showError(__('This subscription cannot be reactivated.', 'fluent-cart-pro'));
        }

        $parentOrder = $subscription->order;
        if (!$parentOrder || !in_array($parentOrder->payment_status, Status::getOrderPaymentSuccessStatuses())) {
            $this->showError(__('The parent order of this subscription is not in a valid state for reactivation.', 'fluent-cart-pro'));
        }

        $product = $subscription->product;
        $variation = $subscription->variation;

        if (!$variation || !$product || Arr::get($variation->other_info, 'repeat_interval') != $subscription->billing_interval || $variation->payment_type != 'subscription') {
            $this->showError(__('The product variant for this subscription is not valid.', 'fluent-cart-pro'));
        }

        $billCount = OrderTransaction::query()->where('subscription_id', $subscription->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->where('status', Status::TRANSACTION_SUCCEEDED)
            ->count();

        $requiredBillTimes = ($subscription->bill_times - $billCount) < 0 ? 0 : ($subscription->bill_times - $subscription->bill_count);

        if (!$variation->canPurchase() && !$requiredBillTimes) {
            $this->showError(__('This subscription is not available for renew. Please purchase a new one!', 'fluent-cart-pro'));
        }

        $newItem = $variation->toArray();

        $recurringAmount = $subscription->recurring_amount;
        $trialDays = 0;

        if (!$requiredBillTimes) {
            if ($subscription->hasAccessValidity()) {
                $trialDays = $subscription->getReactivationTrialDays();
            } else {

                $nextBillingDate = $subscription->next_billing_date ?? $subscription->expire_at;

                // we should let the users to reactivate their subscription if within 60 days of the expiration
                $expirationDays = (int)((time() - strtotime($nextBillingDate)) / 86400);

                $samePriceDaysLimit = apply_filters('fluent_cart/subscription/reactivation_same_price_days_limit', 60, [
                    'subscription' => $subscription
                ]);

                if ($expirationDays <= $samePriceDaysLimit) {
                    $recurringAmount = $subscription->recurring_amount;
                } else {
                    // It's actually expired so the customer must need to pay the new pricing of our product!
                    $recurringAmount = $variation->item_price;
                }
            }
        }

        Arr::set($newItem, 'item_price', $recurringAmount);
        Arr::set($newItem, 'other_info', [
            'payment_type'     => 'subscription',
            'manage_setup_fee' => 'no',
            'trial_days'       => $trialDays,
            'repeat_interval'  => $subscription->billing_interval,
            'installment'      => $requiredBillTimes ? 'yes' : 'no',
            'times'            => $requiredBillTimes ? $requiredBillTimes : '0',
        ]);

        Arr::set($newItem, 'post_title', $variation->product->post_title);
        Arr::set($newItem, 'variation_type', $variation->product_detail->variation_type);

        $instantCart = CartHelper::generateCartFromCustomVariation($newItem);
        $instantCart->cart_group = 'instant';
        $instantCart->first_name = $subscription->customer->first_name;
        $instantCart->last_name = $subscription->customer->last_name;
        $instantCart->email = $subscription->customer->email;
        $instantCart->customer_id = $subscription->customer->customer_id;
        $instantCart->user_id = $subscription->customer->user_id;
        $instantCart->cart_hash = md5('renew_sub_' . wp_generate_uuid4() . time());
        $instantCart->checkout_data = [
            'is_locked'                       => 'yes',
            'disable_coupons'                 => 'yes',
            'renew_data'                      => [
                'subscription_hash'          => $subscription->uuid,
                'old_vendor_subscription_id' => $subscription->vendor_subscription_id,
                'old_payment_method'         => $subscription->current_payment_method,
                'parent_order_id'            => $parentOrder->id,
                'is_renewal'                 => 'yes',
                'trial_days'                 => $trialDays,
                'new_recurring_amount'       => $recurringAmount,
                'old_recurring_amount'       => $subscription->recurring_amount,
            ],
            '__after_draft_created_actions__' => [
                'fluent_cart/subscription_reactivation_order_created'
            ],
            '__on_success_actions__'          => [
                'fluent_cart/subscription_renewal_cart_completed'
            ],
            '__cart_notices'                  => [
                [
                    'id'      => 'renewal_notice',
                    'type'    => 'info',
                    'content' => sprintf(
                        /* translators: %1$s Item Title */
                        __('You are reactivating your subscription: %1$s .','fluent-cart-pro'),
                        $subscription->item_name
                    ),
                ]
            ]
        ];

        $instantCart->save();
        $cartHash = $instantCart->cart_hash;

        $checkoutUrl = add_query_arg(
            [
                'fct_cart_hash' => $cartHash,
            ],
            (new StoreSettings())->getCheckoutPage()
        );

        wp_redirect($checkoutUrl);
        exit();
    }

    private function showError($message)
    {
        die($message);
    }

}
