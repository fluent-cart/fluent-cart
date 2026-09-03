<?php
/**
 * Onsite Stripe 3D Secure redirect return.
 *
 * `stripe.confirmPayment({redirect: 'if_required'})` renders the 3DS challenge
 * inline, but some issuers force a full redirect to their ACS page. Stripe then
 * sends the buyer back to our `return_url` — the receipt page, carrying our own
 * `trx_hash` plus Stripe's `payment_intent` / `redirect_status`. Nothing read
 * those parameters, so:
 *
 *   - a buyer who PASSED 3DS through the redirect path was never confirmed
 *     locally (only a webhook could save the order); and
 *   - a buyer who FAILED left the transaction `pending` forever. That matters
 *     beyond the row itself: `CheckoutProcessor` bumps `payment_attempt` only
 *     when the transaction is `failed`, so the retry reuses the same
 *     idempotency seed, replays Stripe's 24h-cached response for a subscription
 *     the create-guard has since deleted, and answers
 *     `payment_intent_unexpected_state` for a full day.
 *
 * The handler is a public, unauthenticated surface (the return URL is a plain
 * GET the buyer's browser follows), so it gates twice before touching Stripe:
 * `trx_hash` must resolve to a local transaction, and that transaction's stored
 * `vendor_charge_id` must equal the intent id in the URL. `redirect_status` is
 * never trusted — the intent is re-fetched for its authoritative status.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Confirmations;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Processor;

$phase29ReturnStripeSettings = function ($settings) {
    $settings['checkout_mode'] = 'onsite';
    $settings['test_publishable_key'] = 'pk_test_phase29_fixture';
    $settings['test_secret_key'] = 'sk_test_phase29_fixture';

    return $settings;
};

$phase29ReturnTransaction = function ($vendorChargeId, $total = 12345) {
    $product = FcDomainFixture::product(['item_price' => $total]);
    $order = FcDomainFixture::orderWithItem(
        (int) $product['post']->ID,
        (int) $product['variation']->id,
        1,
        [
            'currency'     => 'USD',
            'subtotal'     => $total,
            'tax_total'    => 0,
            'total_amount' => $total,
        ]
    );

    $transaction = OrderTransaction::query()->create([
        'order_id'            => (int) $order->id,
        'order_type'          => (string) $order->type,
        'transaction_type'    => Status::TRANSACTION_TYPE_CHARGE,
        'payment_method'      => 'stripe',
        'payment_mode'        => 'test',
        'payment_method_type' => 'card',
        'status'              => Status::TRANSACTION_PENDING,
        'currency'            => 'USD',
        'total'               => $total,
        'rate'                => 1,
        'vendor_charge_id'    => (string) $vendorChargeId,
        'meta'                => ['fixture_case' => 'phase29-stripe-onsite-3ds-return'],
    ]);

    if (!$transaction || (int) $transaction->id <= 0) {
        throw new RuntimeException('Phase 29 3DS-return transaction was not created.');
    }

    return $transaction;
};

$phase29ReturnIntentUrl = function ($intentId) {
    return 'https://api.stripe.com/v1/payment_intents/' . $intentId
        . '?' . http_build_query(['expand' => ['latest_charge']]);
};

return [
    [
        'id'            => 'phase29-stripe-3ds-return-marks-failed-intent-failed',
        'name'          => 'Stripe 3DS return marks the transaction failed when the intent lost its payment method',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29ReturnTransaction, $phase29ReturnStripeSettings, $phase29ReturnIntentUrl) {
            $transport = new FcProviderHarness();
            $settingsInstalled = false;

            try {
                $transaction = $phase29ReturnTransaction('pi_phase29_failed');

                add_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX, 1);
                $settingsInstalled = true;
                $transport
                    ->expect('GET', $phase29ReturnIntentUrl('pi_phase29_failed'), 'stripe/payment-intent-requires-payment-method.json')
                    ->install();

                (new Confirmations())->confirmRedirectReturn(
                    (string) $transaction->uuid,
                    'pi_phase29_failed'
                );
                $transport->assertComplete();

                $stored = OrderTransaction::query()->find((int) $transaction->id);
                $order = FcFixture::reloadOrder((int) $transaction->order_id);

                FcTest::assertSame(
                    [Status::TRANSACTION_FAILED, Status::PAYMENT_PENDING, 0],
                    [
                        (string) $stored->status,
                        (string) $order->payment_status,
                        (int) $order->total_paid,
                    ],
                    'a terminally failed intent marks the transaction failed and pays nothing'
                );
                FcTest::assertSame(
                    1,
                    (int) Activity::query()
                        ->where('module_name', 'order')
                        ->where('module_id', (int) $transaction->order_id)
                        ->where('title', 'Stripe 3D Secure Authentication Failed')
                        ->where('status', 'error')
                        ->count(),
                    'the authentication failure is named as such in the order log'
                );
            } finally {
                $transport->uninstall();
                if ($settingsInstalled) {
                    remove_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase29-stripe-success-confirmation-loses-to-a-refund-that-landed-first',
        'name'          => 'Stripe success confirmation refuses a row refunded after the model was loaded',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29ReturnTransaction, $phase29ReturnStripeSettings) {
            $settingsInstalled = false;

            try {
                $transaction = $phase29ReturnTransaction('pi_phase29_refund_race');

                add_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX, 1);
                $settingsInstalled = true;

                // The refund lands while the Stripe round-trip is in flight, so the
                // model the handler is holding still reads `pending`. Both guards
                // have to see the row as it is now, not as it was loaded.
                OrderTransaction::query()
                    ->where('id', (int) $transaction->id)
                    ->update(['status' => Status::TRANSACTION_REFUNDED]);

                $intent = [
                    'status'        => 'succeeded',
                    'latest_charge' => [
                        'id'       => 'ch_phase29_refund_race',
                        'status'   => 'succeeded',
                        'amount'   => 12345,
                        'currency' => 'usd',
                        'livemode' => false,
                    ],
                ];

                $confirmations = new Confirmations();
                $applyOutcome = new ReflectionMethod($confirmations, 'applyIntentOutcome');
                $applyOutcome->setAccessible(true);
                $confirmed = $applyOutcome->invoke($confirmations, $transaction, 'pi_phase29_refund_race', $intent);

                $afterOutcome = OrderTransaction::query()->find((int) $transaction->id);

                // Second guard, exercised directly: the shared confirmation writer is
                // reachable from webhook replays too, and it must not resurrect the
                // row even when handed a succeeded charge.
                $confirmations->confirmPaymentSuccessByCharge($transaction, [
                    'charge'    => Arr::get($intent, 'latest_charge'),
                    'intent_id' => 'pi_phase29_refund_race',
                ]);

                $afterWriter = OrderTransaction::query()->find((int) $transaction->id);

                // A stalled-challenge replay against the same settled row must not
                // annotate the order either — the only thing standing between the
                // stale model and that log entry is the re-read.
                $stalled = $applyOutcome->invoke($confirmations, $transaction, 'pi_phase29_refund_race', [
                    'status' => 'requires_action',
                ]);

                FcTest::assertSame(
                    [false, Status::TRANSACTION_REFUNDED, Status::TRANSACTION_REFUNDED, false, 0],
                    [
                        $confirmed,
                        (string) $afterOutcome->status,
                        (string) $afterWriter->status,
                        $stalled,
                        (int) Activity::query()
                            ->where('module_name', 'order')
                            ->where('module_id', (int) $transaction->order_id)
                            ->count(),
                    ],
                    'a refund that landed first survives a succeeded intent arriving behind it'
                );
            } finally {
                if ($settingsInstalled) {
                    remove_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase29-stripe-confirmation-write-loses-to-a-refund-inside-its-own-window',
        'name'          => 'Stripe confirmation write refuses a row refunded mid-method',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29ReturnTransaction, $phase29ReturnStripeSettings) {
            $settingsInstalled = false;
            $transportInstalled = false;

            try {
                $transaction = $phase29ReturnTransaction('pi_phase29_window_race');

                add_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX, 1);
                $settingsInstalled = true;

                // confirmPaymentSuccessByCharge() re-reads the row and then, on a
                // disputed charge, spends a remote round-trip before writing. This
                // transport lands the refund from inside that window — the widest
                // real instance of the gap between the check and the write.
                FcTest::useProviderHttpTransport(function ($args, $url) use ($transaction) {
                    OrderTransaction::query()
                        ->where('id', (int) $transaction->id)
                        ->update(['status' => Status::TRANSACTION_REFUNDED]);

                    return [
                        'response' => ['code' => 200, 'message' => 'OK'],
                        'body'     => wp_json_encode([
                            'id'                   => 'dp_phase29_window_race',
                            'reason'               => 'fraudulent',
                            'status'               => 'needs_response',
                            'is_charge_refundable' => true,
                        ]),
                        'headers'  => ['content-type' => 'application/json'],
                        'cookies'  => [],
                        'filename' => null,
                    ];
                });
                $transportInstalled = true;

                (new Confirmations())->confirmPaymentSuccessByCharge($transaction, [
                    'intent_id' => 'pi_phase29_window_race',
                    'charge'    => [
                        'id'       => 'ch_phase29_window_race',
                        'status'   => 'succeeded',
                        'amount'   => 12345,
                        'currency' => 'usd',
                        'livemode' => false,
                        'disputed' => true,
                        'dispute'  => 'dp_phase29_window_race',
                    ],
                ]);

                $after = OrderTransaction::query()->find((int) $transaction->id);

                FcTest::assertSame(
                    Status::TRANSACTION_REFUNDED,
                    (string) $after->status,
                    'a refund landing inside the confirmation window is not overwritten'
                );
            } finally {
                if ($transportInstalled) {
                    FcTest::clearProviderHttpTransport();
                }
                if ($settingsInstalled) {
                    remove_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase29-stripe-return-never-rewrites-a-settled-transaction',
        'name'          => 'Stripe onsite return and failure report both leave a settled transaction alone',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29ReturnTransaction, $phase29ReturnStripeSettings) {
            // No expectation is installed: a settled row must be refused before
            // any Stripe call, so the harness rejects the request if one is made.
            $transport = new FcProviderHarness();
            $settingsInstalled = false;

            try {
                $transaction = $phase29ReturnTransaction('pi_phase29_settled');

                add_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX, 1);
                $settingsInstalled = true;
                $transport->install();

                // Refunded: downstream of a completed payment, and
                // confirmPaymentSuccessByCharge() guards only `succeeded`, so a
                // replayed return would write `succeeded` straight back over it.
                OrderTransaction::query()
                    ->where('id', (int) $transaction->id)
                    ->update(['status' => Status::TRANSACTION_REFUNDED]);
                $transaction->status = Status::TRANSACTION_REFUNDED;

                $confirmed = (new Confirmations())->confirmRedirectReturn(
                    (string) $transaction->uuid,
                    'pi_phase29_settled'
                );
                $afterReturn = OrderTransaction::query()->find((int) $transaction->id);

                // Authorized: money Stripe is holding for a later capture. A
                // replayed failure report must not call it a decline.
                OrderTransaction::query()
                    ->where('id', (int) $transaction->id)
                    ->update(['status' => Status::TRANSACTION_AUTHORIZED]);
                $transaction->status = Status::TRANSACTION_AUTHORIZED;

                $confirmations = new Confirmations();
                $markFailed = new ReflectionMethod($confirmations, 'markIntentFailed');
                $markFailed->setAccessible(true);
                $markFailed->invoke($confirmations, $transaction, [
                    'is_auth_failure' => true,
                    'detail'          => 'phase29 replayed failure report',
                ]);
                $afterReport = OrderTransaction::query()->find((int) $transaction->id);

                $transport->assertComplete();

                FcTest::assertSame(
                    [false, Status::TRANSACTION_REFUNDED, Status::TRANSACTION_AUTHORIZED, 0],
                    [
                        $confirmed,
                        (string) $afterReturn->status,
                        (string) $afterReport->status,
                        (int) Activity::query()
                            ->where('module_name', 'order')
                            ->where('module_id', (int) $transaction->order_id)
                            ->where('title', 'Stripe 3D Secure Authentication Failed')
                            ->count(),
                    ],
                    'a settled transaction survives both a replayed return and a replayed failure report'
                );
            } finally {
                $transport->uninstall();
                if ($settingsInstalled) {
                    remove_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase29-stripe-confirm-endpoint-reports-the-transaction-status',
        'name'          => 'Stripe confirm endpoint tells the browser what actually landed on the transaction',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29ReturnTransaction, $phase29ReturnStripeSettings, $phase29ReturnIntentUrl) {
            $transport = new FcProviderHarness();
            $settingsInstalled = false;
            $dieFilter = function () {
                return function () {
                    throw new RuntimeException('phase29-json-terminated');
                };
            };
            $captured = '';

            try {
                $transaction = $phase29ReturnTransaction('pi_phase29_endpoint_failed');

                add_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX, 1);
                $settingsInstalled = true;
                $transport
                    ->expect('GET', $phase29ReturnIntentUrl('pi_phase29_endpoint_failed'), 'stripe/payment-intent-requires-payment-method.json')
                    ->install();

                App::request()->set('intentId', 'pi_phase29_endpoint_failed');
                App::request()->set('trx_hash', (string) $transaction->uuid);
                if (!defined('DOING_AJAX')) {
                    define('DOING_AJAX', true);
                }
                add_filter('wp_die_ajax_handler', $dieFilter, PHP_INT_MAX);
                ob_start();
                try {
                    (new Confirmations())->confirmStripePayment();
                } catch (RuntimeException $e) {
                    if ($e->getMessage() !== 'phase29-json-terminated') {
                        throw $e;
                    }
                } finally {
                    $captured = ob_get_clean();
                    remove_filter('wp_die_ajax_handler', $dieFilter, PHP_INT_MAX);
                    App::request()->set('intentId', null);
                    App::request()->set('trx_hash', null);
                }

                $transport->assertComplete();
                $payload = json_decode($captured, true);
                $stored = OrderTransaction::query()->find((int) $transaction->id);

                // The browser releases the checkout button on this field, not on
                // the HTTP status: a 400 is also how an invalid request and an
                // unfinished challenge answer, and neither wrote anything.
                FcTest::assertSame(
                    [Status::TRANSACTION_FAILED, Status::TRANSACTION_FAILED],
                    [
                        (string) Arr::get((array) $payload, 'transaction_status'),
                        (string) $stored->status,
                    ],
                    'the failure response reports the terminal status the row actually reached'
                );
            } finally {
                $transport->uninstall();
                if ($settingsInstalled) {
                    remove_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase29-stripe-3ds-failure-never-overwrites-a-persisted-success',
        'name'          => 'Stripe 3DS failure reporting never flips a persisted success back to failed',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29ReturnTransaction) {
            try {
                $transaction = $phase29ReturnTransaction('pi_phase29_race');

                // The webhook wins the race: `succeeded` is persisted after this
                // request loaded the row, so the in-memory model is stale.
                OrderTransaction::query()
                    ->where('id', (int) $transaction->id)
                    ->update(['status' => Status::TRANSACTION_SUCCEEDED]);

                $confirmations = new Confirmations();
                $markFailed = new ReflectionMethod($confirmations, 'markIntentFailed');
                $markFailed->setAccessible(true);
                $markFailed->invoke($confirmations, $transaction, [
                    'is_auth_failure' => true,
                    'detail'          => 'phase29 stale failure report',
                ]);

                $stored = OrderTransaction::query()->find((int) $transaction->id);

                FcTest::assertSame(
                    Status::TRANSACTION_SUCCEEDED,
                    (string) $stored->status,
                    'a stale failure report leaves a persisted success alone'
                );
                FcTest::assertSame(
                    0,
                    (int) Activity::query()
                        ->where('module_name', 'order')
                        ->where('module_id', (int) $transaction->order_id)
                        ->where('title', 'Stripe 3D Secure Authentication Failed')
                        ->count(),
                    'no failure is logged against the order that already succeeded'
                );

                // The product fixture is single-owner per process, so the second
                // half runs against a fresh one.
                FcDomainFixture::cleanupAll();

                // A repeated report for an already-failed transaction must not
                // write a duplicate log entry either.
                $failedTransaction = $phase29ReturnTransaction('pi_phase29_race_twice');
                $markFailed->invoke($confirmations, $failedTransaction, [
                    'is_auth_failure' => true,
                    'detail'          => 'phase29 first report',
                ]);
                $markFailed->invoke($confirmations, $failedTransaction, [
                    'is_auth_failure' => true,
                    'detail'          => 'phase29 duplicate report',
                ]);

                $storedFailed = OrderTransaction::query()->find((int) $failedTransaction->id);

                FcTest::assertSame(
                    Status::TRANSACTION_FAILED,
                    (string) $storedFailed->status,
                    'a pending transaction still moves to failed'
                );
                FcTest::assertSame(
                    1,
                    (int) Activity::query()
                        ->where('module_name', 'order')
                        ->where('module_id', (int) $failedTransaction->order_id)
                        ->where('title', 'Stripe 3D Secure Authentication Failed')
                        ->count(),
                    'a duplicate report writes the failure log exactly once'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase29-stripe-3ds-return-logs-unfinished-challenge',
        'name'          => 'Stripe 3DS return logs an unfinished challenge without failing the transaction',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29ReturnTransaction, $phase29ReturnStripeSettings, $phase29ReturnIntentUrl) {
            $transport = new FcProviderHarness();
            $settingsInstalled = false;

            try {
                $transaction = $phase29ReturnTransaction('pi_phase29_pending_3ds');

                add_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX, 1);
                $settingsInstalled = true;
                $transport
                    ->expect('GET', $phase29ReturnIntentUrl('pi_phase29_pending_3ds'), 'stripe/payment-intent-requires-action.json')
                    ->install();

                (new Confirmations())->confirmRedirectReturn(
                    (string) $transaction->uuid,
                    'pi_phase29_pending_3ds'
                );
                $transport->assertComplete();

                $stored = OrderTransaction::query()->find((int) $transaction->id);

                FcTest::assertSame(
                    [Status::TRANSACTION_PENDING, 1],
                    [
                        (string) $stored->status,
                        (int) Activity::query()
                            ->where('module_name', 'order')
                            ->where('module_id', (int) $transaction->order_id)
                            ->where('title', 'Stripe 3D Secure Authentication Not Completed')
                            ->where('status', 'warning')
                            ->count(),
                    ],
                    'an intent the buyer can still finish is logged but left payable'
                );
            } finally {
                $transport->uninstall();
                if ($settingsInstalled) {
                    remove_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase29-stripe-3ds-return-confirms-succeeded-intent',
        'name'          => 'Stripe 3DS return confirms the transaction when the re-fetched intent succeeded',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29ReturnTransaction, $phase29ReturnStripeSettings, $phase29ReturnIntentUrl) {
            $transport = new FcProviderHarness();
            $settingsInstalled = false;

            try {
                $transaction = $phase29ReturnTransaction('pi_phase29_succeeded');

                add_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX, 1);
                $settingsInstalled = true;
                $transport
                    ->expect('GET', $phase29ReturnIntentUrl('pi_phase29_succeeded'), 'stripe/payment-intent-succeeded-charge.json')
                    ->install();

                // Confirming a payment is meant to fire the order-paid side
                // effects; capture them at their boundaries instead of letting
                // the fail-closed guards read them as escapes.
                FcTest::beginExpectedActionSchedulerCapture();
                (new Confirmations())->confirmRedirectReturn(
                    (string) $transaction->uuid,
                    'pi_phase29_succeeded'
                );
                $scheduled = FcTest::consumeExpectedActionSchedulerAttempts();
                $mails = FcTest::sentMails();
                FcTest::interceptMail();
                $transport->assertComplete();

                FcTest::assertSame(
                    ['enqueue_async fluent_cart/order_paid_async_private_handle'],
                    array_map(function ($attempt) {
                        return $attempt['operation'] . ' ' . $attempt['hook'];
                    }, $scheduled),
                    'confirming the payment queues the order-paid handler exactly once'
                );
                FcTest::assert(!empty($mails), 'confirming the payment dispatches order mail');

                $stored = OrderTransaction::query()->find((int) $transaction->id);

                FcTest::assertSame(
                    [Status::TRANSACTION_SUCCEEDED, 'pi_phase29_succeeded'],
                    [(string) $stored->status, (string) $stored->vendor_charge_id],
                    'a succeeded intent confirms the transaction from its expanded charge'
                );
            } finally {
                $transport->uninstall();
                if ($settingsInstalled) {
                    remove_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase29-stripe-3ds-return-marks-failed-setup-intent-failed',
        'name'          => 'Stripe 3DS return marks the transaction failed when a setup intent lost its payment method',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29ReturnTransaction, $phase29ReturnStripeSettings) {
            $transport = new FcProviderHarness();
            $settingsInstalled = false;

            try {
                $transaction = $phase29ReturnTransaction('seti_phase29_failed');

                add_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX, 1);
                $settingsInstalled = true;
                $transport
                    ->expect('GET', 'https://api.stripe.com/v1/setup_intents/seti_phase29_failed', 'stripe/setup-intent-requires-payment-method.json')
                    ->install();

                $confirmed = (new Confirmations())->confirmRedirectReturn(
                    (string) $transaction->uuid,
                    'seti_phase29_failed'
                );
                $transport->assertComplete();

                $stored = OrderTransaction::query()->find((int) $transaction->id);

                // A vaulting failure has the same idempotency consequence as a
                // charge failure: left pending, CheckoutProcessor never bumps
                // payment_attempt and the retry replays Stripe's cached response.
                FcTest::assertSame(
                    [false, Status::TRANSACTION_FAILED, 1],
                    [
                        $confirmed,
                        (string) $stored->status,
                        (int) Activity::query()
                            ->where('module_name', 'order')
                            ->where('module_id', (int) $transaction->order_id)
                            ->where('title', 'Stripe 3D Secure Authentication Failed')
                            ->where('status', 'error')
                            ->count(),
                    ],
                    'a terminally failed setup intent marks the transaction failed and names the cause'
                );
            } finally {
                $transport->uninstall();
                if ($settingsInstalled) {
                    remove_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase29-stripe-3ds-return-reports-processing-intent-as-unconfirmed',
        'name'          => 'Stripe 3DS return does not report success for an intent that is still processing',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29ReturnTransaction, $phase29ReturnStripeSettings, $phase29ReturnIntentUrl) {
            $transport = new FcProviderHarness();
            $settingsInstalled = false;

            try {
                $transaction = $phase29ReturnTransaction('pi_phase29_processing');

                add_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX, 1);
                $settingsInstalled = true;
                $transport
                    ->expect('GET', $phase29ReturnIntentUrl('pi_phase29_processing'), 'stripe/payment-intent-processing.json')
                    ->install();

                $confirmed = (new Confirmations())->confirmRedirectReturn(
                    (string) $transaction->uuid,
                    'pi_phase29_processing'
                );
                $transport->assertComplete();

                $stored = OrderTransaction::query()->find((int) $transaction->id);

                // confirmPaymentSuccessByCharge leaves an unsettled charge pending.
                // Reporting that as confirmed sends the buyer a receipt redirect for
                // a payment nobody has taken.
                FcTest::assertSame(
                    [false, Status::TRANSACTION_PENDING],
                    [$confirmed, (string) $stored->status],
                    'an unsettled charge is not reported as a confirmed payment'
                );
            } finally {
                $transport->uninstall();
                if ($settingsInstalled) {
                    remove_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase29-stripe-3ds-return-routes-through-an-internal-return-url',
        'name'          => 'Stripe onsite hands Stripe an internal return URL that dispatches to the confirmation handler',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29ReturnTransaction) {
            try {
                $transaction = $phase29ReturnTransaction('pi_phase29_failed');

                // The buyer's own destination (fluent_cart/payment/success_url) is
                // filterable and may point anywhere, so the URL Stripe redirects to
                // after an issuer-forced 3DS challenge must not be that one — it has
                // to reach WebRoutes, which dispatches the confirmation handler.
                $returnUrl = Processor::getOnsiteGatewayReturnUrl($transaction);

                (new Confirmations())->init();

                FcTest::assertSame(
                    [true, true, true],
                    [
                        strpos($returnUrl, 'fluent-cart=fct_stripe_onsite_return') !== false,
                        strpos($returnUrl, 'trx_hash=' . $transaction->uuid) !== false,
                        (bool) has_action('fluent_cart_action_fct_stripe_onsite_return'),
                    ],
                    'the onsite return URL is internal, carries the hash, and has a registered handler'
                );
            } finally {
                remove_all_actions('fluent_cart_action_fct_stripe_onsite_return');
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase29-stripe-3ds-return-rejects-foreign-intent',
        'name'          => 'Stripe 3DS return refuses an intent id the transaction never stamped',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29ReturnTransaction, $phase29ReturnStripeSettings) {
            $transport = new FcProviderHarness();
            $settingsInstalled = false;

            try {
                $transaction = $phase29ReturnTransaction('pi_phase29_failed');

                add_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX, 1);
                $settingsInstalled = true;
                // No expectations: the ownership gate must resolve before any
                // Stripe call, so the harness throws on the first request.
                $transport->install();

                (new Confirmations())->confirmRedirectReturn(
                    (string) $transaction->uuid,
                    'pi_someone_elses_intent'
                );

                $stored = OrderTransaction::query()->find((int) $transaction->id);

                FcTest::assertSame(
                    [0, Status::TRANSACTION_PENDING, 'pi_phase29_failed'],
                    [
                        count($transport->requests()),
                        (string) $stored->status,
                        (string) $stored->vendor_charge_id,
                    ],
                    'an intent id we never stamped reaches neither Stripe nor the transaction'
                );
            } finally {
                $transport->uninstall();
                if ($settingsInstalled) {
                    remove_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase29-stripe-3ds-return-rejects-malformed-intent-id',
        'name'          => 'Stripe 3DS return refuses a malformed intent id before it reaches an API path',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29ReturnTransaction, $phase29ReturnStripeSettings) {
            $transport = new FcProviderHarness();
            $settingsInstalled = false;

            try {
                // Stamped on the row too, so the ownership gate passes and only
                // the id-shape guard can stop the traversal.
                $malformed = 'pi_../../charges/ch_someone_else';
                $transaction = $phase29ReturnTransaction($malformed);

                add_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX, 1);
                $settingsInstalled = true;
                $transport->install();

                (new Confirmations())->confirmRedirectReturn(
                    (string) $transaction->uuid,
                    $malformed
                );

                $stored = OrderTransaction::query()->find((int) $transaction->id);

                FcTest::assertSame(
                    [0, Status::TRANSACTION_PENDING],
                    [count($transport->requests()), (string) $stored->status],
                    'a traversal-shaped intent id never reaches the Stripe API path'
                );
            } finally {
                $transport->uninstall();
                if ($settingsInstalled) {
                    remove_filter('fluent_cart/stripe_settings', $phase29ReturnStripeSettings, PHP_INT_MAX);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
