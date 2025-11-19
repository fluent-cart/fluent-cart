<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

class IPN
{
    private const TEST_VERIFYING_URL = 'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature';
    private const LIVE_VERIFYING_URL = 'https://api-m.paypal.com/v1/notifications/verify-webhook-signature';
    private static $paypalSettings = null;

    public function init()
    {
        // New
        add_action('fluent_cart/payments/paypal/webhook_payment_capture_completed', [$this, 'processChargeCaptured'], 10, 1);

        // reviewed.
        add_action('fluent_cart/payments/paypal/webhook_billing_subscription_activated', [$this, 'processSubscriptionActivated'], 10, 1);
        add_action('fluent_cart/payments/paypal/webhook_subscription_payment_received', [$this, 'processRecurringPaymentReceived'], 10, 1);
        add_action('fluent_cart/payments/paypal/webhook_payment_capture_refunded', [$this, 'handleSinglePaymentRefund']);
        add_action('fluent_cart/payments/paypal/webhook_payment_sale_refunded', [$this, 'handleWebhookRecurringPaymentRefunded'], 10, 1);
        add_action('fluent_cart/payments/paypal/webhook_billing_subscription_cancelled', [$this, 'handleWebhookRecurringProfileCancelled'], 10, 1);
        add_action('fluent_cart/payments/paypal/webhook_billing_subscription_expired', [$this, 'handleWebhookRecurringProfileExpired'], 10, 1);
        add_action('fluent_cart/payments/paypal/webhook_billing_subscription_suspended', [$this, 'handleWebhookRecurringProfileSuspended'], 10, 1);
        add_action('fluent_cart/payments/paypal/webhook_billing_subscription_re-activated', [$this, 'handleWebhookRecurringProfileReactivated'], 10, 1);

        // dispute
        add_action('fluent_cart/payments/paypal/webhook_customer_dispute_created', [$this, 'handleWebhookDisputeCreated'], 10, 1);
        add_action('fluent_cart/payments/paypal/webhook_customer_dispute_updated', [$this, 'handleWebhookDisputeUpdated'], 10, 1);
        add_action('fluent_cart/payments/paypal/webhook_customer_dispute_resolved', [$this, 'handleWebhookDisputeResolved'], 10, 1);

    }

    public function processPaypalWebhookEvents($event): void
    {
        $eventType = Arr::get($event, 'event_type', '');
        $resource = Arr::get($event, 'resource', []);

        if (empty($resource)) {
            return;
        }

        // convert event to snake case ex: PAYMENT.SALE.COMPLETED to payment_sale_completed
        $eventType = strtolower(str_replace('.', '_', $eventType));

        if ($eventType === 'payment_sale_completed') {
            $billingAgreementId = Arr::get($resource, 'billing_agreement_id', '');
            if ($billingAgreementId) {
                do_action('fluent_cart/payments/paypal/webhook_subscription_payment_received', [
                    'charge'                 => $resource,
                    'vendor_subscription_id' => $billingAgreementId,
                ]);
            } else {
                // do not need webhook for one time payment
                do_action('fluent_cart/payments/paypal/webhook_payment_capture_completed', [
                    'charge' => $resource
                ]);
            }
        } else if ($eventType === 'payment_sale_refunded') {
            // recurring payment refund
            do_action('fluent_cart/payments/paypal/webhook_payment_sale_refunded', [
                'refund' => $resource
            ]);
        } else if ($eventType === 'payment_capture_refunded') { // this is manly the refund for one time items
            do_action('fluent_cart/payments/paypal/webhook_payment_capture_refunded', [
                'refund' => $resource
            ]);
        } else if ($eventType === 'payment_capture_completed') {
            do_action('fluent_cart/payments/paypal/webhook_payment_capture_completed', [
                'charge' => $resource,
            ]);
        } else if ( $eventType === 'customer_dispute_created' ||$eventType == 'customer_dispute_updated' || $eventType === 'customer_dispute_resolved') {
            do_action('fluent_cart/payments/paypal/webhook_' . $eventType, [
                'dispute' => $resource
            ]);
        }
        else {
            /**
             *
             * fluent_cart/payments/paypal/webhook_billing_subscription_activated
             * fluent_cart/payments/paypal/webhook_billing_subscription_created
             * fluent_cart/payments/paypal/webhook_billing_subscription_cancelled
             * fluent_cart/payments/paypal/webhook_billing_subscription_expired
             * fluent_cart/payments/paypal/webhook_billing_subscription_suspended
             * fluent_cart/payments/paypal/webhook_billing_subscription_re-activated
             */
            do_action('fluent_cart/payments/paypal/webhook_' . $eventType, [
                'paypal_subscription' => $resource
            ]);
        }

    }


