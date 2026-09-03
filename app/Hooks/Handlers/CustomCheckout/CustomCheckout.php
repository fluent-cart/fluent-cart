<?php

namespace FluentCart\App\Hooks\Handlers\CustomCheckout;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\ProductItemService;
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

        // Post-tax credits (plan upgrade) live inside manual_discount_total on the order
        // but must NOT be redistributed as item-level manual discounts on re-pay — that
        // would shrink the tax base. They are re-applied post-tax via checkout_data below.
        $prorateCredit = (int) Arr::get($order->config, 'prorate_credit', 0);
        $upgradeDiscount = (int) Arr::get($order->config, 'upgrade_discount', 0);
        $itemManualDiscountTotal = max(0, (int) $order->manual_discount_total - $prorateCredit - $upgradeDiscount);

        if ($order->type === Status::ORDER_TYPE_SUBSCRIPTION) {
            $orderItem = $order->order_items->filter(function ($item) {
                return !in_array($item->payment_type, ['signup_fee', 'fee']);
            })->first();

            if (!$orderItem) {
                die('Subscription order item not found!');
            }

            $subscriptionItemData = ProductItemService::getItem([
                'order_id'     => $orderItem->order_id,
                'product_id'   => $orderItem->post_id,
                'variation_id' => $orderItem->object_id,
            ]);

            if (!$subscriptionItemData || !$subscriptionItemData->variation) {
                die('Failed to load product data for custom checkout!');
            }

            $newItem = $subscriptionItemData->variation->toArray();
            $isCustom = $subscriptionItemData->is_custom;

            Arr::set($newItem, 'item_price', $orderItem->unit_price);

            // Keep the variation's other_info as-is (trial_days, signup_fee, repeat_interval, times).
            // The variation's other_info has the clean product-level config that CheckoutProcessor
            // expects when processing the cart.

            if ($isCustom) {
                Arr::set($newItem, 'post_title', $subscriptionItemData->variation->post_title);
                Arr::set($newItem, 'variation_type', $subscriptionItemData->variation->variation_type);
            } else {
                Arr::set($newItem, 'post_title', $subscriptionItemData->variation->product->post_title);
                Arr::set($newItem, 'variation_type', $subscriptionItemData->variation->product_detail->variation_type);
            }

            if ($order->coupon_discount_total > 0 || $itemManualDiscountTotal > 0) {
                Arr::set($newItem, 'coupon_discount', (int) $order->coupon_discount_total);
                Arr::set($newItem, 'manual_discount', $itemManualDiscountTotal);
            }

            $instantCart = CartHelper::generateCartFromCustomVariation($newItem, $orderItem->quantity);

        } else {
            $instantCart = new Cart();
            $instantCart->cart_data = static::buildOneTimeCartItems($order, $itemManualDiscountTotal);
        }

        $primaryBillingAddress = [];
        if ($order->billing_address) {
            $primaryBillingAddress = $order->billing_address
                ->getFormattedDataForCheckout('billing_');
        }
        $instantCart->cart_group = 'instant';
        $instantCart->first_name = $order->customer->first_name;
        $instantCart->last_name = $order->customer->last_name;
        $instantCart->email = $order->customer->email;
        $instantCart->customer_id = $order->customer->customer_id;
        $instantCart->user_id = $order->customer->user_id;
        $instantCart->order_id = $order->id;
        $instantCart->cart_hash = md5('custom_payment_cart_' . wp_generate_uuid4() . time());

        // Preserve original order fees so the custom payment checkout
        // shows exactly what was charged, not whatever the current filter returns
        $originalFees = [];
        $feeOrderItems = $order->order_items->filter(function ($item) {
            return $item->payment_type === 'fee';
        });

        foreach ($feeOrderItems as $feeItem) {
            $otherInfo = $feeItem->other_info ?? [];
            $originalFees[] = [
                'key'     => Arr::get($otherInfo, 'fee_key', ''),
                'label'   => $feeItem->title,
                'amount'  => (int) $feeItem->unit_price,
                'source'  => Arr::get($otherInfo, 'source', 'custom'),
                'taxable' => !empty(Arr::get($otherInfo, 'taxable')),
                'meta'    => Arr::get($otherInfo, 'meta', []),
            ];
        }

        $checkoutData = [
            'is_locked' => 'yes',
            'disable_coupons' => 'yes',
            'custom_checkout' => 'yes',
            'form_data' => $primaryBillingAddress,
            'fees' => $originalFees,
            'custom_checkout_data' => [
                'coupon_discount_total' => $order->coupon_discount_total,
                'manual_discount_total' => $itemManualDiscountTotal,
                'discount_total' => $order->coupon_discount_total + $itemManualDiscountTotal,
                'shipping_total' => $order->shipping_total,
            ],
            '__cart_notices' => [
                [
                    'id' => 'custom_payment_notice',
                    'type' => 'info',
                    'content' => sprintf(
                        /* translators: %1$s: the order UUID. */
                        __('You are making payment for your order (#%1$s).', 'fluent-cart'),
                        $order->uuid
                    ),
                ]
            ]
        ];

        if ($order->type === Status::ORDER_TYPE_RENEWAL) {
            $checkoutData['renewal_order'] = [
                'due_date' => $order->getMeta('due_date'),
            ];
        }

        if ($prorateCredit > 0) {
            $checkoutData['prorate_credit'] = [
                'amount' => $prorateCredit,
                'title'  => __('Prorate Credit', 'fluent-cart'),
            ];
        }

        if ($upgradeDiscount > 0) {
            $checkoutData['upgrade_discount'] = [
                'amount' => $upgradeDiscount,
                'title'  => __('Upgrade Discount', 'fluent-cart'),
            ];
        }

        $instantCart->checkout_data = $checkoutData;

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

    /**
     * Build cart items for a one-time (non-subscription) order re-pay, priced at the
     * order's own unit_price rather than the current catalogue price.
     */
    protected static function buildOneTimeCartItems(Order $order, $itemManualDiscountTotal)
    {
        $items = [];
        $productItems = $order->order_items->filter(function ($orderItem) {
            return !in_array($orderItem->payment_type, ['fee', 'signup_fee']);
        });

        foreach ($productItems as $orderItem) {
            $itemData = ProductItemService::getItem([
                'order_id'     => $orderItem->order_id,
                'product_id'   => $orderItem->post_id,
                'variation_id' => $orderItem->object_id,
            ]);
            if (!$itemData || !$itemData->variation) {
                die('Failed to load product data for custom checkout!');
            }
            $item = $itemData->variation->toArray();

            Arr::set($item, 'coupon_discount', (string)($orderItem->discount_total)); // item discount total is always coupon discount + manual discount
            Arr::set($item, 'tax_amount', $orderItem->tax_amount);
            Arr::set($item, 'post_title', $orderItem->post_title);
            Arr::set($item, 'variation_type', $itemData->variation->product_detail->variation_type);

            // Use the order item's unit_price, not the current catalogue price — the order
            // may carry a price agreed at purchase time (adjusted unit_price, since expired
            // catalogue price) that differs from what the product sells for today.
            Arr::set($item, 'item_price', $orderItem->unit_price);

            // For renewal invoices, also strip trial_days so CheckoutProcessor doesn't
            // apply trial (0) pricing on a renewal.
            if ($order->type === Status::ORDER_TYPE_RENEWAL) {
                $otherInfo = Arr::get($item, 'other_info', []);
                $otherInfo['trial_days'] = 0;
                Arr::set($item, 'other_info', $otherInfo);
            }

            if ($itemManualDiscountTotal) {
                // Use the order item's own stored split rather than proportionally
                // redistributing the order-level manual_discount_total — a manual
                // discount concentrated on one item must stay on that item, or this
                // reconstruction both keeps it there AND leaves a duplicate slice
                // behind as "coupon_discount" on it.
                $manualDiscount = max(0, (int) $orderItem->discount_total - (int) $orderItem->coupon_discount);
                Arr::set($item, 'manual_discount', $manualDiscount);
                Arr::set($item, 'coupon_discount', (int) $orderItem->coupon_discount);
            }

            $items[] = CartHelper::generateCartItemCustomItem($item, $orderItem->quantity);
        }

        return $items;
    }
}