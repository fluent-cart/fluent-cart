<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\Checkout\CheckoutApi;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Tax\TaxCalculator;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionManagementMode;

class CheckoutProcessor
{

    // Raw Data
    private $cartItems = [];
    private $args = [];

    // Order Related Data
    private $formattedIOrderItems = [];
    private $orderData = [];
    private $subscriptionData = [];

    //  Models

    private $orderModel;

    private $transactionModel;

    private $subscriptionModel;

    // Fee tracking
    private $feeTotal = 0;

    // Store Settings
    private $storeSettings;

    private $couponDiscountTotal = 0;

    private $manualDiscountTotal = 0;

    private $prorateCreditTotal = 0;

    private $upgradeDiscountTotal = 0;

    public function __construct($cartItems = [], $args = [])
    {
        $this->storeSettings = new StoreSettings();
        $this->cartItems = $cartItems;
        $this->args = $args;

        $this->prepareData();
    }

    private function prepareData()
    {
        $this->prepareOrderItems();
        $this->prepareOrderData();
        $this->prepareSubscriptionData();
    }

    public function createDraftOrder($prevOrder = null)
    {
        if ($prevOrder) {
            return $this->getAdjustedOrder($prevOrder);
        }

        $customerId = Arr::get($this->args, 'customer_id', '');
        if (!$customerId) {
            return new \WP_Error('customer_id_missing', __('Customer ID is required to create a draft order.', 'fluent-cart'));
        }

        $orderData = $this->orderData;
        $orderData['customer_id'] = $customerId;
        if (empty($orderData['currency'])) {
            $orderData['currency'] = $this->storeSettings->getCurrency();
        }

        if (empty($orderData['mode'])) {
            $orderData['mode'] = $this->storeSettings->get('order_mode', 'test');
        }

        if (empty($orderData['fee_total'])) {
            unset($orderData['fee_total']);
        }

        $this->orderModel = \FluentCart\App\Models\Order::query()->create($orderData);

        if (!$this->orderModel) {
            return new \WP_Error('order_creation_failed', __('Failed to create order.', 'fluent-cart'));
        }

        // save order meta
        if (Arr::get($this->args, 'tax_id', 0)) {
            $this->orderModel->updateMeta('tax_id', Arr::get($this->args, 'tax_id', 0));
        }

        // Store tax meta for mixed carts (tax_behavior=3) - used by payment gateways and renewals
        $this->persistTaxMeta();

        // Let's create the order items
        $normalOrderItems = array_filter($this->formattedIOrderItems, function ($item) {
            return $item['payment_type'] != 'signup_fee';
        });

        foreach ($normalOrderItems as $orderItem) {
            $orderItem['order_id'] = $this->orderModel->id;
            $orderItem['line_total'] = $orderItem['subtotal'] - $orderItem['discount_total'];
            $additionalItems = [];
            $bundleItems = [];
            if ($orderItem['payment_type'] == 'subscription') {
                // this is a subscription type. We may have additional_items
                $additionalItems = Arr::get($orderItem, 'additional_items', []);
                unset($orderItem['additional_items']);
            }

            if (Arr::get($orderItem, 'other_info.is_bundle_product', 'no') == 'yes') {
                $bundleItems = Arr::get($orderItem, 'bundle_items', []);
                unset($orderItem['bundle_items']);
            }

            if (!empty($orderItem['coupon_discount'])) {
                $lineMeta = Arr::get($orderItem, 'line_meta', []);
                $lineMeta['coupon_discount'] = (int) $orderItem['coupon_discount'];
                $orderItem['line_meta'] = $lineMeta;
            }

            $createdItem = OrderItem::query()->create($orderItem);

            if ($additionalItems) {
                $additionalItemIds = [];
                foreach ($additionalItems as $additionalItem) {
                    $additionalItem['order_id'] = $this->orderModel->id;
                    $additionalItem['line_total'] = Arr::get($additionalItem, 'subtotal', 0) - Arr::get($additionalItem, 'discount_total', 0);
                    $mata = Arr::get($additionalItem, 'line_meta', []);
                    $mata['parent_item_id'] = $createdItem->id;
                    if (!empty($additionalItem['coupon_discount'])) {
                        $mata['coupon_discount'] = (int) $additionalItem['coupon_discount'];
                    }
                    $additionalItem['line_meta'] = $mata;
                    $childItem = OrderItem::query()->create($additionalItem);
                    $additionalItemIds[] = $childItem->id;
                }

                $createdItem->fill([
                    'line_meta' => array_merge(
                        $createdItem->line_meta,
                        [
                            'additional_item_ids' => $additionalItemIds
                        ]
                    )
                ])->save();
            }

            if ($bundleItems) {
                $bundleItemIds = [];
                foreach ($bundleItems as $bundleItem) {
                    $bundleItem['order_id'] = $this->orderModel->id;
                    $bundleItem['line_total'] = Arr::get($bundleItem, 'subtotal', 0) - Arr::get($bundleItem, 'discount_total', 0);
                    $bundleItem['payment_type'] = 'bundle';
                    $meta = Arr::get($bundleItem, 'line_meta', []);
                    $meta['bundle_parent_item_id'] = $createdItem->id;
                    if (!empty($bundleItem['coupon_discount'])) {
                        $meta['coupon_discount'] = (int) $bundleItem['coupon_discount'];
                    }
                    $bundleItem['line_meta'] = $meta;
                    $bundleItem = OrderItem::query()->create($bundleItem);
                    $bundleItemIds[] = $bundleItem->id;
                }

                $createdItem->fill([
                    'line_meta' => array_merge(
                        $createdItem->line_meta,
                        [
                            'bundle_item_ids' => $bundleItemIds
                        ]
                    )
                ])->save();
            }
        }


        // Let's create the subscription if exists
        /*
         *  TODO : on renewal order we shouldn't create another subscription,
         *  basically on manual renew we just create a new sub on gateway and update the existing one on our database
         *  which automates the renewal cycle for the existing subscription
         *  ....created new sub remains on pending state, will create inconsistency
         * */

        if ($this->subscriptionData) {
            $subscriptionData = $this->subscriptionData;
            $subscriptionData['customer_id'] = $customerId;
            $subscriptionData['parent_order_id'] = $this->orderModel->id;

            $this->subscriptionModel = Subscription::query()->create($subscriptionData);
            $this->syncInitialCycleCounting();
        }

        // Let's create the transaction
        $transactionData = [
            'order_id'            => $this->orderModel->id,
            'order_type'          => $this->orderModel->type,
            'transaction_type'    => Status::TRANSACTION_TYPE_CHARGE,
            'subscription_id'     => $this->subscriptionModel ? $this->subscriptionModel->id : NULL,
            'payment_method'      => $this->orderModel->payment_method,
            'payment_mode'        => $this->orderModel->mode,
            'payment_method_type' => '',
            'status'              => Status::PAYMENT_PENDING,
            'currency'            => $this->orderModel->currency,
            'total'               => $this->orderModel->total_amount,
            'rate'                => 1,
            'meta'                => [],
        ];

        $this->transactionModel = \FluentCart\App\Models\OrderTransaction::query()->create($transactionData);

        // insert the applied coupons
        $this->insertAppliedCoupons(
            Arr::get($this->args, 'applied_coupons', []),
            false,
            $this->orderModel
        );

        $cartHash = Arr::get($this->args, 'cart_hash', '');
        if ($cartHash) {
            $cart = Cart::query()->where('cart_hash', $cartHash)->first();
            if ($cart) {
                $cart->order_id = $this->orderModel->id;
                $cart->customer_id = $this->orderModel->customer_id;
                $cart->stage = 'intended';
                $customer = $this->orderModel->customer;

                if ($customer) {
                    $cart->first_name = $customer->first_name;
                    $cart->last_name = $customer->last_name;
                    $cart->email = $customer->email;
                    $cart->user_id = $customer->user_id;
                }

                $cart->save();

                // Carry the traffic source onto the order while the cart still exists.
                // Carts are pruned on a schedule, so this is the last reliable point at
                // which the click that produced the sale can still be recovered.
                UtmHelper::addUtmToOrder(
                    $this->orderModel->id,
                    UtmHelper::resolveUtmData(UtmHelper::getUtmDataOfRequest(), $cart->utm_data),
                    $cart->cart_hash
                );

                $actions = Arr::get($cart->checkout_data, '__after_draft_created_actions__', []);
                if ($actions) {
                    foreach ($actions as $actionName) {
                        $actionName = (string)$actionName;
                        if (has_action($actionName)) {
                            do_action($actionName, [
                                'order' => $this->orderModel,
                                'cart'  => $cart,
                            ]);
                        }
                    }

                    // We are just renewing it!
                    $this->orderModel = \FluentCart\App\Models\Order::query()
                        ->where('id', $this->orderModel->id)
                        ->first();
                }
            }
        }

        // We are almost done!
        return $this->orderModel;
    }

