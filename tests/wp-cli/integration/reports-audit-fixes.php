<?php
/**
 * Fixes from a report audit against real data: duplicate address rows, license
 * statuses, stale header discounts, top sold amounts, meta end dates, quick
 * stats and the sales growth filter.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\DashBoardReportService;
use FluentCart\App\Services\Report\DefaultReportService;
use FluentCart\App\Services\Report\LicenseReportService;
use FluentCart\App\Services\Report\LocationReportService;
use FluentCart\App\Services\Report\ProductReportService;
use FluentCart\App\Services\Report\RevenueReportService;

// No store row uses the ISO test currency, so currency-scoped reports read only these fixtures.
$fcAuditCurrency = 'XTS';

$fcAuditGuard = function () use ($fcAuditCurrency) {
    $foreign = (int) App::db()->table('fct_orders')->where('currency', $fcAuditCurrency)->count();
    if ($foreign !== 0) {
        throw new RuntimeException('Refusing to seed: ' . $foreign . ' orders already use currency ' . $fcAuditCurrency);
    }
    FcFixture::customer();
};

$fcAuditOrder = function (array $attributes) use ($fcAuditCurrency) {
    $window = FcFixture::reportWindow();

    return FcFixture::reportOrder(array_merge([
        'status'         => Status::ORDER_COMPLETED,
        'payment_status' => Status::PAYMENT_PAID,
        'currency'       => $fcAuditCurrency,
        'type'           => Status::ORDER_TYPE_PAYMENT,
        'subtotal'       => 1000,
        'tax_total'      => 0,
        'total_amount'   => 1000,
        'total_paid'     => 1000,
        'total_refund'   => 0,
        'created_at'     => $window['start'],
        'updated_at'     => $window['start'],
    ], $attributes));
};

$fcAuditParams = function (array $extra = []) use ($fcAuditCurrency) {
    $window = FcFixture::reportWindow();

    return array_merge([
        'startDate'     => new DateTime($window['start']),
        'endDate'       => new DateTime($window['end']),
        'groupKey'      => 'default',
        'currency'      => $fcAuditCurrency,
        'paymentStatus' => Status::getReportStatuses(),
        'orderStatus'   => [Status::ORDER_ON_HOLD, Status::ORDER_FAILED],
        'orderTypes'    => null,
        'variationIds'  => [],
        'comparePeriod' => null,
    ], $extra);
};

return [
    [
        'id'            => 'reports-audit-one-address-per-order',
        'name'          => 'Country reports count an order once even when checkout retries left several billing rows',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcAuditGuard, $fcAuditOrder, $fcAuditParams) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcAuditGuard();
                $order = $fcAuditOrder([]);
                // Antarctica: no real order uses it, so the store-wide heat map value is this fixture alone.
                // The older rows say Bouvet Island; the latest row decides.
                foreach (['BV', 'BV', 'AQ'] as $country) {
                    FcFixture::reportOrderAddress($order->id, ['type' => 'billing', 'country' => $country]);
                }
                // No address at all: still one of the two orders
                $fcAuditOrder(['total_amount' => 500, 'total_paid' => 500]);

                $locations = LocationReportService::make()->getReport($fcAuditParams());
                $byCountry = [];
                foreach ($locations['rows'] as $row) {
                    $byCountry[$row['key']] = [(int) $row['orders'], round((float) $row['gross_sales'], 2)];
                }
                FcTest::assertSame(
                    [1, 10.0],
                    $byCountry['AQ'] ?? null,
                    'Locations by billing country count the order once, under its latest row'
                );

                FcTest::assertSame(
                    2,
                    (int) $locations['totals']['orders'],
                    'Orders by billing country add up to the two orders'
                );

                $heat = array_values(array_filter(DashBoardReportService::make()->getCountryHeatMap()['countryHeatMap'], function ($row) {
                    return $row['country_code'] === 'AQ';
                }));
                FcTest::assertSame(1, $heat ? $heat[0]['value'] : 0, 'The heat map counts one order, not three address rows');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-audit-license-statuses',
        'name'          => 'License summary counts inactive licenses as inactive and disabled ones separately',
        'kind'          => 'behavior',
        'known_failure' => false,
        'requires'      => 'pro',
        'run'           => function () {
            $licenseClass = 'FluentCartPro\\App\\Modules\\Licensing\\Models\\License';
            if (!class_exists($licenseClass)) {
                FcTest::fail('FluentCart Pro is expected active, but its licensing models are not loaded.');
                return;
            }
            $count = function ($status) {
                return (int) App::db()->table('fct_licenses')->where('status', $status)->count();
            };
            try {
                $product = FcDomainFixture::product(['payment_type' => 'onetime']);
                $order = FcDomainFixture::orderWithItem((int) $product['post']->ID, (int) $product['variation']->id, 1);
                // One disabled license, and one or two inactive ones so the two store-wide counts always differ.
                $statuses = ['disabled', 'inactive'];
                if ($count('inactive') === $count('disabled')) {
                    $statuses[] = 'inactive';
                }
                foreach ($statuses as $index => $status) {
                    $licenseClass::query()->create([
                        'status'           => $status,
                        'limit'            => 1,
                        'activation_count' => 0,
                        'license_key'      => 'AUDIT-' . strtoupper(substr(hash('sha256', FcFixture::identity()), 0, 12)) . '-' . $index,
                        'product_id'       => (int) $product['post']->ID,
                        'variation_id'     => (int) $product['variation']->id,
                        'order_id'         => (int) $order->id,
                        'customer_id'      => (int) $order->customer_id,
                    ]);
                }
                FcDomainFixture::captureLicensesForOrder((int) $order->id);

                $summary = (new LicenseReportService())->getSummary(null, null)['summaryData'];
                FcTest::assertSame(
                    [$count('inactive'), $count('disabled')],
                    [(int) $summary['totalInactiveLicense'], (int) $summary['totalDisabledLicense']],
                    'Inactive means status inactive; disabled has its own count'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-audit-stale-header-discount',
        'name'          => 'Product report and top sold count a header discount only as far as the order total applied it',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcAuditGuard, $fcAuditOrder, $fcAuditParams) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcAuditGuard();
                $productId = 1900000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000);
                // Stale: a manual discount equal to the subtotal, but the customer paid in full
                $stale = $fcAuditOrder(['subtotal' => 1099, 'manual_discount_total' => 1099, 'total_amount' => 1099, 'total_paid' => 1099]);
                // Upgrade: a 7.99 credit that the order total did apply
                $upgrade = $fcAuditOrder(['subtotal' => 1099, 'manual_discount_total' => 799, 'total_amount' => 300, 'total_paid' => 300]);
                foreach ([$stale, $upgrade] as $order) {
                    FcFixture::reportOrderItem($order->id, [
                        'post_id' => $productId, 'object_id' => $productId + 1,
                        'unit_price' => 1099, 'subtotal' => 1099, 'line_total' => 1099,
                    ]);
                }

                // getProductSales() amounts are in cents
                $sales = ProductReportService::make()->getProductSales($fcAuditParams());
                FcTest::assertSame(
                    13.99,
                    round((float) $sales['summary']['gross'] / 100, 2),
                    'Gross is 10.99 + 3.00'
                );

                $top = DefaultReportService::make()->fetchTopSoldProducts($fcAuditParams())['topSoldProducts'];
                $row = $top->firstWhere('product_id', $productId);
                FcTest::assertSame(13.99, $row ? $row['total_amount'] : null, 'Top sold products show what the lines charged, not their list value');
                $variantRows = [];
                foreach ($sales['products'] as $product) {
                    foreach ($product['variations'] as $line) {
                        if ((int) $line['variation_id'] === $productId + 1) {
                            $variantRows[] = $line;
                        }
                    }
                }
                FcTest::assertSame(13.99, $variantRows ? round((float) $variantRows[0]['gross'] / 100, 2) : null, 'Product report variants match');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-audit-discount-cap-cases',
        'name'          => 'The header discount cap holds with tax, shipping, coupons, fee lines and header-only fees',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcAuditGuard, $fcAuditOrder, $fcAuditParams) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcAuditGuard();
                $base = 1910000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 10;
                // [order attributes, item discount, expected line amount in cents]
                $cases = [
                    'exclusive tax and shipping, coupon' => [['subtotal' => 1000, 'coupon_discount_total' => 300, 'shipping_total' => 500,
                        'tax_total' => 70, 'tax_behavior' => 1, 'total_amount' => 1270, 'total_paid' => 1270], 0, 700],
                    'tax inclusive, manual discount' => [['subtotal' => 1000, 'manual_discount_total' => 200, 'tax_total' => 73,
                        'tax_behavior' => 2, 'total_amount' => 800, 'total_paid' => 800], 0, 800],
                    'coupon on the item plus a manual remainder' => [['subtotal' => 1000, 'coupon_discount_total' => 100, 'manual_discount_total' => 200,
                        'total_amount' => 700, 'total_paid' => 700], 100, 700],
                    'header-only fee, coupon' => [['subtotal' => 1000, 'coupon_discount_total' => 300, 'fee_total' => 200,
                        'total_amount' => 900, 'total_paid' => 900], 0, 700],
                    // A free or credited renewal: no discount recorded, but the order total is 0
                    'credited renewal, total 0' => [['type' => Status::ORDER_TYPE_RENEWAL, 'subtotal' => 1000,
                        'total_amount' => 0, 'total_paid' => 0], 0, 0],
                    // Marked paid, but no money came in: the lines earn only what was paid
                    'paid status, nothing collected' => [['subtotal' => 1000, 'total_amount' => 1000, 'total_paid' => 0], 0, 0],
                    'renewal, part paid' => [['type' => Status::ORDER_TYPE_RENEWAL, 'subtotal' => 1000,
                        'total_amount' => 1000, 'total_paid' => 600], 0, 600],
                ];
                $expected = [];
                $index = 0;
                foreach ($cases as $label => $case) {
                    $variationId = $base + $index++;
                    $order = $fcAuditOrder($case[0]);
                    FcFixture::reportOrderItem($order->id, [
                        'post_id' => $base, 'object_id' => $variationId, 'unit_price' => 1000, 'subtotal' => 1000,
                        'discount_total' => $case[1], 'line_total' => 1000 - $case[1],
                    ]);
                    $expected[$label] = [$variationId, $case[2]];
                }
                // A fee line next to the product: the fee is in the lines, not a hidden header amount
                $variationId = $base + $index;
                $order = $fcAuditOrder(['subtotal' => 1300, 'manual_discount_total' => 200, 'fee_total' => 300, 'total_amount' => 1100, 'total_paid' => 1100]);
                FcFixture::reportOrderItem($order->id, ['post_id' => $base, 'object_id' => $variationId, 'unit_price' => 1000, 'subtotal' => 1000, 'line_total' => 1000]);
                FcFixture::reportOrderItem($order->id, ['post_id' => 0, 'object_id' => 0, 'payment_type' => 'fee', 'unit_price' => 300, 'subtotal' => 300, 'line_total' => 300]);

                // Top sold products with the Products filter on one variation: that line's amount alone
                $actual = [];
                foreach ($expected as $label => $pair) {
                    $row = DefaultReportService::make()->fetchTopSoldProducts($fcAuditParams(['variationIds' => [$pair[0]]]))['topSoldProducts']->firstWhere('product_id', $base);
                    $actual[$label] = $row ? (int) round($row['total_amount'] * 100) : null;
                }
                FcTest::assertSame(
                    array_map(function ($pair) {
                        return $pair[1];
                    }, $expected),
                    $actual,
                    'Each line keeps exactly the discount its order total applied'
                );
                // The discount spreads over the product and fee lines by amount; the cap must still apply all of it:
                // product + fee = 13.00 - 2.00
                $split = ProductReportService::orderDiscountSplitQuery(
                    App::db()->table('fct_orders as o')->where('o.id', $order->id)
                )->first();
                FcTest::assertSame(1100, (int) $split->net, 'With a fee line, the full 2.00 manual discount still applies');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-audit-product-order-date',
        'name'          => 'The product report places an item by its order date, not the item row date',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcAuditGuard, $fcAuditOrder, $fcAuditParams) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcAuditGuard();
                $order = $fcAuditOrder([]);
                // Item rows can carry another date than their order (edited or imported orders)
                FcFixture::reportOrderItem($order->id, [
                    'post_id' => 1, 'object_id' => 1, 'unit_price' => 1000, 'subtotal' => 1000, 'line_total' => 1000,
                    'created_at' => '1999-01-01 00:00:00',
                ]);
                $summary = ProductReportService::make()->getProductSales($fcAuditParams())['summary'];
                FcTest::assertSame([1, 10.0], [(int) $summary['units'], round((float) $summary['gross'] / 100, 2)],
                    'An order in range counts its items, whatever date the item row holds');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-audit-dashboard-paid-matches-revenue',
        'name'          => 'Dashboard paid orders and paid order value equal the Revenue report',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcAuditGuard, $fcAuditOrder, $fcAuditParams, $fcAuditCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcAuditGuard();
                $fcAuditOrder(['total_paid' => 1000]);
                $fcAuditOrder(['payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED, 'total_paid' => 2000, 'total_refund' => 500]);
                $fcAuditOrder(['payment_status' => Status::PAYMENT_REFUNDED, 'total_paid' => 3000, 'total_refund' => 3000]);
                $fcAuditOrder(['payment_status' => Status::PAYMENT_PENDING, 'total_paid' => 0]);

                $window = FcFixture::reportWindow();
                $stats = DashBoardReportService::make(['currency' => $fcAuditCurrency])->getDashBoardStats(
                    new DateTime($window['start']), new DateTime($window['end']),
                    new DateTime('2000-01-01 00:00:00'), new DateTime('2000-01-02 23:59:59')
                )['dashBoardStats'];
                $revenue = RevenueReportService::make()->getRevenueData($fcAuditParams(['groupKey' => 'yearly']))['summary'];

                FcTest::assertSame(
                    [(int) $revenue['order_count'], (int) round($revenue['gross_sale'] * 100)],
                    [(int) $stats['paid_orders']['current_count'], (int) $stats['total_paid_amounts']['current_count']],
                    'Partly refunded and refunded orders count, as in Revenue'
                );
                FcTest::assertSame([4, 3, 6000], [(int) $stats['total_orders']['current_count'],
                    (int) $stats['paid_orders']['current_count'], (int) $stats['total_paid_amounts']['current_count']],
                    'All orders, paid orders and the money they took');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-audit-top-sold-product-filter',
        'name'          => 'Top sold products and the product report variants apply the Products filter, like the Sales chart',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcAuditGuard, $fcAuditOrder, $fcAuditParams) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcAuditGuard();
                $base = 1930000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 10;
                foreach ([[$base, $base + 1], [$base + 5, $base + 6]] as $pair) {
                    $order = $fcAuditOrder([]);
                    FcFixture::reportOrderItem($order->id, ['post_id' => $pair[0], 'object_id' => $pair[1], 'unit_price' => 1000, 'subtotal' => 1000, 'line_total' => 1000]);
                }
                $params = $fcAuditParams(['variationIds' => [$base + 1]]);

                $products = DefaultReportService::make()->fetchTopSoldProducts($params)['topSoldProducts'];
                $variants = [];
                foreach (ProductReportService::make()->getProductSales($params)['products'] as $product) {
                    foreach ($product['variations'] as $line) {
                        $variants[] = (int) $line['variation_id'];
                    }
                }
                FcTest::assertSame(
                    [[$base], [$base + 1]],
                    [array_values(array_map('intval', $products->pluck('product_id')->all())), $variants],
                    'Only the selected variation and its product are listed'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-audit-meta-end-day',
        'name'          => 'Report meta counts orders on the last day when the end date has no time',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcAuditGuard, $fcAuditOrder, $fcAuditCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcAuditGuard();
                $day = substr(FcFixture::reportWindow()['start'], 0, 10);
                $fcAuditOrder(['created_at' => $day . ' 15:00:00']);

                $codes = array_column(ReportCurrency::orderCurrencies($day, $day), 'order_count', 'currency');
                FcTest::assertSame(1, isset($codes[$fcAuditCurrency]) ? $codes[$fcAuditCurrency] : 0, 'An order at 15:00 on the end day counts');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-audit-quick-order-stats',
        'name'          => 'Quick order stats count paid orders by order payment status',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcAuditGuard, $fcAuditOrder, $fcAuditCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcAuditGuard();
                $window = FcFixture::reportWindow();
                $paid = $fcAuditOrder(['total_amount' => 2500, 'total_paid' => 2500]);
                FcFixture::reportOrderItem($paid->id, ['unit_price' => 2000, 'subtotal' => 2000, 'line_total' => 2000]);
                FcFixture::reportOrderItem($paid->id, ['unit_price' => 500, 'subtotal' => 500, 'line_total' => 500]);
                $unpaid = $fcAuditOrder(['payment_status' => 'pending', 'status' => 'pending', 'total_paid' => 0]);
                FcFixture::reportOrderItem($unpaid->id, ['unit_price' => 1000, 'subtotal' => 1000, 'line_total' => 1000]);

                // The Dashboard stat cards, which replaced the quick order stats
                $stats = DashBoardReportService::make(['currency' => $fcAuditCurrency])->getDashBoardStats(
                    new DateTime($window['start']), new DateTime($window['end']),
                    new DateTime('2000-01-01 00:00:00'), new DateTime('2000-01-02 23:59:59')
                )['dashBoardStats'];
                FcTest::assertSame(
                    ['integer', 'integer', 'integer', 'integer'],
                    array_map('gettype', array_column($stats, 'compare_count')),
                    'Compare values are integers too'
                );
                FcTest::assertSame(
                    [2, 1, 2, 2500],
                    [$stats['total_orders']['current_count'], $stats['paid_orders']['current_count'], $stats['total_paid_order_items']['current_count'], $stats['total_paid_amounts']['current_count']],
                    'Two orders, one paid for 25.00 with two items (the unpaid order\'s item does not count), all as integers'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-audit-sales-growth-paid-only',
        'name'          => 'Sales growth counts only orders in the report payment statuses, like the sales report',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC', // fixtures are UTC days; reports-site-timezone covers other zones
        'run'           => function () use ($fcAuditGuard, $fcAuditOrder, $fcAuditCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcAuditGuard();
                $window = FcFixture::reportWindow();
                $fcAuditOrder([]);
                $fcAuditOrder(['payment_status' => 'pending', 'status' => Status::ORDER_PROCESSING, 'total_paid' => 0]);

                $result = FcTest::rest('GET', 'reports/sales-growth-chart', ['params' => [
                    'startDate' => $window['start'],
                    'endDate'   => $window['end'],
                    'currency'  => $fcAuditCurrency,
                    'groupKey'  => 'monthly',
                ]]);
                FcTest::assertHealthy($result, 'GET /reports/sales-growth-chart');
                $orders = array_sum(array_column((array) $result['data']['salesGrowthChart'], 'orders'));
                FcTest::assertSame(1, $orders, 'The unpaid order is not counted');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
