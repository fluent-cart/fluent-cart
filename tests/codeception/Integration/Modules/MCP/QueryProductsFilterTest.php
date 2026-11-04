<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * query-products: the product_id / variation_id entity filters.
 *
 * Regression for the dropped-filter bug: passing product_id with
 * dimensions=[variation] used to group variations across the WHOLE catalog
 * because the param was never wired into the query. These tests seed TWO
 * products (each with two variations) and assert the filter isolates one
 * product's variations with no cross-product leakage, and that the filtered
 * rows reconcile to that product's own totals.
 */
class QueryProductsFilterTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    private const WIN_START = '2025-04-01';
    private const WIN_END   = '2025-04-30';
    private const IN_WINDOW = '2025-04-10 12:00:00';

    private const PROD_A = 9201;
    private const PROD_B = 9202;

    // Variation ids (object_id on the line item). Two per product.
    private const VAR_A1 = 92011;
    private const VAR_A2 = 92012;
    private const VAR_B1 = 92021;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory    = new OrderFactory();
    }

    private function orderWithItem(int $cid, int $postId, int $objectId, $unitDollars, int $qty): void
    {
        // line_total is the whole line (unit price x quantity), matching how
        // FluentCart stores fct_order_items.line_total and how query-products sums
        // it. The comments below describe the per-unit price, so multiply by qty.
        $lineTotal = Helper::toCent($unitDollars) * $qty;
        $this->orderFactory
            ->withItems([[
                'post_id'      => $postId,
                'object_id'    => $objectId,
                'post_title'   => 'Product ' . $postId,
                'title'        => 'Variation ' . $objectId,
                'quantity'     => $qty,
                'line_total'   => $lineTotal,
                'refund_total' => 0,
                'payment_type' => 'onetime',
            ]])
            ->create([
                'customer_id'    => $cid,
                'type'           => Status::ORDER_TYPE_PAYMENT,
                'payment_status' => Status::PAYMENT_PAID,
                'currency'       => 'USD',
                'total_amount'   => $lineTotal,
                'total_paid'     => $lineTotal,
                'created_at'     => self::IN_WINDOW,
            ]);
    }

    private function seed(): void
    {
        $cid = $this->customerFactory->create()->id;

        // Product A: two variations. A1 = $100 x2 units, A2 = $40 x1.
        $this->orderWithItem($cid, self::PROD_A, self::VAR_A1, 100, 2);
        $this->orderWithItem($cid, self::PROD_A, self::VAR_A2, 40, 1);

        // Product B: one variation, $500 x3 — the "other product" that must never
        // bleed into a product_id=A query.
        $this->orderWithItem($cid, self::PROD_B, self::VAR_B1, 500, 3);
    }

    private function toCents($decimalString): int
    {
        return (int) round(((float) $decimalString) * 100);
    }

    private function baseParams(array $extra): array
    {
        return array_merge([
            'currency'   => 'USD',
            'start_date' => self::WIN_START,
            'end_date'   => self::WIN_END,
        ], $extra);
    }

    public function test_product_id_isolates_one_products_variations(): void
    {
        $this->seed();

        $res = ReportTools::queryProducts($this->baseParams([
            'product_id' => self::PROD_A,
            'dimensions' => ['product', 'variation'],
            'metrics'    => ['units_sold', 'line_revenue'],
        ]));

        $rows = $res['data']['rows'];
        $this->assertNotEmpty($rows);

        // Every returned row belongs to Product A — no cross-product leakage.
        $productIds = [];
        $variationIds = [];
        foreach ($rows as $row) {
            $productIds[(int) $row['product_id']] = true;
            $variationIds[(int) $row['variation_id']] = $this->toCents($row['line_revenue']);
        }
        $this->assertSame([self::PROD_A], array_keys($productIds), 'Only Product A appears when product_id=A.');

        // Product A's two variations are present; Product B's is absent.
        $this->assertArrayHasKey(self::VAR_A1, $variationIds);
        $this->assertArrayHasKey(self::VAR_A2, $variationIds);
        $this->assertArrayNotHasKey(self::VAR_B1, $variationIds);

        // Filtered variation rows reconcile to Product A's own total ($100*2 + $40).
        $this->assertSame(Helper::toCent(240), array_sum($variationIds), 'Variation rows sum to Product A total.');
    }

    public function test_nonexistent_product_id_returns_no_rows(): void
    {
        $this->seed();

        $res = ReportTools::queryProducts($this->baseParams([
            'product_id' => 987654321,
            'dimensions' => ['product', 'variation'],
            'metrics'    => ['units_sold'],
        ]));

        // A working filter yields zero rows; the pre-fix bug returned the whole catalog.
        $this->assertSame([], $res['data']['rows'], 'A non-matching product_id returns no rows.');
    }

    public function test_variation_id_narrows_to_a_single_variation(): void
    {
        $this->seed();

        $res = ReportTools::queryProducts($this->baseParams([
            'product_id'   => self::PROD_A,
            'variation_id' => self::VAR_A1,
            'dimensions'   => ['variation'],
            'metrics'      => ['units_sold', 'line_revenue'],
        ]));

        $rows = $res['data']['rows'];
        $this->assertCount(1, $rows, 'variation_id restricts to exactly one variation row.');
        $this->assertSame(self::VAR_A1, (int) $rows[0]['variation_id']);
        $this->assertSame(2, (int) $rows[0]['units_sold']);
        $this->assertSame(Helper::toCent(200), $this->toCents($rows[0]['line_revenue']));
    }

    public function test_omitting_product_id_is_unchanged_and_spans_products(): void
    {
        $this->seed();

        $res = ReportTools::queryProducts($this->baseParams([
            'dimensions' => ['product'],
            'metrics'    => ['line_revenue'],
        ]));

        $productIds = [];
        foreach ($res['data']['rows'] as $row) {
            $productIds[(int) $row['product_id']] = true;
        }
        // Backward-compatible: with no filter, both seeded products are still present.
        $this->assertArrayHasKey(self::PROD_A, $productIds);
        $this->assertArrayHasKey(self::PROD_B, $productIds);
    }
}
