<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway\Webhook;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\StripeHelper;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\Framework\Support\Arr;

class Webhook
{
    const WEBHOOK_ENDPOINT = '?fluent-cart=fct_payment_listener_ipn&method=stripe';

    public static function getURL(): string
    {
        return site_url() . self::WEBHOOK_ENDPOINT;
    }

    public static function getEvents(): array
    {
        return [
            'checkout.session.completed',
            'charge.refunded',
            'charge.refund.updated',
            'charge.succeeded',
            'invoice.paid',
            'customer.subscription.deleted',
            'customer.subscription.updated',
            'invoice.payment_failed'
        ];
    }

    public static function webhookInstruction(): string
    {
        $webhook_url = static::getURL();
        return sprintf(
            '<div>
                <p><b>%1$s</b><code class="copyable-content">%2$s</code></p>
                <p>%3$s</p>
                <br>
                <h4>%4$s</h4>
                <br>
                <p>%5$s</p>
                <p class="fct_hide_on_test">%6$s <a href="https://dashboard.stripe.com/webhooks/create?events=checkout.session.completed%%2Ccharge.refunded%%2Ccharge.refund.updated%%2Ccharge.succeeded%%2Cinvoice.paid%%2Ccustomer.subscription.deleted%%2Ccustomer.subscription.updated%%2Cinvoice.payment_failed%%2Ccharge.captured%%2Ccharge.dispute.closed%%2Ccharge.dispute.created%%2Cinvoice_payment.paid%%2Cpayment_intent.succeeded" target="_blank">%7$s</a></p>
                <p class="fct_hide_on_live">%6$s <a href="https://dashboard.stripe.com/test/webhooks/create?events=checkout.session.completed%%2Ccharge.refunded%%2Ccharge.refund.updated%%2Ccharge.succeeded%%2Cinvoice.paid%%2Ccustomer.subscription.deleted%%2Ccustomer.subscription.updated%%2Cinvoice.payment_failed%%2Ccharge.captured%%2Ccharge.dispute.closed%%2Ccharge.dispute.created%%2Cinvoice_payment.paid%%2Cpayment_intent.succeeded" target="_blank">%7$s</a></p>
                <p>%8$s <code class="copyable-content">%2$s</code></p>
                <b>%9$s</b>
                checkout.session.completed, <br/>
                charge.refunded, <br/>
                charge.refund.updated, <br/>
                charge.succeeded, <br/>
                invoice.paid, <br/>
                invoice.payment_failed, <br/>
                customer.subscription.deleted, <br/>
                customer.subscription.updated, <br/>
                <br/>
            </div>',
            __('Webhook URL: ', 'fluent-cart'),                    // %1$s
            $webhook_url,                                          // %2$s (reused)
            __('You should configure your Stripe webhooks to get all updates of your payments remotely.', 'fluent-cart'), // %3$s
            __('How to configure?', 'fluent-cart'),                // %4$s
            __('In your Stripe account:', 'fluent-cart'),          // %5$s
            __('Go to Developers > Webhooks >', 'fluent-cart'),    // %6$s
            __('Add endpoint', 'fluent-cart'),                     // %7$s
            __('Enter The Webhook URL:', 'fluent-cart'),           // %8$s
            __('Select these events:', 'fluent-cart')              // %9$s
        );
    }

    public function processAndInsertOrderByEvent($event)
    {
        $eventType = $event->type;

        $metaDataEvents = [
            'invoice.paid', // Reviewed for subscription cycle
            'charge.refunded', // reviewed
            'charge.succeeded', // reviewed
            'charge.dispute.created',
            'charge.dispute.closed',
            'checkout.session.completed',
            'customer.subscription.deleted',
            'customer.subscription.updated',
        ];

        if (!in_array($eventType, $metaDataEvents)) {
            return false;
        }

        $vendorDataObject = $event->data->object;

        if ($eventType == 'invoice.paid') {
            //check if subscription billing_cycle invoice paid or failed
            $isSubscriptionCycle = $vendorDataObject->billing_reason === 'subscription_cycle';
            if ($isSubscriptionCycle) {
                if ($eventType === 'invoice.paid') {
                    $this->processSubscriptionRenewal($vendorDataObject);
                }
                return false;
            }
        }

        if ($eventType === 'charge.refunded' || $eventType === 'charge.succeeded') {
            $paymentIntent = $vendorDataObject->payment_intent;
            $orderTransaction = OrderTransaction::query()->where('vendor_charge_id', $paymentIntent)
                ->where('transaction_type', 'charge')
                ->first();

            if (!$orderTransaction) {
                $orderTransaction = apply_filters('fluent_cart/stripe/fallback_order_transaction', null, $vendorDataObject);
                if (!$orderTransaction || $orderTransaction instanceof OrderTransaction) {
                    $orderTransaction = null;
                }
            }

            if ($orderTransaction) {
                $order = Order::where('id', $orderTransaction->order_id)->first();
                $order->current_transaction = $orderTransaction;
                return $order;
            }
        }

        if ($eventType === 'customer.subscription.deleted' || $eventType === 'customer.subscription.updated') {

            $vendorSubscriptionId = $vendorDataObject->id;

            $subscription = Subscription::query()
                ->where('vendor_subscription_id', $vendorSubscriptionId)
                ->first();

            if ($subscription) {
                $order = Order::where('id', $subscription->parent_order_id)->first();
                if ($order) {
                    $order->current_subscription = $subscription;
                }
                return $order;
            }
        }

        if ($eventType === 'charge.dispute.created' || $eventType === 'charge.dispute.closed') {
            $paymentIntent = $vendorDataObject->payment_intent;
            $orderTransaction = OrderTransaction::query()->where('vendor_charge_id', $paymentIntent)
                ->first();

            if ($orderTransaction) {
                return $orderTransaction->order;
            }
            return null;
        }

        $metaData = (array)$vendorDataObject->metadata;
        $orderHash = Arr::get($metaData, 'fct_ref_id', false);

        if ($orderHash) {
            return Order::query()->where('uuid', $orderHash)->first();
        }

        return null;
    }

