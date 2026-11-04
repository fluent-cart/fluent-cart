<?php
/**
 * Products report: one row per product with its variants, net sales split into new sales, renewals and installment
 * payments, new customers (whose first purchase it was), refund rate, a summary that counts orders and customers
 * once, a chart by period, and the change against the previous period.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\ProductReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcSalesCurrency = 'XTS';

return [
    [
        'id'            => 'reports-product-sales',
        'name'          => 'Products report: product rows with variants, new vs renewal sales, new customers, refund rate, compare',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcSalesCurrency) {
            $db = App::db();
            $owner = null;
            $orderIds = [];
            try {
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcSalesCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcSalesCurrency);
                }
                $owner = (int) FcFixture::customer()->id;
                $base = 1640000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 10;
                [$productA, $a1, $a2, $productB, $b1] = [$base, $base + 1, $base + 2, $base + 3, $base + 4];
                [$alice, $bob] = [$base + 5, $base + 6];

                $sale = function ($customer, $type, $createdAt, array $lines, $refund = 0, $parent = 0) use ($fcSalesCurrency, &$orderIds) {
                    $total = array_sum(array_column($lines, 2));
                    $order = FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'currency' => $fcSalesCurrency, 'customer_id' => $customer, 'type' => $type,
                        'payment_status' => $refund ? Status::PAYMENT_PARTIALLY_REFUNDED : Status::PAYMENT_PAID, 'parent_id' => $parent ?: null,
                        'subtotal' => $total, 'tax_total' => 0, 'total_amount' => $total, 'total_paid' => $total, 'total_refund' => $refund,
                        'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ]);
                    $orderIds[] = (int) $order->id;
                    foreach ($lines as $line) {
                        // [product, variation, cents, payment type]
                        FcFixture::reportOrderItem($order->id, ['post_id' => $line[0], 'object_id' => $line[1], 'payment_type' => $line[3],
                            'unit_price' => $line[2], 'subtotal' => $line[2], 'line_total' => $line[2], 'refund_total' => $refund, 'created_at' => $createdAt]);
                    }

                    return $order;
                };

                // March: Alice's first purchase is a one-time A1; Bob's is a new A2 subscription that renews in March too;
                // Alice then buys B1 (not her first purchase) and gets 2.00 of its 5.00 back
                // with a 1.00 checkout fee line: money in the totals, never a unit
                $sale($alice, Status::ORDER_TYPE_PAYMENT, '2001-03-02 10:00:00', [[$productA, $a1, 1000, 'onetime'], [0, 0, 100, 'fee']]);
                $first = $sale($bob, Status::ORDER_TYPE_SUBSCRIPTION, '2001-03-03 10:00:00', [[$productA, $a2, 2000, 'subscription']]);
                $sale($bob, Status::ORDER_TYPE_RENEWAL, '2001-03-20 10:00:00', [[$productA, $a2, 2000, 'subscription']], 0, (int) $first->id);
                $sale($alice, Status::ORDER_TYPE_PAYMENT, '2001-03-25 10:00:00', [[$productB, $b1, 500, 'onetime']], 200);
                // February, the previous period: A1 sold once for 4.00
                $sale($base + 7, Status::ORDER_TYPE_PAYMENT, '2001-02-10 10:00:00', [[$productA, $a1, 400, 'onetime']]);

                $params = [
                    'startDate' => new DateTime('2001-03-01 00:00:00'), 'endDate' => new DateTime('2001-03-31 23:59:59'),
                    'comparePeriod' => [new DateTime('2001-02-01 00:00:00'), new DateTime('2001-02-28 23:59:59')],
                    'currency' => $fcSalesCurrency, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [], 'groupKey' => 'monthly',
                ];
                $report = ProductReportService::make()->getProductSales($params);

                $rows = array_map(function ($product) {
                    return [$product['product_id'], $product['net'], $product['new_net'], $product['renewal_net'], $product['units'],
                        $product['orders'], $product['customers'], $product['new_customers'], $product['refunds'], $product['refund_rate'],
                        $product['previous_net'], count($product['variations'])];
                }, $report['products']);
                FcTest::assertSame([
                    [$productA, 5000, 3000, 2000, 3, 3, 2, 2, 0, 0.0, 400, 2],
                    [$productB, 300, 300, 0, 1, 1, 1, 0, 200, 40.0, 0, 1],
                ], $rows, 'A: 50.00 net, 30.00 new and 20.00 renewal, both customers new to the store with it, 4.00 in February; '
                    . 'B: 3.00 net after the 2.00 refund (40% of its sales), bought by a returning customer');

                FcTest::assertSame([[$a2, 4000, 2000, 2000], [$a1, 1000, 1000, 0]], array_map(function ($variation) {
                    return [$variation['variation_id'], $variation['net'], $variation['new_net'], $variation['renewal_net']];
                }, $report['products'][0]['variations']), 'A\'s variants, largest first');

                $summary = $report['summary'];
                FcTest::assertSame([5400, 5600, 200, 4, 4, 2, 2, 3400, 2000, 0], [$summary['net'], $summary['gross'], $summary['refunds'],
                    $summary['units'], $summary['orders'], $summary['customers'], $summary['new_customers'], $summary['new_net'],
                    $summary['renewal_net'], $summary['installment_net']],
                    'Totals: the fee in the money, not in units; orders and customers counted once across products');
                FcTest::assertSame([400, 1, 1], [$report['previous']['net'], $report['previous']['orders'], $report['previous']['customers']],
                    'The previous period\'s totals');

                FcTest::assertSame([['2001-03', 3400, 2000, 0, 4]], array_map(function ($point) {
                    return [$point['group'], $point['new_net'], $point['renewal_net'], $point['installment_net'], $point['units']];
                }, array_values(array_filter($report['chart'], function ($point) {
                    return $point['units'] > 0;
                }))), 'The chart: March by sale type');

                $filtered = ProductReportService::make()->getProductSales(array_merge($params, ['variationIds' => [$b1]]));
                FcTest::assertSame([[$productB], 300, 1], [array_column($filtered['products'], 'product_id'), $filtered['summary']['net'],
                    $filtered['summary']['orders']], 'The product filter keeps that variant only');
            } finally {
                // Cleanup deletes only orders still on the fixture customer
                if ($owner && $orderIds) {
                    $db->table('fct_orders')->whereIn('id', $orderIds)->update(['customer_id' => $owner]);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
