<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway;

use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;

class Processor
{

    public function handleSubscription(PaymentInstance $paymentInstance, $paymentArgs)
    {

        $orderType = $paymentInstance->order->type;
        $fcCustomer = $paymentInstance->order->customer;
        $billingAddress = $paymentInstance->order->billing_address;

        $subscriptionModel = $paymentInstance->subscription;

        if (!$subscriptionModel) {
            return new \WP_Error('no_subscription', __('No subscription found.', 'fluent-cart'));
        }

        $stripeCustomer = StripeHelper::createOrGetStripeCustomer($paymentInstance->order->customer);

        if (is_wp_error($stripeCustomer)) {
            return $stripeCustomer;
        }

        $initialAmount = (int)$subscriptionModel->signup_fee + $paymentInstance->getExtraAddonAmount();

        if ($orderType == 'renewal') {
            $stripePlan = Plan::getStripePricing([
                'product_id'       => $subscriptionModel->product_id,
                'variation_id'     => $subscriptionModel->variation_id,
                'billing_interval' => $subscriptionModel->billing_interval,
                'recurring_total'  => $subscriptionModel->getCurrentRenewalAmount(),
                'currency'         => $paymentInstance->order->currency,
                'trial_days'       => $subscriptionModel->getReactivationTrialDays(), // No trial for renewals
                'interval_count'   => 1 // per month / year / week
            ]);

            $initialAmount = 0;
        } else {
            $stripePlan = Plan::getStripePricing([
                'product_id'       => $subscriptionModel->product_id,
                'variation_id'     => $subscriptionModel->variation_id,
                'billing_interval' => $subscriptionModel->billing_interval,
                'recurring_total'  => $subscriptionModel->recurring_total,
                'currency'         => $paymentInstance->order->currency,
                'trial_days'       => (int)$subscriptionModel->trial_days,
                'interval_count'   => 1 // per month / year / week
            ]);
        }

        if (is_wp_error($stripePlan)) {
            return $stripePlan;
        }

        $stripeSubscriptionData = [
            'customer'         => Arr::get($stripeCustomer, 'id', ''),
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => [
                'save_default_payment_method' => 'on_subscription'
            ],
            'items'            => [
                [
                    'plan'     => $stripePlan['id'],
                    'quantity' => $subscriptionModel->quantity ?: 1,
                ]
            ],
            'expand'           => [
                'latest_invoice.confirmation_secret',
                'pending_setup_intent'
            ],
            'metadata'         => [
                'fct_ref_id' => $paymentInstance->order->uuid,
                'email'      => $paymentInstance->order->customer->email,
                'name'       => $paymentInstance->order->full_name
            ]
        ];

        if (Arr::get($stripePlan, 'trial_period_days')) {
            $stripeSubscriptionData['trial_end'] = strtotime('+' . Arr::get($stripePlan, 'trial_period_days') . ' days');
        }

        // Maybe we have initial amount
        if ($initialAmount) {
            $addonPrice = Plan::getOneTimeAddonPrice([
                'product_id' => $subscriptionModel->product_id,
                'currency'   => $paymentInstance->order->currency,
                'amount'     => (int)$initialAmount,
            ]);

            if (is_wp_error($addonPrice)) {
                return $addonPrice;
            }

            $stripeSubscriptionData['add_invoice_items'] = [
                [
                    'price'    => $addonPrice['id'],
                    'quantity' => 1
                ]
            ];
        }

        if ($expireAt = $paymentInstance->getSubscriptionCancelAtTimeStamp()) {
          //  $stripeSubscriptionData['cancel_at'] = $expireAt;
        }

        $stripeSubscription = (new API())->createStripeObject('subscriptions', $stripeSubscriptionData);

        if (is_wp_error($stripeSubscription)) {
            return $stripeSubscription;
        }

        $vendorChargeId = Arr::get($stripeSubscription, 'latest_invoice.payment_intent');
        if (!$vendorChargeId) {
            $vendorChargeId = Arr::get($stripeSubscription, 'pending_setup_intent.id');
        }

        if ($vendorChargeId) {
            $paymentInstance->transaction->update(['vendor_charge_id' => $vendorChargeId]);
        }

        $vendorSubscriptionId = Arr::get($stripeSubscription, 'id');

        $subscriptionModel->update([
            'vendor_subscription_id' => $vendorSubscriptionId,
            'vendor_customer_id'     => $stripeSubscription['customer']
        ]);

        if ($stripeSubscription['pending_setup_intent'] != null) {
            $paymentArgs['vendor_subscription_info'] = [
                'type'         => 'setup',
                'clientSecret' => Arr::get($stripeSubscription, 'pending_setup_intent.client_secret')
            ];
        } else {
            $paymentArgs['vendor_subscription_info'] = [
                'type'         => 'payment',
                'clientSecret' => Arr::get($stripeSubscription, 'latest_invoice.confirmation_secret.client_secret')
            ];
        }

        $customerData = [
            'name'      => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
            'email'     => $fcCustomer->email,
            'address_1' => $billingAddress->address_1,
            'address_2' => $billingAddress->address_2,
            'city'      => $billingAddress->city,
            'state'     => $billingAddress->state,
            'postcode'  => $billingAddress->postcode,
            'country'   => $billingAddress->country
        ];

        return [
            'nextAction'   => 'stripe',
            'actionName'   => 'custom',
            'status'       => 'success',
            'message'      => __('Order has been placed successfully', 'fluent-cart'),
            'payment_args' => $paymentArgs,
            'response'     => $stripeSubscription,
            'fc_customer'  => $customerData
        ];
    }


