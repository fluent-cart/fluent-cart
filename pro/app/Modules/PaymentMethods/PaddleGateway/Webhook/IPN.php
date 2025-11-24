<?php

namespace FluentCartPro\App\Modules\PaymentMethods\PaddleGateway\Webhook;

use FluentCart\App\App;
use FluentCart\App\Events\Order\OrderRefund;
use FluentCartPro\App\Modules\PaymentMethods\PaddleGateway\Confirmations;
use FluentCartPro\App\Modules\PaymentMethods\PaddleGateway\PaddleHelper;
use FluentCartPro\App\Modules\PaymentMethods\PaddleGateway\PaddleSettings;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;

class IPN
{
    private PaddleSettings $settings;

    public function __construct()
    {
        $this->settings = new PaddleSettings();
    }

    public function init(): void
    {
        // Register webhook event handlers
        add_action('fluent_cart/payments/paddle/webhook_transaction_paid', [$this, 'handleTransactionPaid'], 10, 1); //done
        add_action('fluent_cart/payments/paddle/webhook_transaction_completed', [$this, 'handleTransactionCompleted'], 10, 1); //done
        add_action('fluent_cart/payments/paddle/webhook_subscription_payment_received', [$this, 'handleSubscriptionPaymentReceived'], 10, 1); // done
        add_action('fluent_cart/payments/paddle/webhook_transaction_payment_failed', [$this, 'handleTransactionPaymentFailed'], 10, 1);
        add_action('fluent_cart/payments/paddle/webhook_subscription_created', [$this, 'handleSubscriptionActivated'], 10, 1); //done
        add_action('fluent_cart/payments/paddle/webhook_subscription_activated', [$this, 'handleSubscriptionActivated'], 10, 1); // done
        add_action('fluent_cart/payments/paddle/webhook_subscription_past_due', [$this, 'handleSubscriptionUpdated'], 10, 1); // done
        add_action('fluent_cart/payments/paddle/webhook_subscription_updated', [$this, 'handleSubscriptionUpdated'], 10, 1); // done
        add_action('fluent_cart/payments/paddle/webhook_subscription_canceled', [$this, 'handleSubscriptionCanceled'], 10, 1); //done
        add_action('fluent_cart/payments/paddle/webhook_subscription_paused', [$this, 'handleSubscriptionUpdated'], 10, 1); //done
        add_action('fluent_cart/payments/paddle/webhook_subscription_resumed', [$this, 'handleSubscriptionUpdated'], 10, 1); //done

        // adjustments/ refunds
        add_action('fluent_cart/payments/paddle/webhook_adjustment_created', [$this, 'handleAdjustmentCreated'], 10, 1); //done
        add_action('fluent_cart/payments/paddle/webhook_adjustment_updated', [$this, 'handleAdjustmentUpdated'], 10, 1); //done
    }

