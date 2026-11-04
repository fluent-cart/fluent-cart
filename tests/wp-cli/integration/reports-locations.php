<?php
/**
 * Locations report: the location chain (own address, a renewal's parent order, the customer record, Unknown),
 * the drill-down, the outsized-state note, and the two totals rules: Σ rows = Revenue without a Products filter,
 * Σ rows money = revenue by price ID with one.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\LocationReportService;
use FluentCart\App\Services\Report\ProductReportService;
use FluentCart\App\Services\Report\RevenueReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcLocationCurrency = 'XTS';

return [
    [
        'id'            => 'reports-locations',
        'name'          => 'Locations report places each order and adds up to Revenue, or to revenue by price ID under a Products filter',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC', // fixtures are UTC days; reports-site-timezone covers other zones
        'run'           => function () use ($fcLocationCurrency) {
            $db = App::db();
            $customerId = 0;
            $customerCountry = null;
            $guestId = 0;
            try {
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcLocationCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' orders already use currency ' . $fcLocationCurrency);
                }
                $customer = FcFixture::customer();
                $customerId = (int) $customer->id;
                $customerCountry = $db->table('fct_customers')->where('id', $customerId)->value('country');
                $db->table('fct_customers')->where('id', $customerId)->update(['country' => 'DE']);

                $window = FcFixture::reportWindow();
                $at = gmdate('Y-m-d H:i:s', strtotime($window['start']) + HOUR_IN_SECONDS);
                $variation = 1950000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 2;
                $order = function (array $attributes, array $address = null, array $lines = []) use ($fcLocationCurrency, $at) {
                    $row = FcFixture::reportOrder(array_merge([
                        'status'         => Status::ORDER_COMPLETED,
                        'payment_status' => Status::PAYMENT_PAID,
                        'currency'       => $fcLocationCurrency,
                        'type'           => Status::ORDER_TYPE_PAYMENT,
                        'tax_total'      => 0,
                        'total_refund'   => 0,
                        'created_at'     => $at,
                        'updated_at'     => $at,
                    ], $attributes));
                    if ($address) {
                        FcFixture::reportOrderAddress((int) $row->id, $address);
                    }
                    foreach ($lines as $line) {
                        FcFixture::reportOrderItem((int) $row->id, [
                            'post_id' => $line[0], 'object_id' => $line[0], 'unit_price' => $line[1], 'subtotal' => $line[1], 'line_total' => $line[1],
                        ]);
                    }

                    return $row;
                };
                $money = function ($cents) {
                    return ['subtotal' => $cents, 'total_amount' => $cents, 'total_paid' => $cents];
                };

                $own = $order($money(1000), ['country' => 'US', 'state' => 'CA', 'city' => 'Los Angeles'], [[$variation, 1000]]);
                // No address: a renewal takes its parent order's, another order the customer record's country
                $order(array_merge($money(500), ['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $own->id]));
                $order(array_merge($money(2000), ['total_refund' => 500, 'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED]), null,
                    [[$variation, 1000], [$variation + 1, 1000]]);
                $order(array_merge($money(700)), ['country' => 'US', 'state' => 'NY', 'city' => 'New York']);
                // A renewal with its own country but no state keeps its own address, not its parent's state
                $partial = $order(array_merge($money(400), ['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $own->id]));
                FcFixture::reportOrderAddress((int) $partial->id, ['country' => 'US', 'state' => null, 'city' => null]);
                // A guest with no address stays Unknown
                $guestId = (int) $order(array_merge($money(300), ['customer_id' => null]))->id;
                // France: one state with three of four orders reads as a likely checkout default
                foreach (['IDF', 'IDF', 'IDF', 'PAC'] as $state) {
                    $order($money(100), ['country' => 'FR', 'state' => $state, 'city' => $state === 'IDF' ? 'Paris' : 'Nice']);
                }

                $params = ['startDate' => new DateTime($window['start']), 'endDate' => new DateTime($window['end']),
                    'currency' => $fcLocationCurrency, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [], 'groupKey' => 'yearly'];
                $service = LocationReportService::make();
                $report = $service->getReport($params + ['level' => 'country']);
                $rows = array_column($report['rows'], null, 'key');
                $pick = function ($row) {
                    return [$row['orders'] ?? null, $row['gross_sales'] ?? null, $row['refunds'] ?? null];
                };

                FcTest::assertSame([4, 26.0, 0.0], $pick($rows['US'] ?? []), 'US: its own two orders and two renewals');
                FcTest::assertSame([1, 20.0, 5.0], $pick($rows['DE'] ?? []), 'DE: the order with no address, through the customer record');
                FcTest::assertSame([1, 3.0, 0.0], $pick($rows[LocationReportService::UNKNOWN] ?? []), 'Unknown: the guest with no address');
                FcTest::assertSame(['parent_order' => 1, 'customer_record' => 1], $report['sources'], 'How many orders were located through a fallback');

                $revenue = RevenueReportService::make()->getRevenueData($params)['summary'];
                FcTest::assertSame(
                    [(int) $revenue['order_count'], round((float) $revenue['gross_sale'], 2), round((float) $revenue['total_refunded_amount'], 2), round((float) $revenue['net_revenue'], 2)],
                    [$report['totals']['orders'], $report['totals']['gross_sales'], $report['totals']['refunds'], $report['totals']['net_sales']],
                    'Σ rows = Revenue without a Products filter'
                );

                // States and cities are a Pro report
                if (class_exists(\FluentCartPro\App\Modules\Reports\Services\LocationInsightsService::class)) {
                    $pro = \FluentCartPro\App\Modules\Reports\Services\LocationInsightsService::make();
                    $states = $pro->getDetails($params + ['level' => 'state', 'country' => 'US']);
                    FcTest::assertSame(['CA' => 2, 'NY' => 1, LocationReportService::UNKNOWN => 1], array_column($states['rows'], 'orders', 'key'),
                        'US states: the renewal with no address under its parent\'s state, the one with its own country under Unknown');
                    FcTest::assertSame(null, $states['outsized'], 'No note when no state stands out');
                    $cities = $pro->getDetails($params + ['level' => 'city', 'country' => 'US', 'state' => 'CA']);
                    FcTest::assertSame(['Los Angeles' => 2], array_column($cities['rows'], 'orders', 'key'), 'California cities');
                    FcTest::assertSame('IDF', $pro->getDetails($params + ['level' => 'state', 'country' => 'FR'])['outsized'], 'A state with most of the orders gets the note');
                }

                // The Order Type filter reaches the route, as on Revenue
                $route = FcTest::rest('GET', 'reports/locations', ['params' => [
                    'startDate' => $window['start'], 'endDate' => $window['end'], 'currency' => $fcLocationCurrency,
                    'paymentStatus' => Status::getReportStatuses(), 'orderTypes' => [Status::ORDER_TYPE_RENEWAL], 'level' => 'country',
                ]]);
                FcTest::assertSame([200, 2], [$route['status'], (int) ($route['data']['totals']['orders'] ?? -1)], 'GET /reports/locations applies the Order Type filter');

                $filtered = array_merge($params, ['variationIds' => [$variation]]);
                $byProduct = $service->getReport($filtered + ['level' => 'country']);
                $price = ProductReportService::make()->getVariationRevenue($filtered)['totals'];
                FcTest::assertSame(
                    [round($price['gross'] / 100, 2), round($price['refunds'] / 100, 2), round($price['net'] / 100, 2)],
                    [$byProduct['totals']['gross_sales'], $byProduct['totals']['refunds'], $byProduct['totals']['net_sales']],
                    'Σ rows money = revenue by price ID under a Products filter'
                );
                FcTest::assertSame([10.0, 10.0], [array_column($byProduct['rows'], 'gross_sales', 'key')['US'] ?? null,
                    array_column($byProduct['rows'], 'gross_sales', 'key')['DE'] ?? null], 'The picked variation\'s share of each order');
            } finally {
                // The fixture cleanup deletes only orders that still belong to the fixture customer
                if ($guestId && $customerId) {
                    $db->table('fct_orders')->where('id', $guestId)->update(['customer_id' => $customerId]);
                }
                if ($customerId) {
                    $db->table('fct_customers')->where('id', $customerId)->update(['country' => $customerCountry]);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
