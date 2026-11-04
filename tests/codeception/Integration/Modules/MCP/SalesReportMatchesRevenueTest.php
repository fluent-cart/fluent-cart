<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\RevenueReportService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * get-sales-report gives the admin Revenue report's figures for the same window.
 */
class SalesReportMatchesRevenueTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Order::query()->delete();
        $cid = (new CustomerFactory())->create()->id;
        $orders = new OrderFactory();

        // Tax on top, a partial refund and a coupon: gross, net and paid all differ
        $orders->create([
            'customer_id'           => $cid,
            'type'                  => Status::ORDER_TYPE_PAYMENT,
            'payment_status'        => Status::PAYMENT_PARTIALLY_REFUNDED,
            'currency'              => 'USD',
            'mode'                  => 'live',
            'subtotal'              => Helper::toCent(100),
            'coupon_discount_total' => Helper::toCent(10),
            'tax_total'             => Helper::toCent(9),
            'total_amount'          => Helper::toCent(99),
            'total_paid'            => Helper::toCent(99),
            'total_refund'          => Helper::toCent(33),
            'created_at'            => '2025-05-10 10:00:00',
        ]);
        $orders->create([
            'customer_id'    => $cid,
            'type'           => Status::ORDER_TYPE_PAYMENT,
            'payment_status' => Status::PAYMENT_PAID,
            'currency'       => 'USD',
            'mode'           => 'test',
            'subtotal'       => Helper::toCent(50),
            'total_amount'   => Helper::toCent(50),
            'total_paid'     => Helper::toCent(50),
            'created_at'     => '2025-05-11 10:00:00',
        ]);
    }

    private function adminSummary(): array
    {
        return RevenueReportService::make()->getRevenueData([
            'startDate'     => new DateTime('2025-05-01 00:00:00', new \DateTimeZone('UTC')),
            'endDate'       => new DateTime('2025-05-31 23:59:59', new \DateTimeZone('UTC')),
            'currency'      => 'USD',
            'paymentStatus' => Status::getReportStatuses(),
            'groupKey'      => 'daily',
        ])['summary'];
    }

    public function test_metrics_equal_the_revenue_report(): void
    {
        $admin = $this->adminSummary();
        $res = ReportTools::getSalesReport(['currency' => 'USD', 'start_date' => '2025-05-01', 'end_date' => '2025-05-31', 'compare' => false]);
        $m = $res['data']['metrics'];

        $this->assertSame(2, $m['order_count']);
        $this->assertSame(Helper::toCent($admin['net_sales']), $m['net_sales']['amount_cents']);
        $this->assertSame(Helper::toCent($admin['gross_sales']), $m['gross_sales']['amount_cents']);
        $this->assertSame(Helper::toCent($admin['net_revenue']), $m['net_revenue']['amount_cents']);
        $this->assertSame(Helper::toCent($admin['gross_sale']), $m['paid']['amount_cents']);
        $this->assertSame(Helper::toCent($admin['discounts']), $m['discounts']['amount_cents']);
        $this->assertSame(Helper::toCent($admin['total_collected']), $m['total_collected']['amount_cents']);
        $this->assertSame(Helper::toCent($admin['total_refunded_amount']), $m['refunded']['amount_cents']);
        // Net sales leave out the kept tax, so they are below paid minus refunds
        $this->assertLessThan(Helper::toCent(99 + 50 - 33), $m['net_sales']['amount_cents']);
    }

    public function test_mode_filter_still_applies(): void
    {
        $res = ReportTools::getSalesReport(['currency' => 'USD', 'start_date' => '2025-05-01', 'end_date' => '2025-05-31', 'compare' => false, 'mode' => 'test']);

        $this->assertSame(1, $res['data']['metrics']['order_count']);
        $this->assertSame(Helper::toCent(50), $res['data']['metrics']['net_sales']['amount_cents']);
    }

    public function test_interval_adds_the_trend(): void
    {
        $res = ReportTools::getSalesReport(['currency' => 'USD', 'start_date' => '2025-05-01', 'end_date' => '2025-05-31', 'compare' => false, 'interval' => 'day']);
        $byDay = array_column($res['data']['trend'], 'order_count', 'bucket');

        $this->assertSame(['2025-05-10' => 1, '2025-05-11' => 1], $byDay);

        // The trend adds up to the report's own net revenue and paid
        $this->assertSame($res['data']['metrics']['net_revenue']['amount_cents'], (int) round(array_sum(array_column($res['data']['trend'], 'net_revenue')) * 100));
        $this->assertSame($res['data']['metrics']['paid']['amount_cents'], (int) round(array_sum(array_column($res['data']['trend'], 'paid')) * 100));
    }
}