    /**
     * Verify and process webhook
     */
    public function verifyAndProcess()
    {
        $rawPayload = file_get_contents('php://input');
        $payload = json_decode($rawPayload, true);

        if (!$payload) {
            $this->sendResponse(400, 'Invalid JSON payload');
            return;
        }

        // Verify webhook signature if not disabled
        if (!$this->settings->isWebhookVerificationDisabled()) {

            $webhookSecret = $this->settings->getWebhookSecret();
            if (empty($webhookSecret)) {
                error_log('Paddle Webhook Failed: Webhook secret not configured');
                $this->sendResponse(500, 'Webhook secret not configured');
                return;
            }

            $verifier = new WebhookVerifier($webhookSecret);

            $paddleSignature = $_SERVER['HTTP_PADDLE_SIGNATURE'];
            $rawBody = file_get_contents('php://input');

            if (!$verifier->verify($paddleSignature, $rawBody)) {
                error_log('Paddle Webhook Failed: Invalid webhook signature');
                $this->sendResponse(401, 'Invalid webhook signature');
                return;
            }
        }

        $order = PaddleHelper::getOrderFromWebhookData($payload);

        if (!$order) {
            $this->sendResponse(200, 'Order not found');
            return;
        }

        $eventType = Arr::get($payload, 'event_type');

        $acceptedEvents = [
            'transaction.paid',
            'transaction.completed',
            'transaction.payment_failed',
            'transaction.refunded',
            'adjustment.created',
            'adjustment.updated',
            'subscription.created',
            'subscription.activated',
            'subscription.updated',
            'subscription.canceled',
            'subscription.paused',
            'subscription.resumed',
            'subscription.past_due'
        ];

        if (!in_array($eventType, $acceptedEvents)) {
            $this->sendResponse(200, 'Event type not handled');
            return;
        }

        // Log webhook for debugging
        do_action('fluent_cart/paddle_webhook_received', [
            'event_type' => $eventType,
            'data' => $payload,
            'raw' => $rawPayload,
            'order' => $order
        ]);

        // Process the webhook event
        $eventTypeFormatted = str_replace('.', '_', $eventType);

        if ($eventTypeFormatted === 'transaction_completed' || $eventTypeFormatted === 'transaction_paid') {
            $paddleTransaction = Arr::get($payload, 'data');
            $vendorSubscriptionId = Arr::get($paddleTransaction, 'subscription_id');

            if ($vendorSubscriptionId) {
                $eventTypeFormatted = 'subscription_payment_received';
            }
        }
        
        if (has_action('fluent_cart/payments/paddle/webhook_' . $eventTypeFormatted)) {
            do_action('fluent_cart/payments/paddle/webhook_' . $eventTypeFormatted, [
                'event_type' => $eventType,
                'data' => $payload,
                'raw' => $rawPayload,
                'order' => $order
            ]);
            
            $this->sendResponse(200, 'Webhook processed successfully');
        } else {
            $this->sendResponse(200, 'No handler found for event type');
        }
    }

    public function handleTransactionPaid($webhookData)
    {
        $payload = Arr::get($webhookData, 'data');
        $paddleTransaction = Arr::get($payload, 'data');
        $paddleTransactionId = Arr::get($paddleTransaction, 'id');

        $customData = Arr::get($paddleTransaction, 'custom_data', []);
        $transactionHash = Arr::get($customData, 'fct_transaction_hash');

        $transactionModel = OrderTransaction::query()->where('vendor_charge_id', $paddleTransactionId)->first();

        if (!$transactionModel && $transactionHash) {
            $transactionModel = OrderTransaction::query()->where('uuid', $transactionHash)->first();
        }

        if (!$transactionModel || $transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            return false;
        }

        (new Confirmations())->confirmPaymentSuccessByCharge($transactionModel, [
            'vendor_charge_id' => $paddleTransactionId,
            'charge' => $paddleTransaction
        ]);

        return true;
    }

    public function handleTransactionCompleted($webhookData)
    {
        $this->handleTransactionPaid($webhookData);

        // completed means: ful process has been completed on paddle side invoicing, tax receipts, entitlement provisioning
        // if any extra processing needed after completed on top of paid, do it here
    }

    public function handleAdjustmentCreated($webhookData)
    {
        $payload = Arr::get($webhookData, 'data');
        $paddleAdjustment = Arr::get($payload, 'data');
        $paddleAdjustmentId = Arr::get($paddleAdjustment, 'id');
        $action = Arr::get($paddleAdjustment, 'action');

        // We are only handling refunds adjustment for now
        if ($action !== 'refund') {
            return false;
        }

        $parentTransactionId = Arr::get($paddleAdjustment, 'transaction_id');

        $order = Arr::get($webhookData, 'order');

        $parentTransaction = OrderTransaction::query()->where('vendor_charge_id', $parentTransactionId)->first();

        if (!$parentTransaction) {
            return false;
        }

        $parentTaxMode = Arr::get($parentTransaction, 'meta.tax_mode', '');

        if (!$parentTaxMode) {
            $parentTaxMode = (new PaddleSettings())->get('tax_mode');
        }

        $paddleRefundAmount = 0;
        foreach (Arr::get($paddleAdjustment, 'items', []) as $item) {
            if ($parentTaxMode === 'external') {
                $paddleRefundAmount += Arr::get($item, 'totals.subtotal', 0);
            } else {
                $paddleRefundAmount += Arr::get($item, 'totals.total', 0);
            }
        }

        $status = self::transformAdjustmentStatus(Arr::get($paddleAdjustment, 'status'));

        return \FluentCart\App\Services\Payments\Refund::createOrRecordRefund([
            'vendor_charge_id' => $paddleAdjustmentId,
            'payment_method'   => 'paddle',
            'status'           => $status,
            'total'            => $paddleRefundAmount,
        ], $parentTransaction);

   }