    public function processSubscriptionRenewal($vendorInvoiceObject)
    {
        $subscription = null;
        $parentOrder = null;

        $vendorSubscriptionId = $vendorInvoiceObject->subscription;

        if ($vendorSubscriptionId) {
            $subscription = Subscription::query()->where('vendor_subscription_id', $vendorSubscriptionId)
                ->orderBy('id', 'DESC')
                ->first();
        }

        if ($subscription) {
            $parentOrder = Order::query()->where('id', $subscription->parent_order_id)->first();
        }

        if ($parentOrder) {
            // let's try to find from the meta ref id
            if (!empty($vendorInvoiceObject->subscription_details->metadata->fct_ref_id)) {
                $refId = $vendorInvoiceObject->subscription_details->metadata->fct_ref_id;
                $parentOrder = Order::query()->where('uuid', $refId)->first();
            }
        }

        if ($parentOrder && !$subscription) {
            $subscription = Subscription::query()
                ->where('parent_order_id', $parentOrder->id)
                ->orderBy('id', 'DESC')
                ->first();
        }

        if (!$parentOrder || !$subscription || $subscription->current_payment_method !== 'stripe') {
            fluent_cart_error_log('Stripe Webhook Error: Subscription Renewal - Order or Subscription not found.', 'Vendor Subscription ID: ' . $vendorSubscriptionId);
            return false; // this is not our order
        }

        if ($vendorInvoiceObject->payment_intent) {
            $alreadyRecorded = OrderTransaction::query()
                ->where('subscription_id', $subscription->id)
                ->where('vendor_charge_id', $vendorInvoiceObject->payment_intent)
                ->exists();
            if ($alreadyRecorded) {
                return false;
            }
        }

        $transactionData = [
            'payment_method'   => 'stripe',
            'total'            => $vendorInvoiceObject->amount_paid,
            'vendor_charge_id' => $vendorInvoiceObject->payment_intent
        ];

        $paymentIntent = (new API())->getStripeObject('payment_intents/' . $vendorInvoiceObject->payment_intent, [], $parentOrder->mode);
        if (!is_wp_error($paymentIntent)) {
            $transactionData['card_last_4'] = Arr::get($paymentIntent, 'charges.data.0.payment_method_details.card.last4', '');
            $transactionData['card_brand'] = (string)Arr::get($paymentIntent, 'charges.data.0.payment_method_details.card.brand', '');
            $transactionData['payment_method_type'] = (string)Arr::get($paymentIntent, 'charges.data.0.payment_method_details.type', '');
        } else {
            $activePaymentMethod = $subscription->getMeta('active_payment_method', []);
            if (!$activePaymentMethod || !is_array($activePaymentMethod)) {
                $activePaymentMethod = [];
            }
            if ($activePaymentMethod) {
                $transactionData['card_last_4'] = Arr::get($activePaymentMethod, 'details.last_4');
                $transactionData['card_brand'] = (string)Arr::get($activePaymentMethod, 'details.brand');
                $transactionData['payment_method_type'] = (string)Arr::get($activePaymentMethod, 'details.type');
            }
        }

        $subscriptionUpdateData = array_filter([
            'current_payment_method' => 'stripe'
        ]);

        $stripeSubscription = (new API())->getStripeObject('subscriptions/' . $vendorSubscriptionId, [
            'expand' => ['latest_invoice']
        ], $parentOrder->mode);

        if (!is_wp_error($stripeSubscription)) {
            $subscriptionUpdateData = StripeHelper::getSubscriptionUpdateData($stripeSubscription, $subscription);
        }

        SubscriptionService::recordRenewalPayment($transactionData, $subscription, $subscriptionUpdateData);


        $subscription = Subscription::query()->find($subscription->id);

        if ($subscription && $subscription->status === Status::SUBSCRIPTION_COMPLETED) {
            if (!is_wp_error($stripeSubscription)) {
                if ($stripeSubscription['status'] === 'active') {
                    $deleted = (new API)->deleteStripeObject('subscriptions/' . $vendorSubscriptionId, [], $parentOrder->mode);
                    if (is_wp_error($deleted)) {
                        fluent_cart_error_log('Stripe Subscription Deletion Error. Subscription ID: ' . $subscription->id, $deleted->get_error_message());
                    }
                }
            }
        }


    }

}
