<?php

namespace FluentCartPro\App\Modules\PaymentMethods\MollieGateway;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCartPro\App\Modules\PaymentMethods\MollieGateway\API\MollieAPI;

class MollieHelper
{
   
    public static function processRemoteRefund($transaction, $amount, $args)
    {
        $paymentId = $transaction->vendor_charge_id;

        if (!$paymentId) {
            return new \WP_Error(
                'mollie_refund_error',
                __('Payment ID not found for refund', 'fluent-cart-pro')
            );
        }

        $payment = (new MollieAPI())->getMollieObject('payments/' . $paymentId);

        if (is_wp_error($payment)) {
            return $payment;
        }

        $amountAvailable = Arr::get($payment, 'amountRemaining.value', '0.00');
        $currency = Arr::get($payment, 'amount.currency');

        $refundAmount = self::formatAmountForMollie($amount, $currency);

        if (floatval($refundAmount) > floatval($amountAvailable)) {
            return new \WP_Error(
                'mollie_refund_error',
                sprintf(
                    __('Refund amount %s exceeds available amount %s', 'fluent-cart-pro'),
                    $refundAmount,
                    $amountAvailable
                )
            );
        }

        $refundData = [
            'amount' => [
                'currency' => $currency,
                'value'    => $refundAmount
            ]
        ];

        if (!empty($args['note'])) {
            $refundData['description'] = $args['note'];
        }

        // Add reason as description if provided and no note
        if (empty($args['note']) && !empty($args['reason'])) {
            $reasonMap = [
                'duplicate' => 'Duplicate payment',
                'fraudulent' => 'Fraudulent payment',
                'requested_by_customer' => 'Requested by customer'
            ];
            $refundData['description'] = $reasonMap[$args['reason']] ?? $args['reason'];
        }

        $refund = (new MollieAPI())->createMollieObject('payments/' . $paymentId . '/refunds', $refundData);

        if (is_wp_error($refund)) {
            return $refund;
        }

        $status = Arr::get($refund, 'status');
        $acceptedStatus = ['queued', 'pending', 'processing', 'refunded'];
        if (!in_array($status, $acceptedStatus)) {
            return new \WP_Error('refund_failed', __('Refund could not be processed with Mollie. Please check your Mollie account', 'fluent-cart-pro'));
        }

        return Arr::get($refund, 'id');
    }

    public static function createOrUpdateIpnRefund($refundData, $parentTransaction)
    {
        $allRefunds = OrderTransaction::query()
            ->where('order_id', $refundData['order_id'])
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->orderBy('id', 'DESC')
            ->get();

        if ($allRefunds->isEmpty()) {
            // This is the first refund for this order
            $createdRefund = OrderTransaction::query()->create($refundData);
            return $createdRefund instanceof OrderTransaction ? $createdRefund : null;
        }

        $currentRefundMollieId = Arr::get($refundData, 'vendor_charge_id', '');

        $existingLocalRefund = null;
        foreach ($allRefunds as $refund) {
            if ($refund->vendor_charge_id == $refundData['vendor_charge_id']) {
                if ($refund->total != $refundData['total']) {
                    $refund->fill($refundData);
                    $refund->save();
                }
                // This refund already exists
                return $refund;
            }

            if (!$refund->vendor_charge_id) { // This is a local refund without vendor charge id
                $refundMollieId = Arr::get($refund->meta, 'mollie_refund_id', '');
                $isRefundMatched = $refundMollieId == $currentRefundMollieId;

                // This is a local refund without vendor charge id, we will update it
                if ($refund->total == $refundData['total'] && $isRefundMatched) {
                    $existingLocalRefund = $refund;
                }
            }
        }

        if ($existingLocalRefund) {
            $existingLocalRefund->fill($refundData);
            $existingLocalRefund->save();
            return $existingLocalRefund;
        }

        $createdRefund = OrderTransaction::query()->create($refundData);    
        PaymentHelper::updateTransactionRefundedTotal($parentTransaction, $createdRefund->total);

        return $createdRefund;
    }

    public static function formatAmountForMollie($amount, $currency)
    {
        $zeroDecimalCurrencies = ['JPY', 'KRW', 'VND', 'CLP', 'TWD'];
        
        if (in_array(strtoupper($currency), $zeroDecimalCurrencies)) {
            return number_format($amount, 0, '.', '');
        }

        return number_format($amount / 100, 2, '.', '');
    }


    public static function convertToCents($amount, $currency)
    {
        $zeroDecimalCurrencies = ['JPY', 'KRW', 'VND', 'CLP', 'TWD'];
        
        if (in_array(strtoupper($currency), $zeroDecimalCurrencies)) {
            return (int) round(floatval($amount));
        }

        return (int) round(floatval($amount) * 100);
    }

    public static function getSubscriptionUpdateData($mollieSubscription, $subscriptionModel)
    {
        $status = self::transformSubscriptionStatus($mollieSubscription, $subscriptionModel);

        $subscriptionUpdateData = array_filter([
            'current_payment_method' => 'mollie',
            'status'                 => $status
        ]);

        // Handle cancellation
        if ($status === Status::SUBSCRIPTION_CANCELED) {
            $canceledAt = Arr::get($mollieSubscription, 'canceledAt');
            if ($canceledAt) {
                $subscriptionUpdateData['canceled_at'] = DateTime::anyTimeToGmt($canceledAt)->format('Y-m-d H:i:s');
            }
        }

        // Handle next billing date
        $nextPaymentDate = Arr::get($mollieSubscription, 'nextPaymentDate');
        if ($nextPaymentDate) {
            $subscriptionUpdateData['next_billing_date'] = DateTime::anyTimeToGmt($nextPaymentDate)->format('Y-m-d H:i:s');
        }

        return $subscriptionUpdateData;
    }