   public static function transformAdjustmentStatus($paddleStatus)
   {
       $statusMap = [
            'approved' => Status::TRANSACTION_REFUNDED,
            'pending_approval' => Status::TRANSACTION_PENDING,
            'rejected' => Status::TRANSACTION_FAILED,
        ];

        return Arr::get($statusMap, strtolower($paddleStatus), Status::TRANSACTION_PENDING);
   }

   public function handleAdjustmentUpdated($webhookData)
    {
        $payload = Arr::get($webhookData, 'data');
        $paddleAdjustment = Arr::get($payload, 'data');
        $paddleAdjustmentId = Arr::get($paddleAdjustment, 'id');
        $action = Arr::get($paddleAdjustment, 'action');

        // We are only handling refunds adjustment for now
        if ($action !== 'refund') {
            return false;
        }

        $parentTransactionId = Arr::get($paddleAdjustment, 'transaction_id');

        $order = Arr::get($webhookData, 'order');

        $parentTransaction = OrderTransaction::query()->where('vendor_charge_id', $parentTransactionId)->first();

        if (!$parentTransaction) {
            return false;
        }

        $adjustmentTransaction = OrderTransaction::query()->where('vendor_charge_id', $paddleAdjustmentId)->first();

        if (!$adjustmentTransaction || $adjustmentTransaction->status === Arr::get($paddleAdjustment, 'status')) {
            return false;
        }

        $adjustmentTransaction->update([
            'status' => self::transformAdjustmentStatus(Arr::get($paddleAdjustment, 'status'))
        ]);

        if ($adjustmentTransaction->status === Status::TRANSACTION_REFUNDED) {
            (new OrderRefund($order, $adjustmentTransaction))->dispatch();
        }

    }

    public function handleSubscriptionPaymentReceived($webhookData)
    {
        $order = Arr::get($webhookData, 'order');
        $payload = Arr::get($webhookData, 'data');

        $paddleSubscriptionId = Arr::get($payload, 'data.subscription_id');

        $subscriptionModel = Subscription::query()->where('vendor_subscription_id', $paddleSubscriptionId)->first();

        if (!$subscriptionModel) {
            $subscriptionModel = Subscription::query()->where('parent_order_id', $order->id)->first();
        }

        if (!$subscriptionModel) {
            return false;
        }

        $subscriptionModel->reSyncFromRemote();

    }

    /**
     * Handle transaction payment failed webhook
     */
    public function handleTransactionPaymentFailed($webhookData)
    {
        $payload = Arr::get($webhookData, 'data');
        $paddleTransaction = Arr::get($payload, 'data');

        $customData = Arr::get($payload, 'data.custom_data', []);
        $transactionHash = Arr::get($customData, 'fct_transaction_hash');

        $paddleTransactionId = Arr::get($paddleTransaction, 'id');

        $transactionModel = OrderTransaction::query()->where('vendor_charge_id', $paddleTransactionId)->first();

        if (!$transactionModel && $transactionHash) {
            $transactionModel = OrderTransaction::query()->where('uuid', $transactionHash)->first();
        }

        if (!$transactionModel) {
            return false;
        }

        $transactionModel->fill([
            'vendor_charge_id' => $paddleTransactionId,
            'status' => Status::TRANSACTION_FAILED
        ]);

        $transactionModel->save();

        return true;
    }

