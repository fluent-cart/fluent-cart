<?php

namespace FluentCart\App\Services\Payments;

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\App;
use FluentCart\App\Events\Order\OrderRefund;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

class Refund
{
    public function processRefund($transaction, $refundAmount, $args = [])
    {

        $args = wp_parse_args($args, [
            'reason'      => '',
            'item_ids'    => [],
            'manageStock' => false,
        ]);

        $order = $transaction->order;


        if ($refundAmount <= 0) {
            return new \WP_Error('invalid_refund_amount', __('Invalid refund amount.', 'fluent-cart'));
        }

        $netOrderPaidAmount = $order->total_paid - $order->total_refunded;
        if ($refundAmount > $netOrderPaidAmount) {
            return new \WP_Error('invalid_refund_amount', __('Refund amount exceeds the net paid amount for this order.', 'fluent-cart'));
        }

        if ($transaction->getMaxRefundableAmount() < $refundAmount) {
            return new \WP_Error('invalid_refund_amount', __('Refund amount exceeds the maximum refundable amount for this transaction.', 'fluent-cart'));
        }

        $orderTransactions = [
            'order_id'            => $transaction->order_id,
            'order_type'          => $order->type,
            'payment_method'      => $transaction->payment_method,
            'payment_mode'        => $transaction->payment_mode,
            'payment_method_type' => $transaction->payment_method_type,
            'transaction_type'    => Status::TRANSACTION_TYPE_REFUND,
            'subscription_id'     => $transaction->subscription_id,
            'status'              => Status::TRANSACTION_REFUNDED,
            'currency'            => $transaction->currency,
            'total'               => $refundAmount,
            'meta'                => [
                'parent_id' => $transaction->id,
                'reason'    => $args['reason'] ?? '',
            ],
            'uuid'                => md5(time() . wp_generate_uuid4())
        ];

        $refundTransaction = OrderTransaction::query()->create($orderTransactions);

        // update the main parent transaction meta
        PaymentHelper::updateTransactionRefundedTotal($transaction, $refundAmount);

        $vendorRefundId = null;
        if ($gateway = App::gateway($transaction->payment_method)) {
            if ($gateway->has('refund')) {
                $vendorRefundId = $gateway->processRefund($transaction, $refundAmount, $args);
            }
        }

        if (!is_wp_error($vendorRefundId) && $vendorRefundId) {
            $refundTransaction->vendor_charge_id = $vendorRefundId;
            $refundTransaction->save();
        }

        $manageStock = filter_var(Arr::get($args, 'manageStock'), FILTER_VALIDATE_BOOLEAN);

        (new OrderRefund($order, $refundTransaction, Arr::get($args, 'item_ids'), $manageStock, Arr::get($args, 'refunded_items', [])))->dispatch();

        return [
            'refund_transaction' => $refundTransaction,
            'vendor_refund_id'   => $vendorRefundId
        ];
    }


    public static function createOrRecordRefund($refundData, OrderTransaction $parentTransaction)
    {
        /*
         * check for existing refund transaction with this vendor charge ID
         * check for local refunds
         * */
        // check for existing refund transactions
        $allRefunds = OrderTransaction::query()
            ->where('order_id', $parentTransaction->order_id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->orderBy('id', 'DESC')
            ->get();

        foreach ($allRefunds as $refund) {
            if ($refund->vendor_charge_id == $refundData['vendor_charge_id']) {
                // this refund already exists
                return $refund;
            }

            if (!$refund->vendor_charge_id) { // this is a local refund without vendor charge id
                $refundParentId = Arr::get($refund->meta, 'parent_id', '');
                $isTransactionMatched = $refundParentId == $parentTransaction->id;
                // this is a local refund without vendor charge id, we will update it
                if ($refund->total == $refundData['total'] && $isTransactionMatched) {
                    // this refund already exists
                    $refund->vendor_charge_id = $refundData['vendor_charge_id'];
                    $refund->save();
                    return $refund;
                }
            }
        }

        return self::recordRefund($refundData, $parentTransaction);
    }

    public static function recordRefund($refundData, OrderTransaction $parentTransaction)
    {
        if (empty($refundData['total']) || $refundData['total'] <= 0) {
            return new \WP_Error('invalid_refund_amount', __('Invalid refund amount.', 'fluent-cart'));
        }

        $status = Arr::get($refundData, 'status', Status::TRANSACTION_REFUNDED);


        $defaults = [
            'order_id'            => $parentTransaction->order_id,
            'order_type'          => $parentTransaction->order_type,
            'transaction_type'    => Status::TRANSACTION_TYPE_REFUND,
            'subscription_id'     => $parentTransaction->subscription_id,
            'card_last_4'         => $parentTransaction->card_last_4,
            'card_brand'          => $parentTransaction->card_brand,
            'payment_method'      => $parentTransaction->payment_method,
            'payment_mode'        => $parentTransaction->payment_mode,
            'payment_method_type' => $parentTransaction->payment_method_type,
            'status'              => $status,
            'currency'            => $parentTransaction->currency,
            'meta'                => [
                'parent_id' => $parentTransaction->id,
                'reason'    => Arr::get($refundData, 'reason', ''),
            ]
        ];

        unset($refundData['reason']);

        $refundData = wp_parse_args($refundData, $defaults);
        $createdRefund = OrderTransaction::query()->create($refundData);

        PaymentHelper::updateTransactionRefundedTotal($parentTransaction, $createdRefund->total);

        if ($status === Status::TRANSACTION_REFUNDED) {
            (new OrderRefund($parentTransaction->order, $createdRefund))->dispatch();
        }

        return $createdRefund;
    }

}