    private function getAdjustedOrder(Order $prevOrder)
    {
        $isLocked = Arr::get($this->args, 'is_locked', false);

        $orderData = $this->orderData;
        $customerId = Arr::get($this->args, 'customer_id', '');
        if ($customerId) {
            $orderData['customer_id'] = $customerId;
        }

        if ($isLocked) {
            $orderData = array_filter(Arr::only($orderData, ['note', 'payment_method', 'ip_address', 'customer_id', 'shipping_total', 'total_amount', 'tax_total', 'fee_total', 'shipping_tax']), function ($value) {
                return $value !== null && $value !== '';
            });

            $prevConfig = $prevOrder->config;
            $prevConfig['user_tz'] = Arr::get($this->args, 'user_tz', '');
            $orderData['config'] = $prevConfig;
            $prevOrder->fill($orderData);
            $prevOrder->save();
        } else {
            $prevOrder->fill($orderData);
            $prevOrder->save();
        }

        $this->orderModel = $prevOrder;

        if (!$this->orderModel) {
            return new \WP_Error('order_creation_failed', __('Failed to create order.', 'fluent-cart'));
        }

        //for previous order if tax is enabled then we need to update the tax total
        $taxSettings = get_option('fluent_cart_tax_configuration_settings', []);
        $taxEnabled = Arr::get($taxSettings, 'enable_tax', 'no');

        if ($isLocked && $taxEnabled !== 'yes') {
            // Locked orders skip full item sync, but fee items must stay in sync with fee_total
            $this->syncFeeItems();

            // Load existing subscription so the transaction gets the correct subscription_id
            if ($this->orderModel->type === Status::ORDER_TYPE_SUBSCRIPTION) {
                $this->subscriptionModel = Subscription::query()
                    ->where('parent_order_id', $this->orderModel->id)
                    ->first();
            }
        }

        if (!$isLocked || $taxEnabled === 'yes') {
            // Let's create the order items
            $normalOrderItems = array_filter($this->formattedIOrderItems, function ($item) {
                return $item['payment_type'] != 'signup_fee';
            });

            $createdItemIds = [];
            $taxTotal = 0;
            foreach ($normalOrderItems as $orderItem) {
                $orderItem['order_id'] = $this->orderModel->id;
                $orderItem['quantity'] = max(1, (int)$orderItem['quantity']);
                $orderItem['line_total'] = $orderItem['subtotal'] - $orderItem['discount_total'];
                $taxTotal += $orderItem['tax_amount'];
                $additionalItems = [];
                $bundleItems = [];
                if ($orderItem['payment_type'] == 'subscription') {
                    // this is a subscription type. We may have additional_items
                    $additionalItems = Arr::get($orderItem, 'additional_items', []);
                    unset($orderItem['additional_items']);
                }

                if (Arr::get($orderItem, 'other_info.is_bundle_product', 'no') == 'yes') {
                    $bundleItems = Arr::get($orderItem, 'bundle_items', []);
                    unset($orderItem['bundle_items']);
                }

                $existingItem = OrderItem::query()->where('order_id', $this->orderModel->id)
                    ->whereNotIn('id', $createdItemIds)
                    ->first();

                if ($existingItem) {
                    $existingItem->fill($orderItem);
                    $existingItem->save();
                    $createdItem = $existingItem;
                } else {
                    $createdItem = OrderItem::query()->create($orderItem);
                }

                $createdItemIds[] = $createdItem->id;

                if ($additionalItems) {
                    $additionalItemIds = [];
                    foreach ($additionalItems as $additionalItem) {
                        $additionalItem['order_id'] = $this->orderModel->id;
                        $additionalItem['quantity'] = max(1, (int)$additionalItem['quantity']);
                        $additionalItem['line_total'] = $additionalItem['subtotal'] - $additionalItem['discount_total'];
                        $mata = Arr::get($additionalItem, 'line_meta', []);
                        $mata['parent_item_id'] = $createdItem->id;
                        $additionalItem['line_meta'] = $mata;
                        $childItem = OrderItem::query()->create($additionalItem);
                        $createdItemIds[] = $childItem->id;
                        $additionalItemIds[] = $childItem->id;

                    }

                    $createdItem->fill([
                        'line_meta' => array_merge(
                            $createdItem->line_meta,
                            [
                                'additional_item_ids' => $additionalItemIds
                            ]
                        )
                    ])->save();
                }

                if ($bundleItems) {
                    $bundleItemIds = [];
                    foreach ($bundleItems as $bundleItem) {
                        $bundleItem['order_id'] = $this->orderModel->id;
                        $bundleItem['quantity'] = Arr::get($orderItem, 'quantity', 1);
                        $bundleItem['line_total'] = Arr::get($bundleItem, 'subtotal', 0) - Arr::get($bundleItem, 'discount_total', 0);
                        $bundleItem['payment_type'] = 'bundle';
                        $meta['bundle_parent_item_id'] = $createdItem->id;
                        $bundleItem['line_meta'] = $meta;
                        $childItem = OrderItem::query()->create($bundleItem);
                        $createdItemIds[] = $childItem->id;
                        $bundleItemIds[] = $childItem->id;
                    }

                    $createdItem->fill([
                        'line_meta' => array_merge(
                            $createdItem->line_meta,
                            [
                                'bundle_item_ids' => $bundleItemIds
                            ]
                        )
                    ])->save();
                }
            }
            if ($taxTotal && !$this->orderModel->tax_total && !$isLocked) {
                $this->orderModel->tax_total = $taxTotal;
                $this->orderModel->total_amount += $taxTotal;
                $this->orderModel->save();
            }

            OrderItem::query()->where('order_id', $this->orderModel->id)->whereNotIn('id', $createdItemIds)->delete();

            // Let's create the subscription if exists
            if ($this->subscriptionData) {
                if ($this->orderModel->type === Status::ORDER_TYPE_RENEWAL) {
                    // it's a renewal order
                    $this->subscriptionModel = Subscription::query()
                        ->where('parent_order_id', $this->orderModel->parent_id)
                        ->first();
                } else {
                    $subscriptionData = $this->subscriptionData;
                    $subscriptionData['customer_id'] = $customerId;
                    $subscriptionData['parent_order_id'] = $this->orderModel->id;
                    $existingSubscription = Subscription::query()->where('parent_order_id', $this->orderModel->id)->first();
                    if ($existingSubscription) {
                        $existingSubscription->fill($subscriptionData);
                        $existingSubscription->save();
                        $this->subscriptionModel = $existingSubscription;
                    } else {
                        $this->subscriptionModel = Subscription::query()->create($subscriptionData);
                    }
                    $this->syncInitialCycleCounting();
                }
            } else {
                Subscription::query()->where('parent_order_id', $this->orderModel->id)->delete();
            }
        }

        // Reload the order to get fresh item data after updates
        $this->orderModel = $this->orderModel->fresh();

        $this->persistTaxMeta();

        // Let's create the transaction
        $transactionData = [
            'order_id'            => $this->orderModel->id,
            'order_type'          => $this->orderModel->type,
            'transaction_type'    => Status::TRANSACTION_TYPE_CHARGE,
            'subscription_id'     => $this->subscriptionModel ? $this->subscriptionModel->id : NULL,
            'payment_method'      => $this->orderModel->payment_method,
            'payment_mode'        => $this->orderModel->mode,
            'payment_method_type' => '',
            'status'              => Status::PAYMENT_PENDING,
            'currency'            => $this->orderModel->currency,
            'total'               => $this->orderModel->total_amount,
            'rate'                => 1,
            'meta'                => [],
        ];

        if ($isLocked && $prevOrder->parent_id) {
            $prevSubscription = Subscription::query()
                ->where('parent_order_id', $prevOrder->parent_id)
                ->first();

            if ($prevSubscription) {
                $transactionData['subscription_id'] = $prevSubscription->id;
            }
        }

        $existingTransaction = \FluentCart\App\Models\OrderTransaction::query()
            ->where('order_id', $this->orderModel->id)
            ->first();

        if ($existingTransaction) {
            // Retry vs duplicate for gateway idempotency (PaymentInstance::getIdempotencySeed):
            // re-submitting a pending transaction is a duplicate (keep attempt -> gateway
            // dedupes); re-submitting a FAILED one is a retry (bump attempt -> fresh seed,
            // never answered with the failed attempt's cached gateway response).
            $attempt = (int) Arr::get($existingTransaction->meta ?: [], 'payment_attempt', 0);
            if ($existingTransaction->status === Status::PAYMENT_FAILED) {
                $attempt++;
            }
            if ($attempt) {
                $transactionData['meta'] = ['payment_attempt' => $attempt];
            }

            $existingTransaction->fill($transactionData);
            $existingTransaction->save();
            $this->transactionModel = $existingTransaction;
        } else {
            $this->transactionModel = \FluentCart\App\Models\OrderTransaction::query()->create($transactionData);
        }

        // For locked carts (e.g. custom checkout), preserve the original order's applied coupon records.
        // Re-inserting would delete existing records and incorrectly increment the coupon use_count.
        if (!$isLocked) {
            $this->insertAppliedCoupons(Arr::get($this->args, 'applied_coupons', []), true, $prevOrder);
        }

        $cartHash = Arr::get($this->args, 'cart_hash', '');

        if ($cartHash) {
            $cart = Cart::query()->where('cart_hash', $cartHash)->first();
            if ($cart) {
                $cart->order_id = $this->orderModel->id;
                $cart->customer_id = $this->orderModel->customer_id;
                $cart->stage = 'intended';
                $cart->ip_address = $this->orderModel->ip_address;
                $cart->save();
            }
        }

        // We are almost done!
        return $this->orderModel;
    }

