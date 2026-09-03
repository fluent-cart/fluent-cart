<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway;

use FluentCart\App\App;
use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Modules\Subscriptions\Services\SystemChargeService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;

class Confirmations
{
    public function init()
    {
        add_action('wp_ajax_nopriv_fluent_cart_confirm_stripe_payment', [$this, 'confirmStripePayment']);
        add_action('wp_ajax_fluent_cart_confirm_stripe_payment', [$this, 'confirmStripePayment']);

        add_filter('fluent_cart/form_disable_stripe_connect', function ($value, $args) {
            if (defined('FCT_STRIPE_LIVE_PUBLIC_KEY') || defined('FCT_STRIPE_TEST_PUBLIC_KEY')) {
                return true;
            }

            return $value;
        }, 10, 2);

    
       // Browser return from Stripe hosted checkout, dispatched by core
       // WebRoutes (?fluent-cart=fct_stripe_hosted): confirm first, then
       // send the buyer to the filterable success URL.
       add_action('fluent_cart_action_fct_stripe_hosted', [$this, 'handleHostedReturn']);

       // Browser return from an issuer-forced 3DS redirect on an onsite confirm
       // (?fluent-cart=fct_stripe_onsite_return), dispatched the same way.
       add_action('fluent_cart_action_fct_stripe_onsite_return', [$this, 'handleOnsiteRedirectReturn']);

    }

    /**
     * Confirm a hosted-checkout session on the buyer's return, then redirect.
     * The gateway return URL is internal and unfiltered; the buyer's real
     * destination (fluent_cart/payment/success_url) applies only after
     * confirmation has run — so a filter that sends buyers to another page
     * can never break payment confirmation.
     */
    public function handleHostedReturn($requestData)
    {
        $transaction = OrderTransaction::query()
            ->where('uuid', sanitize_text_field(Arr::get($requestData, 'trx_hash', '')))
            ->first();

        if (!$transaction) {
            wp_redirect(home_url());
            exit;
        }

        if ($transaction->status !== Status::TRANSACTION_SUCCEEDED) {
            // Get session ID from transaction meta
            $sessionId = Arr::get($transaction->meta, 'session_id');

            if ($sessionId) {
                $this->confirmByCheckoutSession($sessionId, $transaction);
            }
        }

        // Re-query: confirmByCheckoutSession updates the row, not this instance.
        $freshTransaction = OrderTransaction::query()->find($transaction->id);
        if ($freshTransaction && $freshTransaction->status === Status::TRANSACTION_SUCCEEDED) {
            wp_redirect($this->getHostedReturnRedirectUrl($freshTransaction));
            exit;
        }

        // Not confirmed (pending, failed, or no session yet): land on the
        // receipt page, which renders the order's current state.
        wp_redirect($transaction->getReceiptPageUrl());
        exit;
    }

    /**
     * Where the buyer lands after a confirmed hosted-checkout return.
     */
    public function getHostedReturnRedirectUrl($transaction)
    {
        return $transaction->getSuccessUrl();
    }

    /**
     * Confirm an onsite payment on the buyer's return from a 3DS redirect.
     *
     * Onsite confirms with `redirect: 'if_required'`, so the challenge normally
     * renders inline and the page never navigates. Some issuers force a full
     * redirect to their ACS page instead; Stripe then sends the buyer to the
     * return_url with `payment_intent` / `setup_intent` appended. Same contract
     * as the hosted return: an internal, unfiltered URL confirms first, and the
     * buyer's real destination is applied afterwards.
     */
    public function handleOnsiteRedirectReturn($requestData)
    {
        $vendorIntentId = Arr::get($requestData, 'payment_intent');
        if (!$vendorIntentId) {
            $vendorIntentId = Arr::get($requestData, 'setup_intent');
        }

        $trxHash = sanitize_text_field((string) Arr::get($requestData, 'trx_hash', ''));

        $this->confirmRedirectReturn($trxHash, $vendorIntentId);

        $transaction = OrderTransaction::query()->where('uuid', $trxHash)->first();

        if (!$transaction) {
            wp_redirect(home_url());
            exit;
        }

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            wp_redirect($this->getHostedReturnRedirectUrl($transaction));
            exit;
        }

