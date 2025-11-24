<?php

namespace FluentCartPro\App\Modules\PaymentMethods\PaddleGateway;

use FluentCartPro\App\Modules\PaymentMethods\PaddleGateway\API\API;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Support\Arr;

class Price
{
    public static function getOrCreatePaddleSinglePrice($data = [])
    {
        $variation = ProductVariation::query()->find(Arr::get($data, 'variation_id'));
        $product   = Product::query()->find(Arr::get($data, 'fct_product_id'));
        $taxMode = (new PaddleSettings())->get('tax_mode');
        $type = Arr::get($data, 'type', 'custom');


        $priceId = 'fct_paddle_onetime_price_'
            . $data['mode'] . '_'
            . $data['variation_id'] . '_'
            . $data['amount'] . '_'
            . $data['currency'] . '_'
            . $taxMode
        ;

        if ($product && $type === 'standard') {
            $priceId = apply_filters('fluent_cart/paddle_onetime_price_id', $priceId, [
                'variation' => $variation,
                'product'   => $product
            ]);

            $paddlePriceId = (string) $product->getProductMeta($priceId);

            if ($paddlePriceId) {
                $paddlePrice = API::getPaddleObject("prices/{$paddlePriceId}", [], $data['mode']);
                if (!is_wp_error($paddlePrice) && Arr::get($paddlePrice, 'data.status') == 'active') {
                    return $paddlePrice;
                }
            }
        }


        if (strlen(Arr::get($data, 'name')) > 145) {
            $data['name'] = substr(Arr::get($data, 'name'), 0, 145) . '...';
        }
        if (strlen(Arr::get($data, 'description')) > 345) {
            $data['description'] = substr(Arr::get($data, 'description'), 0, 345) . '...';
        }


        $type = Arr::get($data, 'type', 'standard');
        $priceData = [
            'description'    => Arr::get($data, 'description'),
            'product_id'     => Arr::get($data, 'paddle_product_id'),
            'unit_price'     => [
                'amount'        => PaddleHelper::formatAmount(Arr::get($data, 'amount')),
                'currency_code' => strtoupper(Arr::get($data, 'currency'))
            ],
            'name'           => Arr::get($data, 'name'),
            'tax_mode'       => $taxMode,
            'type'           => $type
        ];

        // only in case of custom price , we are passing minimum and maximum quantity which prevent quantity modifying on paddle checkout modal
        if ($type === 'custom') {
            $priceData['quantity'] = [
                'minimum' => (int) Arr::get($data, 'quantity', 1),
                'maximum' => (int) Arr::get($data, 'quantity', 1)
            ];
        }

        $mode = Arr::get($data, 'mode', 'live');


        $paddlePrice = API::createPaddleObject('prices', $priceData, $mode);

        if (is_wp_error($paddlePrice)) {
            return $paddlePrice;
        }

        if ($product && $type === 'standard') {
            $product->updateProductMeta($priceId, Arr::get($paddlePrice, 'data.id'));
        }

        return $paddlePrice;
    }


