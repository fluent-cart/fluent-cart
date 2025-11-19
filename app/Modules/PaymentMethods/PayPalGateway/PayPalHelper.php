<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API;
use FluentCart\Framework\Support\Arr;

class PayPalHelper
{
    /**
     * Get or create a Stripe pricing plan for a product variation.
     *
     * @param array $data {
     * @type string $product_id Product ID.
     * @type string $variation_id Variation ID.
     * @type string $trial_days Trial Days.
     * @type string $billing_interval Billing interval (e.g., 'month', 'year').
     * @type string $currency Currency code (e.g., 'usd').
     * @type int $interval_count Number of intervals.
     * @type int $recurring_amount Recurring total amount (in cents).
     * @type int $signup_fee Recurring total amount (in cents).
     * @type int bill_times optional 0 for continuous billing, or a specific number of billing cycles
     * }
     *
     * @return \WP_Error|array
     */
    public static function getPayPalPlan($data = [])
    {
        $variation = ProductVariation::query()->find(Arr::get($data, 'variation_id'));
        $product = Product::query()->find(Arr::get($data, 'product_id'));

        if (!$variation || !$product) {
            return new \WP_Error('invalid_product', esc_html__('Invalid product or variation.', 'fluent-cart'));
        }
        $sitePrefix = Helper::getSitePrefix();

        $planId = 'fct_pp_plan_'
            . $data['currency'] . '_'
            . $data['variation_id'] . '_'
            . $data['recurring_amount'] . '_'
            . $data['billing_interval'] . '_'
            . $data['interval_count'] . '_'
            . $data['trial_days'] . '_'
            . $data['signup_fee'] . '_'
            . $data['bill_times'];

        $planId = apply_filters('fluent_cart/paypal_plan_id', $planId, [
            'plan_data' => $data,
            'variation' => $variation,
            'product'   => $product
        ]);

        $paypalPlanId = $product->getProductMeta($planId);

        if ($paypalPlanId) {
            $paypalPlan = API::getResource('billing/plans/' . $planId);
            if (!is_wp_error($paypalPlan)) {
                return $paypalPlan;
            }
        }

        $paypalProduct = self::getRemoteProductByData([
            'id'          => 'prod_' . $data['product_id'] . '_' . $sitePrefix,
            'name'        => sanitize_text_field($product->post_title),
            'description' => sanitize_text_field($product->post_excerpt)
        ]);

        if (is_wp_error($paypalProduct)) {
            return $paypalProduct;
        }

        $planData = wp_parse_args($data, [
            'plan_name'         => $product->post_title . ' - ' . $variation->variation_title,
            'plan_description'  => $product->post_title,
            'paypal_product_id' => $paypalProduct['id'],
        ]);

        $paypalPlanArgs = self::getPayPalPlanArgs($planData);

        $paypalPlan = API::createResource('billing/plans', $paypalPlanArgs);

        if (is_wp_error($paypalPlan)) {
            return $paypalPlan;
        }

        $product->updateProductMeta($planId, $paypalPlan['id']);

        return $paypalPlan;
    }

    public static function processRemoteRefund($transaction, $amount, $args)
    {
        $chargeId = $transaction->vendor_charge_id;

        if (!$chargeId) {
            return new \WP_Error('invalid_charge_id', __('Please provide a valid charge Id!', 'fluent-cart'));
        }

        $refundData = [
            'custom_id' => $transaction->uuid,
            'amount'    => array(
                'value'         => Helper::toDecimalWithoutComma($amount),
                'currency_code' => $transaction->currency
            ),

        ];


        $reason = Arr::get($args, 'reason', '');
        if ($reason) {
            $refundData['note_to_payer'] = substr($reason, 0, 255);
        }

        $vendorRefund = (new API())->makeRequest('payments/captures/' . $chargeId . '/refund', 'v2', 'POST', $refundData);

        if (is_wp_error($vendorRefund)) {
            return $vendorRefund;
        }

        if (Arr::get($vendorRefund, 'status') !== 'COMPLETED') {
            return new \WP_Error('refund_not_completed', __('Refund not completed in paypal. Please refund from PayPal Manually.', 'fluent-cart'));
        }

        return Arr::get($vendorRefund, 'id');
    }

    private static function getRemoteProductByData($productData = [])
    {

        if (isset($productData['id']) && strlen($productData['id']) > 50) {
            $productData['id'] = substr($productData['id'], 0, 48); // PayPal product ID should be less than 50 characters
        }

        if (isset($productData['id'])) {
            $existingProduct = API::getResource('catalogs/products/' . $productData['id']);
            if (!is_wp_error($existingProduct)) {
                return $existingProduct;
            }
        }


        $formattedData = [
            'id'          => Arr::get($productData, 'id'),
            'name'        => Arr::get($productData, 'name'),
            'description' => Arr::get($productData, 'description'),
            'type'        => Arr::get($productData, 'type'),
        ];

        $validTypes = ['PHYSICAL', 'DIGITAL', 'SERVICE'];
        if (!in_array($formattedData['type'], $validTypes)) {
            $formattedData['type'] = 'DIGITAL'; // Default to PHYSICAL if type is not valid
        }

        // name should be under 127 characters, add '...' if it is more than 127 characters
        if ($formattedData['name'] && strlen($formattedData['name']) > 127) {
            $formattedData['name'] = substr($formattedData['name'], 0, 120) . '...';
        }

        // description should be under 256 characters, add '...' if it is more than 256 characters
        if ($formattedData['description'] && strlen($formattedData['description']) > 256) {
            $formattedData['description'] = substr($formattedData['description'], 0, 250) . '...';
        }

        $formattedData = array_filter($formattedData);

        $createdProduct = API::createResource('catalogs/products', $formattedData);


        return $createdProduct;
    }

