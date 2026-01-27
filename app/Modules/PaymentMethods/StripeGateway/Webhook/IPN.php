<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway\Webhook;

use FluentCart\App\Events\Order\OrderRefund;
use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Confirmations;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\StripeHelper;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API;
use FluentCart\Framework\Support\Arr;

class IPN
{
    public function init(): void
    {
        // DONE!
        add_action('fluent_cart/payments/stripe/webhook_charge_refunded', [$this, 'handleChargeRefunded'], 10, 1);

        // Done
        add_action('fluent_cart/payments/stripe/webhook_charge_succeeded', [$this, 'handleChargeSucceeded'], 10, 1);

        add_action('fluent_cart/payments/stripe/webhook_charge_dispute_created', [$this, 'handleChargeDisputeCreated'], 10, 1);
        add_action('fluent_cart/payments/stripe/webhook_charge_dispute_closed', [$this, 'handleChargeDisputeClosed'], 10, 1);

        // For Hosted Checkout (Checkout Sessions)
        add_action('fluent_cart/payments/stripe/webhook_checkout_session_completed', [$this, 'handleCheckoutSessionCompleted'], 10, 1);

        // For Subscriptions
        add_action('fluent_cart/payments/stripe/webhook_customer_subscription_updated', [$this, 'handleSubscriptionUpdated'], 10, 1);
        add_action('fluent_cart/payments/stripe/webhook_customer_subscription_deleted', [$this, 'handleSubscriptionUpdated'], 10, 1); // canceled event
    }


    public function handleChargeRefunded($data)
    {
        $event = Arr::get($data, 'event');
        $order = Arr::get($data, 'order');
        $order = Order::query()->where('id', $order->id)->first(); // we are just renewing it

        $eventArray = json_decode(json_encode($event), true);
        $charge = Arr::get($eventArray, 'data.object', []);

        $refunds = Arr::get($charge, 'refunds.data', []);

        if (!$refunds) {
            return false;
        }

        $parentTransaction = OrderTransaction::query()->where('vendor_charge_id', Arr::get($charge, 'payment_intent'))
            ->where('status', Status::TRANSACTION_SUCCEEDED)
            ->first();

        if (!$parentTransaction) {
            return false;
        }

        $generalData = [
            'order_id'         => $order->id,
            'order_type'       => $order->type,
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'payment_method'   => 'stripe',
            'payment_mode'     => $event->livemode ? 'live' : 'test',
            'card_last_4'      => Arr::get($charge, 'payment_method_details.card.last4', ''),
            'card_brand'       => Arr::get($charge, 'payment_method_details.card.brand', ''),
        ];

        $paymentMethodType = Arr::get($charge, 'payment_method_details.type', '');

        if (!$paymentMethodType) {
            $paymentMethodType = $parentTransaction->payment_method_type;
        }

        $currentCreatedRefund = null;
        foreach ($refunds as $refund) {
            $refundMethodType = Arr::get($refund, 'destination_details.type', '');
            if (!$refundMethodType) {
                $refundMethodType = $paymentMethodType;
            }

            $reason = Arr::get($refund, 'reason', 'other') ? Arr::get($refund, 'reason', 'other') : 'not specified';

            $refundCurrency = Arr::get($charge, 'currency') ?? $order->currency;
            $normalizedRefundAmount = (int)Arr::get($refund, 'amount', 0);

            if ($refundCurrency && CurrenciesHelper::isZeroDecimal($refundCurrency)) {
                $normalizedRefundAmount = $normalizedRefundAmount * 100;
            }

            $refundData = [
                'payment_method_type' => $refundMethodType,
                'vendor_charge_id'    => Arr::get($refund, 'id'),
                'status'              => Status::TRANSACTION_REFUNDED,
                'currency'            => $refundCurrency,
                'total'               => $normalizedRefundAmount,
                'meta'                => [
                    'reason'         => $reason,
                    'transaction_id' => $parentTransaction ? $parentTransaction->id : null,
                ],
                'uuid'                => md5(time() . wp_generate_uuid4()),
                'created_at'          => gmdate('Y-m-d H:i:s', Arr::get($refund, 'created', time())),
                'updated_at'          => gmdate('Y-m-d H:i:s', Arr::get($refund, 'created', time())),
            ];
            $refundData = wp_parse_args($refundData, $generalData);

            $syncedRefund = StripeHelper::createOrUpdateIpnRefund($refundData, $parentTransaction);

            if ($syncedRefund->wasRecentlyCreated) {
                $currentCreatedRefund = $syncedRefund;
            }
        }

        (new OrderRefund($order, $currentCreatedRefund))->dispatch();
    }

