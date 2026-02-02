<?php

namespace FluentCartPro\App\Modules\PaymentMethods\MollieGateway;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCartPro\App\Modules\PaymentMethods\MollieGateway\API\MollieAPI;

class Confirmations
{
    public function init(){
        
        add_action('fluent_cart/before_render_redirect_page', [$this, 'maybeConfirmPayment'], 10, 1);

    }

    public function maybeConfirmPayment($data)
    {
        $isReceipt = Arr::get($data, 'is_receipt', false);
        $method = Arr::get($data, 'method', '');

        if ($isReceipt || $method !== 'mollie') {
            return;
        }

        $transactionHash = Arr::get($data, 'trx_hash', '');

        $transaction = OrderTransaction::query()->where('uuid', $transactionHash)
            ->where('payment_method', 'mollie')
            ->first();

        if (!$transaction || $transaction->status === Status::TRANSACTION_SUCCEEDED || $transaction->status === Status::TRANSACTION_AUTHORIZED) {
            return;
        }

        // get payment from mollie and confirm payment status
        $payment = (new MollieAPI())->getMollieObject('payments/' . $transaction->vendor_charge_id); 
        
        if (is_wp_error($payment)) {
            return;
        }

        $mandateId = Arr::get($payment, 'mandateId');

        $billingInfo = [
            'method' => Arr::get($payment, 'method'),
            'brand' => Arr::get($payment, 'details.cardLabel'),
            'last4' => Arr::get($payment, 'details.cardNumber'),
            'token' => Arr::get($payment, 'details.cardToken'),
            'mandate_id' => $mandateId
        ];

        $subscriptionData = [];
        if ($mandateId) {
              $subscriptionData = $this->handleSubscriptionCreation($transaction, [
                'mandate_id' => $mandateId,
                'billingInfo' => $billingInfo
              ]);
        }


        $status = Arr::get($payment, 'status');

        if ($status === 'paid') {
            $this->confirmPaymentSuccessByCharge($transaction, [
                'charge' => $payment,
                'vendor_charge_id' => $transaction->vendor_charge_id,
                'subscription_data' => $subscriptionData
            ]);
        } elseif ($status === 'authorized') {
            $this->authorizePaymentByCharge($transaction, [
                'charge' => $payment,
                'vendor_charge_id' => $transaction->vendor_charge_id,
                'subscription_data' => $subscriptionData
            ]);
        }
    }

