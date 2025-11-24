<?php

namespace FluentCartPro\App\Modules\PaymentMethods\MollieGateway\Webhook;

use FluentCart\Api\Confirmation;
use FluentCart\App\Events\Order\OrderRefund;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\PaymentMethods\MollieGateway\API\MollieAPI;
use FluentCartPro\App\Modules\PaymentMethods\MollieGateway\Confirmations;
use FluentCartPro\App\Modules\PaymentMethods\MollieGateway\MollieSettingsBase;
use FluentCartPro\App\Modules\PaymentMethods\MollieGateway\MollieHelper;

class MollieIPN
{
    public function init(): void
    {
        // Primary payment webhook handlers
        add_action('fluent_cart/payments/mollie/webhook_payment_authorized', [$this, 'handlePaymentAuthorized'], 10, 1);
        add_action('fluent_cart/payments/mollie/webhook_payment_paid', [$this, 'handlePaymentPaid'], 10, 1);
        add_action('fluent_cart/payments/mollie/webhook_payment_failed', [$this, 'handlePaymentFailed'], 10, 1);
        add_action('fluent_cart/payments/mollie/webhook_payment_canceled', [$this, 'handlePaymentCanceled'], 10, 1);
        add_action('fluent_cart/payments/mollie/webhook_payment_expired', [$this, 'handlePaymentExpired'], 10, 1);
        add_action('fluent_cart/payments/mollie/webhook_payment_refunded', [$this, 'handlePaymentRefunded'], 10, 1);

        // Subscription related webhook handlers
        add_action('fluent_cart/payments/mollie/webhook_subscription_payment_paid', [$this, 'handleSubscriptionPaymentPaid'], 10, 1);
        
    }

    public function verifyAndProcess()
    {
        // Get webhook data
        $data = (new MollieAPI())->verifyIPN();
        
        if (is_wp_error($data)) {
            $this->sendResponse(400, $data->get_error_message());
        }

        $paymentId = $data->id ?? '';

        if (!$paymentId) {
            $this->sendResponse(400, 'Invalid payment ID');
        }

        $payment = (new MollieAPI())->getMollieObject('payments/' . $paymentId, ['embed' => 'refunds']);

        if (is_wp_error($payment)) {
            $this->sendResponse(400, 'Unable to fetch payment from Mollie');
        }

        // Get order from metadata
        $orderHash = Arr::get($payment, 'metadata.order_hash');

        $order = Order::query()->where('uuid', $orderHash)->first();

        if (!$order) {
            $this->sendResponse(404, 'Order not found');
        }

        if (!$order) {
            $this->sendResponse(404, 'Order not found');
        }


        $status = Arr::get($payment, 'status');

        // Check for refunds when fetching the payment
        $refunds = Arr::get($payment, '_embedded.refunds', []);

        if (!empty($refunds)) {
            return $this->handlePaymentRefunded([
                'payment' => $payment,
                'order'   => $order,
            ]);
        }

        $subscriptionId = Arr::get($payment, 'subscriptionId');
        if ($subscriptionId) {
            $eventName = 'webhook_subscription_payment_' . $status;
            if (has_action('fluent_cart/payments/mollie/' . $eventName)) {
                do_action('fluent_cart/payments/mollie/' . $eventName, [
                    'payment' => $payment,
                    'order'   => $order,
                ]);
                $this->sendResponse(200, 'Webhook processed successfully');
            }
            $this->sendResponse(200, 'Webhook received but no handler found for subscription payment ' . $status);
        }

        // Process based on status
        $eventName = 'webhook_payment_' . $status;
        
        if (has_action('fluent_cart/payments/mollie/' . $eventName)) {
            do_action('fluent_cart/payments/mollie/' . $eventName, [
                'payment' => $payment,
                'order'   => $order,
            ]);

            $this->sendResponse(200, 'Webhook processed successfully');
        }

        // Log unhandled webhook status
        fluent_cart_add_log(
            'Mollie webhook unhandled status',
            sprintf('Status: %s for Payment ID: %s', $status, $paymentId),
            'info',
            [
                'log_type' => 'webhook',
                'module_type' => 'FluentCartPro\App\Modules\PaymentMethods\MollieGateway',
                'module_name' => 'Mollie',
                'payment_id' => $paymentId
            ]
        );

        $this->sendResponse(200, 'Webhook received but no handler found for status: ' . $status);
    }

