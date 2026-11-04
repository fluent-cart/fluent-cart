<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\Widgets\DashboardWidget;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Traits\BuildsTaxFixtures;

class DashboardCurrencyTotalsTest extends DatabaseTestCase
{
    use BuildsTaxFixtures;

    public function setUp(): void
    {
        parent::setUp();
        $this->setStoreCurrency('USD');
        // The widget counts the whole table, and earlier classes whose code
        // commits its own transaction leave orders behind. Cleared inside this
        // test's transaction, so the delete is rolled back with the test.
        App::db()->table('fct_orders')->delete();
    }

    public function tearDown(): void
    {
        $this->restoreStoreCurrency();
        parent::tearDown();
    }

    public function test_money_uses_each_orders_frozen_rate_and_keeps_tax_deductions(): void
    {
        $customer = (new CustomerFactory())->create();
        foreach ([
            ['USD', '1', 1000, 100, 100, 50],
            ['JPY', '150', 150000, 30000, 15000, 7500],
            ['GBP', '0.8', 800, 80, 0, 0],
            ['JPY', '180', 180000, 0, 0, 0],
        ] as [$currency, $rate, $paid, $refund, $tax, $shippingTax]) {
            (new OrderFactory())->create([
                'customer_id' => $customer->id,
                'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID,
                'currency' => $currency,
                'rate' => $rate,
                'config' => ['fx' => ['currency' => $currency, 'rate' => $rate, 'source' => 'manual']],
                'total_paid' => $paid,
                'total_refund' => $refund,
                'tax_total' => $tax,
                'shipping_tax' => $shippingTax,
            ]);
        }

        $stats = (new DashboardWidget())->widgetData();

        $this->assertSame(4, (int) $stats[1]['current_count']);
        // Net sales as the Revenue report counts them (tax kept by the store stays out), in base currency
        $start = (new \FluentCart\App\Services\DateTime\DateTime('now', wp_timezone()))->modify('-29 days')->startOfDay();
        $end = (new \FluentCart\App\Services\DateTime\DateTime('now', wp_timezone()))->endOfDay();
        $revenue = \FluentCart\App\Services\Report\RevenueReportService::make()->getRevenueData([
            'startDate' => \FluentCart\App\Services\Report\ReportHelper::toGmt($start),
            'endDate' => \FluentCart\App\Services\Report\ReportHelper::toGmt($end),
            'currency' => \FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency::ALL,
            'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [], 'groupKey' => 'monthly',
        ])['summary']['net_sales'];
        $this->assertEquals(33.32, $stats[2]['current_count']);
        $this->assertEquals(round((float) $revenue, 2), round((float) $stats[2]['current_count'], 2));
        $this->assertEquals(4.0, $stats[3]['current_count']);
        $this->assertSame('USD', $stats[2]['currency']);
        $this->assertSame('USD', $stats[3]['currency']);
        $this->assertSame(0, $stats[2]['excluded_orders']);
    }

    public function test_untrusted_orders_stay_in_the_order_count_but_not_money_totals(): void
    {
        $customer = (new CustomerFactory())->create();
        foreach (['unknown', 'legacy_unknown'] as $source) {
            (new OrderFactory())->create([
                'customer_id' => $customer->id,
                'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID,
                'currency' => 'EUR',
                'rate' => '1',
                'config' => ['fx' => ['currency' => 'EUR', 'rate' => '1', 'source' => $source]],
                'total_paid' => 5000,
                'total_refund' => 500,
            ]);
        }
        foreach ([Status::ORDER_ON_HOLD, Status::ORDER_FAILED] as $status) {
            (new OrderFactory())->create([
                'customer_id' => $customer->id, 'status' => $status,
                'total_paid' => 1000, 'total_refund' => 100,
            ]);
        }
        (new OrderFactory())->create([
            'customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID,
            'created_at' => '2000-01-01 00:00:00', 'total_paid' => 1000,
        ]);

        $stats = (new DashboardWidget())->widgetData();

        $this->assertSame(2, (int) $stats[1]['current_count']);
        $this->assertEquals(0, $stats[2]['current_count']);
        $this->assertEquals(0, $stats[3]['current_count']);
        $this->assertSame(2, $stats[2]['excluded_orders']);
        $this->assertSame(2, $stats[3]['excluded_orders']);
    }

    public function test_an_empty_dashboard_returns_zero_in_the_store_currency(): void
    {
        $this->setStoreCurrency('GBP');

        $stats = (new DashboardWidget())->widgetData();

        $this->assertEquals(0, $stats[2]['current_count']);
        $this->assertEquals(0, $stats[3]['current_count']);
        $this->assertSame('GBP', $stats[2]['currency']);
        $this->assertSame('GBP', $stats[3]['currency']);
        $this->assertSame(0, $stats[2]['excluded_orders']);
    }

    /** @dataProvider legacyRates */
    public function test_legacy_and_invalid_rates_are_never_guessed(string $currency, string $rate, array $config, float $expected, int $excluded): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID,
            'currency' => $currency, 'total_paid' => 1000, 'total_refund' => 100,
        ]);
        // Preserve the legacy fixture instead of filling a new snapshot through model hooks.
        App::db()->table('fct_orders')->where('id', $order->id)->update([
            'rate' => $rate, 'config' => json_encode($config),
        ]);

        $stats = (new DashboardWidget())->widgetData();

        $this->assertSame(1, (int) $stats[1]['current_count']);
        $this->assertEquals($expected, $stats[2]['current_count']);
        $this->assertEquals($excluded ? 0 : 1, $stats[3]['current_count']);
        $this->assertSame($excluded, $stats[2]['excluded_orders']);
    }

    public static function legacyRates(): array
    {
        return [
            'base currency without snapshot' => ['USD', '1', [], 9.0, 0],
            'foreign currency without snapshot' => ['EUR', '1', [], 0.0, 1],
            'foreign currency with zero rate' => ['EUR', '0', ['fx' => ['source' => 'manual']], 0.0, 1],
        ];
    }
}
