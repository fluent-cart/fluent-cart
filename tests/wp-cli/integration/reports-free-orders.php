<?php
/**
 * A $0 order (a 100% coupon, a free product) is still an order: the product reports count it and its units, as the
 * Revenue and Orders reports do.
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\ProductReportService;
use FluentCart\App\Services\Report\RevenueReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcFreeCurrency = 'XTS';

return [
    [
        'id'            => 'reports-free-orders',
        'name'          => 'The product reports count a $0 order and its units, as the Revenue report does',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcFreeCurrency) {
            try {
                $foreign = (int) \FluentCart\App\App::db()->table('fct_orders')->where('currency', $fcFreeCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' orders already use currency ' . $fcFreeCurrency);
                }
                FcFixture::customer();
                // A free order in June, then the buyer's first paid order in July
                foreach ([[0, 1000, '2025-06-03 10:00:00'], [1000, 0, '2025-07-03 10:00:00']] as [$paid, $coupon, $at]) {
                    $order = FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcFreeCurrency,
                        'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => 1000, 'coupon_discount_total' => $coupon, 'tax_total' => 0,
                        'total_amount' => $paid, 'total_paid' => $paid, 'total_refund' => 0,
                        'created_at' => $at, 'updated_at' => $at,
                    ]);
                    FcFixture::reportOrderItem($order->id, ['object_id' => 990021, 'unit_price' => 1000, 'subtotal' => 1000,
                        'line_total' => $paid, 'created_at' => $at]);
                }

                $params = [
                    'startDate' => new DateTime('2025-06-01 00:00:00'), 'endDate' => new DateTime('2025-07-31 23:59:59'),
                    'currency' => $fcFreeCurrency, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [],
                    'groupKey' => 'monthly',
                ];
                $variation = ProductReportService::make()->getVariationRevenue($params)['variations'][0] ?? [];

                FcTest::assertSame(
                    [2, 2, 2, 10.0],
                    [
                        (int) RevenueReportService::make()->getRevenueData($params)['summary']['order_count'],
                        (int) ($variation['orders'] ?? -1),
                        (int) ($variation['units'] ?? -1),
                        round(($variation['net'] ?? -100) / 100, 2),
                    ],
                    'Revenue and the variation both count the free order; only the paid one adds money'
                );

                // In June the buyer has paid for nothing: no customer and no new customer yet (customers are paid-only)
                $june = ProductReportService::make()->getProductSales(array_merge($params, ['endDate' => new DateTime('2025-06-30 23:59:59')]))['summary'];
                FcTest::assertSame([1, 0, 0], [(int) ($june['orders'] ?? -1), (int) ($june['customers'] ?? -1), (int) ($june['new_customers'] ?? -1)],
                    'June: the free order counts as an order, its buyer as no customer');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
