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
                    'DefaultReportService::getAllGraphMetricsSeparate (store digest) returns exact gross 200.00 and excludes decoys'
                );
                FcTest::assertSame(
                    188.0,
                    (float) $sales['summary']['net_revenue'],
                    'DefaultReportService::getAllGraphMetricsSeparate (store digest) returns net sales 188.00, as the Revenue report: the 5.00 + 2.00 shipping stays out'
                );
                FcTest::assertSame(
                    2,
                    (int) $sales['summary']['order_count'],
                    'DefaultReportService::getAllGraphMetricsSeparate (store digest) counts exactly two owned paid Orders'
                );
                FcTest::assertSame(
                    5,
                    (int) $sales['summary']['total_item_count'],
                    'DefaultReportService::getAllGraphMetricsSeparate (store digest) sums exact item quantity five'
                );
                FcTest::assertSame(
                    2,
                    (int) $sales['summary']['onetime_count'],
                    'DefaultReportService::getAllGraphMetricsSeparate (store digest) returns exact onetime count'
                );
                FcTest::assertSame(
                    0,
                    (int) $sales['summary']['renewal_count'],
                    'DefaultReportService::getAllGraphMetricsSeparate (store digest) preserves the exact zero renewal count'
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

                // The Products report's variant row (amounts in cents)
                $products = \FluentCart\App\Services\Report\ProductReportService::make()->getProductSales($params)['products'];
                FcTest::assertSame(
                    1,
                    count($products[0]['variations'] ?? []),
                    'GET /reports/product-sales returns one synthetic variant group'
                );
                $variant = $products[0]['variations'][0];
                FcTest::assertSame(
                    (int) $data['variation_id'],
                    (int) $variant['variation_id'],
                    'GET /reports/product-sales preserves the exact synthetic variant ID'
                );
                FcTest::assertSame(
                    FcFixture::reportMarker('variant'),
                    (string) $variant['variation_title'],
                    'GET /reports/product-sales returns the exact inert item title'
                );
                FcTest::assertSame(
                    5,
                    (int) $variant['units'],
                    'GET /reports/product-sales excludes pending/future quantities'
                );
                FcTest::assertSame(
                    200.0,
                    (float) $variant['gross'] / 100,
                    'GET /reports/product-sales returns exact gross 200.00'
                );
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
];
