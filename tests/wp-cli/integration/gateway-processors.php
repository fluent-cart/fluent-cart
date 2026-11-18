<?php
/**
 * fixture-backed Stripe subscription-manager contract.
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\SubscriptionsManager;

$phase29StripeSettings = function ($settings) {
    $settings['checkout_mode'] = 'onsite';
    $settings['test_publishable_key'] = 'pk_test_phase29_fixture';
    $settings['test_secret_key'] = 'sk_test_phase29_fixture';

    return $settings;
};

return [
    [
        'id'            => 'stripe-subscription-manager-provider-mapping',
        'name'          => 'Stripe subscription manager maps success and preserves state after provider failure',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29StripeSettings) {
            $transport = new FcProviderHarness();
            $settingsInstalled = false;

            try {
                $product = FcDomainFixture::product([
                    'payment_type' => 'subscription',
                    'item_price'   => 1001,
                ]);
                $order = FcDomainFixture::orderWithItem(
                    (int) $product['post']->ID,
                    (int) $product['variation']->id,
                    1,
                    [
                        'type'         => Status::ORDER_TYPE_SUBSCRIPTION,
                        'currency'     => 'USD',
                        'subtotal'     => 1001,
                        'tax_total'    => 0,
                        'total_amount' => 1001,
                    ]
                );
                $subscription = FcDomainFixture::subscription(
                    $order,
                    (int) $product['post']->ID,
                    (int) $product['variation']->id,
                    Status::SUBSCRIPTION_ACTIVE,
                    'stripe-manager',
                    ['bill_count' => 7, 'current_payment_method' => '']
                );
                $originalVendorId = (string) $subscription->vendor_subscription_id;

                add_filter('fluent_cart/stripe_settings', $phase29StripeSettings, PHP_INT_MAX, 1);
                $settingsInstalled = true;
                $transport
                    ->expect(
                        'GET',
                        'https://api.stripe.com/v1/subscriptions/' . $originalVendorId,
                        'stripe/subscription-active.json'
                    )
                    ->expect(
                        'GET',
                        'https://api.stripe.com/v1/subscriptions/sub_phase29_confirmed',
                        'stripe/rate-limit.json'
                    )
                    ->install();

                $manager = new SubscriptionsManager();
                $first = $manager->confirmSubscriptionAfterChargeSucceeded($subscription, [
                    'vendor_method_id' => 'pm_phase29_card',
                    'brand'            => 'visa',
                    'last4'            => '4242',
                ]);
                $afterSuccess = FcDomainFixture::recaptureSubscriptionVendorId(
                    (int) $subscription->id,
                    $originalVendorId
                );
                $successSnapshot = $afterSuccess->getAttributes();
                $second = $manager->confirmSubscriptionAfterChargeSucceeded($afterSuccess);
                $afterError = \FluentCart\App\Models\Subscription::query()->find((int) $subscription->id);
                $transport->assertComplete();
                $storedOrder = FcFixture::reloadOrder((int) $order->id);

                FcTest::assertSame((int) $subscription->id, (int) $first->id, 'manager returns the exact owned subscription');
                FcTest::assertSame(null, $second, 'provider failure returns without a subscription mutation');
                FcTest::assertSame(
                    ['sub_phase29_confirmed', Status::SUBSCRIPTION_ACTIVE, 'stripe', '2026-08-31 00:00:00', 0],
                    [
                        (string) $afterSuccess->vendor_subscription_id,
                        (string) $afterSuccess->status,
                        (string) $afterSuccess->current_payment_method,
                        (string) $afterSuccess->next_billing_date,
                        (int) $afterSuccess->bill_count,
                    ],
                    'Stripe subscription response maps exact provider identity, status, date, method, and count'
                );
                $activePaymentMethod = $afterSuccess->getMeta('active_payment_method', []);
                FcTest::assertSame(
                    ['pm_phase29_card', 'visa', '4242'],
                    [
                        (string) ($activePaymentMethod['vendor_method_id'] ?? ''),
                        (string) ($activePaymentMethod['brand'] ?? ''),
                        (string) ($activePaymentMethod['last4'] ?? ''),
                    ],
                    'billing information is persisted under the exact active-payment-method keys'
                );
                FcTest::assertSame($successSnapshot, $afterError->getAttributes(), 'Stripe 429 leaves every subscription field unchanged');
                FcTest::assertSame(true, $manager->validate('pi_phase29', ['vendor_charge_id' => 'pi_phase29']), 'matching charge identity validates');
                $mismatch = $manager->validate('pi_phase29', ['vendor_charge_id' => 'pi_other']);
                FcTest::assertSame(
                    ['invalid_vendor_charge_id', 'Invalid vendor charge ID.'],
                    [$mismatch->get_error_code(), $mismatch->get_error_message()],
                    'mismatched charge identity maps to the exact validation error'
                );
                FcTest::assertSame(
                    [Status::PAYMENT_PENDING, 0, ''],
                    [(string) $storedOrder->payment_status, (int) $storedOrder->total_paid, (string) $storedOrder->payment_method],
                    'subscription confirmation never marks the owned Order paid'
                );
            } finally {
                $transport->uninstall();
                if ($settingsInstalled) {
                    remove_filter('fluent_cart/stripe_settings', $phase29StripeSettings, PHP_INT_MAX);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
