<?php
/**
 * Phase 22 provider-backed plan payload mappings.
 *
 * These cases create only an exact owned Product/Variation fixture. They pass
 * an impossible Order ID so ProductItemService performs a read but no Order is
 * created, changed, paid, or completed.
 */

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API as PayPalApi;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPalHelper;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\SubscriptionManager;
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
    [
        'id'            => 'provider-paypal-plan-amount-status-payload-mapping',
        'name'          => 'PayPal maps plan amounts, payload cycles, and subscription statuses exactly',
        'phase'         => 22,
        'known_failure' => false,
        'run'           => function () {
            $fixture = FcDomainFixture::product([
                'payment_type' => 'subscription',
                'item_price'   => 10005,
            ]);
            $productId = (int) $fixture['post']->ID;
            $variationId = (int) $fixture['variation']->id;
            $sitePrefix = Helper::getSitePrefix();
            $remoteProductId = 'prod_' . $productId . '_' . $sitePrefix;
            if (strlen($remoteProductId) > 50) {
                $remoteProductId = substr($remoteProductId, 0, 48);
            }

            $cachedToken = fluent_cart_get_option(
                '_paypal_access_token_phase22',
                [
                    'access_token' => 'phase22-provider-token',
                    'expires_at'   => time() + 3600,
                ],
                false
            );
            FcTest::assertSame(
                'phase22-provider-token',
                isset($cachedToken['access_token']) ? $cachedToken['access_token'] : null,
                'the isolated provider mode uses only the synthetic access token'
            );

            $transport = new FcProviderHarness();
            $transport
                ->expect(
                    'GET',
                    'https://api.paypal.com/v1/phase22/probe',
                    'paypal/resource-success.json'
                )
                ->expect(
                    'GET',
                    'https://api.paypal.com/v1/catalogs/products/' . $remoteProductId,
                    'paypal/not-found.json'
                )
                ->expect(
                    'POST',
                    'https://api.paypal.com/v1/catalogs/products',
                    'paypal/product-success.json'
                )
                ->expect(
                    'POST',
                    'https://api.paypal.com/v1/billing/plans',
                    'paypal/unprocessable.json'
                );
            $transport->install();

            try {
                $probe = PayPalApi::makeRequest(
                    'phase22/probe',
                    'v1',
                    'GET',
                    [],
                    'phase22'
                );
                FcTest::assertSame(
                    'PAYPAL-PHASE22',
                    $probe['id'],
                    'the isolated synthetic token reaches the fake PayPal transport'
                );

                $actual = PayPalHelper::getPayPalPlan([
                    'order_id'         => -1,
                    'product_id'       => $productId,
                    'variation_id'     => $variationId,
                    'trial_days'       => 7,
                    'billing_interval' => Status::BILLING_QUARTERLY,
                    'currency'         => 'USD',
                    'interval_count'   => 1,
                    'recurring_amount' => 10005,
                    'signup_fee'       => 250,
                    'bill_times'       => 4,
                ]);
                $transport->assertComplete();
                $requests = $transport->requests();
                $planBody = $requests[3]['body'];
                $manager = new SubscriptionManager();
                $statuses = [
                    'active'    => $manager->getCorrectSubscriptionStatus('ACTIVE'),
                    'trialing'  => $manager->getCorrectSubscriptionStatus('TRIALING'),
                    'cancelled' => $manager->getCorrectSubscriptionStatus('CANCELLED'),
                    'expired'   => $manager->getCorrectSubscriptionStatus('EXPIRED'),
                    'paused'    => $manager->getCorrectSubscriptionStatus('PAUSED'),
                    'expiring'  => $manager->getCorrectSubscriptionStatus('EXPIRING'),
                    'suspended' => $manager->getCorrectSubscriptionStatus('SUSPENDED'),
                ];

                FcTest::assertSame(true, is_wp_error($actual), 'the final canned plan error is normalized');
                FcTest::assertSame(
                    ['general_error', 'INVALID_PARAMETER_VALUE'],
                    [$actual->get_error_code(), $actual->get_error_message()],
                    'PayPal create-plan failure preserves the provider issue'
                );
                FcTest::assertSame(
                    [
                        'ACTIVE',
                        'MONTH',
                        3,
                        '2.50',
                        '100.05',
                        4,
                        2,
                    ],
                    [
                        $planBody['status'],
                        $planBody['billing_cycles'][1]['frequency']['interval_unit'],
                        $planBody['billing_cycles'][1]['frequency']['interval_count'],
                        $planBody['billing_cycles'][0]['pricing_scheme']['fixed_price']['value'],
                        $planBody['billing_cycles'][1]['pricing_scheme']['fixed_price']['value'],
                        $planBody['billing_cycles'][1]['total_cycles'],
                        $planBody['billing_cycles'][1]['sequence'],
                    ],
                    'the PayPal plan payload conserves signup and recurring cents across cycles'
                );
                FcTest::assertSame(
                    [
                        'active'    => Status::SUBSCRIPTION_ACTIVE,
                        'trialing'  => Status::SUBSCRIPTION_TRIALING,
                        'cancelled' => Status::SUBSCRIPTION_CANCELED,
                        'expired'   => Status::SUBSCRIPTION_EXPIRED,
                        'paused'    => Status::SUBSCRIPTION_PAUSED,
                        'expiring'  => Status::SUBSCRIPTION_EXPIRING,
                        'suspended' => Status::SUBSCRIPTION_PAUSED,
                    ],
                    $statuses,
                    'PayPal provider statuses map to exact FluentCart subscription statuses'
                );
            } finally {
                $transport->uninstall();
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
