<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway;

use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Modules\Subscriptions\Services\SystemChargeService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;

class Processor
{
    /**
     * Does this order-create error actually implicate the vault attributes?
     *
     * PayPal reports the offending field path in details[].field, which is the
     * structural signal — it points straight at attributes/vault when the vault
     * block is the problem, and elsewhere when it is not. details[].issue is
     * matched too, against a deliberately small list: guessing broadly here would
     * recreate the bug this method exists to prevent, so anything unrecognised is
     * treated as unrelated and the error is returned untouched.
     *
     * The issue list is filterable because PayPal can introduce codes faster than
     * a core release can follow, and a missing code should be correctable without
     * one.
     *
     * @param mixed $error
     * @return bool
     */
    public static function isVaultRejection($error): bool
    {
        if (!is_wp_error($error)) {
            return false;
        }

        $body = $error->get_error_data();
        if (!is_array($body)) {
            return false;
        }

        $vaultIssues = apply_filters('fluent_cart/payments/paypal_vault_rejection_issues', [
            'PAYMENT_SOURCE_CANNOT_BE_USED',
            'PAYMENT_SOURCE_NOT_VAULTABLE',
            'VAULTING_NOT_ENABLED',
            'MERCHANT_NOT_ENABLED_FOR_VAULTING',
            'VAULT_ID_NOT_SUPPORTED',
        ]);

        foreach ((array) Arr::get($body, 'details', []) as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            // Structural: PayPal names the field it rejected.
            $field = strtolower((string) Arr::get($detail, 'field', ''));
            if ($field !== '' && strpos($field, 'vault') !== false) {
                return true;
            }

            $issue = strtoupper((string) Arr::get($detail, 'issue', ''));
            if ($issue !== '' && in_array($issue, $vaultIssues, true)) {
                return true;
            }
        }

        return false;
    }

