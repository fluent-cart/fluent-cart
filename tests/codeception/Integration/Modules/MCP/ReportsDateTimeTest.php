<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Traits\SeedsDatedPaidOrders;

/**
 * Report date/time flexibility: hourly buckets, the `since` delta, time-precise
 * date_from/date_to and the since_launch anchor. Everything resolves in UTC so
 * the numbers keep matching FluentCart's admin reports.
 */
class ReportsDateTimeTest extends DatabaseTestCase
{
    use SeedsDatedPaidOrders;

    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    private int $cid;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory    = new OrderFactory();
        // Every assertion below counts the whole orders table. Rows committed
        // past rollback by earlier DDL-bearing tests (TC-058) would inflate the
        // counts, so start from an empty table inside this test's transaction.
        Order::query()->delete();
        $this->cid = $this->customerFactory->create()->id;
    }

    public function test_hourly_trend_buckets_and_reconciles(): void
    {
        $this->seedDatedPaidOrders($this->cid);

        $trend = ReportTools::getSalesTrend([
            'currency'  => 'USD',
            'date_from' => '2025-04-10T00:00:00',
            'date_to'   => '2025-04-10T23:59:59',
            'interval'  => 'hour',
        ]);

        $this->assertSame('hour', $trend['data']['interval']);
        $byBucket = [];
        foreach ($trend['data']['trend'] as $b) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:00$/', $b['bucket']);
            $byBucket[$b['bucket']] = (int) $b['order_count'];
        }
        $this->assertSame(1, $byBucket['2025-04-10 08:00'] ?? null);
        $this->assertSame(2, $byBucket['2025-04-10 09:00'] ?? null, '09:15 + 09:45 fall in the 09:00 bucket');
        $this->assertSame(1, $byBucket['2025-04-10 11:00'] ?? null);
        $this->assertSame(1, $byBucket['2025-04-10 12:00'] ?? null);

        // Hourly counts reconcile to the single day bucket.
        $day = ReportTools::getSalesTrend([
            'currency'  => 'USD',
            'date_from' => '2025-04-10T00:00:00',
            'date_to'   => '2025-04-10T23:59:59',
            'interval'  => 'day',
        ]);
        $this->assertSame(5, (int) $day['data']['trend'][0]['order_count']);
        $this->assertSame(5, array_sum($byBucket), 'sum of hourly buckets == day total');
    }

    public function test_since_returns_only_post_timestamp_orders(): void
    {
        $this->seedDatedPaidOrders($this->cid);

        // Only the 11:05 and 12:00 orders are after 10:00.
        $res = ReportTools::queryOrders([
            'currency' => 'USD',
            'since'    => '2025-04-10 10:00:00',
            'metrics'  => ['order_count'],
        ]);
        $this->assertSame(2, (int) $res['data']['rows'][0]['order_count']);
        $this->assertSame('since', $res['data']['range']['label']);
        $this->assertStringStartsWith('2025-04-10T10:00:00', $res['data']['range']['start']);
    }

    public function test_date_from_to_honor_time_of_day(): void
    {
        $this->seedDatedPaidOrders($this->cid);

        // A one-hour window catches only the two 09:xx orders — proving the bounds
        // are NOT snapped to the whole day.
        $res = ReportTools::queryOrders([
            'currency'  => 'USD',
            'date_from' => '2025-04-10 09:00:00',
            'date_to'   => '2025-04-10 09:59:59',
            'metrics'   => ['order_count'],
        ]);
        $this->assertSame(2, (int) $res['data']['rows'][0]['order_count']);
    }

    public function test_a_reversed_range_is_an_error_not_a_report_of_zeros(): void
    {
        $res = ReportTools::getSalesReport(['start_date' => '2026-05-10', 'end_date' => '2026-05-01']);
        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame('invalid_param', $res->get_error_code());

        $res = ReportTools::queryOrders(['date_from' => '2026-05-10T00:00:00', 'date_to' => '2026-05-01T00:00:00']);
        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame(['date_from', 'date_to'], $res->get_error_data()['fields']);
    }

    public function test_since_launch_anchors_to_first_paid_order(): void
    {
        $this->seedDatedPaidOrders($this->cid);

        $res = ReportTools::queryOrders([
            'currency' => 'USD',
            'range'    => 'since_launch',
            'metrics'  => ['order_count'],
        ]);
        $this->assertSame('since_launch', $res['data']['range']['label']);
        $this->assertStringStartsWith('2025-04-01T00:00:00', $res['data']['range']['start']);
        // All six seeded orders fall in the since-launch window.
        $this->assertSame(6, (int) $res['data']['rows'][0]['order_count']);
    }
}