    public function confirmPaymentSuccessByCharge(OrderTransaction $transaction, $args = [])
    {
        $charge = Arr::get($args, 'charge', []);
        $vendorChargeId = Arr::get($args, 'vendor_charge_id');
        $subscriptionData = Arr::get($args, 'subscription_data', []);

        $order = $transaction->order;

        if ($order == null || $transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        $billingInfo = [];

        $mandateId = Arr::get($charge, 'mandateId');

         $billingInfo = [
            'method' => Arr::get($charge, 'method'),
            'brand' => Arr::get($charge, 'details.cardLabel'),
            'last4' => Arr::get($charge, 'details.cardNumber'),
            'token' => Arr::get($charge, 'details.cardToken'),
            'mandate_id' => $mandateId
        ];
        
        // if we consider authorized as success state, then it success related actions already is handled
        // currently not handled , just implemented for later use
        if ($transaction->status === Status::TRANSACTION_AUTHORIZED && (new MollieSettingsBase())->get('is_authorize_a_success_state') == 'yes') {
            $transaction->status = Status::TRANSACTION_SUCCEEDED;
            $transaction->save();

            fluent_cart_add_log(__('Mollie Payment Confirmation', 'fluent-cart-pro'), __('Payment confirmation received from Mollie for previously authorized payment. Transaction ID: ', 'fluent-cart-pro')  . $vendorChargeId, 'info', [
                'module_name' => 'order',
                'module_id'   => $order->id,
            ]);

            return $order;
        }

        $amount = Arr::get($charge, 'amount.value');
        $currency = Arr::get($charge, 'amount.currency');
        $amountInCents = MollieHelper::convertToCents($amount, $currency);

        $details = Arr::get($charge, 'details', []);

        $transactionUpdateData = array_filter([
            'order_id'            => $order->id,
            'total'               => $amountInCents,
            'currency'            => $currency,
            'status'              => Status::TRANSACTION_SUCCEEDED,
            'payment_method'      => 'mollie',
            'card_last_4'         => Arr::get($details, 'cardNumber', ''),
            'card_brand'          => Arr::get($details, 'cardLabel', ''),
            'payment_method_type' => Arr::get($charge, 'method', ''),
            'vendor_charge_id'    => $vendorChargeId,
            'payment_mode'        => $order->mode,
            'meta'                => array_merge($transaction->meta ?? [], $billingInfo)
        ]);

        $transaction->fill($transactionUpdateData);
        $transaction->save();

        if ($order->type == status::ORDER_TYPE_RENEWAL) {
            $subscriptionModel = Subscription::query()->where('id', $transaction->subscription_id)->first();

            if (!$subscriptionModel || !$subscriptionData) {
                return $order; // No subscription found for this renewal order. Something is wrong.
            }
            return SubscriptionService::recordManualRenewal($subscriptionModel, $transaction, [
                'billing_info'      => $billingInfo,
                'subscription_args' => $subscriptionData
            ]);
        }

        fluent_cart_add_log(__('Mollie Payment Confirmation', 'fluent-cart-pro'), __('Payment confirmation received from Mollie. Transaction ID: ', 'fluent-cart-pro')  . $vendorChargeId, 'info', [
            'module_name' => 'order',
            'module_id'   => $order->id,
        ]);

        return (new StatusHelper($order))->syncOrderStatuses($transaction);

    }

    public function authorizePaymentByCharge(OrderTransaction $transaction, $args = [])
    {
        $charge = Arr::get($args, 'charge', []);
        $vendorChargeId = Arr::get($args, 'vendor_charge_id');
        $subscriptionData = Arr::get($args, 'subscription_data', []);

        $order = $transaction->order;

        if ($order == null || $transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }   

        $mandateId = Arr::get($charge, 'mandateId');

        $billingInfo = [
            'method' => Arr::get($charge, 'method'),
            'brand' => Arr::get($charge, 'details.cardLabel'),
            'last4' => Arr::get($charge, 'details.cardNumber'),
            'token' => Arr::get($charge, 'details.cardToken'),
            'mandate_id' => $mandateId
        ];

        $amount = Arr::get($charge, 'amount.value');
        $currency = Arr::get($charge, 'amount.currency');
        $amountInCents = MollieHelper::convertToCents($amount, $currency);

        $details = Arr::get($charge, 'details', []);

        $transactionUpdateData = array_filter([
            'order_id'            => $order->id,
            'total'               => $amountInCents,
            'currency'            => $currency,
            'status'              => Status::TRANSACTION_AUTHORIZED,
            'payment_method'      => 'mollie',
            'card_last_4'         => Arr::get($details, 'cardNumber', ''),
            'card_brand'          => Arr::get($details, 'cardLabel', ''),
            'payment_method_type' => Arr::get($charge, 'method', ''),
            'vendor_charge_id'    => $vendorChargeId,
            'payment_mode'        => $order->mode,
            'meta'                => array_merge($transaction->meta ?? [], $billingInfo)
        ]);

        $transaction->fill($transactionUpdateData);
        $transaction->save();

        fluent_cart_add_log(__('Mollie Payment Authorized', 'fluent-cart-pro'), __('Payment Authorized in Mollie. Transaction ID: ', 'fluent-cart-pro')  . $vendorChargeId, 'info', [
            'module_name' => 'order',
            'module_id'   => $order->id,
        ]);

        if ($order->type == status::ORDER_TYPE_RENEWAL) {
            $subscriptionModel = Subscription::query()->where('parent_order_id', $transaction->order_id)->first();

            if (!$subscriptionModel || !$subscriptionData) {
                return $order; // No subscription found for this renewal order. Something is wrong.
            }
            return SubscriptionService::recordManualRenewal($subscriptionModel, $transaction, [
                'billing_info'      => $billingInfo,
                'subscription_args' => $subscriptionData
            ]);
        }

        $isAuthorizedASuccessState = (new MollieSettingsBase())->get('is_authorize_a_success_state') == 'yes';

        if ($isAuthorizedASuccessState) {
            (new StatusHelper($order))->syncOrderStatuses($transaction);
        } else {
            $relatedCart = Cart::query()->where('order_id', $order->id)
                ->where('stage', '!=', 'completed')
                ->first();

            if ($relatedCart) {
                $relatedCart->stage = 'completed';
                $relatedCart->completed_at = DateTime::now()->format('Y-m-d H:i:s');
                $relatedCart->save();

                do_action('fluent_cart/cart_completed', [
                    'cart'  => $relatedCart,
                    'order' => $order,
                ]);

                $onSuccessActions = Arr::get($relatedCart->checkout_data, '__on_success_actions__', []);

                if ($onSuccessActions) {
                    foreach ($onSuccessActions as $onSuccessAction) {
                        $onSuccessAction = (string)$onSuccessAction;
                        if (has_action($onSuccessAction)) {
                            do_action($onSuccessAction, [
                                'cart'        => $relatedCart,
                                'order'       => $order,
                                'transaction' => $transaction
                            ]);
                        }
                    }
                }
            }
        }
        
        return $order;

    }


    public function handleSubscriptionCreation(OrderTransaction $transaction, $args = [])
    {
        $subscriptionModel = Subscription::query()
            ->where('id', $transaction->subscription_id)
            ->first();
        
        if (!$subscriptionModel || !empty($subscriptionModel->vendor_subscription_id)) {
            return null;
        }
        
        $mandateId = Arr::get($args, 'mandate_id');
        $mandate = (new MollieAPI())->getMollieObject('customers/' . $subscriptionModel->vendor_customer_id . '/mandates/' . $mandateId);

        if (is_wp_error($mandate)) {
            return null;
        }

        if (Arr::get($mandate, 'status') != 'valid') {
            return null;
        }

        $order = $transaction->order;

        $intervalMap = [
            'daily'       => '1 day',
            'weekly'      => '1 week',
            'monthly'     => '1 month',
            'quarterly'   => '3 months',
            'half_yearly' => '6 months',
            'yearly'      => '12 months',
        ];

        $billingPeriod = [
            'interval_unit' => Arr::get($intervalMap, $subscriptionModel->billing_interval, '1 month')
        ];

        $billingPeriod = apply_filters('fluent_cart/subscription_billing_period', $billingPeriod, [
            'subscription_interval' => $subscriptionModel->billing_interval,
            'payment_method' => 'mollie',
        ]);

        $billsCount = OrderTransaction::query()
            ->where('subscription_id', $subscriptionModel->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->where('total', '>', 0)
            ->count();


        $description = MollieHelper::generateSubscriptionDescription($subscriptionModel, $order->currency, $order->type);
        $startDate = MollieHelper::calculateSubscriptionStartDate($subscriptionModel, $order);


        // now create the subscription in mollie, remember first payment is already done
        $mollieSubscriptionData = [
            'amount' => [
                'value' => (new MollieProcessor())->formatAmount($subscriptionModel->recurring_total, $transaction->currency),
                'currency' => $transaction->currency
            ],
            'interval' => Arr::get($billingPeriod, 'interval_unit'),
            'description' =>  $description,
            'webhookUrl' => (new MollieProcessor())->getWebhookUrl(),
            'mandateId' => $mandateId,
            'startDate' => $startDate,
            'metadata' => [
                'subscription_hash' => $subscriptionModel->uuid,
                'order_hash' => $transaction->order->uuid
            ]
        ];
       

        if ($subscriptionModel->bill_times > 0) {
            $times = $subscriptionModel->bill_times - $billsCount;
            if ($times < 1) {
               // no need to create subscription as all bills are already paid
               return $subscriptionModel->reSyncFromRemote();

            }
            $mollieSubscriptionData['times'] = $times;
        }


        $mollieSubscription = (new MollieAPI())->createMollieObject('customers/' . $subscriptionModel->vendor_customer_id . '/subscriptions', $mollieSubscriptionData);

        if (is_wp_error($mollieSubscription)) {

            // log the error message
            fluent_cart_add_log(__('Mollie Subscription Creation Failed', 'fluent-cart-pro'), __('Failed to create subscription in Mollie. Error: ', 'fluent-cart-pro')  . $mollieSubscription->get_error_message(), 'error', [
                'module_name' => 'order',
                'module_id'   => $order->id,
            ]);

            return null;
        }
        
        $oldStatus = $subscriptionModel->status;

        $updateData = [
            'vendor_subscription_id' => Arr::get($mollieSubscription, 'id'),
            'current_payment_method' => 'mollie',
            'status' => Arr::get($mollieSubscription, 'status'),
            'bill_count' => $billsCount,
            'next_billing_date' => Arr::get($mollieSubscription, 'nextPaymentDate'),
            'vendor_response' => json_encode($mollieSubscription)
        ];

        $subscriptionModel->update($updateData);

        $subscriptionModel->updateMeta('active_payment_method', Arr::get($args, 'billingInfo', []));

        if ($oldStatus != status::SUBSCRIPTION_ACTIVE && Arr::get($mollieSubscription, 'status') === Status::SUBSCRIPTION_ACTIVE) {
            (new SubscriptionActivated($subscriptionModel, $order, $order->customer))->dispatch();
        }

        return $updateData;

    }
}
