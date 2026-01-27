<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway;

use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;

class Processor
{

    public function handleSubscription(PaymentInstance $paymentInstance, $paymentArgs)
    {
        $stripeSettings = new StripeSettingsBase();
        $checkoutMode = $stripeSettings->get('checkout_mode') ?? 'onsite';

        // If hosted mode, create Checkout Session for subscription
        if ($checkoutMode === 'hosted') {
            return $this->handleHostedSubscriptionCheckout($paymentInstance, $paymentArgs);
        }

        // Original onsite subscription flow
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
            'metadata'         => apply_filters('fluent_cart/payments/stripe_metadata_subscription', [
                'fct_ref_id' => $paymentInstance->order->uuid,
                'email'      => $paymentInstance->order->customer->email,
                'name'       => $paymentInstance->order->full_name,
                'subscription_item'       => $subscriptionModel->item_name,
                'order_reference' => 'fct_order_id_' . $paymentInstance->order->id,
            ], [
                'order'       => $paymentInstance->order,
                'transaction' => $paymentInstance->transaction,
                'subscription' => $subscriptionModel
            ]),
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
     * Handle single payment for stripe (onsite or hosted)
     *
     * @return \WP_Error|array
     */
    public function handleSinglePayment(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $stripeSettings = new StripeSettingsBase();
        $checkoutMode = $stripeSettings->get('checkout_mode') ?? 'onsite';

        if ($checkoutMode === 'hosted') {
            return $this->handleHostedCheckout($paymentInstance, $paymentArgs);
        }

        // Original onsite payment flow
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;
        $billingAddress = $order->billing_address;

        $transactionCurrency = $transaction->currency;
        $intentAmount = (int)$transaction->total;

        if ($transactionCurrency && CurrenciesHelper::isZeroDecimal($transactionCurrency)) {
            $intentAmount = (int)($intentAmount / 100);
        }

        $intentData = [
            'amount'                    => $intentAmount,
            'currency'                  => $transactionCurrency,
            'automatic_payment_methods' => ['enabled' => 'true'],
            'metadata'                  => apply_filters('fluent_cart/payments/stripe_metadata_onetime', [
                'fct_ref_id' => $order->uuid,
                'Name'       => $order->customer->full_name,
                'Email'      => $order->customer->email,
                'order_reference' => 'fct_order_id_' . $paymentInstance->order->id,
            ], [
                'order'       => $order,
                'transaction' => $transaction
            ]),
        ];

        $itemCount = 1;
        foreach($paymentInstance->order->order_items as $item) {
            $intentData['metadata']['item ' . $itemCount] = 'Name: ' . $item->title . ', ' . 'Qty: ' . $item->quantity . ', Price: ' . Helper::toDecimal($item->line_total, false, null, true, true, false);
            if (count($intentData['metadata']) > 49) {
                break;
            }
            $itemCount++;
        }

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


    private function handleHostedCheckout(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $order->customer;
        $billingAddress = $order->billing_address;

        $transactionCurrency = $transaction->currency;
        $chargeAmount = (int)$transaction->total;

        if ($transactionCurrency && CurrenciesHelper::isZeroDecimal($transactionCurrency)) {
            $chargeAmount = (int)($chargeAmount / 100);
        }

        // Create or get Stripe customer
        $stripeCustomer = StripeHelper::createOrGetStripeCustomer($fcCustomer);
        if (is_wp_error($stripeCustomer)) {
            return $stripeCustomer;
        }

        // Use a single line item with the total amount to avoid complexity
        // This is simpler and prevents any calculation mismatches
        $storeName = (new \FluentCart\Api\StoreSettings())->get('store_name');
        $lineItems = [
            [
                'price_data' => [
                    'currency'     => strtolower($transactionCurrency),
                    'product_data' => [
                        'name'        => $storeName . ' - Order #' . $order->uuid,
                        'description' => sprintf(__('Order total including all items, shipping (If any), and taxes (If any)', 'fluent-cart')),
                    ],
                    'unit_amount'  => $chargeAmount,
                ],
                'quantity'   => 1,
            ]
        ];

        $sessionData = [
            'customer'           => $stripeCustomer['id'],
            'client_reference_id' => $order->uuid,
            'line_items'         => $lineItems,
            'mode'               => 'payment',
            'success_url'        => Arr::get($paymentArgs, 'success_url') . '&fct_stripe_hosted=1&trx_hash=' . $transaction->uuid,
            'cancel_url'         => StripeHelper::getCancelUrl(),
            'metadata'           => [
                'fct_ref_id'      => $order->uuid,
                'transaction_hash' => $transaction->uuid,
                'order_reference' => 'fct_order_id_' . $order->id,
            ],
        ];

        $itemCount = 1;
        foreach($order->order_items as $item) {
            $sessionData['metadata']['item ' . $itemCount] = 'Name: ' . $item->title . ', ' . 'Qty: ' . $item->quantity . ', Price: ' . Helper::toDecimal($item->line_total, false, null, true, true, false);
            if (count($sessionData['metadata']) > 49) {
                break;
            }
            
            $itemCount++;
        }

        $sessionData = apply_filters('fluent_cart/payments/stripe_checkout_session_args', $sessionData, [
            'order'       => $order,
            'transaction' => $transaction
        ]);

        $session = (new API())->createStripeObject('checkout/sessions', $sessionData);

        if (is_wp_error($session)) {
            return $session;
        }

        $transaction->update([
            'meta'             => array_merge($transaction->meta ?? [], [
                'session_id' => $session['id']
            ])
        ]);

        return [
            'status'       => 'success',
            'nextAction'   => 'stripe',
            'actionName'   => 'redirect',
            'message'      => __('Redirecting to Stripe checkout...', 'fluent-cart'),
            'response'     => $session,
            'payment_args' => array_merge($paymentArgs, [
                'checkout_url' => $session['url'],
                'session_id'   => $session['id']
            ])
        ];
    }


    private function handleHostedSubscriptionCheckout(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $subscriptionModel = $paymentInstance->subscription;
        $fcCustomer = $order->customer;

        if (!$subscriptionModel) {
            return new \WP_Error('no_subscription', __('No subscription found.', 'fluent-cart'));
        }

        $transactionCurrency = $transaction->currency;
        $orderType = $order->type;

        // Create or get Stripe customer
        $stripeCustomer = StripeHelper::createOrGetStripeCustomer($fcCustomer);
        if (is_wp_error($stripeCustomer)) {
            return $stripeCustomer;
        }

        // Get or create Stripe price/plan
        if ($orderType == 'renewal') {
            $stripePlan = Plan::getStripePricing([
                'product_id'       => $subscriptionModel->product_id,
                'variation_id'     => $subscriptionModel->variation_id,
                'billing_interval' => $subscriptionModel->billing_interval,
                'recurring_total'  => $subscriptionModel->getCurrentRenewalAmount(),
                'currency'         => $order->currency,
                'trial_days'       => $subscriptionModel->getReactivationTrialDays(),
                'interval_count'   => 1
            ]);
        } else {
            $stripePlan = Plan::getStripePricing([
                'product_id'       => $subscriptionModel->product_id,
                'variation_id'     => $subscriptionModel->variation_id,
                'billing_interval' => $subscriptionModel->billing_interval,
                'recurring_total'  => $subscriptionModel->recurring_total,
                'currency'         => $order->currency,
                'trial_days'       => (int)$subscriptionModel->trial_days,
                'interval_count'   => 1
            ]);
        }

        if (is_wp_error($stripePlan)) {
            return $stripePlan;
        }


        $initialAmount = (int)$subscriptionModel->signup_fee + $paymentInstance->getExtraAddonAmount();
        
        if ($orderType == 'renewal') {
            $initialAmount = 0;
        }

        $recurringTotal = (int)$subscriptionModel->recurring_total;
        if ($transactionCurrency && CurrenciesHelper::isZeroDecimal($transactionCurrency)) {
            $initialAmount = (int)($initialAmount / 100);
            $recurringTotal = (int)($recurringTotal / 100);
        }

        $lineItems = [
            [
                'price'    => $stripePlan['id'],
                'quantity' => $subscriptionModel->quantity ?: 1,
            ]
        ];

        $subscriptionData = [
            'metadata' => [
                'fct_ref_id'      => $order->uuid,
                'email'           => $fcCustomer->email,
                'name'            => $order->full_name,
                'order_reference' => 'fct_order_id_' . $order->id,
                'subscription_item'            => $subscriptionModel->item_name,
            ],
        ];

        // Handle trial period if set in plan (same as onsite lines 94-96)
        if (!empty($stripePlan['trial_period_days'])) {
            $subscriptionData['trial_period_days'] = $stripePlan['trial_period_days'];
        }

        if ($initialAmount > 0) {
            $addonPrice = Plan::getOneTimeAddonPrice([
                'product_id' => $subscriptionModel->product_id,
                'currency'   => $order->currency,
                'amount'     => (int)$initialAmount,
                'name'       => __('Signup fee / initial payment', 'fluent-cart'),
            ]);

            if (is_wp_error($addonPrice)) {
                return $addonPrice;
            };

            $lineItems[] = [
                'price'    => $addonPrice['id'],
                'quantity' => 1
            ];
        }

        $sessionData = [
            'customer'            => $stripeCustomer['id'],
            'client_reference_id' => $order->uuid,
            'line_items'          => $lineItems,
            'mode'                => 'subscription',
            'consent_collection' => ['payment_method_reuse_agreement' => ['position' => 'hidden']],
            'success_url'         => Arr::get($paymentArgs, 'success_url') . '&fct_stripe_hosted=1&trx_hash=' . $transaction->uuid,
            'cancel_url'          => StripeHelper::getCancelUrl(),
            'subscription_data'   => $subscriptionData,
            'metadata'            => [
                'fct_ref_id'         => $order->uuid,
                'subscription_item'  => $subscriptionModel->item_name,
                'transaction_hash'   => $transaction->uuid,
                'order_reference'    => 'fct_order_id_' . $order->id,
            ],
        ];

        $sessionData = apply_filters('fluent_cart/payments/stripe_subscription_checkout_session_args', $sessionData, [
            'order'        => $order,
            'transaction'  => $transaction,
            'subscription' => $subscriptionModel
        ]);

        $session = (new API())->createStripeObject('checkout/sessions', $sessionData);

        if (is_wp_error($session)) {
            return $session;
        }

        $subscriptionModel->update([
            'vendor_customer_id'     => $stripeCustomer['id']
        ]);

        $transaction->update([
            'vendor_charge_id' => Arr::get($session, 'payment_intent', Arr::get($session, 'id')),
            'meta'             => array_merge($transaction->meta ?? [], [
                'session_id' => $session['id']
            ])
        ]);

        return [
            'status'       => 'success',
            'nextAction'   => 'stripe',
            'actionName'   => 'redirect',
            'message'      => __('Redirecting to Stripe checkout...', 'fluent-cart'),
            'response'     => $session,
            'payment_args' => array_merge($paymentArgs, [
                'checkout_url' => $session['url'],
                'session_id'   => $session['id']
            ])
        ];
    }

}
