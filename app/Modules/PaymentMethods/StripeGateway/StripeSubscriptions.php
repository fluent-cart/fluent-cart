<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;

class StripeSubscriptions extends AbstractSubscriptionModule
{
    /**
     * Read-only lookup used by the admin "Edit Vendor IDs" verify action.
     *
     * Stripe addresses subscriptions directly; the customer it reports back is what
     * the admin compares against before saving.
     */
    public function verifyVendorSubscription(array $args, $mode = 'current')
    {
        $vendorSubscriptionId = Arr::get($args, 'vendor_subscription_id');

        if (!$vendorSubscriptionId) {
            return new \WP_Error('invalid_subscription', __('A Vendor Subscription ID is required to look up a Stripe subscription.', 'fluent-cart'));
        }

        $subscription = (new API())->getStripeObject('subscriptions/' . $vendorSubscriptionId, [], $mode);

        if (is_wp_error($subscription)) {
            return $subscription;
        }

        $currency = strtoupper((string) Arr::get($subscription, 'currency'));
        $amount = Arr::get($subscription, 'items.data.0.price.unit_amount');

        if ($amount !== null) {
            $amount = CurrenciesHelper::isZeroDecimal($currency)
                ? (string) (int) $amount
                : number_format(((int) $amount) / 100, 2, '.', '');
        }

        $nextBilling = Arr::get($subscription, 'current_period_end');

        return [
            'id'                => Arr::get($subscription, 'id'),
            'status'            => Arr::get($subscription, 'status'),
            'customer_id'       => Arr::get($subscription, 'customer'),
            'amount'            => $amount === null ? '' : $amount,
            'currency'          => $currency,
            'next_billing_date' => $nextBilling ? gmdate('Y-m-d H:i:s', (int) $nextBilling) : '',
        ];
    }

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
            'expand' => ['latest_invoice', 'default_payment_method']
        ], $order->mode);

        if (is_wp_error($stripeSubscription)) {
            return $stripeSubscription;
        }

        $this->syncActivePaymentMethod($subscriptionModel, $stripeSubscription);

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
            if (Arr::get($invoice, 'amount_paid') == 0) {
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

                $amountPaid = Arr::get($invoice, 'amount_paid');
                $chargeCurrency = Arr::get($invoice, 'currency');

                if ($chargeCurrency && CurrenciesHelper::isZeroDecimal($chargeCurrency)) {
                    $amountPaid = $amountPaid * 100;
                }

                $transactionData = [
                    'payment_method'   => 'stripe',
                    'total'            => (int)$amountPaid,
                    'vendor_charge_id' => Arr::get($invoice, 'payment_intent'),
                    'created_at'       => ($paidAt = Arr::get($invoice, 'status_transitions.paid_at')) ? DateTime::anyTimeToGmt($paidAt)->format('Y-m-d H:i:s') : DateTime::now()->format('Y-m-d H:i:s'),
                ];

                // The remote timestamp is the settlement moment; without it the
                // model hook would stamp the (much later) resync time.
                if ($paidAt) {
                    $transactionData['meta'] = array_merge($transactionData['meta'] ?? [], ['settled_at' => DateTime::anyTimeToGmt($paidAt)->format('Y-m-d H:i:s')]);
                }

                $paymentIntent = (new API())->getStripeObject('payment_intents/' . Arr::get($invoice, 'payment_intent'), ['expand' => ['latest_charge']], $order->mode);

                if (!is_wp_error($paymentIntent) && Arr::get($paymentIntent, 'latest_charge')) {
                    $transactionData['created_at'] = DateTime::anyTimeToGmt(Arr::get($paymentIntent, 'latest_charge.created'))->format('Y-m-d H:i:s');
                    $transactionData['meta'] = array_merge($transactionData['meta'] ?? [], ['settled_at' => $transactionData['created_at']]);
                    $paymentMethodType = Arr::get($paymentIntent, 'latest_charge.payment_method_details.type', '');
                    $transactionData['payment_method_type'] = $paymentMethodType;
                    if ($paymentMethodType === 'sepa_debit') {
                        $transactionData['card_last_4'] = Arr::get($paymentIntent, 'latest_charge.payment_method_details.sepa_debit.last4', '');
                        $transactionData['card_brand'] = 'sepa_debit';
                    } else {
                        $transactionData['card_last_4'] = Arr::get($paymentIntent, 'latest_charge.payment_method_details.card.last4', '');
                        $transactionData['card_brand'] = Arr::get($paymentIntent, 'latest_charge.payment_method_details.card.brand', '');
                    }
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
                // Empty-only, same contract as the model hook: the invoice's
                // paid_at is the settlement moment, not this resync's run time.
                $paidAt = Arr::get($invoice, 'status_transitions.paid_at');
                if ($paidAt && empty($transaction->meta['settled_at'])) {
                    $transaction->meta = array_merge($transaction->meta, [
                        'settled_at' => DateTime::anyTimeToGmt($paidAt)->format('Y-m-d H:i:s')
                    ]);
                }

                $transaction->update([
                    'vendor_charge_id' => Arr::get($invoice, 'payment_intent'),
                    'status'           => Status::TRANSACTION_SUCCEEDED,
                    'total'            => (int)Arr::get($invoice, 'amount_paid')
                ]);

                (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
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

    /**
     * The card behind the vendor subscription can change outside our card-update
     * flow (Stripe-hosted portal, dunning card replacement) — such a change fires
     * customer.subscription.updated, which lands here via reSyncFromRemote. Carry
     * the remote default payment method into active_payment_method, or the portal
     * and admin keep rendering the old card.
     */
    private function syncActivePaymentMethod(Subscription $subscriptionModel, $stripeSubscription)
    {
        $paymentMethod = Arr::get($stripeSubscription, 'default_payment_method');

        if (!is_array($paymentMethod) || !Arr::get($paymentMethod, 'id')) {
            return;
        }

        $existing = $subscriptionModel->getMeta('active_payment_method', []) ?: [];
        // Meta has two shapes in the wild: details.payment_method_id (card-update
        // flow) and vendor_method_id (confirmation paths) — accept both.
        $existingMethodId = Arr::get($existing, 'details.payment_method_id') ?: Arr::get($existing, 'vendor_method_id');

        if ($existingMethodId === Arr::get($paymentMethod, 'id')) {
            return;
        }

        $value = PaymentHelper::parsePaymentMethodDetails('stripe', $paymentMethod);

        $rows = SubscriptionMeta::query()
            ->where('subscription_id', $subscriptionModel->id)
            ->where('meta_key', 'active_payment_method')
            ->orderBy('id', 'ASC')
            ->get();

        if ($rows->isEmpty()) {
            SubscriptionMeta::query()->create([
                'subscription_id' => $subscriptionModel->id,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'        => 'active_payment_method',
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value'      => $value,
            ]);

            $rows = SubscriptionMeta::query()
                ->where('subscription_id', $subscriptionModel->id)
                ->where('meta_key', 'active_payment_method')
                ->orderBy('id', 'ASC')
                ->get();
        } else {
            $rows->first()->update(['meta_value' => $value]);
        }

        if ($rows->count() > 1) {
            SubscriptionMeta::query()
                ->whereIn('id', $rows->slice(1)->pluck('id')->toArray())
                ->delete();
        }
    }

    public function cancel($vendorSubscriptionId, $args = [])
    {
        if (!$vendorSubscriptionId) {
            return new \WP_Error('invalid_subscription', __('Invalid vendor subscription ID.', 'fluent-cart'));
        }

        // first check if the subscription is already canceled in Stripe
        $response = (new API())->getStripeObject('subscriptions/' . $vendorSubscriptionId, [], Arr::get($args, 'mode', 'live'));

        if (is_wp_error($response)) {
            return $response;
        }

        $status = StripeHelper::transformSubscriptionStatus($response);

        if ($status == Status::SUBSCRIPTION_CANCELED) {
            $canceledAt = Arr::get($response, 'canceled_at');
            return [
                'status' => Status::SUBSCRIPTION_CANCELED,
                'canceled_at' =>  $canceledAt ? gmdate('Y-m-d H:i:s', $canceledAt) : NULL
            ];
        }

        $response = (new API())->deleteStripeObject('subscriptions/' . $vendorSubscriptionId, [], Arr::get($args, 'mode', 'live'));

        if (is_wp_error($response)) {
            return $response;
        }

        $canceledAt = Arr::get($response, 'canceled_at');

        return [
            'status'      => StripeHelper::transformSubscriptionStatus($response),
            'canceled_at' => $canceledAt ? gmdate('Y-m-d H:i:s', $canceledAt) : NULL
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
