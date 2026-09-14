<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Events\Subscription\SubscriptionRenewalFailed;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\SubscriptionHelper;
use FluentCart\Framework\Support\Arr;

class IPN
{
    private const TEST_VERIFYING_URL = 'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature';
    private const LIVE_VERIFYING_URL = 'https://api-m.paypal.com/v1/notifications/verify-webhook-signature';
    private const RESYNC_RETRY_HOOK = 'fluent_cart/paypal/subscription_resync_retry';
    private const PENDING_SALES_META = 'paypal_pending_sales';
    private static $paypalSettings = null;

    /** @var \WP_Error|null unacknowledged failure from the recurring-payment handler */
    private static $recurringPaymentError = null;

    /**
     * Indirection so PHPStan reads the declared property type instead of
     * narrowing it to the literal null assigned right before processPaypalWebhookEvents().
     *
     * @return \WP_Error|null
     */
    private static function getRecurringPaymentError()
    {
        return self::$recurringPaymentError;
    }

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
        add_action('fluent_cart/payments/paypal/webhook_billing_subscription_payment_failed', [$this, 'processSubscriptionPaymentFailed'], 10, 1);

        // dispute
        add_action('fluent_cart/payments/paypal/webhook_customer_dispute_created', [$this, 'handleWebhookDisputeCreated'], 10, 1);
        add_action('fluent_cart/payments/paypal/webhook_customer_dispute_updated', [$this, 'handleWebhookDisputeUpdated'], 10, 1);
        add_action('fluent_cart/payments/paypal/webhook_customer_dispute_resolved', [$this, 'handleWebhookDisputeResolved'], 10, 1);

        add_action(self::RESYNC_RETRY_HOOK, [$this, 'handleResyncRetry'], 10, 2);
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
                $subscriptionHash = Arr::get($resource, 'custom', '');
                $subscription = $subscriptionHash ? Subscription::query()
                    ->where('uuid', $subscriptionHash)
                    ->where('current_payment_method', 'paypal')
                    ->first() : null;

