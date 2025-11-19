<?php

namespace FluentCart\App\Modules\PaymentMethods\Cod;

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;


class Cod extends AbstractPaymentGateway
{
    public array $supportedFeatures = ['payment', 'refund'];

    public BaseGatewaySettings $settings;

    public function boot()
    {
        add_action('fluent_cart/payment_paid', [$this, 'handlePaymentPaid'], 10, 1);
        add_filter('fluent_cart_payment_method_list_class', [$this, 'getPaymentMethodClass'], 10, 2);
    }

    public function getPaymentMethodClass($class, $data): string
    {
        $route = Arr::get($data, 'route');
        return $route === 'offline_payment'?'no-padding' : '';
    }

    public function meta(): array
    {
        return [
            'title'       => __('Cash', 'fluent-cart'),
            'route'       => 'offline_payment',
            'slug'        => 'offline_payment',
            'description' => esc_html__('Pay with cash upon delivery', 'fluent-cart'),
            'logo'        => Vite::getAssetUrl("images/payment-methods/offline-payment.svg"),
            'icon'        => Vite::getAssetUrl("images/payment-methods/cod-icon.svg"),
            'brand_color' => '#136196',
            'upcoming'    => false,
            'status'      => $this->settings->get('is_active') === 'yes',
        ];
    }

    public function __construct()
    {
        parent::__construct(
            new CodSettingsBase()
        );
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        try {
            return [
                'status'      => 'success',
                'message'     => __('Order has been placed successfully', 'fluent-cart'),
                'redirect_to' => (new CodHandler($this))->handlePayment($paymentInstance)
            ];

        } catch (\Exception $e) {
            return [
                'status'  => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }


    public function handlePaymentPaid($params)
    {
        $orderId = Arr::get($params, 'order.id');
        // get the order with the id
        $order = OrderResource::getQuery()->find($orderId);

        if (!$order) {
            return;
        }

        $orderStatus = Arr::get($params, 'order.status');
        $fulfillmentType = Arr::get($params, 'order.fulfillment_type');
        $paymentMethod = Arr::get($params, 'order.payment_method');

        //if new status is processing and payment_method is offline_payment and fulfillment_type is digital then make it completed
        if ($orderStatus === Status::ORDER_PROCESSING && $paymentMethod === 'offline_payment' && $fulfillmentType === 'digital') {
            $order->status = Status::ORDER_COMPLETED;
            $order->completed_at = DateTime::gmtNow();
            $order->save();

            $actionActivity = [
                'title'   => __('Order status updated', 'fluent-cart'),
                'content' => sprintf(
                /* translators: 1: old status, 2: new status */
                    __('Order status has been updated from %1$s to %2$s', 'fluent-cart'),
                    $orderStatus,
                    $order->status
                )
            ];

            (new OrderStatusUpdated($order, $orderStatus, 'completed', true, $actionActivity, 'order_status'))->dispatch();

            $this->maybeUpdateSubscription($params);

        } else if ($orderStatus === Status::ORDER_PROCESSING && $paymentMethod === 'offline_payment' && $fulfillmentType === 'physical') {
            $this->maybeUpdateSubscription($params);
        }

        return;
    }

    public function maybeUpdateSubscription($params)
    {
        $order = Arr::get($params, 'order');
        $orderId = Arr::get($params, 'order.id');
        $orderStatus = Arr::get($params, 'order.status');

        if (!$order) {
            return;
        }

        if (!in_array($orderStatus, [Status::ORDER_PROCESSING, Status::ORDER_COMPLETED])) {
            return;
        }

        // just search in subscription table with order id get every subscription
        $subscriptions = Subscription::query()->where('parent_order_id', $orderId)->get();

        // update subscription status
        foreach ($subscriptions as $subscription) {
            if ($subscription->status === 'active') {
                continue;
            }
            $subscription->status = 'active';
            $subscription->save();
        }
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'fluent-cart-checkout-handler-cod',
                'src'    => Vite::getEnqueuePath('public/payment-methods/cod-checkout.js'),
            ]
        ];
    }

    public function getLocalizeData(): array
    {
        return [
            'fct_cod_data' => [
                'translations' => [
                    'Cash upon delivery, bank transfer or other manual process.' => __('Cash upon delivery, bank transfer or other manual process.', 'fluent-cart'),
                ]
            ]
        ];
    }

    public function fields()
    {
        return array(
            'cod_description' => array(
                'value' =>
                    wp_kses(sprintf(
                        "<div class='pt-4'>
                            <p>%s</p>
                        </div>",
                        __('✅  Customers can pay for their orders by cash upon delivery. You may receive using bank transfer or other manual process as well.
                        You may remind them to have the exact amount ready for our delivery personnel!', 'fluent-cart')
                    ),
                        [
                            'p'   => [],
                            'div' => ['class' => true],
                            'i'   => [],
                        ]),
                'label' => __('Webhook URL', 'fluent-cart'),
                'type'  => 'html_attr'
            ),
        );

    }

    public static function validateSettings($data): array
    {
        return [
            'status'  => 'success',
            'message' => __('Settings saved successfully', 'fluent-cart')
        ];
    }

    public function maybeUpdatePayments($orderHash)
    {
        $updateData = [
            'status' => Status::PAYMENT_PENDING
        ];
        $order = OrderResource::getOrderByHash($orderHash);
        $this->updateOrderDataByOrder($order, $updateData);
    }

    public function refund($refundInfo, $orderData, $transaction)
    {
        // cod does not have any refund process
    }

    public function webHookPaymentMethodName()
    {
        return $this->getMeta('route');
    }

    public function handleIPN()
    {
        // cod does not have any ipn
    }

    public function getOrderInfo(array $data)
    {
        // TODO: Implement getOrderInfo() method.
    }
}
