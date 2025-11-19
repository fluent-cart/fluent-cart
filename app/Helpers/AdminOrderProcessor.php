<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Helpers\Helper;

class AdminOrderProcessor
{
    private $args = [];
    private $checkoutItems = [];

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

    public function __construct($checkoutItems = [], $args = [])
    {
        $this->storeSettings = new StoreSettings();
        $this->checkoutItems = $checkoutItems;
        $this->args = $args;

        $this->prepareData();
    }

    private function prepareData()
    {
        $this->prepareOrderItems();
        $this->prepareOrderData();
        $this->prepareSubscriptionData();
    }

    private function prepareOrderItems()
    {
        $formattedItems = [];

        foreach ($this->checkoutItems as $checkoutItem) {
            $unitPrice = (int)Arr::get($checkoutItem, 'unit_price', 0);
            $quantity = (int)Arr::get($checkoutItem, 'quantity', 1);

            //TODO:  right now discount total from admin only comes from coupon applied discount, is same as coupon_discount
            $this->couponDiscountTotal += (int)Arr::get($checkoutItem, 'discount_total', 0);
            $this->manualDiscountTotal += (int)Arr::get($checkoutItem, 'manual_discount', 0);

            $discountTotal = (int)Arr::get($checkoutItem, 'manual_discount', 0) + (int)Arr::get($checkoutItem, 'discount_total', 0);

            $shippingCharge = (int)Arr::get($checkoutItem, 'shipping_charge', 0);
            $tax = 0;
            $subtotal = $unitPrice * $quantity;
            $args = Arr::get($checkoutItem, 'other_info', []);
            $paymentType = Arr::get($args, 'payment_type', 'default');

            $postTitle = Arr::get($checkoutItem, 'product_title', '');
            $variationTitle = Arr::get($checkoutItem, 'variation_title', '');

            if (!$postTitle) {
                $product = Product::query()->find(Arr::get($checkoutItem, 'post_id', 0));
                if ($product) {
                    $postTitle = $product->post_title;
                }
            }

            if (!$variationTitle) {
                $variation = ProductVariation::query()->find(Arr::get($checkoutItem, 'object_id', 0));
                if ($variation) {
                    $variationTitle = $variation->variation_title;
                }
            }


            $item = [
                'payment_type'     => $paymentType,
                'post_id'          => Arr::get($checkoutItem, 'post_id'),
                'object_id'        => Arr::get($checkoutItem, 'object_id'),
                'post_title'       => $postTitle,
                'title'            => $variationTitle,
                'fulfillment_type' => Arr::get($checkoutItem, 'fulfillment_type', 'digital'),
                'quantity'         => $quantity,
                'cost'             => (int)Arr::get($checkoutItem, 'cost', 0),
                'unit_price'       => $unitPrice,
                'subtotal'         => $subtotal,
                'tax_amount'       => $tax,
                'shipping_charge'  => $shippingCharge,
                'discount_total'   => $discountTotal,
                'other_info'       => $args,
                'line_meta'        => []
            ];

            $childItem = null;
            if ($paymentType === 'subscription' && Arr::get($checkoutItem, 'other_info.signup_fee', 0)) {
                // We have a signup fee for subscription
                $singupFeeAmount = (int)Arr::get($checkoutItem, 'other_info.signup_fee', 0);

                $childDiscontTotal = 0;
                $signupFeeSubtotal = $singupFeeAmount * $quantity;
                if ($discountTotal) {
                    // Distribute discount with signup fee and item
                    $childDiscontTotal = (int)($discountTotal / ($subtotal + $signupFeeSubtotal) * $signupFeeSubtotal);
                    $discountTotal -= $childDiscontTotal;
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
                    'unit_price'       => $singupFeeAmount,
                    'subtotal'         => $signupFeeSubtotal,
                    'tax_amount'       => 0,
                    'shipping_charge'  => 0,
                    'discount_total'   => $childDiscontTotal,
                    'line_meta'        => []
                ];

                $item['discount_total'] = $discountTotal;
                $item['additional_items'] = [$childItem];

                Arr::set($item, 'other_info.signup_fee', $singupFeeAmount);
                Arr::set($item, 'other_info.signup_discount', $childDiscontTotal);
            }

            $formattedItems[] = $item;
            if ($childItem) {
                $formattedItems[] = $childItem;
            }
        }

        $this->formattedIOrderItems = $formattedItems;
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
            'mode'                  => $this->storeSettings->get('order_mode'),
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
            'shipping_tax'          => 0,
            'shipping_total'        => Arr::get($this->args, 'shipping_total', 0),
            'tax_total'             => 0,
//            'total_amount'          => $this->orderTotals['total_amount'],
            'total_paid'            => 0,
            'total_refund'          => 0,
            'rate'                  => 1,
            'note'                  => Arr::get($this->args, 'note', ''),
            'ip_address'            => Arr::get($this->args, 'ip_address', ''),
            'config'                => [
                'user_tz'                   => Arr::get($this->args, 'user_tz', ''),
            ],
        ];

