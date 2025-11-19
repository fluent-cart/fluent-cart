<?php

namespace FluentCart\App\Modules\PaymentMethods\Cod;

use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\SubscriptionHelper;

class CodHandler {

    protected $cod;

    public function __construct(Cod $cod)
    {
        $this->cod = $cod;
    }
    public function handlePayment($paymentInstance)
    {
        $settings = $this->cod->settings->get();
        $order = $paymentInstance->order;
        if ($order->total_amount == 0) {
            return $this->handleZeroTotalPayment($paymentInstance);
        }

        if (!$settings['is_active'] === 'yes') {
            throw new \Exception(esc_html__('Offline payment is not activated', 'fluent-cart'));
        }


        $order->payment_method_title = $this->cod->getMeta('title');
        $order->save();


        if (!$order->id) {
            throw new \Exception(esc_html__('Order not found!', 'fluent-cart'));
        }

        $paymentHelper = new PaymentHelper('offline_payment');

        $relatedCart = Cart::query()->where('order_id', $order->id)
            ->where('stage', '!=', 'completed')
            ->first();

        if ($relatedCart) {
            $relatedCart->stage = 'completed';
            $relatedCart->completed_at = DateTime::now()->format('Y-m-d H:i:s');
            $relatedCart->save();
        }

        return $paymentHelper->successUrl($paymentInstance->transaction->uuid);
    }

    public function handleZeroTotalPayment($paymentInstance)
    {
        $order = $paymentInstance->order;

        $transaction = $paymentInstance->transaction;
        $transaction->status = Status::TRANSACTION_SUCCEEDED;
        $transaction->save();

        (new StatusHelper($order))->syncOrderStatuses($transaction);


        $paymentHelper = new PaymentHelper('offline_payment');
        return $paymentHelper->successUrl($transaction->uuid);
    }
}
