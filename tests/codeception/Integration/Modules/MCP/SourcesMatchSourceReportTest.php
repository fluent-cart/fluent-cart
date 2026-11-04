<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\SourceReportService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * query-sources gives the admin Sources report: its groupings, figures and channels.
 */
class SourcesMatchSourceReportTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Order::query()->delete();
        $cid = (new CustomerFactory())->create()->id;
        // The same source spelled two ways is one row; one order has a partial refund
        foreach ([['Google', 'cpc', 3000, 1000], ['google', 'CPC', 2000, 0], ['newsletter', 'email', 1500, 0]] as $i => $o) {
            $order = (new OrderFactory())->create([
                'customer_id' => $cid, 'type' => Status::ORDER_TYPE_PAYMENT, 'currency' => 'USD',
                'payment_status' => $o[3] ? Status::PAYMENT_PARTIALLY_REFUNDED : Status::PAYMENT_PAID,
                'total_amount' => $o[2], 'total_paid' => $o[2], 'total_refund' => $o[3],
                'created_at' => '2025-08-1' . $i . ' 10:00:00',
            ]);
            App::db()->table('fct_order_operations')->insert([
                'order_id' => $order->id, 'utm_source' => $o[0], 'utm_medium' => $o[1], 'utm_campaign' => 'fall',
                'created_at' => '2025-08-1' . $i . ' 10:00:00', 'updated_at' => '2025-08-1' . $i . ' 10:00:00',
            ]);
        }
    }

    private function adminParams(): array
    {
        return [
            'startDate'     => new DateTime('2025-08-01 00:00:00', new \DateTimeZone('UTC')),
            'endDate'       => new DateTime('2025-08-31 23:59:59', new \DateTimeZone('UTC')),
            'currency'      => 'USD',
            'paymentStatus' => Status::getReportStatuses(),
            'groupKey'      => 'monthly',
        ];
    }

    public function test_rows_equal_the_sources_report(): void
    {
        $admin = SourceReportService::make()->getReport($this->adminParams() + ['group_by' => 'source_medium'])['rows'];
        $res = ReportTools::querySources(['currency' => 'USD', 'start_date' => '2025-08-01', 'end_date' => '2025-08-31', 'group_by' => 'source_medium']);
        $rows = $res['data']['rows'];

        $this->assertCount(2, $rows, 'Google/cpc and google/CPC are one row');
        $byLabel = array_column($admin, null, 'label');
        foreach ($rows as $row) {
            $a = $byLabel[$row['label']];
            $this->assertSame($a['orders'], $row['orders']);
            $this->assertEquals($a['net_sales'], $row['net_sales']);
            $this->assertEquals($a['gross_sales'], $row['gross_sales']);
            $this->assertEquals($a['refunds'], $row['refunds']);
        }
    }

    public function test_old_dimensions_map_to_the_grouping(): void
    {
        $res = ReportTools::querySources(['currency' => 'USD', 'start_date' => '2025-08-01', 'end_date' => '2025-08-31', 'dimensions' => ['utm_medium', 'utm_source']]);
        $this->assertSame('source_medium', $res['data']['group_by']);
    }

    public function test_channels_view_equals_the_channels_report(): void
    {
        $admin = SourceReportService::make()->getChannels($this->adminParams());
        $res = ReportTools::querySources(['currency' => 'USD', 'start_date' => '2025-08-01', 'end_date' => '2025-08-31', 'view' => 'channels']);

        $this->assertSame($admin['coverage']['customers'], $res['data']['coverage']['customers']);
        $this->assertSame(array_column($admin['channels'], 'customers', 'key'), array_column($res['data']['channels'], 'customers', 'key'));
    }

    public function test_product_grouping_needs_pro(): void
    {
        remove_all_filters('fluent_cart/report/source_groupings');
        $res = ReportTools::querySources(['group_by' => 'product']);
        $this->assertSame('pro_required', is_wp_error($res) ? $res->get_error_code() : null);
    }

    public function test_unknown_grouping_is_an_error(): void
    {
        $res = ReportTools::querySources(['group_by' => 'utm_source']);
        $this->assertSame('unsupported_grouping', is_wp_error($res) ? $res->get_error_code() : null);
    }
}