    /**
     * Handle single payment for stripe (onsite)
     *
     * @return \WP_Error|array
     */
    public function handleSinglePayment(PaymentInstance $paymentInstance, $paymentArgs = [])
    {

        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;
        $billingAddress = $order->billing_address;

        $intentData = [
            'amount'                    => $transaction->total,
            'currency'                  => $transaction->currency,
            'automatic_payment_methods' => ['enabled' => 'true'],
            'metadata'                  => [
                'fct_ref_id' => $order->uuid,
                'Name'       => $order->customer->full_name,
                'Email'      => $order->customer->email
            ]
        ];

        if (!empty($paymentArgs['customer'])) {
            $intentData['customer'] = $paymentArgs['customer'];
        } else {
            $stripeCustomer = StripeHelper::createOrGetStripeCustomer($order->customer);
            if (is_wp_error($stripeCustomer)) {
                return $stripeCustomer;
            }
            $intentData['customer'] = $stripeCustomer['id'];
        }

        if (!empty($paymentArgs['setup_future_usage'])) {
            $intentData['setup_future_usage'] = $paymentArgs['setup_future_usage'];
        }

        $paymentArgs['public_key'] = (new StripeSettingsBase())->getPublicKey();

        $intentData = apply_filters('fluent_cart/payments/stripe_onetime_intent_args', $intentData, [
            'order'       => $order,
            'transaction' => $transaction
        ]);

        $intent = (new API())->createStripeObject('payment_intents', $intentData);

        if (is_wp_error($intent)) {
            return $intent;
        }

        $transaction->update([
            'vendor_charge_id' => $intent['id']
        ]);

        $customerData = [
            'name'      => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
            'email'     => $fcCustomer->email,
            'address_1' => $billingAddress->address_1,
            'address_2' => $billingAddress->address_2,
            'city'      => $billingAddress->city,
            'state'     => $billingAddress->state,
            'postcode'  => $billingAddress->postcode,
            'country'   => $billingAddress->country
        ];

        return [
            'status'       => 'success',
            'nextAction'   => 'stripe',
            'actionName'   => 'custom',
            'message'      => __('Order has been placed successfully', 'fluent-cart'),
            'response'     => $intent,
            'payment_args' => $paymentArgs,
            'fc_customer'  => $customerData
        ];
    }


}
