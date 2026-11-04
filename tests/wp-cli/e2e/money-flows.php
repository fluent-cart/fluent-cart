<?php
/**
 * Phase 27 opt-in E2E money flows.
 */

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\OrderItem;

return [
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
];
