<?php
/**
 * Exact-value coverage for the Products Compare totals.
 */

return [
    [
        'id'            => 'reports-product-compare',
        'name'          => 'Product compare counts a shared Order once per entity and splits its money by line',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            try {
                $data = FcReportFixture::createDataset();
                $currency = $data['window']['currency'];
                $productA = (int) $data['product_id'] + 10;
                $productB = (int) $data['product_id'] + 11;
                $variationA = (int) $data['variation_id'] + 10;
                $variationB = (int) $data['variation_id'] + 11;

                $order = function ($total, $refund, $date = '2001-02-03 10:00:00') use ($currency) {
                    return FcFixture::reportOrder([
                        'status'               => 'completed',
                        'payment_status'       => $refund ? 'partially_refunded' : 'paid',
                        'payment_method'       => 'phase7-card',
                        'payment_method_title' => 'Phase 7 Card',
                        'currency'             => $currency,
                        'subtotal'             => $total,
                        'total_amount'         => $total,
                        'total_paid'           => $total,
                        'total_refund'         => $refund,
                        'completed_at'         => $date,
                        'created_at'           => $date,
                        'updated_at'           => $date,
                        'type'                 => 'payment',
                        'mode'                 => 'test',
                    ]);
                };
                $line = function ($order, $productId, $variationId, $index, $quantity, $total, $refund = 0, $date = '2001-02-03 10:00:00') {
                    FcFixture::reportOrderItem((int) $order->id, [
                        'post_id'      => $productId,
                        'object_id'    => $variationId,
                        'post_title'   => 'Compare product ' . $productId,
                        'title'        => 'Compare variation ' . $variationId,
                        'cart_index'   => $index,
                        'quantity'     => $quantity,
                        'unit_price'   => (int) ($total / $quantity),
                        'subtotal'     => $total,
                        'line_total'   => $total,
                        'refund_total' => $refund,
                        'created_at'   => $date,
                    ]);
                };

                $line($order(2000, 0), $productA, $variationA, 0, 2, 2000);
                $line($order(1500, 0), $productB, $variationB, 0, 1, 1500);
                $shared = $order(4000, 500);
                $line($shared, $productA, $variationA, 0, 1, 1000);
                $line($shared, $productB, $variationB, 1, 3, 3000, 500);

                $params = FcReportFixture::params('daily');
                $params['paymentStatus'] = ['paid', 'partially_refunded'];
                $idle = FcDomainFixture::product();
                $productC = (int) $idle['post']->ID;
                $variationC = (int) $idle['variation']->id;

                $params['variationIds'] = [$variationA, $variationB, $variationC];
                $service = \FluentCart\App\Services\Report\ProductReportService::make();
                $compare = $service->getProductCompare($params);

                $entities = [];
                foreach ($compare['entities'] as $entity) {
                    $entities[(int) $entity['variation_id']] = (int) $entity['product_id'];
                }
                FcTest::assertSame([$variationA => $productA, $variationB => $productB, $variationC => $productC], $entities, 'one entity per picked variation in pick order, a pick without sales included');

                $totals = function ($variationId) use ($compare) {
                    $row = $compare['totals'][$variationId];
                    return [(int) $row['units'], round((float) $row['revenue'], 4), (int) $row['orders']];
                };
                FcTest::assertSame([3, 30.0, 2], $totals($variationA), 'A: 2 + 1 units, 20.00 + its 10.00 line of the shared Order, 2 Orders');
                FcTest::assertSame([4, 40.0, 2], $totals($variationB), 'B: 1 + 3 units, 15.00 + its 30.00 line less the 5.00 line refund, the shared Order once');
                FcTest::assertSame(5.0, round((float) ($compare['totals'][$variationB]['refunds'] ?? -1), 4), 'B: its 5.00 line refund');
                FcTest::assertSame([0, 0.0, 0], $totals($variationC), 'C: picked, no Orders in range, zero row');

                $params['variationIds'] = array_merge(
                    [$variationB, $variationC],
                    range((int) $data['variation_id'] + 100, (int) $data['variation_id'] + 122),
                    [$variationA]
                );
                $many = $service->getProductCompare($params);
                FcTest::assertSame(30.0, round((float) $many['totals'][$variationA]['revenue'], 4), '26 picks: the 26th is still counted');

                $params['variationIds'] = array_merge(
                    range((int) $data['variation_id'] + 100, (int) $data['variation_id'] + 149),
                    [$variationA]
                );
                $capped = $service->getProductCompare($params);
                FcTest::assertSame(false, isset($capped['totals'][$variationA]), '51 picks: the 51st is dropped in pick order');
                FcTest::assertSame(50, $capped['variation_limit'], 'the cap is reported so the page can say picks were dropped');

                $request = function (array $ids) {
                    return FcTest::rest('GET', 'reports/product-compare', [
                        'params' => array_merge(FcReportFixture::requestParams(), ['variation_ids' => $ids]),
                    ]);
                };
                $route = $request([$variationA]);
                if (\FluentCart\App\App::isProActive()) {
                    $served = array_map('intval', array_column((array) ($route['data']['entities'] ?? []), 'variation_id'));
                    FcTest::assertSame([200, [$variationA]], [$route['status'], $served], 'Pro active: the route serves the picked entity');
                } else {
                    FcTest::assertSame(403, $route['status'], 'Pro absent: the route refuses Compare');
                }

                $line($order(500, 0, '2001-01-20 10:00:00'), $productA, $variationA, 0, 1, 500, 0, '2001-01-20 10:00:00');
                $params['startDate'] = new \FluentCart\App\Services\DateTime\DateTime('2001-01-01 00:00:00');
                $params['groupKey'] = 'monthly';
                $params['variationIds'] = [$variationA, $variationB, $variationC];
                $monthly = $service->getProductCompare($params);

                FcTest::assertSame(['2001-01', '2001-02'], $monthly['buckets'], 'one monthly bucket per month of the range');
                $series = function ($variationId) use ($monthly) {
                    $row = $monthly['series'][$variationId];
                    return [$row['units'], array_map(function ($value) {
                        return round((float) $value, 4);
                    }, $row['revenue']), $row['orders']];
                };
                FcTest::assertSame([[1, 3], [5.0, 30.0], [1, 2]], $series($variationA), 'A per month: the January Order, then its February units and lines');
                FcTest::assertSame([[0, 4], [0.0, 40.0], [0, 2]], $series($variationB), 'B per month: zero-filled January, the shared Order split by line in February');
                FcTest::assertSame(false, isset($monthly['series'][$variationC]), 'C per month: a pick without sales gets no series entry, the page zero-fills it');
                FcTest::assertSame('monthly', $monthly['group_key'], 'the grouping the buckets were built with is reported');

                $params['startDate'] = new \FluentCart\App\Services\DateTime\DateTime('2001-01-15 00:00:00');
                $params['endDate'] = new \FluentCart\App\Services\DateTime\DateTime('2001-03-10 23:59:59');
                $midMonth = $service->getProductCompare($params);
                FcTest::assertSame(['2001-01', '2001-02', '2001-03'], $midMonth['buckets'], 'a mid-month start keeps a final month without sales');
                FcTest::assertSame([1, 3, 0], $midMonth['series'][$variationA]['units'], 'A per month: the sale-free March is zero-filled');

                $params['groupKey'] = 'yearly';
                FcTest::assertSame(['2001'], $service->getProductCompare($params)['buckets'], 'yearly bucket keys stay strings for the chart labels');

                $params['groupKey'] = 'daily';
                $params['startDate'] = new \FluentCart\App\Services\DateTime\DateTime('2000-01-01 00:00:00');
                $params['endDate'] = new \FluentCart\App\Services\DateTime\DateTime('2001-03-10 23:59:59');
                $coarsened = $service->getProductCompare($params);
                FcTest::assertSame('monthly', $coarsened['group_key'], 'daily over more than 366 days coarsens to monthly');
                FcTest::assertSame(15, count($coarsened['buckets']), 'Jan 2000 to Mar 2001: one bucket per month');
                FcTest::assertSame([1, 3, 0], array_slice($coarsened['series'][$variationA]['units'], 12), 'A per month in the coarsened buckets: January, February, a sale-free March');

                $params['groupKey'] = 'payment_method';
                FcTest::assertSame('yearly', $service->getProductCompare($params)['group_key'], 'a non-time grouping key falls back to Auto, yearly for a range over a year');

                $params['startDate'] = new \FluentCart\App\Services\DateTime\DateTime('1600-01-01 00:00:00');
                $rejected = null;
                try {
                    $service->getProductCompare($params);
                } catch (\FluentCart\Framework\Foundation\Exceptions\HttpException $e) {
                    $rejected = $e->getStatusCode();
                }
                FcTest::assertSame(422, $rejected, 'more than 366 years cannot coarsen further and is rejected');
                $params['startDate'] = new \FluentCart\App\Services\DateTime\DateTime($data['window']['start']);
                $params['endDate'] = new \FluentCart\App\Services\DateTime\DateTime($data['window']['end']);
                $params['groupKey'] = 'daily';
                $off = $service->getProductCompare($params);
                FcTest::assertSame([null, null], [$off['previous_totals'], $off['compare_range']], 'compare period off: no previous totals and no compare range');

                $line($order(1200, 0, '2001-02-01 10:00:00'), $productA, $variationA, 0, 2, 1200, 0, '2001-02-01 10:00:00');
                $productD = (int) $data['product_id'] + 12;
                $variationD = (int) $data['variation_id'] + 12;
                $line($order(800, 0, '2001-02-02 10:00:00'), $productD, $variationD, 0, 1, 800, 0, '2001-02-02 10:00:00');
                $params['variationIds'] = [$variationA, $variationB, $variationC, $variationD];
                $params['comparePeriod'] = \FluentCart\App\Services\Report\ReportHelper::getCompareRange('previous_period', $params);
                $previous = $service->getProductCompare($params);
                $previousTotals = function ($variationId) use ($previous) {
                    $row = $previous['previous_totals'][$variationId];
                    return [(int) $row['units'], round((float) $row['revenue'], 4), (int) $row['orders']];
                };
                FcTest::assertSame(['start' => '2001-01-31 00:00:00', 'end' => '2001-02-02 23:59:59'], $previous['compare_range'], 'previous period of a 3-day range is the 3 days before it');
                FcTest::assertSame([2, 12.0, 1], $previousTotals($variationA), 'A: only its Order inside the compare period, not the January one');
                FcTest::assertSame([0, 0.0, 0], $previousTotals($variationB), 'B: no Orders in the compare period, zero row');
                FcTest::assertSame([0, 0.0, 0], $previousTotals($variationC), 'C: picked, no Orders in either period, zero row');
                FcTest::assertSame(30.0, round((float) $previous['totals'][$variationA]['revenue'], 4), 'current totals are unchanged by the compare period');

                $entityD = array_values(array_filter($previous['entities'], function ($entity) use ($variationD) {
                    return (int) $entity['variation_id'] === $variationD;
                }));
                FcTest::assertSame([$productD, 'Compare product ' . $productD], $entityD ? [(int) $entityD[0]['product_id'], $entityD[0]['product_title']] : null, 'D: no catalog row, sold only in the compare period, still an entity from its order line');
                FcTest::assertSame([0, 0.0, 0, 0, 0.0, 0.0], array_values(array_map(function ($value) {
                    return is_float($value) ? round($value, 4) : $value;
                }, $previous['totals'][$variationD] ?? [])), 'D: zero current totals');
                FcTest::assertSame([1, 8.0, 1], $previousTotals($variationD), 'D: its compare-period Order in previous totals');
            } finally {
                FcDomainFixture::cleanupAll();
                FcFixture::cleanupAll();
            }
        },
    ],
];