    public function handlePaymentPaid($data)
    {
        $payment = Arr::get($data, 'payment');
        $order = Arr::get($data, 'order');

        if (!$order) {
            return false;
        }

        $paymentId = Arr::get($payment, 'id');

        $transactionHash = Arr::get($payment, 'metadata.transaction_hash');

        $query = OrderTransaction::query()
            ->where('vendor_charge_id', $paymentId);
            
        if (!empty($transactionHash)) {
            $query->orWhere('uuid', $transactionHash);
        }
        
        $transaction = $query->first();

        if (!$transaction) {
           $subsctipionId = Arr::get($payment, 'subscriptionId');

           if ($subsctipionId) {
               return $this->handleSubscriptionPaymentPaid($data);
           }
        }

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return true;
        }

        (new Confirmations())->confirmPaymentSuccessByCharge($transaction, [
            'vendor_charge_id' => $paymentId,
            'charge' => $payment
        ]);

        return true;
    }

    public function handleSubscriptionPaymentPaid($data){
        
        $payment = Arr::get($data, 'payment');
        $order = Arr::get($data, 'order');

        if (!$order) {
            return false;
        }

        $mollieSubscriptionId = Arr::get($payment, 'subscriptionId');

        if (!$mollieSubscriptionId) {
            return false;
        }


        $subscriptionModel = Subscription::query()->where('vendor_subscription_id', $mollieSubscriptionId)->first();

        if (!$subscriptionModel) {
            $subscriptionModel = Subscription::query()->where('parent_order_id', $order->id)->first();
        }

        if (!$subscriptionModel) {
            return false;
        }

        $subscriptionModel->reSyncFromRemote();


    }

    public function handlePaymentCanceled($data)
    {
        return $this->handlePaymentFailed($data, 'canceled');
    }

    public function handlePaymentExpired($data)
    {
        return $this->handlePaymentFailed($data, 'expired');
    }

    public function handlePaymentAuthorized($data)
    {
        $payment = Arr::get($data, 'payment');
        $order = Arr::get($data, 'order');

        if (!$order) {
            return false;
        }

        $paymentId = Arr::get($payment, 'id');
        
        // Get metadata for transaction lookup
        $transactionHash = Arr::get($payment, 'metadata.transaction_hash');

        // Find transaction by payment ID
        $query = OrderTransaction::query()
            ->where('vendor_charge_id', $paymentId);
            
        if (!empty($transactionHash)) {
            $query->orWhere('uuid',  $transactionHash);
        }
        
        $transaction = $query->first();

        if (!$transaction) {
            return false;
        }

        if (!$transaction || $transaction->status === Status::TRANSACTION_SUCCEEDED || $transaction->status === Status::TRANSACTION_AUTHORIZED) {
            return false;
        }
        

        (new Confirmations())->authorizePaymentByCharge($transaction, [
            'vendor_charge_id' => $paymentId,
            'charge' => $payment
        ]);

        return true;
    }

     public function handlePaymentFailed($data, $reason = 'failed')
     {
         $payment = Arr::get($data, 'payment');
         $order = Arr::get($data, 'order');

         $transactionHash = Arr::get($payment, 'metadata.transaction_hash');

         $paymentId = Arr::get($payment, 'id');

         $query = OrderTransaction::query()
             ->where('vendor_charge_id', $paymentId);

         if (!empty($transactionHash)) {
             $query->orWhere('uuid', $transactionHash);
         }

         $transaction = $query->first();

         if (!$transaction) {
             return false;
         }

         $oldStatus = $order->payment_status;

         if ($transaction->status !== Status::TRANSACTION_AUTHORIZED) {
             return false;

         }

         $transactionData = [
             'status' => Status::TRANSACTION_FAILED,
             'meta'   => array_merge($transaction->meta ?? [], [
                 'failure_reason' => 'failed',
                 'mollie_status'  => 'failed'
             ])
         ];

         $transaction->fill($transactionData);
         $transaction->save();

         $order->update([
             'payment_status' => Status::PAYMENT_FAILED,
             'status'         => Status::ORDER_FAILED
         ]);

         fluent_cart_error_log(
             'Payment ' . $reason,
             __('Payment ' . $reason . ' via Mollie', 'fluent-cart-pro'),
             [
                 'module_name' => 'order',
                 'module_id'   => $order->id,
                 'log_type'    => 'payment'
             ]
         );


         do_action('fluent_cart/payment_failed', [
             'order'   => $order,
             'transaction' => $transaction,
             'old_payment_status' => $oldStatus,
             'new_payment_status' => Status::PAYMENT_FAILED,
             'reason' => $reason
         ]);

         return true;
     }

    public function handlePaymentRefunded($data)
    {
        $payment = Arr::get($data, 'payment');
        $order = Arr::get($data, 'order');

        $paymentId = Arr::get($payment, 'id');

        // Get parent transaction
        $parentTransaction = OrderTransaction::query()
            ->where('vendor_charge_id', $paymentId)
            ->where('status', Status::TRANSACTION_SUCCEEDED)
            ->first();

        if (!$parentTransaction) {
            return false;
        }

        // Get refunds from payment
        $refunds = Arr::get($payment, '_embedded.refunds', []);

        if (empty($refunds)) {
            return false;
        }

        $currentCreatedRefund = null;
        foreach ($refunds as $refund) {
            $refundId = Arr::get($refund, 'id');
            $refundAmount = Arr::get($refund, 'amount.value');
            $refundCurrency = Arr::get($refund, 'amount.currency');
            $amountInCents = MollieHelper::convertToCents($refundAmount, $refundCurrency);

            // Prepare refund data matching Stripe pattern
            $refundData = [
                'order_id'           => $order->id,
                'transaction_type'   => Status::TRANSACTION_TYPE_REFUND,
                'status'             => Status::TRANSACTION_REFUNDED,
                'payment_method'     => 'mollie',
                'payment_mode'       => $parentTransaction->payment_mode,
                'vendor_charge_id'   => $refundId,
                'total'              => $amountInCents,
                'currency'           => $refundCurrency,
                'meta'               => [
                    'parent_id'          => $parentTransaction->id,
                    'refund_description' => Arr::get($refund, 'description', ''),
                    'refund_source'      => 'webhook'
                ]
            ];

            $syncedRefund = MollieHelper::createOrUpdateIpnRefund($refundData, $parentTransaction);
            if ($syncedRefund->wasRecentlyCreated) {
                $currentCreatedRefund = $syncedRefund;
            }
        }

        (new OrderRefund($order, $currentCreatedRefund))->dispatch();
    }

    /**
     * Handle subscription creation webhook
     */
    public function handleSubscriptionCreated($data)
    {
        $subscription = Arr::get($data, 'subscription');
        $mollieSubscriptionId = Arr::get($subscription, 'id');
        
        if (!$mollieSubscriptionId) {
            return false;
        }

        // Find FluentCart subscription by Mollie subscription ID
        $fcSubscription = \FluentCart\App\Models\Subscription::query()
            ->where('vendor_subscription_id', $mollieSubscriptionId)
            ->first();

        if (!$fcSubscription) {
            return false;
        }

        $fcSubscription->update([
            'status' => Status::SUBSCRIPTION_ACTIVE,
            'vendor_response' => json_encode($subscription, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        fluent_cart_add_log(
            'Mollie Subscription Created',
            'Subscription created successfully via webhook',
            'info',
            [
                'log_type' => 'webhook',
                'subscription_id' => $fcSubscription->id,
                'mollie_subscription_id' => $mollieSubscriptionId
            ]
        );

        return true;
    }

    protected function sendResponse($statusCode = 200, $message = 'Success')
    {
        http_response_code($statusCode);
        echo json_encode([
            'message' => $message,
        ]);

        exit;
    }
}