                if ($subscription && $subscription->status === Status::SUBSCRIPTION_INTENDED) {
                    // First payment - confirm initial order and activate subscription, rare case
                    do_action('fluent_cart/payments/paypal/webhook_payment_capture_completed', [
                        'charge'                 => $resource,
                        'vendor_subscription_id' => $billingAgreementId,
                    ]);
                } else {
                    // Renewal payment
                    do_action('fluent_cart/payments/paypal/webhook_subscription_payment_received', [
                        'charge'                 => $resource,
                        'vendor_subscription_id' => $billingAgreementId,
                    ]);
                }
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
                'paypal_subscription' => $resource,
                'webhook_event_id'    => Arr::get($event, 'id', '')
            ]);
        }

    }


    public function processChargeCaptured($data)
    {
        $charge = Arr::get($data, 'charge', []);

        $vendorChargeId = Arr::get($charge, 'id', '');
        $vendorSubscriptionId = Arr::get($data, 'vendor_subscription_id', '');

        // Handle first payment for intended subscriptions
        if ($vendorSubscriptionId) {
            // Same reasoning as processPaypalWebhookEvents(): match by uuid, not
            // vendor_subscription_id, which isn't set yet for an intended subscription.
            $subscriptionHash = Arr::get($charge, 'custom', '');
            $subscription = $subscriptionHash ? Subscription::query()
                ->where('uuid', $subscriptionHash)
                ->where('current_payment_method', 'paypal')
                ->first() : null;

            if ($subscription && $subscription->status === Status::SUBSCRIPTION_INTENDED) {
                $transaction = $subscription->getLatestTransaction();
                if ($transaction) {
                    $mismatch = false;

                    if ($transaction->status !== Status::TRANSACTION_SUCCEEDED) {
                        $paidAmount = Helper::toCent(Arr::get($charge, 'amount.total', 0));
                        $paidCurrency = strtoupper(Arr::get($charge, 'amount.currency', ''));

                        if ($paidCurrency && $transaction->currency && strtoupper($transaction->currency) !== $paidCurrency) {
                            $mismatch = true;
                            fluent_cart_add_log(
                                __('PayPal Webhook Currency Mismatch', 'fluent-cart'),
                                sprintf(
                                    /* translators: %1$s: expected currency, %2$s: received currency, %3$s: transaction UUID */
                                    __('Payment currency mismatch detected. Expected: %1$s, Received: %2$s. Transaction: %3$s. Subscription not confirmed.', 'fluent-cart'),
                                    $transaction->currency,
                                    $paidCurrency,
                                    $transaction->uuid
                                ),
                                'error',
                                [
                                    'module_name' => 'order',
                                    'module_id'   => $transaction->order_id,
                                    'log_type'    => 'webhook'
                                ]
                            );
                        } else if ($transaction->total > 0 && $paidAmount != PayPalHelper::wireCents($transaction->total, $transaction->currency)) {
                            $mismatch = true;
                            fluent_cart_add_log(
                                __('PayPal Webhook Amount Mismatch', 'fluent-cart'),
                                sprintf(
                                    /* translators: %1$s: expected amount, %2$s: received amount, %3$s: transaction UUID */
                                    __('Payment amount mismatch detected. Expected: %1$s, Received: %2$s. Transaction: %3$s. Subscription not confirmed.', 'fluent-cart'),
                                    Helper::toDecimal(PayPalHelper::wireCents($transaction->total, $transaction->currency)),
                                    Helper::toDecimal($paidAmount),
                                    $transaction->uuid
                                ),
                                'error',
                                [
                                    'module_name' => 'order',
                                    'module_id'   => $transaction->order_id,
                                    'log_type'    => 'webhook'
                                ]
                            );
                        } else {
                            // Confirm transaction with actual charge amount from webhook
                            (new Processor())->confirmPaymentSuccessByCharge($transaction, [
                                'vendor_charge_id'    => $vendorChargeId,
                                'status'              => Status::TRANSACTION_SUCCEEDED,
                                'total'               => $paidAmount,
                                'payment_method_type' => 'PayPal',
                            ]);
                        }
                    }

                    if (!$mismatch) {
                        // Activate even if the transaction was already confirmed elsewhere (e.g. AJAX return) — activateSubscription() guards against re-activating.
                        $paypalSubscription = API::getResource('billing/subscriptions/' . $vendorSubscriptionId);
                        if (!is_wp_error($paypalSubscription) && $paypalSubscription) {
                            (new Processor())->activateSubscription($paypalSubscription, $transaction, $subscription);
                        } else {
                            fluent_cart_add_log(
                                __('PayPal Subscription Activation Skipped', 'fluent-cart'),
                                sprintf(
                                    /* translators: %1$s: subscription UUID, %2$s: vendor subscription ID */
                                    __('Could not fetch PayPal subscription resource to activate. Subscription: %1$s, Vendor Subscription ID: %2$s.', 'fluent-cart'),
                                    $subscription->uuid,
                                    $vendorSubscriptionId
                                ),
                                'error',
                                [
                                    'module_name' => 'order',
                                    'module_id'   => $transaction->order_id,
                                    'log_type'    => 'webhook'
                                ]
                            );
                        }
                    }
                }
                return;
            }
        }

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

        if (is_wp_error($paypalIntent)) {
            fluent_cart_add_log(
                __('PayPal Webhook Verification Failed', 'fluent-cart'),
                __('Could not verify PayPal payment from webhook. Charge ID: ', 'fluent-cart') . $vendorChargeId,
                'error',
                [
                    'module_name' => 'order',
                    'module_id'   => $transaction->order_id,
                    'log_type'    => 'webhook'
                ]
            );
            return;
        }

        // Verify that the paid amount and currency match the expected transaction
        $paidAmount = 0;
        $paidCurrency = '';
        foreach (Arr::get($paypalIntent, 'purchase_units', []) as $unit) {
            $paidAmount += Helper::toCent(Arr::get($unit, 'amount.value', 0));
            if (!$paidCurrency) {
                $paidCurrency = strtoupper(Arr::get($unit, 'amount.currency_code', ''));
            }
        }

        if ($paidCurrency && $transaction->currency && strtoupper($transaction->currency) !== $paidCurrency) {
            fluent_cart_add_log(
                __('PayPal Webhook Currency Mismatch', 'fluent-cart'),
                sprintf(
                    /* translators: %1$s: expected currency, %2$s: received currency, %3$s: transaction UUID */
                    __('Payment currency mismatch detected. Expected: %1$s, Received: %2$s. Transaction: %3$s. Order not confirmed.', 'fluent-cart'),
                    $transaction->currency,
                    $paidCurrency,
                    $transaction->uuid
                ),
                'error',
                [
                    'module_name' => 'order',
                    'module_id'   => $transaction->order_id,
                    'log_type'    => 'webhook'
                ]
            );
            return;
        }

        $expectedAmount = PayPalHelper::wireCents($transaction->total, $transaction->currency);

        if ($transaction->total > 0 && $paidAmount != $expectedAmount) {
            fluent_cart_add_log(
                __('PayPal Webhook Amount Mismatch', 'fluent-cart'),
                sprintf(
                    /* translators: %1$s: expected amount, %2$s: received amount, %3$s: transaction UUID */
                    __('Payment amount mismatch detected. Expected: %1$s, Received: %2$s. Transaction: %3$s. Order not confirmed.', 'fluent-cart'),
                    Helper::toDecimal($expectedAmount),
                    Helper::toDecimal($paidAmount),
                    $transaction->uuid
                ),
                'error',
                [
                    'module_name' => 'order',
                    'module_id'   => $transaction->order_id,
                    'log_type'    => 'webhook'
                ]
            );
            return;
        }

        // All Verified! Let's update the transaction and order
        (new Processor())->confirmPaymentSuccessByCharge($transaction, [
            'vendor_charge_id'    => $vendorChargeId,
            'payment_method_type' => 'PayPal',
            'status'              => Status::TRANSACTION_SUCCEEDED,
            'total'               => $paidAmount,
            'payment_source'      => Arr::get($paypalIntent, 'payment_source', []),
            'meta'               => [
                'payer' => Arr::get($paypalIntent, 'payer', [])
            ]
        ]);

        // System subscription: persist the vault token from the captured order
        // (idempotent — the AJAX confirmation may have done it already).
        (new Processor())->maybePersistVaultToken($transaction, $paypalIntent);

    }


    // called only when webhook/ipn hits
    public function verifyAndProcess($data = []): void
    {
        $this->processWebhook();
    }

    /**
     * Verify the webhook signature
     *
     * @param string      $webhookId PayPal webhook ID for the current mode.
     * @param string|null $rawBody   Raw request body; read from php://input when omitted.
     * @return true|\WP_Error
     */
    public function verifyWebhook($webhookId, $rawBody = null)
    {
        $disableWebhookVerification = apply_filters('fluent_cart/payments/paypal/disable_webhook_verification', 'no', []);
        if ($disableWebhookVerification === 'yes') {
            return true;
        }

        if (empty($webhookId)) {
            return new \WP_Error('webhook_id_missing', __('Webhook ID is missing.', 'fluent-cart'));
        }

        $webhookId = trim($webhookId);
        $header = self::getRequestHeaders();

        // make all headers lowercase
        $header = array_change_key_case($header, CASE_LOWER);
        if (!isset($header['paypal-auth-algo']) || !isset($header['paypal-cert-url']) ||
            !isset($header['paypal-transmission-id']) || !isset($header['paypal-transmission-sig']) ||
            !isset($header['paypal-transmission-time'])) {

            return new \WP_Error('webhook_header_missing', __('Required PayPal webhook headers are missing.', 'fluent-cart'), [
                'headers' => $header
            ]);
        }

        if ($rawBody === null) {
            $rawBody = file_get_contents('php://input');
        }
        $webhookEvent = json_decode($rawBody);
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
        $statusCode = $this->handleWebhookRequest(file_get_contents('php://input'));

        // exit(int) only sets the process exit code; the HTTP status has to be
        // sent explicitly or PayPal records every rejection as delivered.
        status_header($statusCode);
        exit;
    }

    /**
     * Handle one PayPal webhook delivery and return the HTTP status to answer with.
     *
     * Separated from processWebhook() so the request body, headers and status
     * can be exercised without php://input or exit().
     *
     * @param string $rawBody Raw JSON request body.
     * @return int
     */
    public function handleWebhookRequest($rawBody): int
    {
        $post_data = (string) $rawBody;

        $data = json_decode($post_data, true);

        if (empty($data)) {
            return 200;
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
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
            'PAYMENT.CAPTURE.COMPLETED',
            'CUSTOMER.DISPUTE.CREATED',
            'CUSTOMER.DISPUTE.UPDATED',
            'CUSTOMER.DISPUTE.RESOLVED',
            'CHECKOUT.ORDER.APPROVED' // we don't need this
        ];

        if (!in_array($webhookType, $webhookEvents)) {
            return 200;
        }

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

            $verified = $this->verifyWebhook($webhookId, $post_data);

            if (is_wp_error($verified)) {
                $data = json_encode($verified->get_error_data());
                fluent_cart_add_log($verified->get_error_message() . ' Webhook: ' . $webhookType, $data, 'error', [
                    'log_type'    => 'webhook',
                    'module_type' => 'FluentCart\App\Modules\PaymentMethods\PayPal',
                    'module_name' => 'PayPal',
                ]);

                return 400;
            }
        }

        // Only a delivery that passed signature verification (or whose verification
        // the site explicitly bypassed above) may reach extension listeners.
        // Firing this earlier let an anonymous sender feed forged events to every
        // listener even though the request was then rejected (FC-SEC-05).
        do_action('fluent_cart/paypal_webhook_received', [
            'data' => $data,
            'raw'  => $post_data
        ]);

        self::$recurringPaymentError = null;

        $this->processPaypalWebhookEvents($data);

        $recurringPaymentError = self::getRecurringPaymentError();

        if (is_wp_error($recurringPaymentError)) {
            fluent_cart_add_log(
                'PayPal renewal processing failed: ' . $recurringPaymentError->get_error_message() . ' Webhook: ' . $webhookType,
                json_encode($recurringPaymentError->get_error_data()),
                'error',
                [
                    'log_type'    => 'webhook',
                    'module_type' => 'FluentCart\App\Modules\PaymentMethods\PayPal',
                    'module_name' => 'PayPal',
                ]
            );

            // Non-2xx so PayPal redelivers; the payment is not recorded yet.
            return 500;
        }

        return 200;
    }

    /**
     * Request headers, falling back to $_SERVER for SAPIs without getallheaders().
     *
     * @return array<string, string>
     */
    private static function getRequestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            return is_array($headers) ? $headers : [];
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;
            }
        }

        return $headers;
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

    public function processSubscriptionPaymentFailed($data)
    {
        $paypalSubscription = Arr::get($data, 'paypal_subscription', []);
        $vendorSubscriptionId = sanitize_text_field(Arr::get($paypalSubscription, 'id'));

        $subscriptionModel = $vendorSubscriptionId ? Subscription::query()->where('vendor_subscription_id', $vendorSubscriptionId)->first() : null;

        if (!$subscriptionModel) {
            $subscriptionHash = Arr::get($paypalSubscription, 'custom_id', '');
            if ($subscriptionHash) {
                $subscriptionModel = Subscription::query()->where('uuid', $subscriptionHash)->first();
            }
        }

        if (!$subscriptionModel || $subscriptionModel->current_payment_method !== 'paypal') {
            return false;
        }

        $order = $subscriptionModel->order;
        if (!$order) {
            return false;
        }

        $failedCount = Arr::get($paypalSubscription, 'billing_info.failed_payments_count');
        $webhookEventId = sanitize_text_field(Arr::get($data, 'webhook_event_id', ''));

        // One notification per failed attempt. The webhook event ID is the durable
        // discriminator — unique per PayPal delivery, stable across a redelivery of
        // that same event (mirrors the Stripe invoice-id claim in
        // StripeGateway/Webhook/IPN.php). failed_payments_count is NOT usable for this:
        // it resets to 0 on the next successful payment, so a later billing cycle that
        // reaches the same failure count would reuse an old permanent claim key and
        // silently suppress its own notification.
        $claimDiscriminator = $webhookEventId !== '' ? $webhookEventId : ($failedCount !== null ? (string)(int)$failedCount : '');

        if ($claimDiscriminator !== '') {
            $claimKey = 'fct_sub_renewal_failed_' . $subscriptionModel->id . '_' . $claimDiscriminator;
            if (!add_option($claimKey, '1', '', false)) {
                return true; // already notified for this failed attempt
            }
        }

        $error = $failedCount !== null
            ? sprintf(
                /* translators: %d: number of consecutive failed payments reported by PayPal */
                __('PayPal reported a failed subscription payment (failed attempts: %d).', 'fluent-cart'),
                (int)$failedCount
            )
            : __('PayPal reported a failed subscription payment.', 'fluent-cart');

        try {
            (new SubscriptionRenewalFailed($subscriptionModel, $order, $subscriptionModel->customer, $error))->dispatch();
        } catch (\Throwable $e) {
            if (isset($claimKey)) {
                delete_option($claimKey);
            }
            throw $e;
        }

        return true;
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

        if ($vendorSubscriptionId && !$subscriptionModel->vendor_subscription_id) {
            $subscriptionModel->update(['vendor_subscription_id' => $vendorSubscriptionId]);
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

        // Fetch PayPal subscription data once — used for plan verification and renewal processing
        $paypalSubscription = $vendorSubscriptionId ? API::getResource('billing/subscriptions/' . $vendorSubscriptionId) : null;

        // Verify the PayPal subscription plan matches the expected plan
        if ($subscriptionModel->vendor_plan_id && $paypalSubscription && !is_wp_error($paypalSubscription)) {
            $paypalPlanId = Arr::get($paypalSubscription, 'plan_id', '');
            if ($paypalPlanId && $paypalPlanId !== $subscriptionModel->vendor_plan_id) {
                fluent_cart_add_log(
                    __('PayPal Recurring Plan Mismatch', 'fluent-cart'),
                    sprintf(
                        /* translators: %1$s: expected plan ID, %2$s: received plan ID, %3$d: subscription ID */
                        __('Recurring payment plan mismatch. Expected: %1$s, Received: %2$s. Subscription ID: %3$d. Payment not recorded.', 'fluent-cart'),
                        $subscriptionModel->vendor_plan_id,
                        $paypalPlanId,
                        $subscriptionModel->id
                    ),
                    'error',
                    [
                        'module_type' => 'FluentCart\App\Models\Subscription',
                        'module_id'   => $subscriptionModel->id,
                        'module_name' => 'subscription',
                        'log_type'    => 'webhook'
                    ]
                );
                return false;
            }
        }

        // Latest charge transaction = pending one for initial subscription OR for renewal
        $latestTransaction = $subscriptionModel->getLatestTransaction();

        if ($latestTransaction && !$latestTransaction->vendor_charge_id && $latestTransaction->total) {

            if (!is_array($paypalSubscription)) {
                self::$recurringPaymentError = is_wp_error($paypalSubscription)
                    ? $paypalSubscription
                    : new \WP_Error('paypal_subscription_fetch_failed', __('Could not fetch the PayPal subscription to confirm this payment.', 'fluent-cart'));
                if (self::isRetryableRemoteError(self::$recurringPaymentError)) {
                    self::scheduleResyncRetry($subscriptionModel, $chargeId);
                }
                return false;
            }

            $paypalSubscriptions = new PayPalSubscriptions();

            // One remote pull decides everything below — the sorted list answers
            // the first-payment question and, on a mismatch, feeds the resync.
            $remoteTransactions = $paypalSubscriptions->fetchSortedRemoteTransactions($subscriptionModel, $paypalSubscription);

            if (is_wp_error($remoteTransactions)) {
                if (self::isTerminalRenewalError($remoteTransactions, $subscriptionModel)) {
                    return true;
                }

                self::$recurringPaymentError = $remoteTransactions;
                if (self::isRetryableRemoteError($remoteTransactions)) {
                    self::scheduleResyncRetry($subscriptionModel, $chargeId);
                }
                return false;
            }

            // The sale must be on the list AND completed there — a listed-but-pending
            // entry binds nothing downstream (the resync loop only consumes completed
            // sales), so it takes the same lag path as an absent one.
            $saleInRemoteList = false;
            foreach ($remoteTransactions as $remoteTransaction) {
                if (Arr::get($remoteTransaction, 'id') === $chargeId
                    && strtolower((string) Arr::get($remoteTransaction, 'status')) === 'completed'
                ) {
                    $saleInRemoteList = true;
                    break;
                }
            }

            if (!$saleInRemoteList) {
                self::$recurringPaymentError = new \WP_Error(
                    'paypal_transaction_list_lagging',
                    __('The incoming sale is not yet on the PayPal transaction list.', 'fluent-cart')
                );
                self::scheduleResyncRetry($subscriptionModel, $chargeId);
                return false;
            }

            $earliestSale = $paypalSubscriptions->getEarliestCompletedRemoteSale($remoteTransactions);

            if ($earliestSale && Arr::get($earliestSale, 'id') === $chargeId) {
                $paypalSubscriptions->bindSaleToTransaction(
                    $latestTransaction,
                    $chargeId,
                    $amount,
                    Arr::get($paypalSubscription, 'subscriber', []),
                    DateTime::anyTimeToGmt(Arr::get($earliestSale, 'time'))->format('Y-m-d H:i:s')
                );

                return true;
            }

            $result = $paypalSubscriptions->reSyncSubscriptionFromRemote(
                $subscriptionModel,
                $paypalSubscription,
                $remoteTransactions
            );

            if (is_wp_error($result)) {
                if (self::isTerminalRenewalError($result, $subscriptionModel)) {
                    return true;
                }

                self::$recurringPaymentError = $result;
                if (self::isRetryableRemoteError($result)) {
                    self::scheduleResyncRetry($subscriptionModel, $chargeId);
                }
                return false;
            }

            return true;
        }


        // Now we are sure, we have a renewal payment for this subscription!

        // we will just create the transaction here

        $subscriptionUpdateData = [
            'current_payment_method' => 'paypal',
            'vendor_subscription_id' => $vendorSubscriptionId
        ];

        $payer = ($paypalSubscription && !is_wp_error($paypalSubscription)) ? Arr::get($paypalSubscription, 'subscriber', []) : [];
        if ($paypalSubscription && !is_wp_error($paypalSubscription)) {
            $subscriptionUpdateData['status'] = (new SubscriptionManager)->getCorrectSubscriptionStatus(Arr::get($paypalSubscription, 'status'));
            $nextBillingDate = Arr::get($paypalSubscription, 'billing_info.next_billing_time');
            if ($nextBillingDate) {
                $subscriptionUpdateData['next_billing_date'] = SubscriptionHelper::safeTimestampToDatetime($nextBillingDate);
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

        // Credited before recording: recordRenewalPayment() recomputes bill_count
        // and the installment end-of-term inside itself, so an outstanding-balance
        // collection's extra cycles must already be on the books when it runs.
        // Idempotent per sale id, so a failed record retried later credits once.
        $paypalSubscriptions = new PayPalSubscriptions();
        $credited = $paypalSubscriptions->creditOutstandingCollection($subscriptionModel, $chargeId, $amount);

        // Credit undecided: record nothing. Once a transaction exists this
        // method returns early on every redelivery, so the missed cycles would
        // become unreachable — leaving the sale unrecorded keeps both recovery
        // channels (PayPal redelivery, the resync ladder) able to repair it.
        if (is_wp_error($credited)) {
            self::$recurringPaymentError = $credited;
            self::scheduleResyncRetry($subscriptionModel, $chargeId);
            return false;
        }

        $result = SubscriptionService::recordRenewalPayment($transactionData, $subscriptionModel, $subscriptionUpdateData);

        if (is_wp_error($result)) {
            if ($result->get_error_code() === 'transaction_exists') {
                return true;
            }

            if ($result->get_error_code() === 'lock_failed') {
                // Contention is not proof of a committed payment. Keep the credit
                // available to the lock holder, but retry until the sale is recorded.
                if ($paypalSubscriptions->hasRecordedSale($subscriptionModel, $chargeId)) {
                    return true;
                }

                self::$recurringPaymentError = $result;
                self::scheduleResyncRetry($subscriptionModel, $chargeId);
                return false;
            }

            if ($credited) {
                $paypalSubscriptions->revokeOutstandingCollection($subscriptionModel, $chargeId);
            }

            if (self::isTerminalRenewalError($result, $subscriptionModel)) {
                return true;
            }

            self::$recurringPaymentError = $result;
            if (self::isRetryableRemoteError($result)) {
                self::scheduleResyncRetry($subscriptionModel, $chargeId);
            }
            return false;
        }

        return true;
    }

    /**
     * Queue a resync at +1h / +6h / +24h as a backstop to PayPal's finite redelivery
     * window. One ladder per subscription (args are [subscription, attempt]); the
     * sale ids it chases accumulate in PENDING_SALES_META.
     *
     * @param Subscription $subscriptionModel
     * @param string|null  $saleId  the sale this attempt is chasing, if any
     * @param int          $attempt 1-based position on the delay ladder
     * @return void
     */
    private static function scheduleResyncRetry(Subscription $subscriptionModel, $saleId = null, $attempt = 1)
    {
        // After the guard: without Action Scheduler no worker ever prunes the set.
        if (!function_exists('as_schedule_single_action') || !function_exists('as_next_scheduled_action')) {
            return;
        }

        if ($saleId) {
            $pending = self::getPendingSales($subscriptionModel);
            if (!in_array($saleId, $pending, true)) {
                $pending[] = $saleId;
                $subscriptionModel->updateMeta(self::PENDING_SALES_META, $pending);
            }
        }

        $delays = [HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS];

        if (!isset($delays[$attempt - 1])) {
            fluent_cart_add_log(
                __('PayPal renewal resync retries exhausted — needs manual review', 'fluent-cart'),
                sprintf(
                    /* translators: 1: subscription ID, 2: comma separated PayPal sale IDs */
                    __('All scheduled resync retries failed. Subscription ID: %1$d. Unresolved PayPal sales: %2$s', 'fluent-cart'),
                    $subscriptionModel->id,
                    implode(', ', self::getPendingSales($subscriptionModel)) ?: __('none recorded', 'fluent-cart')
                ),
                'error',
                [
                    'module_type' => 'FluentCart\App\Models\Subscription',
                    'module_id'   => $subscriptionModel->id,
                    'module_name' => 'subscription',
                    'log_type'    => 'webhook'
                ]
            );
            $subscriptionModel->deleteMeta(self::PENDING_SALES_META);
            return;
        }

        // Start at $attempt so the running attempt's own in-progress action
        // (which as_next_scheduled_action reports as scheduled) can't block
        // its follow-up.
        for ($pending = $attempt; $pending <= count($delays); $pending++) {
            if (as_next_scheduled_action(self::RESYNC_RETRY_HOOK, [$subscriptionModel->id, $pending], 'fluent-cart')) {
                return;
            }
        }

        as_schedule_single_action(
            time() + $delays[$attempt - 1],
            self::RESYNC_RETRY_HOOK,
            [$subscriptionModel->id, $attempt],
            'fluent-cart'
        );
    }

    public function handleResyncRetry($subscriptionId, $attempt = 1)
    {
        /** @var Subscription|null $subscriptionModel */
        $subscriptionModel = Subscription::query()->find($subscriptionId);

        if (!$subscriptionModel || $subscriptionModel->current_payment_method !== 'paypal' || !$subscriptionModel->vendor_subscription_id) {
            return;
        }

        $result = (new PayPalSubscriptions())->reSyncSubscriptionFromRemote($subscriptionModel);

        if (is_wp_error($result)) {
            if (self::isTerminalRenewalError($result, $subscriptionModel) || !self::isRetryableRemoteError($result)) {
                $subscriptionModel->deleteMeta(self::PENDING_SALES_META);
                return;
            }

            self::scheduleResyncRetry($subscriptionModel, null, (int) $attempt + 1);
            return;
        }

        // A successful resync only means PayPal answered. Its transaction list
        // lags, so the sale that armed this ladder can still be absent (or
        // listed as non-completed, which binds nothing). Local rows are the
        // only proof the payment landed.
        if (self::pruneResolvedSales($subscriptionModel)) {
            self::scheduleResyncRetry($subscriptionModel, null, (int) $attempt + 1);
        }
    }

    /**
     * @param Subscription $subscriptionModel
     * @return string[]
     */
    private static function getPendingSales(Subscription $subscriptionModel)
    {
        $pending = $subscriptionModel->getMeta(self::PENDING_SALES_META, []);

        return array_values(array_filter((array) $pending, 'is_string'));
    }

    /**
     * Drop the sales that now have a local transaction, keep the rest.
     * A recorded-then-refunded row still counts as recorded.
     *
     * @param Subscription $subscriptionModel
     * @return bool true while at least one sale is still unaccounted for
     */
    private static function pruneResolvedSales(Subscription $subscriptionModel)
    {
        $pending = self::getPendingSales($subscriptionModel);

        if (!$pending) {
            return false;
        }

        $recorded = OrderTransaction::query()
            ->where('subscription_id', $subscriptionModel->id)
            ->whereIn('vendor_charge_id', $pending)
            ->get(['vendor_charge_id'])
            ->pluck('vendor_charge_id')
            ->toArray();

        $unresolved = array_values(array_diff($pending, $recorded));

        if ($unresolved === $pending) {
            return true;
        }

        // An empty array does not survive the meta cast round-trip, so drop the row.
        if ($unresolved) {
            $subscriptionModel->updateMeta(self::PENDING_SALES_META, $unresolved);
        } else {
            $subscriptionModel->deleteMeta(self::PENDING_SALES_META);
        }

        return (bool) $unresolved;
    }

    /**
     * A retry only helps against transient remote failures. PayPal reports a
     * missing/invalid resource in the error body's `name` field (the WP_Error
     * code stays `general_error` for REST errors), so inspect the data.
     *
     * @param \WP_Error $error
     * @return bool
     */
    private static function isRetryableRemoteError($error)
    {
        $data = $error->get_error_data();
        $name = is_array($data) ? Arr::get($data, 'name', '') : '';

        return !in_array($name, ['RESOURCE_NOT_FOUND', 'INVALID_RESOURCE_ID'], true);
    }

    /**
     * Errors redelivery can never fix (local records gone): log loudly and ack,
     * since a 500 would make PayPal retry for days and can disable the endpoint.
     *
     * @param \WP_Error    $error
     * @param Subscription $subscriptionModel
     * @return bool true when the error was terminal and has been logged
     */
    private static function isTerminalRenewalError($error, $subscriptionModel)
    {
        if (!in_array($error->get_error_code(), ['subscription_not_found', 'parent_order_not_found'], true)) {
            return false;
        }

        fluent_cart_add_log(
            __('PayPal renewal payment could not be recorded — needs manual review', 'fluent-cart'),
            sprintf(
                /* translators: %1$s: error message, %2$d: subscription ID */
                __('%1$s Subscription ID: %2$d', 'fluent-cart'),
                $error->get_error_message(),
                $subscriptionModel->id
            ),
            'error',
            [
                'module_type' => 'FluentCart\App\Models\Subscription',
                'module_id'   => $subscriptionModel->id,
                'module_name' => 'subscription',
                'log_type'    => 'webhook'
            ]
        );

        return true;
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

        if ($parentTransaction->status === Status::TRANSACTION_FAILED) {
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