    public function handleSubscriptionActivated($webhookData)
    {
        $data = Arr::get($webhookData, 'data');
        $paddleSubscription = Arr::get($data, 'data');
        $order = Arr::get($webhookData, 'order');

        $customData = Arr::get($paddleSubscription, 'custom_data', []);
        $subscriptionHash = Arr::get($customData, 'fct_subscription_hash');

        $vendorSubscriptionId = Arr::get($paddleSubscription, 'id');
        $subscription = Subscription::query()->where('vendor_subscription_id', $vendorSubscriptionId)->first();

        if (!$subscription) {
            $subscription = Subscription::query()->where('uuid', $subscriptionHash)->first();
        }

        if (!$subscription) {
            return false;
        }

        $billCount = OrderTransaction::query()->where('subscription_id', $subscription->id)->count();
        $updateData = [
            'vendor_subscription_id' => Arr::get($paddleSubscription, 'id'),
            'current_payment_method' => 'paddle',
            'status' => PaddleHelper::transformSubscriptionStatus(Arr::get($paddleSubscription, 'status')),
            'bill_count' => $billCount,
            'next_billing_date' => gmdate('Y-m-d H:i:s', strtotime(Arr::get($paddleSubscription, 'next_billed_at'))),
            'vendor_response' => json_encode($paddleSubscription)
        ];

        $oldStatus = $subscription->status;
        $oldVendorSubscriptionId = $subscription->vendor_subscription_id;
        $oldPaymentMethod = $subscription->current_payment_method;

        $subscription->update($updateData);

        if (in_array($subscription->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING]) && !in_array($oldStatus, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING]) && $subscription->bill_count === 0) {
            (new SubscriptionActivated($subscription, $order, $order->customer))->dispatch();
        }

        if ($oldVendorSubscriptionId && $oldVendorSubscriptionId !== $vendorSubscriptionId) {
            // we have a new vendor subscription ID or payment method, so let's cancel the old subscription
            $gateway = App::gateway($oldPaymentMethod);
            if ($gateway && $gateway->has('subscriptions')) {
                $result = $gateway->subscriptions->cancel($oldVendorSubscriptionId, [
                    'mode' => $order->mode
                ]);

                if (is_wp_error($result)) {
                    $order->addLog(
                        'OLD Subscription cancellation failed for renewal - ' . $oldPaymentMethod,
                        'Failed to cancel the old subscription with ID: ' . $oldVendorSubscriptionId,
                        'error'
                    );
                } else {
                    $order->addLog(
                        'Old Subscription cancelled on subscription reactivation - ' . $oldPaymentMethod,
                        'Old subscription with ID: ' . $oldVendorSubscriptionId . ' has been cancelled.',
                        'info'
                    );
                }
            }
        }
    }

    /**
     * Handle subscription updated webhook
     */
    public function handleSubscriptionUpdated($webhookData)
    {
        $payload = Arr::get($webhookData, 'data');
        $paddleSubscription = Arr::get($payload, 'data');

        $subscriptionId = Arr::get($paddleSubscription, 'id');
        $subscription = Subscription::query()->where('vendor_subscription_id', $subscriptionId)->first();

        if (!$subscription) {
            return false;
        }

        // Re-sync subscription from remote
        $subscription->reSyncFromRemote();

        return true;
    }

    public function handleSubscriptionCanceled($webhookData)
    {
        $payload = Arr::get($webhookData, 'data');
        $paddleSubscription = Arr::get($payload, 'data');

        $subscriptionId = Arr::get($paddleSubscription, 'id');
        $subscription = Subscription::query()->where('vendor_subscription_id', $subscriptionId)->first();

        if (!$subscription || ($subscription->status === Status::SUBSCRIPTION_CANCELED || $subscription->status === Status::SUBSCRIPTION_COMPLETED || $subscription->status === Status::SUBSCRIPTION_EXPIRED)) {
            return false;
        }

        $subscription->reSyncFromRemote();

        return true;

    }

    /**
     * Send HTTP response
     */
    private function sendResponse($code, $message)
    {
        http_response_code($code);
        echo $message;
        exit;
    }
}