    private function persistTaxMeta()
    {
        $exclusiveTaxTotal = (int) Arr::get($this->args, 'exclusive_tax_total', 0);
        $storeTaxBehavior  = (int) Arr::get($this->args, 'store_tax_behavior', 0);
        $feeTax            = (int) Arr::get($this->args, 'fee_tax', 0);
        $feeTaxLines       = (array) Arr::get($this->args, 'fee_tax_lines', []);

        $this->orderModel->updateMeta('exclusive_tax_total', $exclusiveTaxTotal);
        $this->orderModel->updateMeta('store_tax_behavior', $storeTaxBehavior);
        $this->orderModel->updateMeta('fee_tax', $feeTax);

        if (!empty($feeTaxLines)) {
            $this->orderModel->updateMeta('fee_tax_lines', $feeTaxLines);
        } else {
            $this->orderModel->deleteMeta('fee_tax_lines');
        }
    }

    private function insertAppliedCoupons($appliedCoupons, $removeOlds = false, $order = null): void
    {
        if ($removeOlds) {
            $this->orderModel->appliedCoupons()->delete();
        }

        $couponCodes = Arr::pluck($appliedCoupons, 'code');

        $customerId = $this->orderModel->customer_id;
        if ($order instanceof Order) {
            $customerId = $order->customer_id;
        }

        if (!empty($couponCodes)) {
            $coupons = Coupon::query()->whereIn('code', $couponCodes)->get();

            /*
             * Resolve virtual (un-persisted) coupons so an AppliedCoupon row is written for
             * them too — the row stores coupon_id = null (the column is nullable) with the
             * code and computed discount, so it shows in the order's Coupons section like any
             * coupon. See DiscountService::applyCouponCodes() for the same filter.
             */
            $coupons = apply_filters('fluent_cart/coupon/resolve_coupons', $coupons, $couponCodes, [
                'order' => $this->orderModel,
            ]);

            $coupons = $coupons->keyBy('code')->toArray();

            foreach ($coupons as $code => &$coupon) {
                $coupon['coupon_id'] = $appliedCoupons[$code]['id'];
                $coupon['amount'] = $appliedCoupons[$code]['discount'];
                $coupon['customer_id'] = $customerId;
            }
            $this->orderModel->appliedCoupons()->createMany($coupons);

            Coupon::query()
                ->whereIn('code', $couponCodes)
                ->increment('use_count', 1);
        }
    }