    // for subscriptions by default we are creating a standard(catalog) price
    public static function getOrCreatePaddleRecurringPrice($data = [])
    {
        $variation = ProductVariation::query()->find(Arr::get($data, 'variation_id'));
        $product   = Product::query()->find(Arr::get($data, 'fct_product_id'));

        $taxMode = (new PaddleSettings())->get('tax_mode');
        $type = Arr::get($data, 'type', 'standard');

        $priceId = 'fct_paddle_recurring_price_'
            . $data['mode'] . '_'
            . $data['variation_id'] . '_'
            . $data['recurring_total'] . '_'
            . $data['billing_interval'] . '_'
            . $data['interval_count'] . '_'
            . $data['trial_days'] . '_'
            . $data['signup_fee'] . '_'
            . $data['bill_times'] . '_'
            . $data['currency'] . '_'
            . $taxMode
        ;

        $priceId = apply_filters('fluent_cart/paddle_recurring_price_id', $priceId, [
            'plan_data' => $data,
            'variation' => $variation,
            'product'   => $product
        ]);

        $paddlePriceId = (string) $product->getProductMeta($priceId);

        // if ($paddlePriceId && $type === 'standard') {
        //     $paddlePrice = API::getPaddleObject("prices/{$paddlePriceId}", [], $data['mode']);
        //     if (!is_wp_error($paddlePrice) && Arr::get($paddlePrice, 'data.status') == 'active') {
        //         return $paddlePrice;
        //     }
        // }

        $formattedRecurringAmount = Helper::toDecimal(Arr::get($data, 'recurring_amount'), false, null, true, true, false);

        $description = $formattedRecurringAmount . ' / ' . $data['billing_interval'];

        if (Arr::get($data, 'bill_times') > 0) {
            $description .= ' - ' . __('For', 'fluent-cart-pro') . ' ' . Arr::get($data, 'bill_times') . ' ' . __('Times', 'fluent-cart-pro');
        } else {
            $description .= ' - ' . __('Until Cancel', 'fluent-cart-pro');
        }

        if (strlen($description) > 345) {
            $description = substr($description, 0, 345) . '...';
        }
        if (strlen(Arr::get($data, 'name')) > 145) {
            $data['name'] = substr(Arr::get($data, 'name'), 0, 145) . '...';
        }

        $priceData = [
            'description' => $description,
            'product_id' => Arr::get($data, 'paddle_product_id'),
            'unit_price' => [
                'amount' => PaddleHelper::formatAmount(Arr::get($data, 'recurring_amount')),
                'currency_code' => strtoupper(Arr::get($data, 'currency'))
            ],
            'quantity' => [
                'minimum' => 1, // a subscription can only be purchased one at a time
                'maximum' => 1  // a subscription can only be purchased one at a time
            ],
            'name' => Arr::get($data, 'name'),
            'tax_mode' => $taxMode,
            'type' => 'standard'
        ];

        $billingMaps = [
            'daily'       => 'day',
            'weekly'      => 'week',
            'monthly'     => 'month',
            'quarterly'   => 'month',
            'half_yearly' => 'month',
            'yearly'      => 'year'
        ];

        // Determine frequency based on billing_interval
        $frequency = 1;
        if ($data['billing_interval'] === 'quarterly') {
            $frequency = 3; // 3 months
        } elseif ($data['billing_interval'] === 'half_yearly') {
            $frequency = 6; // 6 months
        }

        $billingPeriod = [
            'interval_unit' => Arr::get($billingMaps, $data['billing_interval'], 'month'),
            'interval_frequency' => $frequency
        ];

        $billingPeriod = apply_filters('fluent_cart/subscription_billing_period', $billingPeriod, [
            'subscription_interval' => $data['billing_interval'],
            'payment_method' => 'paddle',
        ]);


        $billingCycle = [
            'interval' => Arr::get($billingPeriod, 'interval_unit'),
            'frequency' => Arr::get($billingPeriod, 'interval_frequency'),
        ];

        $priceData['billing_cycle'] = $billingCycle;

        if (Arr::get($data, 'trial_days') > 0) {
            $priceData['trial_period'] = [
                'interval' => 'day',
                'frequency' => (int) Arr::get($data, 'trial_days')
            ];

            $description .= ' - ' . __('Trial', 'fluent-cart-pro') . ' ' . Arr::get($data, 'trial_days') . ' ' . __('Days', 'fluent-cart-pro');
            $priceData['description'] = $description;
        }


        $paddlePrice =  API::createPaddleObject('prices', $priceData, $data['mode']);
        if (is_wp_error($paddlePrice)) {
            return $paddlePrice;
        }

        if ($type === 'standard') {
            $product->updateProductMeta($priceId, Arr::get($paddlePrice, 'data.id'));
        }
        return $paddlePrice;
    }

    public static function createPaddleDiscount($order, $discountAmount, $discountMode)
    {
        $discountId = 'fct_paddle_discount_' . $order->mode . '_' . $discountAmount . '_' . $order->currency;
        $discountId = apply_filters('fluent_cart/paddle_discount_id', $discountId, [
            'order' => $order,
            'discount_amount' => $discountAmount
        ]);

        $paddleDiscountId = (string) fluent_cart_get_option($discountId);

        if ($paddleDiscountId) {
            $paddleDiscount = API::getPaddleObject("discounts/{$paddleDiscountId}", [], $order->mode);
            if (!is_wp_error($paddleDiscount) && Arr::get($paddleDiscount, 'data.status') == 'active') {
                return $paddleDiscount;
            }
        }

        $data = [
            'amount'      => PaddleHelper::formatAmount($discountAmount),
            'type'        => 'flat',
            'enabled_for_checkout' => false,
            'mode'        => $discountMode,
            'description'          => 'Discount',
            'currency_code'        => $order->currency,
            'recur'                => false
        ];

        $paddleDiscount =  API::createPaddleObject('discounts', $data, $order->mode);

        if (is_wp_error($paddleDiscount)) {
            return $paddleDiscount;
        }

        if ($discountMode === 'standard') {
            fluent_cart_update_option($discountId, Arr::get($paddleDiscount, 'data.id'));
        }

        return $paddleDiscount;
    }
}
