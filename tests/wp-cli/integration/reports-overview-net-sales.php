<?php
/**
 * The Overview's 12-month chart: the overview endpoint returns 24 store months of net sales counted as the Revenue
 * report counts them (shipping kept out), whatever date range the request or the license would set.
 */

use FluentCart\App\Helpers\Status;

// No store row uses the ISO test currency, so the report scoped to it reads only these fixtures.
$fcOverviewSalesCurrency = 'XTS';

return [
    [
        'id'            => 'reports-overview-net-sales',
        'name'          => 'Overview endpoint returns 24 months of net sales that match the Revenue report',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcOverviewSalesCurrency) {
            try {
                $foreign = (int) \FluentCart\App\App::db()->table('fct_orders')->where('currency', $fcOverviewSalesCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcOverviewSalesCurrency);
                }
                FcFixture::customer();
                $month = new \DateTime('first day of last month', new \DateTimeZone('UTC'));
                $at = $month->format('Y-m-10 10:00:00');
                // 10.00 of products and 2.00 of shipping
                FcFixture::reportOrder([
                    'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcOverviewSalesCurrency,
                    'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => 1000, 'shipping_total' => 200, 'tax_total' => 0,
                    'total_amount' => 1200, 'total_paid' => 1200, 'total_refund' => 0, 'created_at' => $at, 'updated_at' => $at,
                ]);

                $response = FcTest::rest('GET', 'reports/overview', ['params' => ['currency' => $fcOverviewSalesCurrency]]);
                $data = $response['data']['data'] ?? [];
                $months = array_column($data['net_sales_months'] ?? [], 'net_sales', 'group');

                FcTest::assertSame([200, 24], [$response['status'], count($months)], 'Twenty-four months, this one included');
                FcTest::assertSame(10.0, (float) ($months[$month->format('Y-m')] ?? -1), 'Net sales leave the shipping out, as on the Revenue report');
                FcTest::assertSame(1200, (int) ($data['net_revenue'][$month->format('Y-m')]['current'] ?? -1), 'The older net revenue series is unchanged');

                // All currencies: a second-currency order, converted, counts as on the Revenue report and the Net sales card
                $foreignCurrency = \FluentCart\App\Modules\MultiCurrency\Services\BaseCurrency::get() === 'EUR' ? 'GBP' : 'EUR';
                FcFixture::reportOrder([
                    'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $foreignCurrency, 'rate' => 2,
                    'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => 800, 'tax_total' => 0, 'total_amount' => 800, 'total_paid' => 800,
                    'total_refund' => 0, 'created_at' => $at, 'updated_at' => $at,
                ]);
                $all = \FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency::ALL;
                $converted = FcTest::rest('GET', 'reports/overview', ['params' => ['currency' => $all]]);
                $allMonths = array_column($converted['data']['data']['net_sales_months'] ?? [], 'net_sales', 'group');
                $site = wp_timezone();
                $revenue = \FluentCart\App\Services\Report\RevenueReportService::make()->getRevenueData([
                    'startDate' => \FluentCart\App\Services\Report\ReportHelper::toGmt((new \FluentCart\App\Services\DateTime\DateTime($month->format('Y-m-01 00:00:00'), $site))),
                    'endDate' => \FluentCart\App\Services\Report\ReportHelper::toGmt((new \FluentCart\App\Services\DateTime\DateTime($month->format('Y-m-t 23:59:59'), $site))),
                    'currency' => $all, 'paymentStatus' => Status::getReportStatuses(), 'groupKey' => 'monthly',
                ])['summary']['net_sales'];
                FcTest::assertSame(round((float) $revenue, 2), (float) ($allMonths[$month->format('Y-m')] ?? -1),
                    'All currencies: the month equals the Revenue report\'s converted net sales');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
