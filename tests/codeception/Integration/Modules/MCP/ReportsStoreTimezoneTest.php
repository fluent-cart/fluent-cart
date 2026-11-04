<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Models\Order;
use FluentCart\App\Modules\MCP\Tools\ProductFinancialsTools;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\SeedsDatedPaidOrders;

/**
 * MCP report days are store days (WordPress timezone), the same as the admin reports.
 */
class ReportsStoreTimezoneTest extends DatabaseTestCase
{
    use SeedsDatedPaidOrders;

    private $originalTimezone;
    private int $cid;

    public function setUp(): void
    {
        parent::setUp();
        Order::query()->delete();
        $this->originalTimezone = get_option('timezone_string');
        update_option('timezone_string', 'Asia/Dhaka');
        $this->cid = (new CustomerFactory())->create()->id;

        // UTC times; in Dhaka (+06:00) the first two are on Apr 10, the last two on Apr 11
        $this->createPaidOrderAt($this->cid, '2025-04-09 19:00:00');
        $this->createPaidOrderAt($this->cid, '2025-04-10 08:00:00');
        $this->createPaidOrderAt($this->cid, '2025-04-10 19:00:00');
        $this->createPaidOrderAt($this->cid, '2025-04-10 20:00:00');
    }

    public function tearDown(): void
    {
        update_option('timezone_string', $this->originalTimezone);
        parent::tearDown();
    }

    public function test_date_only_window_is_a_store_day(): void
    {
        $range = ReportTools::resolveRange(['date_from' => '2025-04-10', 'date_to' => '2025-04-10']);
        $this->assertSame('2025-04-09 18:00:00', $range['start']);
        $this->assertSame('2025-04-10 17:59:59', $range['end']);

        $res = ReportTools::queryOrders([
            'currency'  => 'USD',
            'date_from' => '2025-04-10',
            'date_to'   => '2025-04-10',
            'metrics'   => ['order_count'],
        ]);
        $this->assertSame(2, (int) $res['data']['rows'][0]['order_count']);
    }

    public function test_day_buckets_use_store_days(): void
    {
        $args = ['currency' => 'USD', 'date_from' => '2025-04-10', 'date_to' => '2025-04-11'];

        $trend = ReportTools::getSalesTrend($args + ['interval' => 'day']);
        $byDay = [];
        foreach ($trend['data']['trend'] as $bucket) {
            $byDay[$bucket['bucket']] = (int) $bucket['order_count'];
        }
        $this->assertSame(['2025-04-10' => 2, '2025-04-11' => 2], $byDay);

        $orders = ReportTools::queryOrders($args + ['metrics' => ['order_count'], 'dimensions' => ['day']]);
        $byDay = [];
        foreach ($orders['data']['rows'] as $row) {
            $byDay[$row['day']] = (int) $row['order_count'];
        }
        $this->assertSame(['2025-04-10' => 2, '2025-04-11' => 2], $byDay);
    }

    public function test_explicit_offset_is_kept(): void
    {
        $range = ReportTools::resolveRange(['date_from' => '2025-04-10T00:00:00Z', 'date_to' => '2025-04-10T23:59:59Z']);
        $this->assertSame('2025-04-10 00:00:00', $range['start']);
        $this->assertSame('2025-04-10 23:59:59', $range['end']);
    }

    public function test_product_financials_window_is_a_store_day(): void
    {
        $productId = (int) (new ProductFactory())->create()->ID;

        $res = ProductFinancialsTools::getProductFinancials([
            'product_id' => $productId,
            'date_from'  => '2025-04-10',
            'date_to'    => '2025-04-10',
        ]);
        $this->assertStringStartsWith('2025-04-09T18:00:00', $res['data']['window']['from']);
        $this->assertStringStartsWith('2025-04-10T17:59:59', $res['data']['window']['to']);

        // A preset gives the same bounds as the report tools
        $res = ProductFinancialsTools::getProductFinancials(['product_id' => $productId, 'range' => 'last_month']);
        $range = ReportTools::resolveRange(['range' => 'last_month']);
        $this->assertStringStartsWith(str_replace(' ', 'T', $range['start']), $res['data']['window']['from']);
    }
}
