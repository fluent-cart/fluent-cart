<?php
/**
 * Coupons overview: discount given, coupon orders and their share, new customers won with a coupon (the shared
 * first-purchase rule), coupon vs full-price new customers (first order, lifetime value, repeat, refunds), a chart
 * by period, and per coupon the lifetime value of the customers it won and its refund rate.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\CouponReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcCouponOverviewCurrency = 'XTS';

return [
    [
        'id'            => 'reports-coupon-overview',
        'name'          => 'Coupons overview: discount, coupon share, coupon vs full-price new customers, chart, per-coupon won value',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcCouponOverviewCurrency) {
            $db = App::db();
            $owner = null;
            $orderIds = [];
            try {
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcCouponOverviewCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcCouponOverviewCurrency);
                }
                $owner = (int) FcFixture::customer()->id;
                $base = 1560000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 10;
                $code = 'FCOVER' . substr(md5(FcFixture::identity()), 0, 6);

                $order = function ($customer, $createdAt, $paid, $discount = 0, $refund = 0) use ($fcCouponOverviewCurrency, &$orderIds, $db, $code) {
                    $row = FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'currency' => $fcCouponOverviewCurrency, 'customer_id' => $customer,
                        'payment_status' => $refund ? Status::PAYMENT_REFUNDED : Status::PAYMENT_PAID, 'type' => Status::ORDER_TYPE_PAYMENT,
                        'subtotal' => $paid + $discount, 'coupon_discount_total' => $discount, 'tax_total' => 0, 'total_amount' => $paid,
                        'total_paid' => $paid, 'total_refund' => $refund, 'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ]);
                    $orderIds[] = (int) $row->id;
                    if ($discount) {
                        $db->table('fct_applied_coupons')->insert(['order_id' => $row->id, 'coupon_id' => null, 'code' => $code,
                            'amount' => $discount, 'created_at' => $createdAt, 'updated_at' => $createdAt]);
                    }

                    return $row;
                };

                // March: Alice won with a coupon (9.00 after 1.00 off), buys again in April
                $order($base, '2001-03-02 10:00:00', 900, 100);
                $order($base, '2001-04-02 10:00:00', 1000);
                // Bob and Erin at full price
                $order($base + 1, '2001-03-03 10:00:00', 2000);
                $order($base + 4, '2001-03-06 10:00:00', 1500);
                // Carol won with a coupon, refunded in full
                $order($base + 2, '2001-03-04 10:00:00', 400, 100, 400);
                // Dave, a returning customer, uses the coupon (2.00 off)
                $order($base + 3, '2001-01-05 10:00:00', 700);
                $order($base + 3, '2001-03-05 10:00:00', 800, 200);
                // ...and a 100% coupon: nothing paid, 5.00 off — still a discount given
                $order($base + 3, '2001-03-07 10:00:00', 0, 500);
                // Frank: a free order with a coupon first (3.00 off, nothing paid), then his first paid purchase at full price
                $order($base + 5, '2001-03-08 10:00:00', 0, 300);
                $order($base + 5, '2001-03-20 10:00:00', 600);

                $params = [
                    'startDate' => new DateTime('2001-03-01 00:00:00'), 'endDate' => new DateTime('2001-03-31 23:59:59'),
                    'comparePeriod' => [new DateTime('2001-02-01 00:00:00'), new DateTime('2001-02-28 23:59:59')],
                    'currency' => $fcCouponOverviewCurrency, 'paymentStatus' => Status::getReportStatuses(), 'groupKey' => 'monthly', 'variationIds' => [],
                ];
                $service = CouponReportService::make();
                $overview = $service->getOverview($params);

                $s = $overview['summary'];
                FcTest::assertSame([1200, 8, 5, 62.5, 5, 2, 40.0, 36.4], [$s['discount'], $s['orders'], $s['coupon_orders'], $s['coupon_share'],
                    $s['new_customers'], $s['coupon_new_customers'], $s['new_share'], $s['discount_rate']],
                    'March: 12.00 off on 5 of 8 orders (two free with a coupon); 2 of the 5 new customers came with a coupon '
                    . '(Frank\'s first paid purchase was at full price); 12.00 of 33.00 before discount');
                FcTest::assertSame(0, $overview['previous']['orders'], 'February had no orders');

                $coupon = $overview['comparison']['coupon'];
                $full = $overview['comparison']['full_price'];
                FcTest::assertSame([2, 450, 950, 50.0, 50.0], [$coupon['customers'], $coupon['average_first'], $coupon['average_ltv'],
                    $coupon['repeat_rate'], $coupon['refund_rate']], 'Coupon customers: Alice (19.00 to date, bought again) and Carol (refunded)');
                FcTest::assertSame([3, 1367, 1367, 0.0, 0.0], [$full['customers'], $full['average_first'], $full['average_ltv'],
                    $full['repeat_rate'], $full['refund_rate']], 'Full-price customers: Bob, Erin and Frank');

                $march = array_values(array_filter($overview['chart'], function ($point) {
                    return $point['group'] === '2001-03';
                }))[0];
                FcTest::assertSame([1200, 8, 5], [$march['discount'], $march['orders'], $march['coupon_orders']], 'The chart\'s March point');

                $rows = array_column($service->getReport($params)['rows'], null, 'code');
                FcTest::assertSame([5, 2, 3, 9.5, 20.0], [$rows[$code]['orders'], $rows[$code]['new_orders'], $rows[$code]['returning_orders'],
                    $rows[$code]['won_ltv'], $rows[$code]['refund_rate']],
                    'The coupon won Alice and Carol (19.00 and 0, 9.50 each), not Frank, whose coupon order was free; '
                    . 'one of its five orders was refunded');
            } finally {
                if ($orderIds) {
                    $db->table('fct_applied_coupons')->whereIn('order_id', $orderIds)->delete();
                }
                if ($owner && $orderIds) {
                    $db->table('fct_orders')->whereIn('id', $orderIds)->update(['customer_id' => $owner]);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
