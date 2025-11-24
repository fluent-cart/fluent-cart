<?php

namespace FluentCartPro\App\Modules\PaymentMethods\PaddleGateway;

use FluentCartPro\App\Modules\PaymentMethods\PaddleGateway\API\API;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

class Confirmations
{
    public function init()
    {
        add_action('wp_ajax_nopriv_fluent_cart_confirm_paddle_payment', [$this, 'confirmPaddlePayment']);
        add_action('wp_ajax_fluent_cart_confirm_paddle_payment', [$this, 'confirmPaddlePayment']);
    }

    /**
     * Confirm Paddle payment after successful checkout
     */
    public function confirmPaddlePayment()
    {
        if (!isset($_REQUEST['vendor_charge_id'])) {
            wp_send_json([
                'message' => 'Transaction ID is required to confirm the payment.',
                'status' => 'failed'
            ], 400);
        }

        $vendorChargeId = sanitize_text_field($_REQUEST['vendor_charge_id']);
        $transactionHash = sanitize_text_field($_REQUEST['fct_transaction_hash'] ?? '');
        $fctOrderHash = sanitize_text_field($_REQUEST['fct_order_hash'] ?? '');

        // Find the transaction by UUID (ref_id)
        $transactionModel = null;

        if ($transactionHash) {
            $transactionModel = OrderTransaction::query()->where('uuid', $transactionHash)->first();
        }

        if (!$transactionModel) {
            $transaction = OrderTransaction::query()->where('vendor_charge_id', $vendorChargeId)->first();
        }

        if (!$transactionModel) {
            $order = Order::query()->where('uuid', $fctOrderHash)->first();
            if ($order) {
                $transactionModel = $order->getLatestTransaction();
            }

        }

        if (!$transactionModel) {
            wp_send_json([
                'message' => 'Transaction not found for the provided transaction ID.',
                'status' => 'failed'
            ], 404);
        }

        // Check if already processed
        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            wp_send_json([
                'redirect_url' => $transactionModel->getReceiptPageUrl(),
                'order' => [
                    'uuid' => $transactionModel->order->uuid,
                ],
                'message' => __('Payment already confirmed. Redirecting...!', 'fluent-cart-pro'),
                'status' => 'success'
            ], 200);
        }

        // Get transaction details from Paddle
        $paddleTransaction = API::getPaddleObject("transactions/{$vendorChargeId}", [], $transactionModel->payment_mode);

        if (is_wp_error($paddleTransaction)) {
            wp_send_json([
                'message' => $paddleTransaction->get_error_message(),
                'status' => 'failed'
            ], 500);
        }

        $data = Arr::get($paddleTransaction, 'data');
        $transactionStatus = Arr::get($data, 'status');

        // Check if payment is completed
        if ($transactionStatus !== 'paid' && $transactionStatus !== 'completed') {
            wp_send_json([
                'message' => sprintf(__('Payment is not completed. Current status: %s', 'fluent-cart-pro'), $transactionStatus),
                'status' => 'failed'
            ], 400);
        }


        $this->confirmPaymentSuccessByCharge($transactionModel, [
            'vendor_charge_id' => $vendorChargeId,
            'charge'      => $data,
        ]);

