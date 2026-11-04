<?php
/**
 * The MCP sales tools give the admin reports' figures: average order value is net sales per order (the Orders
 * report's Average Order Value (Net)), and gross revenue is the Revenue report's gross sales, in the sales report,
 * query-orders and the sales trend alike.
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MCP\Tools\ReportTools;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcMcpCurrency = 'XTS';

return [
    [
        'id'            => 'reports-mcp-sales-figures',
        'name'          => 'MCP average order value is net sales per order and gross revenue is gross sales, in every sales tool',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcMcpCurrency) {
            try {
                $foreign = (int) \FluentCart\App\App::db()->table('fct_orders')->where('currency', $fcMcpCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' orders already use currency ' . $fcMcpCurrency);
                }
                FcFixture::customer();
                $order = function (array $attributes) use ($fcMcpCurrency) {
                    return FcFixture::reportOrder(array_merge([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcMcpCurrency,
                        'type' => Status::ORDER_TYPE_PAYMENT, 'total_refund' => 0, 'tax_total' => 0, 'shipping_tax' => 0,
                        'shipping_total' => 0, 'fee_total' => 0, 'coupon_discount_total' => 0, 'manual_discount_total' => 0,
                        'created_at' => '2025-06-03 10:00:00', 'updated_at' => '2025-06-03 10:00:00',
                    ], $attributes));
                };
                // 100.00 goods - 10.00 coupon + 10.00 shipping + 9.00 tax = 109.00: net sales 90.00, gross sales 100.00
                $order(['subtotal' => 10000, 'coupon_discount_total' => 1000, 'shipping_total' => 1000, 'tax_total' => 900,
                    'total_amount' => 10900, 'total_paid' => 10900]);
                // 50.00, refunded in full: net sales 0, gross sales 50.00
                $order(['subtotal' => 5000, 'total_amount' => 5000, 'total_paid' => 5000, 'total_refund' => 5000,
                    'payment_status' => Status::PAYMENT_REFUNDED]);

                $range = ['currency' => $fcMcpCurrency, 'start_date' => '2025-06-01', 'end_date' => '2025-06-30', 'compare' => false];
                $metrics = ReportTools::getSalesReport($range)['data']['metrics'];
                $query = ReportTools::queryOrders($range + ['metrics' => ['order_count', 'gross_revenue', 'aov']])['data']['rows'][0] ?? [];
                $trend = ReportTools::getSalesTrend($range + ['interval' => 'month'])['data']['trend'][0] ?? [];

                FcTest::assertSame(
                    [
                        'sales_aov' => 4500, 'sales_gross' => 15000,
                        'query_aov' => 4500, 'query_gross' => 15000,
                        'trend_gross' => 15000,
                    ],
                    [
                        'sales_aov' => $metrics['aov']['amount_cents'] ?? -1, 'sales_gross' => $metrics['gross_revenue']['amount_cents'] ?? -1,
                        'query_aov' => (int) round(((float) ($query['aov'] ?? -1)) * 100), 'query_gross' => (int) round(((float) ($query['gross_revenue'] ?? -1)) * 100),
                        'trend_gross' => (int) round(((float) ($trend['gross'] ?? -1)) * 100),
                    ],
                    'AOV = net sales 90.00 / 2 orders; gross revenue = gross sales 150.00 (no tax, shipping, refunds taken off)'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
