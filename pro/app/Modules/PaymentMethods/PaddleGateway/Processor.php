<?php

namespace FluentCartPro\App\Modules\PaymentMethods\PaddleGateway;

use FluentCartPro\App\Modules\PaymentMethods\PaddleGateway\API\API;
use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\Payments\SubscriptionHelper;
use FluentCart\Framework\Support\Arr;

class Processor
{
    /**
     * Handle single payment processing
     */
    public function handleSinglePayment(PaymentInstance $paymentInstance)
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;

        try {
            // Create or get Paddle customer
            $paddleCustomer = PaddleHelper::createOrGetPaddleCustomer($order->customer, $order->mode);
            
            if (is_wp_error($paddleCustomer)) {
                return $paddleCustomer;
            }

            $paddleData = $this->preparePaddleOneTimeCheckoutData($order, $transaction);

            if (is_wp_error($paddleData)) {
                $errorData = $paddleData->get_error_data();
                // get error message from this type of errors
                $errors = Arr::get($errorData, 'response_body.error.errors', []);
                $errorMessage = '';
                if (empty($errors)) {
                   $errorMessage = 'Paddle Unknown Error';
                } else {
                    foreach ($errors as $error) {
                        $errorMessage .= 'field: ' . $error['field'] . ': ' . 'message: ' . $error['message'] . '<br>';
                    }
                }

                return new \WP_Error(
                    'paddle_payment_error',
                    $errorMessage
                );
            }

            $paddleData['customer_id'] = Arr::get($paddleCustomer, 'data.id');

            return [
                'nextAction'         => 'paddle',
                'actionName'         => 'custom',
                'status'             => 'success',
                'data'               => [
                    'order'       => [
                        'hash' => $order->uuid,
                    ],
                    'transaction' => [
                        'hash' => $transaction->uuid,
                    ],
                    'customer' => [
                        'email'     => $order->customer->email,
                        'address'   => [
                            'countryCode' => $order->customer->country,
                            'postalCode' => $order->customer->postcode,
                        ]
                    ],
                    'success_url' => $transaction->getReceiptPageUrl(),
                    'cancel_url' =>  Paddle::getCancelUrl()
                ],
                'message' => __('Order has been placed successfully', 'fluent-cart-pro'),
                'response' => $paddleData,
            ];

        } catch (\Exception $e) {
            return new \WP_Error(
                'paddle_payment_error',
                $e->getMessage()
            );
        }
    }

    /**
     * Handle subscription payment processing
     */
    public function handleSubscriptionPayment(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $order = $paymentInstance->order;
        $subscription = $paymentInstance->subscription;
        $initialAmount = (int)$subscription->signup_fee + $paymentInstance->getExtraAddonAmount();
        $orderType = $paymentInstance->order->type;

        $transaction = $paymentInstance->transaction;
        $status = Status::SUBSCRIPTION_INTENDED;

        if (!$subscription) {
            return new \WP_Error(
                'no_subscription',
                __('No subscription found for this order', 'fluent-cart-pro')
            );
        }

        try {
            $paddleCustomer = PaddleHelper::createOrGetPaddleCustomer($order->customer, $order->mode);
            
            if (is_wp_error($paddleCustomer)) {
                return $paddleCustomer;
            }

            $productId = 'fct_paddle_product_' .
                $subscription->product_id . '_v_' .
                $subscription->variation_id . '_' .
                $subscription->recurring_total . '_' .
                $subscription->billing_interval . '_' .
                $subscription->trial_days . '_' .
                $subscription->bill_times . '_' .
                $subscription->signup_fee . '-' .
                $order->currency . '_' . $order->mode;

            // temporary
            $productIdtTest = 'fct_paddle_product_' . $subscription->product_id . '_' . $order->mode;

            $subscriptionItem = $order->order_items->filter(function ($item) {
                return $item->payment_type !== 'signup_fee';
            })->first();


            $productName = $subscriptionItem->post_title;
            $productDescription = $subscriptionItem->post_title . ' - ' . $subscriptionItem->title;
            $subscriptionProductType = apply_filters('fluent_cart/paddle_subscription_product_type', 'standard', [
                'subscription' => $subscription
            ]);

            $paddleProduct = Product::createOrGetPaddleProduct([
                'fct_product_id' => $subscription->product_id,
                'product_id'     => $productIdtTest,
                'currency'       => $order->currency,
                'mode'           => $order->mode,
                'name'           => $productName,
                'description'    => $productDescription,
                'type'           => $subscriptionProductType
            ]);


            $priceName = $subscriptionItem->title;
            $subscriptionPriceType = apply_filters('fluent_cart/paddle_subscription_price_type', 'standard', [
                'subscription' => $subscription
            ]);

            if ($orderType == 'renewal') {
                $status = $subscription->status;
                $initialAmount = 0;

                $data = [
                    'name'             => $priceName,
                    'mode'             => $order->mode,
                    'paddle_product_id'       => Arr::get($paddleProduct, 'data.id'),
                    'fct_product_id'   => $subscription->product_id,
                    'variation_id'     => $subscription->variation_id,
                    'trial_days'       => $subscription->getReactivationTrialDays(),
                    'billing_interval' => $subscription->billing_interval,
                    'currency'         => $order->currency,
                    'interval_count'   => 1, // 1
                    'signup_fee'       => $initialAmount, // default setup fee in cents ($0.00)
                    'recurring_amount' => $subscription->getCurrentRenewalAmount(), // default recurring total in cents// default recurring total in cents
                    'recurring_total'  => $subscription->getCurrentRenewalAmount(), //
                    'bill_times'       => (int)$subscription->getRequiredBillTimes(), // 0 for unlimited
                    'type'             => $subscriptionPriceType
                ];
                $paddleRecurringPrice = Price::getOrCreatePaddleRecurringPrice($data);
            } else {
                $data = [
                    'name'             => $priceName,
                    'mode'             => $order->mode,
                    'paddle_product_id'       => Arr::get($paddleProduct, 'data.id'),
                    'fct_product_id'   => $subscription->product_id,
                    'variation_id'     => $subscription->variation_id,
                    'trial_days'       => $subscription->trial_days,
                    'billing_interval' => $subscription->billing_interval,
                    'currency'         => $order->currency,
                    'interval_count'   => 1, // 1
                    'signup_fee'       => $initialAmount, // default setup fee in cents ($0.00)
                    'recurring_amount' => PaddleHelper::formatAmount($subscription->recurring_amount), // default recurring total in cents
                    'recurring_total'  => PaddleHelper::formatAmount($subscription->recurring_total), // default recurring total in cents
                    'bill_times'       => (int)$subscription->bill_times, // 0 for unlimited,
                    'type'             => $subscriptionPriceType
                ];

                $paddleRecurringPrice = Price::getOrCreatePaddleRecurringPrice($data);
            }

            if (is_wp_error($paddleRecurringPrice)) {
                return $paddleRecurringPrice;
            }

            $paddleData = [
                'paddle_price_ids' => [
                    [
                        'price_id' => Arr::get($paddleRecurringPrice, 'data.id'),
                        'quantity' => 1
                    ]
                ]
            ];

            if ($initialAmount) {
                $initialPriceName = __('Signup Fee', 'fluent-cart-pro');
                $subscriptionSignupFee = Arr::get($subscriptionItem->other_info, 'signup_fee', 0);
                if ($initialAmount != $subscriptionSignupFee) {
                    $initialPriceName = __('Initial Payment', 'fluent-cart-pro');
                }

                $signupFeePriceType = apply_filters('fluent_cart/paddle_signup_fee_price_type', 'custom', [
                    'subscription' => $subscription
                ]);
                $addonPrice = Price::getOrCreatePaddleSinglePrice([
                    'paddle_product_id'  => Arr::get($paddleProduct, 'data.id'),
                    'fct_product_id' => 0,
                    'variation_id'   => 0,
                    'currency'    => $order->currency,
                    'mode'        => $order->mode,
                    'name'        =>  $initialPriceName,
                    'description' => $subscriptionItem->title . ' - Signup Fee',
                    'amount'      => PaddleHelper::formatAmount($initialAmount),
                    'quantity'    => 1,
                    'type'        =>  $signupFeePriceType
                ]);

                if (is_wp_error($addonPrice)) {
                    return $addonPrice;
                }

                $paddleData['paddle_price_ids'][] = [
                    'price_id' => Arr::get($addonPrice, 'data.id'),
                    'quantity' => 1
                ];
            }

            // Update subscription with Paddle reference
            $subscription->update([
                'status' => $status,
                'current_payment_method' => 'paddle',
                'next_billing_date'      => SubscriptionHelper::getNextBillingDate($subscription),
                'vendor_plan_id'         => Arr::get($paddleRecurringPrice, 'data.id'),
                'vendor_customer_id'     => Arr::get($paddleCustomer, 'data.id'),
            ]);

            return [
                'nextAction'         => 'paddle',
                'actionName'         => 'custom',
                'status'             => 'success',
                'data'               => [
                    'order' => [
                        'hash' => $order->uuid,
                    ],
                    'transaction' => [
                        'hash' => $transaction->uuid,
                    ],
                    'subscription' => [
                        'hash' => $subscription->uuid,
                    ],
                    'customer' => [
                        'email'     => $order->customer->email,
                        'address'   => [
                            'countryCode' => $order->customer->country,
                            'postalCode' => $order->customer->postcode,
                        ]
                    ],
                    'success_url' => $transaction->getReceiptPageUrl(),
                    'cancel_url' =>  Paddle::getCancelUrl()
                ],
                'message' => __('Order has been placed successfully', 'fluent-cart-pro'),
                'response' => $paddleData,
            ];

        } catch (\Exception $e) {
            return new \WP_Error(
                'paddle_subscription_error',
                $e->getMessage()
            );
        }
    }

    private function getPaddlePriceIdsForOneTimePayment(Order $order)
    {
        $prices = [];
        foreach ($order->order_items as $orderItem) {
            // Get item name from available properties
            $productName = $orderItem->post_title;
            $quantity = $orderItem->quantity;
            $perQuantityAmount   = $orderItem->unit_price;

            $variation = ProductVariation::query()->find($orderItem->object_id);
            $productId = 'fct_paddle_product_' .
                $variation->post_id . '_' .
                $variation->id . '_' .
                $variation->item_price . '_' .
                $order->currency . '_' .
                $variation->fulfillment_type . '_' .
                $variation->payment_type . '_' .
                $order->mode;

            $productId = apply_filters('fluent_cart/paddle_product_id', $productId, [
                'variation' => $variation
            ]);

            $onetimeProductType = apply_filters('fluent_cart/paddle_onetime_product_type', 'standard', [
                'variation' => $variation
            ]);
            $paddleProduct = Product::createOrGetPaddleProduct([
                'fct_product_id' => $variation->post_id,
                'variation_id'   => $variation->id,
                'product_id'     => $productId,
                'currency'       => $order->currency,
                'mode'           => $order->mode,
                'name'           => $productName,
                'type'           => $onetimeProductType
            ]);


            if (is_wp_error($paddleProduct)) {
                return $paddleProduct;
            }

            $paddleProductId = Arr::get($paddleProduct, 'data.id');
            $priceName = $orderItem->title;
            $priceDescription = $orderItem->title . ' - ' . $orderItem->post_title;
            $onetimePriceType = apply_filters('fluent_cart/paddle_onetime_price_type', 'custom', [
                'variation' => $variation
            ]);
            // Create price for the product
            $price = Price::getOrCreatePaddleSinglePrice([
                'paddle_product_id'     => $paddleProductId,
                'fct_product_id'        => $variation->post_id,
                'variation_id'          => $variation->id,
                'currency'              => $order->currency,
                'mode'                  => $order->mode,
                'name'                  => $priceName,
                'description'           => $priceDescription,
                'amount'                => PaddleHelper::formatAmount($perQuantityAmount),
                'quantity'              => $quantity,
                'type'                  => $onetimePriceType
            ]);

            if (is_wp_error($price)) {
                return $price;
            }

            $prices[] = [
                'price_id' => Arr::get($price, 'data.id'),
                'quantity' => $quantity
            ];

        }


        if ($order->shipping_total > 0) {
            $addonProductType = apply_filters('fluent_cart/paddle_addon_product_type', 'custom', [
                'order' => $order
            ]);
             $shippingProduct = Product::getOrCreateAddOnProduct([
                 'product_id'     => 'fct_paddle_shipping_product_amount_' . $order->shipping_total . '_' . $order->currency . '_' . $order->mode,
                 'currency'       => $order->currency,
                 'mode'           => $order->mode,
                 'name'           => __('Shipping Fee', 'fluent-cart-pro'),
                 'type'           => $addonProductType
             ]);

             if (is_wp_error($shippingProduct)) {
                return $shippingProduct;
            }

            $paddleProductId = Arr::get($shippingProduct, 'data.id');

            // Create price for shipping
            $price = Price::getOrCreatePaddleSinglePrice([
                'paddle_product_id'     => $paddleProductId,
                'fct_product_id' => 0,
                'variation_id'   => 0,
                'currency'       => $order->currency,
                'mode'           => $order->mode,
                'name'           => __('Shipping Fee', 'fluent-cart-pro'),
                'description'    => 'Shipping Fee of ' . (string) ($order->shipping_total / 100) . ' ' . strtoupper($order->currency),
                'amount'         => PaddleHelper::formatAmount($order->shipping_total),
                'quantity'       => 1,
                'type'           => $addonProductType
            ]);

            if (is_wp_error($price)) {
                return $price;
            }

            $prices[] = [
                'price_id' => Arr::get($price, 'data.id'),
                'quantity' => 1
            ];
        }

        return $prices;
    }


    /**
     * Prepare Paddle checkout data for single payment
     */
    private function preparePaddleOneTimeCheckoutData($order, $transaction)
    {
        $priceIds = $this->getPaddlePriceIdsForOneTimePayment($order);

        $totalDiscount =  $order->coupon_discount_total + $order->manual_discount_total;

        $discountId = null;
        if ($totalDiscount > 0) {
            // discount mode (type) 'custom/standard'
            $discountMode = apply_filters('fluent_cart/paddle_discount_mode', 'custom', [
                'order' => $order,
                'discount_amount' => $totalDiscount
            ]);
            $discount = Price::createPaddleDiscount($order, $totalDiscount, $discountMode);
            if (is_wp_error($discount)) {
                return $discount;
            }
            $discountId = Arr::get($discount, 'data.id');
        }

        if (is_wp_error($priceIds)) {
            return $priceIds;
        }

        return [
            'paddle_price_ids' => $priceIds,
            'paddle_discount_id' => $discountId,
            'total_amount' => PaddleHelper::formatAmount($order->total_amount),
            'currency' => strtoupper($order->currency),
            'return_url' => $transaction->getReceiptPageUrl(),
        ];
    }

}
