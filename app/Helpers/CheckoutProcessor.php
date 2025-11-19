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

    // Store Settings
    private $storeSettings;

    private $couponDiscountTotal = 0;

    private $manualDiscountTotal = 0;

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

        $this->orderModel = \FluentCart\App\Models\Order::query()->create($orderData);

        if (!$this->orderModel) {
            return new \WP_Error('order_creation_failed', __('Failed to create order.', 'fluent-cart'));
        }

        // save order meta
        if (Arr::get($this->args, 'tax_id', 0)) {
            $this->orderModel->updateMeta('tax_id', Arr::get($this->args, 'tax_id', 0));
        }

        // Let's create the order items
        $normalOrderItems = array_filter($this->formattedIOrderItems, function ($item) {
            return $item['payment_type'] != 'signup_fee';
        });

        foreach ($normalOrderItems as $orderItem) {
            $orderItem['order_id'] = $this->orderModel->id;
            $orderItem['line_total'] = $orderItem['subtotal'] - $orderItem['discount_total'];
            $additionalItems = [];
            if ($orderItem['payment_type'] == 'subscription') {
                // this is a subscription type. We may have additional_items
                $additionalItems = Arr::get($orderItem, 'additional_items', []);
                unset($orderItem['additional_items']);
            }

            $createdItem = OrderItem::query()->create($orderItem);

            if ($additionalItems) {
                $additionalItemIds = [];
                foreach ($additionalItems as $additionalItem) {
                    $additionalItem['order_id'] = $this->orderModel->id;
                    $additionalItem['line_total'] = $additionalItem['subtotal'] - $additionalItem['discount_total'];
                    $mata = Arr::get($additionalItem, 'line_meta', []);
                    $mata['parent_item_id'] = $createdItem->id;
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
                $actions = Arr::get($cart->checkout_data, '__after_draft_created_actions__', []);
                if ($actions) {
                    foreach ($actions as $actioName) {
                        $actioName = (string)$actioName;
                        if (has_action($actioName)) {
                            do_action($actioName, [
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

        $oldShippingCharge = $prevOrder->shipping_total;
        $newShippingCharge = Arr::get($this->args, 'shipping_charge', 0);

        if ($newShippingCharge != $oldShippingCharge) {
            $orderData['shipping_total'] = $newShippingCharge;
            $orderData['total_amount'] = $prevOrder->total_amount + ($newShippingCharge - $oldShippingCharge);
        }

        $oldTaxTotal = $prevOrder->tax_total;
        $newTaxTotal = Arr::get($this->args, 'tax_total', 0);

        if ($newTaxTotal != $oldTaxTotal) {
            $orderData['tax_total'] = $newTaxTotal;
            if (Arr::get($this->args, 'tax_behavior', 0) == 1) {
                $orderData['total_amount'] = $prevOrder->total_amount + ($newTaxTotal - $oldTaxTotal);
            }
        }

        $oldShippingTax = $prevOrder->shipping_tax;
        $newShippingTax = Arr::get($this->args, 'shipping_tax', 0);

        if ($newShippingTax != $oldShippingTax) {
            $orderData['shipping_tax'] = $newShippingTax;
        }

        if ($isLocked) {
            $orderData = array_filter(Arr::only($orderData, ['note', 'payment_method', 'ip_address', 'customer_id', 'shipping_total', 'total_amount', 'tax_total', 'shipping_tax']));

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
                if ($orderItem['payment_type'] == 'subscription') {
                    // this is a subscription type. We may have additional_items
                    $additionalItems = Arr::get($orderItem, 'additional_items', []);
                    unset($orderItem['additional_items']);
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
                    foreach ($additionalItems as $additionalItem) {
                        $additionalItem['order_id'] = $this->orderModel->id;
                        $additionalItem['quantity'] = max(1, (int)$additionalItem['quantity']);
                        $additionalItem['line_total'] = $additionalItem['subtotal'] - $additionalItem['discount_total'];
                        $mata = Arr::get($additionalItem, 'line_meta', []);
                        $mata['parent_item_id'] = $createdItem->id;
                        $additionalItem['line_meta'] = $mata;
                        $childItem = OrderItem::query()->create($additionalItem);
                        $createdItemIds[] = $childItem->id;
                    }
                }
            }
            if ($taxTotal && !$this->orderModel->tax_total) {
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
                }
            } else {
                Subscription::query()->where('parent_order_id', $this->orderModel->id)->delete();
            }
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
            $existingTransaction->fill($transactionData);
            $existingTransaction->save();
            $this->transactionModel = $existingTransaction;
        } else {
            $this->transactionModel = \FluentCart\App\Models\OrderTransaction::query()->create($transactionData);
        }

        // insert the applied coupons
        $this->insertAppliedCoupons(Arr::get($this->args, 'applied_coupons', []), true, $prevOrder);

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
            $coupons = Coupon::query()->whereIn('code', $couponCodes)->get()
                ->keyBy('code')
                ->toArray();

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

            $subtotal = $unitPrice * $quantity;
            $args = Arr::get($cartItem, 'other_info', []);
            $paymentType = Arr::get($args, 'payment_type', 'default');

            $postTitle = Arr::get($cartItem, 'product_title', '');
            $variationTitle = Arr::get($cartItem, 'variation_title', '');

            if (!$postTitle) {
                $product = Product::query()->find(Arr::get($cartItem, 'post_id', 0));
                if ($product) {
                    $postTitle = $product->post_title;
                }
            }

            if (!$variationTitle) {
                $variation = ProductVariation::query()->find(Arr::get($cartItem, 'object_id', 0));
                if ($variation) {
                    $variationTitle = $variation->variation_title;
                }
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
                'other_info'       => $args,
                'line_meta'        => Arr::get($cartItem, 'line_meta', []),
            ];

            $childItem = null;
            if ($paymentType === 'subscription' && Arr::get($cartItem, 'other_info.signup_fee', 0)) {
                // We have a signup fee for subscription
                $signupFeeAmount = (int)Arr::get($cartItem, 'other_info.signup_fee', 0);

                $signupFeeTax = (int)Arr::get($cartItem, 'other_info.signup_fee_tax', 0);

                $childDiscountTotal = 0;
                $signupFeeSubtotal = $signupFeeAmount * $quantity;

                if ($discountTotal) {
                    // Distribute discount with signup fee and item
                    $childDiscountTotal = (float)($discountTotal / ($subtotal + $signupFeeSubtotal) * $signupFeeSubtotal);
                    $discountTotal -= $childDiscountTotal;
                }

                $childItem = [
                    'payment_type'     => 'signup_fee',
                    'post_id'          => $item['post_id'],
                    'object_id'        => $item['object_id'],
                    'post_title'       => $item['post_title'],
                    'title'            => __('Signup Fee', 'fluent-cart'),
                    'fulfillment_type' => $item['fulfillment_type'],
                    'quantity'         => $quantity,
                    'cost'             => 0,
                    'unit_price'       => $signupFeeAmount,
                    'subtotal'         => $signupFeeSubtotal,
                    'tax_amount'       => $signupFeeTax,
                    'shipping_charge'  => 0,
                    'discount_total'   => $childDiscountTotal,
                    'line_meta'        => Arr::get($cartItem, 'signup_fee_tax_config', []),
                ];

                $item['discount_total'] = $discountTotal;
                $item['additional_items'] = [$childItem];

                Arr::set($item, 'other_info.signup_fee', $signupFeeAmount);
                Arr::set($item, 'other_info.signup_discount', $childDiscountTotal);
            }

            $formattedItems[] = $item;
            if ($childItem) {
                $formattedItems[] = $childItem;
            }
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
        $taxBehavior = Arr::get($this->args, 'tax_behavior', 0);

        $recurringTotal = (int)$item['subtotal'];
        $recurringTax = (int)Arr::get($item, 'other_info.recurring_tax', 0);
        
        // Add shipping charges to recurring total for physical subscription products
        $shippingCharge = (int)Arr::get($this->args, 'shipping_charge', 0);
        $isPhysicalProduct = Arr::get($item, 'fulfillment_type') === 'physical';
        if ($isPhysicalProduct && $shippingCharge > 0) {
            $recurringTotal += $shippingCharge;
        }
        
        if ($taxBehavior === 1) {
            $recurringTotal += $recurringTax;
        }

        $signupFee = (int)Arr::get($signupFeeItem, 'subtotal', 0);

        // in case of discount applied 'tax_amount' is different than recurring tax ,
        $firstIterationTax = (int)Arr::get($item, 'tax_amount', 0) + $signupFeeTax;


        // Calculate recurring amount including shipping for physical products
        $recurringAmount = (int)$item['subtotal'];
        if ($isPhysicalProduct && $shippingCharge > 0) {
            $recurringAmount += $shippingCharge;
        }

        $subscriptionPricing = $this->convertToSubscriptionFormat([
            'initial_trial_days'  => Arr::get($item, 'other_info.trial_days', 0),
            'repeat_interval'     => Arr::get($item, 'other_info.repeat_interval', 'monthly'),
            'times'               => Arr::get($item, 'other_info.times', 0),
            'recurring_amount'    => $recurringAmount,
            'recurring_tax_total' => $recurringTax,
            'recurring_total'     => $recurringTotal,
            'tax_behavior'        => $taxBehavior,
            'signup_fee'          => $signupFee,
            'signup_fee_tax'      => $signupFeeTax,
            'first_iteration_tax' => $firstIterationTax,
            'total_discount'      => $item['discount_total'] + Arr::get($signupFeeItem, 'discount_total', 0)
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
                'currency'                => $this->orderData['currency']
            ]
        ];

        $this->subscriptionData = wp_parse_args($subscriptionPricing, $subscriptionItem);
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
            return $carry + $item['subtotal'];
        }, 0);

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
            'manual_discount_total' => $this->manualDiscountTotal,
            'coupon_discount_total' => $this->couponDiscountTotal,
            'shipping_tax'          => Arr::get($this->args, 'shipping_tax', 0),
            'shipping_total'        => Arr::get($this->args, 'shipping_charge', 0),
            'tax_total'             => Arr::get($this->args, 'tax_total', 0),
            'tax_behavior'          => Arr::get($this->args, 'tax_behavior', 0),
//            'total_amount'          => $this->orderTotals['total_amount'],
            'total_paid'            => 0,
            'total_refund'          => 0,
            'rate'                  => 1,
            'note'                  => Arr::get($this->args, 'note', ''),
            'ip_address'            => Arr::get($this->args, 'ip_address', ''),
            'config'                => [
                'user_tz'                   => Arr::get($this->args, 'user_tz', ''),
                'create_account_after_paid' => Arr::get($this->args, 'create_account_after_paid', 'no')
            ],
        ];

        $estimatedTaxTotal = $orderData['tax_behavior'] === 1 ? $orderData['tax_total'] : 0;
        $estimatedShippingTax = $orderData['tax_behavior'] === 1 ? $orderData['shipping_tax'] : 0;

        $totalAmount = $orderData['subtotal'] - $orderData['coupon_discount_total'] - $orderData['manual_discount_total'] + $orderData['shipping_total'] + $estimatedTaxTotal + $estimatedShippingTax;

        $orderData['total_amount'] = $totalAmount > 0 ? $totalAmount : 0;
        $this->orderData = $orderData;
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
            'trial_days'       => $trialDays,
            'repeat_interval'  => $standardInterval,
            'times'            => $times,
            'recurring_amount' => $recurringAmount,
        ];


        // Calculate signup fee logic
        if ($totalDiscount > 0) {

            $firstCycleCost = $recurringAmount + $signupFee - $totalDiscount;

            if ($firstCycleCost < $recurringAmount) {
                $adjustedTrialDays = Helper::calculateAdjustedTrialDaysForInterval($trialDays, $repeatInterval);

                $result['trial_days'] = $adjustedTrialDays;
                $result['is_trial_days_simulated'] = 'yes';
                $result['signup_fee'] = $firstCycleCost;
                $result['manage_setup_fee'] = 'yes';
                $result['times'] = $times > 0 ? $times - 1 : 0;
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
            if (Arr::get($inputData, 'tax_behavior', 0) == 1 && $firstIterationTax) {
                if ($result['trial_days'] > 0) {
                    $result['signup_fee'] += $firstIterationTax;
                } else {
                    $result['signup_fee'] += ($firstIterationTax - $recurringTax); // need to minus the recurring tax as it would add automatically to the payable amount as no trial days
                }
            }

        } else {
            $result['signup_fee'] = $signupFee;

            if (Arr::get($inputData, 'tax_behavior', 0) == 1) {
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
            'recurring_amount'        => $result['recurring_amount'],
            'recurring_tax_total'     => $recurringTax,
            'signup_fee'              => $result['signup_fee'] ?? 0,
        ];
    }
}
