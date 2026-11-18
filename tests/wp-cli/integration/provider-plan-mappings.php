<?php
/**
 * Phase 22 provider-backed plan payload mappings.
 *
 * These cases create only an exact owned Product/Variation fixture. They pass
 * an impossible Order ID so ProductItemService performs a read but no Order is
 * created, changed, paid, or completed.
 */

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Plan;

return [
    [
        'id'            => 'provider-stripe-plan-interval-amount-mapping',
        'name'          => 'Stripe Plan maps billing intervals and zero-decimal amounts exactly',
        'phase'         => 22,
        'known_failure' => false,
        'run'           => function () {
            $fixture = FcDomainFixture::product([
                'payment_type' => 'subscription',
                'item_price'   => 12300,
            ]);
            $productId = (int) $fixture['post']->ID;
            $variationId = (int) $fixture['variation']->id;
            $sitePrefix = Helper::getSitePrefix();
            $pricingId = 'fct_' . $sitePrefix . '_price_' . $variationId
                . '_12300_half_yearly_6_7_JPY';
            $remoteProductId = 'fct_' . $sitePrefix . '_product_' . $productId;

            $settingsFilter = function ($settings) {
                $settings['live_secret_key'] = 'sk_test_phase22_fixture';
                $settings['test_secret_key'] = 'sk_test_phase22_fixture';

                return $settings;
            };
            add_filter('fluent_cart/stripe_settings', $settingsFilter, PHP_INT_MAX, 1);

            $transport = new FcProviderHarness();
            $transport
                ->expect(
                    'GET',
                    'https://api.stripe.com/v1/plans/' . $pricingId,
                    'stripe/not-found.json'
                )
                ->expect(
                    'GET',
                    'https://api.stripe.com/v1/products/' . $remoteProductId,
                    'stripe/product-success.json'
                )
                ->expect(
                    'POST',
                    'https://api.stripe.com/v1/plans',
                    'stripe/plan-success.json'
                );
            $transport->install();

            try {
                $actual = Plan::getStripePricing([
                    'order_id'         => -1,
                    'product_id'       => $productId,
                    'variation_id'     => $variationId,
                    'trial_days'       => 7,
                    'billing_interval' => 'half_yearly',
                    'currency'         => 'JPY',
                    'interval_count'   => 1,
                    'recurring_total'  => 12300,
                ]);
                $transport->assertComplete();
                $requests = $transport->requests();
                $planBody = $requests[2]['body'];

                FcTest::assertSame(
                    [
                        'daily'      => 'day',
                        'weekly'     => 'week',
                        'monthly'    => 'month',
                        'quarterly'  => 'month',
                        'half-yearly' => 'month',
                        'yearly'     => 'year',
                    ],
                    [
                        'daily'      => Plan::convertFctIntervalToStripeInterval('daily'),
                        'weekly'     => Plan::convertFctIntervalToStripeInterval('weekly'),
                        'monthly'    => Plan::convertFctIntervalToStripeInterval('monthly'),
                        'quarterly'  => Plan::convertFctIntervalToStripeInterval('quarterly'),
                        'half-yearly' => Plan::convertFctIntervalToStripeInterval('half_yearly'),
                        'yearly'     => Plan::convertFctIntervalToStripeInterval('yearly'),
                    ],
                    'all FluentCart subscription intervals map to exact Stripe units'
                );
                FcTest::assertSame(
                    ['plan_phase22', '123', 'JPY', 'month', '6', '7'],
                    [
                        $actual['id'],
                        $planBody['amount'],
                        $planBody['currency'],
                        $planBody['interval'],
                        $planBody['interval_count'],
                        $planBody['trial_period_days'],
                    ],
                    'the recorded plan payload converts JPY cents and half-yearly cadence exactly'
                );
                FcTest::assertSame(
                    [$productId, $variationId, 'fluent-cart'],
                    [
                        (int) $planBody['metadata']['fct_product_id'],
                        (int) $planBody['metadata']['fct_variation_id'],
                        $planBody['metadata']['provider'],
                    ],
                    'the Stripe plan payload retains exact owned Product and Variation identities'
                );
            } finally {
                $transport->uninstall();
                remove_filter(
                    'fluent_cart/stripe_settings',
                    $settingsFilter,
                    PHP_INT_MAX
                );
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