        wp_redirect($transaction->getReceiptPageUrl());
        exit;
    }

    /**
     * Confirm an onsite payment the buyer completed through a 3DS redirect.
     *
     * Unauthenticated surface: the return URL is a plain GET the buyer's browser
     * follows, so nothing here trusts the caller. `redirect_status` is ignored
     * entirely — the intent is re-fetched for its authoritative status — and the
     * intent must both be shaped like a Stripe id and match the one we stamped on
     * the transaction the hash resolves to.
     *
     * @param string $trxHash
     * @param string $vendorIntentId
     * @return bool whether the payment was confirmed
     */
    public function confirmRedirectReturn($trxHash, $vendorIntentId)
    {
        $trxHash = sanitize_text_field((string) $trxHash);
        $vendorIntentId = sanitize_text_field((string) $vendorIntentId);

        if (!$trxHash || !preg_match('/^(pi|seti)_[a-zA-Z0-9_]+$/', $vendorIntentId)) {
            return false;
        }

        $transaction = OrderTransaction::query()->where('uuid', $trxHash)->first();
        if (!$transaction || $this->isSettledTransaction($transaction->status)) {
            return false;
        }

        if ((string) $transaction->vendor_charge_id !== $vendorIntentId) {
            return false;
        }

        if (strpos($vendorIntentId, 'seti_') === 0) {
            return !is_wp_error($this->confirmSetupIntent($vendorIntentId, $trxHash));
        }

        $intent = (new API())->getStripeObject('payment_intents/' . $vendorIntentId, [
            'expand' => ['latest_charge']
        ]);

        if (is_wp_error($intent)) {
            fluent_cart_add_log(__('Stripe Payment Intent Retrieval Failed', 'fluent-cart'), $intent->get_error_message(), 'error', [
                'module_name' => 'order',
                'module_id'   => $transaction->order_id,
            ]);
            return false;
        }

        return $this->applyIntentOutcome($transaction, $vendorIntentId, $intent);
    }

    /**
     * Record a terminal PaymentIntent outcome against its transaction.
     *
     * A failed confirm has to land as `failed`, not stay `pending`:
     * `CheckoutProcessor` bumps `payment_attempt` only for a failed transaction,
     * and without that bump the retry reuses the same idempotency seed and
     * replays Stripe's 24h-cached response for a subscription the create-guard
     * has since deleted.
     *
     * @param string $intentId
     * @param array  $intent
     * @param bool   $markFailed set false when the caller has not proven the
     *                           reporter owns this transaction
     * @return bool
     */
    protected function applyIntentOutcome(OrderTransaction $transaction, $intentId, $intent, $markFailed = true)
    {
        // Both entry points are buyer-replayable — the return URL can be revisited
        // and the failure report is a nopriv POST — and the caller's model was
        // loaded before a Stripe round-trip of hundreds of milliseconds, so a
        // refund landing inside that window has to win.
        $transaction = OrderTransaction::query()->find($transaction->id);

        if (!$transaction) {
            return false;
        }

        if ($this->isSettledTransaction($transaction->status)) {
            return $transaction->status === Status::TRANSACTION_SUCCEEDED;
        }

        $status = Arr::get($intent, 'status');
        $failure = $this->intentFailureContext($status, Arr::get($intent, 'last_payment_error', []));

        if (in_array($status, ['requires_payment_method', 'canceled'], true)) {
            if ($markFailed) {
                $this->markIntentFailed($transaction, $failure);
            }

            return false;
        }

        // The buyer can still finish this very intent, so leave the transaction
        // pending and let them — but record the stall, otherwise an abandoned
        // challenge leaves no trace anywhere until Stripe expires the intent.
        if (in_array($status, ['requires_action', 'requires_confirmation'], true)) {
            $this->logIntentOutcome(
                $transaction,
                $failure['is_auth_failure']
                    ? __('Stripe 3D Secure Authentication Not Completed', 'fluent-cart')
                    : __('Stripe Payment Not Completed', 'fluent-cart'),
                $failure['detail'],
                'warning'
            );

            return false;
        }

        $this->confirmPaymentSuccessByCharge($transaction, [
            'charge'    => Arr::get($intent, 'latest_charge', []),
            'intent_id' => $intentId
        ]);

        // `processing` and `requires_capture` reach here with a charge that has not
        // settled, and confirmPaymentSuccessByCharge leaves those pending. Reporting
        // them as confirmed would hand the buyer a receipt redirect for a payment
        // nobody has taken, so read back what actually landed.
        $settled = OrderTransaction::query()->find($transaction->id);

        return $settled && $settled->status === Status::TRANSACTION_SUCCEEDED;
    }

    /**
     * Classify a Stripe intent failure and build the line written to the log.
     *
     * Stripe reports an abandoned or rejected 3DS challenge as
     * payment_intent_authentication_failure / setup_intent_authentication_failure /
     * authentication_required. It is the single largest cause of a first attempt
     * that never completes, so it earns its own title rather than a generic
     * decline line.
     *
     * @param string $status
     * @param array  $error `last_payment_error` or `last_setup_error`
     * @return array{is_auth_failure: bool, detail: string}
     */
    protected function intentFailureContext($status, $error)
    {
        if (!is_array($error)) {
            $error = [];
        }

        $code = (string) Arr::get($error, 'code', '');
        $declineCode = (string) Arr::get($error, 'decline_code', '');

        return [
            'is_auth_failure' => strpos($code, 'authentication') !== false
                || $declineCode === 'authentication_required',
            'detail'          => sprintf(
                /* translators: 1: Stripe payment intent status, 2: Stripe error message */
                __('Stripe reported the payment intent as %1$s. %2$s', 'fluent-cart'),
                $status,
                Arr::get($error, 'message', '')
            ),
        ];
    }

    /**
     * What actually landed on the row, for the browser's failure report to read.
     * It may only re-enable checkout once the transaction is genuinely terminal,
     * and the HTTP status cannot say that — a 400 is also how "invalid request"
     * and an unfinished challenge answer.
     *
     * @param OrderTransaction|null $transaction
     * @return string
     */
    protected function reportedTransactionStatus($transaction)
    {
        if (!$transaction) {
            return '';
        }

        $fresh = OrderTransaction::query()->find($transaction->id);

        return (string) ($fresh ? $fresh->status : $transaction->status);
    }

    /**
     * Statuses downstream of a completed payment. Owned by refunds, disputes and
     * webhooks — never writable by a confirmation, which can always arrive with a
     * charge that still reads `succeeded` at Stripe.
     *
     * @return array
     */
    protected function postPaymentStatuses()
    {
        return [
            Status::TRANSACTION_REFUNDED,
            Status::TRANSACTION_DISPUTE_LOST,
        ];
    }

    /**
     * Statuses a browser-driven confirm must never rewrite. Adds the two the
     * buyer's own replays would otherwise reopen: `succeeded`, and `authorized`
     * money Stripe is holding for a later capture.
     *
     * @return array
     */
    protected function settledTransactionStatuses()
    {
        return array_merge([
            Status::TRANSACTION_SUCCEEDED,
            Status::TRANSACTION_AUTHORIZED,
        ], $this->postPaymentStatuses());
    }

    /**
     * @param string $status
     * @return bool
     */
    protected function isSettledTransaction($status)
    {
        return in_array((string) $status, $this->settledTransactionStatuses(), true);
    }

    /**
     * @param array $failure from intentFailureContext()
     * @return void
     */
    protected function markIntentFailed(OrderTransaction $transaction, $failure)
    {
        // Compare-and-set, not read-then-write: a webhook can settle the row while
        // a stale failure report is in flight, and that report must never flip a
        // captured, refunded or disputed payment to `failed`. A zero row count also
        // covers a repeat report, keeping the log entry below from doubling.
        $updated = OrderTransaction::query()
            ->where('id', $transaction->id)
            ->whereNotIn('status', $this->settledTransactionStatuses())
            ->where('status', '!=', Status::TRANSACTION_FAILED)
            ->update(['status' => Status::TRANSACTION_FAILED]);

        if (!$updated) {
            return;
        }

        $transaction->status = Status::TRANSACTION_FAILED;

        $this->logIntentOutcome(
            $transaction,
            $failure['is_auth_failure']
                ? __('Stripe 3D Secure Authentication Failed', 'fluent-cart')
                : __('Stripe Payment Failed', 'fluent-cart'),
            $failure['detail'],
            'error'
        );
    }

    /**
     * Mirror an intent outcome onto the Order and, when there is one, its Subscription.
     *
     * @param string $title
     * @param string $detail
     * @param string $level
     * @return void
     */
    protected function logIntentOutcome(OrderTransaction $transaction, $title, $detail, $level)
    {
        fluent_cart_add_log($title, $detail, $level, [
            'module_name' => 'order',
            'module_id'   => $transaction->order_id,
        ]);

        if ($transaction->subscription_id) {
            fluent_cart_add_log($title, $detail, $level, [
                'module_type' => 'FluentCart\App\Models\Subscription',
                'module_id'   => $transaction->subscription_id,
                'module_name' => 'subscription',
            ]);
        }
    }

    private function confirmByCheckoutSession($sessionId, $transaction)
    {
      
        $api = new API();

        $session = $api->getStripeObject('checkout/sessions/' . $sessionId, [
            'expand' => ['payment_intent', 'subscription.latest_invoice.payment_intent.latest_charge']
        ]);


        if (is_wp_error($session)) {
            fluent_cart_add_log(__('Stripe Session Retrieval Failed', 'fluent-cart'), $session->get_error_message(), 'error', [
                'module_name' => 'order',
                'module_id'   => $transaction->order_id,
            ]);
            if ($transaction->subscription_id) {
                $subscription = Subscription::query()->find($transaction->subscription_id);
                if ($subscription) {
                    $subscription->addLog(__('Stripe Session Retrieval Failed', 'fluent-cart'), $session->get_error_message(), 'error');
                }
            }
            return;
        }

        $paymentStatus = Arr::get($session, 'payment_status');
        $mode = Arr::get($session, 'mode');

        if ($mode === 'subscription') {
            $vendorSubscription = Arr::get($session, 'subscription');
            $vendorSubscriptionId = is_array($vendorSubscription) ? Arr::get($vendorSubscription, 'id') : $vendorSubscription;
            
            $subscription = Subscription::query()->where('id', $transaction->subscription_id)->first();
            
            if ($subscription && $vendorSubscriptionId) {
                $updateData = [
                    'vendor_subscription_id' => $vendorSubscriptionId,
                    'vendor_customer_id' => Arr::get($vendorSubscription, 'customer'),
                ];
                

                if (is_array($vendorSubscription)) {
                    if (Arr::get($vendorSubscription, 'current_period_end')) {
                        $updateData['next_billing_date'] = gmdate('Y-m-d H:i:s', (int) Arr::get($vendorSubscription, 'current_period_end'));
                    }
                    
                    if (Arr::get($vendorSubscription, 'trial_end')) {
                        $updateData['trial_ends_at'] = gmdate('Y-m-d H:i:s', (int) Arr::get($vendorSubscription, 'trial_end'));
                    }
                }
                
                $subscription->update($updateData);
            }

            $paymentIntent = null;
            $billingInfo = [];
            

            if (is_array($vendorSubscription)) {
                $paymentIntent = Arr::get($vendorSubscription, 'latest_invoice.payment_intent');
            }
            

            if (!$paymentIntent && Arr::get($session, 'invoice')) {
                $invoiceId = Arr::get($session, 'invoice');
                $invoice = $api->getStripeObject('invoices/' . $invoiceId, [
                    'expand' => ['payment_intent.latest_charge']
                ]);
                if (!is_wp_error($invoice)) {
                    $paymentIntent = Arr::get($invoice, 'payment_intent.latest_charge');
                }
            }

            if (!is_array($paymentIntent)) {
                $paymentIntent = $api->getStripeObject('payment_intents/' . $paymentIntent, [
                    'expand' => ['latest_charge']
                ]);
            }


            $charge = Arr::get($paymentIntent, 'latest_charge', []);

            if ($charge) {
                $billingInfo = $this->extractBillingInfoFromCharge($charge);
                $this->processPaymentIntentConfirmation($paymentIntent, $transaction);
            }   else {
                if ($paymentStatus === 'paid' || $transaction->total <= 0) {
                    // Try to get payment method from setup intent
                    $setupIntent = Arr::get($session, 'setup_intent');
                    if ($setupIntent) {
                        $setupIntentData = $api->getStripeObject('setup_intents/' . $setupIntent);
                        if (!is_wp_error($setupIntentData)) {
                            $paymentMethodId = Arr::get($setupIntentData, 'payment_method');
                            if ($paymentMethodId) {
                                $billingInfo = $this->getPaymentMethodDetails($paymentMethodId);
                            }
                        }
                    }
                    
                    $transaction->status = Status::TRANSACTION_SUCCEEDED;
                    $transaction->save();
                }
            }

            if ($subscription && !in_array($subscription->status, Status::getValidableSubscriptionStatuses())) {
                (new SubscriptionsManager())->confirmSubscriptionAfterChargeSucceeded($subscription, $billingInfo);
            }

            (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);

        } elseif ($mode === 'setup') {
            // Zero-payable system-subscription hosted checkout — no payment_intent
            // to confirm, just the vaulted setup_intent. confirmSetupIntent() also
            // resolves the transaction by vendor_charge_id, so a stale/mismatched
            // session for this transaction is harmless here.
            $setupIntentId = Arr::get($session, 'setup_intent');
            if ($setupIntentId) {
                $this->confirmSetupIntent($setupIntentId);
            }
        } else {
            if ($paymentStatus === 'paid') {
                $paymentIntent = Arr::get($session, 'payment_intent');
                if (is_array($paymentIntent)) {
                    $paymentIntent = $api->getStripeObject('payment_intents/' . $paymentIntent['id'], [
                        'expand' => ['latest_charge']
                    ]);
                    $this->processPaymentIntentConfirmation($paymentIntent, $transaction);
                } elseif ($paymentIntent) {
                    $intentData = $api->getStripeObject('payment_intents/' . $paymentIntent, [
                        'expand' => ['latest_charge']
                    ]);
                    if (!is_wp_error($intentData)) {
                        $this->processPaymentIntentConfirmation($intentData, $transaction);
                    }
                }
            }
        }
    }


    /**
     * Process payment intent confirmation
     */
    private function processPaymentIntentConfirmation($intent, $transaction)
    {
        $charge = Arr::get($intent, 'latest_charge', []);
        $intentId = Arr::get($intent, 'id');

        if ($charge && $intentId) {
            $this->confirmPaymentSuccessByCharge($transaction, [
                'charge'    => $charge,
                'intent_id' => $intentId
            ]);
        }
    }

    /**
     * Extract billing info from charge for subscription confirmation
     */
    private function extractBillingInfoFromCharge($charge)
    {
        $billingDetails = Arr::get($charge, 'billing_details', []);
        $paymentMethodDetails = Arr::get($charge, 'payment_method_details', []);
        
        return [
            'method'           => 'stripe',
            'vendor_method_id' => Arr::get($charge, 'payment_method', ''),
            'payment_type'     => Arr::get($paymentMethodDetails, 'type'),
            'details'          => array_filter([
                'brand'       => Arr::get($paymentMethodDetails, 'card.brand'),
                'last_4'      => Arr::get($paymentMethodDetails, 'card.last4'),
                'exp_month'   => Arr::get($paymentMethodDetails, 'card.exp_month'),
                'exp_year'    => Arr::get($paymentMethodDetails, 'card.exp_year'),
                'country'     => Arr::get($paymentMethodDetails, 'card.country'),
                'postal_code' => Arr::get($billingDetails, 'address.postal_code', ''),
                'name'        => Arr::get($billingDetails, 'name', '')
            ])
        ];
    }

    /*
        * Only for validating hosted checkout payment confirmation
        */
    public function confirmStripePayment()
    {
        $intentId = App::request()->get('intentId');
        if (empty($intentId)) {
            wp_send_json(
                [
                    'message' => __('Intent ID is required to confirm the payment.', 'fluent-cart'),
                ],
                400
            );
        }

        $intentId = sanitize_text_field($intentId);

        // in case of plan change, and first payment is 0, then setup intent will be created
        if (strpos($intentId, 'seti_') === 0) {
            $trxHash = sanitize_text_field(App::request()->get('trx_hash'));
            if (empty($trxHash)) {
                wp_send_json(['message' => __('Invalid request.', 'fluent-cart')], 400);
            }
            $result = $this->confirmSetupIntent($intentId, $trxHash);
            if (is_wp_error($result)) {
                wp_send_json(
                    [
                        'message'            => $result->get_error_message(),
                        'transaction_status' => $this->reportedTransactionStatus(
                            OrderTransaction::query()->where('uuid', $trxHash)->first()
                        ),
                    ], 400
                );
            }
            wp_send_json(
                [
                    'message' => __('Setup intent confirmed successfully. Please check your subscriptions.', 'fluent-cart'),
                ], 200
            );
        }

        $api = new API();
        $response = $api->getStripeObject('payment_intents/' . $intentId, [
            'expand' => ['latest_charge']
        ]);

        if (is_wp_error($response)) {
            wp_send_json(
                [
                    'message' => $response->get_error_message(),
                ],
                500
            );
        }

        $transaction = OrderTransaction::query()->where('vendor_charge_id', $intentId)->first();

        if (!$transaction) {
            wp_send_json(
                [
                    'message' => __('Order not found for the provided intent ID.', 'fluent-cart'),
                ],
                404
            );
        }

        // This action is nopriv and carries no nonce, so a reporter may only
        // move the transaction to `failed` when it also produced the hash we
        // handed the buyer. Confirming a success is safe either way — Stripe's
        // own status is the authority there.
        $reportedHash = sanitize_text_field((string) App::request()->get('trx_hash'));
        $ownsTransaction = $reportedHash !== '' && $reportedHash === (string) $transaction->uuid;

        if (!$this->applyIntentOutcome($transaction, $intentId, $response, $ownsTransaction)) {
            // An in-flight charge is not a decline. Telling the buyer to try again
            // invites a resubmit for money Stripe is already taking.
            if (in_array(Arr::get($response, 'status'), ['processing', 'requires_capture'], true)) {
                wp_send_json(
                    [
                        'message'            => __('Your payment is still being processed by Stripe. Please do not submit it again — we will confirm your order as soon as it settles.', 'fluent-cart'),
                        'transaction_status' => $this->reportedTransactionStatus($transaction),
                    ],
                    400
                );
            }

            wp_send_json(
                [
                    'message'            => Arr::get(
                        $response,
                        'last_payment_error.message',
                        __('The payment could not be completed. Please try again.', 'fluent-cart')
                    ),
                    'transaction_status' => $this->reportedTransactionStatus($transaction),
                ],
                400
            );
        }

        wp_send_json(
            [
                'redirect_url' => $transaction->getSuccessUrl(),
                'order'        => [
                    'uuid' => $transaction->order->uuid,
                ],
                'message'      => __('Payment confirmed successfully. Redirecting...!', 'fluent-cart')
            ], 200
        );
    }

    // make sure customer given the acknowledgement for saving the payment methods
    public function savePaymentMethodToCustomerMeta($vendorCustomer, $paymentMethodId, $order)
    {
        $fctCustomer = Customer::query()->where('id', $order->customer_id)->first();
        $metaKey = 'saved_payment_method';

        $stripeApiKey = (new StripeSettingsBase())->getApiKey();
        $api = new API();

        // Allow redisplay for the payment method
        $api->makeRequest('payment_methods/' . $paymentMethodId, ['allow_redisplay' => 'always'], $stripeApiKey, 'POST');

        // Fetch customer to get default payment method
        $customer = $api->makeRequest('customers/' . $vendorCustomer, [], $stripeApiKey, 'GET');
        $defaultPaymentMethodId = Arr::get($customer, 'invoice_settings.default_payment_method');

        $paymentMethodsResponse = $api->makeRequest(
            'customers/' . $vendorCustomer . '/payment_methods',
            [],
            $stripeApiKey,
            'GET'
        );

        $stripeMeta = [
            'customer_id'     => $vendorCustomer,
            'payment_methods' => []
        ];

        if ($paymentMethodsResponse && !is_wp_error($paymentMethodsResponse) && ($methods = Arr::get($paymentMethodsResponse, 'data', []))) {
            $seenFingerprints = [];
            foreach ($methods as $method) {

                $type = Arr::get($method, 'type');
                $pm = [
                    'id'   => Arr::get($method, 'id'),
                    'type' => $type,
                ];

                $details = Arr::get($method, $type);
                if (!is_array($details)) {
                    $details = [];
                }

                foreach (['last4', 'brand', 'exp_month', 'exp_year', 'fingerprint'] as $field) {
                    if (Arr::has($details, $field)) {
                        $pm[$field] = Arr::get($details, $field);
                    }
                }

                // Identifier for account-like methods: link.email, paypal.payer_email,
                // cashapp.cashtag — first one present labels the entry in the UI.
                foreach (['email', 'payer_email', 'cashtag'] as $field) {
                    if (Arr::get($details, $field)) {
                        $pm['email'] = Arr::get($details, $field);
                        break;
                    }
                }

                $fingerprint = Arr::get($details, 'fingerprint');

                if ($fingerprint && in_array($fingerprint, $seenFingerprints, true)) {
                    continue;
                }
                if ($fingerprint) {
                    $seenFingerprints[] = $fingerprint;
                }

                if ($defaultPaymentMethodId === Arr::get($method, 'id')) {
                    $stripeMeta['payment_methods']['default'] = $pm;
                } else {
                    $stripeMeta['payment_methods'][] = $pm;
                }
            }
        }

        $meta = $fctCustomer->getMeta($metaKey);
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta['stripe'] = $stripeMeta;

        $fctCustomer->updateMeta($metaKey, $meta);
    }

    public function confirmSetupIntent($setupIntent, $trxHash = null)
    {
        $api = new API();

        $response = $api->getStripeObject('setup_intents/' . $setupIntent);

        if (is_wp_error($response)) {
            return $response;
        }

        $transaction = OrderTransaction::query()->where('vendor_charge_id', $setupIntent)->first();

        if (!$transaction) {
            return new \WP_Error(
                'transaction_not_found',
                __('Transaction not found for the provided setup intent.', 'fluent-cart')
            );
        }

        if ($trxHash !== null && $transaction->uuid !== $trxHash) {
            return new \WP_Error('invalid_request', __('Invalid request.', 'fluent-cart'));
        }

        $setupStatus = Arr::get($response, 'status');

        if ($setupStatus !== 'succeeded') {
            // A vaulting failure carries the same idempotency consequence as a
            // charge failure: left pending, CheckoutProcessor never bumps
            // `payment_attempt`, so the retry reuses the seed and Stripe replays
            // its cached response for an intent that can no longer be confirmed.
            $failure = $this->intentFailureContext($setupStatus, Arr::get($response, 'last_setup_error', []));

            if (in_array($setupStatus, ['requires_payment_method', 'canceled'], true)) {
                $this->markIntentFailed($transaction, $failure);
            } else {
                $this->logIntentOutcome(
                    $transaction,
                    $failure['is_auth_failure']
                        ? __('Stripe 3D Secure Authentication Not Completed', 'fluent-cart')
                        : __('Stripe Payment Method Setup Not Completed', 'fluent-cart'),
                    $failure['detail'],
                    'warning'
                );
            }

            return new \WP_Error(
                'setup_intent_not_succeeded',
                __('Payment method setup is not complete. Please complete the payment method setup.', 'fluent-cart')
            );
        }

        $transaction->status = Status::TRANSACTION_PENDING;

        if ($transaction->total <= 0) {
            $transaction->status = Status::TRANSACTION_SUCCEEDED;
        }


        $transaction->vendor_charge_id = ''; // removing vendor charge id , because setup intent id is not the charge id
        $transaction->save();

        $order = Order::query()->where('id', $transaction->order_id)->first();


        $paymentMethod = Arr::get($response, 'payment_method');
        $customer = Arr::get($response, 'customer');

        $billingInfo = $this->getPaymentMethodDetails($paymentMethod);

        // attach the payment method to the customer
        if ($paymentMethod && $customer) {
            $api->createStripeObject('payment_methods/' . $paymentMethod . '/attach', [
                'customer' => $customer
            ]);

            $this->savePaymentMethodToCustomerMeta($customer, $paymentMethod, $order);
        }


        $subscription = Subscription::query()->where('id', $transaction->subscription_id)->first();

        if ($subscription) {
            if ($subscription->isSystem()) {
                // Zero-payable free-trial checkout: no vendor subscription to confirm,
                // just vault the Stripe customer + reusable payment method.
                $stripeCustomerId = Arr::get($response, 'customer', '');
                if ($stripeCustomerId && !$subscription->vendor_customer_id) {
                    $subscription->vendor_customer_id = $stripeCustomerId;
                    $subscription->save();
                }

                $vendorMethodId = Arr::get($response, 'payment_method', '');
                if ($vendorMethodId) {
                    $billingInfo['vendor_method_id'] = $vendorMethodId;
                }

                $this->maybePersistSystemVaultToken($subscription, $order, $billingInfo);
            } else {
                (new SubscriptionsManager())->confirmSubscriptionAfterChargeSucceeded($subscription, $billingInfo);
            }
        }

        (new StatusHelper($order))->syncOrderStatuses($transaction);

        // Notify that a renewal invoice has been deferred — the actual charge will fire
        // later via the gateway's subscription_cycle webhook. Gateways that capture a card
        // for a deferred renewal charge should fire this so the invoice status can be updated.
        if ($order->type === Status::ORDER_TYPE_RENEWAL) {
            do_action('fluent_cart/renewal/payment_scheduled', [
                'order'        => $order,
                'subscription' => $subscription,
            ]);
        }

    }

    /**
     * Vault the token for a system subscription, or demote to manual when the
     * initial checkout capture came back without one — mirrors PayPal's
     * Processor::maybePersistVaultToken().
     */
    private function maybePersistSystemVaultToken($subscription, $order, $billingInfo)
    {
        $vendorMethodId = Arr::get($billingInfo, 'vendor_method_id', '');
        $existing = $subscription->getMeta('active_payment_method', []) ?: [];
        // Meta has two shapes in the wild: vendor_method_id (confirmation paths) and
        // details.payment_method_id (card-update flow) — accept both, same as chargeRenewal().
        $existingMethodId = Arr::get($existing, 'vendor_method_id') ?: Arr::get($existing, 'details.payment_method_id');

        if ($vendorMethodId) {
            if ($existingMethodId === $vendorMethodId) {
                return; // already persisted (webhook/AJAX race)
            }

            $subscription->updateMeta('active_payment_method', $billingInfo);
            return;
        }

        // No token on the initial capture and none stored yet — never leave a
        // system subscription that can never be charged.
        if ($order
            && $order->type === Status::ORDER_TYPE_SUBSCRIPTION
            && !$existingMethodId
        ) {
            SystemChargeService::demoteToManual(
                $subscription,
                __('Stripe did not return a saved payment method for automatic charging.', 'fluent-cart')
            );
        }
    }

    public function getPaymentMethodDetails($methodId)
    {
        $paymentMethodDetails = (new API())->makeRequest('payment_methods/' . $methodId, [], (new StripeSettingsBase())->getApiKey(), 'GET');

        if (is_wp_error($paymentMethodDetails) || !$paymentMethodDetails) {
            $billingInfo = PaymentHelper::parsePaymentMethodDetails('stripe', ['type' => 'card']);
        } else {
            $billingInfo = PaymentHelper::parsePaymentMethodDetails('stripe', $paymentMethodDetails);
        }

        return $billingInfo;
    }


    public function syncRemoteTransaction(OrderTransaction $transaction)
    {
        $mode = $transaction->payment_mode;
        if (!$mode) {
            $mode = $transaction->order ? $transaction->order->mode : '';
        }

        $intent = (new API())->getStripeObject('payment_intents/' . $transaction->vendor_charge_id, [
            'expand' => ['latest_charge']
        ], $mode);

        if (is_wp_error($intent)) {
            return $intent;
        }

        $intentStatus = Arr::get($intent, 'status');

        if ($intentStatus === 'succeeded') {
            $chargeCurrency = strtoupper((string) Arr::get($intent, 'latest_charge.currency', ''));
            if ($chargeCurrency && $transaction->currency && strtoupper($transaction->currency) !== $chargeCurrency) {
                fluent_cart_warning_log(
                    __('Stripe Currency Mismatch On Sync', 'fluent-cart'),
                    sprintf(
                        /* translators: %1$s: expected currency, %2$s: received currency */
                        __('Charge currency mismatch detected during transaction sync. Expected: %1$s, Received: %2$s. Transaction was not confirmed.', 'fluent-cart'),
                        $transaction->currency,
                        $chargeCurrency
                    ),
                    [
                        'module_name' => 'order',
                        'module_id'   => $transaction->order_id,
                        'log_type'    => 'api'
                    ]
                );

                return new \WP_Error('currency_mismatch', __('The Stripe payment currency does not match this transaction. Please verify the payment at Stripe.', 'fluent-cart'));
            }

            $this->confirmPaymentSuccessByCharge($transaction, [
                'charge'    => Arr::get($intent, 'latest_charge', []),
                'intent_id' => Arr::get($intent, 'id'),
            ]);

            return OrderTransaction::query()->find($transaction->id);
        }

        if ($intentStatus === 'processing') {
            return new \WP_Error('still_processing', __('The payment is still processing at Stripe. Please try again later.', 'fluent-cart'));
        }

        $failureMessage = Arr::get($intent, 'last_payment_error.message');
        if (!$failureMessage) {
            $failureMessage = sprintf(
            /* translators: %1$s: Stripe payment intent status */
                __('The payment has not completed at Stripe (status: %1$s).', 'fluent-cart'),
                $intentStatus ?: 'unknown'
            );
        }

        return new \WP_Error('charge_not_completed', $failureMessage);
    }

    /**
     * Confirm payment success by charge.
     * Currently used by:
     * - fluent_cart/payments/stripe/webhook_charge_succeeded
     * -
     *
     * @param OrderTransaction $transaction
     * @param array $args
     * @param array $args ['charge'] - The charge details from Stripe.
     * @param string $args ['intent_id'] - The intent ID from Stripe.
     */
    public function confirmPaymentSuccessByCharge(OrderTransaction $transaction, $args = [])
    {
        $charge = Arr::get($args, 'charge', []);
        $intentId = Arr::get($args, 'intent_id', '');

        if (!$intentId) {
            $intentId = Arr::get($charge, 'payment_intent', '');
        }

        $order = Order::query()->where('id', $transaction->order_id)->first();

        // in race conditions between webhook and AJAX confirmation
        $transaction = OrderTransaction::query()->where('id', $transaction->id)->first();
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            if ($transaction->subscription_id) {
                $subscription = Subscription::query()->where('id', $transaction->subscription_id)->first();
                // Only automatic subs have a remote to resync; store-managed (system/manual) have none.
                if ($subscription && $subscription->vendor_subscription_id) {
                    $subscription->reSyncFromRemote();
                }
            }

            return (new StatusHelper($order))->syncOrderStatuses($transaction);
        }

        // Bail before the dispute round-trip below, which would otherwise annotate
        // a row this confirmation is not allowed to touch.
        if (in_array($transaction->status, $this->postPaymentStatuses(), true)) {
            return (new StatusHelper($order))->syncOrderStatuses($transaction);
        }

        $chargeCurrency = Arr::get($charge, 'currency', $transaction->currency);
        $status = Arr::get($charge, 'status') === 'succeeded' ? Status::TRANSACTION_SUCCEEDED : Status::TRANSACTION_PENDING;

        if ($status === Status::TRANSACTION_PENDING) {
            if (!$transaction->vendor_charge_id && !empty($intentId)) {
                $transaction->update(['vendor_charge_id' => $intentId]);
            }
            return $order; // already pending, 
        }

        $normalizedAmount = (int)Arr::get($charge, 'amount', 0);

        if ($chargeCurrency && CurrenciesHelper::isZeroDecimal($chargeCurrency)) {
            $normalizedAmount = $normalizedAmount * 100;
        }

        $transactionUpdateData = array_filter([
            'order_id'            => $order->id,
            'total'               => $normalizedAmount,
            'currency'            => $chargeCurrency,
            'status'              => $status,
            'payment_method'      => 'stripe',
            'card_last_4'         => Arr::get($charge, 'payment_method_details.card.last4', ''),
            'card_brand'          => Arr::get($charge, 'payment_method_details.card.brand', ''),
            'payment_method_type' => Arr::get($charge, 'payment_method_details.type', ''),
            'vendor_charge_id'    => $intentId,
            'payment_mode'        => Arr::isTrue($charge, 'livemode') ? 'live' : 'test'
        ]);

        if (Arr::get($charge, 'disputed', false)) {
            $transactionUpdateData['transaction_type'] = Status::TRANSACTION_TYPE_DISPUTE;
            $disputeId = Arr::get($charge, 'dispute', '');
            $reason = 'unknown';

            $retreiveDispute = (new API())->getStripeObject('disputes/' . $disputeId);

            if (!is_wp_error($retreiveDispute)) {
                $reason = Arr::get($retreiveDispute, 'reason');
            }

            $transaction->meta = array_merge($transaction->meta, [
                'dispute_id' => $disputeId,
                'dispute_reason' => $reason,
                'is_dispute_actionable' => in_array(Arr::get($retreiveDispute, 'status'), ['needs_response']),
                'is_charge_refundable' => Arr::get($retreiveDispute, 'is_charge_refundable', false)
            ]);

            fluent_cart_warning_log('Stripe charge disputed', 'This payment was disputed (' . $charge['id'] . ')', [
                'module_name' => 'order',
                'module_id' => $order->id,
                'log_type' => 'api'
            ]);
            if ($transaction->subscription_id) {
                fluent_cart_warning_log('Stripe charge disputed', 'This payment was disputed (' . $charge['id'] . ')', [
                    'module_type' => 'FluentCart\App\Models\Subscription',
                    'module_id'   => $transaction->subscription_id,
                    'module_name' => 'subscription',
                    'log_type'    => 'api'
                ]);
            }
        }

        // Stripe's charge `created` is when the money actually moved. When this
        // confirmation is the first path to mark the transaction succeeded, it
        // beats the model hook's fallback now() stamp — which for a delayed
        // webhook would be the (later) processing time, not the charge time.
        $chargeCreatedAt = (int)Arr::get($charge, 'created', 0);
        if ($chargeCreatedAt && empty($transaction->meta['settled_at'])) {
            $transaction->meta = array_merge($transaction->meta, [
                'settled_at' => DateTime::anyTimeToGmt($chargeCreatedAt)->format('Y-m-d H:i:s')
            ]);
        }

        $transaction->fill($transactionUpdateData);
        $transaction->updated_at = DateTime::gmtNow();

        // The re-read at the top of this method is a check, not a claim, and the
        // disputed branch above spends a remote round-trip inside the window it
        // leaves open. Write through a guarded UPDATE so a refund landing there
        // wins. `succeeded` and `authorized` stay writable: the first is
        // idempotent here, the second is exactly what capture moves forward.
        $dirty = $transaction->getDirty();

        if ($dirty) {
            OrderTransaction::query()
                ->where('id', $transaction->id)
                ->whereNotIn('status', $this->postPaymentStatuses())
                ->update($dirty);
        }

        // Decide on the row, not on the affected-row count — an identical replay
        // inside the same second changes nothing and still reports zero.
        $confirmed = OrderTransaction::query()->find($transaction->id);

        if (!$confirmed) {
            return $order;
        }

        // Settled behind our back: sync the order and skip the confirmation side
        // effects below — logs, subscription activation, vault persistence.
        if (in_array($confirmed->status, $this->postPaymentStatuses(), true)) {
            return (new StatusHelper($order))->syncOrderStatuses($confirmed);
        }

        $transaction = $confirmed;

        fluent_cart_add_log(__('Stripe Payment Confirmation', 'fluent-cart'), __('Payment confirmation received from Stripe. Transaction ID:', 'fluent-cart') . ' ' . $intentId,  'info', [
            'module_name' => 'order',
            'module_id'   => $order->id,
        ]);
        if ($transaction->subscription_id) {
            fluent_cart_add_log(__('Stripe Payment Confirmation', 'fluent-cart'), __('Payment confirmation received from Stripe. Transaction ID:', 'fluent-cart') . ' ' . $intentId, 'info', [
                'module_type' => 'FluentCart\App\Models\Subscription',
                'module_id'   => $transaction->subscription_id,
                'module_name' => 'subscription',
            ]);
        }

        $billingDetails = Arr::get($charge, 'billing_details', []);
        $paymentMethodDetails = Arr::get($charge, 'payment_method_details', []);
        $billingInfo = [
            'method'           => 'stripe',
            'vendor_method_id' => Arr::get($charge, 'payment_method', ''),
            'payment_type'     => Arr::get($paymentMethodDetails, 'type'),
            'details'          => array_filter([
                'brand'       => Arr::get($paymentMethodDetails, 'card.brand'),
                'last_4'      => Arr::get($paymentMethodDetails, 'card.last4'),
                'exp_month'   => Arr::get($paymentMethodDetails, 'card.exp_month'),
                'exp_year'    => Arr::get($paymentMethodDetails, 'card.exp_year'),
                'country'     => Arr::get($paymentMethodDetails, 'card.country'),
                'postal_code' => Arr::get($billingDetails, 'address.postal_code', ''),
                'name'        => Arr::get($billingDetails, 'name', '')
            ])
        ];

        if ($order->type === Status::ORDER_TYPE_RENEWAL) {

            $parentOrderId = $transaction->order->parent_id;
            if (!$parentOrderId) {
                return;
            }
            $subscription = Subscription::query()->where('id', $transaction->subscription_id)->first();

            if (!$subscription) {
                return $order; // No subscription found for this renewal order. Something is wrong.
            }

            $subscriptionArgs = [
                'status'                 => Status::SUBSCRIPTION_ACTIVE,
                'canceled_at'            => null,
                'current_payment_method' => 'stripe'
            ];

            // Only automatic subs expose a Stripe subscription to read the period end from;
            // store-managed (system/manual) advance next_billing_date via handleRenewalPaid.
            if ($subscription->vendor_subscription_id) {
                $response = (new API())->getStripeObject('subscriptions/' . $subscription->vendor_subscription_id, [], $transaction->payment_mode);
                if (!is_wp_error($response)) {
                    $nextBillingDate = Arr::get($response, 'current_period_end') ?? null;
                    if ($nextBillingDate) {
                        $subscriptionArgs['next_billing_date'] = gmdate('Y-m-d H:i:s', (int)$nextBillingDate);
                    }
                }
            }

            SubscriptionService::recordManualRenewal($subscription, $transaction, [
                'billing_info'      => $billingInfo,
                'subscription_args' => $subscriptionArgs
            ]);

        } else {
            $subscription = Subscription::query()->where('id', $transaction->subscription_id)->first();

            if ($subscription && !in_array($subscription->status, Status::getValidableSubscriptionStatuses())) {
                (new SubscriptionsManager())->confirmSubscriptionAfterChargeSucceeded($subscription, $billingInfo);
            }

            // System (auto-charged, store-billed) subscription: persist the token from
            // the first charge — the only write path for it, since
            // confirmSubscriptionAfterChargeSucceeded() early-returns without a vendor subscription.
            if ($subscription && $subscription->isSystem()) {
                $stripeCustomerId = Arr::get($charge, 'customer', '');
                if ($stripeCustomerId && !$subscription->vendor_customer_id) {
                    $subscription->vendor_customer_id = $stripeCustomerId;
                    $subscription->save();
                }

                $this->maybePersistSystemVaultToken($subscription, $order, $billingInfo);
            }

            (new StatusHelper($order))->syncOrderStatuses($transaction);
        }

        return $order;
    }

}
