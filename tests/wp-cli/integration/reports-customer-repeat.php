<?php
/**
 * Customers report repeat behavior: purchases per customer for the customers who paid in the range (with and
 * without renewals), and time to the second purchase for customers gained in the 12 months ending with the range.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCartPro\App\Modules\Reports\Services\CustomerInsightsService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcRepeatCurrency = 'XTS';

return [
    [
        'id'            => 'reports-customer-repeat',
        'name'          => 'Customers repeat behavior: purchases per customer and time to the second purchase',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcRepeatCurrency) {
            // A Pro report: without Pro the route is gone and the free page shows a locked preview
            if (!class_exists(CustomerInsightsService::class)) {
                $route = FcTest::rest('GET', 'reports/customer-repeat', ['params' => ['currency' => $fcRepeatCurrency]]);
                FcTest::assertSame(404, $route['status'], 'Pro absent: core no longer serves reports/customer-repeat');

                return;
            }

            $db = App::db();
            $owner = null;
            $orderIds = [];
            try {
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcRepeatCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcRepeatCurrency);
                }
                $owner = (int) FcFixture::customer()->id;
                $base = 1590000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 10;
                [$alice, $bob, $carol, $dave] = [$base, $base + 1, $base + 2, $base + 3];

                $order = function ($customer, $type, $createdAt, $cents, $parent = 0, $paid = null) use ($fcRepeatCurrency, &$orderIds) {
                    $row = FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcRepeatCurrency,
                        'customer_id' => $customer, 'type' => $type, 'parent_id' => $parent ?: null, 'subtotal' => $cents, 'tax_total' => 0,
                        'total_amount' => $cents, 'total_paid' => $paid ?? $cents, 'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ]);
                    $orderIds[] = (int) $row->id;

                    return $row;
                };

                // Alice: first purchase 10 Jan, second 15 Feb (36 days), a renewal in March
                $first = $order($alice, Status::ORDER_TYPE_SUBSCRIPTION, '2001-01-10 10:00:00', 1000);
                $order($alice, Status::ORDER_TYPE_PAYMENT, '2001-02-15 10:00:00', 500);
                $order($alice, Status::ORDER_TYPE_RENEWAL, '2001-03-20 10:00:00', 1000, (int) $first->id);
                // Bob: one purchase
                $order($bob, Status::ORDER_TYPE_PAYMENT, '2001-01-20 10:00:00', 2000);
                // Carol: 5 Feb, then 25 Feb (20 days)
                $order($carol, Status::ORDER_TYPE_PAYMENT, '2001-02-05 10:00:00', 400);
                $order($carol, Status::ORDER_TYPE_PAYMENT, '2001-02-25 10:00:00', 100);
                // Erin: a free trial in December (nothing paid), then a paid renewal in March: renewals only
                $trial = $order($base + 4, Status::ORDER_TYPE_SUBSCRIPTION, '2000-12-01 10:00:00', 1000, 0, 0);
                $order($base + 4, Status::ORDER_TYPE_RENEWAL, '2001-03-01 10:00:00', 1000, (int) $trial->id);
                // Dave: one purchase
                $order($dave, Status::ORDER_TYPE_PAYMENT, '2001-03-03 10:00:00', 600);

                $repeat = CustomerInsightsService::make()->getRepeat([
                    'startDate' => new DateTime('2001-01-01 00:00:00'), 'endDate' => new DateTime('2001-03-31 23:59:59'),
                    'currency' => $fcRepeatCurrency, 'paymentStatus' => Status::getReportStatuses(),
                ]);

                $counts = function (array $buckets) {
                    return array_column($buckets, 'customers', 'bucket');
                };
                FcTest::assertSame(['0' => 1, '1' => 2, '2' => 2, '3' => 0, '4' => 0, '5+' => 0], $counts($repeat['purchases']),
                    'Purchases: Erin only renewed, Bob and Dave once, Alice and Carol twice');
                FcTest::assertSame(['1' => 3, '2' => 1, '3' => 1, '4' => 0, '5+' => 0], $counts($repeat['orders']),
                    'With renewals: Erin\'s renewal makes her one, Alice\'s makes her three');

                $second = $repeat['second_purchase'];
                FcTest::assertSame([4, 2, 50.0, 28], [$second['customers'], $second['repeaters'], $second['repeat_rate'], $second['median_days']],
                    'Of the four gained, Alice (36 days) and Carol (20 days) bought again; the median is 28 days');
                FcTest::assertSame(['0-7' => 0, '8-30' => 1, '31-90' => 1, '91-180' => 0, '181-365' => 0, '365+' => 0],
                    $counts($second['buckets']), 'Carol within a month, Alice within three');
            } finally {
                if ($owner && $orderIds) {
                    $db->table('fct_orders')->whereIn('id', $orderIds)->update(['customer_id' => $owner]);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