    public function handleSinglePayment(PaymentInstance $paymentInstance, $args = [])
    {
        $transaction = $paymentInstance->transaction;
        $order = $paymentInstance->order;

        $itemsSubTotal = 0;
        $formattedItems = [];

        foreach ($order->order_items as $item) {
            $quantity = $item->quantity ?? 1;
            $perQuantity = $this->toDecimal($item->line_total / $quantity);
            $title = $item->post_title . ' ' . $item->title;

            $formattedItems[] = [
                'name'        => strlen($title) > 127 ? substr($title, 0, 120) . '...' : $title,
                'description' => strlen($title) > 4000 ? substr($title, 0, 3997) . '...' : $title,
                'unit_amount' => [
                    'currency_code' => $transaction->currency,
                    'value'         => number_format($perQuantity, 2, '.', ''),
                ],
                'quantity'    => $quantity,
            ];

            $itemsSubTotal += $perQuantity * $quantity;
        }

        $chargingAmount = $this->toDecimal($transaction->total);
        $pushedTotal = $itemsSubTotal;


        // Learn more at: https://developer.paypal.com/docs/api/orders/v2/#definition-purchase_unit
        $purchaseUnits = [
            'reference_id' => $transaction->uuid, // This is the order UUID
            'amount'       => [ // https://developer.paypal.com/docs/api/orders/v2/#definition-amount_breakdown
                'currency_code' => $transaction->currency,
                'value'         => number_format($chargingAmount, 2, '.', ''),
                'breakdown'     => [
                    'item_total' => [
                        'currency_code' => $transaction->currency,
                        'value'         => number_format($itemsSubTotal, 2, '.', ''),
                    ]
                ]
            ],
            'items'        => $formattedItems
        ];

        // if there is no defined credential for specific mode,
        // then add merchantId as it's a partner app connection
        $payPalSettings = new PayPalSettingsBase();
        if ($merchantId = $payPalSettings->getMerchantId()) {
            if ($payPalSettings->getProviderType() === 'api_keys') {
                $purchaseUnits['payee'] = [
                    "merchant_id" => $merchantId
                ];
            }
        }

        if ($order->shipping_total > 0) {
            $shippingAmount = $this->toDecimal($order->shipping_total);
            $purchaseUnits['amount']['breakdown']['shipping'] = [
                'currency_code' => $transaction->currency,
                'value'         => number_format($shippingAmount, 2, '.', ''),
            ];
            $pushedTotal += $shippingAmount;
        }



        $taxBehavior       = (int) $order->tax_behavior;
        $exclusiveTaxTotal = (int) $order->getMeta('exclusive_tax_total');
        $storeTaxBehavior  = (int) $order->getMeta('store_tax_behavior');
        $feeTax            = (int) $order->getMeta('fee_tax');

        // Fallback: if meta missing (old order), use tax_behavior as store_tax_behavior
        if (empty($storeTaxBehavior) && $taxBehavior > 0) {
            $storeTaxBehavior = $taxBehavior;
        }

        if ($taxBehavior === 1) {
            // Pure exclusive: all tax is additive on top of item prices.
            // tax_total includes product + fee tax (both exclusive).
            $taxTotal = $this->toDecimal($order->tax_total) + $this->toDecimal($order->shipping_tax);
        } elseif ($taxBehavior === 3) {
            // Mixed: only exclusive product + fee tax is additive; shipping conditional.
            $taxTotal = $this->toDecimal($exclusiveTaxTotal);
            if ($storeTaxBehavior === 1) {
                // Store is exclusive: fees and shipping are also exclusive.
                $taxTotal += $this->toDecimal($order->shipping_tax);
                $taxTotal += $this->toDecimal($feeTax);
            }
        } else {
            $taxTotal = 0;
        }

        if ($taxTotal > 0) {
            $purchaseUnits['amount']['breakdown']['tax_total'] = [
                'currency_code' => $transaction->currency,
                'value'         => number_format($taxTotal, 2, '.', ''),
            ];
            $pushedTotal += $taxTotal;
        }

        if ($chargingAmount < $pushedTotal) {
            $discount = $pushedTotal - $chargingAmount;
            $purchaseUnits['amount']['breakdown']['discount'] = [
                'currency_code' => $transaction->currency,
                'value'         => number_format($discount, 2, '.', ''),
            ];
        } else if ($chargingAmount > $pushedTotal) {
            $extraChargeNeedToBeAdded = $chargingAmount - $pushedTotal;
            $formattedItems[] = [
                'name'        => __('Adjustment Amount', 'fluent-cart'),
                'unit_amount' => [
                    'currency_code' => $transaction->currency,
                    'value'         => number_format($extraChargeNeedToBeAdded, 2, '.', ''),
                ],
                'quantity'    => 1,
            ];

            $purchaseUnits['items'] = $formattedItems;

            //now the total amount need to be adjusted with item total value
            $adjustedItemTotal = $itemsSubTotal + $extraChargeNeedToBeAdded;
            $purchaseUnits['amount']['breakdown']['item_total']['value'] = number_format($adjustedItemTotal, 2, '.', '');
        }

        // System (auto-charged, store-billed) subscription checkout: vault the
        // buyer's PayPal account during this purchase (Vault v3 save-on-success)
        // so future renewal invoices can be charged merchant-initiated. The buyer
        // sees and approves the save agreement inside PayPal's own approval UI.
        // Vaulting on a plain one-time order cannot be requested from outside core:
        // the vault_attributes filter below fires only once this branch is already
        // taken, so it can shape a vault but never ask for one. This filter is the
        // PayPal counterpart of fluent_cart/payments/stripe_onetime_intent_args, and
        // it is what lets the saved-payment-methods module vault on buyer consent.
        // Defaults to the existing value, so with no listener behaviour is unchanged.
        $vaultOnSuccess = apply_filters(
            'fluent_cart/payments/paypal_vault_one_time',
            !empty($args['vault_on_success']),
            [
                'order'        => $order,
                'transaction'  => $transaction,
                'subscription' => $paymentInstance->subscription,
            ]
        );

        $extraBody = [];
        if ($vaultOnSuccess) {
            $vaultAttributes = apply_filters('fluent_cart/paypal/vault_attributes', [
                'store_in_vault' => 'ON_SUCCESS',
                'usage_type'     => 'MERCHANT',
                'customer_type'  => 'CONSUMER',
            ], [
                'order'        => $order,
                'subscription' => $paymentInstance->subscription,
            ]);

            $extraBody['payment_source'] = [
                'paypal' => [
                    'attributes'         => ['vault' => $vaultAttributes],
                    'experience_context' => [
                        'return_url'          => PaymentHelper::getCustomPaymentLink($order->uuid),
                        'cancel_url'          => \FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway::getCancelUrl(),
                        'shipping_preference' => 'NO_SHIPPING',
                    ],
                ],
            ];
        }

        $paypalOrder = API::createOrder($purchaseUnits, $extraBody);

        // Vaulting is a convenience; the purchase is the point. A merchant account
        // not approved for vaulting can reject the order outright because of the
        // vault attributes, and failing the sale over a save the buyer merely
        // opted into would be the wrong trade. Retry once without them and let
        // listeners record that this account cannot vault, so the saving UI can
        // stop being offered instead of failing silently on every order.
        //
        // ONLY for an error that actually implicates the vault attributes. An auth
        // failure, rate limit, malformed amount or transport error is not evidence
        // that this account cannot vault: retrying would not fix it, and telling a
        // listener otherwise would switch saving off for a perfectly capable
        // account on the strength of an unrelated outage.
        if (is_wp_error($paypalOrder) && $vaultOnSuccess && self::isVaultRejection($paypalOrder)) {
            do_action('fluent_cart/payments/paypal_vault_rejected', [
                'order'       => $order,
                'transaction' => $transaction,
                'error'       => $paypalOrder,
            ]);

            unset($extraBody['payment_source']['paypal']['attributes']);

            $paypalOrder = API::createOrder($purchaseUnits, $extraBody);
        }

        if (is_wp_error($paypalOrder)) {
            return $paypalOrder;
        }

        $paypalOrderId = Arr::get($paypalOrder, 'id');

        $transaction->update([
            'meta' => array_merge($transaction->meta ?? [], ['paypal_order_id' => $paypalOrderId])
        ]);

        return [
            'nextAction'         => 'paypal',
            'actionName'         => 'custom',
            'status'             => 'success',
            'data'               => [
                'order'       => [
                    'uuid' => $order->uuid,
                ],
                'transaction' => [
                    'uuid' => $transaction->uuid,
                ]
            ],
            'message'            => __('Order has been placed successfully', 'fluent-cart'),
            'custom_payment_url' => PaymentHelper::getCustomPaymentLink($order->uuid),
            'response'           => [
                'paypalOrderId' => $paypalOrderId,
            ]
        ];
    }

    /**
     * Zero-payable system subscription checkout (free trial): a $0 PayPal order
     * is invalid, so the buyer's PayPal account is vaulted via a Vault v3 setup
     * token; confirmVaultSetup() exchanges it, completes the $0 order, and the
     * trial-end invoice is charged off-session like any other system renewal.
     * The save agreement is carried by PayPal's own approval popup; the checkout
     * page shows the informational disclosure next to the buttons.
     */
    public function handleSetupOnlyPayment(PaymentInstance $paymentInstance)
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;