        wp_send_json([
            'redirect_url' => $transactionModel->getReceiptPageUrl(),
            'order' => [
                'uuid' => $transactionModel->order->uuid,
            ],
            'message' => __('Payment confirmed successfully. Redirecting...!', 'fluent-cart-pro'),
            'status' => 'success'
        ], 200);
    }

    /**
     * Confirm payment success and update transaction
     */
    public function confirmPaymentSuccessByCharge(OrderTransaction $transaction, $args = [])
    {
        $vendorChargeId = Arr::get($args, 'vendor_charge_id');
        $charge = Arr::get($args, 'charge');

        $payment     = Arr::get($charge, 'payments.0');
        $methodType  = Arr::get($payment, 'method_details.type');
        $billingInfo = [];

        if ($methodType == 'card') {
            $billingInfo  = [
                'type'   => 'card',
                'last4'  => Arr::get($payment, 'method_details.card.last4'),
                'brand'  => Arr::get($payment, 'method_details.card.type'),
                'expiry' => Arr::get($payment, 'method_details.card.expiry_month').'/'.Arr::get($payment, 'method_details.card.expiry_year'),
                'payment_method_id' => Arr::get($payment, 'payment_method_id')
            ];
        } else {
            $billingInfo  = [
                'type' => $methodType,
                'payment_method_id' => Arr::get($payment, 'payment_method_id')
            ];
        }

        $order = Order::query()->where('id', $transaction->order_id)->first();

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return $order; // already confirmed
        }

        $details = Arr::get($charge, 'details');
        $amount = Arr::get($details, 'totals.total', 0);
        $lineItems = Arr::get($details, 'line_items', []);

        $paddleItems = Arr::get($charge, 'items');

        $meta = PaddleHelper::getTransactionMeta($charge, $order);

        $subscription = Subscription::query()->where('parent_order_id', $order->id)->first();

        if ($amount > $transaction->total) {
            $recurringPriceId = null;
            foreach ($paddleItems as $item) {
                if (Arr::get($item, 'price.billing_cycle')) {
                    $recurringPriceId = Arr::get($item, 'price.id');
                    break;
                }
            }
            PaddleHelper::adjustExtraAmount($amount - $transaction->total, $transaction, $lineItems, $recurringPriceId);
        }

        $transactionUpdateData = array_filter([
            'order_id'            => $order->id,
            'total'               => $amount,
            'currency'            => Arr::get($charge, 'currency_code'),
            'status'              => Status::TRANSACTION_SUCCEEDED,
            'payment_method'      => 'paddle',
            'card_last_4'         => Arr::get($billingInfo, 'last4', ''),
            'card_brand'          => Arr::get($billingInfo, 'brand', ''),
            'payment_method_type' => Arr::get($billingInfo, 'type', ''),
            'vendor_charge_id'    => $vendorChargeId,
            'payment_mode'        => $order->mode,
            'meta'                => array_merge($transaction->meta ?? [], $meta)
        ]);

        $transaction->fill($transactionUpdateData);
        $transaction->save();

        fluent_cart_add_log(__('Paddle Payment Confirmation', 'fluent-cart-pro'), __('Payment confirmation received from Paddle. Transaction ID:', 'fluent-cart-pro')  . $vendorChargeId, 'info', [
            'module_name' => 'order',
            'module_id'   => $order->id,
        ]);

        if ($order->type === Status::ORDER_TYPE_RENEWAL) {
            $parentOrderId = $transaction->order->parent_id;
            if (!$parentOrderId) {
                return $order;
            }
            $subscription = Subscription::query()->where('parent_order_id', $parentOrderId)->first();

            if (!$subscription) {
                return $order; // No subscription found for this renewal order. Something is wrong.
            }

            $api = new API();
            $response = $api->getPaddleObject('subscriptions/' . $subscription->vendor_subscription_id, [], $transaction->payment_mode);

            $subscriptionArgs = [
                'status'                 => Status::SUBSCRIPTION_ACTIVE,
                'canceled_at'            => null,
                'current_payment_method' => 'paddle'
            ];

            if (!is_wp_error($response)) {
                $nextBillingDate = Arr::get($response, 'next_billed_at') ?? null;
                if ($nextBillingDate) {
                    $subscriptionArgs['next_billing_date'] = DateTime::anyTimeToGmt($nextBillingDate)->format('Y-m-d H:i:s');
                }
            }

            SubscriptionService::recordManualRenewal($subscription, $transaction, [
                'billing_info'      => $billingInfo,
                'subscription_args' => $subscriptionArgs
            ]);

        } else {
            if ($subscription) {
                // can't confirm subscription as we don't have the subscription id/data yet
                $subscription->updateMeta('active_payment_method', $billingInfo);
            }

            (new StatusHelper($order))->syncOrderStatuses($transaction);
        }

        return $order;
    }
}
