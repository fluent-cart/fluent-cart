<?php
/**
 * Phase 29 gateway webhook signature and replay regressions.
 */

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\IPN as PayPalIPN;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API as StripeAPI;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Webhook\IPN as StripeIPN;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Webhook\Webhook as StripeWebhook;

if (!function_exists('getallheaders')) {
    function getallheaders()
    {
        return isset($GLOBALS['fc_phase29_headers'])
            && is_array($GLOBALS['fc_phase29_headers'])
            ? $GLOBALS['fc_phase29_headers']
            : [];
    }
}

$webhookFixture = function ($relativePath, $asObject = false) {
    $root = realpath(dirname(__DIR__) . '/fixtures/webhooks');
    $path = $root !== false
        ? realpath($root . '/' . ltrim((string) $relativePath, '/'))
        : false;
    if (
        $root === false
        || $path === false
        || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0
        || !is_file($path)
    ) {
        throw new RuntimeException('Webhook fixture is missing or outside its root: ' . $relativePath);
    }

    $decoded = json_decode((string) file_get_contents($path), !$asObject);
    if (json_last_error() !== JSON_ERROR_NONE || (!$asObject && !is_array($decoded)) || ($asObject && !is_object($decoded))) {
        throw new RuntimeException('Webhook fixture is malformed: ' . $relativePath);
    }

    return $decoded;
};

$withPhase29PayPalMode = function (callable $callback) {
    $property = new ReflectionProperty(StoreSettings::class, 'cachedStoreSettings');
    $property->setAccessible(true);
    $previous = $property->getValue();
    $settings = is_array($previous) ? $previous : [];
    $settings['order_mode'] = 'phase29';
    $property->setValue(null, $settings);

    fluent_cart_get_option('_paypal_access_token_phase29', [
        'access_token' => 'phase29-provider-token',
        'expires_at'   => time() + 3600,
    ], false);

    try {
        return $callback();
    } finally {
        $property->setValue(null, $previous);
        $GLOBALS['fc_phase29_headers'] = [];
    }
};

$paypalHeaders = function ($signature) {
    return [
        'PayPal-Auth-Algo'         => 'SHA256withRSA',
        'PayPal-Cert-Url'          => 'https://api-m.sandbox.paypal.com/certs/phase29.pem',
        'PayPal-Transmission-Id'   => 'phase29-transmission-id',
        'PayPal-Transmission-Sig'  => (string) $signature,
        'PayPal-Transmission-Time' => '2026-07-31T12:34:56Z',
    ];
};