    public function handleChargeSucceeded($data)
    {
        $event = Arr::get($data, 'event');
        $order = Arr::get($data, 'order');
        $eventArray = json_decode(json_encode($event), true);
        $charge = Arr::get($eventArray, 'data.object');

        $intentId = Arr::get($charge, 'payment_intent');


        if (!$intentId) {
            return false; // no payment intent found
        }

        $transaction = OrderTransaction::query()->where('vendor_charge_id', $intentId)->first();

        if (!$transaction) {
            $chargeCurrency = Arr::get($charge, 'currency', $order->currency);
            $normalizedChargeAmount = (int)Arr::get($charge, 'amount', 0);

            if ($chargeCurrency && CurrenciesHelper::isZeroDecimal($chargeCurrency)) {
                $normalizedChargeAmount = $normalizedChargeAmount * 100;
            }

            $transaction = OrderTransaction::query()
                ->where('order_id', $order->id)
                ->where('status', Status::TRANSACTION_PENDING)
                ->where('total', $normalizedChargeAmount)
                ->orderBy('id', 'DESC')
                ->first();
        }

        if (!$transaction) {
            return false;
        }

        (new Confirmations())->confirmPaymentSuccessByCharge($transaction, [
            'charge'    => $charge,
            'intent_id' => $intentId
        ]);
    }

    public function handleChargeDisputeCreated($data)
    {
        $event = Arr::get($data, 'event');
        $order = Arr::get($data, 'order');
        $eventArray = json_decode(json_encode($event), true);
        $disputedCharge = Arr::get($eventArray, 'data.object');

        $disputeId = Arr::get($disputedCharge, 'id');
        $intentId = Arr::get($disputedCharge, 'payment_intent');
        $status = Arr::get($disputedCharge, 'status');

        if (!$intentId || !in_array($status, ['needs_response', 'under_review', 'warning_needs_response'])) {
            return false;
        }

        $transactionModel = OrderTransaction::query()->where('vendor_charge_id', $intentId)->first();

        if (!$transactionModel || $transactionModel->transaction_type === Status::TRANSACTION_TYPE_DISPUTE) {
            return false;
        }

        $reason = Arr::get($disputedCharge, 'reason');

        $isChargeRefundable = Arr::get($disputedCharge, 'is_charge_refundable', false);

        // make this transaction type dispute if not already
        $transactionModel->transaction_type = Status::TRANSACTION_TYPE_DISPUTE;
        $transactionModel->meta = array_merge($transactionModel->meta ?? [], [
            'dispute_id'      => $disputeId,
            'dispute_reason'  => $reason,
            'is_dispute_actionable' => in_array(Arr::get($disputedCharge, 'status'), ['needs_response', 'warning_needs_response']),
            'is_charge_refundable' => $isChargeRefundable,
            'dispute_status' => $status
        ]);

        $transactionModel->save();

        fluent_cart_warning_log('This payment was disputed', 'Disputed claimed for this payment due to ' . $reason, [
            'module_name' => 'order',
            'module_id'   => $order->id,
            'log_type'    => 'api'
        ]);

        return true;

    }


    public function handleChargeDisputeClosed($data)
    {
        $event = Arr::get($data, 'event');
        $order = Arr::get($data, 'order');
        $eventArray = json_decode(json_encode($event), true);
        $disputedCharge = Arr::get($eventArray, 'data.object');

        $intentId = Arr::get($disputedCharge, 'payment_intent');

        if (!$intentId) {
            return false; // no payment intent found
        }

        $transactionModel = OrderTransaction::query()->where('vendor_charge_id', $intentId)->first();

        $status = Arr::get($disputedCharge, 'status');
        $reason = Arr::get($disputedCharge, 'reason');

        if (!$transactionModel || $transactionModel->status === Status::TRANSACTION_DISPUTE_LOST) {
            return false;
        }

        if (in_array($status, ['won', 'prevented', 'warning_closed'])) {
            $transactionModel->transaction_type = Status::TRANSACTION_TYPE_CHARGE;
            $transactionModel->meta = array_merge($transactionModel->meta, [
                'is_dispute_actionable' => false,
                'is_charge_refundable' => false,
                'dispute_status' => $status
            ]);
            $transactionModel->save();

            $title = 'Dispute won!';
            $content = 'Dispute won for this payment due to ' . $reason;

            if ($status == 'prevented') {
                $title = 'Dispute prevented!';
                $content = 'Dispute was prevented from becoming a formal chargeback. ' . $reason;
            } else if(  $status == 'warning_closed') {
                $title = 'Dispute warning closed!';
                $content = 'An inquiry closed without becoming a formal dispute.';
            }

            fluent_cart_add_log($title, $content, 'info', [
                'module_name' => 'order',
                'module_id'   => $order->id,
                'log_type'    => 'api'
            ]);
            return true;

        } else if ($status == 'lost') {
            $transactionModel->status = Status::TRANSACTION_DISPUTE_LOST;
            $transactionModel->meta = array_merge($transactionModel->meta ?? [], [
                'is_dispute_actionable' => false,
                'is_charge_refundable' => false,
                'dispute_status' => $status
            ]);
            $transactionModel->save();

            fluent_cart_add_log('Dispute lost', 'Dispute lost for this payment . ' . $transactionModel->vendor_charge_id, 'info', [
                'module_name' => 'order',
                'module_id'   => $order->id,
                'log_type'    => 'api'
            ]);

            $newPaidAmount = intval($transactionModel->order->total_paid - $transactionModel->total);
            $transactionModel->order->update([
                'total_paid' => max($newPaidAmount, 0),
                'payment_status' => $newPaidAmount > 0 ? Status::PAYMENT_PARTIALLY_PAID : Status::PAYMENT_FAILED,
            ]);
        }

        return true;
    }

