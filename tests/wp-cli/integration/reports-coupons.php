<?php
/**
 * Coupons report: per-code orders and money, an order with two coupons split by discount,
 * new vs returning, renewals of coupon orders, and header discounts with no code.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\CouponReportService;

// No store row uses the ISO test currency, so the report scoped to it reads only these fixtures.
$fcCouponCurrency = 'XTS';

return [
    [
        'id'            => 'reports-coupons',
        'name'          => 'Coupons report splits a two-coupon order by discount and adds renewals of coupon orders',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcCouponCurrency) {
            $db = App::db();
            $orderIds = [];
            $couponId = 0;
            try {
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcCouponCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' orders already use currency ' . $fcCouponCurrency);
                }
                FcFixture::customer();
                $window = FcFixture::reportWindow();
                $start = strtotime($window['start']);
                $order = function (array $attributes, $hours) use ($fcCouponCurrency, $start, &$orderIds) {
                    $at = gmdate('Y-m-d H:i:s', $start + $hours * HOUR_IN_SECONDS);
                    $row = FcFixture::reportOrder(array_merge([
                        'status'         => Status::ORDER_COMPLETED,
                        'payment_status' => Status::PAYMENT_PAID,
                        'currency'       => $fcCouponCurrency,
                        'type'           => Status::ORDER_TYPE_PAYMENT,
                        'tax_total'      => 0,
                        'total_refund'   => 0,
                        'created_at'     => $at,
                        'updated_at'     => $at,
                    ], $attributes));
                    $orderIds[] = (int) $row->id;

                    return $row;
                };
                $coupon = function ($orderId, $code, $amount, $id = null) use ($db) {
                    $db->table('fct_applied_coupons')->insert([
                        'order_id' => $orderId, 'coupon_id' => $id, 'code' => $code, 'amount' => $amount,
                        'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                };
                $codeA = 'FCTESTA' . substr(md5(FcFixture::identity()), 0, 6);
                $codeB = 'FCTESTB' . substr(md5(FcFixture::identity()), 0, 6);

                // The customer's first paid order: 9.00 paid after a 1.00 coupon
                $first = $order(['subtotal' => 1000, 'coupon_discount_total' => 100, 'total_amount' => 900, 'total_paid' => 900], 1);
                $coupon($first->id, $codeA, 100);
                // Two coupons, 3.00 + 1.00: the 16.00 paid and 4.00 refunded split 3 : 1
                $second = $order(['subtotal' => 2000, 'coupon_discount_total' => 400, 'total_amount' => 1600, 'total_paid' => 1600,
                    'total_refund' => 400, 'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED], 2);
                $coupon($second->id, $codeA, 300);
                $coupon($second->id, $codeB, 100);
                // A renewal of the first order, later than the range
                $order(['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $first->id, 'subtotal' => 500,
                    'total_amount' => 500, 'total_paid' => 500, 'created_at' => '2030-01-01 00:00:00'], 0);
                // A discount with no code record
                $order(['subtotal' => 1000, 'coupon_discount_total' => 200, 'total_amount' => 800, 'total_paid' => 800], 3);
                // A coupon renamed after it was used: one row under its current code, the old code kept
                $renamed = 'FCTESTNEW' . substr(md5(FcFixture::identity()), 0, 6);
                $couponId = (int) $db->table('fct_coupons')->insertGetId([
                    'title' => $renamed, 'code' => $renamed, 'type' => 'fixed', 'amount' => 100, 'status' => 'active', 'notes' => '',
                    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $old = $order(['subtotal' => 1000, 'coupon_discount_total' => 100, 'total_amount' => 900, 'total_paid' => 900], 4);
                $coupon($old->id, 'FCTESTOLD', 100, $couponId);

                $report = CouponReportService::make()->getReport([
                    'startDate'     => new DateTime($window['start']),
                    'endDate'       => new DateTime($window['end']),
                    'currency'      => $fcCouponCurrency,
                    'paymentStatus' => Status::getReportStatuses(),
                    'variationIds'  => [],
                ]);
                $rows = array_column($report['rows'], null, 'code');
                $pick = function ($row) {
                    return array_intersect_key($row, array_flip(['orders', 'new_orders', 'returning_orders', 'gross_sales', 'discount',
                        'refunds', 'net_sales', 'first_order_net', 'later_net', 'lifetime_net', 'discount_rate']));
                };

                FcTest::assertSame([
                    'orders' => 2, 'new_orders' => 1, 'returning_orders' => 1, 'gross_sales' => 21.0, 'discount' => 4.0,
                    'refunds' => 3.0, 'net_sales' => 18.0, 'first_order_net' => 18.0, 'later_net' => 5.0, 'lifetime_net' => 23.0,
                    'discount_rate' => 16.0,
                ], $pick($rows[$codeA] ?? []), 'Coupon A: its own order in full, three quarters of the shared order, and the renewal');
                FcTest::assertSame([
                    'orders' => 1, 'new_orders' => 0, 'returning_orders' => 1, 'gross_sales' => 4.0, 'discount' => 1.0,
                    'refunds' => 1.0, 'net_sales' => 3.0, 'first_order_net' => 3.0, 'later_net' => 0.0, 'lifetime_net' => 3.0,
                    'discount_rate' => 20.0,
                ], $pick($rows[$codeB] ?? []), 'Coupon B: a quarter of the shared order');
                FcTest::assertSame([3, 34.0, 6.0, 4.0, 5.0], [$report['totals']['orders'], $report['totals']['gross_sales'],
                    $report['totals']['discount'], $report['totals']['refunds'], $report['totals']['later_net']],
                    'Totals count the shared order once');
                FcTest::assertSame(['orders' => 1, 'discount' => 2.0], $report['no_code'], 'A header discount with no code record is shown apart');
                FcTest::assertSame([1, ['FCTESTOLD']], [$rows[$renamed]['orders'] ?? null, $rows[$renamed]['used_as'] ?? null],
                    'A renamed coupon shows its current code and the code used on the order');

                $route = FcTest::rest('GET', 'reports/coupons', ['params' => [
                    'startDate' => $window['start'], 'endDate' => $window['end'], 'currency' => $fcCouponCurrency,
                    'paymentStatus' => Status::getReportStatuses(), 'orderTypes' => [Status::ORDER_TYPE_SUBSCRIPTION],
                ]]);
                FcTest::assertSame([200, 0], [$route['status'], (int) ($route['data']['totals']['orders'] ?? -1)], 'GET /reports/coupons applies the Order Type filter');
            } finally {
                if ($couponId) {
                    $db->table('fct_coupons')->where('id', $couponId)->delete();
                }
                if ($orderIds) {
                    $db->table('fct_applied_coupons')->whereIn('order_id', $orderIds)->delete();
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
