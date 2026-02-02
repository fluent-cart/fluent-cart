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
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;

class Confirmations
{
    public function init()
    {
        add_action('wp_ajax_nopriv_fluent_cart_confirm_stripe_payment', [$this, 'confirmStripePayment']);
        add_action('wp_ajax_fluent_cart_confirm_stripe_payment', [$this, 'confirmStripePayment']);

        add_filter('fluent_cart_form_disable_stripe_connect', function ($value, $args) {
            if (defined('FCT_STRIPE_LIVE_PUBLIC_KEY') || defined('FCT_STRIPE_TEST_PUBLIC_KEY')) {
                return true;
            }

            return $value;
        }, 10, 2);

    
       if (isset($_REQUEST['fct_stripe_hosted']) && isset($_REQUEST['trx_hash'])) {
           $transaction = OrderTransaction::query()->where('uuid', sanitize_text_field(App::request()->get('trx_hash')))->first();
           if (!$transaction || $transaction->status === Status::TRANSACTION_SUCCEEDED) {
               return;
           }

            // Get session ID from transaction meta
            $sessionId = Arr::get($transaction->meta, 'session_id');
            
            if ($sessionId) {
                $this->confirmByCheckoutSession($sessionId, $transaction);
            } else {
                return;
            }
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
            $this->confirmSetupIntent($intentId);
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

        $this->confirmPaymentSuccessByCharge($transaction, [
            'charge'    => Arr::get($response, 'latest_charge', []),
            'intent_id' => $intentId
        ]);

        wp_send_json(
            [
                'redirect_url' => $transaction->getReceiptPageUrl(),
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

                $fingerprint = null;
                switch ($type) {
                    case 'card':
                        $pm['last4'] = Arr::get($method, 'card.last4');
                        $pm['brand'] = Arr::get($method, 'card.brand');
                        $pm['exp_month'] = Arr::get($method, 'card.exp_month');
                        $pm['exp_year'] = Arr::get($method, 'card.exp_year');
                        $pm['fingerprint'] = Arr::get($method, 'card.fingerprint');
                        $fingerprint = $pm['fingerprint'];
                        break;
//                   case 'sepa_debit':
//                       $pm['last4'] = Arr::get($method, 'sepa_debit.last4');
//                       $fingerprint = Arr::get($method, 'sepa_debit.fingerprint');
//                       break;
//                   case 'ach_debit':
//                       $pm['last4'] = Arr::get($method, 'ach_debit.last4');
//                       $fingerprint = Arr::get($method, 'ach_debit.fingerprint');
//                       break;
//                   case 'ach_credit_transfer':
//                       $pm['account_number'] = Arr::get($method, 'ach_credit_transfer.account_number');
//                       $fingerprint = Arr::get($method, 'ach_credit_transfer.fingerprint');
//                       break;
//                   case 'us_bank_account':
//                       $pm['account_number'] = Arr::get($method, 'us_bank_account.account_number');
//                       $fingerprint = Arr::get($method, 'us_bank_account.fingerprint');
//                       break;
//                   case 'bacs_debit':
//                       $pm['account_number'] = Arr::get($method, 'bacs_debit.account_number');
//                       $fingerprint = Arr::get($method, 'bacs_debit.fingerprint');
//                       break;
                    default:
                        break;
                }

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
        $meta['stripe'] = $stripeMeta;

        $fctCustomer->updateMeta($metaKey, [
            'stripe' => $stripeMeta
        ]);
    }

    public function confirmSetupIntent($setupIntent)
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
            (new SubscriptionsManager())->confirmSubscriptionAfterChargeSucceeded($subscription, $billingInfo);
        }

        (new StatusHelper($order))->syncOrderStatuses($transaction);

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

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return $order; // already confirmed
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
        }

        $transaction->fill($transactionUpdateData);
        $transaction->save();

        fluent_cart_add_log(__('Stripe Payment Confirmation', 'fluent-cart'), __('Payment confirmation received from Stripe. Transaction ID:', 'fluent-cart') . ' ' . $intentId,  'info', [
            'module_name' => 'order',
            'module_id'   => $order->id,
        ]);

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

            $api = new API();
            $response = $api->getStripeObject('subscriptions/' . $subscription->vendor_subscription_id, [], $transaction->payment_mode);

            $subscriptionArgs = [
                'status'                 => Status::SUBSCRIPTION_ACTIVE,
                'canceled_at'            => null,
                'current_payment_method' => 'stripe'
            ];

            if (!is_wp_error($response)) {
                $nextBillingDate = Arr::get($response, 'current_period_end') ?? null;
                if ($nextBillingDate) {
                    $subscriptionArgs['next_billing_date'] = gmdate('Y-m-d H:i:s', (int)$nextBillingDate);
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

            (new StatusHelper($order))->syncOrderStatuses($transaction);
        }

        return $order;
    }

}