        $setupToken = API::makeRequest('vault/setup-tokens', 'v3', 'POST', [
            'payment_source' => [
                'paypal' => [
                    'usage_type'         => 'MERCHANT',
                    'customer_type'      => 'CONSUMER',
                    'experience_context' => [
                        'return_url'          => PaymentHelper::getCustomPaymentLink($order->uuid),
                        'cancel_url'          => \FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway::getCancelUrl(),
                        'shipping_preference' => 'NO_SHIPPING',
                    ],
                ],
            ],
        ]);

        if (is_wp_error($setupToken)) {
            return $setupToken;
        }

        $setupTokenId = Arr::get($setupToken, 'id');

        if (!$setupTokenId) {
            return new \WP_Error('setup_token_failed', __('PayPal did not return a setup token.', 'fluent-cart'));
        }

        // confirmVaultSetup() binds the buyer's approval to this transaction by
        // this id; the write takes the same lock as confirmation so a
        // replacement can never interleave with an in-flight confirm.
        if (!self::acquireVaultTransactionLock($transaction->uuid)) {
            return new \WP_Error('setup_in_progress', __('Another payment confirmation is in progress. Please try again.', 'fluent-cart'));
        }

        try {
            $transaction->update([
                'meta' => array_merge($transaction->meta ?? [], ['paypal_setup_token_id' => $setupTokenId])
            ]);
        } finally {
            self::releaseVaultTransactionLock($transaction->uuid);
        }

