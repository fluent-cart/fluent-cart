<?php

namespace FluentCartPro\App\Modules\PaymentMethods\MollieGateway;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\PaymentMethods\MollieGateway\API\MollieAPI;

class MollieSubscriptions extends AbstractSubscriptionModule
{
    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)
    {
        if ($subscriptionModel->current_payment_method !== 'mollie') {
            return new \WP_Error('invalid_payment_method', __('This subscription is not using Mollie as payment method.', 'fluent-cart-pro'));
        }

        
        $order = $subscriptionModel->order;
        $vendorSubscriptionId = $subscriptionModel->vendor_subscription_id;

        if (!$vendorSubscriptionId) {
            return new \WP_Error(
                'invalid_subscription',
                __('Invalid vendor subscription ID.', 'fluent-cart-pro')
            );
        }

        $mollieSubscription = (new MollieAPI())->getMollieObject('customers/' . $subscriptionModel->vendor_customer_id . '/subscriptions/' . $vendorSubscriptionId);

        if (is_wp_error($mollieSubscription)) {
            return $mollieSubscription;
        }

        $subscriptionUpdateData = MollieHelper::getSubscriptionUpdateData($mollieSubscription, $subscriptionModel);

        $molliePayments = (new MollieAPI())->getMollieObject('customers/' . $subscriptionModel->vendor_customer_id . '/subscriptions/' . $vendorSubscriptionId . '/payments');

        if (is_wp_error($molliePayments)) {
            return $molliePayments;
        }

        $newPayment = false;
        
        $molliePayments = Arr::get($molliePayments, '_embedded.payments', []);
        $count = Arr::get($molliePayments, 'count', 0);
        
        foreach($molliePayments as $payment){
            $paymentId = Arr::get($payment, 'id');

            if (Arr::get($payment, 'status') == 'paid') {

                $amount = MollieHelper::convertToCents(Arr::get($payment, 'amount.value', '0.00'), Arr::get($payment, 'amount.currency'));
                $methodType  = Arr::get($payment, 'method');
                $cardLast4 =  Arr::get($payment, 'details.cardNumber', null);
                $cardBrand = Arr::get($payment, 'details.cardLabel', null);

                $transaction = OrderTransaction::query()->where('vendor_charge_id', $paymentId)->first();

                if (!$transaction) {

                     $transaction = OrderTransaction::query()
                            ->where('subscription_id', $subscriptionModel->id)
                            ->where('vendor_charge_id', '')
                            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                            ->first();

                    if ($transaction) {
                        $transaction->update([
                            'vendor_charge_id' => $paymentId,
                            'status'           => Status::TRANSACTION_SUCCEEDED,
                            'total'            => $amount,
                            'meta'             => array_merge($transaction->meta ?? [], $transaction->meta),
                            'card_last_4'    => $cardLast4,
                            'card_brand'       => $cardBrand,
                            'payment_method_type' => $methodType
                        ]);

                        (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);

                        continue;
                    }
                    // Create new transaction
                    $transactionData = [
                        'order_id'         => $order->id,
                        'amount'           => $amount,
                        'currency'         => Arr::get($payment, 'amount.currency'),
                        'vendor_charge_id' => $paymentId,
                        'status'           => MollieHelper::getCorrectTransactionStatus(strtolower(Arr::get($payment, 'status', ''))),
                        'payment_method'   => 'mollie',
                        'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
                        'card_last_4'       => $cardLast4,
                        'card_brand'       => $cardBrand,
                        'created_at'       => DateTime::anyTimeToGmt(Arr::get($payment, 'paidAt'))->format('Y-m-d H:i:s'),
                    ];
                    $newPayment = true;
                    SubscriptionService::recordRenewalPayment($transactionData, $subscriptionModel, $subscriptionUpdateData);
                } else if ($transaction->status !== Status::TRANSACTION_SUCCEEDED) {
                    // Update existing transaction if status has changed
                    $transaction->update([
                        'status' => MollieHelper::getCorrectTransactionStatus(strtolower(Arr::get($payment, 'status', ''))),
                    ]);

                    (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
                }
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

    public function cancel($vendorSubscriptionId, $args = [])
    {
        
        $subscriptionModel = Subscription::query()->where('vendor_subscription_id', $vendorSubscriptionId)->first();

        if (!$subscriptionModel) {
            return new \WP_Error(
                'invalid_subscription',
                __('Invalid vendor subscription ID.', 'fluent-cart-pro')
            );
        }


        $response = (new MollieAPI())->deleteMollieObject('customers/' . $subscriptionModel->vendor_customer_id .'/subscriptions/' . $vendorSubscriptionId, []);


        if (is_wp_error($response)) {
            return $response;
        }
 

        return [
            'status' => Status::SUBSCRIPTION_CANCELED,
            'canceled_at' => DateTime::anyTimeToGmt(Arr::get($response, 'canceledAt'))->format('Y-m-d H:i:s')
        ];
    
    }

    /**
     * Transform Mollie subscription status to FluentCart status
     *
     * @param array $mollieSubscription
     * @param Subscription|null $subscriptionModel
     * @return string
     */
    private function transformSubscriptionStatus($mollieSubscription, $subscriptionModel = null)
    {
        $status = strtolower(Arr::get($mollieSubscription, 'status', ''));

        switch ($status) {
            case 'active':
                return Status::SUBSCRIPTION_ACTIVE;
            case 'pending':
                return Status::SUBSCRIPTION_INTENDED;
            case 'canceled':
                return Status::SUBSCRIPTION_CANCELED;
            case 'suspended':
                return Status::SUBSCRIPTION_PAUSED;
            case 'completed':
                return Status::SUBSCRIPTION_COMPLETED;
            default:
                return Status::SUBSCRIPTION_INTENDED;
        }
    }

    /**
     * Get subscription update data from Mollie subscription
     *
     * @param array $mollieSubscription
     * @param Subscription $subscriptionModel
     * @return array
     */
    private function getSubscriptionUpdateData($mollieSubscription, $subscriptionModel)
    {
        $status = $this->transformSubscriptionStatus($mollieSubscription, $subscriptionModel);

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

    /**
     * Convert amount to cents
     *
     * @param string $amount
     * @param string $currency
     * @return int
     */
    private function convertToCents($amount, $currency)
    {
        $zeroDecimalCurrencies = ['JPY', 'KRW', 'VND', 'CLP', 'TWD'];
        
        if (in_array(strtoupper($currency), $zeroDecimalCurrencies)) {
            return (int) $amount;
        }

        return (int) (floatval($amount) * 100);
    }
}
