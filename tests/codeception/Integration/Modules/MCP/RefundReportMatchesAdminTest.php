<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\RefundReportService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;

/**
 * get-refund-report counts refunds on their own date, as the admin Refunds page does.
 */
class RefundReportMatchesAdminTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Order::query()->delete();
        $cid = (new CustomerFactory())->create()->id;

        // An April order refunded in May
        $order = (new OrderFactory())->create([
            'customer_id'    => $cid,
            'type'           => Status::ORDER_TYPE_PAYMENT,
            'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED,
            'currency'       => 'USD',
            'total_amount'   => Helper::toCent(100),
            'total_paid'     => Helper::toCent(100),
            'total_refund'   => Helper::toCent(40),
            'created_at'     => '2025-04-20 10:00:00',
        ]);
        (new OrderTransactionFactory())->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => Helper::toCent(40),
            'created_at'       => '2025-05-05 10:00:00',
        ]);
    }

    public function test_refunds_count_in_the_month_they_happened(): void
    {
        $may = ReportTools::getRefundReport(['currency' => 'USD', 'start_date' => '2025-05-01', 'end_date' => '2025-05-31']);
        $this->assertSame(Helper::toCent(40), $may['data']['total_refunded']['amount_cents']);
        $this->assertSame(1, $may['data']['refunded_order_count']);

        $april = ReportTools::getRefundReport(['currency' => 'USD', 'start_date' => '2025-04-01', 'end_date' => '2025-04-30']);
        $this->assertSame(0, $april['data']['total_refunded']['amount_cents']);
        // The April order was later refunded, so April's cohort rate counts it
        $this->assertEquals(100, $april['data']['refund_rate_percent']);
    }

    public function test_summary_equals_the_admin_overview(): void
    {
        $admin = RefundReportService::make()->getOverview([
            'startDate'     => new DateTime('2025-05-01 00:00:00', new \DateTimeZone('UTC')),
            'endDate'       => new DateTime('2025-05-31 23:59:59', new \DateTimeZone('UTC')),
            'currency'      => 'USD',
            'paymentStatus' => Status::getReportStatuses(),
            'withProducts'  => false,
        ])['summary'];

        $res = ReportTools::getRefundReport(['currency' => 'USD', 'start_date' => '2025-05-01', 'end_date' => '2025-05-31'])['data'];

        $this->assertSame($admin['refunds'], $res['refund_count']);
        $this->assertSame($admin['full']['count'], $res['full']['count']);
        $this->assertSame($admin['partial']['count'], $res['partial']['count']);
        $this->assertSame(Helper::toCent($admin['average_refund']), $res['average_refund']['amount_cents']);
        $this->assertArrayNotHasKey('by_product', $res, 'Most refunded products are a Pro report');
    }
}
