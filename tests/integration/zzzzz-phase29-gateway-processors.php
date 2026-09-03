<?php
/**
 * Phase 29 fixture-backed gateway processor and subscription-manager contracts.
 */

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\Processor as PayPalProcessor;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Processor as StripeProcessor;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\SubscriptionsManager;
use FluentCart\App\Services\Payments\PaymentInstance;

$phase29WithStoreMode = function ($mode, callable $callback) {
    $property = new ReflectionProperty(StoreSettings::class, 'cachedStoreSettings');
    $property->setAccessible(true);
    $previous = $property->getValue();
    $settings = is_array($previous) ? $previous : [];
    $settings['order_mode'] = (string) $mode;
    $property->setValue(null, $settings);

    try {
        return $callback();
    } finally {
        $property->setValue(null, $previous);
    }
};

$phase29StripeSettings = function ($settings) {
    $settings['checkout_mode'] = 'onsite';
    $settings['test_publishable_key'] = 'pk_test_phase29_fixture';
    $settings['test_secret_key'] = 'sk_test_phase29_fixture';

    return $settings;
};

$phase29MakePaymentInstance = function ($product, array $orderAttributes, array $transactionAttributes) {
    $order = FcDomainFixture::orderWithItem(
        (int) $product['post']->ID,
        (int) $product['variation']->id,
        1,
        $orderAttributes
    );
    FcFixture::reportOrderAddress((int) $order->id, [
        'name'      => 'Phase Twenty Nine',
        'address_1' => '29 Provider Lane',
        'address_2' => 'Suite 2',
        'city'      => 'Dhaka',
        'state'     => 'C',
        'postcode'  => '1205',
        'country'   => 'BD',
    ]);
    $transaction = OrderTransaction::query()->create(array_merge([
        'order_id'            => (int) $order->id,
        'order_type'          => (string) $order->type,
        'transaction_type'    => Status::TRANSACTION_TYPE_CHARGE,
        'payment_method'      => '',
        'payment_mode'        => 'test',
        'payment_method_type' => '',
        'status'              => Status::TRANSACTION_PENDING,
        'currency'            => (string) $order->currency,
        'total'               => (int) $order->total_amount,
        'rate'                => 1,
        'meta'                => ['fixture_case' => 'phase29-gateway-processor'],
    ], $transactionAttributes));
    if (!$transaction || (int) $transaction->id <= 0) {
        throw new RuntimeException('Phase 29 pending transaction was not created.');
    }

    return new PaymentInstance(FcFixture::reloadOrder((int) $order->id));
};

