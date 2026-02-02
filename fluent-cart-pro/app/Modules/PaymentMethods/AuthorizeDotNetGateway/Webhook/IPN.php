<?php

namespace FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway\Webhook;

use FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway\API\AuthorizeDotNetAPI;
use FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway\AuthorizeDotNetHelper;
use FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway\AuthorizeDotNetSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway\AuthorizeDotNetSubscriptions;
use FluentCart\App\Events\Order\OrderRefund;

class IPN
{
    protected AuthorizeDotNetSettings $settings;
    protected AuthorizeDotNetAPI $api;

    public function __construct(?AuthorizeDotNetSettings $settings = null)
    {
        $this->settings = $settings ?: new AuthorizeDotNetSettings();
        $this->api = new AuthorizeDotNetAPI();
    }

    public function init(): void
    {
        $this->verifyAndProcess();

    }

    public function verifyAndProcess(): void
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!$this->settings->isActive()) {
            return;
        }

        $payload = file_get_contents('php://input');
        if (!$payload) {
            return;
        }

        $reqSignatureKey = $this->getAnetSignatureHeader();
        if (!$reqSignatureKey) {
            return;
        }

        // Remove the 'sha512=' prefix if present
        if (strpos($reqSignatureKey, 'sha512=') === 0) {
            $reqSignatureKey = substr($reqSignatureKey, strlen('sha512='));
        }

        if (!$payload || !$reqSignatureKey || !$this->api->validateWebhookSignature($payload, $reqSignatureKey)) {
            $this->sendResponse(400, __('Invalid webhook signature.', 'fluent-cart-pro'));
        }

        $data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $this->sendResponse(400, __('Invalid webhook payload.', 'fluent-cart-pro'));
        }

        $eventType = Arr::get($data, 'eventType');
        $entity = strtolower((string) Arr::get($data, 'payload.entityName'));

        // Handle subscription events
        if ($entity === 'subscription') {
            $this->handleSubscriptionEvent($data);
            $this->sendResponse(200, __('Webhook processed.', 'fluent-cart-pro'));
            return;
        }

        // Handle transaction events
        if ($entity !== 'transaction') {
            $this->sendResponse(200, __('Webhook ignored. Entity not supported.', 'fluent-cart-pro'));
        }

        switch ($eventType) {
            case 'net.authorize.payment.authcapture.created':
            case 'net.authorize.payment.priorAuthCapture.created':
            case 'net.authorize.payment.capture.created':
                $this->handlePaymentCaptured($data);
                break;
            case 'net.authorize.payment.fraud.approved':
                $this->handleFraudApproved($data);
                break;
            case 'net.authorize.payment.fraud.declined':
            case 'net.authorize.payment.void.created':
                $this->handlePaymentFailed($data, __('Authorize.Net marked the payment as declined/void.', 'fluent-cart-pro'));
                break;
            case 'net.authorize.payment.refund.created':
                $this->handleRefundCreated($data);
                break;
            default:
                // Unhandled event but acknowledged.
                break;
        }

        $this->sendResponse(200, __('Webhook processed.', 'fluent-cart-pro'));
    }

    protected function handlePaymentCaptured(array $payload): void
    {
        $transactionId = Arr::get($payload, 'payload.id');
        if (!$transactionId) {
            return;
        }

        $transactionDetails = $this->getTransactionDetails($transactionId);
        if ($transactionDetails && Arr::get($transactionDetails, 'transaction.subscription')) {
            $this->handleSubscriptionPayment($payload, Arr::get($transactionDetails, 'transaction.subscription'));
            return;
        }

        // Regular one-time payment
        $transaction = OrderTransaction::query()->where('vendor_charge_id', $transactionId)->first();
        if (!$transaction) {
            return;
        }

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        // check if the transaction was held for review on subscrption process, then we need to create and activate the subscription
        $subscriptionId = $transaction->subscription_id;
        if ($subscriptionId) {
            $subscriptionModel = Subscription::query()->where('id', $subscriptionId)->first();
            $order = $transaction->order;
            
            if ($subscriptionModel && in_array($subscriptionModel->status, [Status::SUBSCRIPTION_PENDING, Status::SUBSCRIPTION_INTENDED]) || ($order->type == 'renewal' && in_array($subscriptionModel->status, [Status::SUBSCRIPTION_CANCELED, Status::SUBSCRIPTION_EXPIRED])) ) {
                // create and activate the subscription in authorize dot net
                (new AuthorizeDotNetSubscriptions($this->settings))->createAndActivateSubscriptionOnTransactionApprove($subscriptionModel, $transaction);
            }
        }

        fluent_cart_add_log(
            __('Authorize.Net Payment Captured', 'fluent-cart-pro'),
            sprintf(__('Payment captured via webhook. Transaction ID: %s', 'fluent-cart-pro'), $transactionId),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $transaction->order_id,
            ]
        );

        AuthorizeDotNetHelper::markTransactionSucceeded($transaction, Arr::get($payload, 'payload', []));

    }

    protected function handleFraudApproved(array $payload): void
    {
        $transactionId = Arr::get($payload, 'payload.id');
        if (!$transactionId) {
            return;
        }

        $transaction = OrderTransaction::query()->where('vendor_charge_id', $transactionId)->first();
        if (!$transaction) {
            return;
        }

        fluent_cart_add_log(
            __('Authorize.Net Fraud Approved', 'fluent-cart-pro'),
            sprintf(__('Fraud approved via webhook. Transaction ID: %s', 'fluent-cart-pro'), $transactionId),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $transaction->order_id,
            ]
        );

        AuthorizeDotNetHelper::markTransactionSucceeded($transaction, Arr::get($payload, 'payload', []));
    }

    protected function handlePaymentFailed(array $payload, string $reason): void
    {
        $transactionId = Arr::get($payload, 'payload.id');
        if (!$transactionId) {
            return;
        }

        $transaction = OrderTransaction::query()->where('vendor_charge_id', $transactionId)->first();
        if (!$transaction) {
            return;
        }

        fluent_cart_add_log(
            __('Authorize.Net Payment Failed', 'fluent-cart-pro'),
            sprintf(__('Payment failed via webhook. Transaction ID: %s', 'fluent-cart-pro'), $transactionId),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $transaction->order_id,
            ]
        );

        AuthorizeDotNetHelper::markTransactionFailed($transaction, $reason, Arr::get($payload, 'payload', []));
    }

    protected function handleRefundCreated(array $payload): void
    {
        $transactionId = Arr::get($payload, 'payload.id');
        $refTransId = Arr::get($payload, 'payload.refTransId');
        if (!$transactionId || !$refTransId) {
            return;
        }

        // Get parent transaction
        $parentTransaction = OrderTransaction::query()
            ->where('vendor_charge_id', $refTransId)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->where('status', Status::TRANSACTION_SUCCEEDED)
            ->first();

        if (!$parentTransaction) {
            return;
        }

        $order = $parentTransaction->order;
        if (!$order) {
            return;
        }

        $amount = (float) Arr::get($payload, 'payload.authAmount', 0);
        $amountInMinor = (int) round($amount * 100);

        // Prepare refund data matching FluentCart pattern
        $refundData = [
            'order_id'           => $order->id,
            'transaction_type'   => Status::TRANSACTION_TYPE_REFUND,
            'status'             => Status::TRANSACTION_REFUNDED,
            'payment_method'     => 'authorize_dot_net',
            'payment_mode'       => $parentTransaction->payment_mode,
            'vendor_charge_id'   => $transactionId,
            'total'              => $amountInMinor,
            'currency'           => $parentTransaction->currency,
            'meta'               => [
                'parent_id'      => $parentTransaction->id,
                'authnet'        => Arr::get($payload, 'payload', []),
                'refund_source'  => 'webhook'
            ]
        ];

        $syncedRefund = AuthorizeDotNetHelper::createOrUpdateIpnRefund($refundData, $parentTransaction);

        // Dispatch OrderRefund event to properly update order status and trigger notifications
        if ($syncedRefund && $syncedRefund->wasRecentlyCreated) {
            (new OrderRefund($order, $syncedRefund))->dispatch();
        }
    }


    protected function handleSubscriptionEvent(array $payload): void
    {
        $eventType = Arr::get($payload, 'eventType');
        $subscriptionId = Arr::get($payload, 'payload.id');

        if (!$subscriptionId) {
            return;
        }

        $subscription = Subscription::query()
            ->where('vendor_subscription_id', $subscriptionId)
            ->where('current_payment_method', 'authorize_dot_net')
            ->first();

        if (!$subscription) {
            return;
        }

        switch ($eventType) {
            case 'net.authorize.customer.subscription.cancelled':
                $this->handleSubscriptionCancelled($subscription, $payload);
                break;
            case 'net.authorize.customer.subscription.expired':
                $this->handleSubscriptionExpired($subscription, $payload);
                break;
            case 'net.authorize.customer.subscription.expiring':
                $this->handleSubscriptionExpiring($subscription, $payload);
                break;
            case 'net.authorize.customer.subscription.payment.terminated':
                $this->handleSubscriptionPaymentTerminated($subscription, $payload);
                break;
            case 'net.authorize.customer.subscription.payment.failed':
                $this->handleSubscriptionPaymentFailed($subscription, $payload);
                break;
            case 'net.authorize.customer.subscription.payment.updated':
                $this->handleSubscriptionPaymentUpdated($subscription, $payload);
                break;
            default:
                break;
        }

    }


    protected function handleSubscriptionPayment(array $payload, $authSubscriptionData): void
    {
        $vendorSubscriptionId = Arr::get($authSubscriptionData, 'id');

        if (!$vendorSubscriptionId) {
            return;
        }

        $subscription = Subscription::query()
            ->where('vendor_subscription_id', $vendorSubscriptionId)
            ->where('current_payment_method', 'authorize_dot_net')
            ->first();

        if (!$subscription) {
            return;
        }

        $orderId = $subscription->parent_order_id;


        fluent_cart_add_log(
            __('Authorize.Net Subscription Payment', 'fluent-cart-pro'),
            sprintf(__('Subscription payment via webhook. Subscription ID: %s', 'fluent-cart-pro'), Arr::get($payload, 'payload.id')),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $orderId,
            ]
        );

        (new AuthorizeDotNetSubscriptions($this->settings))->reSyncSubscriptionFromRemote($subscription);

    }

    protected function handleSubscriptionPaymentUpdated(Subscription $subscription, array $payload)
    {
        // will handle this later
    }

    protected function getTransactionDetails(string $transactionId): ?array
    {
        $data = [
            'merchantAuthentication' => $this->api->getMerchantAuthentication(),
            'transId'                => $transactionId,
        ];

        $response = $this->api->getAuthorizeNetObject('getTransactionDetailsRequest', $data);

        if (is_wp_error($response) || !AuthorizeDotNetAPI::isSuccessResponse($response)) {
            return null;
        }

        return Arr::get($response, 'transaction', []);
    }


    protected function handleSubscriptionCancelled(Subscription $subscription, array $payload): void
    {
        fluent_cart_add_log(
            __('Authorize.Net Subscription Cancelled', 'fluent-cart-pro'),
            sprintf(__('Subscription cancelled via webhook. Subscription ID: %s', 'fluent-cart-pro'), Arr::get($payload, 'payload.id')),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $subscription->parent_order_id,
            ]
        );
        (new AuthorizeDotNetSubscriptions($this->settings))->reSyncSubscriptionFromRemote($subscription);
    }


    protected function handleSubscriptionExpired(Subscription $subscription, array $payload): void
    {
        $orderId = $subscription->parent_order_id;
        if (!$orderId) {
            return;
        }

        fluent_cart_add_log(
            __('Authorize.Net Subscription Expired', 'fluent-cart-pro'),
            sprintf(__('Subscription expired via webhook. Subscription ID: %s', 'fluent-cart-pro'), Arr::get($payload, 'payload.id')),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $orderId,
            ]
        );

        (new AuthorizeDotNetSubscriptions($this->settings))->reSyncSubscriptionFromRemote($subscription);
    }

    protected function handleSubscriptionExpiring(Subscription $subscription, array $payload): void
    {
        $orderId = $subscription->parent_order_id;
        if (!$orderId) {
            return;
        }

        fluent_cart_add_log(
            __('Authorize.Net Subscription Expiring', 'fluent-cart-pro'),
            sprintf(__('Subscription expiring via webhook. Subscription ID: %s', 'fluent-cart-pro'), Arr::get($payload, 'payload.id')),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $orderId,
            ]
        );

       (new AuthorizeDotNetSubscriptions($this->settings))->reSyncSubscriptionFromRemote($subscription);
    }

    protected function handleSubscriptionPaymentTerminated(Subscription $subscription, array $payload): void
    {
        $orderId = $subscription->parent_order_id;
        if (!$orderId) {
            return;
        }

        fluent_cart_add_log(
            __('Authorize.Net Subscription Payment Terminated', 'fluent-cart-pro'),
            sprintf(__('Subscription payment terminated via webhook. Subscription ID: %s', 'fluent-cart-pro'), Arr::get($payload, 'payload.id')),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $orderId,
            ]
        );

        (new AuthorizeDotNetSubscriptions($this->settings))->reSyncSubscriptionFromRemote($subscription);
    }

    protected function handleSubscriptionPaymentFailed(Subscription $subscription, array $payload): void
    {
        $orderId = $subscription->parent_order_id;
        if (!$orderId) {
            return;
        }

        fluent_cart_add_log(
            __('Authorize.Net Subscription Payment Failed', 'fluent-cart-pro'),
            sprintf(__('Subscription payment failed via webhook. Subscription ID: %s', 'fluent-cart-pro'), Arr::get($payload, 'payload.id')),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $orderId,
            ]
        );

        (new AuthorizeDotNetSubscriptions($this->settings))->reSyncSubscriptionFromRemote($subscription);
    }

    protected function getAnetSignatureHeader(): ?string
    {
        if (function_exists('getallheaders')) {
            $headers = array_change_key_case(getallheaders(), CASE_LOWER);
            return $headers['x-anet-signature'] ?? null;
        }

        if (isset($_SERVER['HTTP_X_ANET_SIGNATURE'])) {
            return $_SERVER['HTTP_X_ANET_SIGNATURE'];
        }

        return null;
    }

    protected function sendResponse(int $code, string $message): void
    {
        status_header($code);
        echo wp_json_encode(['message' => $message]);
        exit;
    }
}