    private static function getPayPalPlanArgs($data = [])
    {
        // Default values
        $defaults = [
            'paypal_product_id' => '',
            'trial_days'        => 0,
            'billing_interval'  => 'monthly',
            'currency'          => 'USD',
            'interval_count'    => 1,
            'recurring_amount'  => 0,
            'signup_fee'        => 0,
            'bill_times'        => 0,
            'plan_name'         => __('Subscription', 'fluent-cart'),
            'plan_description'  => __('Subscription Plan', 'fluent-cart')
        ];

        // Merge input data with defaults
        $data = wp_parse_args($data, $defaults);

        $data['product_id'] = $data['paypal_product_id'];
        $data['recurring_total'] = $data['recurring_amount'];

        // Convert amounts from cents to dollars with 2 decimal places
        $recurring_amount = number_format($data['recurring_total'] / 100, 2, '.', '');
        $initial_amount = $data['signup_fee'] > 0 ? number_format($data['signup_fee'] / 100, 2, '.', '') : 0;

        // Map billing interval to PayPal API interval unit
        $interval_map = [
            Status::BILLING_MONTHLY     => 'MONTH',
            Status::BILLING_QUARTERLY   => 'MONTH',
            Status::BILLING_HALF_YEARLY => 'MONTH',
            Status::BILLING_YEARLY      => 'YEAR',
            Status::BILLING_WEEKLY      => 'WEEK',
            Status::BILLING_DAILY       => 'DAY'
        ];

        $interval_unit = isset($interval_map[$data['billing_interval']]) ? $interval_map[$data['billing_interval']] : 'MONTH';
        
        // Determine interval_count based on billing_interval
        $intervalCount = 1;
        if ($data['billing_interval'] === Status::BILLING_QUARTERLY) {
            $intervalCount = 3;
        } elseif ($data['billing_interval'] === Status::BILLING_HALF_YEARLY) {
            $intervalCount = 6;
        }

        $intervalCount = 1;
        if ($data['billing_interval'] === Status::BILLING_QUARTERLY) {
            $intervalCount = 3;
        } elseif ($data['billing_interval'] === Status::BILLING_HALF_YEARLY) {
            $intervalCount = 6;
        }

        $billingPeriod = [
            'interval_unit' => $interval_unit,
            'interval_frequency' => $intervalCount,
        ];

        $billingPeriod = apply_filters('fluent_cart/subscription_billing_period', $billingPeriod, [
            'subscription_interval' => $data['billing_interval'],
            'payment_method' => 'paypal',
        ]);

        // Base plan structure
        $plan_name = $data['plan_name'];
        $plan_description = $data['plan_description'];

        $paypal_plan = [
            'product_id'          => $data['product_id'],
            'name'                => $plan_name,
            'description'         => $plan_description,
            'status'              => 'ACTIVE',
            'payment_preferences' => [
                'auto_bill_outstanding'     => true,
                'payment_failure_threshold' => 3
            ]
        ];

        $normalCycle = [
            'frequency'      => [
                'interval_unit'  => Arr::get($billingPeriod, 'interval_unit'),
                'interval_count' => Arr::get($billingPeriod, 'interval_frequency'),
            ],
            'tenure_type'    => 'REGULAR',
            'sequence'       => 1,
            'total_cycles'   => $data['bill_times'],
            'pricing_scheme' => [
                'fixed_price' => [
                    'value'         => $recurring_amount,
                    'currency_code' => $data['currency']
                ]
            ]
        ];

        $trialCycle = [];

        if ($data['trial_days'] > 0) {
            $trialCycle = [
                'tenure_type'    => 'TRIAL',
                'frequency'      => [
                    'interval_unit'  => 'DAY',
                    'interval_count' => $data['trial_days']
                ],
                'sequence'       => 1,
                'pricing_scheme' => [
                    'fixed_price' => [
                        'value'         => '0.00',
                        'currency_code' => $data['currency']
                    ]
                ],
                'total_cycles'   => 1
            ];
            $normalCycle['sequence'] = 2; // If there's a trial, the regular cycle sequence should be 2
        }

        if ($initial_amount) {
            if ($trialCycle) {
                $trialCycle['pricing_scheme']['fixed_price']['value'] = $initial_amount;
            } else {
                $trialCycle = [
                    'tenure_type'    => 'TRIAL',
                    'frequency'      => [
                        'interval_unit'  => $interval_unit,
                        'interval_count' => 1
                    ],
                    'sequence'       => 1,
                    'pricing_scheme' => [
                        'fixed_price' => [
                            'value'         => $initial_amount + $recurring_amount,
                            'currency_code' => $data['currency']
                        ]
                    ],
                    'total_cycles'   => 1
                ];

                $normalCycle['total_cycles'] = $data['bill_times'] > 1 ? $data['bill_times'] - 1 : $data['bill_times']; // this trial cycle is without trial days, it's an adjusted paid trial cycle to take signupFee with one transaction
                $normalCycle['sequence'] = 2; // If there's a trial, the regular cycle sequence should be 2
            }
        }

        $cycles = [];

        if ($trialCycle) {
            $cycles[] = $trialCycle;
        }

        $cycles[] = $normalCycle;

        $paypal_plan['billing_cycles'] = $cycles;

        return $paypal_plan;
    }
}