return [
    [
        'id'            => 'phase29-stripe-processor-amount-currency-zero-decimal',
        'name'          => 'Stripe processor sends exact standard and zero-decimal intent payloads',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29MakePaymentInstance, $phase29StripeSettings, $phase29WithStoreMode) {
            $transport = new FcProviderHarness();
            $settingsInstalled = false;

            try {
                $product = FcDomainFixture::product(['item_price' => 12345]);
                $usd = $phase29MakePaymentInstance($product, [
                    'currency'     => 'USD',
                    'subtotal'     => 12345,
                    'tax_total'    => 0,
                    'total_amount' => 12345,
                ], ['currency' => 'USD', 'total' => 12345]);
                $jpy = $phase29MakePaymentInstance($product, [
                    'currency'     => 'JPY',
                    'subtotal'     => 12345,
                    'tax_total'    => 0,
                    'total_amount' => 12345,
                ], ['currency' => 'JPY', 'total' => 12345]);

                add_filter('fluent_cart/stripe_settings', $phase29StripeSettings, PHP_INT_MAX, 1);
                $settingsInstalled = true;
                $transport
                    ->expect('POST', 'https://api.stripe.com/v1/payment_intents', 'stripe/payment-intent-success.json')
                    ->expect('POST', 'https://api.stripe.com/v1/payment_intents', 'stripe/payment-intent-success.json')
                    ->install();

                $responses = $phase29WithStoreMode('test', function () use ($usd, $jpy) {
                    $processor = new StripeProcessor();

                    return [
                        $processor->handleSinglePayment($usd, ['customer' => 'cus_phase29_usd']),
                        $processor->handleSinglePayment($jpy, ['customer' => 'cus_phase29_jpy']),
                    ];
                });
                $transport->assertComplete();
                $requests = $transport->requests();
                $usdBody = $requests[0]['body'];
                $jpyBody = $requests[1]['body'];

                FcTest::assertSame(
                    [12345, 'USD', 'cus_phase29_usd', 'true'],
                    [
                        (int) $usdBody['amount'],
                        (string) $usdBody['currency'],
                        (string) $usdBody['customer'],
                        (string) $usdBody['automatic_payment_methods']['enabled'],
                    ],
                    'Stripe standard-currency payload is exact'
                );
                FcTest::assertSame(
                    [123, 'JPY', 'cus_phase29_jpy', 'true'],
                    [
                        (int) $jpyBody['amount'],
                        (string) $jpyBody['currency'],
                        (string) $jpyBody['customer'],
                        (string) $jpyBody['automatic_payment_methods']['enabled'],
                    ],
                    'Stripe zero-decimal payload converts stored minor units exactly once'
                );
                FcTest::assertSame(
                    [
                        (string) $usd->order->uuid,
                        'fct_order_id_' . (int) $usd->order->id,
                        (string) $jpy->order->uuid,
                        'fct_order_id_' . (int) $jpy->order->id,
                    ],
                    [
                        (string) $usdBody['metadata']['fct_ref_id'],
                        (string) $usdBody['metadata']['order_reference'],
                        (string) $jpyBody['metadata']['fct_ref_id'],
                        (string) $jpyBody['metadata']['order_reference'],
                    ],
                    'Stripe payload retains both exact owned Order identities'
                );
                FcTest::assertSame(
                    ['success', 'success', 'pi_phase22_success', 'pi_phase22_success'],
                    [
                        (string) $responses[0]['status'],
                        (string) $responses[1]['status'],
                        (string) OrderTransaction::query()->find((int) $usd->transaction->id)->vendor_charge_id,
                        (string) OrderTransaction::query()->find((int) $jpy->transaction->id)->vendor_charge_id,
                    ],
                    'canned Stripe responses map only their provider identities'
                );
                foreach ([$usd->order, $jpy->order] as $ownedOrder) {
                    $stored = FcFixture::reloadOrder((int) $ownedOrder->id);
                    FcTest::assertSame(
                        [Status::PAYMENT_PENDING, 0, ''],
                        [(string) $stored->payment_status, (int) $stored->total_paid, (string) $stored->payment_method],
                        'Stripe processor leaves each owned Order inert and unpaid'
                    );
                }
            } finally {
                $transport->uninstall();
                if ($settingsInstalled) {
                    remove_filter('fluent_cart/stripe_settings', $phase29StripeSettings, PHP_INT_MAX);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase29-stripe-processor-rate-limit-mapping',
        'name'          => 'Stripe processor returns the exact provider rate-limit error without mutation',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29MakePaymentInstance, $phase29StripeSettings, $phase29WithStoreMode) {
            $transport = new FcProviderHarness();
            $settingsInstalled = false;

            try {
                $product = FcDomainFixture::product(['item_price' => 1001]);
                $payment = $phase29MakePaymentInstance($product, [
                    'currency'     => 'USD',
                    'subtotal'     => 1001,
                    'tax_total'    => 0,
                    'total_amount' => 1001,
                ], ['currency' => 'USD', 'total' => 1001]);

                add_filter('fluent_cart/stripe_settings', $phase29StripeSettings, PHP_INT_MAX, 1);
                $settingsInstalled = true;
                $transport
                    ->expect('POST', 'https://api.stripe.com/v1/payment_intents', 'stripe/rate-limit.json')
                    ->install();

                $result = $phase29WithStoreMode('test', function () use ($payment) {
                    return (new StripeProcessor())->handleSinglePayment(
                        $payment,
                        ['customer' => 'cus_phase29_rate_limit']
                    );
                });
                $transport->assertComplete();
                $storedTransaction = OrderTransaction::query()->find((int) $payment->transaction->id);
                $storedOrder = FcFixture::reloadOrder((int) $payment->order->id);

                FcTest::assertSame(true, is_wp_error($result), 'Stripe 429 becomes WP_Error');
                FcTest::assertSame(
                    ['api_error', 'Too many requests made to the API too quickly.'],
                    [$result->get_error_code(), $result->get_error_message()],
                    'Stripe processor preserves exact rate-limit mapping'
                );
                FcTest::assertSame('', (string) $storedTransaction->vendor_charge_id, 'failed intent stores no vendor charge');
                FcTest::assertSame(
                    [Status::TRANSACTION_PENDING, Status::PAYMENT_PENDING, 0, ''],
                    [
                        (string) $storedTransaction->status,
                        (string) $storedOrder->payment_status,
                        (int) $storedOrder->total_paid,
                        (string) $storedOrder->payment_method,
                    ],
                    'Stripe rate limit leaves the transaction and Order inert'
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
    [
        'id'            => 'phase29-paypal-processor-exact-payload',
        'name'          => 'PayPal processor sends an exact amount, currency, item, and identity payload',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29MakePaymentInstance, $phase29WithStoreMode) {
            $transport = new FcProviderHarness();

            try {
                $product = FcDomainFixture::product(['item_price' => 1001]);
                $payment = $phase29MakePaymentInstance($product, [
                    'currency'     => 'USD',
                    'subtotal'     => 1001,
                    'tax_total'    => 0,
                    'total_amount' => 1001,
                ], ['currency' => 'USD', 'total' => 1001]);
                fluent_cart_get_option('_paypal_access_token_phase29', [
                    'access_token' => 'phase29-provider-token',
                    'expires_at'   => time() + 3600,
                ], false);
                $transport
                    ->expect('POST', 'https://api.paypal.com/v2/checkout/orders', 'paypal/order-created-success.json')
                    ->install();

                $result = $phase29WithStoreMode('phase29', function () use ($payment) {
                    return (new PayPalProcessor())->handleSinglePayment($payment);
                });
                $transport->assertComplete();
                $body = $transport->requests()[0]['body'];
                $purchase = $body['purchase_units'][0];
                $item = $purchase['items'][0];
                $storedTransaction = OrderTransaction::query()->find((int) $payment->transaction->id);
                $storedOrder = FcFixture::reloadOrder((int) $payment->order->id);

                FcTest::assertSame(
                    ['CAPTURE', 'NO_SHIPPING', (string) $payment->transaction->uuid],
                    [
                        (string) $body['intent'],
                        (string) $body['application_context']['shipping_preference'],
                        (string) $purchase['reference_id'],
                    ],
                    'PayPal order shell carries the exact capture and owned transaction identity contract'
                );
                FcTest::assertSame(
                    ['USD', '10.01', 'USD', '10.01', 'USD', '10.01', 1],
                    [
                        (string) $purchase['amount']['currency_code'],
                        (string) $purchase['amount']['value'],
                        (string) $purchase['amount']['breakdown']['item_total']['currency_code'],
                        (string) $purchase['amount']['breakdown']['item_total']['value'],
                        (string) $item['unit_amount']['currency_code'],
                        (string) $item['unit_amount']['value'],
                        (int) $item['quantity'],
                    ],
                    'PayPal preserves exact currency and fixed two-decimal amount strings'
                );
                FcTest::assertSame(
                    (string) $payment->order->order_items->first()->post_title . ' '
                        . (string) $payment->order->order_items->first()->title,
                    (string) $item['name'],
                    'PayPal item name maps the exact owned product and variation labels'
                );
                FcTest::assertSame(
                    ['success', 'PAYPAL-PHASE29-ORDER', 'PAYPAL-PHASE29-ORDER'],
                    [
                        (string) $result['status'],
                        (string) $result['response']['paypalOrderId'],
                        (string) $storedTransaction->meta['paypal_order_id'],
                    ],
                    'PayPal response maps the exact provider Order identity'
                );
                FcTest::assertSame(
                    [Status::TRANSACTION_PENDING, Status::PAYMENT_PENDING, 0, ''],
                    [
                        (string) $storedTransaction->status,
                        (string) $storedOrder->payment_status,
                        (int) $storedOrder->total_paid,
                        (string) $storedOrder->payment_method,
                    ],
                    'PayPal processor leaves the transaction and Order inert'
                );
            } finally {
                $transport->uninstall();
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase29-paypal-processor-rate-limit-mapping',
        'name'          => 'PayPal processor returns the exact provider rate-limit error without mutation',
        'kind'          => 'behavior',
        'phase'         => 29,
        'known_failure' => false,
        'run'           => function () use ($phase29MakePaymentInstance, $phase29WithStoreMode) {
            $transport = new FcProviderHarness();

            try {
                $product = FcDomainFixture::product(['item_price' => 1001]);
                $payment = $phase29MakePaymentInstance($product, [
                    'currency'     => 'USD',
                    'subtotal'     => 1001,
                    'tax_total'    => 0,
                    'total_amount' => 1001,
                ], ['currency' => 'USD', 'total' => 1001]);
                fluent_cart_get_option('_paypal_access_token_phase29', [
                    'access_token' => 'phase29-provider-token',
                    'expires_at'   => time() + 3600,
                ], false);
                $transport
                    ->expect('POST', 'https://api.paypal.com/v2/checkout/orders', 'paypal/rate-limit.json')
                    ->install();

                $result = $phase29WithStoreMode('phase29', function () use ($payment) {
                    return (new PayPalProcessor())->handleSinglePayment($payment);
                });
                $transport->assertComplete();
                $storedTransaction = OrderTransaction::query()->find((int) $payment->transaction->id);
                $storedOrder = FcFixture::reloadOrder((int) $payment->order->id);

                FcTest::assertSame(true, is_wp_error($result), 'PayPal 429 becomes WP_Error');
                FcTest::assertSame(
                    ['general_error', 'Too many requests.'],
                    [$result->get_error_code(), $result->get_error_message()],
                    'PayPal processor preserves exact rate-limit mapping'
                );
                FcTest::assertSame(
                    ['fixture_case' => 'phase29-gateway-processor'],
                    $storedTransaction->meta,
                    'failed PayPal create preserves metadata without a remote Order ID'
                );
                FcTest::assertSame(
                    [Status::TRANSACTION_PENDING, Status::PAYMENT_PENDING, 0, ''],
                    [
                        (string) $storedTransaction->status,
                        (string) $storedOrder->payment_status,
                        (int) $storedOrder->total_paid,
                        (string) $storedOrder->payment_method,
                    ],
                    'PayPal rate limit leaves the transaction and Order inert'
                );
            } finally {
                $transport->uninstall();
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase29-stripe-subscription-manager-provider-mapping',
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