    /**
     * Handle checkout.session.completed webhook for hosted checkout mode
     * This ensures webhooks work properly even if redirect confirmation hasn't happened yet
     */
    public function handleCheckoutSessionCompleted($data)
    {
        $event = Arr::get($data, 'event');
        $order = Arr::get($data, 'order');
        $eventArray = json_decode(json_encode($event), true);
        $session = Arr::get($eventArray, 'data.object');

        $sessionId = Arr::get($session, 'id');
        $paymentIntentId = Arr::get($session, 'payment_intent');
        $paymentStatus = Arr::get($session, 'payment_status');
        $mode = Arr::get($session, 'mode');

        if (!$sessionId) {
            return false;
        }

        // Find transaction by session_id stored in meta
        $transaction = OrderTransaction::query()
            ->where('order_id', $order->id)
            ->whereRaw("JSON_EXTRACT(meta, '$.session_id') = ?", [$sessionId])
            ->first();

        // Fallback: try to find by vendor_charge_id if it was stored as session_id
        if (!$transaction) {
            $transaction = OrderTransaction::query()
                ->where('order_id', $order->id)
                ->where('vendor_charge_id', $sessionId)
                ->first();
        }

        if (!$transaction) {
            return false;
        }

        // Skip if already confirmed
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return true;
        }

        // Update vendor_charge_id to payment_intent for future webhook lookups
        if ($paymentIntentId && $mode === 'payment') {
            $transaction->update([
                'vendor_charge_id' => $paymentIntentId
            ]);
        }

        // For subscription mode, update vendor_subscription_id
        if ($mode === 'subscription') {
            $subscriptionId = Arr::get($session, 'subscription');
            if ($subscriptionId) {
                $subscription = Subscription::query()->where('id', $transaction->subscription_id)->first();
                if ($subscription) {
                    $subscription->update([
                        'vendor_subscription_id' => $subscriptionId
                    ]);
                }

                // Update transaction with payment_intent if available
                if ($paymentIntentId) {
                    $transaction->update([
                        'vendor_charge_id' => $paymentIntentId
                    ]);
                }
            }
        }

        return true;
    }

    public function handleSubscriptionUpdated($data)
    {
        $event = Arr::get($data, 'event');
        $order = Arr::get($data, 'order');

        $currentSubscription = Subscription::query()->where('parent_order_id', $order->id)->first();

        if (!$currentSubscription) {
            return false; // no subscription found
        }

        return $currentSubscription->reSyncFromRemote();
    }

    public function verifyAndProcess()
    {
        $data = (new API())->verifyIPN();
        if (is_wp_error($data)) {
            $this->sendResponse(400, $data->get_error_message());
        }

        $acceptedEvents = [
            'invoice.paid', // Reviewed for subscription cycle
            'charge.refunded', // reviewed
            'charge.succeeded', // reviewed
            'charge.dispute.created',
            'charge.dispute.closed',
            'checkout.session.completed',
            'customer.subscription.deleted',
            'customer.subscription.updated',
        ];

        $eventType = $data->type;
        if (!in_array($eventType, $acceptedEvents)) {
            $this->sendResponse(200, 'Event type not accepted.');
        }

        $eventId = $data->id;
        $event = (new API())->getEvent($eventId);

        if (!$event || is_wp_error($event)) {
            $this->sendResponse(400, 'Event not found or error occurred.');
        }

        // get the order from the event, in case of renewal create one
        $order = (new Webhook())->processAndInsertOrderByEvent($event);

        if (!$order) {
            // This is already handled or not our event
            $this->sendResponse(200, 'Event not handled or not related to an order.');
        }

        if (is_wp_error($order)) {
            $this->sendResponse(400, 'Order not found or error occurred. Error: '. $order->get_error_message());
        }

        $eventType = str_replace('.', '_', $event->type);

        if (has_action('fluent_cart/payments/stripe/webhook_' . $eventType)) {

            do_action('fluent_cart/payments/stripe/webhook_' . $eventType, [
                'event' => $event,
                'order' => $order
            ]);

            $this->sendResponse(200, 'Webhook event processed successfully.');
        }

        $this->sendResponse(200, 'No handler found for this event type.');

    }

    protected function sendResponse($statusCode = 200, $message = 'Success')
    {
        wp_send_json([
            'message' => $message,
        ], $statusCode);
    }

}