        return [
            'nextAction'         => 'paypal',
            'actionName'         => 'custom',
            'status'             => 'success',
            'data'               => [
                'order'       => [
                    'uuid' => $order->uuid,
                ],
                'transaction' => [
                    'uuid' => $transaction->uuid,
                ]
            ],
            'message'            => __('Order has been placed successfully', 'fluent-cart'),
            'custom_payment_url' => PaymentHelper::getCustomPaymentLink($order->uuid),
            'response'           => [
                'setupTokenId' => $setupTokenId,
            ]
        ];
    }

    /**
     * Vault-flow lock, keyed on the transaction uuid — shared by the setup-token
     * binding write and the confirmation endpoint so token replacement and
     * confirmation of one transaction always serialize.
     */
    public static function acquireVaultTransactionLock($transactionUuid)
    {
        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            'fluent_cart_paypal_vault_' . md5($transactionUuid),
            10
        ));

        return (string) $result === '1';
    }

    public static function releaseVaultTransactionLock($transactionUuid)
    {
        global $wpdb;

        $wpdb->get_var($wpdb->prepare(
            'SELECT RELEASE_LOCK(%s)',
            'fluent_cart_paypal_vault_' . md5($transactionUuid)
        ));
    }

    /**
     * Exchange an approved setup token for a durable payment token, persist it
     * on the system subscription, and complete the $0 order — the trial then
     * activates through the normal status-sync path.
     *
     * @param OrderTransaction $transaction
     * @param string $setupTokenId
     * @return true|\WP_Error
     */
    public function confirmVaultSetup(OrderTransaction $transaction, $setupTokenId)
    {
        // A prior confirmation may have died between marking the transaction
        // succeeded and syncing the order — always re-run the idempotent sync.
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
            return true;
        }

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->find($transaction->subscription_id);

        if (!$subscription || !$subscription->isSystem()) {
            return new \WP_Error('invalid_subscription', __('No auto-charged subscription is attached to this transaction.', 'fluent-cart'));
        }

        // Keyed on the setup token: a double-fired confirmation replays the
        // original payment token instead of vaulting twice.
        $paymentToken = API::makeRequest('vault/payment-tokens', 'v3', 'POST', [
            'payment_source' => [
                'token' => [
                    'id'   => $setupTokenId,
                    'type' => 'SETUP_TOKEN',
                ],
            ],
        ], '', [
            'PayPal-Request-Id' => 'fct_paypal_pt_' . md5($setupTokenId),
        ]);

        if (is_wp_error($paymentToken)) {
            return $paymentToken;
        }

        $tokenId = Arr::get($paymentToken, 'id');

        if (!$tokenId) {
            return new \WP_Error('vault_failed', __('PayPal did not return a saved payment method.', 'fluent-cart'));
        }

        $vaultCustomerId = Arr::get($paymentToken, 'customer.id', '');
        if ($vaultCustomerId && !$subscription->vendor_customer_id) {
            $subscription->vendor_customer_id = $vaultCustomerId;
            $subscription->save();
        }

        $paypalSource = Arr::get($paymentToken, 'payment_source.paypal', []);
        $billingInfo = PaymentHelper::parsePaymentMethodDetails('paypal', [
            'email'    => Arr::get($paypalSource, 'email_address', ''),
            'payer_id' => Arr::get($paypalSource, 'account_id', ''),
            'name'     => trim(Arr::get($paypalSource, 'name.given_name', '') . ' ' . Arr::get($paypalSource, 'name.surname', '')),
        ]);
        $billingInfo['vendor_method_id'] = $tokenId;

        $subscription->updateMeta('active_payment_method', $billingInfo);

        $subscription->addLog(
            'PayPal account saved',
            __('PayPal payment method vaulted for automatic renewal charges.', 'fluent-cart'),
            'info'
        );

        $transaction->fill([
            'status'         => Status::TRANSACTION_SUCCEEDED,
            'payment_method' => 'paypal',
        ]);
        $transaction->save();

        (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);

        return true;
    }

    public function handleSubscriptionPaymentFromPaymentInstance(PaymentInstance $paymentInstance, $args = [])
    {
        $orderType = $paymentInstance->order->type;
        $subscription = $paymentInstance->subscription;
        $feeTotal = $orderType !== 'renewal' ? (int)$paymentInstance->order->fee_total : 0;
        $initialAmount = (int)$subscription->signup_fee + $paymentInstance->getExtraAddonAmount() + $feeTotal;
        $status = Status::SUBSCRIPTION_INTENDED;

        if ($orderType == 'renewal') {
            $requiredBillTimes = $subscription->getRequiredBillTimes();

            if ($requiredBillTimes === -1) {
                return new \WP_Error('already_completed', __('Invalid bill times for the subscription.', 'fluent-cart'));
            }

            $data = [
                'order_id'         => $subscription->parent_order_id,
                'product_id'       => $subscription->product_id,
                'variation_id'     => $subscription->variation_id,
                'trial_days'       => $subscription->getReactivationTrialDays(), // trial days for reactivation
                'billing_interval' => $subscription->billing_interval,
                'currency'         => $paymentInstance->order->currency,
                'interval_count'   => 1, // 1
                'recurring_amount' => $subscription->getCurrentRenewalAmount(), // default recurring total in cents
                'signup_fee'       => 0, // default setup fee in cents ($0.00)
                'bill_times'       => $requiredBillTimes, // 0 for unlimited
            ];
            $status = $subscription->status;
        } else {
            $data = [
                'order_id'         => $subscription->parent_order_id,
                'product_id'       => $subscription->product_id,
                'variation_id'     => $subscription->variation_id,
                'trial_days'       => $subscription->trial_days,
                'billing_interval' => $subscription->billing_interval,
                'currency'         => $paymentInstance->order->currency,
                'interval_count'   => 1, // 1
                'recurring_amount' => $subscription->recurring_total, // default recurring total in cents
                'signup_fee'       => $initialAmount, // default setup fee in cents ($0.00)
                'bill_times'       => $subscription->getInitialRemoteBillTimes(), // 0 for unlimited; simulated-trial first installment excluded
            ];

        }

        $paypalPlan = PayPalHelper::getPayPalPlan($data);

        if (is_wp_error($paypalPlan)) {
            return $paypalPlan;
        }

        $subscriptionUpdateFields = [
            'status'          => $status,
            'vendor_plan_id'  => Arr::get($paypalPlan, 'id'),
            'vendor_response' => json_encode($paypalPlan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ];

        $subscription->update($subscriptionUpdateFields);

        if ($orderType == 'renewal' && !empty($data['trial_days'])) {
            $subscription->mergeConfig(['is_trial_days_simulated' => 'yes']);
        }

        return [
            'status'     => 'success',
            'nextAction' => 'paypal',
            'actionName' => 'custom',
            'message'    => __('Order has been placed successfully', 'fluent-cart'),
            'data'       => [
                'order'        => [
                    'uuid' => $paymentInstance->order->uuid,
                ],
                'transaction'  => [
                    'uuid' => $paymentInstance->transaction->uuid,
                ],
                'subscription' => [
                    'uuid' => $subscription->uuid,
                ]
            ],
            'response'   => [
                'planId' => Arr::get($paypalPlan, 'id')
            ]
        ];
    }

    /**
     * Confirm payment success
     * Currently used by:
     * @param OrderTransaction $transaction
     * @param array $args
     * @param array $transactionArgs
     *      string vendor_charge_id - The intent_id from paypal
     *      string total - The amount charged in cents
     *      string status - The status of the transaction ('succeeded', 'pending', etc.))
     *      array payer - The payer information from PayPal.
     *      array payment_source - The payment source information from PayPal.
     *
     * @param string $args ['intent_id'] - The intent ID from Stripe.
     * @return Order
     */
    public function confirmPaymentSuccessByCharge(OrderTransaction $transaction, $transactionArgs = [])
    {
        $transactionUpdateData = array_filter([
            'vendor_charge_id'    => Arr::get($transactionArgs, 'vendor_charge_id', ''),
            'payment_method'      => 'paypal',
            'status'              => Arr::get($transactionArgs, 'status', Status::TRANSACTION_SUCCEEDED),
            'total'               => (int)Arr::get($transactionArgs, 'total', 0),
            // payment_method_type: this is the intent ID. We may need that later In case we don't have the vendor_charge_id
            'payment_method_type' => Arr::get($transactionArgs, 'payment_method_type', ''),
        ]);

        $order = Order::query()->where('id', $transaction->order_id)->first();
        // in race conditions between webhook and AJAX confirmation
        $transaction = OrderTransaction::query()->where('id', $transaction->id)->first();
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED || $transactionUpdateData['status'] !== Status::TRANSACTION_SUCCEEDED) {
            if (!$transaction->vendor_charge_id && !empty($transactionUpdateData['vendor_charge_id'])) {
                $transaction->update(['vendor_charge_id' => $transactionUpdateData['vendor_charge_id']]);
            }
            return $order; // already confirmed or not needed to confirm
        }

        // handle payment source
        $cardData = Arr::get($transactionArgs, 'payment_source.card', []);
        if ($cardData) {
            $transactionUpdateData['card_last_4'] = strlen(Arr::get($cardData, 'last_digits')) > 4 ? substr(Arr::get($cardData, 'last_digits'), -4) : Arr::get($cardData, 'last_digits');
            $transactionUpdateData['card_brand'] = Arr::get($cardData, 'brand');
        }

        $transactionUpdateData['meta'] = array_merge($transaction->meta ?? [], Arr::get($transactionArgs, 'meta', []));

        $transaction->fill($transactionUpdateData);
        $transaction->save();

        fluent_cart_add_log(__('PayPal Payment Confirmation', 'fluent-cart'), __('Payment confirmation received from PayPal. Transaction ID: ', 'fluent-cart') . Arr::get($transactionArgs, 'vendor_charge_id', ''), 'info', [
            'module_name' => 'order',
            'module_id'   => $order->id,
        ]);

        // Maybe we have to save the billing details

        // We are assuming. This is only for one time payment. No subscription or renewal will be here!

        return (new StatusHelper($order))->syncOrderStatuses($transaction);
    }


    // This should be only used from the ajax call for the very first time subscription activation
    public function activateSubscription($paypalSubscription, OrderTransaction $transaction, $subscriptionModel = null)
    {
        $order = $transaction->order;

        if (!$subscriptionModel) {
            $subscriptionModel = Subscription::query()->where('id', $transaction->subscription_id)->first();
        }

        if (!$subscriptionModel) {
            return null;
        }

        if ($order->type !== Status::ORDER_TYPE_RENEWAL && $subscriptionModel->status === Status::SUBSCRIPTION_ACTIVE) {
            return $subscriptionModel;
        }

        // Verify the PayPal subscription's plan matches the expected plan
        if ($subscriptionModel->vendor_plan_id) {
            $paypalPlanId = Arr::get($paypalSubscription, 'plan_id', '');
            if ($paypalPlanId && $paypalPlanId !== $subscriptionModel->vendor_plan_id) {
                fluent_cart_add_log(
                    __('PayPal Subscription Plan Mismatch', 'fluent-cart'),
                    sprintf(
                        /* translators: %1$s: expected plan ID, %2$s: received plan ID */
                        __('PayPal subscription plan mismatch. Expected: %1$s, Received: %2$s. Subscription not activated.', 'fluent-cart'),
                        $subscriptionModel->vendor_plan_id,
                        $paypalPlanId
                    ),
                    'error',
                    [
                        'module_name' => 'order',
                        'module_id'   => $order->id,
                        'log_type'    => 'api'
                    ]
                );
                return $subscriptionModel; // Do not activate
            }
        }

        $nextBillingDate = Arr::get($paypalSubscription, 'billing_info.next_billing_time') ?? null;
        if ($nextBillingDate) {
            $nextBillingDate = gmdate('Y-m-d H:i:s', strtotime($nextBillingDate));
        } else {
            // calculate the next billing date, as PayPal has not been charged yet
            $billingIntervalDays = PaymentHelper::getIntervalDays($subscriptionModel->billing_interval) + (int) $subscriptionModel->trial_days;
            $nextBillingDate = DateTime::gmtNow()->addDays($billingIntervalDays)->format('Y-m-d H:i:s');
        }

        $subscriptionUpdateData = array_filter([
            'next_billing_date'      => $nextBillingDate,
            'status'                 => Status::SUBSCRIPTION_ACTIVE,
            'vendor_subscription_id' => $paypalSubscription['id'],
            'vendor_customer_id'     => Arr::get($paypalSubscription, 'subscriber.payer_id', ''),
            'current_payment_method' => 'paypal',
        ]);

        $lastPaymentAmount = Helper::toCent(Arr::get($paypalSubscription, 'billing_info.last_payment.amount.value', 0));
        $lastPaymentCurrency = strtoupper(Arr::get($paypalSubscription, 'billing_info.last_payment.amount.currency_code', ''));

        // A subscription can legitimately be ACTIVE with no initial payment yet — a free
        // trial, or a future start_time whose first charge PayPal has not run. Only mark the
        // initial transaction SUCCEEDED (which flips the order to paid and triggers
        // fulfilment) when PayPal reports a real initial payment whose amount AND currency
        // match what we expected, or when nothing is owed (total == 0). ACTIVE alone is never
        // treated as paid: an amount- or currency-mismatched payment leaves the order pending
        // for the PAYMENT.SALE.COMPLETED webhook to reconcile, so a forced activation can
        // never deliver a paid product for free.
        $currencyMatches = !$lastPaymentCurrency || !$transaction->currency
            || strtoupper($transaction->currency) === $lastPaymentCurrency;

        $initialPaymentVerified = $lastPaymentAmount
            && $transaction->total == $lastPaymentAmount
            && $currencyMatches;

        if ($initialPaymentVerified || $transaction->total == 0) {
            $transactionUpdateData = array_filter([
                'order_id'       => $order->id,
                'status'         => Status::TRANSACTION_SUCCEEDED,
                'payment_method' => 'paypal',
            ]);

            $transaction->fill($transactionUpdateData);
            $transaction->save();
        } elseif ($lastPaymentAmount && $transaction->total > 0) {
            // A payment was reported but its amount or currency does not match the expected
            // charge — do not mark the order paid; record it for audit (possible tampering).
            fluent_cart_warning_log(
                __('PayPal Subscription Payment Mismatch', 'fluent-cart'),
                sprintf(
                    /* translators: %1$s: expected amount, %2$s: expected currency, %3$s: received amount, %4$s: received currency */
                    __('Subscription initial payment mismatch. Expected: %1$s %2$s, Received: %3$s %4$s. Order not marked paid; awaiting webhook.', 'fluent-cart'),
                    Helper::toDecimal($transaction->total),
                    $transaction->currency,
                    Helper::toDecimal($lastPaymentAmount),
                    $lastPaymentCurrency
                ),
                [
                    'module_name' => 'order',
                    'module_id'   => $order->id,
                    'log_type'    => 'api'
                ]
            );
        }


        if ($order->type === Status::ORDER_TYPE_RENEWAL) {
            $subscriptionUpdateData['canceled_at'] = null;
            $billingInfo = PaymentHelper::parsePaymentMethodDetails('paypal', [
                'email'    => Arr::get($paypalSubscription, 'subscriber.email_address'),
                'payer_id' => Arr::get($paypalSubscription, 'subscriber.payer_id'),
                'name'     => Arr::get($paypalSubscription, 'subscriber.name.given_name') . ' ' . Arr::get($paypalSubscription, 'subscriber.name.surname'),
                'address'  => Arr::get($paypalSubscription, 'subscriber.shipping_address.address')
            ]);

            if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
                SubscriptionService::recordManualRenewal($subscriptionModel, $transaction, [
                    'billing_info'      => $billingInfo,
                    'subscription_args' => $subscriptionUpdateData
                ]);
            } else {
                $subscriptionModel->fill($subscriptionUpdateData)->save();
                $subscriptionModel->updateMeta('active_payment_method', $billingInfo);
                do_action('fluent_cart/renewal/payment_scheduled', [
                    'order'        => $order,
                    'subscription' => $subscriptionModel,
                ]);
            }

        } else {
            // This can be a trialing subscription
            if ($subscriptionModel->trial_days > 0) {
                $subscriptionUpdateData['status'] = Status::SUBSCRIPTION_TRIALING;
            }

            // Atomic conditional update: only the caller that actually flips status out of a
            // pre-active state wins the transition, so concurrent AJAX-return + webhook calls
            // can't both dispatch SubscriptionActivated.
            $activatedNow = (bool) Subscription::query()
                ->where('id', $subscriptionModel->id)
                ->whereNotIn('status', [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])
                ->update($subscriptionUpdateData);

            $subscriptionModel->fill($subscriptionUpdateData);

            // updateMeta() is check-then-create with no unique (subscription_id, meta_key)
            // constraint — gate it behind $activatedNow too, else a losing concurrent caller
            // still inserts a duplicate active_payment_method meta row.
            if ($activatedNow) {
                $subscriptionModel->updateMeta('active_payment_method', PaymentHelper::parsePaymentMethodDetails('paypal', [
                    'email'    => Arr::get($paypalSubscription, 'subscriber.email_address'),
                    'payer_id' => Arr::get($paypalSubscription, 'subscriber.payer_id'),
                    'name'     => Arr::get($paypalSubscription, 'subscriber.name.given_name') . ' ' . Arr::get($paypalSubscription, 'subscriber.name.surname'),
                    'address'  => Arr::get($paypalSubscription, 'subscriber.shipping_address.address')
                ]));

                if (Status::SUBSCRIPTION_ACTIVE === $subscriptionModel->status || Status::SUBSCRIPTION_TRIALING === $subscriptionModel->status) {
                    (new SubscriptionActivated($subscriptionModel, $order, $order->customer))->dispatch();
                }
            }
        }

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            (new StatusHelper($order))->syncOrderStatuses($transaction);
        } else {
            fluent_cart_add_log('PayPal Subscription Activated', 'Subscription activated, transaction & order statuses will be synced on webhook receive.', [
                'module_name' => 'order',
                'module_id'   => $order->id,
            ]);
            if ($subscriptionModel) {
                $subscriptionModel->addLog('PayPal Subscription Activated', 'Subscription activated, transaction & order statuses will be synced on webhook receive.');
            }
        }

        return $subscriptionModel;
    }


    private function toDecimal($cents)
    {
        return Helper::toDecimalWithoutComma($cents);
    }

    /**
     * Persist the vaulted PayPal payment token from a captured order onto the
     * system subscription — the token future renewal charges read (at fire time)
     * from active_payment_method. Idempotent per token; shared by the AJAX
     * confirmation and the PAYMENT.CAPTURE.COMPLETED webhook (whichever lands
     * first wins).
     *
     * When the FIRST (initial) capture of a system subscription carries NO vault
     * token — vaulting declined or unavailable on the merchant account — the
     * subscription is demoted to plain manual invoicing immediately: a `system`
     * subscription without a token would fail every scheduled charge forever.
     *
     * @param OrderTransaction $transaction
     * @param array $paypalOrder The captured Orders-v2 order (full representation).
     */
    public function maybePersistVaultToken(OrderTransaction $transaction, $paypalOrder)
    {
        if (!$transaction->subscription_id || !is_array($paypalOrder)) {
            return;
        }

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->find($transaction->subscription_id);

        if (!$subscription || !$subscription->isSystem()) {
            return;
        }

        $vault = Arr::get($paypalOrder, 'payment_source.paypal.attributes.vault', []);
        $tokenId = Arr::get($vault, 'id', '');

        $existing = $subscription->getMeta('active_payment_method', []) ?: [];

        if ($tokenId) {
            if (Arr::get($existing, 'vendor_method_id') === $tokenId) {
                return; // already persisted (webhook/AJAX race)
            }

            $vaultCustomerId = Arr::get($vault, 'customer.id', '');
            if ($vaultCustomerId && !$subscription->vendor_customer_id) {
                $subscription->vendor_customer_id = $vaultCustomerId;
                $subscription->save();
            }

            $payerEmail = Arr::get($paypalOrder, 'payment_source.paypal.email_address', '');
            if (!$payerEmail) {
                $payerEmail = Arr::get($paypalOrder, 'payer.email_address', '');
            }

            $payerName = trim(Arr::get($paypalOrder, 'payer.name.given_name', '') . ' ' . Arr::get($paypalOrder, 'payer.name.surname', ''));

            $billingInfo = PaymentHelper::parsePaymentMethodDetails('paypal', [
                'email'    => $payerEmail,
                'payer_id' => Arr::get($paypalOrder, 'payer.payer_id', ''),
                'name'     => $payerName,
            ]);
            $billingInfo['vendor_method_id'] = $tokenId;

            $subscription->updateMeta('active_payment_method', $billingInfo);

            $subscription->addLog(
                'PayPal account saved',
                __('PayPal payment method vaulted for automatic renewal charges.', 'fluent-cart'),
                'info'
            );

            return;
        }

        // No token on the INITIAL capture and none stored yet — never leave a
        // system subscription that can never be charged.
        if ($transaction->order
            && $transaction->order->type === Status::ORDER_TYPE_SUBSCRIPTION
            && !Arr::get($existing, 'vendor_method_id')
        ) {
            SystemChargeService::demoteToManual(
                $subscription,
                __('PayPal did not return a saved payment method for automatic charging.', 'fluent-cart')
            );
        }
    }

    /**
     * Merchant-initiated off-session charge of a renewal invoice against the
     * vaulted PayPal token (Orders v2 create with payment_source.paypal.vault_id).
     * Contract per dev-docs/system-subscriptions/gateway-implementation-guide.md:
     * true = confirmed through the normal capture path; 'processing' = accepted
     * but settling (eCheck); WP_Error = definitive failure.
     */
    public function chargeVaultedRenewal(PaymentInstance $paymentInstance, $args = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $subscription = $paymentInstance->subscription;

        if (!$order || !$transaction || !$subscription) {
            return new \WP_Error('invalid_instance', __('Renewal invoice is missing its order, transaction, or subscription.', 'fluent-cart'));
        }

        // Token read AT FIRE TIME — never snapshotted. Both meta shapes accepted.
        $paymentMethodMeta = $subscription->getMeta('active_payment_method', []) ?: [];
        $token = Arr::get($paymentMethodMeta, 'vendor_method_id');
        if (!$token) {
            $token = Arr::get($paymentMethodMeta, 'details.payment_method_id');
        }

        if (!$token) {
            return new \WP_Error('missing_token', __('No saved PayPal payment method is available for this subscription.', 'fluent-cart'));
        }

        $attempt = max(1, (int) Arr::get($args, 'attempt', 1));

        $purchaseUnit = [
            'reference_id' => $transaction->uuid,
            'custom_id'    => $transaction->uuid,
            'amount'       => [
                'currency_code' => strtoupper($transaction->currency),
                'value'         => number_format($this->toDecimal((int) $transaction->total), 2, '.', ''),
            ],
        ];

        $paypalOrder = API::createOrder($purchaseUnit, [
            'payment_source' => ['paypal' => ['vault_id' => $token]],
        ], [
            // One vendor charge per (order, attempt) — a scheduler double-fire
            // replays the original response instead of charging twice.
            'PayPal-Request-Id' => 'fct_system_charge_' . $order->uuid . '_' . $attempt,
        ]);

        if (is_wp_error($paypalOrder)) {
            return $paypalOrder;
        }

        return $this->settleVaultChargeResponse($transaction, $paypalOrder);
    }

    /**
     * Re-check a processing vault charge (lost webhook / slow eCheck). A transient
     * API error reports 'processing' — never fail a possibly-settled payment.
     */
    public function reconcileVaultedRenewal(PaymentInstance $paymentInstance)
    {
        $transaction = $paymentInstance->transaction;

        if (!$transaction) {
            return new \WP_Error('missing_intent', __('No transaction is recorded for this renewal order.', 'fluent-cart'));
        }

        // Preferred: the capture id recorded when the charge was accepted.
        if ($transaction->vendor_charge_id) {
            $capture = API::makeRequest('payments/captures/' . $transaction->vendor_charge_id, 'v2', 'GET');

            if (is_wp_error($capture)) {
                return 'processing';
            }

            $captureStatus = strtoupper((string) Arr::get($capture, 'status', ''));

            if ($captureStatus === 'COMPLETED') {
                $this->confirmPaymentSuccessByCharge(OrderTransaction::query()->find($transaction->id), [
                    'vendor_charge_id'    => Arr::get($capture, 'id', $transaction->vendor_charge_id),
                    'status'              => Status::TRANSACTION_SUCCEEDED,
                    'total'               => Helper::toCent(Arr::get($capture, 'amount.value', 0)),
                    'payment_method_type' => 'PayPal',
                ]);
                return true;
            }

            if ($captureStatus === 'PENDING') {
                return 'processing';
            }

            return new \WP_Error('charge_failed', sprintf(
            /* translators: %1$s: PayPal capture status */
                __('The pending PayPal payment could not be completed (status: %1$s).', 'fluent-cart'),
                $captureStatus !== '' ? $captureStatus : 'unknown'
            ));
        }

        // Fallback: the vault order id stored at charge time.
        $paypalOrderId = Arr::get($transaction->meta ?? [], 'paypal_vault_order_id', '');

        if (!$paypalOrderId) {
            return new \WP_Error('missing_intent', __('No PayPal charge is recorded for this renewal order.', 'fluent-cart'));
        }

        $paypalOrder = API::verifyPayment($paypalOrderId);

        if (is_wp_error($paypalOrder)) {
            return 'processing';
        }

        return $this->settleVaultChargeResponse(OrderTransaction::query()->find($transaction->id), $paypalOrder);
    }

    public function syncRemoteTransaction(OrderTransaction $transaction)
    {
        $mode = $transaction->payment_mode ?: '';

        $capture = API::makeRequest('payments/captures/' . $transaction->vendor_charge_id, 'v2', 'GET', [], $mode);

        if (is_wp_error($capture)) {
            return $capture;
        }

        $captureStatus = strtoupper((string) Arr::get($capture, 'status', ''));

        if ($captureStatus === 'COMPLETED') {
            $captureCurrency = strtoupper((string) Arr::get($capture, 'amount.currency_code', ''));
            if ($captureCurrency && $transaction->currency && strtoupper($transaction->currency) !== $captureCurrency) {
                fluent_cart_warning_log(
                    __('PayPal Currency Mismatch On Sync', 'fluent-cart'),
                    sprintf(
                        /* translators: %1$s: expected currency, %2$s: received currency */
                        __('Capture currency mismatch detected during transaction sync. Expected: %1$s, Received: %2$s. Transaction was not confirmed.', 'fluent-cart'),
                        $transaction->currency,
                        $captureCurrency
                    ),
                    [
                        'module_name' => 'order',
                        'module_id'   => $transaction->order_id,
                        'log_type'    => 'api'
                    ]
                );

                return new \WP_Error('currency_mismatch', __('The PayPal payment currency does not match this transaction. Please verify the payment at PayPal.', 'fluent-cart'));
            }

            $captureAmount = Helper::toCent(Arr::get($capture, 'amount.value', 0));
            if ($captureAmount !== (int) $transaction->total) {
                fluent_cart_warning_log(
                    __('PayPal Amount Mismatch On Sync', 'fluent-cart'),
                    sprintf(
                        /* translators: %1$s: expected amount, %2$s: received amount */
                        __('Capture amount mismatch detected during transaction sync. Expected: %1$s, Received: %2$s. Transaction was not confirmed.', 'fluent-cart'),
                        Helper::toDecimal($transaction->total),
                        Helper::toDecimal($captureAmount)
                    ),
                    [
                        'module_name' => 'order',
                        'module_id'   => $transaction->order_id,
                        'log_type'    => 'api'
                    ]
                );

                return new \WP_Error('amount_mismatch', __('The PayPal payment amount does not match this transaction. Please verify the payment at PayPal.', 'fluent-cart'));
            }

            $this->confirmPaymentSuccessByCharge(OrderTransaction::query()->find($transaction->id), [
                'vendor_charge_id'    => Arr::get($capture, 'id', $transaction->vendor_charge_id),
                'status'              => Status::TRANSACTION_SUCCEEDED,
                'total'               => Helper::toCent(Arr::get($capture, 'amount.value', 0)),
                'payment_method_type' => 'PayPal',
            ]);

            return OrderTransaction::query()->find($transaction->id);
        }

        if ($captureStatus === 'PENDING') {
            return new \WP_Error('still_pending', sprintf(
            /* translators: %1$s: PayPal pending hold reason */
                __('The payment is still pending at PayPal (reason: %1$s). Please try again later.', 'fluent-cart'),
                Arr::get($capture, 'status_details.reason', '') ?: 'unknown'
            ));
        }

        return new \WP_Error('charge_not_completed', sprintf(
        /* translators: %1$s: PayPal capture status */
            __('The PayPal payment could not be completed (status: %1$s).', 'fluent-cart'),
            $captureStatus !== '' ? $captureStatus : 'unknown'
        ));
    }

    /**
     * Shared outcome derivation for a vault-charged Orders-v2 order: record the
     * ids for reconciliation, confirm completed captures through the normal
     * capture path, report settling captures as 'processing', everything else as
     * a definitive failure with PayPal's reason.
     *
     * Public so an extension charging a vaulted token outside the renewal engine
     * (saved payment methods) settles through this exact contract rather than
     * reimplementing it. The PENDING branch in particular is money-critical: a
     * settling eCheck is neither paid nor failed, and a duplicate of this logic
     * would eventually drift and mis-report one.
     *
     * @return true|string|\WP_Error true = captured, 'processing' = settling
     */
    public function settleVaultChargeResponse(OrderTransaction $transaction, $paypalOrder)
    {
        $orderStatus = strtoupper((string) Arr::get($paypalOrder, 'status', ''));
        $capture = Arr::get($paypalOrder, 'purchase_units.0.payments.captures.0', []);
        $captureId = Arr::get($capture, 'id', '');
        $captureStatus = strtoupper((string) Arr::get($capture, 'status', ''));

        // Persist ids FIRST — the reconciliation loop and webhook dedup key on them.
        $transactionMeta = array_merge($transaction->meta ?? [], [
            'paypal_vault_order_id' => Arr::get($paypalOrder, 'id', ''),
        ]);
        $transactionUpdate = ['meta' => $transactionMeta];
        if ($captureId && !$transaction->vendor_charge_id) {
            $transactionUpdate['vendor_charge_id'] = $captureId;
        }
        $transaction->update($transactionUpdate);

        if ($captureId && $captureStatus === 'COMPLETED') {
            $this->confirmPaymentSuccessByCharge(OrderTransaction::query()->find($transaction->id), [
                'vendor_charge_id'    => $captureId,
                'status'              => Status::TRANSACTION_SUCCEEDED,
                'total'               => Helper::toCent(Arr::get($capture, 'amount.value', 0)),
                'payment_method_type' => 'PayPal',
                'payment_source'      => Arr::get($paypalOrder, 'payment_source', []),
                'meta'                => ['payer' => Arr::get($paypalOrder, 'payer', [])],
            ]);
            return true;
        }

        if ($captureStatus === 'PENDING' || $orderStatus === 'PENDING') {
            return 'processing';
        }

        $reason = Arr::get($capture, 'status_details.reason', '');

        if ($reason) {
            /* translators: %1$s: PayPal decline reason code */
            $message = sprintf(__('Automatic PayPal charge failed: %1$s', 'fluent-cart'), $reason);
        } else {
            /* translators: %1$s: PayPal order status */
            $message = sprintf(__('Automatic PayPal charge could not be completed (status: %1$s).', 'fluent-cart'), $orderStatus !== '' ? $orderStatus : 'unknown');
        }

        return new \WP_Error('charge_failed', $message);
    }

}
