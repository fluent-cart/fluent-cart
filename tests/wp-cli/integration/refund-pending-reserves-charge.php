<?php
/**
 * A pending gateway refund reserves its charge against local refunds.
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\Refund;

$refundPendingCharge = function () {
    FcFixture::customer();
    $order = FcFixture::order([
        'subtotal'     => 2000,
        'tax_total'    => 0,
        'total_amount' => 2000,
    ]);
    Order::query()->where('id', (int) $order->id)->update([
        'total_paid'     => 2000,
        'payment_status' => Status::PAYMENT_PAID,
    ]);
    $charge = OrderTransaction::query()->create([
        'order_id'            => (int) $order->id,
        'order_type'          => (string) $order->type,
        'transaction_type'    => Status::TRANSACTION_TYPE_CHARGE,
        'payment_method'      => '',
        'payment_mode'        => 'test',
        'payment_method_type' => '',
        'status'              => Status::TRANSACTION_SUCCEEDED,
        'currency'            => 'USD',
        'total'               => 2000,
        'rate'                => 1,
        'meta'                => [],
    ]);
    FcTest::assert((int) $charge->id > 0, 'exact owned charge ID is captured');

    return [$order, $charge];
};

$refundPendingVendorId = function ($suffix) {
    return 're_pending_' . $suffix . '_' . substr(hash('sha256', FcFixture::identity()), 0, 12);
};

$refundPendingRows = function ($orderId) {
    return OrderTransaction::query()
        ->where('order_id', (int) $orderId)
        ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
        ->orderBy('id', 'ASC')
        ->get();
};

return [
    [
        'id'            => 'refund-pending-reserves-charge',
        'name'          => 'A pending gateway refund reserves its charge, so a forced local refund cannot exceed what is left',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($refundPendingCharge, $refundPendingVendorId, $refundPendingRows) {
            $mailFilter = function () {
                return false;
            };
            add_filter('fluent_cart/should_send_email_notification', $mailFilter, 1, 2);

            try {
                list($order, $charge) = $refundPendingCharge();

                $pending = Refund::createOrRecordRefund([
                    'vendor_charge_id' => $refundPendingVendorId('reserve'),
                    'payment_method'   => 'mollie',
                    'status'           => Status::TRANSACTION_PENDING,
                    'total'            => 1000,
                ], $charge);
                FcTest::assert($pending instanceof OrderTransaction, 'pending gateway refund is recorded');

                $charge = OrderTransaction::query()->find((int) $charge->id);
                FcTest::assertSame(1000, (int) $charge->refundable, 'refundable figure the modal shows leaves out the pending refund');
                FcTest::assertSame(1000, Refund::netPaidAmount(Order::query()->find((int) $order->id)), 'order net paid leaves out the pending refund');

                $tooMuch = (new Refund())->processRefund($charge, 1500, ['force_local' => true]);
                FcTest::assert(is_wp_error($tooMuch), 'forced local refund above what the charge has left is rejected');
                FcTest::assertSame(1, count($refundPendingRows((int) $order->id)), 'rejected refund writes no row');

                $within = (new Refund())->processRefund(OrderTransaction::query()->find((int) $charge->id), 1000, ['force_local' => true]);
                FcTest::assert(!is_wp_error($within), 'forced local refund within what the charge has left is recorded');

                $storedCharge = OrderTransaction::query()->find((int) $charge->id);
                $storedOrder = FcFixture::reloadOrder((int) $order->id);
                FcTest::assertSame(
                    [1000, 1000, 0],
                    [
                        (int) ($storedCharge->meta['refunded_total'] ?? 0),
                        (int) $storedOrder->total_refund,
                        (int) $storedCharge->refundable,
                    ],
                    'only the local refund is counted, and the pending refund still reserves the rest'
                );
            } finally {
                remove_filter('fluent_cart/should_send_email_notification', $mailFilter, 1);
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'refund-pending-overflow-flagged',
        'name'          => 'A gateway refund that settles above its charge is counted in full and flagged with the excess once',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($refundPendingCharge, $refundPendingVendorId) {
            $mailFilter = function () {
                return false;
            };
            add_filter('fluent_cart/should_send_email_notification', $mailFilter, 1, 2);

            try {
                list($order, $charge) = $refundPendingCharge();

                $local = (new Refund())->processRefund($charge, 2000, ['force_local' => true]);
                FcTest::assert(!is_wp_error($local), 'forced local refund of the whole charge is recorded');

                $gatewayRefund = [
                    'vendor_charge_id' => $refundPendingVendorId('overflow'),
                    'payment_method'   => 'mollie',
                    'status'           => Status::TRANSACTION_PENDING,
                    'total'            => 1000,
                ];
                $pending = Refund::createOrRecordRefund($gatewayRefund, OrderTransaction::query()->find((int) $charge->id));
                FcTest::assert($pending instanceof OrderTransaction, 'pending gateway refund is recorded');

                $gatewayRefund['status'] = Status::TRANSACTION_REFUNDED;
                Refund::createOrRecordRefund($gatewayRefund, OrderTransaction::query()->find((int) $charge->id));
                $settled = OrderTransaction::query()->find((int) $pending->id);
                $storedCharge = OrderTransaction::query()->find((int) $charge->id);
                $storedOrder = FcFixture::reloadOrder((int) $order->id);
                FcTest::assertSame(
                    [Status::TRANSACTION_REFUNDED, 1000, 3000, 3000],
                    [
                        (string) $settled->status,
                        (int) ($settled->meta['exceeds_charge'] ?? 0),
                        (int) ($storedCharge->meta['refunded_total'] ?? 0),
                        (int) $storedOrder->total_refund,
                    ],
                    'settled refund is counted in full and carries the excess over the charge'
                );

                Refund::createOrRecordRefund($gatewayRefund, OrderTransaction::query()->find((int) $charge->id));
                $redelivered = OrderTransaction::query()->find((int) $pending->id);
                $storedCharge = OrderTransaction::query()->find((int) $charge->id);
                $storedOrder = FcFixture::reloadOrder((int) $order->id);
                FcTest::assertSame(
                    [1000, 3000, 3000],
                    [
                        (int) ($redelivered->meta['exceeds_charge'] ?? 0),
                        (int) ($storedCharge->meta['refunded_total'] ?? 0),
                        (int) $storedOrder->total_refund,
                    ],
                    'a redelivered webhook changes neither the flag nor the totals'
                );

                $second = Refund::createOrRecordRefund([
                    'vendor_charge_id' => $refundPendingVendorId('overflow_second'),
                    'payment_method'   => 'mollie',
                    'status'           => Status::TRANSACTION_REFUNDED,
                    'total'            => 500,
                ], OrderTransaction::query()->find((int) $charge->id));
                Refund::createOrRecordRefund($gatewayRefund, OrderTransaction::query()->find((int) $charge->id));
                $storedCharge = OrderTransaction::query()->find((int) $charge->id);
                FcTest::assertSame(
                    [1000, 500, 3500],
                    [
                        (int) (OrderTransaction::query()->find((int) $pending->id)->meta['exceeds_charge'] ?? 0),
                        (int) (OrderTransaction::query()->find((int) $second->id)->meta['exceeds_charge'] ?? 0),
                        (int) ($storedCharge->meta['refunded_total'] ?? 0),
                    ],
                    'two overflowing refunds carry flags that add up to refunded total minus the charge'
                );
            } finally {
                remove_filter('fluent_cart/should_send_email_notification', $mailFilter, 1);
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'refund-pending-legacy-stripe-link',
        'name'          => 'A refund an older Stripe webhook linked by transaction_id does not cancel a new pending refund\'s reservation',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($refundPendingCharge, $refundPendingVendorId) {
            $mailFilter = function () {
                return false;
            };
            add_filter('fluent_cart/should_send_email_notification', $mailFilter, 1, 2);

            try {
                list($order, $charge) = $refundPendingCharge();

                OrderTransaction::query()->create([
                    'order_id'            => (int) $order->id,
                    'order_type'          => (string) $order->type,
                    'transaction_type'    => Status::TRANSACTION_TYPE_REFUND,
                    'vendor_charge_id'    => $refundPendingVendorId('legacy_stripe'),
                    'payment_method'      => 'stripe',
                    'payment_mode'        => 'test',
                    'payment_method_type' => '',
                    'status'              => Status::TRANSACTION_REFUNDED,
                    'currency'            => 'USD',
                    'total'               => 1200,
                    'rate'                => 1,
                    'meta'                => ['transaction_id' => (int) $charge->id],
                ]);
                $charge->meta = ['refunded_total' => 1200];
                $charge->save();

                $pending = Refund::createOrRecordRefund([
                    'vendor_charge_id' => $refundPendingVendorId('legacy_pending'),
                    'payment_method'   => 'mollie',
                    'status'           => Status::TRANSACTION_PENDING,
                    'total'            => 800,
                ], OrderTransaction::query()->find((int) $charge->id));
                FcTest::assert($pending instanceof OrderTransaction, 'pending gateway refund is recorded');

                FcTest::assertSame(0, (int) OrderTransaction::query()->find((int) $charge->id)->refundable, 'the older Stripe refund and the pending refund together leave nothing to refund');
            } finally {
                remove_filter('fluent_cart/should_send_email_notification', $mailFilter, 1);
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
