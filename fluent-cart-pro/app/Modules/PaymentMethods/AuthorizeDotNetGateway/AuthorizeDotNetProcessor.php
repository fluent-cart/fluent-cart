<?php

namespace FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway;

use FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway\API\AuthorizeDotNetAPI;
use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;

class AuthorizeDotNetProcessor
{
    protected AuthorizeDotNetSettings $settings;
    protected AuthorizeDotNetAPI $api;

    public function __construct(AuthorizeDotNetSettings $settings)
    {
        $this->settings = $settings;
        $this->api = new AuthorizeDotNetAPI();
    }

    public function handleSinglePayment(PaymentInstance $paymentInstance)
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;

        $fcCustomer = $paymentInstance->order->customer;
        $billingAddress = $paymentInstance->order->billing_address;
        $shippingAddress = $paymentInstance->order->shipping_address ?? $billingAddress;

        $descriptor = $this->getRequestValue('dataDescriptor');
        $dataValue = $this->getRequestValue('dataValue');
        $paymentMethod = $this->getRequestValue('paymentMethod', 'card');


        if (!$descriptor || !$dataValue) {
            return new \WP_Error(
                'authnet_missing_token',
                __('Payment token could not be generated. Please try again.', 'fluent-cart-pro')
            );
        }

        $amount = AuthorizeDotNetHelper::formatAmount($transaction->total, $transaction->currency);
        $invoiceNumber = AuthorizeDotNetHelper::getInvoiceNumber($transaction);

        $transactionRequest = [
            'transactionType' => 'authCaptureTransaction',
            'amount'          => $amount,
            'payment'         => [
                'opaqueData' => [
                    'dataDescriptor' => $descriptor,
                    'dataValue'      => $dataValue,
                ]
            ],
            'billTo' => AuthorizeDotNetHelper::formatAddress($billingAddress, $fcCustomer, true),
            'shipTo' => AuthorizeDotNetHelper::formatAddress($shippingAddress, $fcCustomer, false),
        ];


        $refId = substr($transaction->uuid, 0, 20); 

        $data = [
            'merchantAuthentication' => $this->api->getMerchantAuthentication(),
            'refId'                  => $refId,
            'transactionRequest'     => $transactionRequest,
        ];

        $response = $this->api->createAuthorizeNetObject('createTransactionRequest', $data);

        if (is_wp_error($response)) {
            return $response;
        }


        if (!AuthorizeDotNetAPI::isSuccessResponse($response)) {
            return new \WP_Error(
                'authnet_payment_failed',
                AuthorizeDotNetAPI::extractErrorMessage($response)
            );
        }

        $transactionResponse = Arr::get($response, 'transactionResponse', []);
        $responseCode = (string) Arr::get($transactionResponse, 'responseCode');
        $vendorChargeId = Arr::get($transactionResponse, 'transId');
        $authCode = Arr::get($transactionResponse, 'authCode');

        if (!$vendorChargeId) {
            return new \WP_Error('authnet_missing_transaction', __('Unable to process payment. No transaction ID returned by Authorize.Net.', 'fluent-cart-pro'));
        }

        $details = AuthorizeDotNetHelper::extractPaymentDetails($transactionResponse, $paymentMethod);


        $meta = array_merge($transaction->meta ?? [], [
            'authnet_invoice' => $invoiceNumber,
            'authnet_auth_code' => $authCode,
            'authnet_response' => $transactionResponse,
            'authnet_ref_id' => $refId,
        ]);

        $transaction->fill(array_filter([
            'vendor_charge_id'    => $vendorChargeId,
            'payment_method_type' => $details['method'],
            'card_last_4'         => $details['last_four'],
            'card_brand'          => $details['method'] === 'card' ? $details['brand'] : null,
            'meta'                => $meta,
        ]));

        if ($responseCode === '1') {
            $transaction->status = Status::TRANSACTION_SUCCEEDED;
        } elseif ($responseCode === '4') {
            $transaction->status = Status::TRANSACTION_PENDING;
        } else {
            $transaction->status = Status::TRANSACTION_FAILED;
        }

