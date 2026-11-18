<?php
/**
 * gateway webhook signature and replay regressions.
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\IPN as PayPalIPN;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API as StripeAPI;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Webhook\IPN as StripeIPN;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Webhook\Webhook as StripeWebhook;

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

return [
    [
        'id'            => 'stripe-checkout-session-replay',
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
        'id'            => 'stripe-unknown-event',
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
        'id'            => 'stripe-cancel-before-create',
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
        'id'            => 'paypal-unknown-event',
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
        'id'            => 'paypal-cancel-before-create',
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
