<?php
/**
 * Stripe subscription create-guard.
 *
 * A default_incomplete subscription sits in `incomplete` for the whole window
 * the buyer needs to confirm (3DS). Deleting it mid-confirm voids the invoice
 * and cancels the PaymentIntent the browser is about to confirm
 * (payment_intent_unexpected_state) — and because the pending transaction's
 * idempotency seed has not rolled, the follow-up create replays Stripe's
 * 24h-cached response for the deleted subscription.
 *
 * The guard therefore: reuses an owned, still-confirmable incomplete sub (or a
 * trialing one still awaiting card setup — a $0 first invoice skips
 * `incomplete` entirely, riding a pending_setup_intent instead);
 * cancels an owned dead one and clears the local vendor id so the caller rolls
 * the idempotency key; fails closed when the cancel fails; and never touches a
 * subscription it does not own (metadata.fct_ref_id mismatch — e.g. the
 * previous cycle's sub during a renewal, which the pro renewal handler cancels
 * only after payment succeeds) or a non-Stripe vendor id.
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Processor;
use FluentCart\Framework\Support\Arr;

$phase30GuardStripeSettings = function ($settings) {
    $settings['checkout_mode'] = 'onsite';
    $settings['test_publishable_key'] = 'pk_test_phase30_guard';
    $settings['test_secret_key'] = 'sk_test_phase30_guard';

    return $settings;
};

// Returns a pending subscription whose vendor_subscription_id has been swapped
// to $vendorSubId (fixture default is a p8s- marker, which the guard's sub_
// prefix check would skip) with the cleanup canary recaptured.
$phase30GuardSubscription = function ($suffix, $vendorSubId) {
    $product = FcDomainFixture::product([
        'payment_type' => 'subscription',
        'item_price'   => 1000,
    ]);
    $order = FcDomainFixture::orderWithItem(
        (int) $product['post']->ID,
        (int) $product['variation']->id,
        1,
        [
            'type'         => Status::ORDER_TYPE_SUBSCRIPTION,
            'currency'     => 'USD',
            'subtotal'     => 1000,
            'tax_total'    => 0,
            'total_amount' => 1000,
        ]
    );

    $subscription = FcDomainFixture::subscription(
        $order,
        (int) $product['post']->ID,
        (int) $product['variation']->id,
        Status::SUBSCRIPTION_PENDING,
        $suffix,
        ['vendor_customer_id' => 'cus_phase30_guard']
    );

    $markerVendorId = (string) $subscription->vendor_subscription_id;
    $subscription->update(['vendor_subscription_id' => $vendorSubId]);
    FcDomainFixture::recaptureSubscriptionVendorId((int) $subscription->id, $markerVendorId);

    return $subscription;
};

$phase30GuardSubUrl = function ($vendorSubId) {
    return 'https://api.stripe.com/v1/subscriptions/' . $vendorSubId
        . '?' . http_build_query([
            'expand' => [
                'latest_invoice.confirmation_secret',
                'latest_invoice.payment_intent',
                'pending_setup_intent',
            ],
        ]);
};

$phase30GuardRequestData = function () {
    return [
        'customer'         => 'cus_phase30_guard',
        'payment_behavior' => 'default_incomplete',
        'items'            => [
            [
                'plan'     => 'price_phase30_guard',
                'quantity' => 1,
            ],
        ],
    ];
};

$phase30GuardInvoke = function ($subscription, $orderUuid, $requestData) {
    $processor = new Processor();
    $guard = new ReflectionMethod($processor, 'guardExistingRemoteSubscription');
    $guard->setAccessible(true);

    return $guard->invoke($processor, $subscription, (object) ['uuid' => $orderUuid], $requestData);
};

$phase30GuardCase = function ($suffix, $vendorSubId, $orderUuid, array $expectations, callable $assert) use (
    $phase30GuardStripeSettings,
    $phase30GuardSubscription,
    $phase30GuardRequestData,
    $phase30GuardInvoke
) {
    $transport = new FcProviderHarness();
    $settingsInstalled = false;

    try {
        $subscription = $phase30GuardSubscription($suffix, $vendorSubId);

        add_filter('fluent_cart/stripe_settings', $phase30GuardStripeSettings, PHP_INT_MAX, 1);
        $settingsInstalled = true;

        foreach ($expectations as $expectation) {
            $transport->expect($expectation[0], $expectation[1], $expectation[2]);
        }
        $transport->install();

        $result = $phase30GuardInvoke($subscription, $orderUuid, $phase30GuardRequestData());
        $transport->assertComplete();

        $stored = Subscription::query()->find((int) $subscription->id);
        $assert($result, $stored, count($transport->requests()));
    } finally {
        if (isset($subscription)) {
            Subscription::query()
                ->where('id', (int) $subscription->id)
                ->update(['vendor_subscription_id' => $vendorSubId]);
        }
        $transport->uninstall();
        if ($settingsInstalled) {
            remove_filter('fluent_cart/stripe_settings', $phase30GuardStripeSettings, PHP_INT_MAX);
        }
        FcDomainFixture::cleanupAll();
    }
};

return [
    [
        'id'            => 'phase30-stripe-guard-reuses-confirmable-incomplete-subscription',
        'name'          => 'Stripe create-guard reuses an owned incomplete subscription the buyer can still confirm',
        'kind'          => 'behavior',
        'phase'         => 30,
        'known_failure' => false,
        'run'           => function () use ($phase30GuardCase, $phase30GuardSubUrl) {
            $vendorSubId = 'sub_phase30_incomplete';
            $phase30GuardCase(
                'guard-reuse',
                $vendorSubId,
                'phase30-guard-order-ref',
                [['GET', $phase30GuardSubUrl($vendorSubId), 'stripe/subscription-incomplete-confirmable.json']],
                function ($result, $stored, $requestCount) use ($vendorSubId) {
                    FcTest::assertSame(
                        ['sub_phase30_incomplete', 'pi_phase30_guard_secret_live', 1, $vendorSubId],
                        [
                            is_array($result) ? (string) Arr::get($result, 'id') : $result,
                            is_array($result)
                                ? (string) Arr::get($result, 'latest_invoice.confirmation_secret.client_secret')
                                : '',
                            $requestCount,
                            (string) $stored->vendor_subscription_id,
                        ],
                        'a confirmable owned incomplete subscription is handed back for reuse, never deleted'
                    );
                }
            );
        },
    ],
    [
        'id'            => 'phase30-stripe-guard-cancel-rolls-the-idempotency-key',
        'name'          => 'Stripe create-guard clears the local vendor id when it cancels an owned dead subscription',
        'kind'          => 'behavior',
        'phase'         => 30,
        'known_failure' => false,
        'run'           => function () use ($phase30GuardCase, $phase30GuardSubUrl) {
            $vendorSubId = 'sub_phase30_dead';
            $phase30GuardCase(
                'guard-cancel',
                $vendorSubId,
                'phase30-guard-order-ref',
                [
                    ['GET', $phase30GuardSubUrl($vendorSubId), 'stripe/subscription-incomplete-dead-intent.json'],
                    ['DELETE', 'https://api.stripe.com/v1/subscriptions/' . $vendorSubId, 'stripe/subscription-canceled.json'],
                ],
                function ($result, $stored, $requestCount) use ($vendorSubId) {
                    FcTest::assertSame(
                        [null, '', $vendorSubId, 2],
                        [
                            $result,
                            (string) $stored->vendor_subscription_id,
                            (string) Arr::get((array) $stored->config, 'stripe_replaced_vendor_sub_id'),
                            $requestCount,
                        ],
                        'a dead owned subscription is deleted, the vendor id cleared, and the cancelled id persisted so retries keep the rolled key'
                    );
                }
            );
        },
    ],
    [
        'id'            => 'phase30-stripe-guard-fails-closed-when-cancel-fails',
        'name'          => 'Stripe create-guard refuses the create when it cannot cancel the stale subscription',
        'kind'          => 'behavior',
        'phase'         => 30,
        'known_failure' => false,
        'run'           => function () use ($phase30GuardCase, $phase30GuardSubUrl) {
            $vendorSubId = 'sub_phase30_dead';
            $phase30GuardCase(
                'guard-cancel-failed',
                $vendorSubId,
                'phase30-guard-order-ref',
                [
                    ['GET', $phase30GuardSubUrl($vendorSubId), 'stripe/subscription-incomplete-dead-intent.json'],
                    ['DELETE', 'https://api.stripe.com/v1/subscriptions/' . $vendorSubId, 'stripe/rate-limit.json'],
                ],
                function ($result, $stored, $requestCount) use ($vendorSubId) {
                    FcTest::assertSame(
                        [true, 'stripe_subscription_cancel_failed', $vendorSubId, '', 2],
                        [
                            is_wp_error($result),
                            is_wp_error($result) ? $result->get_error_code() : '',
                            (string) $stored->vendor_subscription_id,
                            (string) Arr::get((array) $stored->config, 'stripe_replaced_vendor_sub_id', ''),
                            $requestCount,
                        ],
                        'an uncancelled stale subscription stops the create instead of racing a second confirmable one'
                    );
                }
            );
        },
    ],
    [
        'id'            => 'phase30-stripe-guard-marks-remotely-canceled-subscription-replaced',
        'name'          => 'Stripe create-guard marks an owned remotely-canceled subscription replaced so retries keep the rolled key',
        'kind'          => 'behavior',
        'phase'         => 30,
        'known_failure' => false,
        'run'           => function () use ($phase30GuardCase, $phase30GuardSubUrl) {
            $vendorSubId = 'sub_phase30_replaced';
            $phase30GuardCase(
                'guard-remote-canceled',
                $vendorSubId,
                'phase30-guard-order-ref',
                [['GET', $phase30GuardSubUrl($vendorSubId), 'stripe/subscription-canceled-owned.json']],
                function ($result, $stored, $requestCount) use ($vendorSubId) {
                    FcTest::assertSame(
                        [null, '', $vendorSubId, 1],
                        [
                            $result,
                            (string) $stored->vendor_subscription_id,
                            (string) Arr::get((array) $stored->config, 'stripe_replaced_vendor_sub_id'),
                            $requestCount,
                        ],
                        'a remotely-canceled owned subscription is marked replaced without a DELETE call so the recreate replays the post-cancel key'
                    );
                }
            );
        },
    ],
    [
        'id'            => 'phase30-stripe-guard-hands-off-unowned-subscription',
        'name'          => 'Stripe create-guard never touches an incomplete subscription another order created',
        'kind'          => 'behavior',
        'phase'         => 30,
        'known_failure' => false,
        'run'           => function () use ($phase30GuardCase, $phase30GuardSubUrl) {
            $vendorSubId = 'sub_phase30_incomplete';
            $phase30GuardCase(
                'guard-unowned',
                $vendorSubId,
                'phase30-other-order-ref',
                [['GET', $phase30GuardSubUrl($vendorSubId), 'stripe/subscription-incomplete-confirmable.json']],
                function ($result, $stored, $requestCount) use ($vendorSubId) {
                    FcTest::assertSame(
                        [null, $vendorSubId, 1],
                        [$result, (string) $stored->vendor_subscription_id, $requestCount],
                        'an unowned incomplete subscription is neither reused nor deleted (renewal old-cycle hands-off)'
                    );
                }
            );
        },
    ],
    [
        'id'            => 'phase30-stripe-guard-skips-non-stripe-vendor-id',
        'name'          => 'Stripe create-guard skips a non-Stripe vendor subscription id without any API call',
        'kind'          => 'behavior',
        'phase'         => 30,
        'known_failure' => false,
        'run'           => function () use ($phase30GuardCase) {
            $phase30GuardCase(
                'guard-paypal-id',
                'I-PHASE30PAYPAL',
                'phase30-guard-order-ref',
                [],
                function ($result, $stored, $requestCount) {
                    FcTest::assertSame(
                        [null, 'I-PHASE30PAYPAL', 0],
                        [$result, (string) $stored->vendor_subscription_id, $requestCount],
                        'a PayPal vendor id on a Stripe renewal attempt is left alone with zero Stripe calls'
                    );
                }
            );
        },
    ],
    [
        'id'            => 'phase30-stripe-guard-reuses-trialing-subscription-awaiting-card-setup',
        'name'          => 'Stripe create-guard reuses an owned trialing subscription whose card setup can still be retried',
        'kind'          => 'behavior',
        'phase'         => 30,
        'known_failure' => false,
        'run'           => function () use ($phase30GuardCase, $phase30GuardSubUrl) {
            $vendorSubId = 'sub_phase30_trialing';
            $phase30GuardCase(
                'guard-trial-retry',
                $vendorSubId,
                'phase30-guard-order-ref',
                [['GET', $phase30GuardSubUrl($vendorSubId), 'stripe/subscription-trialing-setup-retry.json']],
                function ($result, $stored, $requestCount) use ($vendorSubId) {
                    FcTest::assertSame(
                        ['sub_phase30_trialing', 'seti_phase30_guard_secret_live', 1, $vendorSubId],
                        [
                            is_array($result) ? (string) Arr::get($result, 'id') : $result,
                            is_array($result)
                                ? (string) Arr::get($result, 'pending_setup_intent.client_secret')
                                : '',
                            $requestCount,
                            (string) $stored->vendor_subscription_id,
                        ],
                        'a trialing sub with no payment method hands its setup intent back for a card retry instead of blocking'
                    );
                }
            );
        },
    ],
    [
        'id'            => 'phase30-stripe-guard-blocks-trialing-subscription-with-card-attached',
        'name'          => 'Stripe create-guard still blocks a trialing subscription whose card setup already succeeded',
        'kind'          => 'behavior',
        'phase'         => 30,
        'known_failure' => false,
        'run'           => function () use ($phase30GuardCase, $phase30GuardSubUrl) {
            $vendorSubId = 'sub_phase30_trialing';
            $phase30GuardCase(
                'guard-trial-live',
                $vendorSubId,
                'phase30-guard-order-ref',
                [['GET', $phase30GuardSubUrl($vendorSubId), 'stripe/subscription-trialing-card-attached.json']],
                function ($result, $stored, $requestCount) use ($vendorSubId) {
                    FcTest::assertSame(
                        [true, 'stripe_subscription_already_active', $vendorSubId, 1],
                        [
                            is_wp_error($result),
                            is_wp_error($result) ? $result->get_error_code() : '',
                            (string) $stored->vendor_subscription_id,
                            $requestCount,
                        ],
                        'a trialing sub with an attached payment method is live and blocks the create'
                    );
                }
            );
        },
    ],
    [
        'id'            => 'phase30-stripe-guard-blocks-active-regardless-of-owner',
        'name'          => 'Stripe create-guard blocks the create when the remote subscription is active, whoever owns it',
        'kind'          => 'behavior',
        'phase'         => 30,
        'known_failure' => false,
        'run'           => function () use ($phase30GuardCase, $phase30GuardSubUrl) {
            $vendorSubId = 'sub_phase30_active';
            $phase30GuardCase(
                'guard-active',
                $vendorSubId,
                'phase30-guard-order-ref',
                [['GET', $phase30GuardSubUrl($vendorSubId), 'stripe/subscription-active-foreign-owner.json']],
                function ($result, $stored, $requestCount) use ($vendorSubId) {
                    FcTest::assertSame(
                        [true, 'stripe_subscription_already_active', $vendorSubId, 1],
                        [
                            is_wp_error($result),
                            is_wp_error($result) ? $result->get_error_code() : '',
                            (string) $stored->vendor_subscription_id,
                            $requestCount,
                        ],
                        'a live remote subscription blocks the create even when this order did not create it'
                    );
                }
            );
        },
    ],
];
