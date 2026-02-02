<?php

namespace FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway\API\AuthorizeDotNetAPI;
use FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway\AuthorizeDotNetHelper;
use FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway\AuthorizeDotNetSettings;

class AuthorizeDotNetSubscriptions extends AbstractSubscriptionModule
{
    protected AuthorizeDotNetAPI $api;
    protected AuthorizeDotNetSettings $settings;

    public function __construct(AuthorizeDotNetSettings $settings)
    {
        $this->settings = $settings;
        $this->api = new AuthorizeDotNetAPI();
    }

    public function cancel($vendorSubscriptionId, $args = [])
    {
        $data = [
            'merchantAuthentication' => $this->api->getMerchantAuthentication(),
            'subscriptionId'         => $vendorSubscriptionId,
        ];

        $response = $this->api->createAuthorizeNetObject('ARBCancelSubscriptionRequest', $data);

        if (is_wp_error($response)) {
            return $response;
        }

        if (!AuthorizeDotNetAPI::isSuccessResponse($response)) {
            return new \WP_Error(
                'cancel_failed',
                AuthorizeDotNetAPI::extractErrorMessage($response, __('Failed to cancel subscription.', 'fluent-cart-pro'))
            );
        }

        return [
            'status' => Status::SUBSCRIPTION_CANCELED,
            'canceled_at' => DateTime::now()->format('Y-m-d H:i:s')
        ];
    }

    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)
    {
        if ($subscriptionModel->current_payment_method !== 'authorize_dot_net') {
            return new \WP_Error(
                'invalid_payment_method',
                __('This subscription is not using Authorize.Net as payment method.', 'fluent-cart-pro')
            );
        }

        $order = $subscriptionModel->order;
        $vendorSubscriptionId = $subscriptionModel->vendor_subscription_id;

        if (!$vendorSubscriptionId) {
            return new \WP_Error(
                'invalid_subscription',
                __('Invalid vendor subscription ID.', 'fluent-cart-pro')
            );
        }

        // Get subscription details with transactions
        $data = [
            'merchantAuthentication' => $this->api->getMerchantAuthentication(),
            'subscriptionId'         => $vendorSubscriptionId,
            'includeTransactions'    => true,
        ];

        $response = $this->api->getAuthorizeNetObject('ARBGetSubscriptionRequest', $data);

        if (is_wp_error($response)) {
            return $response;
        }

        if (!AuthorizeDotNetAPI::isSuccessResponse($response)) {
            return new \WP_Error(
                'sync_failed',
                AuthorizeDotNetAPI::extractErrorMessage($response, __('Failed to sync subscription.', 'fluent-cart-pro'))
            );
        }

        $subscriptionData = Arr::get($response, 'subscription', []);
        $subscriptionUpdateData = $this->getSubscriptionUpdateData($subscriptionData, $subscriptionModel);

        // Get transactions from subscription
        $arbTransactions = Arr::get($subscriptionData, 'arbTransactions', []);
        $subscriptionAmount = Arr::get($subscriptionData, 'amount', '0.00');
        
        $newPayment = false;

        // Process each transaction
        foreach ($arbTransactions as $arbTransaction) {
            $transactionId = Arr::get($arbTransaction, 'transId');
            $paymentNumber = Arr::get($arbTransaction, 'payNum');
            $responseCode = Arr::get($arbTransaction, 'responseCode', '1');

            if (!$transactionId) {
                continue;
            }

            // Check if transaction already exists
            $transaction = OrderTransaction::query()
                ->where('vendor_charge_id', $transactionId)
                ->first();

            if (!$transaction) {
                $transaction = OrderTransaction::query()
                    ->where('subscription_id', $subscriptionModel->id)
                    ->where('vendor_charge_id', '')
                    ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                    ->first();

                if ($transaction) {
                    // Update existing transaction
                    $amount = $this->convertAmountToCents($subscriptionAmount, $order->currency);
                    $transactionStatus = $this->getTransactionStatusFromResponseCode($responseCode);

                    $transaction->update([
                        'vendor_charge_id' => $transactionId,
                        'status'           => $transactionStatus,
                        'total'            => $amount,
                        'meta'              => array_merge($transaction->meta ?? [], [
                            'authnet_transaction' => $arbTransaction,
                            'payment_number'      => $paymentNumber,
                        ]),
                    ]);

                    (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
                    continue;
                }

                // Create new transaction using SubscriptionService
                $amount = $this->convertAmountToCents($subscriptionAmount, $order->currency);
                $transactionStatus = $this->getTransactionStatusFromResponseCode($responseCode);

                $transactionData = [
                    'order_id'         => $order->id,
                    'subscription_id'  => $subscriptionModel->id,
                    'vendor_charge_id' => $transactionId,
                    'status'           => $transactionStatus,
                    'total'            => $amount,
                    'currency'         => $order->currency,
                    'payment_method'   => 'authorize_dot_net',
                    'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
                    'created_at'       => Arr::get($arbTransaction, 'submitTimeUTC') 
                        ? DateTime::anyTimeToGmt(Arr::get($arbTransaction, 'submitTimeUTC'))->format('Y-m-d H:i:s')
                        : DateTime::now()->format('Y-m-d H:i:s'),
                    'meta'             => [
                        'authnet_transaction' => $arbTransaction,
                        'payment_number'      => $paymentNumber,
                    ],
                ];

                $newPayment = true;
                SubscriptionService::recordRenewalPayment($transactionData, $subscriptionModel, $subscriptionUpdateData);
            } elseif ($transaction->status !== Status::TRANSACTION_SUCCEEDED) {
                // Update existing transaction if status has changed
                $transactionStatus = $this->getTransactionStatusFromResponseCode($responseCode);
                $transaction->update([
                    'status' => $transactionStatus,
                ]);

                (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
            }
        }

        // Update subscription data
        if (!$newPayment) {
            $subscriptionModel = SubscriptionService::syncSubscriptionStates($subscriptionModel, $subscriptionUpdateData);
        } else {
            $subscriptionModel = Subscription::query()->find($subscriptionModel->id);
        }

        return $subscriptionModel;
    }

    protected function getSubscriptionUpdateData($subscriptionData, $subscriptionModel)
    {
        $status = Arr::get($subscriptionData, 'status');
        $fctStatus = AuthorizeDotNetHelper::translateToFluentCartStatus($status);

        $subscriptionUpdateData = array_filter([
            'current_payment_method' => 'authorize_dot_net',
            'status'                 => $fctStatus,
        ]);

        // Handle cancellation
        if ($fctStatus === Status::SUBSCRIPTION_CANCELED) {
            if (!$subscriptionModel->canceled_at) {
                $subscriptionUpdateData['canceled_at'] = DateTime::now()->format('Y-m-d H:i:s');
            }
        }

        // Handle next billing date
        $nextPaymentDate = Arr::get($subscriptionData, 'paymentSchedule.nextPaymentDate');
        if ($nextPaymentDate) {
            $subscriptionUpdateData['next_billing_date'] = DateTime::anyTimeToGmt($nextPaymentDate)->format('Y-m-d H:i:s');
        }

        return $subscriptionUpdateData;
    }

    protected function convertAmountToCents($amount, $currency)
    {
        $zeroDecimalCurrencies = ['JPY', 'KRW', 'VND', 'CLP', 'TWD'];
        
        if (in_array(strtoupper($currency), $zeroDecimalCurrencies)) {
            return (int) $amount;
        }

        return (int) (floatval($amount) * 100);
    }

    protected function getTransactionStatusFromResponseCode($responseCode)
    {
        // Response codes: 1 = Approved, 2 = Declined, 3 = Error, 4 = Held for Review
        $responseCode = (string) $responseCode;
        
        if ($responseCode === '1') {
            return Status::TRANSACTION_SUCCEEDED;
        } elseif ($responseCode === '4') {
            return Status::TRANSACTION_PENDING;
        } else {
            return Status::TRANSACTION_FAILED;
        }
    }

    public function reactivateSubscription($data, $subscriptionId)
    {
        $subscription = Subscription::query()->find($subscriptionId);
        
        if (!$subscription || $subscription->current_payment_method !== 'authorize_dot_net') {
            return new \WP_Error(
                'invalid_subscription',
                __('Invalid subscription or payment method.', 'fluent-cart-pro')
            );
        }

        $vendorSubscriptionId = $subscription->vendor_subscription_id;
        
        if (!$vendorSubscriptionId) {
            return new \WP_Error(
                'invalid_subscription',
                __('Invalid vendor subscription ID.', 'fluent-cart-pro')
            );
        }

        // Authorize.Net doesn't have a direct reactivate API
        // We need to update the subscription status
        $data = [
            'merchantAuthentication' => $this->api->getMerchantAuthentication(),
            'subscriptionId'         => $vendorSubscriptionId,
            'subscription'           => [
                'status' => 'active',
            ],
        ];

        $response = $this->api->createAuthorizeNetObject('ARBUpdateSubscriptionRequest', $data);

        if (is_wp_error($response)) {
            return $response;
        }

        if (!AuthorizeDotNetAPI::isSuccessResponse($response)) {
            return new \WP_Error(
                'reactivate_failed',
                AuthorizeDotNetAPI::extractErrorMessage($response, __('Failed to reactivate subscription.', 'fluent-cart-pro'))
            );
        }

        return true;
    }

    public function createAndActivateSubscriptionOnTransactionApprove($subscriptionModel, $transactionModel)
    {

        $order = $subscriptionModel->order;
        $vendorChargeId = $transactionModel->vendor_charge_id;

        $shipTo = AuthorizeDotNetHelper::formatAddress($order->shipping_address, $order->customer, false);

        $customerProfileData = [
            'merchantAuthentication' => $this->api->getMerchantAuthentication(),
            'transId' => $vendorChargeId,
        ];

        $customerProfile = $this->api->createAuthorizeNetObject('createCustomerProfileFromTransactionRequest', $customerProfileData);

        if (is_wp_error($customerProfile)) {
            fluent_cart_error_log('Authorize.Net Subscription', 'Failed to create customer profile from transaction. Error: ' . $customerProfile->get_error_message(), [
                'module_name' => 'order',
                'module_id'   => $order->id,
                'error'       => $customerProfile->get_error_message(),
            ]);
            return false;
        }

        $profile = [
            'customerProfileId' => Arr::get($customerProfile, 'customerProfileId'),
            'customerPaymentProfileId' => Arr::get($customerProfile, 'customerPaymentProfileIdList.0'),
        ];

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

        // first payment done, subscription start date(first billing date) will be from second billing date or after default trial days

        if ($order->type == 'renewal') {

           $amount = $subscriptionModel->getCurrentRenewalAmount();
           $trialDays = $subscriptionModel->getReactivationTrialDays();
           $totalOccurrences = $subscriptionModel->getRequiredBillTimes();

           if ($totalOccurrences < 0) {
            fluent_cart_error_log('Authorize.Net Subscription', 'Subscription is already completed.', [
                'module_name' => 'order',
                'module_id'   => $order->id,
                'error'       => 'Subscription is already completed.',
            ]);
            return false;
           }

           if ($totalOccurrences == 0) {
                $totalOccurrences = 9999;
           }

            $trialOccurrences = 1;
            $trialAmount = '0.00';

            if (empty($trialDays)) {
                $trialDays = $intervalInDays;
            }

            $startDate = DateTime::parse(DateTime::anyTimeToGmt($transactionModel->created_at))->addDays($trialDays);
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
                fluent_cart_error_log('Authorize.Net Subscription', 'Minimum 7 days is required for subscription. Please change the subscription interval to at weekly or greater', [
                    'module_name' => 'order',
                    'module_id'   => $order->id,
                    'error'       => 'Minimum 7 days is required for subscription. Please change the subscription interval to at weekly or greater',
                ]);
                return false;
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
                'profile' => $profile,
                'shipTo' => $shipTo,
            ];

            $refId = substr($subscriptionModel->uuid, 0, 20);


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

        
            $trialDays = 0;
            $trialOccurrences = 1;
            $trialAmount = '0.00';
            if (empty($subscriptionModel->trial_days)) {
                $trialDays = $intervalInDays;
            } else {
                $trialDays = $subscriptionModel->trial_days;
            }


            $startDate = DateTime::parse(DateTime::anyTimeToGmt($transactionModel->created_at))->addDays($trialDays);
            $startDateStr = $startDate->format('Y-m-d');

            // Format recurring amount
            $amount = AuthorizeDotNetHelper::formatAmount($subscriptionModel->recurring_total, $transactionModel->currency);


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

                fluent_cart_error_log('Authorize.Net Subscription', 'Minimum 7 days is required for subscription. Please change the subscription interval to at weekly or greater', [
                    'module_name' => 'order',
                    'module_id'   => $order->id,
                    'error'       => 'Minimum 7 days is required for subscription. Please change the subscription interval to at weekly or greater',
                ]);
                return false;
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
                'profile' => $profile,
                'shipTo' => $shipTo,
            ];

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
            fluent_cart_error_log('Authorize.Net Subscription', 'Failed to create subscription. Error: ' . $errorMessage, [
                'module_name' => 'order',
                'module_id'   => $order->id,
                'error'       => $errorMessage,
            ]);
            return false;
        }


        if (!AuthorizeDotNetAPI::isSuccessResponse($response)) {
            return false;
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


        // update 'active_payment_method' meta
        $subscriptionModel->updateMeta('active_payment_method', [
            'type' => Arr::get($transactionModel, 'payment_method_type'),
            'last_four' => Arr::get($transactionModel, 'card_last_4'),
            'brand' => Arr::get($transactionModel, 'card_brand'),
            'customer_profile_id' => Arr::get($profile, 'customerProfileId'),
            'payment_profile_id' => Arr::get($profile, 'customerPaymentProfileId'),
        ]);


        (new SubscriptionActivated($subscriptionModel, $order, $order->customer))->dispatch();

        return true;
    }
}

