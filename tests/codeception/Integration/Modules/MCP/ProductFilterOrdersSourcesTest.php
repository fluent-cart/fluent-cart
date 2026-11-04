<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * Priority-4 regression: product_id / variation_id on query-orders and
 * query-sources. Neither accepted a product filter, so per-product order analytics
 * and per-product UTM attribution were impossible on a multi-product store.
 *
 * Seeds two single-product orders and asserts each tool isolates the one product
 * (order-level metrics, orders containing the product) with no cross-product leak,
 * and that omitting the filter still spans both — backward compatible.
 */
class ProductFilterOrdersSourcesTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    private const WIN_START = '2025-06-01';
    private const WIN_END   = '2025-06-30';
    private const IN_WINDOW = '2025-06-10 12:00:00';

    private const PROD_A = 9501;
    private const VAR_A  = 95011;
    private const PROD_B = 9502;
    private const VAR_B  = 95021;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory    = new OrderFactory();
    }

    private function order(int $cid, int $postId, int $objectId, $dollars): void
    {
        $this->orderFactory
            ->withItems([[
                'post_id'      => $postId,
                'object_id'    => $objectId,
                'post_title'   => 'Product ' . $postId,
                'title'        => 'Variation ' . $objectId,
                'quantity'     => 1,
                'line_total'   => Helper::toCent($dollars),
                'refund_total' => 0,
                'payment_type' => 'onetime',
            ]])
            ->create([
                'customer_id'    => $cid,
                'type'           => Status::ORDER_TYPE_PAYMENT,
                'payment_status' => Status::PAYMENT_PAID,
                'currency'       => 'USD',
                'total_amount'   => Helper::toCent($dollars),
                'total_paid'     => Helper::toCent($dollars),
                'created_at'     => self::IN_WINDOW,
            ]);
    }

    private function seed(): void
    {
        $cid = $this->customerFactory->create()->id;
        $this->order($cid, self::PROD_A, self::VAR_A, 100);
        $this->order($cid, self::PROD_B, self::VAR_B, 500);
    }

    private function base(array $extra): array
    {
        return array_merge(['currency' => 'USD', 'start_date' => self::WIN_START, 'end_date' => self::WIN_END], $extra);
    }

    private function toCents($decimalString): int
    {
        return (int) round(((float) $decimalString) * 100);
    }

    public function test_query_orders_product_id_isolates_one_product(): void
    {
        $this->seed();

        $res = ReportTools::queryOrders($this->base([
            'product_id' => self::PROD_A,
            'metrics'    => ['order_count', 'gross_revenue'],
        ]));
        $row = $res['data']['rows'][0];

        $this->assertSame(1, (int) $row['order_count'], 'Only the order containing Product A.');
        $this->assertSame(Helper::toCent(100), $this->toCents($row['gross_revenue']), 'Gross is Product A order only.');

        // Backward compatible: no filter spans both orders.
        $all = ReportTools::queryOrders($this->base(['metrics' => ['order_count', 'gross_revenue']]));
        $this->assertSame(2, (int) $all['data']['rows'][0]['order_count']);
        $this->assertSame(Helper::toCent(600), $this->toCents($all['data']['rows'][0]['gross_revenue']));
    }

    public function test_query_orders_variation_id_isolates_one_variation(): void
    {
        $this->seed();

        $res = ReportTools::queryOrders($this->base([
            'variation_id' => self::VAR_B,
            'metrics'      => ['order_count', 'gross_revenue'],
        ]));
        $row = $res['data']['rows'][0];

        $this->assertSame(1, (int) $row['order_count']);
        $this->assertSame(Helper::toCent(500), $this->toCents($row['gross_revenue']));
    }

    public function test_query_sources_product_id_attributes_one_product(): void
    {
        $this->seed();

        $res = ReportTools::querySources($this->base([
            'product_id' => self::PROD_A,
            'metrics'    => ['orders', 'gross_revenue'],
        ]));

        // Neither seeded order carries UTM, so attribution collapses to the 'none'
        // bucket — but the product filter keeps only Product A's order there.
        $totalOrders = 0;
        $totalGross  = 0;
        foreach ($res['data']['rows'] as $row) {
            $totalOrders += (int) $row['orders'];
            $totalGross  += $this->toCents($row['gross_revenue']);
        }
        $this->assertSame(1, $totalOrders, 'Only Product A order is attributed.');
        $this->assertSame(Helper::toCent(100), $totalGross);

        // Backward compatible: no filter attributes both orders.
        $all = ReportTools::querySources($this->base(['metrics' => ['orders', 'gross_revenue']]));
        $allOrders = 0;
        foreach ($all['data']['rows'] as $row) {
            $allOrders += (int) $row['orders'];
        }
        $this->assertSame(2, $allOrders);
    }
}
