<?php
/**
 * Report queries that strict SQL modes reject: the Products report under ONLY_FULL_GROUP_BY (run this file with
 * --axis=strict-sql).
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\ProductReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcStrictCurrency = 'XTS';

return [
    [
        'id'            => 'reports-product-top-chart',
        'name'          => 'Products report sums quantity per month and variation',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC', // fixtures are UTC days; reports-site-timezone covers other zones
        'run'           => function () use ($fcStrictCurrency) {
            try {
                $foreign = (int) App::db()->table('fct_orders')->where('currency', $fcStrictCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' orders already use currency ' . $fcStrictCurrency);
                }
                FcFixture::customer();
                $variation = 1700500000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 2;
                $order = FcFixture::reportOrder([
                    'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcStrictCurrency,
                    'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => 3000, 'total_amount' => 3000, 'total_paid' => 3000,
                    'created_at' => '2025-05-03 10:00:00', 'updated_at' => '2025-05-03 10:00:00',
                ]);
                FcFixture::reportOrderItem((int) $order->id, ['post_id' => $variation, 'object_id' => $variation, 'quantity' => 3,
                    'unit_price' => 1000, 'subtotal' => 3000, 'line_total' => 3000, 'created_at' => '2025-05-03 10:00:00']);

                $report = ProductReportService::make()->getProductSales([
                    'startDate' => new DateTime('2025-05-01 00:00:00'), 'endDate' => new DateTime('2025-05-31 23:59:59'),
                    'currency' => $fcStrictCurrency, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [],
                    'groupKey' => 'monthly',
                ]);
                $flat = [];
                foreach ($report['chart'] as $row) {
                    if ((int) $row['units'] > 0) {
                        foreach ($report['products'] as $product) {
                            foreach ($product['variations'] as $line) {
                                $flat[] = [(string) $row['group'], (int) $line['variation_id'], (int) $line['units']];
                            }
                        }
                    }
                }
                FcTest::assertSame([['2025-05', $variation, 3]], $flat, 'One variation, three units in May');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
