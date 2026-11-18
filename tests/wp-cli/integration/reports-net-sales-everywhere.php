<?php
/**
 * Net sales is one figure on every report: what was paid, less refunds and the tax, shipping and fees the store
 * kept, as the Revenue report counts it. Orders with shipping, fees and a shipping-only refund prove it.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\CouponReportService;
use FluentCart\App\Services\Report\CustomerReportService;
use FluentCart\App\Services\Report\LocationReportService;
use FluentCart\App\Services\Report\PaymentMethodReportService;
use FluentCart\App\Services\Report\ProductReportService;
use FluentCart\App\Services\Report\RevenueReportService;
use FluentCart\App\Services\Report\SourceReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcNetCurrency = 'XTS';

return [
    [
        'id'            => 'reports-net-sales-everywhere',
        'name'          => 'Every report counts net sales as the Revenue report does: no shipping, fees or tax',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcNetCurrency) {
            $db = App::db();
            $orderIds = [];
            try {
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcNetCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' orders already use currency ' . $fcNetCurrency);
                }
                $customer = FcFixture::customer();
                $order = function (array $attributes, $variationId) use ($fcNetCurrency, &$orderIds) {
                    $row = FcFixture::reportOrder(array_merge([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcNetCurrency,
                        'type' => Status::ORDER_TYPE_PAYMENT, 'payment_method' => 'stripe', 'total_refund' => 0, 'tax_total' => 0,
                        'shipping_tax' => 0, 'shipping_total' => 0, 'fee_total' => 0, 'coupon_discount_total' => 0,
                        'manual_discount_total' => 0, 'created_at' => '2025-06-03 10:00:00', 'updated_at' => '2025-06-03 10:00:00',
                    ], $attributes));
                    $orderIds[] = (int) $row->id;
                    FcFixture::reportOrderItem($row->id, ['object_id' => $variationId, 'unit_price' => $attributes['subtotal'],
                        'subtotal' => $attributes['subtotal'], 'line_total' => $attributes['subtotal'], 'created_at' => '2025-06-03 10:00:00']);

                    return $row;
                };

                // 100.00 goods + 10.00 shipping + 5.00 fee: net sales 100.00
                $first = $order(['subtotal' => 10000, 'shipping_total' => 1000, 'fee_total' => 500, 'total_amount' => 11500,
                    'total_paid' => 11500], 990001);
                // 50.00 goods + 10.00 shipping, the shipping refunded (the store records it): net sales 50.00
                $shippingBack = $order(['subtotal' => 5000, 'shipping_total' => 1000, 'total_amount' => 6000, 'total_paid' => 6000,
                    'total_refund' => 1000, 'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED], 990002);
                \FluentCart\App\Models\OrderMeta::query()->create(['order_id' => (int) $shippingBack->id,
                    'meta_key' => 'shipping_refunded_amount', 'meta_value' => 1000]);
                // 40.00 goods + 5.00 fee + 4.00 tax: net sales 40.00
                $order(['subtotal' => 4000, 'fee_total' => 500, 'tax_total' => 400, 'total_amount' => 4900, 'total_paid' => 4900], 990001);

                // A July renewal of the coupon order (after the range): 30.00 goods + 5.00 shipping, the shipping refunded
                $renewal = $order(['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $first->id, 'subtotal' => 3000,
                    'shipping_total' => 500, 'total_amount' => 3500, 'total_paid' => 3500, 'total_refund' => 500,
                    'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED, 'created_at' => '2025-07-03 10:00:00',
                    'updated_at' => '2025-07-03 10:00:00'], 990001);
                \FluentCart\App\Models\OrderMeta::query()->create(['order_id' => (int) $renewal->id,
                    'meta_key' => 'shipping_refunded_amount', 'meta_value' => 500]);

                $db->table('fct_applied_coupons')->insert(['order_id' => (int) $first->id, 'coupon_id' => null,
                    'code' => 'FCTESTNET' . substr(md5(FcFixture::identity()), 0, 6), 'amount' => 0,
                    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);

                $params = [
                    'startDate' => new DateTime('2025-06-01 00:00:00'), 'endDate' => new DateTime('2025-06-30 23:59:59'),
                    'currency' => $fcNetCurrency, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [],
                    'groupKey' => 'monthly', 'orderTypes' => null, 'comparePeriod' => null,
                ];

                FcTest::assertSame(190.0, round((float) RevenueReportService::make()->getRevenueData($params)['summary']['net_sales'], 2),
                    'Revenue: 100 + 50 + 40');

                $coupons = CouponReportService::make()->getReport($params)['rows'];
                $customers = CustomerReportService::make()->getOverview($params)['summary'];
                FcTest::assertSame(
                    [
                        'payment_methods' => 190.0,
                        'locations'       => 190.0,
                        'sources'         => 190.0,
                        'products'        => 190.0,
                        'coupon'          => 100.0,
                        'customers'       => 190.0,
                    ],
                    [
                        'payment_methods' => round((float) PaymentMethodReportService::make()->getReport($params)['totals']['net_sales'], 2),
                        'locations'       => round((float) LocationReportService::make()->getReport($params + ['level' => 'country'])['totals']['net_sales'], 2),
                        'sources'         => round((float) SourceReportService::make()->getReport($params + ['group_by' => 'campaign', 'include_none' => true])['totals']['net_sales'], 2),
                        'products'        => round((float) ProductReportService::make()->getVariationRevenue($params)['totals']['net'] / 100, 2),
                        'coupon'          => round((float) ($coupons[0]['net_sales'] ?? -1), 2),
                        'customers'       => round(($customers['net'] ?? -100) / 100, 2),
                    ],
                    'Each report leaves out the shipping, fees and tax the store kept'
                );

                $sources = SourceReportService::make()->getReport($params + ['group_by' => 'campaign', 'include_none' => true])['totals'];
                FcTest::assertSame([30.0, 30.0], [round((float) ($coupons[0]['later_net'] ?? -1), 2), round((float) ($sources['later_net'] ?? -1), 2)],
                    'Later payments of the range\'s orders: the July renewal\'s 30.00, its refunded shipping found by the store record');

                $insights = 'FluentCartPro\\App\\Modules\\Reports\\Services\\CustomerInsightsService';
                if (class_exists($insights)) {
                    $net = null;
                    foreach ($insights::SEGMENTS as $segment) {
                        foreach ($insights::make()->getSegmentCustomers($params, $segment, 1, 1000)['data'] as $row) {
                            if ($row['customer_id'] === (int) $customer->id) {
                                $net = $row['net'];
                            }
                        }
                    }
                    FcTest::assertSame(22000, $net, 'Pro customer segments: lifetime net sales to date, 190.00 in June + the July renewal\'s 30.00');
                }
            } finally {
                if ($orderIds) {
                    $db->table('fct_applied_coupons')->whereIn('order_id', $orderIds)->delete();
                    $db->table('fct_order_meta')->whereIn('order_id', $orderIds)->delete();
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-renewals-card-net-sales',
        'name'          => 'The Subscriptions Renewals card shows renewal net sales, as the Revenue report\'s Renewal row',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcNetCurrency) {
            try {
                $foreign = (int) App::db()->table('fct_orders')->where('currency', $fcNetCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' orders already use currency ' . $fcNetCurrency);
                }
                FcFixture::customer();
                // A 20.00 renewal + 2.00 tax, 11.00 refunded (10.00 + its 1.00 tax): renewal net sales 10.00
                FcFixture::reportOrder([
                    'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED, 'currency' => $fcNetCurrency,
                    'type' => Status::ORDER_TYPE_RENEWAL, 'subtotal' => 2000, 'tax_total' => 200, 'shipping_total' => 0,
                    'shipping_tax' => 0, 'fee_total' => 0, 'coupon_discount_total' => 0, 'manual_discount_total' => 0,
                    'total_amount' => 2200, 'total_paid' => 2200, 'total_refund' => 1100,
                    'created_at' => '2025-06-03 10:00:00', 'updated_at' => '2025-06-03 10:00:00',
                ]);
                $params = [
                    'startDate' => new DateTime('2025-06-01 00:00:00'), 'endDate' => new DateTime('2025-06-30 23:59:59'),
                    'currency' => $fcNetCurrency, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [],
                    'groupKey' => 'monthly',
                ];
                $byType = array_column(RevenueReportService::make()->getRevenueByOrderType($params), null, 'key');
                $card = \FluentCart\App\Services\Report\SubscriptionReportService::make()->getOverview($params, false)['period'];

                FcTest::assertSame(
                    [10.0, 1, 10.0],
                    [round((float) ($byType[Status::ORDER_TYPE_RENEWAL]['net_sales'] ?? -1), 2), $card['renewals'], round((float) $card['renewal_amount'], 2)],
                    'The card counts the renewal and its net sales: tax and the refund stay out'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