        $transaction->save();


        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            (new StatusHelper($order))->syncOrderStatuses($transaction);
        } elseif ($transaction->status === Status::TRANSACTION_PENDING) {
            AuthorizeDotNetHelper::markTransactionPending(
                $transaction,
                $transactionResponse,
                __('Transaction is held for review by Authorize.Net.', 'fluent-cart-pro')
            );
        } else {
            AuthorizeDotNetHelper::markTransactionFailed(
                $transaction,
                __('Authorize.Net declined the transaction.', 'fluent-cart-pro'),
                $transactionResponse
            );

            return new \WP_Error(
                'authnet_declined',
                AuthorizeDotNetAPI::extractErrorMessage($response, __('Authorize.Net declined the transaction.', 'fluent-cart-pro'))
            );
        }

        wp_send_json([
            'status'       => 'success',
            'redirect_to' => $transaction->getReceiptPageUrl(),
        ]);
    }

    public function handleSubscription(PaymentInstance $paymentInstance)
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $subscriptionModel = $paymentInstance->subscription;
        
        $descriptor = $this->getRequestValue('dataDescriptor');
        $dataValue = $this->getRequestValue('dataValue');

        $billTo = AuthorizeDotNetHelper::formatAddress($order->billing_address, $order->customer, true);
        $shipTo = AuthorizeDotNetHelper::formatAddress($order->shipping_address, $order->customer, false);

        if (!$descriptor || !$dataValue) {
            return new \WP_Error(
                'authnet_missing_token',
                __('Payment token could not be generated. Please try again.', 'fluent-cart-pro')
            );
        }

        $payment = [];
        $profile = [];

        // handle first payment, as in authorizedotnet subscription's first transaction do not happens immediately, more details here: https://developer.authorize.net/api/reference/features/recurring-billing.html.
        if ($transaction->total > 0) {
            $firstPaymentResult = $this->handleFirstPayment($transaction, $order->customer, $billTo, $shipTo, $descriptor, $dataValue);

            if (is_wp_error($firstPaymentResult)) {
                $errorMessage = $firstPaymentResult->get_error_message();
                if (str_contains(strtolower($errorMessage), 'A duplicate transaction has been submitted')) {
                    return new \WP_Error('authnet_duplicate_transaction', __('You have already paid for this order.', 'fluent-cart-pro'));
                }
                return $firstPaymentResult;
            }

            if (!Arr::get($firstPaymentResult, 'customer_profile')) {
                $reason = Arr::get($firstPaymentResult, 'reason');
                if ($reason === 'transaction_held_for_review') {

                    fluent_cart_warning_log('Authorize.Net Subscription', 'Transaction is held for review by Authorize.Net. Once the transaction is approved, subscription will be activated.', [
                        'module_name' => 'order',
                        'module_id'   => $order->id,
                    ]);

                    wp_send_json([
                        'status'       => 'success',
                        'redirect_to' => $transaction->getReceiptPageUrl(),
                    ]);
                }
            }


            $profile = [
                'customerProfileId' => Arr::get($firstPaymentResult, 'customer_profile.customerProfileId'),
                'customerPaymentProfileId' => Arr::get($firstPaymentResult, 'customer_profile.customerPaymentProfileIdList.0'),
            ];

        } else {
            $firstPaymentResult = [];
            $transaction->status = Status::TRANSACTION_SUCCEEDED;
            $transaction->save();

            $payment = [
                'opaqueData' => [
                    'dataDescriptor' => $descriptor,
                    'dataValue'      => $dataValue,
                ]
            ];
        }
        // first payment done

        // now subscribe
        $interval = $subscriptionModel->billing_interval;
        $intervalMap = [
            'daily'   => 'days',
            'weekly'  => 'days',
            'monthly' => 'months',
            'yearly'  => 'months',
            'quarterly' => 'months',
            'half_yearly' => 'months',
        ];

        $intervalCount = 1;

        if ($interval === 'weekly') {
            $intervalCount = 7;
        } elseif ($interval === 'yearly') {
            $intervalCount = 12; 
        } elseif ($interval === 'quarterly') {
            $intervalCount = 3;
        } elseif ($interval === 'half_yearly') {
            $intervalCount = 6;
        }

        $intervalInDays = PaymentHelper::getIntervalDays($interval);

        $intervalUnit = $intervalMap[$interval] ?? 'months';

        if ($order->type == 'renewal') {

           $amount = $subscriptionModel->getCurrentRenewalAmount();
           $trialDays = $subscriptionModel->getReactivationTrialDays();
           $totalOccurrences = $subscriptionModel->getRequiredBillTimes();

           if ($totalOccurrences < 0) {
             return new \WP_Error('authnet_invalid_bill_times', __('Subscription is already completed.', 'fluent-cart-pro'));
           }

           if ($totalOccurrences == 0) {
                $totalOccurrences = 9999;
           }

            $trialOccurrences = 1;
            $trialAmount = '0.00';

            if (!$trialDays) {
                $trialDays = $intervalInDays;
            }

            $startDate = DateTime::now();
            $startDate->addDays($trialDays);
            $startDateStr = $startDate->format('Y-m-d');

            $billingPeriod = apply_filters('fluent_cart/subscription_billing_period', [
                    'interval_frequency' => $intervalCount,
                    'interval_unit'   => $intervalUnit,
                ],
                [
                    'subscription_interval' => $subscriptionModel->billing_interval,
                    'payment_method' => 'authorize_dot_net',
                ]
            );

            // check minimum 7 days
            if (Arr::get($billingPeriod, 'interval_frequency') < 7 && Arr::get($billingPeriod, 'interval_unit') === 'days') {
                return new \WP_Error('authnet_minimum_days', __('Minimum 7 days is required for subscription. Please change the subscription interval to at weekly or greater', 'fluent-cart-pro'));
            }

            $subscriptionData = [
                'name' => mb_substr($subscriptionModel->item_name, 0, 50) ?: 'Subscription',
                'paymentSchedule' => [
                    'interval' => [
                        'length' => Arr::get($billingPeriod, 'interval_frequency'),
                        'unit'   => Arr::get( $billingPeriod, 'interval_unit'),
                    ],
                    'startDate'        => $startDateStr,
                    'totalOccurrences' => $totalOccurrences,
                    'trialOccurrences' => $trialOccurrences,
                ],
                'amount'      => $amount,
                'trialAmount' => $trialAmount,
                'payment' => $payment,
                'billTo' => $billTo,
                'shipTo' => $shipTo,
            ];

            $refId = substr($subscriptionModel->uuid, 0, 20);

            if ($transaction->total > 0) {
                $subscriptionData['profile'] = $profile;
                unset($subscriptionData['billTo']);
                unset($subscriptionData['payment']);
            }

            $data = [
                'merchantAuthentication' => $this->api->getMerchantAuthentication(),
                'refId'                  => $refId,
                'subscription'           => $subscriptionData,
            ];


        } else {         
          
            // Calculate total occurrences
            $totalOccurrences = 9999; // Unlimited by default

            if ($subscriptionModel->bill_times) {
                $totalOccurrences = $subscriptionModel->bill_times;
            }


            // as first payment done, subscription start date(first billing date) will be from second billing date
        
            $trialDays = 0;
            $trialOccurrences = 1;
            $trialAmount = '0.00';
            if (!$subscriptionModel->trial_days) {
                $trialDays = $intervalInDays;
            } else {
                $trialDays = $subscriptionModel->trial_days;
            }


            $startDate = DateTime::now();
            $startDate->addDays($trialDays);
            $startDateStr = $startDate->format('Y-m-d');


            // Format recurring amount
            $amount = AuthorizeDotNetHelper::formatAmount($subscriptionModel->recurring_total, $transaction->currency);


            $billingPeriod = apply_filters('fluent_cart/subscription_billing_period', [
                    'interval_frequency' => $intervalCount,
                    'interval_unit'   => $intervalUnit,
                ],
                [
                    'subscription_interval' => $subscriptionModel->billing_interval,
                    'payment_method' => 'authorize_dot_net',
                ]
            );
            

            // check minimum 7 days
            if (Arr::get($billingPeriod, 'interval_frequency') < 7 && Arr::get($billingPeriod, 'interval_unit') === 'days') {
                return new \WP_Error('authnet_minimum_days', __('Minimum 7 days is required for subscription. Please change the subscription interval to at weekly or greater', 'fluent-cart-pro'));
            }

            $subscriptionData = [
                'name' => mb_substr($subscriptionModel->item_name, 0, 50) ?: 'Subscription',
                'paymentSchedule' => [
                    'interval' => [
                        'length' => Arr::get($billingPeriod, 'interval_frequency'),
                        'unit'   => Arr::get( $billingPeriod, 'interval_unit'),
                    ],
                    'startDate'        => $startDateStr,
                    'totalOccurrences' => $totalOccurrences,
                    'trialOccurrences' => $trialOccurrences,
                ],
                'amount'      => $amount,
                'trialAmount' => $trialAmount,
                'payment' => $payment,
                'billTo' => $billTo,
                'shipTo' => $shipTo,
            ];

            if ($transaction->total > 0) {
                $subscriptionData['profile'] = $profile;
                unset($subscriptionData['billTo']);
                unset($subscriptionData['payment']);
            }

            $refId = substr($subscriptionModel->uuid, 0, 20);

            $data = [
                'merchantAuthentication' => $this->api->getMerchantAuthentication(),
                'refId'                  => $refId,
                'subscription'           => $subscriptionData,
            ];

        }

       
        $response = $this->api->createAuthorizeNetObject('ARBCreateSubscriptionRequest', $data);

        if (is_wp_error($response)) {
            // check if error message contains 'You have submitted a duplicate of Subscription'
            $errorMessage = $response->get_error_message();
            if (str_contains(strtolower($errorMessage), 'You have submitted a duplicate of Subscription')) {
                return new \WP_Error('authnet_duplicate_subscription', __('You have already subscribed to this plan.', 'fluent-cart-pro'));
            }   
            return $response;
        }


        if (!AuthorizeDotNetAPI::isSuccessResponse($response)) {
            return new \WP_Error(
                'authnet_payment_failed',
                AuthorizeDotNetAPI::extractErrorMessage($response)
            );
        }

        $vendorSubscriptionId = Arr::get($response, 'subscriptionId');
        $vendorCustomerId = Arr::get($response, 'profile.customerProfileId');

        if (!$vendorSubscriptionId) {
            return new \WP_Error('authnet_missing_subscription_id', __('Unable to process subscription. No subscription ID returned by Authorize.Net.', 'fluent-cart-pro'));
        }
    
        $updateData = [
            'vendor_subscription_id' => $vendorSubscriptionId,
            'vendor_customer_id'    => $vendorCustomerId,
            'status'                => Status::SUBSCRIPTION_ACTIVE,
            'current_payment_method' => 'authorize_dot_net',
            'next_billing_date'      => DateTime::anyTimeToGmt($startDateStr)->format('Y-m-d H:i:s'),
            'vendor_response'        => json_encode($response),
        ];

        $subscriptionModel->update($updateData);


        $activePaymentMethod = [
            'customer_profile_id' => Arr::get($response, 'profile.customerProfileId'),
            'payment_profile_id' => Arr::get($response, 'profile.customerPaymentProfileId'),
        ];

        if (!empty($firstPaymentResult['billing_info'])) {
            $activePaymentMethod['type'] = Arr::get($firstPaymentResult, 'billing_info.type');
            $activePaymentMethod['last_four'] = Arr::get($firstPaymentResult, 'billing_info.last_four');
            $activePaymentMethod['brand'] = Arr::get($firstPaymentResult, 'billing_info.brand');
        }

        // update 'active_payment_method' meta
        $subscriptionModel->updateMeta('active_payment_method', $activePaymentMethod);


        (new SubscriptionActivated($subscriptionModel, $order, $order->customer))->dispatch();

        (new StatusHelper($order))->syncOrderStatuses($transaction);

        wp_send_json([
            'status'       => 'success',
            'redirect_to' => $transaction->getReceiptPageUrl(),
        ]);
    }

    protected function getRequestValue(string $key, $default = null)
    {
        $value = App::request()->get($key);
        if (is_array($value)) {
            return $default;
        }

        $value = is_string($value) ? sanitize_text_field(wp_unslash($value)) : $value;
        return $value !== null && $value !== '' ? $value : $default;
    }

    protected function handleFirstPayment($transaction, $customer, $billTo, $shipTo, $descriptor, $dataValue)
    {
        $amount = AuthorizeDotNetHelper::formatAmount($transaction->total, $transaction->currency);

        $transactionRequest = [
            'transactionType' => 'authCaptureTransaction',
            'amount'          => $amount,
            'payment'         => [
                'opaqueData' => [
                    'dataDescriptor' => $descriptor,
                    'dataValue'      => $dataValue,
                ]
            ],
            'customer' => [
                'type' => 'individual',
                'email' => $customer->email,
            ],
            'billTo' => $billTo,
            'shipTo' => $shipTo,
        ];


        $refId = substr($transaction->uuid, 0, 20); 

        $firstPaymentData = [
            'merchantAuthentication' => $this->api->getMerchantAuthentication(),
            'refId'                  => $refId,
            'transactionRequest'     => $transactionRequest,
        ];


        $firstPayment = $this->api->createAuthorizeNetObject('createTransactionRequest', $firstPaymentData);


        if (is_wp_error($firstPayment)) {
            $errorMessage = $firstPayment->get_error_message();
            if (str_contains(strtolower($errorMessage), 'You have submitted a duplicate of Transaction')) {
                return new \WP_Error('authnet_duplicate_transaction', __('You have already paid for this order.', 'fluent-cart-pro'));
            }
            return $firstPayment;
        }

        if (!AuthorizeDotNetAPI::isSuccessResponse($firstPayment)) {
            return new \WP_Error('authnet_payment_failed', AuthorizeDotNetAPI::extractErrorMessage($firstPayment));
        }

        $transactionResponse = Arr::get($firstPayment, 'transactionResponse', []);
        $responseCode = (string) Arr::get($transactionResponse, 'responseCode');
        $vendorChargeId = Arr::get($transactionResponse, 'transId');
        $authCode = Arr::get($transactionResponse, 'authCode');

        if (!$vendorChargeId) {
            return new \WP_Error('authnet_missing_transaction', __('Unable to process payment. No transaction ID returned by Authorize.Net.', 'fluent-cart-pro'));
        }

        $details = AuthorizeDotNetHelper::extractPaymentDetails($transactionResponse, 'card');


        $meta = array_merge($transaction->meta ?? [], [
            'authnet_auth_code' => $authCode,
            'authnet_response' => $transactionResponse,
            'authnet_ref_id' => $refId,
        ]);

        if ($responseCode === '1') {
            $transaction->status = Status::TRANSACTION_SUCCEEDED;
        } elseif ($responseCode === '4') {
            $transaction->status = Status::TRANSACTION_PENDING;
        } else {
            $transaction->status = Status::TRANSACTION_FAILED;
        }
    

        $transaction->fill(array_filter([
            'vendor_charge_id'    => $vendorChargeId,
            'payment_method_type' => Arr::get($details, 'method', 'card'),
            'card_last_4'         => Arr::get($details, 'last_four'),
            'card_brand'          => Arr::get($details, 'brand', null),
            'meta'                => $meta,
        ]));


        $transaction->save();

        // if response code 4 , then transaction is in held for review, so we can't create a customer profile
        if ($responseCode === '4') {
            return [
                'reason' => 'transaction_held_for_review',
                'customer_profile' => null,
                'billing_info' => [
                    'type' => Arr::get($details, 'method', 'card'),
                    'last_four' => Arr::get($details, 'last_four'),
                    'brand' => Arr::get($details, 'brand'),
                ],
            ];
        }

        // create a customer profile if not exists

        $customerProfileData = [
            'merchantAuthentication' => $this->api->getMerchantAuthentication(),
            'transId' => $vendorChargeId,
        ];

        $customerProfile = $this->api->createAuthorizeNetObject('createCustomerProfileFromTransactionRequest', $customerProfileData);

        if (is_wp_error($customerProfile)) {
            return $customerProfile;
        }


        return [
            'customer_profile' => $customerProfile,
            'billing_info' => [
                'type' => Arr::get($details, 'method', 'card'),
                'last_four' => Arr::get($details, 'last_four'),
                'brand' => Arr::get($details, 'brand')
            ],
        ];
    }

}
