<?php
/**
 * Revenue page sales breakdown, in Shopify's order: Gross sales − Discounts − Refunds = Net sales;
 * + Shipping + Fees + Taxes = Total sales (what customers paid, less refunds). Every line adds up to the next,
 * the old Revenue figure stays Net sales + Shipping + Fees, and net sales split by order type adds up to Net sales.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\RevenueReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcBreakdownCurrency = 'XTS';

return [
    [
        'id'            => 'reports-revenue-breakdown',
        'name'          => 'Revenue breakdown runs gross sales to total sales and splits net sales by order type',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC', // fixtures are UTC days; reports-site-timezone covers other zones
        'run'           => function () use ($fcBreakdownCurrency) {
            $metaOrders = [];
            try {
                $foreign = (int) App::db()->table('fct_orders')->where('currency', $fcBreakdownCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' orders already use currency ' . $fcBreakdownCurrency);
                }
                FcFixture::customer();
                $order = function (array $attributes) use ($fcBreakdownCurrency) {
                    return FcFixture::reportOrder(array_merge([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcBreakdownCurrency,
                        'type' => Status::ORDER_TYPE_PAYMENT, 'total_refund' => 0, 'tax_total' => 0, 'shipping_tax' => 0,
                        'shipping_total' => 0, 'fee_total' => 0, 'coupon_discount_total' => 0, 'manual_discount_total' => 0,
                        'created_at' => '2025-06-03 10:00:00', 'updated_at' => '2025-06-03 10:00:00',
                    ], $attributes));
                };
                // Exclusive tax: 100.00 goods − 10.00 coupon + 5.00 shipping + 9.00 tax = 104.00
                $order(['subtotal' => 10000, 'coupon_discount_total' => 1000, 'shipping_total' => 500, 'tax_total' => 900,
                    'total_amount' => 10400, 'total_paid' => 10400]);
                // Inclusive tax: 50.00 holds 5.00 tax
                $order(['subtotal' => 5000, 'tax_total' => 500, 'total_amount' => 5000, 'total_paid' => 5000]);
                // Renewal: 20.00 − 2.00 manual discount + 1.00 fee = 19.00, half refunded
                $order(['type' => Status::ORDER_TYPE_RENEWAL, 'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED,
                    'subtotal' => 2000, 'manual_discount_total' => 200, 'fee_total' => 100, 'total_amount' => 1900,
                    'total_paid' => 1900, 'total_refund' => 950]);

                // Exclusive tax, fully refunded: 100.00 goods + 10.00 tax; the refunded tax must not count as product sales
                $order(['subtotal' => 10000, 'tax_total' => 1000, 'total_amount' => 11000, 'total_paid' => 11000,
                    'total_refund' => 11000, 'payment_status' => Status::PAYMENT_REFUNDED]);

                // Goods 100.00 + shipping 10.00, fully refunded: net sales is 0, not -10 (refunded shipping leaves Shipping)
                $order(['subtotal' => 10000, 'shipping_total' => 1000, 'total_amount' => 11000, 'total_paid' => 11000,
                    'total_refund' => 11000, 'payment_status' => Status::PAYMENT_REFUNDED]);

                // Shipping-only refund, recorded by the store: product sales keep their 100.00
                $shippingOnly = $order(['subtotal' => 10000, 'shipping_total' => 1000, 'total_amount' => 11000, 'total_paid' => 11000,
                    'total_refund' => 1000, 'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED]);
                \FluentCart\App\Models\OrderMeta::query()->create(['order_id' => (int) $shippingOnly->id, 'meta_key' => 'shipping_refunded_amount', 'meta_value' => 1000]);
                $metaOrders[] = (int) $shippingOnly->id;
                // Taxed goods refunded with their tax, shipping kept: net sales is 0, the tax goes back in full
                $order(['subtotal' => 10000, 'tax_total' => 1000, 'shipping_total' => 1000, 'total_amount' => 12000, 'total_paid' => 12000,
                    'total_refund' => 11000, 'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED]);

                // Shipping refunded with its tax (the store records 12.00): goods and goods tax stay whole
                $shippingTax = $order(['subtotal' => 10000, 'tax_total' => 1000, 'shipping_total' => 1000, 'shipping_tax' => 200,
                    'total_amount' => 12200, 'total_paid' => 12200, 'total_refund' => 1200, 'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED]);
                \FluentCart\App\Models\OrderMeta::query()->create(['order_id' => (int) $shippingTax->id, 'meta_key' => 'shipping_refunded_amount', 'meta_value' => 1200]);
                $metaOrders[] = (int) $shippingTax->id;

                // A stale shipping record (5.00) on a fully refunded order with a fee: refunds still cover items first
                $stale = $order(['subtotal' => 10000, 'shipping_total' => 1000, 'fee_total' => 500, 'total_amount' => 11500,
                    'total_paid' => 11500, 'total_refund' => 11500, 'payment_status' => Status::PAYMENT_REFUNDED]);
                \FluentCart\App\Models\OrderMeta::query()->create(['order_id' => (int) $stale->id, 'meta_key' => 'shipping_refunded_amount', 'meta_value' => 500]);
                $metaOrders[] = (int) $stale->id;

                $params = [
                    'startDate' => new DateTime('2025-06-01 00:00:00'), 'endDate' => new DateTime('2025-06-30 23:59:59'),
                    'currency' => $fcBreakdownCurrency, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [],
                    'groupKey' => 'monthly',
                ];
                $service = RevenueReportService::make();
                $summary = $service->getRevenueData($params)['summary'];
                $pick = function ($keys) use ($summary) {
                    return array_map(function ($key) use ($summary) {
                        return round((float) ($summary[$key] ?? -1), 2);
                    }, $keys);
                };

                FcTest::assertSame(
                    [765.0, 12.0, 409.5, 343.5, 15.0, 1.0, 24.0, 383.5],
                    $pick(['gross_sales', 'discounts', 'refunds', 'net_sales', 'shipping_net', 'fees', 'taxes_net', 'total_collected']),
                    'Gross 765 - discounts 12 - refunds 409.50 (refunded tax and shipping leave their own lines) = net 343.50; + shipping kept 15 + fees 1 + taxes 24 = total 383.50'
                );
                FcTest::assertSame([357.85, 860.0, 476.5], $pick(['net_revenue', 'gross_sale', 'total_refunded_amount']),
                    'The old Revenue (net + shipping + fees) and paid gross keep their meaning');

                $byType = array_column($service->getRevenueByOrderType($params), null, 'key');
                FcTest::assertSame(
                    [[8, 335.0, 97.53], [1, 8.5, 2.47]],
                    [
                        [$byType[Status::ORDER_TYPE_PAYMENT]['orders'] ?? -1, round((float) ($byType[Status::ORDER_TYPE_PAYMENT]['net_sales'] ?? -1), 2), $byType[Status::ORDER_TYPE_PAYMENT]['share'] ?? -1],
                        [$byType[Status::ORDER_TYPE_RENEWAL]['orders'] ?? -1, round((float) ($byType[Status::ORDER_TYPE_RENEWAL]['net_sales'] ?? -1), 2), $byType[Status::ORDER_TYPE_RENEWAL]['share'] ?? -1],
                    ],
                    'Net sales by order type adds up to net sales'
                );

                $route = FcTest::rest('GET', 'reports/revenue', ['params' => [
                    'startDate' => '2025-06-01', 'endDate' => '2025-06-30', 'currency' => $fcBreakdownCurrency, 'groupKey' => 'monthly',
                ]]);
                FcTest::assertSame([200, 343.5, 2], [
                    $route['status'], round((float) ($route['data']['summary']['net_sales'] ?? -1), 2), count($route['data']['byOrderType'] ?? []),
                ], 'GET /reports/revenue returns the breakdown and the order-type split');
            } finally {
                if ($metaOrders) {
                    App::db()->table('fct_order_meta')->whereIn('order_id', $metaOrders)->delete();
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
