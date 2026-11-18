<?php
/**
 * Paddle checkout recovery sends an order with money still due to its payment link.
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\PaymentHelper;

return [
    [
        'id'            => 'paddle-checkout-recovery-due-after-refund',
        'name'          => 'Paddle checkout recovery of a partially paid, partly refunded order redirects to the payment link',
        'kind'          => 'behavior',
        'known_failure' => false,
        'requires'      => 'pro',
        'run'           => function () {
            $paddleClass = 'FluentCartPro\\App\\Modules\\PaymentMethods\\PaddleGateway\\Paddle';
            if (!class_exists($paddleClass)) {
                FcTest::fail('FluentCart Pro is expected active, but its Paddle gateway is not loaded.');
                return;
            }

            $savedGet = $_GET;
            $stop = function ($location) {
                throw new \RuntimeException((string) $location);
            };

            try {
                FcFixture::customer();
                $order = FcFixture::order([
                    'subtotal'     => 5000,
                    'tax_total'    => 0,
                    'total_amount' => 5000,
                ]);
                $orderId = (int) $order->id;
                // total_paid is gross: 3000 charged, 2000 of it refunded, 2000 still due
                Order::query()->where('id', $orderId)->update([
                    'total_paid'   => 3000,
                    'total_refund' => 2000,
                ]);
                $paddleTxn = 'txn_' . FcTest::uniq();
                $charge = OrderTransaction::query()->create([
                    'order_id'            => $orderId,
                    'order_type'          => (string) $order->type,
                    'payment_method'      => 'paddle',
                    'payment_mode'        => 'test',
                    'payment_method_type' => '',
                    'vendor_charge_id'    => $paddleTxn,
                    'transaction_type'    => Status::TRANSACTION_TYPE_CHARGE,
                    'status'              => Status::TRANSACTION_SUCCEEDED,
                    'total'               => 3000,
                    'currency'            => 'USD',
                    'rate'                => 1,
                    'meta'                => [],
                ]);
                FcTest::assert((int) $charge->id > 0, 'owned transaction ID is captured');

                $_GET['_paction'] = 'recovery';
                $_GET['_ptxn'] = $paddleTxn;
                add_filter('wp_redirect', $stop, 1);

                $location = '';
                try {
                    (new $paddleClass())->handleCheckoutRecovery();
                } catch (\RuntimeException $e) {
                    $location = $e->getMessage();
                }

                FcTest::assertSame(
                    PaymentHelper::getCustomPaymentLink((string) $order->uuid),
                    $location,
                    'recovery redirects to the custom payment link while 2000 is still due'
                );
            } finally {
                remove_filter('wp_redirect', $stop, 1);
                $_GET = $savedGet;
                FcFixture::cleanupAll();
            }
        },
    ],
];
