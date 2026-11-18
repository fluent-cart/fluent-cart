<?php
/**
 * Product Compare: each picked item is a whole product or one variation; its figures read money the way the Products
 * page does, count an order and a customer once per item, and come with the previous period and a chart by period.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCartPro\App\Modules\Reports\Services\ProductInsightsService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcComparisonCurrency = 'XTS';

return [
    [
        'id'            => 'reports-product-comparison',
        'name'          => 'Product Compare: products and variations side by side, counted once per item, with previous period and chart',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcComparisonCurrency) {
            // A Pro report: without Pro the route is gone and the free page shows a locked preview
            if (!class_exists(ProductInsightsService::class)) {
                $route = FcTest::rest('GET', 'reports/product-comparison', ['params' => ['currency' => $fcComparisonCurrency]]);
                FcTest::assertSame(404, $route['status'], 'Pro absent: core no longer serves reports/product-comparison');

                return;
            }

            $db = App::db();
            $owner = null;
            $orderIds = [];
            try {
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcComparisonCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcComparisonCurrency);
                }
                $owner = (int) FcFixture::customer()->id;
                $base = 1620000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 10;
                [$productA, $a1, $a2, $productB, $b1] = [$base, $base + 1, $base + 2, $base + 3, $base + 4];
                [$alice, $bob, $carol, $dave] = [$base + 5, $base + 6, $base + 7, $base + 8];

                $sale = function ($customer, $type, $createdAt, array $lines, $refund = 0, $parent = 0) use ($fcComparisonCurrency, &$orderIds) {
                    $total = array_sum(array_column($lines, 2));
                    $order = FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'currency' => $fcComparisonCurrency, 'customer_id' => $customer, 'type' => $type,
                        'payment_status' => $refund ? Status::PAYMENT_PARTIALLY_REFUNDED : Status::PAYMENT_PAID, 'parent_id' => $parent ?: null,
                        'subtotal' => $total, 'tax_total' => 0, 'total_amount' => $total, 'total_paid' => $total, 'total_refund' => $refund,
                        'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ]);
                    $orderIds[] = (int) $order->id;
                    foreach ($lines as $line) {
                        FcFixture::reportOrderItem($order->id, ['post_id' => $line[0], 'object_id' => $line[1], 'payment_type' => $line[3] ?? 'onetime',
                            'unit_price' => $line[2], 'subtotal' => $line[2], 'line_total' => $line[2], 'refund_total' => $refund, 'created_at' => $createdAt]);
                    }

                    return $order;
                };

                $sale($alice, Status::ORDER_TYPE_PAYMENT, '2001-03-02 10:00:00', [[$productA, $a1, 1000]]);
                $first = $sale($bob, Status::ORDER_TYPE_SUBSCRIPTION, '2001-03-03 10:00:00', [[$productA, $a2, 2000, 'subscription']]);
                $sale($bob, Status::ORDER_TYPE_RENEWAL, '2001-03-20 10:00:00', [[$productA, $a2, 2000, 'subscription']], 0, (int) $first->id);
                $sale($alice, Status::ORDER_TYPE_PAYMENT, '2001-03-25 10:00:00', [[$productB, $b1, 500]], 200);
                // Two variants of A in one order: one order, one customer for A
                $sale($carol, Status::ORDER_TYPE_PAYMENT, '2001-03-26 10:00:00', [[$productA, $a1, 300], [$productA, $a2, 300]]);
                $sale($dave, Status::ORDER_TYPE_PAYMENT, '2001-02-10 10:00:00', [[$productA, $a1, 400]]);
                // One early, isolated sale of A: not its launch, which starts its first three months running with sales
                $sale($dave, Status::ORDER_TYPE_PAYMENT, '2000-05-05 10:00:00', [[$productA, $a1, 100]]);
                // B1 given free alongside A in November: not B1's launch, which is its first paid month (March)
                $sale($dave, Status::ORDER_TYPE_PAYMENT, '2000-11-05 10:00:00', [[$productA, $a1, 900], [$productB, $b1, 0]]);
                $sale($dave, Status::ORDER_TYPE_PAYMENT, '2000-12-05 10:00:00', [[$productA, $a1, 200]]);
                $sale($dave, Status::ORDER_TYPE_PAYMENT, '2001-01-05 10:00:00', [[$productA, $a1, 300]]);

                $params = [
                    'startDate' => new DateTime('2001-03-01 00:00:00'), 'endDate' => new DateTime('2001-03-31 23:59:59'),
                    'comparePeriod' => [new DateTime('2001-02-01 00:00:00'), new DateTime('2001-02-28 23:59:59')],
                    'currency' => $fcComparisonCurrency, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [], 'groupKey' => 'monthly',
                ];
                $keys = ['p:' . $productA, 'v:' . $b1, 'v:' . $a2, 'v:' . $b1, 'x:9'];
                $result = ProductInsightsService::make()->getProductComparison($params, $keys);

                FcTest::assertSame(['p:' . $productA, 'v:' . $b1, 'v:' . $a2], array_column($result['items'], 'key'),
                    'Items in pick order; a repeat and an unknown kind are dropped');
                // Subscriptions and overlap have their own reads, so the main one stays light
                FcTest::assertSame([false, false], [isset($result['overlap']), isset($result['items'][0]['subscriptions'])],
                    'The main read leaves out overlap and subscriptions');

                $subscriptions = ProductInsightsService::make()->getProductComparisonSubscriptions($params, $keys);
                FcTest::assertSame([['p:' . $productA, 0], ['v:' . $b1, 0], ['v:' . $a2, 0]], array_map(function ($item) {
                    return [$item['key'], $item['subscriptions']['subscriptions']];
                }, $subscriptions['items']), 'Subscriptions per item, in pick order, keyed like the main read');

                $rows = array_map(function ($item) {
                    $s = $item['summary'];
                    return [$s['net'], $s['new_net'], $s['renewal_net'], $s['units'], $s['orders'], $s['customers'], $s['new_customers'],
                        $s['refund_rate'], $item['previous']['net']];
                }, $result['items']);
                FcTest::assertSame([
                    [5600, 3600, 2000, 5, 4, 3, 3, 0.0, 400],
                    [300, 300, 0, 1, 1, 1, 0, 40.0, 0],
                    [4300, 2300, 2000, 3, 3, 2, 2, 0.0, 0],
                ], $rows, 'A as a whole counts the two-variant order once; B1 is Alice\'s second purchase with 40% refunded; '
                    . 'A2 alone overlaps A');

                FcTest::assertSame([5600, 300, 4300], array_map(function ($item) {
                    return array_sum(array_column($item['chart'], 'net'));
                }, $result['items']), 'Each item\'s chart adds up to its net sales');
                FcTest::assertSame([4, 1, 3], array_map(function ($item) {
                    return array_sum(array_column($item['chart'], 'orders'));
                }, $result['items']), 'Orders by period add up to the item\'s orders');

                // Overlap: of each item's customers this period, who has ever bought the other, and who bought it first.
                // A: Alice, Bob, Carol; B1: Alice (bought A first); A2: Bob, Carol
                $overlap = [];
                foreach (ProductInsightsService::make()->getProductComparisonOverlap($params, $keys)['overlap'] as $pair) {
                    $overlap[$pair['from'] . '>' . $pair['to']] = [$pair['customers'], $pair['both'], $pair['to_first']];
                }
                FcTest::assertSame([3, 1, 0], $overlap['0>1'], 'Of A\'s 3 customers, Alice also bought B1, after A');
                FcTest::assertSame([1, 1, 1], $overlap['1>0'], 'B1\'s one customer owns A, bought first');
                FcTest::assertSame([2, 2, 0], $overlap['2>0'], 'A2\'s customers all own A (same purchase, not before)');

                // The route is served by Pro
                $route = FcTest::rest('GET', 'reports/product-comparison', [
                    'params' => ['startDate' => '2001-03-01 00:00:00', 'endDate' => '2001-03-31 23:59:59', 'currency' => $fcComparisonCurrency],
                    'items'  => ['p:' . $productA],
                ]);
                FcTest::assertSame([200, ['p:' . $productA]], [$route['status'], array_column((array) ($route['data']['items'] ?? []), 'key')],
                    'Pro active: the route serves the picked item');
                $routeQuery = [
                    'params' => ['startDate' => '2001-03-01 00:00:00', 'endDate' => '2001-03-31 23:59:59', 'currency' => $fcComparisonCurrency],
                    'items'  => ['p:' . $productA, 'v:' . $b1],
                ];
                $subscriptionsRoute = FcTest::rest('GET', 'reports/product-comparison-subscriptions', $routeQuery);
                $overlapRoute = FcTest::rest('GET', 'reports/product-comparison-overlap', $routeQuery);
                FcTest::assertSame([200, ['p:' . $productA, 'v:' . $b1], 200, ['p:' . $productA, 'v:' . $b1], 2],
                    [$subscriptionsRoute['status'], array_column((array) ($subscriptionsRoute['data']['items'] ?? []), 'key'),
                        $overlapRoute['status'], array_column((array) ($overlapRoute['data']['items'] ?? []), 'key'),
                        count((array) ($overlapRoute['data']['overlap'] ?? []))],
                    'Pro active: the subscriptions and overlap routes serve the picked items');

                // Since launch: months from each item's first sale, the previous period's sale included
                $launch = ProductInsightsService::make()->getProductComparisonLaunch([
                    'currency' => $fcComparisonCurrency, 'paymentStatus' => Status::getReportStatuses(),
                ], ['p:' . $productA, 'v:' . $b1]);
                FcTest::assertSame([['2000-11', [900, 200, 300, 400, 5600]], ['2001-03', [300]]], array_map(function ($item) {
                    return [substr($item['launched_at'], 0, 7), array_column($item['months'], 'net')];
                }, $launch['items']), 'A launched in November (its May sale stood alone); B1 has no run of three months yet, so its first paid month, '
                    . 'March, not the month it came free');
            } finally {
                if ($owner && $orderIds) {
                    $db->table('fct_orders')->whereIn('id', $orderIds)->update(['customer_id' => $owner]);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