    public function processChargeCaptured($data)
    {
        $charge = Arr::get($data, 'charge', []);

        $vendorChargeId = Arr::get($charge, 'id', '');

        $transaction = OrderTransaction::query()->where('vendor_charge_id', $vendorChargeId)->first();

        if (!$transaction) {
            // We did not find the charge. So let's find the parent order ID and transactio reference
            $parentIntentId = Arr::get($charge, 'supplementary_data.related_ids.order_id', '');
            if ($parentIntentId) {
                $paypalIntent = API::verifyPayment($parentIntentId);
                if (is_wp_error($paypalIntent)) {
                    return;
                }

                $transactionHash = Arr::get($paypalIntent, 'purchase_units.0.reference_id', '');
                if ($transactionHash) {
                    $transaction = OrderTransaction::query()
                        ->where('uuid', $transactionHash)
                        ->first();
                }
            }
        }

        if (!$transaction) {
            // not our transaction!
            return;
        }

        if ($transaction->status == Status::TRANSACTION_SUCCEEDED) {
            if (!$transaction->vendor_charge_id) {
                // We are just updating the vendor charge ID
                $transaction->vendor_charge_id = $vendorChargeId;
                $transaction->save();
            }

            // already processed
            return;
        }

        // get full payment intent
        $paypalOrderId = Arr::get($charge, 'supplementary_data.related_ids.order_id', '');
        $paypalIntent = API::verifyPayment($paypalOrderId);

        // All Verified! Let's update the transaction and order
        (new Processor())->confirmPaymentSuccessByCharge($transaction, [
            'vendor_charge_id'    => $vendorChargeId,
//            'payment_method_type' => Arr::get($charge, 'payee.email_address', 'PayPal'),
            'payment_method_type' => 'PayPal',
            'status'              => Status::TRANSACTION_SUCCEEDED,
            'payment_source'      => Arr::get($paypalIntent, 'payment_source', []),
            'meta'               => [
                'payer' => Arr::get($paypalIntent, 'payer', [])
            ]
        ]);

    }


    // called only when webhook/ipn hits
    public function verifyAndProcess($data = []): void
    {
        $this->processWebhook();
    }

