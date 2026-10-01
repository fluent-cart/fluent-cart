<?php
/**
 * Stripe checkout response carries the transaction hash.
 *
 * A failed onsite confirmation is reported back over a nopriv AJAX action that
 * only moves a transaction to `failed` when the reporter also supplies the hash
 * the buyer was handed. That hash reached the browser on the setup-intent paths
 * only, so an ordinary one-time card decline reported nothing: the transaction
 * stayed `pending`, CheckoutProcessor never bumped `payment_attempt` (it bumps
 * only for a `failed` transaction), and the retry reused the same idempotency
 * seed — Stripe replayed its 24h-cached response for an intent that could no
 * longer be paid.
 *
 * The hash is the transaction uuid, which the same payload already publishes
 * inside `gateway_return_url`, so nothing new is exposed to the browser.
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Stripe;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;

$phase30ResponseStripeSettings = function ($settings) {
    $settings['checkout_mode'] = 'onsite';
    $settings['test_publishable_key'] = 'pk_test_phase30_response';
    $settings['test_secret_key'] = 'sk_test_phase30_response';

    return $settings;
};

$phase30ResponsePaymentInstance = function () {
    $product = FcDomainFixture::product(['item_price' => 4200]);
    $order = FcDomainFixture::orderWithItem(
        (int) $product['post']->ID,
        (int) $product['variation']->id,
        1,
        [
            'currency'     => 'USD',
            'subtotal'     => 4200,
            'tax_total'    => 0,
            'total_amount' => 4200,
        ]
    );
    FcFixture::reportOrderAddress((int) $order->id, [
        'name'      => 'Phase Thirty Checkout',
        'address_1' => '30 Response Lane',
        'city'      => 'Dhaka',
        'state'     => 'C',
        'postcode'  => '1205',
        'country'   => 'BD',
    ]);
    $transaction = OrderTransaction::query()->create([
        'order_id'            => (int) $order->id,
        'order_type'          => (string) $order->type,
        'transaction_type'    => Status::TRANSACTION_TYPE_CHARGE,
        'payment_method'      => '',
        'payment_mode'        => 'test',
        'payment_method_type' => '',
        'status'              => Status::TRANSACTION_PENDING,
        'currency'            => 'USD',
        'total'               => 4200,
        'rate'                => 1,
        'meta'                => ['fixture_case' => 'phase30-stripe-checkout-response'],
    ]);
    if (!$transaction || (int) $transaction->id <= 0) {
        throw new RuntimeException('Phase 30 pending transaction was not created.');
    }

    return new PaymentInstance(FcFixture::reloadOrder((int) $order->id));
};

return [
    [
        'id'            => 'phase30-stripe-checkout-response-carries-trx-hash',
        'name'          => 'Stripe one-time checkout response hands the browser the transaction hash',
        'kind'          => 'behavior',
        'phase'         => 30,
        'known_failure' => false,
        'run'           => function () use ($phase30ResponseStripeSettings, $phase30ResponsePaymentInstance) {
            $transport = new FcProviderHarness();
            $settingsInstalled = false;

            try {
                $payment = $phase30ResponsePaymentInstance();

                add_filter('fluent_cart/stripe_settings', $phase30ResponseStripeSettings, PHP_INT_MAX, 1);
                $settingsInstalled = true;
                $transport
                    ->expect('POST', 'https://api.stripe.com/v1/customers', 'stripe/customer-checkout.json')
                    ->expect('POST', 'https://api.stripe.com/v1/payment_intents', 'stripe/payment-intent-success.json')
                    ->install();

                $response = (new Stripe())->makePaymentFromPaymentInstance($payment);
                $transport->assertComplete();

                $paymentArgs = is_array($response) ? (array) Arr::get($response, 'payment_args', []) : [];

                FcTest::assertSame(
                    [(string) $payment->transaction->uuid, true],
                    [
                        (string) Arr::get($paymentArgs, 'trx_hash'),
                        strpos(
                            (string) Arr::get($paymentArgs, 'gateway_return_url'),
                            'trx_hash=' . $payment->transaction->uuid
                        ) !== false,
                    ],
                    'the one-time onsite response publishes the transaction hash the failure report requires'
                );
            } finally {
                $transport->uninstall();
                if ($settingsInstalled) {
                    remove_filter('fluent_cart/stripe_settings', $phase30ResponseStripeSettings, PHP_INT_MAX);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
