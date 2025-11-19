<?php

namespace FluentCart\App\Hooks\Handlers\CustomCheckout;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;

class CustomCheckout
{
    public function register()
    {
        add_action('fluent_cart_action_custom_checkout', [$this, 'handleCustomCheckoutRedirect'], 10, 1);
    }

    /*
  * 1. validation
  * 2. create cart
  *      - subscription
  *          discount, signup etc.
  *      - onetime
  * 3. redirect to the checkout with cart
  */
    public function handleCustomCheckoutRedirect($data)
    {
        $orderHash = sanitize_text_field(Arr::get($data, 'order_hash', ''));
        $order = Order::query()->where('uuid', $orderHash)->first();
        if (!$order) {
            die('Invalid order!');
        }

        if ($order->payment_status === Status::PAYMENT_PAID || $order->status === Status::ORDER_COMPLETED) {
            die('Order already completed!');
        }

        $totalDue = $order->total_amount - $order->paid;

        if ($totalDue <= 0) {
            die('No due amount found!');
        }

        if ($order->type === Status::ORDER_TYPE_SUBSCRIPTION) {

            $orderItem = $order->order_items->filter(function ($item) {
                return $item->payment_type !== 'signup_fee';
            })->first()->toArray();

            $subscriptionItem = ProductVariation::query()
                ->where('id', $orderItem['object_id'])
                ->first()->toArray();
            $subscriptionModel = Subscription::query()->where('parent_order_id', $order->id)->first();

            $totalSignup = Arr::get($orderItem, 'other_info.signup_fee', 0) - Arr::get($orderItem, 'other_info.signup_discount', 0);
            $firstPrice = $orderItem['subtotal'] + $totalSignup - Arr::get($orderItem, 'discount_total', 0);
            $recurringPrice = $orderItem['subtotal'] + $orderItem['tax_amount'];

            if ($firstPrice < $recurringPrice) {
                Arr::set($subscriptionItem, 'other_info.trial_days', $subscriptionModel->trial_days);
                Arr::set($subscriptionItem, 'other_info.signup_fee', $subscriptionModel->signup_fee);
                Arr::set($subscriptionItem, 'other_info.manage_setup_fee', 'yes');
                Arr::set($subscriptionItem, 'other_info.original_signup_fee', Arr::get($orderItem, 'other_info.signup_fee', 0));
            } else if ($firstPrice > $recurringPrice) {
                Arr::set($subscriptionItem, 'other_info.signup_fee', $subscriptionModel->signup_fee);
                Arr::set($subscriptionItem, 'other_info.manage_setup_fee', 'yes');
                Arr::set($subscriptionItem, 'other_info.trial_days', 0);
                Arr::set($subscriptionItem, 'other_info.original_signup_fee', Arr::get($orderItem, 'other_info.signup_fee', 0));
            }

            if ($order->manual_discount_total) {
                Arr::set($subscriptionItem, 'manual_discount', ceil($order->manual_discount_total));
            }

            if (Arr::get($subscriptionItem, 'other_info.trial_days', 0) > 0) {

                $isTrialDaysSimulated = Arr::get($subscriptionModel, 'config.is_trial_days_simulated', ) == 'yes' ? true : false;
                Arr::set($subscriptionItem, 'other_info.is_trial_days_simulated', $isTrialDaysSimulated ? 'yes' : 'no');
                
            }

            $instantCart = CartHelper::generateCartFromCustomVariation($subscriptionItem, 1);

        } else {
            $items = [];
            foreach ($order->order_items as $orderItem) {
                $item = ProductVariation::query()->where('id', $orderItem['object_id'])->first()->toArray();
                Arr::set($item, 'discount_total', (string)($orderItem->discount_total));
                Arr::set($item, 'tax_amount', $orderItem->tax_amount);
                Arr::set($item, 'post_title', $orderItem->post_title);

                if ($order->manual_discount_total) {
                    $subtotal = Arr::get($orderItem, 'subtotal');
                    $manualDiscount = ($subtotal * $order->manual_discount_total) / $order->subtotal;
                    Arr::set($item, 'manual_discount', $manualDiscount);
                }

                $items[] = CartHelper::generateCartItemCustomItem($item, $orderItem->quantity);
            }

            $instantCart = new Cart();
            $instantCart->cart_data = $items;
        }


        $instantCart->cart_group = 'instant';
        $instantCart->first_name = $order->customer->first_name;
        $instantCart->last_name = $order->customer->last_name;
        $instantCart->email = $order->customer->email;
        $instantCart->customer_id = $order->customer->customer_id;
        $instantCart->user_id = $order->customer->user_id;
        $instantCart->order_id = $order->id;
        $instantCart->cart_hash = md5('custom_payment_cart_' . wp_generate_uuid4() . time());

        $instantCart->checkout_data = [
            'is_locked' => 'yes',
            'disable_coupons' => 'yes',
            'custom_checkout' => 'yes',
            'custom_checkout_data' => [
                'coupon_discount_total' => $order->coupon_discount_total,
                'manual_discount_total' => $order->manual_discount_total,
                'discount_total' => $order->coupon_discount_total + $order->manual_discount_total,
                'shipping_total' => $order->shipping_total,
            ],
            '__cart_notices' => [
                [
                    'id' => 'custom_payment_notice',
                    'type' => 'info',
                    'content' => 'You are making payment for your order (#' . $order->uuid . ').',
                ]
            ]
        ];

        $instantCart->save();

        $cartHash = $instantCart->cart_hash;

        $checkoutUrl = add_query_arg(
            [
                'fct_cart_hash' => $cartHash,
            ],
            (new StoreSettings())->getCheckoutPage()
        );

        wp_redirect($checkoutUrl);
        exit();
    }
}