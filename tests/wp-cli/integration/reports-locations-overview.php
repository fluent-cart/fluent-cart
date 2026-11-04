<?php
/**
 * Locations overview: cards (net, international share against the store country, countries with sales, top country,
 * international new customers) with the previous period, each location's net change, the lifetime value and repeat
 * rate of the new customers it won, and the biggest movers, including a location with no sales this period.
 */

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\LocationReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcLocationOverviewCurrency = 'XTS';

return [
    [
        'id'            => 'reports-locations-overview',
        'name'          => 'Locations overview: cards vs the store country, net change, new customers\' lifetime value, movers',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcLocationOverviewCurrency) {
            $db = App::db();
            $owner = null;
            $orderIds = [];
            $storeCountry = function ($settings) {
                $settings = is_array($settings) ? $settings : [];
                $settings['store_country'] = 'US';

                return $settings;
            };
            add_filter('option_fluent_cart_store_settings', $storeCountry);
            add_filter('default_option_fluent_cart_store_settings', $storeCountry);
            StoreSettings::clearCache();
            try {
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcLocationOverviewCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcLocationOverviewCurrency);
                }
                $owner = (int) FcFixture::customer()->id;
                $base = 1570000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 10;

                $order = function ($customer, $createdAt, $paid, $country, $state = null) use ($fcLocationOverviewCurrency, &$orderIds) {
                    $row = FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcLocationOverviewCurrency,
                        'customer_id' => $customer, 'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => $paid, 'tax_total' => 0,
                        'total_amount' => $paid, 'total_paid' => $paid, 'total_refund' => 0, 'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ]);
                    $orderIds[] = (int) $row->id;
                    FcFixture::reportOrderAddress((int) $row->id, ['country' => $country, 'state' => $state ?: strtoupper($country) . '-1', 'city' => $country . ' City']);

                    return $row;
                };

                // March: Alice (the store country, typed in lower case) is new and buys again in April
                $order($base, '2001-03-02 10:00:00', 1000, 'us');
                $order($base, '2001-04-02 10:00:00', 600, 'US');
                // Bob is new in Germany; Carol bought there in January, so she returns
                $order($base + 1, '2001-03-03 10:00:00', 2000, 'DE');
                $order($base + 2, '2001-01-10 10:00:00', 300, 'DE');
                $order($base + 2, '2001-03-04 10:00:00', 500, 'DE');
                // Gina is new in the same German state, typed in lower case
                $order($base + 6, '2001-03-06 10:00:00', 300, 'DE', 'de-1');
                // Henry had a free order in January; his first paid purchase in March makes him new, as on the Customers report
                $order($base + 7, '2001-01-12 10:00:00', 0, 'DE');
                $order($base + 7, '2001-03-07 10:00:00', 500, 'DE');
                // Dave is new in France
                $order($base + 3, '2001-03-05 10:00:00', 400, 'FR');
                // February: Germany and the UK, which sells nothing in March
                $order($base + 4, '2001-02-10 10:00:00', 1000, 'DE');
                $order($base + 5, '2001-02-11 10:00:00', 700, 'GB');

                $params = [
                    'startDate' => new DateTime('2001-03-01 00:00:00'), 'endDate' => new DateTime('2001-03-31 23:59:59'),
                    'comparePeriod' => [new DateTime('2001-02-01 00:00:00'), new DateTime('2001-02-28 23:59:59')],
                    'currency' => $fcLocationOverviewCurrency, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [],
                    'level' => 'country', 'country' => '', 'state' => '', 'address_type' => 'billing',
                ];
                $report = LocationReportService::make()->getReport($params);

                $s = $report['summary'];
                FcTest::assertSame(['US', 47.0, 6, 37.0, 78.7, 3, 'DE', 70.2, 4], [$s['store_country'], $s['net_sales'], $s['orders'],
                    $s['international_net'], $s['international_share'], $s['countries'], $s['top_country'], $s['top_share'],
                    $s['international_new_customers']], 'March: 37.00 of 47.00 from outside the US; Germany leads; Bob, Gina, Henry and Dave are new abroad');
                $p = $report['previous'];
                FcTest::assertSame([17.0, 100.0, 2], [$p['net_sales'], $p['international_share'], $p['countries']], 'February: all abroad');

                $rows = array_column($report['rows'], null, 'key');
                $pick = function ($row) {
                    return [$row['net_sales'], $row['previous_net'], $row['net_change']];
                };
                FcTest::assertSame([33.0, 10.0, 230.0], $pick($rows['DE']), 'Germany: up 230%');
                FcTest::assertSame([10.0, 0.0, null], $pick($rows['US']), 'US: no February sales');
                FcTest::assertSame([false, null], [isset($rows['DE']['average_ltv']), $report['movers']],
                    'Lifetime value and the biggest movers are Pro reports: the free response leaves them out');
                $free = LocationReportService::make()->getReport(array_merge($params, ['level' => 'state', 'country' => 'DE']));
                FcTest::assertSame('country', $free['level'], 'Free stores read countries only');

                if (!class_exists(\FluentCartPro\App\Modules\Reports\Services\LocationInsightsService::class)) {
                    return;
                }
                $pro = \FluentCartPro\App\Modules\Reports\Services\LocationInsightsService::make();
                $full = $pro->getDetails($params);
                $fullRows = array_column($full['rows'], null, 'key');
                $ltv = function ($row) {
                    return [$row['average_ltv'], $row['repeat_rate']];
                };
                FcTest::assertSame([9.33, 0.0], $ltv($fullRows['DE']), 'Pro: Bob, Gina and Henry have spent 28.00');
                FcTest::assertSame([16.0, 100.0], $ltv($fullRows['US']), 'Pro: Alice has spent 16.00 and bought again');

                $movers = $full['movers'];
                FcTest::assertSame(['DE', 'US', 'FR'], array_column($movers['up'], 'key'), 'Biggest gains first');
                FcTest::assertSame([['GB', 0.0, 7.0]], array_map(function ($row) {
                    return [$row['key'], $row['net_sales'], $row['previous_net']];
                }, $movers['down']), 'The UK sold nothing in March: still a mover');

                $state = $pro->getDetails(array_merge($params, ['level' => 'state', 'country' => 'DE', 'with_summary' => true]));
                FcTest::assertSame([1, 33.0, 10.0, 230.0, 9.33], [count($state['rows']), $state['rows'][0]['net_sales'], $state['rows'][0]['previous_net'],
                    $state['rows'][0]['net_change'], $state['rows'][0]['average_ltv']],
                    'Drilled into Germany: one state whatever its letter case, compared with its own February, with all three new customers');
                FcTest::assertSame(47.0, $state['summary']['net_sales'], 'The cards stay store-wide when drilled in');
                FcTest::assertSame(null, $pro->getDetails(array_merge($params, ['level' => 'state', 'country' => 'DE']))['summary'],
                    'A plain drill-down skips the store-wide cards');
            } finally {
                remove_filter('option_fluent_cart_store_settings', $storeCountry);
                remove_filter('default_option_fluent_cart_store_settings', $storeCountry);
                StoreSettings::clearCache();
                if ($owner && $orderIds) {
                    $db->table('fct_orders')->whereIn('id', $orderIds)->update(['customer_id' => $owner]);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
