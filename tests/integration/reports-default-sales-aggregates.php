<?php
/**
 * Phase 7 exact-value coverage for default sales and top-item reports.
 */

return [
    [
        'id'            => 'reports-default-sales-aggregates',
        'name'          => 'Default sales and top-item reports return exact owned values',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            try {
                $data = FcReportFixture::createDataset();
                $params = FcReportFixture::params('daily');
                $service = \FluentCart\App\Services\Report\DefaultReportService::make();

                $sales = $service->getAllGraphMetricsSeparate($params);
                FcTest::assertSame(
                    200.0,
                    (float) $sales['summary']['gross_sale'],
                    'GET /reports/sales-report returns exact gross 200.00 and excludes decoys'
                );
                FcTest::assertSame(
                    195.0,
                    (float) $sales['summary']['net_revenue'],
                    'GET /reports/sales-report returns exact net 195.00'
                );
                FcTest::assertSame(
                    2,
                    (int) $sales['summary']['order_count'],
                    'GET /reports/sales-report counts exactly two owned paid Orders'
                );
                FcTest::assertSame(
                    5,
                    (int) $sales['summary']['total_item_count'],
                    'GET /reports/sales-report sums exact item quantity five'
                );
                FcTest::assertSame(
                    2,
                    (int) $sales['summary']['onetime_count'],
                    'GET /reports/sales-report returns exact onetime count'
                );
                FcTest::assertSame(
                    0,
                    (int) $sales['summary']['renewal_count'],
                    'GET /reports/sales-report preserves the exact zero renewal count'
                );

                $products = $service->fetchTopSoldProducts($params)['topSoldProducts'];
                FcTest::assertSame(
                    1,
                    count($products),
                    'GET /reports/fetch-top-sold-products returns one synthetic product group'
                );
                FcTest::assertSame(
                    (int) $data['product_id'],
                    (int) $products->first()['product_id'],
                    'GET /reports/fetch-top-sold-products preserves the exact synthetic product ID'
                );
                FcTest::assertSame(
                    FcFixture::reportMarker('product'),
                    (string) $products->first()['product_name'],
                    'GET /reports/fetch-top-sold-products returns the exact inert item title'
                );
                FcTest::assertSame(
                    5,
                    (int) $products->first()['quantity_sold'],
                    'GET /reports/fetch-top-sold-products excludes pending/future item quantities'
                );
                FcTest::assertSame(
                    200.0,
                    (float) $products->first()['total_amount'],
                    'GET /reports/fetch-top-sold-products returns exact line total 200.00'
                );

                $variants = $service->fetchTopSoldVariants($params)['topSoldVariants'];
                FcTest::assertSame(
                    1,
                    count($variants),
                    'GET /reports/fetch-top-sold-variants returns one synthetic variant group'
                );
                FcTest::assertSame(
                    (int) $data['variation_id'],
                    (int) $variants->first()['variation_id'],
                    'GET /reports/fetch-top-sold-variants preserves the exact synthetic variant ID'
                );
                FcTest::assertSame(
                    FcFixture::reportMarker('variant'),
                    (string) $variants->first()['variation_name'],
                    'GET /reports/fetch-top-sold-variants returns the exact inert item title'
                );
                FcTest::assertSame(
                    5,
                    (int) $variants->first()['quantity'],
                    'GET /reports/fetch-top-sold-variants excludes pending/future quantities'
                );
                FcTest::assertSame(
                    200.0,
                    (float) $variants->first()['total_amount'],
                    'GET /reports/fetch-top-sold-variants returns exact line total 200.00'
                );
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
];
