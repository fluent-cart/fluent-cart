<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API;
use FluentCart\Framework\Support\Arr;

class Plan
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
     * @type int $recurring_total Recurring total amount (in cents).
     * }
     *
     * @return \WP_Error|array
     */
    public static function getStripePricing($data = [])
    {
        $variation = ProductVariation::query()->find(Arr::get($data, 'variation_id'));
        $product = Product::query()->find(Arr::get($data, 'product_id'));
        if (!$variation || !$product) {
            return new \WP_Error('invalid_product', esc_html__('Invalid product or variation.', 'fluent-cart'));
        }

        $originalInterval = Arr::get($data, 'billing_interval');

        // Determine interval_count based on billing_interval
        $intervalCount = 1;
        if ($originalInterval === 'quarterly') {
            $intervalCount = 3;
        } elseif ($originalInterval === 'half_yearly') {
            $intervalCount = 6;
        }

        $interval = self::convertFctIntervalToStripeInterval($originalInterval);

        $billingPeriod = [
            'interval_unit' => $interval,
            'interval_frequency' => $intervalCount,
        ];

        $billingPeriod = apply_filters('fluent_cart/subscription_billing_period', $billingPeriod, [
            'subscription_interval' => $originalInterval,
            'payment_method' => 'stripe',
        ]);

        $sitePrefix = Helper::getSitePrefix();
        $pricingId = 'fct_' . $sitePrefix . '_price_' . $data['variation_id'] . '_' . $data['recurring_total'] . '_' . $data['billing_interval'] . '_' . $intervalCount . '_' . $data['trial_days'] . '_' . $data['currency'];
        $productId = 'fct_' . $sitePrefix . '_product_' . $data['product_id'];

        // Let's see if we have the price already created
        $pricePlan = (new API())->getStripeObject('plans/' . $pricingId);

        if (!is_wp_error($pricePlan)) {
            return $pricePlan;
        }

        // We don't have this price yet. Now we have to create the price from subscription
        $stripeProduct = self::retriveOrCreateProduct([
            'id'       => $productId,
            'name'     => $product->post_title,
            'metadata' => [
                'product_id' => $product->ID,
                'provider'   => 'fluent-cart',
            ]
        ]);

        if (is_wp_error($stripeProduct)) {
            return $stripeProduct;
        }

        // let's create the price now
        $priceData = [
            'id'                => $pricingId,
            'product'           => $stripeProduct['id'],
            'currency'          => $data['currency'],
            'amount'            => $data['recurring_total'],
            'trial_period_days' => Arr::get($data, 'trial_days', 0),
            'interval'          => Arr::get($billingPeriod, 'interval_unit'),
            'interval_count'    => Arr::get($billingPeriod, 'interval_frequency'),
            'metadata'          => [
                'fct_product_id'   => $data['product_id'],
                'fct_variation_id' => $data['variation_id'],
                'provider'         => 'fluent-cart'
            ]
        ];


        return (new API())->createStripeObject('plans', $priceData);
    }

    /**
     * Get or create a Stripe pricing plan for a product variation.
     *
     * @param array $data {
     * @type string $product_id Product ID.\
     * @type string $currency Currency code (e.g., 'usd').
     * @type int $amount Amount in cents.
     * }
     *
     * @return \WP_Error|array
     */
    public static function getOneTimeAddonPrice($data = [])
    {
        $product = Product::query()->find(Arr::get($data, 'product_id'));

        if (!$product) {
            return new \WP_Error('invalid_product', esc_html__('Invalid product.', 'fluent-cart'));
        }

        $sitePrefix = Helper::getSitePrefix();
        $productId = 'fct_' . $sitePrefix . '_product_' . $data['product_id'];


        $priceId = 'fct_' . $data['amount'] . '_' . $data['currency'];

        $priceData = [
            'unit_amount' => $data['amount'],
            'currency'    => $data['currency'],
            'product'     => $productId,
            'metadata'    => [
                'fct_product_id' => $data['product_id'],
                'price_id'       => $priceId,
                'provider'       => 'fluent-cart'
            ]
        ];

        // Let's see if we have the price already created
        $pricePlans = (new API())->getStripeObject('prices/search', [
            'query' => 'active:"true" AND metadata["price_id"]:"' . $priceId . '" AND product:"' . $productId . '"'
        ]);

        if (!is_wp_error($pricePlans) && !empty($pricePlans['data'])) {
            return $pricePlans['data'][0];
        }

        $newlyCreated = (new API())->createStripeObject('prices', $priceData);

        if (!is_wp_error($newlyCreated)) {
            return $newlyCreated;
        }

        // This is a fallback in case the price creation fails

        // Let's created a price without anything!
        $priceData['product_data'] = [
            'name' => 'Addon Item'
        ];

        unset($pricePlans['product']);

        return (new API())->createStripeObject('prices', $priceData);
    }

    public static function retriveOrCreateProduct($productData = [])
    {
        $existingProduct = (new API())->getStripeObject('products/' . $productData['id']);
        if (!is_wp_error($existingProduct)) {
            return $existingProduct;
        }

        return (new API())->createStripeObject('products', $productData);
    }

    public static function convertFctIntervalToStripeInterval($billingInterval)
    {
        $monthlyIntervals = ['quarterly', 'half_yearly', 'monthly'];
        // Quarterly uses month with interval_count=3
        // Half-yearly uses month with interval_count=6

        if ($billingInterval === 'daily') {
            $billingInterval = 'day';
        } elseif (in_array($billingInterval, $monthlyIntervals)) {
            $billingInterval = 'month';
        } elseif ($billingInterval === 'yearly') {
            $billingInterval = 'year';
        } elseif ($billingInterval === 'weekly') {
            $billingInterval = 'week';
        }

        return $billingInterval;
    }
}
