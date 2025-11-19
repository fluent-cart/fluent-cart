<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

class StripeSubscriptions extends AbstractSubscriptionModule
{

    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)
    {
        if ($subscriptionModel->current_payment_method !== 'stripe') {
            return new \WP_Error('invalid_payment_method', __('This subscription is not using Stripe as payment method.', 'fluent-cart'));
        }

        $order = $subscriptionModel->order;

        $vendorSubscriptionId = $subscriptionModel->vendor_subscription_id;
        if (!$vendorSubscriptionId) {
            return new \WP_Error('invalid_subscription', __('Invalid vendor subscription ID.', 'fluent-cart'));
        }

        $stripeSubscription = (new API())->getStripeObject('subscriptions/' . $vendorSubscriptionId, [
            'expand' => ['latest_invoice']
        ], $order->mode);

        if (is_wp_error($stripeSubscription)) {
            return $stripeSubscription;
        }

        $invoices = (new API())->getStripeObject('invoices', [
            'subscription' => $vendorSubscriptionId,
            'status'       => 'paid'
        ], $order->mode);

        if (is_wp_error($invoices)) {
            return $invoices;
        }

        $invoices = Arr::get($invoices, 'data', []);

        $subscriptionUpdateData = StripeHelper::getSubscriptionUpdateData($stripeSubscription, $subscriptionModel);

        // reverse the array to get the latest transaction last
        $invoices = array_reverse($invoices);
        $newPayment = false;

        foreach ($invoices as $key => $invoice) {
            //$invoice is array
            if (Arr::get($invoice, 'amount_paid') == 0 || Arr::get($invoice, 'charge') == '') {
                continue;
            }

            $transaction = OrderTransaction::query()
                ->whereIn('vendor_charge_id', [
                    Arr::get($invoice, 'payment_intent'),
                    Arr::get($invoice, 'charge')
                ])
                ->where('transaction_type', 'charge')
                ->first();

            if (!$transaction) {
                // check local transactions missing vendor_charge_id
                $transaction = OrderTransaction::query()
                    ->select(['id', 'order_id'])
                    ->where('subscription_id', $subscriptionModel->id)
                    ->where('vendor_charge_id', '')
                    ->where('transaction_type', 'charge')
                    ->where('total', (int)Arr::get($invoice, 'amount_paid'))
                    ->first();

                if ($transaction) {
                    $transaction->update([
                        'vendor_charge_id' => Arr::get($invoice, 'payment_intent')
                    ]);
                    continue;
                }

                $transactionData = [
                    'payment_method'   => 'stripe',
                    'total'            => (int)Arr::get($invoice, 'amount_paid'),
                    'vendor_charge_id' => Arr::get($invoice, 'payment_intent'),
                    'created_at'       => ($paidAt = Arr::get($invoice, 'status_transitions.paid_at')) ? DateTime::anyTimeToGmt($paidAt)->format('Y-m-d H:i:s') : DateTime::now()->format('Y-m-d H:i:s'),
                ];

                $paymentIntent = (new API())->getStripeObject('payment_intents/' . Arr::get($invoice, 'payment_intent'), ['expand' => ['latest_charge']], $order->mode);

                if (!is_wp_error($paymentIntent) && Arr::get($paymentIntent, 'latest_charge')) {
                    $transactionData['created_at'] = DateTime::anyTimeToGmt(Arr::get($paymentIntent, 'latest_charge.created'))->format('Y-m-d H:i:s');
                    $transactionData['payment_method_type'] = Arr::get($paymentIntent, 'latest_charge.payment_method_details.type', '');
                    $transactionData['card_last_4'] = Arr::get($paymentIntent, 'latest_charge.payment_method_details.card.last4', '');
                    $transactionData['card_brand'] = Arr::get($paymentIntent, 'latest_charge.payment_method_details.card.brand', '');
                } else {
                    $activePaymentMethod = $subscriptionModel->getMeta('active_payment_method', []);
                    if (!$activePaymentMethod || !is_array($activePaymentMethod)) {
                        $activePaymentMethod = [];
                    }
                    if ($activePaymentMethod) {
                        $transactionData['card_last_4'] = Arr::get($activePaymentMethod, 'details.last_4');
                        $transactionData['card_brand'] = Arr::get($activePaymentMethod, 'details.brand');
                    }
                }

                $newPayment = true;
                SubscriptionService::recordRenewalPayment($transactionData, $subscriptionModel, $subscriptionUpdateData);
            } else {
                $transaction->update([
                    'vendor_charge_id' => Arr::get($invoice, 'payment_intent'),
                    'status'           => Status::TRANSACTION_SUCCEEDED,
                    'total'            => (int)Arr::get($invoice, 'amount_paid')
                ]);
            }
        }

        if (!$newPayment) {
            $subscriptionModel = SubscriptionService::syncSubscriptionStates($subscriptionModel, $subscriptionUpdateData);
        } else {
            $subscriptionModel = Subscription::find($subscriptionModel->id);
        }

        if ($subscriptionModel->status == Status::SUBSCRIPTION_COMPLETED && $stripeSubscription['status'] === 'active') {
            $response = (new API)->deleteStripeObject('subscriptions/' . $vendorSubscriptionId, [], $order->mode);

            if (is_wp_error($response)) {
                fluent_cart_error_log('Stripe Subscription Deletion Error. Subscription ID: ' . $subscriptionModel->id, $response->get_error_message());
            }
        }

        return $subscriptionModel;
    }

    public function cancel($vendorSubscriptionId, $args = [])
    {
        if (!$vendorSubscriptionId) {
            return new \WP_Error('invalid_subscription', __('Invalid vendor subscription ID.', 'fluent-cart'));
        }

        $response = (new API())->deleteStripeObject('subscriptions/' . $vendorSubscriptionId, [], Arr::get($args, 'mode', 'live'));

        if (is_wp_error($response)) {
            return $response;
        }

        $canceledAt = Arr::get($response, 'canceled_at');

        return [
            'status'      => StripeHelper::transformSubscriptionStatus($response),
            'canceled_at' => $canceledAt ? gmdate('Y-m-d H:i:s', strtotime(Arr::get($response, 'canceled_at'))) : NULL
        ];
    }

    public function cardUpdate($data, $subscriptionId)
    {
        (new UpdateCustomerPaymentMethod())->update($data, $subscriptionId);
    }

    public function switchPaymentMethod($data, $subscriptionId)
    {
        (new SwitchCustomerMethod())->switchPayMethod($data, $subscriptionId);
    }
}