return [
    [
        'id'            => 'phase29-stripe-signature-rejection',
        'name'          => 'Stripe rejects absent, wrong, and malformed webhook signatures without change',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => true,
        'run'           => function () use ($webhookFixture) {
            $previousPost = $_POST;
            $previousSignature = isset($_SERVER['HTTP_STRIPE_SIGNATURE'])
                ? $_SERVER['HTTP_STRIPE_SIGNATURE']
                : null;
            $baseline = FcFixture::protectedCounts();
            $payload = $webhookFixture('stripe/charge-succeeded.json');
            $results = [];

            try {
                foreach ([
                    'absent'    => null,
                    'wrong'     => 't=1785492800,v1=wrong',
                    'malformed' => 'not-a-stripe-signature',
                ] as $label => $signature) {
                    $_POST = $payload;
                    if ($signature === null) {
                        unset($_SERVER['HTTP_STRIPE_SIGNATURE']);
                    } else {
                        $_SERVER['HTTP_STRIPE_SIGNATURE'] = $signature;
                    }
                    $results[$label] = (new StripeAPI())->verifyIPN();
                }

                $allRejected = true;
                $observed = [];
                foreach ($results as $label => $result) {
                    $allRejected = $allRejected && is_wp_error($result);
                    $observed[$label] = is_wp_error($result)
                        ? $result->get_error_code()
                        : (isset($result->id) ? (string) $result->id : gettype($result));
                }

                FcTest::assertSame(
                    $baseline,
                    FcFixture::protectedCounts(),
                    'invalid Stripe signature probes preserve protected rows'
                );
                if ($allRejected) {
                    FcTest::fail('KNOWN-FAILURE unexpectedly passed; reclassify Stripe signature verification.');
                } elseif ($observed === [
                    'absent'    => 'evt_phase29_stripe_charge_1',
                    'wrong'     => 'evt_phase29_stripe_charge_1',
                    'malformed' => 'evt_phase29_stripe_charge_1',
                ]) {
                    FcTest::skip(
                        'KNOWN-FAILURE — Stripe API::verifyIPN() accepts the canned event ID '
                        . 'with absent, wrong, and malformed Stripe-Signature headers; observed='
                        . wp_json_encode($observed)
                    );
                } else {
                    FcTest::fail(
                        'Stripe signature rejection partially drifted: ' . wp_json_encode($observed)
                    );
                }
            } finally {
                $_POST = $previousPost;
                if ($previousSignature === null) {
                    unset($_SERVER['HTTP_STRIPE_SIGNATURE']);
                } else {
                    $_SERVER['HTTP_STRIPE_SIGNATURE'] = $previousSignature;
                }
            }
        },
    ],
    [
        'id'            => 'phase29-stripe-checkout-session-replay',
        'name'          => 'Stripe checkout-session replay is an exact pending-state no-op',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($webhookFixture) {
            try {
                FcFixture::customer();
                $order = FcFixture::order([
                    'subtotal'     => 1001,
                    'tax_total'    => 0,
                    'total_amount' => 1001,
                ]);
                $transaction = OrderTransaction::query()->create([
                    'order_id'            => (int) $order->id,
                    'order_type'          => (string) $order->type,
                    'transaction_type'    => Status::TRANSACTION_TYPE_CHARGE,
                    'payment_method'      => 'stripe',
                    'payment_mode'        => 'test',
                    'payment_method_type' => '',
                    'status'              => Status::TRANSACTION_PENDING,
                    'currency'            => 'USD',
                    'total'               => 1001,
                    'rate'                => 1,
                    'meta'                => ['session_id' => 'cs_phase29_checkout_1'],
                ]);
                FcTest::assert((int) $transaction->id > 0, 'owned pending transaction is captured immediately');

                $event = $webhookFixture('stripe/checkout-session-completed.json', true);
                $handler = new StripeIPN();
                $first = $handler->handleCheckoutSessionCompleted([
                    'event' => $event,
                    'order' => $order,
                ]);
                $afterFirst = OrderTransaction::query()->find((int) $transaction->id);
                $firstSnapshot = $afterFirst ? $afterFirst->getAttributes() : [];

                $second = $handler->handleCheckoutSessionCompleted([
                    'event' => $event,
                    'order' => $order,
                ]);
                $afterSecond = OrderTransaction::query()->find((int) $transaction->id);
                $storedOrder = FcFixture::reloadOrder((int) $order->id);
                $transactionCount = OrderTransaction::query()
                    ->where('order_id', (int) $order->id)
                    ->count();

                FcTest::assertSame(true, $first, 'first canned Stripe checkout event is handled');
                FcTest::assertSame(true, $second, 'replayed canned Stripe checkout event returns cleanly');
                FcTest::assertSame(
                    'pi_phase29_checkout_1',
                    (string) $afterSecond->vendor_charge_id,
                    'first delivery maps the exact provider payment-intent identity'
                );
                FcTest::assertSame(1, (int) $transactionCount, 'replay creates no duplicate transaction');
                FcTest::assertSame(
                    $firstSnapshot,
                    $afterSecond->getAttributes(),
                    'same Stripe event ID changes no physical transaction field on replay'
                );
                FcTest::assertSame(
                    [Status::PAYMENT_PENDING, 0, ''],
                    [
                        (string) $storedOrder->payment_status,
                        (int) $storedOrder->total_paid,
                        (string) $storedOrder->payment_method,
                    ],
                    'the Order remains inert, pending, and unpaid'
                );
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase29-stripe-unknown-event',
        'name'          => 'Stripe ignores an unknown signed-event shape without error',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($webhookFixture) {
            $baseline = FcFixture::protectedCounts();
            $webhook = new StripeWebhook();
            $result = $webhook->processAndInsertOrderByEvent(
                $webhookFixture('stripe/unknown-event.json', true)
            );

            FcTest::assertSame(false, $result, 'unknown Stripe event type returns the documented no-op value');
            FcTest::assertSame(
                'Event type has no order resolver.',
                $webhook->getUnresolvedReason(),
                'an unresolvable event type is reported as unhandled, not as unrelated'
            );
            FcTest::assertSame($baseline, FcFixture::protectedCounts(), 'unknown Stripe event changes no protected row');
        },
    ],
    [
        'id'            => 'phase29-stripe-cancel-before-create',
        'name'          => 'Stripe cancellation before local subscription creation changes nothing',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($webhookFixture) {
            $subscriptionCount = Subscription::query()->count();
            $baseline = FcFixture::protectedCounts();
            $webhook = new StripeWebhook();
            $result = $webhook->processAndInsertOrderByEvent(
                $webhookFixture('stripe/subscription-deleted-before-create.json', true)
            );

            FcTest::assertSame(null, $result, 'out-of-order Stripe cancellation finds no local order');
            FcTest::assertSame(
                'Event carries no reference to a local order.',
                $webhook->getUnresolvedReason(),
                'a resolvable event type with no local match is reported as unrelated, not as unhandled'
            );
            FcTest::assertSame(
                (int) $subscriptionCount,
                (int) Subscription::query()->count(),
                'out-of-order Stripe cancellation creates or changes no subscription'
            );
            FcTest::assertSame($baseline, FcFixture::protectedCounts(), 'out-of-order Stripe event preserves protected rows');
        },
    ],
    [
        'id'            => 'phase29-paypal-signature-rejection',
        'name'          => 'PayPal rejects absent, wrong, and malformed webhook signatures without change',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($paypalHeaders, $withPhase29PayPalMode) {
            $baseline = FcFixture::protectedCounts();
            $transport = new FcProviderHarness();
            $verifyUrl = 'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature';
            $transport
                ->expect('POST', $verifyUrl, 'paypal/webhook-verification-failure.json')
                ->expect('POST', $verifyUrl, 'paypal/webhook-verification-failure.json');
            $transport->install();

            try {
                $withPhase29PayPalMode(function () use ($paypalHeaders, $transport) {
                    $handler = new PayPalIPN();
                    $GLOBALS['fc_phase29_headers'] = [];
                    $absent = $handler->verifyWebhook('WH-PHASE29-SIGNATURE');

                    $GLOBALS['fc_phase29_headers'] = $paypalHeaders('wrong-signature');
                    $wrong = $handler->verifyWebhook('WH-PHASE29-SIGNATURE');

                    $GLOBALS['fc_phase29_headers'] = $paypalHeaders('%%%malformed%%%');
                    $malformed = $handler->verifyWebhook('WH-PHASE29-SIGNATURE');

                    $transport->assertComplete();
                    $requests = $transport->requests();
                    FcTest::assertSame(
                        ['webhook_header_missing', 'webhook_verification_failed', 'webhook_verification_failed'],
                        [
                            is_wp_error($absent) ? $absent->get_error_code() : gettype($absent),
                            is_wp_error($wrong) ? $wrong->get_error_code() : gettype($wrong),
                            is_wp_error($malformed) ? $malformed->get_error_code() : gettype($malformed),
                        ],
                        'all invalid PayPal signature shapes are rejected exactly'
                    );
                    FcTest::assertSame(
                        ['wrong-signature', '%%%malformed%%%'],
                        [
                            $requests[0]['body']['transmission_sig'],
                            $requests[1]['body']['transmission_sig'],
                        ],
                        'PayPal verification receives the exact canned invalid signatures'
                    );
                });
                FcTest::assertSame($baseline, FcFixture::protectedCounts(), 'invalid PayPal signatures preserve protected rows');
            } finally {
                $transport->uninstall();
                $GLOBALS['fc_phase29_headers'] = [];
            }
        },
    ],
    [
        'id'            => 'phase29-paypal-subscription-cancel-replay',
        'name'          => 'PayPal processes one signed cancellation exactly once across replay',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($paypalHeaders, $webhookFixture, $withPhase29PayPalMode) {
            $disableIntegrations = '__return_empty_array';
            $disableNotifications = '__return_false';
            $canceledEvents = 0;
            $captureCancellation = function () use (&$canceledEvents) {
                $canceledEvents++;
            };
            add_filter('fluent_cart/integration/order_integrations', $disableIntegrations, PHP_INT_MAX, 1);
            add_filter('fluent_cart/should_send_email_notification', $disableNotifications, 1, 2);
            add_action('fluent_cart/subscription_canceled', $captureCancellation, PHP_INT_MAX, 1);

            $transport = new FcProviderHarness();
            $verifyUrl = 'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature';
            $transport
                ->expect('POST', $verifyUrl, 'paypal/webhook-verification-success.json')
                ->expect('POST', $verifyUrl, 'paypal/webhook-verification-success.json');
            $transport->install();

            try {
                $product = FcDomainFixture::product([
                    'payment_type' => 'subscription',
                ]);
                $order = FcDomainFixture::orderWithItem(
                    (int) $product['post']->ID,
                    (int) $product['variation']->id,
                    1,
                    ['type' => 'subscription']
                );
                $subscription = FcDomainFixture::subscription(
                    $order,
                    (int) $product['post']->ID,
                    (int) $product['variation']->id,
                    Status::SUBSCRIPTION_ACTIVE,
                    'phase29-paypal-replay',
                    ['current_payment_method' => 'paypal']
                );
                $event = $webhookFixture('paypal/subscription-cancelled.json');
                $event['resource']['id'] = (string) $subscription->vendor_subscription_id;

                $withPhase29PayPalMode(function () use (
                    $event,
                    $paypalHeaders,
                    $subscription,
                    $transport
                ) {
                    $handler = new PayPalIPN();
                    FcTest::assert(
                        has_action(
                            'fluent_cart/payments/paypal/webhook_billing_subscription_cancelled'
                        ) !== false,
                        'production PayPal cancellation handler is registered'
                    );
                    $GLOBALS['fc_phase29_headers'] = $paypalHeaders('valid-canned-signature');

                    $firstVerified = $handler->verifyWebhook('WH-PHASE29-SIGNATURE');
                    if ($firstVerified === true) {
                        $handler->processPaypalWebhookEvents($event);
                    }
                    $afterFirst = Subscription::query()->find((int) $subscription->id);
                    $firstSnapshot = $afterFirst ? $afterFirst->getAttributes() : [];

                    $secondVerified = $handler->verifyWebhook('WH-PHASE29-SIGNATURE');
                    if ($secondVerified === true) {
                        $handler->processPaypalWebhookEvents($event);
                    }
                    $afterSecond = Subscription::query()->find((int) $subscription->id);

                    $transport->assertComplete();
                    FcTest::assertSame([true, true], [$firstVerified, $secondVerified], 'both signed PayPal deliveries verify');
                    FcTest::assertSame(
                        Status::SUBSCRIPTION_CANCELED,
                        (string) $afterFirst->status,
                        'first signed delivery applies the cancellation'
                    );
                    FcTest::assertSame(
                        '2026-07-31 12:34:56',
                        (string) $afterFirst->canceled_at,
                        'first signed delivery preserves the exact provider cancellation time'
                    );
                    FcTest::assertSame(
                        $firstSnapshot,
                        $afterSecond->getAttributes(),
                        'same PayPal event ID changes no physical subscription field on replay'
                    );
                });

                $activityCount = Activity::query()
                    ->where('module_type', Subscription::class)
                    ->where('module_id', (int) $subscription->id)
                    ->count();
                $storedOrder = FcFixture::reloadOrder((int) $order->id);
                FcTest::assertSame(1, $canceledEvents, 'replay dispatches the cancellation domain event exactly once');
                FcTest::assertSame(1, (int) $activityCount, 'replay records exactly one owned lifecycle activity');
                FcTest::assertSame(
                    [Status::PAYMENT_PENDING, 0, ''],
                    [
                        (string) $storedOrder->payment_status,
                        (int) $storedOrder->total_paid,
                        (string) $storedOrder->payment_method,
                    ],
                    'PayPal cancellation replay leaves the Order inert and unpaid'
                );
            } finally {
                $transport->uninstall();
                remove_action('fluent_cart/subscription_canceled', $captureCancellation, PHP_INT_MAX);
                remove_filter('fluent_cart/should_send_email_notification', $disableNotifications, 1);
                remove_filter('fluent_cart/integration/order_integrations', $disableIntegrations, PHP_INT_MAX);
                $GLOBALS['fc_phase29_headers'] = [];
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase29-paypal-unknown-event',
        'name'          => 'PayPal ignores an unknown webhook event without error',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($webhookFixture) {
            $baseline = FcFixture::protectedCounts();
            $subscriptionCount = Subscription::query()->count();
            (new PayPalIPN())->processPaypalWebhookEvents(
                $webhookFixture('paypal/unknown-event.json')
            );

            FcTest::assertSame(
                (int) $subscriptionCount,
                (int) Subscription::query()->count(),
                'unknown PayPal event creates or changes no subscription'
            );
            FcTest::assertSame($baseline, FcFixture::protectedCounts(), 'unknown PayPal event preserves protected rows');
        },
    ],
    [
        'id'            => 'phase29-paypal-cancel-before-create',
        'name'          => 'PayPal cancellation before local subscription creation changes nothing',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($webhookFixture) {
            $baseline = FcFixture::protectedCounts();
            $subscriptionCount = Subscription::query()->count();
            $activityCount = Activity::query()->count();
            (new PayPalIPN())->processPaypalWebhookEvents(
                $webhookFixture('paypal/subscription-cancelled-before-create.json')
            );

            FcTest::assertSame(
                (int) $subscriptionCount,
                (int) Subscription::query()->count(),
                'out-of-order PayPal cancellation creates or changes no subscription'
            );
            FcTest::assertSame(
                (int) $activityCount,
                (int) Activity::query()->count(),
                'out-of-order PayPal cancellation creates no activity'
            );
            FcTest::assertSame($baseline, FcFixture::protectedCounts(), 'out-of-order PayPal event preserves protected rows');
        },
    ],
];