    public static function generateSubscriptionDescription($subscriptionModel, $currency = '', $orderType = '')
    {
        $recurringTotal = $subscriptionModel->recurring_total;
        $initialTrial = $subscriptionModel->trial_days;
        $signUpfee = $signUpfee = $subscriptionModel->signup_fee;

        if ($orderType == 'renewal') {
            $recurringTotal = $subscriptionModel->getCurrentRenewalAmount();
            $initialTrial = $subscriptionModel->getReactivationTrialDays();
            $signUpfee = 0;
        }

        $intervalMap = [
            'daily'       => '1 day',
            'weekly'      => '1 week',
            'monthly'     => '1 month',
            'quarterly'   => '3 months',
            'half_yearly' => '6 months',
            'yearly'      => '12 months'
        ];

        $description = $subscriptionModel->item_name . ' - ' . self::formatAmountForMollie($recurringTotal, $currency) . ' every ' . $intervalMap[$subscriptionModel->billing_interval];

        if ($initialTrial > 0 && Arr::get($subscriptionModel->config, 'is_trial_days_simulated', 'no') != 'yes') {
            $description .= ' ( ' . __(' after', 'fluent-cart-pro') . ' ' . $initialTrial . ' ' . __('day', 'fluent-cart-pro') . ' ' . __('trial', 'fluent-cart-pro') . ' )';
        }

        if ($signUpfee > 0) {
            $description .= ' ' . __(' with', 'fluent-cart-pro') . self::formatAmountForMollie($signUpfee, $currency) . __(' sign-up fee', 'fluent-cart-pro');
        }

        return $description;
    }

    public static function calculateSubscriptionStartDate($subscriptionModel, $order)
    {
        // For renewal orders, start date is today
        if ($order->type == 'renewal') {
            $trialDays = $subscriptionModel->getReactivationTrialDays();
            if ($trialDays > 0) {
                // now + trial days
                $startDate =  DateTime::anytimeToGmt(strtotime(DateTime::now()) + ($trialDays * DAY_IN_SECONDS));
                return DateTime::anytimeToGmt($startDate)->format('Y-m-d');
            } else {
                return DateTime::anytimeToGmt(DateTime::now())->format('Y-m-d');
            }
          
        } else {
            // add trail days if any, add interval days to last order date
            $trialDays = $subscriptionModel->trial_days;
            $intervalDaysMap = [
                'daily'       => 1,
                'weekly'      => 7,
                'monthly'     => 30,
                'quarterly'   => 90,
                'half_yearly' => 182,
                'yearly'      => 365
            ];

            $intervalDays = $intervalDaysMap[$subscriptionModel->billing_interval] ?? 0;

            if ($trialDays > 0) {
                // order created at + trial days
                $startDate =  strtotime(DateTime::now()) + $intervalDays * DAY_IN_SECONDS + ($trialDays * DAY_IN_SECONDS);
                return DateTime::anytimeToGmt($startDate)->format('Y-m-d');
            } else {
                $startDate = strtotime(DateTime::now()) + $intervalDays * DAY_IN_SECONDS;
                return DateTime::anytimeToGmt($startDate)->format('Y-m-d');
            }

        }
    }

    public static function transformSubscriptionStatus($mollieSubscription, $subscriptionModel)
    {
        $mollieStatus = strtolower(Arr::get($mollieSubscription, 'status', ''));

        switch ($mollieStatus) {
            case 'active':
                return Status::SUBSCRIPTION_ACTIVE;
            case 'canceled':
                return Status::SUBSCRIPTION_CANCELED;
            case 'suspended':
                return Status::SUBSCRIPTION_EXPIRED;
            case 'completed':
                return Status::SUBSCRIPTION_COMPLETED;
            default:
                return Status::SUBSCRIPTION_PENDING;
        }
    }

    public static function getCorrectTransactionStatus($mollieStatus)
    {
        switch ($mollieStatus) {
            case 'paid':
                return Status::TRANSACTION_SUCCEEDED;
            case 'authorized':
                return Status::TRANSACTION_AUTHORIZED;
            case 'canceled':
                return Status::TRANSACTION_FAILED;
            case 'expired':
                return Status::TRANSACTION_FAILED;
            case 'refunded':
                return Status::TRANSACTION_REFUNDED;
            case 'failed':
                return Status::TRANSACTION_FAILED;
            case 'open':
            case 'pending':
            default:
                return Status::TRANSACTION_PENDING;
        }
    }


    public static function getTransactionUrl($paymentId, $isLive = true)
    {
        return 'https://www.mollie.com/dashboard/payments/' . $paymentId;
    }

    public static function getSubscriptionUrl($subscriptionId, $isLive = true)
    {
        $subscriptionModel = Subscription::query()->where('id', $subscriptionId)->first();

        if (!$subscriptionModel) {
            return '';
        }

        return 'https://www.mollie.com/dashboard/customers/' . $subscriptionModel->vendor_customer_id;
    }

    public static function formatAddress($fcCustomer, $customerAddress)
    {
       $address = [
            'givenName' => $fcCustomer->first_name,
            'familyName' => $fcCustomer->last_name,
            'email' => $fcCustomer->email,
       ];

       if ($customerAddress->address_1) {
            $address['streetAndNumber'] = $customerAddress->address_1;
       }
       if ($customerAddress->postcode) {
            $address['postalCode'] = $customerAddress->postcode;
       }
       if ($customerAddress->city) {
            $address['city'] = $customerAddress->city;
       }
       if ($customerAddress->country) {
            $address['country'] = $customerAddress->country;
       }

       return $address;
    }
}

