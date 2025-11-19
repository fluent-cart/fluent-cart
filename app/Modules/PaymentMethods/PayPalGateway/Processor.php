<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway;

use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;

class Processor
{
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
                    'value'         => $perQuantity,
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
                'value'         => $chargingAmount,
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
                'value'         => $shippingAmount,
            ];
            $pushedTotal += $shippingAmount;
        }



        $taxTotal = $this->toDecimal($order->tax_total) + $this->toDecimal($order->shipping_tax);
        if ($taxTotal > 0 && $order->tax_behavior == 1) {
            $purchaseUnits['amount']['breakdown']['tax_total'] = [
                'currency_code' => $transaction->currency,
                'value'         => $taxTotal,
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
            'response'           => $purchaseUnits
        ];
    }

    public function handleSubscriptionPaymentFromPaymentInstance(PaymentInstance $paymentInstance, $args = [])
    {
        $orderType = $paymentInstance->order->type;
        $subscription = $paymentInstance->subscription;
        $initialAmount = (int)$subscription->signup_fee + $paymentInstance->getExtraAddonAmount();
        $status = Status::SUBSCRIPTION_INTENDED;

        if ($orderType == 'renewal') {
            $requiredBillTimes = $subscription->getRequiredBillTimes();

            if ($requiredBillTimes === -1) {
                return new \WP_Error('already_completed', __('Invalid bill times for the subscription.', 'fluent-cart'));
            }

            $data = [
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
                'product_id'       => $subscription->product_id,
                'variation_id'     => $subscription->variation_id,
                'trial_days'       => $subscription->trial_days,
                'billing_interval' => $subscription->billing_interval,
                'currency'         => $paymentInstance->order->currency,
                'interval_count'   => 1, // 1
                'recurring_amount' => $subscription->recurring_total, // default recurring total in cents
                'signup_fee'       => $initialAmount, // default setup fee in cents ($0.00)
                'bill_times'       => (int)$subscription->bill_times, // 0 for unlimited
            ];

        }

        $paypalPlan = PayPalHelper::getPayPalPlan($data);

        if (is_wp_error($paypalPlan)) {
            return $paypalPlan;
        }

        $subscription->update([
            'status'          => $status,
            'vendor_plan_id'  => Arr::get($paypalPlan, 'id'),
            'vendor_response' => json_encode($paypalPlan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

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

        $parentOrderId = $order->id;
        if ($transaction->order_type === Status::ORDER_TYPE_RENEWAL) {
            $parentOrderId = $transaction->order->parent_id;
        };

        if (!$subscriptionModel) {
            $subscriptionModel = Subscription::query()->where('parent_order_id', $parentOrderId)->first();
        }

        if (!$subscriptionModel || $subscriptionModel->status === Status::SUBSCRIPTION_ACTIVE) {
            return $subscriptionModel; // already active or invalid
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

        $transactionUpdateData = [];
        $lastTransactionAmount = Helper::toCent(Arr::get($paypalSubscription, 'billing_info.last_payment.amount.value', 0));

        if (($lastTransactionAmount && $transaction->total == $lastTransactionAmount) || $transaction->total == 0) {
            $transactionUpdateData = [
                'order_id'       => $order->id,
                'status'         => Status::TRANSACTION_SUCCEEDED,
                'payment_method' => 'paypal'
            ];
        }

        if ($transactionUpdateData) {
            $transactionUpdateData = array_filter([
                'order_id'       => $order->id,
                'status'         => Status::TRANSACTION_SUCCEEDED,
                'payment_method' => 'paypal',
            ]);

            $transaction->fill($transactionUpdateData);
            $transaction->save();
        }


        if ($order->type === Status::ORDER_TYPE_RENEWAL) {
            $subscriptionUpdateData['canceled_at'] = null;
            $billingInfo = PaymentHelper::parsePaymentMethodDetails('paypal', [
                'email'    => Arr::get($paypalSubscription, 'subscriber.email_address'),
                'payer_id' => Arr::get($paypalSubscription, 'subscriber.payer_id'),
                'name'     => Arr::get($paypalSubscription, 'subscriber.name.given_name') . ' ' . Arr::get($paypalSubscription, 'subscriber.name.surname'),
                'address'  => Arr::get($paypalSubscription, 'subscriber.shipping_address.address')
            ]);

            SubscriptionService::recordManualRenewal($subscriptionModel, $transaction, [
                'billing_info'      => $billingInfo,
                'subscription_args' => $subscriptionUpdateData
            ]);

        } else {
            // This can be a trialing subscription
            if ($subscriptionModel->trial_days > 0) {
                $subscriptionUpdateData['status'] = Status::SUBSCRIPTION_TRIALING;
            }

            $oldStatus = $subscriptionModel->status;

            $subscriptionModel->fill($subscriptionUpdateData);
            $subscriptionModel->save();

            $subscriptionModel->updateMeta('active_payment_method', PaymentHelper::parsePaymentMethodDetails('paypal', [
                'email'    => Arr::get($paypalSubscription, 'subscriber.email_address'),
                'payer_id' => Arr::get($paypalSubscription, 'subscriber.payer_id'),
                'name'     => Arr::get($paypalSubscription, 'subscriber.name.given_name') . ' ' . Arr::get($paypalSubscription, 'subscriber.name.surname'),
                'address'  => Arr::get($paypalSubscription, 'subscriber.shipping_address.address')
            ]));

            if ($oldStatus != $subscriptionModel->status && (Status::SUBSCRIPTION_ACTIVE === $subscriptionModel->status || Status::SUBSCRIPTION_TRIALING === $subscriptionModel->status)) {
                (new SubscriptionActivated($subscriptionModel, $order, $order->customer))->dispatch();
            }
        }

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            (new StatusHelper($order))->syncOrderStatuses($transaction);
        } else {
            fluent_cart_add_log('PayPal Subscription Activated', 'Subscription activated, transaction & order statuses will be synced on webhook receive.', [
                'module_name' => 'order',
                'module_id'   => $order->id,
            ]);
        }

        return $subscriptionModel;
    }


    private function toDecimal($cents)
    {
        return Helper::toDecimalWithoutComma($cents);
    }

}