    public function getTransaction()
    {
        return $this->transactionModel;
    }

    public function getOrder()
    {
        return $this->orderModel;
    }

    public function getSubscription()
    {
        return $this->subscriptionModel;
    }

    private function prepareOrderItems()
    {
        $formattedItems = [];

        foreach ($this->cartItems as $cartItem) {
            $unitPrice = (int)Arr::get($cartItem, 'unit_price', 0);
            $quantity = (int)Arr::get($cartItem, 'quantity', 1);

            $this->couponDiscountTotal += (int)Arr::get($cartItem, 'coupon_discount', 0);
            $this->manualDiscountTotal += (int)Arr::get($cartItem, 'manual_discount', 0);

            $discountTotal = (int)Arr::get($cartItem, 'manual_discount', 0) + (int)Arr::get($cartItem, 'coupon_discount', 0);
            $shippingCharge = (int)Arr::get($cartItem, 'shipping_charge', 0);

            $subtotal = (int) Arr::get($cartItem, 'subtotal', $unitPrice * $quantity);
            $args = Arr::get($cartItem, 'other_info', []);
            $paymentType = Arr::get($args, 'payment_type', 'default');

            $postTitle = Arr::get($cartItem, 'product_title', '');
            $variationTitle = Arr::get($cartItem, 'variation_title', '');

            if (!$postTitle) {
                if (Arr::get($cartItem, 'is_custom', false)) {
                    $postTitle = Arr::get($cartItem, 'post_title', '');
                } else {
                    $product = Product::query()->find(Arr::get($cartItem, 'post_id', 0));
                    if ($product) {
                        $postTitle = $product->post_title;
                    }
                }
            }

            if (!$variationTitle) {
                if (Arr::get($cartItem, 'is_custom', false)) {
                    $variationTitle = Arr::get($cartItem, 'title', '');
                } else {
                    $variation = ProductVariation::query()->find(Arr::get($cartItem, 'object_id', 0));
                    if ($variation) {
                        $variationTitle = $variation->variation_title;
                    }
                }
            }

            // Snapshot package dimensions into other_info for email/PDF rendering
            if (Arr::get($cartItem, 'fulfillment_type') === 'physical') {
                $packageSlug = Arr::get($args, 'package_slug', '');
                $package = Helper::getPackageBySlug($packageSlug);
                if ($package) {
                    $args['package_name']           = Arr::get($package, 'name', '');
                    $args['package_type']           = Arr::get($package, 'type', '');
                    $args['package_length']         = Arr::get($package, 'length', '');
                    $args['package_width']          = Arr::get($package, 'width', '');
                    $args['package_height']         = Arr::get($package, 'height', '');
                    $args['package_dimension_unit'] = Arr::get($package, 'dimension_unit', 'cm');
                    $args['package_weight']         = Arr::get($package, 'weight', 0);
                    $args['package_weight_unit']    = Arr::get($package, 'weight_unit', 'kg');
                }
            }

            // Carry the attribute snapshot onto the order item. It normally
            // arrives via the cart item's other_info; rebuild it here as a
            // fallback for items that reach checkout without one (instant
            // checkout, legacy carts).
            if (!isset($args['item_attributes'])) {
                $args['item_attributes'] = AttributeHelper::getProductItemAttributes(
                    Arr::get($cartItem, 'object_id', 0),
                    Arr::get($cartItem, 'post_id', 0)
                );
            }

            if (!isset($args['variation_type'])) {
                $args['variation_type'] = (string) Arr::get($cartItem, 'variation_type', '');
            }

            $item = [
                'payment_type'     => $paymentType,
                'post_id'          => Arr::get($cartItem, 'post_id'),
                'object_id'        => Arr::get($cartItem, 'object_id'),
                'post_title'       => $postTitle,
                'title'            => $variationTitle,
                'fulfillment_type' => Arr::get($cartItem, 'fulfillment_type', 'digital'),
                'quantity'         => $quantity,
                'cost'             => (int)Arr::get($cartItem, 'cost', 0),
                'unit_price'       => $unitPrice,
                'subtotal'         => $subtotal,
                'tax_amount'       => (int)Arr::get($cartItem, 'tax_amount', 0),
                'shipping_charge'  => $shippingCharge,
                'discount_total'   => $discountTotal,
                'coupon_discount'  => (int)Arr::get($cartItem, 'coupon_discount', 0),
                'other_info'       => $args,
                'line_meta'        => Arr::get($cartItem, 'line_meta', []),
            ];

            if (isset($cartItem['recurring_discounts'])) {
                $item['recurring_discounts'] = $cartItem['recurring_discounts'];
            }

            $childItem = null;
            if ($paymentType === 'subscription' && Arr::get($cartItem, 'other_info.signup_fee', 0)) {
                // We have a signup fee for subscription
                $signupFeeAmount = (int)Arr::get($cartItem, 'other_info.signup_fee', 0);

                $signupFeeTax = (int)Arr::get($cartItem, 'other_info.signup_fee_tax', 0);

                // Nest under tax_config — the same shape regular items and the admin
                // order path use. Readers keep a fallback for the legacy flat shape.
                $signupFeeTaxConfig = Arr::get($cartItem, 'signup_fee_tax_config', []);

                $childDiscountTotal = 0;
                $childCouponDiscount = 0;
                $signupFeeSubtotal = $signupFeeAmount * $quantity;
                $couponDiscount = $item['coupon_discount'];

                $hasTrialDays = Arr::get($cartItem, 'other_info.trial_days', 0) > 0;

                if ($discountTotal && !$hasTrialDays) {
                    $childDiscountTotal = (float)($discountTotal / ($subtotal + $signupFeeSubtotal) * $signupFeeSubtotal);
                    $discountTotal -= $childDiscountTotal;
                    $childCouponDiscount = (int) round($couponDiscount * $signupFeeSubtotal / ($subtotal + $signupFeeSubtotal));
                    $couponDiscount -= $childCouponDiscount;
                } elseif ($discountTotal && $hasTrialDays) { // if trial days , then discount should be applied on signup fee only
                    $childDiscountTotal = min($discountTotal, $signupFeeSubtotal);
                    $discountTotal = 0;
                    $childCouponDiscount = min($couponDiscount, $signupFeeSubtotal);
                    $couponDiscount = 0;
                }

                $childItem = [
                    'payment_type' => 'signup_fee',
                    'post_id' => $item['post_id'],
                    'object_id' => $item['object_id'],
                    'post_title' => $item['post_title'],
                    'title' => Arr::get($cartItem, 'other_info.signup_fee_name', __('Signup Fee', 'fluent-cart')),
                    'fulfillment_type' => $item['fulfillment_type'],
                    'quantity'         => $quantity,
                    'cost'             => 0,
                    'unit_price'       => $signupFeeAmount,
                    'subtotal'         => $signupFeeSubtotal,
                    'tax_amount'       => $signupFeeTax,
                    'shipping_charge'  => 0,
                    'discount_total'   => $childDiscountTotal,
                    'coupon_discount'  => $childCouponDiscount,
                    'line_meta'        => $signupFeeTaxConfig ? ['tax_config' => $signupFeeTaxConfig] : [],
                ];

                $item['discount_total'] = $discountTotal;
                $item['coupon_discount'] = $couponDiscount;
                $item['additional_items'] = [$childItem];

                Arr::set($item, 'other_info.signup_fee', $signupFeeAmount);
                Arr::set($item, 'other_info.signup_discount', $childDiscountTotal);
            }

            if (
                Arr::get($cartItem, 'other_info.is_bundle_product', 'no') == 'yes'
                || !empty(Arr::get($cartItem, 'other_info.bundle_child_ids', []))
            ) {
                $bundleItems = Arr::get($cartItem, 'child_variants', []);

                foreach ($bundleItems as $bundleItem) {
                    $bundleChildItem = [
                        'payment_type'     => 'bundle',
                        'post_id'          => Arr::get($bundleItem, 'post_id', 0),
                        'object_id'        => Arr::get($bundleItem, 'id', 0),
                        'post_title'       => Arr::get($bundleItem, 'post_title', ''),
                        'title'            => Arr::get($bundleItem, 'variation_title', ''),
                        'fulfillment_type' => Arr::get($bundleItem, 'fulfillment_type', 'digital'),
                        'quantity'         => $quantity,
                        'cost'             => 0,
                        'unit_price'       => 0,
                        'subtotal'         => 0,
                        'tax_amount'       => 0,
                        'shipping_charge'  => 0,
                        'discount_total'   => 0,
                        'other_info'       => [
                            'bundle_parent_product_id'   => Arr::get($cartItem, 'post_id', 0),
                            'bundle_parent_variation_id' => Arr::get($cartItem, 'object_id', 0)
                        ],
                    ];

                    //TODO: if bundleItem price is included on for the bundle, then we need to set the price to the bundleItem price
                    // if (Arr::get($bundleItem, 'other_info.is_price_included', 'no') == 'yes') {
                    //     $bundleChildItem['unit_price'] = Arr::get($bundleItem, 'unit_price', 0);
                    //     $bundleChildItem['subtotal'] = Arr::get($bundleItem, 'subtotal', 0);
                    //     $bundleChildItem['tax_amount'] = Arr::get($bundleItem, 'tax_amount', 0);
                    //     $bundleChildItem['shipping_charge'] = Arr::get($bundleItem, 'shipping_charge', 0);
                    //     $bundleChildItem['discount_total'] = Arr::get($bundleItem, 'discount_total', 0);
                    // }

                    $item['bundle_items'][] = $bundleChildItem;

                }

            }

            $formattedItems[] = $item;
            if ($childItem) {
                $formattedItems[] = $childItem;
            }
        }

        // Create order items for fees
        $fees = (array)Arr::get($this->args, 'fees', []);
        $this->feeTotal = 0;

        foreach ($fees as $fee) {
            $amount = (int)($fee['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $this->feeTotal += $amount;

            $formattedItems[] = [
                'payment_type'     => 'fee',
                'post_id'          => 0,
                'object_id'        => 0,
                'post_title'       => '',
                'title'            => $fee['label'] ?? '',
                'fulfillment_type' => 'digital',
                'quantity'         => 1,
                'cost'             => 0,
                'unit_price'       => $amount,
                'subtotal'         => $amount,
                'tax_amount'       => 0,
                'shipping_charge'  => 0,
                'discount_total'   => 0,
                'other_info'       => [
                    'payment_type' => 'fee',
                    'fee_key'      => $fee['key'] ?? '',
                    'source'       => $fee['source'] ?? 'custom',
                    'taxable'      => !empty($fee['taxable']),
                    'meta'         => $fee['meta'] ?? [],
                ],
                'line_meta'        => [],
            ];
        }

        $this->formattedIOrderItems = $formattedItems;
    }

    private function prepareSubscriptionData()
    {
        $subscriptionItems = array_filter($this->formattedIOrderItems, function ($item) {
            return $item['payment_type'] === 'subscription';
        });

        $signupFeeItems = array_filter($this->formattedIOrderItems, function ($item) {
            return $item['payment_type'] === 'signup_fee';
        });

        if (!$subscriptionItems) {
            return;
        }

        if (count($subscriptionItems) > 1) {
            return;
        }

        $item = reset($subscriptionItems);
        $signupFeeItem = reset($signupFeeItems) ?? [];
        $signupFeeTax = (int)Arr::get($signupFeeItem, 'tax_amount', 0);
        $taxBehavior = (int)Arr::get($this->args, 'tax_behavior', 0);

        $recurringTotal = (int)$item['subtotal'];
        $recurringTax = (int)Arr::get($item, 'other_info.recurring_tax', 0);

        $recurringDiscountAmount = (int)Arr::get($item, 'recurring_discounts.amount', 0);

        if ($recurringDiscountAmount && $recurringDiscountAmount > 0) {
            $recurringTotal -= $recurringDiscountAmount;
        }

        // Add shipping charges (and tax) to recurring total for physical subscription products
        $shippingCharge = (int)Arr::get($this->args, 'shipping_charge', 0);
        $isPhysicalProduct = Arr::get($item, 'fulfillment_type') === 'physical';
        if ($isPhysicalProduct && $shippingCharge > 0) {
            $recurringTotal += $shippingCharge;
            $shippingTax = (int)Arr::get($this->args, 'shipping_tax', 0);
            if ($shippingTax > 0) {
                $storeTaxBehavior = (int)Arr::get($this->args, 'store_tax_behavior', $taxBehavior);
                if ($taxBehavior === 1 || ($taxBehavior === 3 && $storeTaxBehavior === 1)) {
                    $recurringTotal += $shippingTax;
                }
            }
        }

        $itemInclusive = (bool) Arr::get($item, 'line_meta.tax_config.inclusive', false);
        if ($taxBehavior === 1 || ($taxBehavior === 3 && !$itemInclusive)) {
            $recurringTotal += $recurringTax;
        }

        $signupFee = (int)Arr::get($signupFeeItem, 'subtotal', 0);

        // in case of discount applied 'tax_amount' is different than recurring tax ,
        $firstIterationTax = (int)Arr::get($item, 'tax_amount', 0) + $signupFeeTax;

        // Calculate recurring amount including shipping for physical products
        $recurringAmount = (int)$item['subtotal'];
        if ($isPhysicalProduct && $shippingCharge > 0) {
            $recurringAmount += $shippingCharge;
            $shippingTaxForFirst = (int)Arr::get($this->args, 'shipping_tax', 0);
            if ($shippingTaxForFirst > 0) {
                $storeTaxBehaviorForFirst = (int)Arr::get($this->args, 'store_tax_behavior', $taxBehavior);
                if ($taxBehavior === 1 || ($taxBehavior === 3 && $storeTaxBehaviorForFirst === 1)) {
                    $firstIterationTax += $shippingTaxForFirst;
                }
            }
        }

        $discountTotal = $item['discount_total'] + Arr::get($signupFeeItem, 'discount_total', 0) + $this->prorateCreditTotal + $this->upgradeDiscountTotal;
        $subscriptionPricing = $this->convertToSubscriptionFormat([
            'initial_trial_days'  => Arr::get($item, 'other_info.trial_days', 0),
            'repeat_interval'     => Arr::get($item, 'other_info.repeat_interval', 'monthly'),
            'times'               => Arr::get($item, 'other_info.times', 0),
            'recurring_amount'    => $recurringAmount,
            'recurring_tax_total' => $recurringTax,
            'recurring_total'     => $recurringTotal,
            'tax_behavior'        => $taxBehavior,
            'line_meta'           => Arr::get($item, 'line_meta', []),
            'signup_fee'          => $signupFee,
            'signup_fee_tax'      => $signupFeeTax,
            'first_iteration_tax' => $firstIterationTax,
            'is_recurring_coupon' => Arr::get($item, 'is_recurring_coupon', 'no'),
            'total_discount'      => $discountTotal
        ]);

        // removable upon discussion
        $subscriptionItem = [
            'product_id'             => $item['post_id'],
            'current_payment_method' => Arr::get($this->orderData, 'payment_method'),
            'object_id'              => $item['object_id'],
            'recurring_tax_total'    => 0,
            'recurring_total'        => $recurringTotal, //use price not line_total to ignore discount
            'item_name'              => $item['post_title'] . ' - ' . $item['title'],
            'bill_count'             => 0,
            'quantity'               => 1,
            'variation_id'           => Arr::get($item, 'object_id', 0),
            'status'                 => Status::SUBSCRIPTION_PENDING,
            'config'                 => [
                'is_trial_days_simulated' => Arr::get($subscriptionPricing, 'is_trial_days_simulated', 'no'),
                'currency'                => $this->orderData['currency'],
                // Snapshot the variant attribute map + variation type from the order
                // item so the subscription carries the same pa_* set behind its item_name.
                'item_attributes'         => Arr::get($item, 'other_info.item_attributes', []),
                'variation_type'          => Arr::get($item, 'other_info.variation_type', '')
            ]
        ];

        // if recurring coupon is applied, we need to subtract the total discount from the recurring total
        if (Arr::get($item, 'is_recurring_coupon', 'no') === 'yes') {
            $subscriptionItem['recurring_total'] -= $discountTotal;
        }

        $subscriptionData = wp_parse_args($subscriptionPricing, $subscriptionItem);
        $paymentMethod = Arr::get($this->orderData, 'payment_method', '');

        $collectionMethod = apply_filters('fluent_cart/subscription_collection_method_' . $paymentMethod, $this->determineCollectionMethod());

        // A filter can hand back anything, but `system` only means something on a
        // gateway that can charge a saved payment method.
        $subscriptionData['collection_method'] = SubscriptionManagementMode::sanitizeCollectionMethod(
            $collectionMethod,
            GatewayManager::getInstance()->get($paymentMethod)
        );

        // Stamp store-managed origin durably on the subscription. Gateways consult
        // the stamp (not the current store setting) before converting a manual
        // subscription to automatic, so switching the mode back to gateway-managed
        // later never flips subscriptions born under store-managed.
        if (in_array($subscriptionData['collection_method'], ['manual', 'system'], true) && SubscriptionManagementMode::isStoreManaged()) {
            $subscriptionConfig = Arr::get($subscriptionData, 'config', []);
            $subscriptionConfig[SubscriptionManagementMode::CONFIG_KEY] = SubscriptionManagementMode::STORE_MANAGED;
            $subscriptionData['config'] = $subscriptionConfig;
        }

        $this->subscriptionData = $subscriptionData;
    }

    private function determineCollectionMethod(): string
    {
        if (SubscriptionManagementMode::isStoreManaged()) {
            $paymentMethod = Arr::get($this->orderData, 'payment_method', '');

            return SubscriptionManagementMode::resolveCollectionMethodFor(
                GatewayManager::getInstance()->get($paymentMethod)
            );
        }

        $paymentMethod = Arr::get($this->orderData, 'payment_method', '');
        $gateway = GatewayManager::getInstance()->get($paymentMethod);

        if ($gateway && $gateway->has('subscriptions')) {
            return 'automatic';
        }

        return 'manual';
    }

    private function prepareOrderData()
    {
        $hasPhysical = array_filter($this->formattedIOrderItems, function ($item) {
            return $item['fulfillment_type'] === 'physical';
        });

        $hasSubscription = array_filter($this->formattedIOrderItems, function ($item) {
            return $item['payment_type'] === 'subscription';
        });

        $itemsSubtotal = array_reduce($this->formattedIOrderItems, function ($carry, $item) {
            if (Arr::get($item, 'other_info.trial_days', 0) > 0) {
                return $carry;
            }
            // Fee items are tracked separately via fee_total
            if (Arr::get($item, 'payment_type') === 'fee') {
                return $carry;
            }
            return $carry + $item['subtotal'];
        }, 0);

        $taxBehavior = (int) Arr::get($this->args, 'tax_behavior', 0);
        $storeTaxBehavior = (int) Arr::get($this->args, 'store_tax_behavior', $taxBehavior);
        $exclusiveTaxTotal = (int) Arr::get($this->args, 'exclusive_tax_total', 0);
        $feeTax = (int) Arr::get($this->args, 'fee_tax', 0);

        // Roll fee tax into fee_total for exclusive scenarios — gateways use fee_total as source of truth.
        if ($feeTax && ($taxBehavior === 1 || ($taxBehavior === 3 && $storeTaxBehavior === 1))) {
            $this->feeTotal += $feeTax;
        }

        $this->prorateCreditTotal = (int) Arr::get($this->args, 'prorate_credit', 0);
        // Upgrade-path discount is a post-tax adjustment like the prorate credit: it does
        // not reduce the taxable base (tax args were computed on the full price), it only
        // reduces the payable total via manual_discount_total below.
        $this->upgradeDiscountTotal = (int) Arr::get($this->args, 'upgrade_discount', 0);

        $orderData = [
            'status'                => Status::ORDER_ON_HOLD,
            'fulfillment_type'      => $hasPhysical ? Status::FULFILLMENT_TYPE_PHYSICAL : Status::FULFILLMENT_TYPE_DIGITAL,
            'type'                  => $hasSubscription ? Status::ORDER_TYPE_SUBSCRIPTION : Status::ORDER_TYPE_PAYMENT, // revisit this on manual renewal
            'mode'                  => $this->storeSettings->get('order_mode', 'test'),
            'shipping_status'       => $hasPhysical ? 'unshipped' : '',
            'customer_id'           => '',
            'payment_method'        => Arr::get($this->args, 'payment_method', ''),
            'payment_status'        => Status::PAYMENT_PENDING,
            'payment_method_title'  => '',
            'currency'              => $this->storeSettings->get('currency'),
            'subtotal'              => $itemsSubtotal,
            'discount_tax'          => 0,
            'manual_discount_total' => $this->manualDiscountTotal + $this->prorateCreditTotal + $this->upgradeDiscountTotal,
            'coupon_discount_total' => $this->couponDiscountTotal,
            'shipping_tax'          => Arr::get($this->args, 'shipping_tax', 0),
            'shipping_total'        => Arr::get($this->args, 'shipping_charge', 0),
            'fee_total'             => $this->feeTotal,
            'tax_total'             => Arr::get($this->args, 'tax_total', 0),
            'tax_behavior'          => $taxBehavior,
            //            'total_amount'          => $this->orderTotals['total_amount'],
            'total_paid'            => 0,
            'total_refund'          => 0,
            'rate'                  => 1,
            'note'                  => Arr::get($this->args, 'note', ''),
            'ip_address'            => Arr::get($this->args, 'ip_address', ''),
            'config'                => [
                'user_tz'                   => Arr::get($this->args, 'user_tz', ''),
                'create_account_after_paid' => Arr::get($this->args, 'create_account_after_paid', 'no'),
                'shipping_method_id'        => Arr::get($this->args, 'shipping_method_id', 0),
                'shipping_method_title'     => Arr::get($this->args, 'shipping_method_title', ''),
                'prorate_credit'            => $this->prorateCreditTotal,
                'upgrade_discount'          => $this->upgradeDiscountTotal,
            ],
        ];

        if ($taxBehavior === 1) {
            // Pure exclusive: fee_tax is already in fee_total; exclude it here to avoid double-count.
            $estimatedTaxTotal = $orderData['tax_total'] - $feeTax;
            $estimatedShippingTax = $orderData['shipping_tax'];
        } elseif ($taxBehavior === 3) {
            // Mixed: fee_tax rolled into fee_total for exclusive store; shipping still additive.
            $estimatedTaxTotal = $exclusiveTaxTotal;
            $estimatedShippingTax = ($storeTaxBehavior === 1) ? $orderData['shipping_tax'] : 0;
        } else {
            // Inclusive (2) or reverse-charge (0): nothing to add to total; tax is in item prices.
            $estimatedTaxTotal = 0;
            $estimatedShippingTax = 0;
            if ($taxBehavior !== 2) {
                // Reverse-charge (0): zero stored columns — no tax applies.
                // Inclusive (2): keep orderData values so reporting surfaces can read them.
                $orderData['tax_total'] = 0;
                $orderData['shipping_tax'] = 0;
            }
        }

        $totalAmount = $orderData['subtotal']
            - $orderData['coupon_discount_total']
            - $orderData['manual_discount_total']
            + $orderData['fee_total']
            + $orderData['shipping_total']
            + $estimatedTaxTotal
            + $estimatedShippingTax;

        $orderData['total_amount'] = $totalAmount > 0 ? $totalAmount : 0;

        /**
         * Filter the prepared order data before it is used for order creation.
         *
         * This runs after FluentCart calculates totals, so plugins can adjust
         * currency, rate, totals, config, mode, or any other order field before
         * the order model, transaction, and subscription are derived from it.
         *
         * @param array $orderData Prepared order data array.
         * @param array $context {
         *     Additional context for the filter.
         *
         *     @type array $items Formatted order items with prices and quantities.
         *     @type array $args  Checkout arguments: customer data, payment method,
         *                        shipping, tax, coupons, fees, and IP data.
         * }
         */
        $orderData = apply_filters('fluent_cart/checkout/order_data', $orderData, [
            'items' => $this->formattedIOrderItems,
            'args'  => $this->args,
        ]);

        $this->orderData = $orderData;
    }

    private function syncFeeItems()
    {
        $orderId = $this->orderModel->id;

        // Remove existing fee items
        OrderItem::query()->where('order_id', $orderId)->where('payment_type', 'fee')->delete();

        // Create new fee items from formatted items
        $feeItems = array_filter($this->formattedIOrderItems, function ($item) {
            return $item['payment_type'] === 'fee';
        });

        foreach ($feeItems as $feeItem) {
            $feeItem['order_id'] = $orderId;
            $feeItem['quantity'] = 1;
            $feeItem['line_total'] = $feeItem['subtotal'] - $feeItem['discount_total'];
            OrderItem::query()->create($feeItem);
        }
    }

    /**
     * @param $inputData
     * @return array
     */
    private function convertToSubscriptionFormat($inputData)
    {

        /**
         *  Normal Subscription $100/month
         *  {
         *      'trial_days' => 0,
         *      'repeat_interval' => 'month',
         *      'times' => 0, // 0 means unlimited
         *      'recurring_amount' => 100,
         *      'signup_fee' => 0
         *  }
         *
         * 30 Days Trial $100/month
         * {
         *     'trial_days' => 30,
         *     'repeat_interval' => 'month',
         *     'times' => 0, // 0 means unlimited
         *     'recurring_amount' => 100,
         * }
         *
         * $100/month - 30 days Trial with $40 signup fee
         *  {
         *      'trial_days' => 30,
         *      'repeat_interval' => 'month',
         *      'times' => 0, // 0 means unlimited
         *      'recurring_amount' => 100,
         *      'signup_fee' => 40
         *  }
         *
         * $100 / month with $40 signup fee
         *   {
         *       'trial_days' => 0,
         *       'repeat_interval' => 'month',
         *       'times' => 0, // 0 means unlimited
         *       'recurring_amount' => 100,
         *       'signup_fee' => 40
         *   }
         *
         * $100 per month but 30% discount on first month
         *    {
         *        'trial_days' => 30,
         *        'repeat_interval' => 'month',
         *        'times' => 0, // 0 means unlimited
         *        'recurring_amount' => 100,
         *        'signup_fee' => 70
         *    }
         *
         *  $100 per month but 50% extra on first month
         *     {
         *         'trial_days' => 0,
         *         'repeat_interval' => 'month',
         *         'times' => 0, // 0 means unlimited
         *         'recurring_amount' => 100,
         *         'signup_fee' => 50
         *     }
         *
         *   $100 per month and 1 month trial and service_fee = $50
         *      {
         *          'trial_days' => 30,
         *          'repeat_interval' => 'month',
         *          'times' => 0, // 0 means unlimited
         *          'recurring_amount' => 100,
         *          'signup_fee' => 50
         *      }
         *
         *
         *    $100 per month, signup fee $200 - First Month 50% discount
         *
         *    $first Month = 100 + 200 = 300 - 50% discount = 150
         *       {
         *           'trial_days' => 30,
         *           'repeat_interval' => 'month',
         *           'times' => 0, // 0 means unlimited
         *           'recurring_amount' => 100,
         *           'signup_fee' => 150
         *       }
         *
         *      // prefered when signup_fee > recurring_amount
         *      {
         *            'trial_days' => 0,
         *            'repeat_interval' => 'month',
         *            'times' => 0, // 0 means unlimited
         *            'recurring_amount' => 100,
         *            'signup_fee' => 50
         *      }
         */


        // Extract and validate input data
        $trialDays = (int)($inputData['initial_trial_days'] ?? 0);
        $repeatInterval = strtolower(trim($inputData['repeat_interval'] ?? 'yearly'));
        $times = (int)($inputData['times'] ?? 0);
        $recurringAmount = (int)($inputData['recurring_amount'] ?? 0);
        $recurringTax = (int)($inputData['recurring_tax_total'] ?? 0);
        $signupFee = (int)($inputData['signup_fee'] ?? 0);
        $signupFeeTax = (int)($inputData['signup_fee_tax'] ?? 0);
        $firstIterationTax = (int)($inputData['first_iteration_tax'] ?? 0);
        $totalDiscount = (int)($inputData['total_discount'] ?? 0);

        // Determine if THIS subscription item is tax-inclusive (for behavior=3 mixed carts)
        $taxBehavior = (int) Arr::get($inputData, 'tax_behavior', 0);
        $itemInclusive = (bool) Arr::get($inputData, 'line_meta.tax_config.inclusive', false);
        $isAdditiveTax = ($taxBehavior === 1) || ($taxBehavior === 3 && !$itemInclusive);

        // Validate repeat_interval
        $validIntervals = array_keys(Helper::getAvailableSubscriptionIntervalMaps());

        if (!in_array($repeatInterval, $validIntervals)) {
            $repeatInterval = 'yearly';
        }

        // Convert repeat_interval to standard format
        $intervalMap = Helper::getAvailableSubscriptionIntervalMaps();
        $standardInterval = Helper::translateIntervalToStandardFormat($repeatInterval);

        // Calculate trial days based on interval


        // Initialize result array
        $result = [
            'trial_days'      => $trialDays,
            'repeat_interval' => $standardInterval,
            'times'           => $times,
        ];


        // Calculate signup fee logic
        if ($totalDiscount > 0) {
            // case: discount applied on subscription with trial days and have signup_fee, otherise discount can't be applied on subscription with trial days.
            if ($trialDays > 0 && $signupFee > 0) {
                $result['signup_fee'] = max(0, $signupFee - $totalDiscount);
                $result['manage_setup_fee'] = 'yes';

                if ($isAdditiveTax && $firstIterationTax) {
                    $result['signup_fee'] += $firstIterationTax;
                }
            } else {
                $firstCycleCost = $recurringAmount + $signupFee - $totalDiscount;

                if (Arr::get($inputData, 'is_recurring_coupon', 'no') === 'yes') {
                    $recurringAmount -= $totalDiscount; // as now discount applied on recurring amount
                }

                if ($firstCycleCost < $recurringAmount) {
                    $adjustedTrialDays = Helper::calculateAdjustedTrialDaysForInterval($trialDays, $repeatInterval);

                    $result['trial_days'] = $adjustedTrialDays;
                    $result['is_trial_days_simulated'] = 'yes';
                    $result['signup_fee'] = $firstCycleCost;
                    $result['manage_setup_fee'] = 'yes';
                    // bill_times stays the full installment count. The simulated trial cycle IS the
                    // first installment (charged as one-time payment / free when 100% discounted);
                    // gateways derive the remaining remote cycles from is_trial_days_simulated.
                } else if ($firstCycleCost > $recurringAmount) {
                    $result['trial_days'] = 0;
                    $result['signup_fee'] = $firstCycleCost - $recurringAmount;
                    $result['manage_setup_fee'] = 'yes';
                } else if ($firstCycleCost == $recurringAmount) {
                    $result['trial_days'] = 0;
                    $result['signup_fee'] = 0;
                    $result['manage_setup_fee'] = 'no';
                }

                // only the signup fee is adjustable on our system, so we can adjust that to our needs
                if ($isAdditiveTax && $firstIterationTax) {
                    if ($result['trial_days'] > 0) {
                        $result['signup_fee'] += $firstIterationTax;
                    } else {
                        $result['signup_fee'] += ($firstIterationTax - $recurringTax); // need to minus the recurring tax as it would add automatically to the payable amount as no trial days
                    }
                }
            }

        } else {
            $result['signup_fee'] = $signupFee;

            if ($isAdditiveTax) {
                if ($signupFeeTax) {
                    $result['signup_fee'] += $signupFeeTax;
                }
            }
        }


        $result['repeat_interval'] = array_flip($intervalMap)[$result['repeat_interval']] ?? 'yearly';


        return [
            'billing_interval'        => $result['repeat_interval'],
            'bill_times'              => $result['times'],
            'trial_days'              => $result['trial_days'],
            'is_trial_days_simulated' => Arr::get($result, 'is_trial_days_simulated', 'no'),
            'recurring_amount'        => $recurringAmount,
            'recurring_tax_total'     => $recurringTax,
            'signup_fee'              => $result['signup_fee'] ?? 0,
        ];
    }

    /**
     * bill_count is derived from counting total > 0 CHARGE transactions linked to
     * the subscription (see syncSubscriptionStates / getRequiredBillTimes), which
     * can't tell "this was a billed cycle" from "this was something else
     * charged alongside it." Two corrections needed only at initial checkout —
     * is_trial_days_simulated alone can't be used at runtime because
     * payment-method switching also sets that flag:
     *
     * - Simulated trial, $0 first cycle: consumes a cycle but produces no
     *   total > 0 transaction — add billed_cycles_offset so it still counts.
     * - Real trial with a signup fee: the initial charge is the signup fee only
     *   (the recurring item isn't billed yet), but it IS a total > 0 transaction
     *   linked to the subscription — mark billed_cycles_deduction so it does
     *   NOT count as a cycle.
     */
    private function syncInitialCycleCounting()
    {
        if (!$this->subscriptionModel) {
            return;
        }

        $isSimulated = Arr::get($this->subscriptionData, 'config.is_trial_days_simulated', 'no') === 'yes';
        $trialDays = (int)Arr::get($this->subscriptionData, 'trial_days', 0);
        $billTimes = (int)$this->subscriptionModel->bill_times;
        $orderTotal = (int)$this->orderModel->total_amount;

        // signup_fee <= 0 (not just == 0): prorate/upgrade credit can push the
        // first cycle cost negative — still a free first cycle for counting
        $isFreeFirstCycle = $isSimulated
            && $billTimes > 0
            && (int)$this->subscriptionModel->signup_fee <= 0
            && !$orderTotal;

        if ($isFreeFirstCycle) {
            $this->subscriptionModel->updateMeta('billed_cycles_offset', 1);
        } else {
            $this->subscriptionModel->deleteMeta('billed_cycles_offset');
        }

        $isRealTrialWithCharge = !$isSimulated
            && $trialDays > 0
            && $billTimes > 0
            && $orderTotal > 0;

        if ($isRealTrialWithCharge) {
            $this->subscriptionModel->updateMeta('billed_cycles_deduction', 1);
        } else {
            $this->subscriptionModel->deleteMeta('billed_cycles_deduction');
        }
    }
}
