<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * Priority-3 regression: every windowed aggregate must echo meta.date_basis so an
 * agent can tell whether a window means created_at or paid_at. query-sources and
 * get-sales-report already did; query-orders and query-products did not.
 */
class DateBasisMetaTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory    = new OrderFactory();

        $cid = $this->customerFactory->create()->id;
        $this->orderFactory
            ->withItems([[
                'post_id'      => 8801,
                'object_id'    => 88011,
                'post_title'   => 'P',
                'title'        => 'V',
                'quantity'     => 1,
                'line_total'   => Helper::toCent(100),
                'refund_total' => 0,
                'payment_type' => 'onetime',
            ]])
            ->create([
                'customer_id'    => $cid,
                'type'           => Status::ORDER_TYPE_PAYMENT,
                'payment_status' => Status::PAYMENT_PAID,
                'currency'       => 'USD',
                'total_amount'   => Helper::toCent(100),
                'total_paid'     => Helper::toCent(100),
                'created_at'     => '2025-05-10 12:00:00',
            ]);
    }

    private function params(): array
    {
        return ['currency' => 'USD', 'start_date' => '2025-05-01', 'end_date' => '2025-05-31'];
    }

    public function test_query_orders_emits_date_basis(): void
    {
        $res = ReportTools::queryOrders($this->params());
        $this->assertSame('created_at', $res['meta']['date_basis']);
    }

    public function test_query_products_emits_date_basis(): void
    {
        $res = ReportTools::queryProducts($this->params());
        $this->assertSame('created_at', $res['meta']['date_basis']);
    }
}
