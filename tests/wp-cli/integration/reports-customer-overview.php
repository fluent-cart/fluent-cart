<?php
/**
 * Customers report overview: each customer who paid in the range is new (first purchase in the range), returning
 * (bought again, not a renewal) or renewing (renewals only), in that order; revenue split the same way by order;
 * repeat purchase rate and average lifetime value; the previous period; and a chart by period.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\CustomerReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcCustomerCurrency = 'XTS';

return [
    [
        'id'            => 'reports-customer-overview',
        'name'          => 'Customers overview: new, returning and renewing customers, revenue split, repeat rate, lifetime value',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcCustomerCurrency) {
            $db = App::db();
            $owner = null;
            $orderIds = [];
            try {
                $foreign = (int) $db->table('fct_orders')->whereIn('currency', [$fcCustomerCurrency, 'XXX'])->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcCustomerCurrency);
                }
                $owner = (int) FcFixture::customer()->id;
                $base = 1610000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 10;
                [$alice, $bob, $carol, $dave, $erin] = [$base, $base + 1, $base + 2, $base + 3, $base + 4];

                $order = function ($customer, $type, $createdAt, $cents, $refund = 0, $parent = 0, $currency = null) use ($fcCustomerCurrency, &$orderIds) {
                    $row = FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'currency' => $currency ?: $fcCustomerCurrency, 'customer_id' => $customer, 'type' => $type,
                        'payment_status' => $refund ? Status::PAYMENT_PARTIALLY_REFUNDED : Status::PAYMENT_PAID, 'parent_id' => $parent ?: null,
                        'subtotal' => $cents, 'tax_total' => 0, 'total_amount' => $cents, 'total_paid' => $cents, 'total_refund' => $refund,
                        'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ]);
                    $orderIds[] = (int) $row->id;

                    return $row;
                };

                // Alice bought in January and again in March: returning
                $order($alice, Status::ORDER_TYPE_PAYMENT, '2001-01-15 10:00:00', 1000);
                $order($alice, Status::ORDER_TYPE_PAYMENT, '2001-03-10 10:00:00', 500);
                // Bob's first purchase is in March, renewed in March: new
                $bobFirst = $order($bob, Status::ORDER_TYPE_SUBSCRIPTION, '2001-03-03 10:00:00', 2000);
                $order($bob, Status::ORDER_TYPE_RENEWAL, '2001-03-20 10:00:00', 2000, 0, (int) $bobFirst->id);
                // Carol subscribed in January; only renewals since: renewing
                $carolFirst = $order($carol, Status::ORDER_TYPE_SUBSCRIPTION, '2001-01-05 10:00:00', 1000);
                $order($carol, Status::ORDER_TYPE_RENEWAL, '2001-02-05 10:00:00', 1000, 0, (int) $carolFirst->id);
                $order($carol, Status::ORDER_TYPE_RENEWAL, '2001-03-05 10:00:00', 1000, 0, (int) $carolFirst->id);
                // Dave's first purchase in March, 1.00 of 3.00 refunded: new
                $order($dave, Status::ORDER_TYPE_PAYMENT, '2001-03-25 10:00:00', 300, 100);
                // Frank bought in the report currency in January, but in March only in another one: not in March's figures
                $order($base + 5, Status::ORDER_TYPE_PAYMENT, '2001-01-20 10:00:00', 1000);
                $order($base + 5, Status::ORDER_TYPE_PAYMENT, '2001-03-12 10:00:00', 400, 0, 0, 'XXX');
                // Erin, new in February (the previous period)
                $order($erin, Status::ORDER_TYPE_PAYMENT, '2001-02-10 10:00:00', 700);

                $report = CustomerReportService::make()->getOverview([
                    'startDate' => new DateTime('2001-03-01 00:00:00'), 'endDate' => new DateTime('2001-03-31 23:59:59'),
                    'comparePeriod' => [new DateTime('2001-02-01 00:00:00'), new DateTime('2001-02-28 23:59:59')],
                    'currency' => $fcCustomerCurrency, 'paymentStatus' => Status::getReportStatuses(), 'groupKey' => 'monthly',
                ]);

                $s = $report['summary'];
                FcTest::assertSame([4, 2, 1, 1], [$s['customers'], $s['new_customers'], $s['returning_customers'], $s['renewing_customers']],
                    'March: Bob and Dave new, Alice returning, Carol renewing');
                FcTest::assertSame([5700, 2200, 500, 3000], [$s['net'], $s['new_net'], $s['returning_net'], $s['renewal_net']],
                    'Revenue by order: new purchases 20.00 + 2.00 (after the refund), Alice\'s 5.00, renewals 20.00 + 10.00');
                FcTest::assertSame([25.0, 2175], [$s['repeat_rate'], $s['average_ltv']],
                    'Repeat: Alice alone has two purchases; lifetime value up to March: (15 + 40 + 30 + 2) / 4; '
                    . 'Frank, who bought in March only in another currency, is not among them');

                FcTest::assertSame([2, 1, 0, 1], [$report['previous']['customers'], $report['previous']['new_customers'],
                    $report['previous']['returning_customers'], $report['previous']['renewing_customers']], 'February: Erin new, Carol renewing');

                $march = array_values(array_filter($report['chart'], function ($point) {
                    return $point['group'] === '2001-03';
                }))[0];
                FcTest::assertSame([2, 1, 1, 2200, 500, 3000], [$march['new_customers'], $march['returning_customers'], $march['renewing_customers'],
                    $march['new_net'], $march['returning_net'], $march['renewal_net']], 'The chart\'s March point matches the summary');
            } finally {
                if ($owner && $orderIds) {
                    $db->table('fct_orders')->whereIn('id', $orderIds)->update(['customer_id' => $owner]);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
