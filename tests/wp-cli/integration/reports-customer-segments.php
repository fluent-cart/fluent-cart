<?php
/**
 * Customers report RFM segments, as of today, in the report currency: recency and money scored by the store's own
 * quintiles, frequency by fixed steps, each customer in one segment; the segment list for the CSV export.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCartPro\App\Modules\Reports\Services\CustomerInsightsService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcSegmentCurrency = 'XTS';

return [
    [
        'id'            => 'reports-customer-segments',
        'name'          => 'Customers RFM segments: one segment per customer by recency, frequency and money; segment export rows',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcSegmentCurrency) {
            // A Pro report: without Pro the route is gone and the free page shows a locked preview
            if (!class_exists(CustomerInsightsService::class)) {
                $route = FcTest::rest('GET', 'reports/customer-segments', ['params' => ['currency' => $fcSegmentCurrency]]);
                FcTest::assertSame(404, $route['status'], 'Pro absent: core no longer serves reports/customer-segments');

                return;
            }

            $db = App::db();
            $owner = null;
            $orderIds = [];
            try {
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcSegmentCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcSegmentCurrency);
                }
                $owner = (int) FcFixture::customer()->id;
                $base = 1580000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 10;
                $ago = function ($days) {
                    return gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
                };
                $orders = function ($customer, array $days, $cents) use ($fcSegmentCurrency, &$orderIds, $ago) {
                    foreach ($days as $day) {
                        $row = FcFixture::reportOrder([
                            'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcSegmentCurrency,
                            'customer_id' => $customer, 'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => $cents, 'tax_total' => 0,
                            'total_amount' => $cents, 'total_paid' => $cents, 'created_at' => $ago($day), 'updated_at' => $ago($day),
                        ]);
                        $orderIds[] = (int) $row->id;
                    }
                };

                // A: six orders, last 5 days ago, 60.00 — a champion
                $orders($base, [5, 40, 80, 120, 160, 200], 1000);
                // B: three orders, last 20 days ago, 30.00 — loyal
                $orders($base + 1, [20, 60, 100], 1000);
                // C: one order 2 days ago, 5.00 — new
                $orders($base + 2, [2], 500);
                // D: four orders, the last 400 days ago, 50.00 — at risk
                $orders($base + 3, [400, 450, 500, 550], 1250);
                // E: one order 500 days ago, 1.00 — hibernating
                $orders($base + 4, [500], 100);
                // F: one big order 3 days ago, 90.00 — new, not loyal: one order is no habit
                $orders($base + 5, [3], 9000);

                $service = CustomerInsightsService::make();
                $result = $service->getSegments(['currency' => $fcSegmentCurrency, 'paymentStatus' => Status::getReportStatuses()]);
                $segments = array_column($result['segments'], null, 'key');

                FcTest::assertSame(6, $result['customers'], 'Every customer with a paid order in the currency');
                // With F the quintiles move: B (three orders, 20 days ago) scores recency 3 and money 2 — promising
                FcTest::assertSame(
                    ['champions' => 1, 'loyal' => 0, 'new' => 2, 'promising' => 1, 'at_risk' => 1, 'cant_lose' => 0, 'hibernating' => 1],
                    array_map(function ($segment) {
                        return $segment['customers'];
                    }, $segments),
                    'A champion, C and F new (one order each, however big), B promising, D at risk, E hibernating'
                );
                FcTest::assertSame([6000, 25.4, 6.0], [$segments['champions']['net'], $segments['champions']['share'], $segments['champions']['average_orders']],
                    'Champions hold 60.00 of 236.00 (25.4%), six orders on average');

                // The export reuses the card's cut points and as-of time, so every page reads the same segments
                $rows = $service->getSegmentCustomers(['currency' => $fcSegmentCurrency, 'paymentStatus' => Status::getReportStatuses()], 'at_risk', 1, 100,
                    $result['cuts'], $result['as_of']);
                FcTest::assertSame([1, [$base + 3], [4], [5000]], [$rows['total'], array_column($rows['data'], 'customer_id'),
                    array_column($rows['data'], 'orders'), array_column($rows['data'], 'net')], 'The at-risk export lists D');
            } finally {
                if ($owner && $orderIds) {
                    $db->table('fct_orders')->whereIn('id', $orderIds)->update(['customer_id' => $owner]);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