    /**
     * Verify the webhook signature
     *
     * @param string $webhookId
     * @return bool|\WP_Error
     */
    public function verifyWebhook($webhookId)
    {
        $disableWebhookVerification = apply_filters('fluent_cart/payments/paypal/disable_webhook_verification', 'no', []);
        if ($disableWebhookVerification === 'yes') {
            return true;
        }

        if (empty($webhookId)) {
            return new \WP_Error('webhook_id_missing', __('Webhook ID is missing.', 'fluent-cart'));
        }

        $webhookId = trim($webhookId);
        $header = getallheaders();

        // make all headers lowercase
        $header = array_change_key_case($header, CASE_LOWER);
        if (!isset($header['paypal-auth-algo']) || !isset($header['paypal-cert-url']) ||
            !isset($header['paypal-transmission-id']) || !isset($header['paypal-transmission-sig']) ||
            !isset($header['paypal-transmission-time'])) {

            return new \WP_Error('webhook_header_missing', __('Required PayPal webhook headers are missing.', 'fluent-cart'), [
                'headers' => $header
            ]);
        }

        $webhookEvent = json_decode(file_get_contents('php://input'));
        $body = [
            'auth_algo'         => $header['paypal-auth-algo'],
            'transmission_id'   => $header['paypal-transmission-id'],
            'transmission_time' => $header['paypal-transmission-time'],
            'cert_url'          => $header['paypal-cert-url'],
            'transmission_sig'  => $header['paypal-transmission-sig'],
            'webhook_id'        => $webhookId,
            'webhook_event'     => $webhookEvent
        ];

        $response = API::verifyWebhookSignature($body);

        if (is_wp_error($response)) {
            do_action('fluent_cart/dev_log', [
                'raw_data'    => $body,
                'status'      => 'failed',
                'title'       => __('Failed to verify PayPal webhook signature', 'fluent-cart'),
                'log_type'    => 'webhook',
                'module_type' => 'FluentCart\App\Modules\PaymentMethods\PayPal',
                'module_name' => 'PayPal'
            ]);

            return $response;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if ($http_code !== 200 || empty($response_data['verification_status']) || $response_data['verification_status'] !== 'SUCCESS') {
            return new \WP_Error('webhook_verification_failed', __('Webhook verification failed.', 'fluent-cart'), [
                'http_code' => $http_code,
                'response'  => $response_data
            ]);
        }

        return true;
    }

    public function processWebhook()
    {
        $post_data = file_get_contents('php://input');

        $data = json_decode($post_data, true);

        if (!$data) {
            return; // could not decode JSON
        }

        $webhookType = Arr::get($data, 'event_type', '');

        $webhookEvents = [
            'PAYMENT.SALE.COMPLETED',
            'PAYMENT.SALE.REFUNDED',
            'PAYMENT.CAPTURE.REFUNDED',
            'BILLING.SUBSCRIPTION.CREATED',
            'BILLING.SUBSCRIPTION.ACTIVATED',
            'BILLING.SUBSCRIPTION.CANCELLED',
            'BILLING.SUBSCRIPTION.EXPIRED',
            'BILLING.SUBSCRIPTION.SUSPENDED',
            'BILLING.SUBSCRIPTION.RE-ACTIVATED',
            'PAYMENT.CAPTURE.COMPLETED',
            'CUSTOMER.DISPUTE.CREATED',
            'CUSTOMER.DISPUTE.UPDATED',
            'CUSTOMER.DISPUTE.RESOLVED',
            'CHECKOUT.ORDER.APPROVED' // we don't need this
        ];

        if (!in_array($webhookType, $webhookEvents)) {
            return;
        }

        do_action('fluent_cart/paypal_webhook_received', [
            'data' => $data,
            'raw'  => $post_data
        ]);

        if (defined('FLUENT_CART_DEV_MODE')) {
            do_action('fluent_cart/dev_log', [
                'raw_data'    => $post_data,
                'status'      => 'received',
                'title'       => __('PayPal Webhook Received', 'fluent-cart'),
                'log_type'    => 'webhook',
                'module_type' => 'FluentCart\App\Modules\PaymentMethods\PayPal',
                'module_name' => 'PayPal'
            ]);
        }

        $paymentSettings = self::getPayPalSettings()->get();

        $mode = (new StoreSettings)->get('order_mode');

        // FCT_PAYPAL_LIVE_WEBHOOK_ID
        if ($mode === 'test') {
            $webhookId = defined('FCT_PAYPAL_TEST_WEBHOOK_ID') ? FCT_PAYPAL_TEST_WEBHOOK_ID : Arr::get($paymentSettings, $mode . '_webhook_id', '');
        } else {
            $webhookId = defined('FCT_PAYPAL_LIVE_WEBHOOK_ID') ? FCT_PAYPAL_LIVE_WEBHOOK_ID : Arr::get($paymentSettings, $mode . '_webhook_id', '');
        }

        $willVerify = apply_filters('fluent_cart/payments/paypal/verify_webhook', true, [
            'data' => $data,
            'mode' => $mode,
            'type' => $webhookType
        ]);

        if ($willVerify) {

            $verified = $this->verifyWebhook($webhookId);

            if (is_wp_error($verified)) {
                $data = json_encode($verified->get_error_data());
                fluent_cart_add_log($verified->get_error_message() . ' Webhook: ' . $webhookType, $data, 'error', [
                    'log_type'    => 'webhook',
                    'module_type' => 'FluentCart\App\Modules\PaymentMethods\PayPal',
                    'module_name' => 'PayPal',
                ]);

                exit(400);
            }
        }

        $this->processPaypalWebhookEvents($data);
        exit(200);
    }

    public function processSubscriptionActivated($data)
    {
        $paypalSubscription = Arr::get($data, 'paypal_subscription', []);
        $vendorSubscriptionId = sanitize_text_field(Arr::get($paypalSubscription, 'id'));
        if (empty($vendorSubscriptionId)) {
            return;
        }

        $subscriptionModel = Subscription::query()->where('vendor_subscription_id', $vendorSubscriptionId)->first();
        if (!$subscriptionModel) {
            $subscriptionHash = Arr::get($paypalSubscription, 'custom_id', '');

            if ($subscriptionHash) {
                $subscriptionModel = Subscription::query()->where('uuid', $subscriptionHash)->first();
            }
        }

        if (!$subscriptionModel || $subscriptionModel->status === Status::SUBSCRIPTION_ACTIVE) {
            return;
        }

        $transaction = $subscriptionModel->getLatestTransaction();
        if (!$transaction) {
            return;
        }

        (new Processor())->activateSubscription($paypalSubscription, $transaction, $subscriptionModel);
    }

    public function processRecurringPaymentReceived($data)
    {
        $charge = Arr::get($data, 'charge', []);
        $vendorSubscriptionId = Arr::get($data, 'vendor_subscription_id', '');

        $subscriptionModel = $vendorSubscriptionId ? Subscription::query()->where('vendor_subscription_id', $vendorSubscriptionId)->with('order')->first() : null;

        if (!$subscriptionModel) {
            $subscriptionHash = Arr::get($charge, 'custom', '');
            if ($subscriptionHash) {
                $subscriptionModel = Subscription::query()->where('uuid', $subscriptionHash)->first();
            }
        }

        if (!$subscriptionModel || $subscriptionModel->current_payment_method !== 'paypal') {
            return false;
        }

        $amount = Helper::toCent(Arr::get($charge, 'amount.total', 0));
        $chargeId = Arr::get($charge, 'id');
        if (!$amount || !$chargeId) {
            return false;
        }

        // find the OrderTransaction
        $transaction = OrderTransaction::query()->where('vendor_charge_id', $chargeId)
            ->where('subscription_id', $subscriptionModel->id)
            ->where('payment_method', 'paypal')
            ->first();

        if ($transaction) {
            return true;
        }

        // Let's see if this is our first transaction that we did not capture
        $latestTransaction = $subscriptionModel->getLatestTransaction();

        if (!$latestTransaction->vendor_charge_id && $latestTransaction->total) {
            // That means this is our first transaction of the subscription
            $latestTransaction->payment_method_type = 'PayPal';
            $latestTransaction->vendor_charge_id = $chargeId;
            $latestTransaction->total = $amount;
            $latestTransaction->save();
            return true;
        }


        // Now we are sure, we have a renewal payment for this subscription!

        // we will just create the transaction here

        $subscriptionUpdateData = [
            'current_payment_method' => 'paypal',
            'vendor_subscription_id' => $vendorSubscriptionId
        ];

        // get the subscription data from paypal
        $paypalSubscription = API::getResource('billing/subscriptions/' . $vendorSubscriptionId);
        $payer = Arr::get($paypalSubscription, 'subscriber', []);
        if (!is_wp_error($paypalSubscription)) {
            $nextBillingDate = Arr::get($paypalSubscription, 'billing_info.next_billing_time');
            if ($nextBillingDate) {
                $subscriptionUpdateData['next_billing_date'] = gmdate('Y-m-d H:i:s', strtotime($nextBillingDate));
            }

            $payerId = Arr::get($paypalSubscription, 'subscriber.payer_id');

            if ($payerId) {
                $subscriptionUpdateData['vendor_customer_id'] = $payerId;
            }

            if (!empty($paypalSubscription['plan_id'])) {
                $subscriptionUpdateData['vendor_plan_id'] = $paypalSubscription['plan_id'];
            }

            if (Arr::get($paypalSubscription, 'status') === 'CANCELLED') {
                $statusUpdateTime = Arr::get($paypalSubscription, 'status_update_time');
                if ($statusUpdateTime) {
                    $subscriptionUpdateData['canceled_at'] = gmdate('Y-m-d H:i:s', strtotime($statusUpdateTime));
                }
            }

        }

        $transactionData = [
            'payment_method'      => 'paypal',
            'total'               => $amount,
            'vendor_charge_id'    => $chargeId,
            'payment_method_type' => 'paypal',
            'meta'                => [
                'payer' => $payer
            ]
        ];

        return SubscriptionService::recordRenewalPayment($transactionData, $subscriptionModel, $subscriptionUpdateData);
    }

    public function handleSinglePaymentRefund($data)
    {
        $refundData = Arr::get($data, 'refund', []);
        $paypalRefundId = Arr::get($refundData, 'id', '');
        $paypalRefundAmount = Helper::toCent(Arr::get($refundData, 'amount.value', 0));

        // Let's guess the transaction ID from links

        $paypalTransactionId = '';

        foreach (Arr::get($refundData, 'links', []) as $link) {
            if (Arr::get($link, 'rel') !== 'up') {
                continue;
            }

            $href = Arr::get($link, 'href', '');
            $paypalTransactionId = basename($href);
            if ($paypalTransactionId) {
                break;
            }
        }

        if (!$paypalTransactionId) {

            do_action('fluent_cart/dev_log', [
                'raw_data'    => $refundData,
                'status'      => 'failed',
                'title'       => __('Failed to find parent transaction for PayPal Refund webhook', 'fluent-cart'),
                'log_type'    => 'webhook',
                'module_type' => 'FluentCart\App\Modules\PaymentMethods\PayPal',
                'module_name' => 'PayPal'
            ]);

            return false; // We are really sorry that we could not get the transaction ID.
        }

        $parentTransaction = OrderTransaction::query()
            ->where('vendor_charge_id', $paypalTransactionId)
            ->where('status', Status::TRANSACTION_SUCCEEDED)
            ->first();

        if (!$parentTransaction) {

            do_action('fluent_cart/dev_log', [
                'raw_data'    => $refundData,
                'status'      => 'failed',
                'title'       => __('Failed to find parent transaction for PayPal Refund webhook', 'fluent-cart'),
                'log_type'    => 'webhook',
                'module_type' => 'FluentCart\App\Modules\PaymentMethods\PayPal',
                'module_name' => 'PayPal'
            ]);

            return false; // not our transaction, we are not handling this refund
        }

        return \FluentCart\App\Services\Payments\Refund::createOrRecordRefund([
            'vendor_charge_id' => $paypalRefundId,
            'payment_method'   => 'paypal',
            'total'            => $paypalRefundAmount,
        ], $parentTransaction);

    }


    public function handleWebhookRecurringPaymentRefunded($data)
    {
        $refundData = Arr::get($data, 'refund', []);

        if (Arr::get($refundData, 'state') !== 'completed') {
            return false;
        }

        $parentTxnId = Arr::get($refundData, 'sale_id', '');
        if (!$parentTxnId) {
            return false;
        }

        $subscriptionHash = sanitize_text_field(Arr::get($data, 'custom', ''));

        $parentTransaction = OrderTransaction::query()->where('vendor_charge_id', $parentTxnId)
            ->where('status', Status::TRANSACTION_SUCCEEDED)
            ->first();

        if (!$parentTransaction && $subscriptionHash) {
            $parentSubscription = Subscription::query()->where('uuid', $subscriptionHash)->first();
            $parentTransaction = $parentSubscription ? $parentSubscription->getLatestTransaction() : null;
        }

        if ($parentTransaction->transaction_type === Status::TRANSACTION_FAILED) {
            return null;
        }

        if (!$parentTransaction) {
            do_action('fluent_cart/dev_log', [
                'raw_data'    => $data,
                'status'      => 'failed',
                'title'       => __('Failed to find parent transaction for PayPal Refund webhook', 'fluent-cart'),
                'log_type'    => 'webhook',
                'module_type' => 'FluentCart\App\Modules\PaymentMethods\PayPal',
                'module_name' => 'PayPal'
            ]);

            return null;
        }

        $paypalRefundAmount = Helper::toCent(Arr::get($refundData, 'amount.total', 0));

        return \FluentCart\App\Services\Payments\Refund::createOrRecordRefund([
            'vendor_charge_id' => Arr::get($refundData, 'id'),
            'payment_method'   => 'paypal',
            'total'            => $paypalRefundAmount,
            'reason'           => Arr::get($refundData, 'description'),
        ], $parentTransaction);
    }

    public function handleWebhookRecurringProfileCancelled($data)
    {
        $subscriptionInfo = Arr::get($data, 'paypal_subscription', []);
        $subscriptionModel = $this->getSubscriptionByPaypalSubscriptionInfo($subscriptionInfo);

        if (!$subscriptionModel) {
            do_action('fluent_cart/dev_log', [
                'raw_data'    => $subscriptionInfo,
                'status'      => 'failed',
                'title'       => __('Failed to find Subscription for PayPal Cancel webhook', 'fluent-cart'),
                'log_type'    => 'webhook',
                'module_type' => 'FluentCart\App\Modules\PaymentMethods\PayPal',
                'module_name' => 'PayPal'
            ]);
            return;
        }

        if ($subscriptionModel->status === Status::SUBSCRIPTION_CANCELED || $subscriptionModel->current_payment_method !== 'paypal') {
            return;
        }

        return SubscriptionService::syncSubscriptionStates($subscriptionModel, [
            'status'      => Status::SUBSCRIPTION_CANCELED,
            'canceled_at' => DateTime::anyTimeToGmt(Arr::get($subscriptionInfo, 'status_update_time'))->format('Y-m-d H:i:s'),
            'meta'        => [
                'cancellation_reason' => Arr::get($subscriptionInfo, 'status_change_note', ''),
            ]
        ]);
    }

    public function handleWebhookRecurringProfileExpired($data)
    {
        $subscriptionInfo = Arr::get($data, 'paypal_subscription', []);
        $subscriptionModel = $this->getSubscriptionByPaypalSubscriptionInfo($subscriptionInfo);

        if (!$subscriptionModel) {
            do_action('fluent_cart/dev_log', [
                'raw_data'    => $subscriptionInfo,
                'status'      => 'failed',
                'title'       => __('Failed to find Subscription for PayPal Subscription Expired webhook', 'fluent-cart'),
                'log_type'    => 'webhook',
                'module_type' => 'FluentCart\App\Modules\PaymentMethods\PayPal',
                'module_name' => 'PayPal'
            ]);
            return;
        }

        return SubscriptionService::syncSubscriptionStates($subscriptionModel, [
            'status' => Status::SUBSCRIPTION_EXPIRED
        ]);
    }

    public function handleWebhookRecurringProfileSuspended($data)
    {
        $subscriptionInfo = Arr::get($data, 'paypal_subscription', []);
        $subscriptionModel = $this->getSubscriptionByPaypalSubscriptionInfo($subscriptionInfo);

        if (!$subscriptionModel) {
            do_action('fluent_cart/dev_log', [
                'raw_data'    => $subscriptionInfo,
                'status'      => 'failed',
                'title'       => __('Failed to find Subscription for PayPal Subscription Suspended webhook', 'fluent-cart'),
                'log_type'    => 'webhook',
                'module_type' => 'FluentCart\App\Modules\PaymentMethods\PayPal',
                'module_name' => 'PayPal'
            ]);
            return;
        }

        return SubscriptionService::syncSubscriptionStates($subscriptionModel, [
            'status' => Status::SUBSCRIPTION_PAUSED
        ]);

    }

    public function handleWebhookRecurringProfileReactivated($data)
    {
        $subscriptionInfo = Arr::get($data, 'paypal_subscription', []);
        $subscriptionModel = $this->getSubscriptionByPaypalSubscriptionInfo($subscriptionInfo);

        if (!$subscriptionModel) {
            do_action('fluent_cart/dev_log', [
                'raw_data'    => $subscriptionInfo,
                'status'      => 'failed',
                'title'       => __('Failed to find Subscription for PayPal Subscription Reactive webhook', 'fluent-cart'),
                'log_type'    => 'webhook',
                'module_type' => 'FluentCart\App\Modules\PaymentMethods\PayPal',
                'module_name' => 'PayPal'
            ]);
            return;
        }

        return SubscriptionService::syncSubscriptionStates($subscriptionModel, [
            'status' => Status::SUBSCRIPTION_ACTIVE
        ]);
    }

    public function handleWebhookDisputeCreated($data)
    {
        $disputeInfo = Arr::get($data, 'dispute', []);
        $disputeId = Arr::get($disputeInfo, 'dispute_id', '');
        if (empty($disputeId)) {
            return false;
        }

        $disputedTransactions = Arr::get($disputeInfo, 'disputed_transactions', []);

        if (count($disputedTransactions) > 1) {
            return false;
        }

        $status = Arr::get($disputeInfo, 'status', '');
        $stage = Arr::get($disputeInfo, 'dispute_life_cycle_stage', '');
        $reason = Arr::get($disputeInfo, 'reason', '');

        $fluentCartTransactions = [];
        foreach ($disputedTransactions as $transaction) {
            $transactionModel = OrderTransaction::query()->where('vendor_charge_id', Arr::get($transaction, 'seller_transaction_id'))->first();
            if ($transaction) {
                $fluentCartTransactions[] = $transactionModel;
            }
        }
        if (empty($fluentCartTransactions)) {
            return false;
        }

        $isChargeRefundable = in_array($status, ['OPEN', 'WAITING_FOR_SELLER_RESPONSE']);

        $transactionModel = $fluentCartTransactions[0];
        $transactionModel->update([
            'transaction_type' => Status::TRANSACTION_TYPE_DISPUTE,
            'meta' => array_merge($transactionModel->meta, [
                'dispute_id' => $disputeId,
                'dispute_reason' => $reason,
                'is_dispute_actionable' => in_array($stage, ['CHARGEBACK', 'REVIEW']),
                'is_charge_refundable' => $isChargeRefundable,
                'status' => $status
            ])
        ]);

        return true;
    }

    public function handleWebhookDisputeUpdated($data)
    {
        $disputeInfo = Arr::get($data, 'dispute', []);
        $disputeId = Arr::get($disputeInfo, 'dispute_id', '');
        if (empty($disputeId)) {
            return false;
        }

        $disputedTransactions = Arr::get($disputeInfo, 'disputed_transactions', []);

        if (count($disputedTransactions) > 1) {
            return false;
        }

        $stage = Arr::get($disputeInfo, 'dispute_life_cycle_stage', '');

        $fluentCartTransactions = [];
        foreach ($disputedTransactions as $transaction) {
            $transactionModel = OrderTransaction::query()->where('vendor_charge_id', Arr::get($transaction, 'seller_transaction_id'))->first();
            if (!$transactionModel) {
                continue;
            }
            $fluentCartTransactions[] = $transactionModel;
        }

        if (empty($fluentCartTransactions)) {
            return false;
        }

        $transactionModel = $fluentCartTransactions[0];
        $status = Arr::get($disputeInfo, 'status', '');
        $isChargeRefundable = in_array($status, ['OPEN', 'WAITING_FOR_SELLER_RESPONSE']) || in_array($stage, ['CHARGEBACK', 'INQUIRY']);

        if ($stage === 'CHARGEBACK') {
            $transactionModel->update([
                'meta' => array_merge($transactionModel->meta, [
                    'is_dispute_actionable' => true,
                    'dispute_status' => Arr::get($disputeInfo, 'status', ''),
                    'is_charge_refundable' => $isChargeRefundable
                ])
            ]);
        } else {
            $transactionModel->update([
                'meta' => array_merge($transactionModel->meta, [
                    'is_dispute_actionable' => false,
                    'dispute_status' => Arr::get($disputeInfo, 'status', ''),
                    'is_charge_refundable' => $isChargeRefundable
                ])
            ]);
        }

        return true;

    }

    public function handleWebhookDisputeResolved($data)
    {
        $disputeInfo = Arr::get($data, 'dispute', []);
        $disputeId = Arr::get($disputeInfo, 'dispute_id', '');
        if (empty($disputeId)) {
            return false;
        }

        $disputedTransactions = Arr::get($disputeInfo, 'disputed_transactions', []);
        if (count($disputedTransactions) > 1) {
            return false;
        }

        $status = Arr::get($disputeInfo, 'status', '');
        if ($status !== 'RESOLVED') {
            return false;
        }

        $fluentCartTransactions = [];
        foreach ($disputedTransactions as $transaction) {
            $transactionModel = OrderTransaction::query()->where('vendor_charge_id', Arr::get($transaction, 'seller_transaction_id'))->first();
            if (!$transactionModel) {
                continue;
            }
            $fluentCartTransactions[] = $transactionModel;
        }

        if (empty($fluentCartTransactions)) {
            return false;
        }

        // we are handling disputes only with one transaction - PayPal allow user to select multiple transactions on dispute creation
        $transactionModel = $fluentCartTransactions[0];

        if ($transactionModel->status === Status::TRANSACTION_DISPUTE_LOST) { // already dispute claim accepted via admin dashboard
            return false;
        }

        // dispute always resolved via refund in PayPal if outcome favoured buyer. Regardless! the main transaction remains as charge if not dispute claim already accepted via admin dashboard
        $transactionModel->update([
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'meta' => array_merge($transactionModel->meta, [
                'is_dispute_actionable' => false,
                'is_charge_refundable' => false,
                'dispute_status' => $status
            ])
        ]);

        return true;
    }

    private function getSubscriptionByPaypalSubscriptionInfo($subscriptionInfo = [])
    {
        $id = Arr::get($subscriptionInfo, 'id', '');
        if (empty($id)) {
            return null;
        }

        $subscription = Subscription::query()->where('vendor_subscription_id', $id)->first();

        if (!$subscription) {
            $subscriptionHash = Arr::get($subscriptionInfo, 'custom_id', '');
            if ($subscriptionHash) {
                $subscription = Subscription::query()->where('uuid', $subscriptionHash)->first();
            }
        }

        return $subscription;
    }


    private static function getPayPalSettings()
    {
        if (!self::$paypalSettings) {
            self::$paypalSettings = new PayPalSettingsBase();
        }
        return self::$paypalSettings;
    }
}
