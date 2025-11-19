<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;

class StripeHelper
{
    public static function createOrGetStripeCustomer(Customer $customer)
    {
        // check if we already have a stripe_customer_id for this person
        $existingStripeCustomerId = $customer->getMeta('stripe_customer_id', false);
        if ($existingStripeCustomerId) {
            $existingStripeCustomer = (new API())->getStripeObject('customers/' . $existingStripeCustomerId);

            if (!is_wp_error($existingStripeCustomer) && is_array($existingStripeCustomer) && !empty($existingStripeCustomer['id']) && isset($existingStripeCustomer['email']) && $existingStripeCustomer['email'] === $customer->email) {
                return $existingStripeCustomer;
            }
        }

        $customerInfo = array_filter([
            'name'    => $customer->full_name,
            'email'   => $customer->email,
            'phone'   => $customer->phone ?? '',
            'address' => array_filter([
                'city'        => $customer->city ?? '',
                'country'     => $customer->country ?? '',
                'postal_code' => $customer->postcode ?? '',
                'state'       => $customer->state ?? '',
            ])
        ]);

        $newStripeCustomer = (new API())->createStripeObject('customers', $customerInfo);

        if (is_wp_error($newStripeCustomer)) {
            return $newStripeCustomer;
        }

        $id = Arr::get($newStripeCustomer, 'id', false);

        if ($id) {
            $customer->updateMeta('stripe_customer_id', $id);
        }

        return $newStripeCustomer;
    }

    public static function transformSubscriptionStatus($stripeSubscription, $subscriptionModel = null)
    {
        $status = strtolower($stripeSubscription['status']);

        if ($status === 'active') {
            $status = Status::SUBSCRIPTION_ACTIVE;
        } else if ($status === 'incomplete' || $status === 'incomplete_expired') {
            $status = Status::SUBSCRIPTION_INTENDED;
        } else if ($status === 'trialing') {
            $status = Status::SUBSCRIPTION_TRIALING;
        } else if ($status === 'canceled') {
            $status = Status::SUBSCRIPTION_CANCELED;
            if (Arr::get($stripeSubscription, 'cancellation_details.reason', '') === 'payment_failed') {
                $status = Status::SUBSCRIPTION_EXPIRED;
            }
        } else if ($status === 'unpaid') {
            $status = Status::SUBSCRIPTION_EXPIRED;
        } else if ($status === 'paused') {
            $status = Status::SUBSCRIPTION_PAUSED;
        } else if ($status === 'past_due') {
            $status = Status::SUBSCRIPTION_EXPIRING;
            if ($subscriptionModel && $subscriptionModel->status === 'expired') {
                $status = Status::SUBSCRIPTION_EXPIRED;
            }
        }

        return $status;
    }

    public static function getSubscriptionUpdateData($stripeSubscription, $subscriptionModel = null)
    {
        $stripeStatus = strtolower(Arr::get($stripeSubscription, 'status', ''));

        $status = self::transformSubscriptionStatus($stripeSubscription, $subscriptionModel);

        $subscriptionUpdateData = array_filter([
            'current_payment_method' => 'stripe',
            'status'                 => $status
        ]);

        if ($stripeStatus == Status::SUBSCRIPTION_CANCELED) {
            $cancelledAt = (int)Arr::get($stripeSubscription, 'canceled_at');
            if ($cancelledAt) {
                $subscriptionUpdateData['canceled_at'] = gmdate('Y-m-d H:i:s', $cancelledAt);
            }
        }

        $currentPeriodEnds = (int)Arr::get($stripeSubscription, 'current_period_end');
        // we have to check if the last invoice is paid or not!
        // If not paid, then we have to use the $stripeSubscription['current_period_start']
        $latestInvoice = Arr::get($stripeSubscription, 'latest_invoice', null);
        if ($latestInvoice && !empty($latestInvoice['id'])) {
            // we have the latest invoice
            if (Arr::get($latestInvoice, 'status') !== 'paid') {
                $currentPeriodEnds = (int)Arr::get($stripeSubscription, 'current_period_start');
            }
        }

        if ($currentPeriodEnds) {
            $subscriptionUpdateData['next_billing_date'] = gmdate('Y-m-d H:i:s', $currentPeriodEnds);
        }

        return $subscriptionUpdateData;
    }


    public static function processRemoteRefund($transaction, $amount, $args)
    {
        $intentId = $transaction->vendor_charge_id;
        if (!$intentId) {
            return new \WP_Error('invalid_refund', __('Invalid transaction ID for refund.', 'fluent-cart'));
        }

        $refundData = [
            'payment_intent' => $intentId,
            'amount'         => $amount,
        ];

        $reason = Arr::get($args, 'reason', '');

        if ($reason && in_array($reason, ['duplicate', 'fraudulent', 'requested_by_customer'])) {
            $refundData['reason'] = $reason;
        }

        $refunded = (new API())->createStripeObject('refunds', $refundData, $transaction->payment_mode);

        if (is_wp_error($refunded)) {
            return $refunded;
        }

        $status = Arr::get($refunded, 'status');
        $acceptedStatus = ['succeeded', 'pending'];
        if (!in_array($status, $acceptedStatus)) {
            return new \WP_Error('refund_failed', __('Refund could not be processed in stripe. Please check on your stripe account', 'fluent-cart'));
        }

        return Arr::get($refunded, 'id');
    }

    public static function createOrUpdateIpnRefund($refundData, $parentTransaction)
    {
        $allRefunds = OrderTransaction::query()
            ->where('order_id', $refundData['order_id'])
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->orderBy('id', 'DESC')
            ->get();

        if ($allRefunds->isEmpty()) {
            // this is the first refund for this order
            return OrderTransaction::query()->create($refundData);
        }

        $currentRefundTransactionId = Arr::get($refundData, 'meta.parent_id', '');

        $existingLocalRefund = null;
        foreach ($allRefunds as $refund) {
            if ($refund->vendor_charge_id == $refundData['vendor_charge_id']) {
                if ($refund->total != $refundData['total']) {
                    $refund->fill($refundData);
                    $refund->save();
                }
                // this refund already exists
                return $refund;
            }

            if (!$refund->vendor_charge_id) { // this is a local redfund without vendor charge id
                $refundTransactionId = Arr::get($refund->meta, 'parent_id', '');
                $isTransactionMatched = $refundTransactionId == $currentRefundTransactionId;

                // this is a local refund without vendor charge id, we will update it
                if ($refund->total == $refundData['total'] && $isTransactionMatched) {
                    // this refund already exists
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

}
