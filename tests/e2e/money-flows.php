<?php
/**
 * Phase 27 opt-in E2E money flows.
 */

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\StockManagement\StockManagement;

return [
    [
        'id'   => 'guest-checkout-tax-stock',
        'name' => 'Guest checkout preserves exact totals and tax while reserving stock',
        'run'  => function () use (&$phase27Evidence) {
            try {
                $product = FcDomainFixture::product([
                    'item_price'       => 1001,
                    'fulfillment_type' => 'digital',
                    'total_stock'      => 10,
                    'available'        => 10,
                    'committed'        => 0,
                    'on_hold'          => 0,
                ]);
                $customer = FcFixture::customer();
                FcFixture::customerAddress((int) $customer->id, 'billing');
                FcFixture::customerAddress((int) $customer->id, 'shipping');

                $cartItem = CartHelper::generateCartItemFromVariation(
                    $product['variation'],
                    2
                );
                $cartItem['tax_amount'] = 200;
                $result = FcPhase27CheckoutHarness::place(
                    'guest-tax-stock',
                    (int) $customer->id,
                    $cartItem,
                    [
                        'disable_coupons' => 'yes',
                        'fees'            => [],
                        'tax_data'        => [
                            'tax_total'           => 200,
                            'shipping_tax'        => 0,
                            'tax_behavior'        => 1,
                            'store_tax_behavior'  => 1,
                            'exclusive_tax_total' => 200,
                            'fee_tax'             => 0,
                            'fee_tax_lines'       => [],
                        ],
                    ]
                );
                $order = FcFixture::reloadOrder((int) $result['order']->id);
                $item = OrderItem::query()
                    ->where('order_id', (int) $order->id)
                    ->first();
                $transaction = OrderTransaction::query()
                    ->where('order_id', (int) $order->id)
                    ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                    ->first();
                $variation = ProductVariation::query()
                    ->find((int) $product['variation']->id);

                FcTest::assertSame(true, $result['terminated'], 'checkout JSON boundary caught');
                FcTest::assertSame('success', (string) ($result['payload']['status'] ?? ''), 'inert checkout response');
                FcTest::assertSame([2202], $result['gateway_amounts'], 'gateway receives exact payable cents once');
                FcTest::assertSame(2002, (int) $order->subtotal, 'Order subtotal exact cents');
                FcTest::assertSame(200, (int) $order->tax_total, 'Order exclusive tax exact cents');
                FcTest::assertSame(2202, (int) $order->total_amount, 'Order payable total exact cents');
                FcTest::assertSame(Status::PAYMENT_PENDING, (string) $order->payment_status, 'inert Order remains pending');
                FcTest::assertSame(0, (int) $order->total_paid, 'inert Order records no payment');
                FcTest::assert($item !== null, 'checkout creates one real OrderItem');
                FcTest::assertSame([2, 2002, 200], [
                    (int) $item->quantity,
                    (int) $item->subtotal,
                    (int) $item->tax_amount,
                ], 'OrderItem preserves quantity, subtotal, and tax');
                FcTest::assert($transaction !== null, 'checkout creates one pending charge transaction');
                FcTest::assertSame(2202, (int) $transaction->total, 'transaction total exact cents');
                FcTest::assertSame([8, 0, 2], [
                    (int) $variation->available,
                    (int) $variation->committed,
                    (int) $variation->on_hold,
                ], 'guest checkout reserves exactly two stock units');

                $phase27Evidence = [
                    'order_id' => (int) $order->id,
                    'amount'   => (int) $order->total_amount,
                    'stock'    => [8, 0, 2],
                ];
            } finally {
                FcDomainFixture::cleanupAll();
                FcAutomationFixture::cleanupAll();
            }
        },
    ],
    [
        'id'   => 'coupon-checkout-discount',
        'name' => 'Coupon checkout persists one exact discount in cart, Order, and ledger',
        'run'  => function () use (&$phase27Evidence) {
            try {
                $product = FcDomainFixture::product([
                    'item_price'   => 1001,
                    'manage_stock' => 0,
                ]);
                $customer = FcFixture::customer();
                FcFixture::customerAddress((int) $customer->id, 'billing');
                FcFixture::customerAddress((int) $customer->id, 'shipping');
                $coupon = FcFixture::coupon([
                    'status'     => 'active',
                    'type'       => 'fixed',
                    'amount'     => 101,
                    'stackable'  => 'yes',
                    'conditions' => [],
                ]);

                $cartItem = CartHelper::generateCartItemFromVariation(
                    $product['variation'],
                    1
                );
                $result = FcPhase27CheckoutHarness::place(
                    'coupon',
                    (int) $customer->id,
                    $cartItem,
                    [
                        'disable_coupons' => 'no',
                        'fees'            => [],
                        'tax_data'        => [
                            'tax_total'           => 0,
                            'shipping_tax'        => 0,
                            'tax_behavior'        => 1,
                            'store_tax_behavior'  => 1,
                            'exclusive_tax_total' => 0,
                            'fee_tax'             => 0,
                            'fee_tax_lines'       => [],
                        ],
                    ],
                    [(string) $coupon->code]
                );
                $order = FcFixture::reloadOrder((int) $result['order']->id);
                $item = OrderItem::query()
                    ->where('order_id', (int) $order->id)
                    ->first();
                $ledger = AppliedCoupon::query()
                    ->where('order_id', (int) $order->id)
                    ->first();
                $freshCoupon = $coupon->fresh();

                FcTest::assertSame(true, $result['terminated'], 'coupon checkout JSON boundary caught');
                FcTest::assertSame([900], $result['gateway_amounts'], 'discounted gateway amount exact cents');
                FcTest::assertSame(1001, (int) $order->subtotal, 'coupon Order subtotal exact cents');
                FcTest::assertSame(101, (int) $order->coupon_discount_total, 'Order coupon discount exact cents');
                FcTest::assertSame(900, (int) $order->total_amount, 'discounted Order payable exact cents');
                FcTest::assertSame(Status::PAYMENT_PENDING, (string) $order->payment_status, 'coupon Order remains pending');
                FcTest::assert($item !== null, 'coupon checkout creates one OrderItem');
                FcTest::assertSame([101, 900], [
                    (int) $item->discount_total,
                    (int) $item->line_total,
                ], 'OrderItem reflects exact coupon cents');
                FcTest::assert($ledger !== null, 'checkout creates one applied-coupon row');
                FcTest::assertSame([
                    (int) $coupon->id,
                    (string) $coupon->code,
                    101,
                ], [
                    (int) $ledger->coupon_id,
                    (string) $ledger->code,
                    (int) $ledger->amount,
                ], 'applied-coupon ledger preserves identity and amount');
                FcTest::assertSame(1, (int) $freshCoupon->use_count, 'coupon use count increments once');

                $phase27Evidence = [
                    'order_id' => (int) $order->id,
                    'subtotal' => 1001,
                    'discount' => 101,
                    'payable'  => 900,
                ];
            } finally {
                FcDomainFixture::cleanupAll();
                FcAutomationFixture::cleanupAll();
            }
        },
    ],
    [
        'id'   => 'admin-refund-money-stock-idempotent',
        'name' => 'Admin full refund restores money and stock and repeat delivery is a no-op',
        'run'  => function () use (&$phase27Evidence) {
            $mailFilter = function () {
                return false;
            };
            $manualRefundFilter = function () {
                return [
                    'status' => 'yes',
                    'source' => 'phase27-inert',
                ];
            };

            try {
                $product = FcDomainFixture::product([
                    'item_price'       => 1001,
                    'fulfillment_type' => 'physical',
                    'total_stock'      => 10,
                    'available'        => 10,
                    'committed'        => 0,
                    'on_hold'          => 0,
                ]);
                FcFixture::customer();
                $order = FcFixture::reportOrder([
                    'subtotal'        => 1001,
                    'total_amount'    => 1001,
                    'total_paid'      => 1001,
                    'total_refund'    => 0,
                    'payment_status'  => Status::PAYMENT_PAID,
                    'payment_method'  => 'phase27_inert',
                    'shipping_status' => Status::SHIPPING_UNSHIPPED,
                ]);
                $item = FcFixture::reportOrderItem((int) $order->id, [
                    'post_id'          => (int) $product['post']->ID,
                    'object_id'        => (int) $product['variation']->id,
                    'quantity'         => 1,
                    'unit_price'       => 1001,
                    'subtotal'         => 1001,
                    'line_total'       => 1001,
                    'fulfillment_type' => 'physical',
                ]);
                $order = FcFixture::reloadOrder((int) $order->id);
                $order->load('order_items');
                (new StockManagement())->manageStockOnOrderCreated([
                    'order'      => $order,
                    'prev_order' => null,
                ]);
                $reserved = ProductVariation::query()
                    ->find((int) $product['variation']->id);
                FcTest::assertSame([9, 0, 1], [
                    (int) $reserved->available,
                    (int) $reserved->committed,
                    (int) $reserved->on_hold,
                ], 'paid Order fixture reserves one unit before refund');

                $parent = OrderTransaction::query()->create([
                    'order_id'            => (int) $order->id,
                    'order_type'          => (string) $order->type,
                    'transaction_type'    => Status::TRANSACTION_TYPE_CHARGE,
                    'payment_method'      => 'phase27_inert',
                    'payment_mode'        => 'test',
                    'payment_method_type' => '',
                    'status'              => Status::TRANSACTION_SUCCEEDED,
                    'currency'            => 'USD',
                    'total'               => 1001,
                    'rate'                => 1,
                    'meta'                => [],
                ]);
                $refundInfo = [
                    'transaction_id' => (int) $parent->id,
                    'amount'         => '10.01',
                    'reason'         => 'Phase 27 full admin refund',
                    'manageStock'    => true,
                    'item_ids'       => [(int) $item->id],
                    'refunded_items' => [[
                        'id'               => (int) $item->id,
                        'variation_id'     => (int) $product['variation']->id,
                        'restore_quantity' => 1,
                    ]],
                ];

                add_filter('fluent_cart/order_refund_manually', $manualRefundFilter, PHP_INT_MAX, 2);
                add_filter('fluent_cart/should_send_email_notification', $mailFilter, 1, 2);

                $first = FcTest::rest(
                    'POST',
                    'orders/' . (int) $order->id . '/refund',
                    ['refund_info' => $refundInfo]
                );
                $afterFirstOrder = FcFixture::reloadOrder((int) $order->id);
                $afterFirstStock = ProductVariation::query()
                    ->find((int) $product['variation']->id);
                $refundCountAfterFirst = OrderTransaction::query()
                    ->where('order_id', (int) $order->id)
                    ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
                    ->count();

                FcTest::assertSame(200, (int) $first['status'], 'first admin refund succeeds');
                FcTest::assertSame(1001, (int) $afterFirstOrder->total_refund, 'admin refund restores all paid cents');
                FcTest::assertSame(Status::PAYMENT_REFUNDED, (string) $afterFirstOrder->payment_status, 'Order becomes fully refunded');
                FcTest::assertSame(1, (int) $refundCountAfterFirst, 'first admin refund creates one refund row');
                FcTest::assertSame([10, 0, 0], [
                    (int) $afterFirstStock->available,
                    (int) $afterFirstStock->committed,
                    (int) $afterFirstStock->on_hold,
                ], 'first admin refund restores the reserved stock unit');

                $firstSnapshot = [
                    'total_refund' => (int) $afterFirstOrder->total_refund,
                    'status'       => (string) $afterFirstOrder->payment_status,
                    'refund_rows'  => (int) $refundCountAfterFirst,
                    'stock'        => [
                        (int) $afterFirstStock->available,
                        (int) $afterFirstStock->committed,
                        (int) $afterFirstStock->on_hold,
                    ],
                ];
                $second = FcTest::rest(
                    'POST',
                    'orders/' . (int) $order->id . '/refund',
                    ['refund_info' => $refundInfo]
                );
                $afterSecondOrder = FcFixture::reloadOrder((int) $order->id);
                $afterSecondStock = ProductVariation::query()
                    ->find((int) $product['variation']->id);
                $secondSnapshot = [
                    'total_refund' => (int) $afterSecondOrder->total_refund,
                    'status'       => (string) $afterSecondOrder->payment_status,
                    'refund_rows'  => (int) OrderTransaction::query()
                        ->where('order_id', (int) $order->id)
                        ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
                        ->count(),
                    'stock'        => [
                        (int) $afterSecondStock->available,
                        (int) $afterSecondStock->committed,
                        (int) $afterSecondStock->on_hold,
                    ],
                ];

                FcTest::assert((int) $second['status'] >= 400, 'repeat full refund is rejected');
                FcTest::assertSame($firstSnapshot, $secondSnapshot, 'repeat refund is a complete money and stock no-op');

                $phase27Evidence = [
                    'order_id'    => (int) $order->id,
                    'refunded'    => 1001,
                    'refund_rows' => 1,
                    'stock'       => [10, 0, 0],
                    'repeat_http' => (int) $second['status'],
                ];
            } finally {
                remove_filter('fluent_cart/order_refund_manually', $manualRefundFilter, PHP_INT_MAX);
                remove_filter('fluent_cart/should_send_email_notification', $mailFilter, 1);
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
