<?php
/**
 * Phase 7 exact-value coverage for filtered Order/Revenue/Customer/Source reports.
 */

return [
    [
        'id'            => 'reports-order-aggregates',
        'name'          => 'Filtered report services return exact owned values and exclude decoys',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            try {
                $data = FcReportFixture::createDataset();
                $expected = $data['expected'];
                $daily = FcReportFixture::params('daily');

                $revenueService = \FluentCart\App\Services\Report\RevenueReportService::make();
                $revenue = $revenueService->getRevenueData($daily);
                FcTest::assertSame(
                    $expected['gross'],
                    (float) $revenue['summary']['gross_sale'],
                    'GET /reports/revenue returns exact gross 200.00 and excludes pending/future decoys'
                );
                FcTest::assertSame(
                    $expected['net'],
                    (float) $revenue['summary']['net_revenue'],
                    'GET /reports/revenue returns exact net 195.00'
                );
                FcTest::assertSame(
                    $expected['order_count'],
                    (int) $revenue['summary']['order_count'],
                    'GET /reports/revenue counts exactly two paid in-window Orders'
                );
                FcTest::assertSame(
                    0.0,
                    (float) FcReportFixture::groupRow($revenue['groups'], '2001-02-03')['total_sales'],
                    'GET /reports/revenue preserves the exact leading zero bucket'
                );
                FcTest::assertSame(
                    123.45,
                    (float) FcReportFixture::groupRow($revenue['groups'], '2001-02-04')['total_sales'],
                    'GET /reports/revenue returns the exact first daily bucket'
                );
                FcTest::assertSame(
                    76.55,
                    (float) FcReportFixture::groupRow($revenue['groups'], '2001-02-05')['total_sales'],
                    'GET /reports/revenue returns the exact end-boundary daily bucket'
                );
                foreach (['monthly' => '2001-02', 'yearly' => '2001'] as $groupKey => $label) {
                    $variant = $revenueService->getRevenueData(FcReportFixture::params($groupKey));
                    FcTest::assertSame(
                        $expected['gross'],
                        (float) FcReportFixture::groupRow($variant['groups'], $label)['total_sales'],
                        'GET /reports/revenue ' . $groupKey . ' admin variation returns exact gross'
                    );
                }

                foreach ([
                    'billing_country'  => 'BD',
                    'shipping_country' => 'BD',
                    'payment_method'   => 'phase7-card',
                ] as $groupKey => $value) {
                    $params = FcReportFixture::params('daily');
                    $params['groupKey'] = $groupKey;
                    $groups = $revenueService->revenueByGroup($params);
                    FcTest::assertSame(
                        1,
                        count($groups),
                        'GET /reports/revenue-by-group ' . $groupKey
                        . ' returns only the owned dimension'
                    );
                    FcTest::assertSame(
                        $value,
                        (string) $groups[0]->{$groupKey},
                        'GET /reports/revenue-by-group preserves exact ' . $groupKey
                    );
                    FcTest::assertSame(
                        $expected['gross'],
                        (float) $groups[0]->gross_sale,
                        'GET /reports/revenue-by-group ' . $groupKey . ' returns exact gross'
                    );
                    FcTest::assertSame(
                        $expected['items'],
                        (int) $groups[0]->items,
                        'GET /reports/revenue-by-group ' . $groupKey . ' returns exact item quantity'
                    );
                }

                $orderService = \FluentCart\App\Services\Report\OrderReportService::make();
                $distribution = $orderService->getOrderValueDistribution($daily);
                FcTest::assertSame(
                    1,
                    (int) $distribution->{'0-100'},
                    'GET /reports/order-value-distribution includes exact 100.00 upper boundary'
                );
                FcTest::assertSame(
                    1,
                    (int) $distribution->{'100-200'},
                    'GET /reports/order-value-distribution includes exact 200.00 upper boundary'
                );
                FcTest::assertSame(
                    0,
                    (int) $distribution->{'1000+'},
                    'GET /reports/order-value-distribution excludes pending/future high-value decoys'
                );

                $newReturning = $orderService->getNewVsReturningCustomer($daily);
                FcTest::assertSame(
                    1,
                    count($newReturning),
                    'GET /reports/fetch-new-vs-returning-customer returns one exact owned class'
                );
                FcTest::assertSame(
                    'New',
                    (string) $newReturning[0]->customer_type,
                    'GET /reports/fetch-new-vs-returning-customer honors the first-purchase boundary'
                );
                FcTest::assertSame(
                    2,
                    (int) $newReturning[0]->order_count,
                    'GET /reports/fetch-new-vs-returning-customer counts exact owned Orders'
                );

                foreach ([
                    'billing_country'  => 'BD',
                    'shipping_country' => 'BD',
                    'payment_method'   => 'phase7-card',
                ] as $groupKey => $value) {
                    $params = $daily;
                    $params['groupKey'] = $groupKey;
                    $groups = $orderService->groupBy($params);
                    FcTest::assertSame(
                        1,
                        count($groups),
                        'GET /reports/fetch-order-by-group ' . $groupKey
                        . ' returns only the owned dimension'
                    );
                    FcTest::assertSame(
                        $value,
                        (string) $groups->first()->{$groupKey},
                        'GET /reports/fetch-order-by-group preserves exact ' . $groupKey
                    );
                    FcTest::assertSame(
                        $expected['gross'],
                        (float) $groups->first()->gross_sale,
                        'GET /reports/fetch-order-by-group returns exact gross'
                    );
                }

                $dayHour = $orderService->getReportByDayAndHour($daily);
                FcTest::assertSame(
                    1,
                    (int) $dayHour['orderByDayAndHour'][10]['Sunday'],
                    'GET /reports/fetch-report-by-day-and-hour maps the exact Sunday/hour bucket'
                );
                FcTest::assertSame(
                    1,
                    (int) $dayHour['orderByDayAndHour'][23]['Monday'],
                    'GET /reports/fetch-report-by-day-and-hour maps the exact end-boundary bucket'
                );
                FcTest::assertSame(
                    123.45,
                    (float) $dayHour['grossSaleByHour'][10]['gross_sale'],
                    'GET /reports/fetch-report-by-day-and-hour returns exact hourly gross'
                );

                $itemDistribution = $orderService->getItemCountDistribution($daily);
                $itemBuckets = [];
                foreach ($itemDistribution as $row) {
                    $itemBuckets[(int) $row->item_count] = (int) $row->order_count;
                }
                FcTest::assertSame(
                    [2 => 1, 3 => 1],
                    $itemBuckets,
                    'GET /reports/item-count-distribution returns exact quantities and excludes decoys'
                );

                $completion = $orderService->getOrderCompletionTime($daily);
                $completionBuckets = [];
                foreach ($completion as $row) {
                    $completionBuckets[(int) $row->hour] = (int) $row->orders;
                }
                FcTest::assertSame(
                    [3 => 1, 5 => 1],
                    $completionBuckets,
                    'GET /reports/order-completion-time returns exact elapsed-hour buckets'
                );

                foreach (['daily', 'monthly', 'yearly'] as $groupKey) {
                    $chart = $orderService->getOrderLineChart(
                        FcReportFixture::params($groupKey)
                    );
                    FcTest::assertSame(
                        $expected['gross'],
                        (float) $chart['summary']['gross_sale'],
                        'GET /reports/order-chart ' . $groupKey . ' variation returns exact gross'
                    );
                    FcTest::assertSame(
                        $expected['items'],
                        (int) $chart['summary']['total_item_count'],
                        'GET /reports/order-chart ' . $groupKey . ' variation returns exact items'
                    );
                    FcTest::assertSame(
                        2.5,
                        (float) $chart['summary']['average_order_items_count'],
                        'GET /reports/order-chart ' . $groupKey . ' returns exact average items'
                    );
                }

                foreach (['daily', 'monthly', 'yearly'] as $groupKey) {
                    $customerReport = \FluentCart\App\Services\Report\CustomerReportService::make()
                        ->getCustomerReportData(FcReportFixture::params($groupKey));
                    FcTest::assertSame(
                        1,
                        (int) $customerReport['summary']['customer_count'],
                        'GET /reports/customer-report ' . $groupKey
                        . ' variation returns the exact owned Customer'
                    );
                }

                $source = \FluentCart\App\Services\Report\SourceReportService::make()
                    ->getSourceReportData($daily);
                FcTest::assertSame(
                    1,
                    count($source),
                    'GET /reports/sources returns one exact owned UTM group'
                );
                FcTest::assertSame(
                    FcFixture::reportMarker('source'),
                    (string) $source->first()->utm_source,
                    'GET /reports/sources preserves the exact UTM source'
                );
                FcTest::assertSame(
                    $expected['gross'],
                    (float) $source->first()->gross_sales,
                    'GET /reports/sources returns exact gross 200.00'
                );

                $repeat = \FluentCart\App\Helpers\CustomerHelper::getRepeatCustomerBySearch([
                    'search'       => '',
                    'order_status' => 'completed',
                    'created_at'   => [
                        'column'   => 'created_at',
                        'operator' => 'between',
                        'value'    => [$data['window']['start'], $data['window']['end']],
                    ],
                ])->get();
                FcTest::assertSame(
                    [(int) $data['customer']->id],
                    array_map('intval', $repeat->pluck('id')->toArray()),
                    'GET /reports/search-repeat-customer returns only the exact two-order Customer'
                );
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
];
