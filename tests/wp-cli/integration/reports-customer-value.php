<?php
/**
 * Customers report value: lifetime value by first-purchase month (cumulative average per customer by months since),
 * value by first product for the customers gained in the range, and revenue concentration (the share of revenue
 * from the top customers), all in the report currency.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCartPro\App\Modules\Reports\Services\CustomerInsightsService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcValueCurrency = 'XTS';

return [
    [
        'id'            => 'reports-customer-value',
        'name'          => 'Customers value: lifetime value by first-purchase month, value by first product, revenue concentration',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcValueCurrency) {
            // A Pro report: without Pro the route is gone and the free page shows a locked preview
            if (!class_exists(CustomerInsightsService::class)) {
                $route = FcTest::rest('GET', 'reports/customer-value', ['params' => ['currency' => $fcValueCurrency]]);
                FcTest::assertSame(404, $route['status'], 'Pro absent: core no longer serves reports/customer-value');

                return;
            }

            $db = App::db();
            $owner = null;
            $orderIds = [];
            try {
                $foreign = (int) $db->table('fct_orders')->whereIn('currency', [$fcValueCurrency, 'XXX'])->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcValueCurrency);
                }
                $owner = (int) FcFixture::customer()->id;
                $base = 1600000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 10;
                [$p, $q] = [$base, $base + 2];
                [$alice, $bob, $carol, $dave] = [$base + 5, $base + 6, $base + 7, $base + 8];

                $sale = function ($customer, $type, $createdAt, array $lines, $parent = 0, array $extra = []) use ($fcValueCurrency, &$orderIds) {
                    $total = array_sum(array_column($lines, 1));
                    $order = FcFixture::reportOrder(array_merge([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcValueCurrency,
                        'customer_id' => $customer, 'type' => $type, 'parent_id' => $parent ?: null, 'subtotal' => $total, 'tax_total' => 0,
                        'total_amount' => $total, 'total_paid' => $total, 'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ], $extra));
                    $orderIds[] = (int) $order->id;
                    foreach ($lines as $line) {
                        // [product, cents]
                        FcFixture::reportOrderItem($order->id, ['post_id' => $line[0], 'object_id' => $line[0] + 1, 'unit_price' => $line[1],
                            'subtotal' => $line[1], 'line_total' => $line[1], 'created_at' => $createdAt]);
                    }

                    return $order;
                };

                // January: Alice (P, then Q in February, then a renewal in March) and Bob (P)
                $aliceFirst = $sale($alice, Status::ORDER_TYPE_SUBSCRIPTION, '2001-01-10 10:00:00', [[$p, 1000]]);
                $sale($alice, Status::ORDER_TYPE_PAYMENT, '2001-02-15 10:00:00', [[$q, 500]]);
                $sale($alice, Status::ORDER_TYPE_RENEWAL, '2001-03-20 10:00:00', [[$p, 1000]], (int) $aliceFirst->id);
                $sale($bob, Status::ORDER_TYPE_PAYMENT, '2001-01-20 10:00:00', [[$p, 2000]]);
                // February: Carol (Q twice); March: Dave (P and Q in one order)
                $sale($carol, Status::ORDER_TYPE_PAYMENT, '2001-02-05 10:00:00', [[$q, 400]]);
                $sale($carol, Status::ORDER_TYPE_PAYMENT, '2001-02-25 10:00:00', [[$q, 100]]);
                $sale($dave, Status::ORDER_TYPE_PAYMENT, '2001-03-03 10:00:00', [[$p, 300], [$q, 300]]);
                // Erin was refunded in full: she spent nothing, so she is no customer in the concentration
                $sale($base + 9, Status::ORDER_TYPE_PAYMENT, '2001-03-08 10:00:00', [[$p, 800]], 0, [
                    'payment_status' => Status::PAYMENT_REFUNDED, 'total_refund' => 800]);
                // Frank first bought in another currency, then Q in February: a returning customer, not a new one
                $sale($base + 10, Status::ORDER_TYPE_PAYMENT, '2000-12-05 10:00:00', [[$q, 700]], 0, ['currency' => 'XXX']);
                $sale($base + 10, Status::ORDER_TYPE_PAYMENT, '2001-02-12 10:00:00', [[$q, 900]]);

                $value = CustomerInsightsService::make()->getValue([
                    'startDate' => new DateTime('2001-01-01 00:00:00'), 'endDate' => new DateTime('2001-03-31 23:59:59'),
                    'currency' => $fcValueCurrency, 'paymentStatus' => Status::getReportStatuses(),
                ]);

                $cohorts = array_column($value['cohorts'], null, 'cohort');
                FcTest::assertSame([2, [1500, 1750, 2250]], [$cohorts['2001-01']['customers'], array_slice($cohorts['2001-01']['values'], 0, 3)],
                    'January\'s two customers: 30.00 in their first month, then Alice\'s 5.00 purchase, then her 10.00 renewal, per customer');
                FcTest::assertSame([[1, [500, 500]], [2, [300]]], [
                    [$cohorts['2001-02']['customers'], array_slice($cohorts['2001-02']['values'], 0, 2)],
                    [$cohorts['2001-03']['customers'], array_slice($cohorts['2001-03']['values'], 0, 1)],
                ], 'February: Carol alone (Frank had bought before, in another currency); March: Dave and Erin (refunded, 0)');
                FcTest::assertSame(12, count($value['cohorts']), 'The 12 months ending with the range\'s last month');

                $products = array_column($value['first_products'], null, 'product_id');
                FcTest::assertSame([[4, 1275, 25.0], [2, 550, 50.0]], [
                    [$products[$p]['customers'], $products[$p]['average_value'], $products[$p]['repeat_rate']],
                    [$products[$q]['customers'], $products[$q]['average_value'], $products[$q]['repeat_rate']],
                ], 'P first: Alice, Bob, Dave and Erin (refunded), worth 51.00 together, Alice bought again; '
                    . 'Q first: Carol (bought again) and Dave, not Frank, who had bought before');

                FcTest::assertSame([[1, 1, 38.5], [50, 3, 83.1]], array_map(function ($tier) {
                    return [$tier['top'], $tier['customers'], $tier['share']];
                }, [$value['concentration']['period'][0], $value['concentration']['period'][4]]),
                    'Five customers kept spend (not Erin, refunded in full): the top 1% (at least one) is Alice, 25.00 of 65.00; '
                    . 'the top half is Alice, Bob and Frank, 54.00');
                FcTest::assertSame([null, 83.1], [$value['concentration']['lifetime'],
                    CustomerInsightsService::make()->getLifetimeConcentration(['currency' => $fcValueCurrency, 'paymentStatus' => Status::getReportStatuses(),
                        'startDate' => new DateTime('2001-01-01 00:00:00'), 'endDate' => new DateTime('2001-03-31 23:59:59')])[4]['share']],
                    'All time is read only when asked for; here it matches the range, which holds every order');
            } finally {
                if ($owner && $orderIds) {
                    $db->table('fct_orders')->whereIn('id', $orderIds)->update(['customer_id' => $owner]);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