        $totalAmount = $orderData['subtotal'] - $orderData['coupon_discount_total'] - $orderData['manual_discount_total'] + $orderData['shipping_total'] + $orderData['tax_total'];
        $orderData['total_amount'] = $totalAmount > 0 ? $totalAmount : 0;
        $this->orderData = $orderData;
    }


    public function createDraftOrder($prevOrder = null)
    {
        $customerId = Arr::get($this->args, 'customer_id', '');
        if (!$customerId) {
            return new \WP_Error('customer_id_missing', __('Customer ID is required to create a draft order.', 'fluent-cart'));
        }

        $orderData = $this->orderData;
        $orderData['customer_id'] = $customerId;
        $this->orderModel = \FluentCart\App\Models\Order::query()->create($orderData);

        if (!$this->orderModel) {
            return new \WP_Error('order_creation_failed', __('Failed to create order.', 'fluent-cart'));
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
                foreach ($additionalItems as $additionalItem) {
                    $additionalItem['order_id'] = $this->orderModel->id;
                    $additionalItem['line_total'] = $additionalItem['subtotal'] - $additionalItem['discount_total'];
                    $mata = Arr::get($additionalItem, 'line_meta', []);
                    $mata['parent_item_id'] = $createdItem->id;
                    $additionalItem['line_meta'] = $mata;
                    OrderItem::query()->create($additionalItem);
                }
            }
        }

        // Let's create the subscription if exists
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
        $this->insertAppliedCoupons(Arr::get($this->args, 'applied_coupons', []));

        $cartHash = Arr::get($this->args, 'cart_hash', '');
        if ($cartHash) {
            $cart = Cart::query()->where('cart_hash', $cartHash)->first();
            if ($cart) {
                $cart->order_id = $this->orderModel->id;
                $cart->customer_id = $this->orderModel->customer_id;
                $cart->stage = 'intended';
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
                }
            }
        }

        // We are almost done!
        return $this->orderModel;
    }


    private function insertAppliedCoupons($appliedCoupons, $removeOldCoupons = false): void
    {
        if ($removeOldCoupons) {
            $this->orderModel->appliedCoupons()->delete();
        }

        $couponCodes = array_keys($appliedCoupons);

        if (!empty($couponCodes)) {
            $coupons = Coupon::query()->whereIn('code', $couponCodes)->get()
                ->keyBy('code')
                ->toArray();

            foreach ($coupons as $code => &$coupon) {
                $coupon['coupon_id'] = $appliedCoupons[$code]['id'];
                $coupon['amount'] = $appliedCoupons[$code]['discount'];
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

        $totalSignup = Arr::get($item, 'other_info.signup_fee', 0) - Arr::get($item, 'other_info.signup_discount', 0);
        $firstPrice = Arr::get($item, 'subtotal') + $totalSignup - Arr::get($item, 'discount_total', 0);
        $recurringPrice = Arr::get($item, 'subtotal', 0) + Arr::get($item, 'tax_amount', 0);

        if ($firstPrice < $recurringPrice) {
            Arr::set($item, 'other_info.trial_days', PaymentHelper::getIntervalDays(Arr::get($item, 'other_info.repeat_interval')));
            Arr::set($item, 'other_info.signup_fee', $firstPrice);
            Arr::set($item, 'other_info.manage_setup_fee', 'yes');
            Arr::set($item, 'other_info.times', Arr::get($item, 'other_info.times', 0) > 1 ? Arr::get($item, 'other_info.times', 0) - 1 : 0);
        } else if ($firstPrice > $recurringPrice) {
            Arr::set($item, 'other_info.signup_fee', $firstPrice - $recurringPrice);
            Arr::set($item, 'other_info.manage_setup_fee', 'yes');
            Arr::set($item, 'other_info.trial_days', 0);
        }

        $subscriptionPricing = $this->convertToSubscriptionFormat([
            'initial_trial_days' => Arr::get($item, 'other_info.trial_days', 0),
            'repeat_interval'    => Arr::get($item, 'other_info.repeat_interval', 'monthly'),
            'times'              => Arr::get($item, 'other_info.times', 0),
            'recurring_amount'   => $item['subtotal'],
            'signup_fee'         => $signupFeeItem ? $signupFeeItem['subtotal'] : 0,
            'total_discount'     => $item['discount_total'] + Arr::get($signupFeeItem, 'discount_total', 0)
        ]);

        // removable upon discussion
        $subscriptionItem = [
            'product_id'             => $item['post_id'],
            'current_payment_method' => Arr::get($this->orderData, 'payment_method'),
            'object_id'              => $item['object_id'],
            'recurring_tax_total'    => 0,
            'recurring_total'        => $subscriptionPricing['recurring_amount'], //use price not line_total to ignore discount
            'item_name'              => $item['post_title'] . ' - ' . $item['title'],
            'bill_count'             => 0,
            'quantity'               => 1,
            'variation_id'           => Arr::get($item, 'object_id', 0),
            'status'                 => Status::SUBSCRIPTION_PENDING,
            'config'                 => [
                'currency' => $this->orderData['currency'],
                'is_trial_days_simulated' => Arr::get($subscriptionPricing, 'is_trial_days_simulated', 'no'),
            ]
        ];

        $this->subscriptionData = wp_parse_args($subscriptionPricing, $subscriptionItem);
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
        $signupFee = (int)($inputData['signup_fee'] ?? 0);
        $totalDiscount = (int)($inputData['total_discount'] ?? 0);

        // Convert repeat_interval to standard format
        $intervalMap = Helper::getAvailableSubscriptionIntervalMaps();
        $standardInterval = Helper::translateIntervalToStandardFormat($repeatInterval);


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

        } else {
            $result['signup_fee'] = $signupFee;
        }

        // inverse the repeat interval to match the expected format

        $result['repeat_interval'] = array_flip($intervalMap)[$result['repeat_interval']] ?? 'yearly';


        return [
            'billing_interval' => $result['repeat_interval'],
            'bill_times'       => $result['times'],
            'trial_days'       => $result['trial_days'],
            'is_trial_days_simulated' => Arr::get($result, 'is_trial_days_simulated', 'no'),
            'recurring_amount' => $result['recurring_amount'],
            'signup_fee'       => $result['signup_fee'] ?? 0,
        ];
    }
}
