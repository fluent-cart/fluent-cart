<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * query-products: the order_type dimension.
 *
 * order_type lives on the parent order (fct_orders.type), so the tool joins
 * orders to expose it. Because order_id -> orders.id is many-to-one, the join
 * can't fan out line rows, so a product's per-type line_revenue must sum back
 * to that product's ungrouped total. These tests assert that reconciliation and
 * that all product metrics survive an order_type grouping.
 */
class QueryProductsByOrderTypeTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    private const WIN_START = '2025-04-01';
    private const WIN_END   = '2025-04-30';
    private const IN_WINDOW = '2025-04-10 12:00:00';

    /** Two products so the grouping has to keep per-product buckets separate. */
    private const PROD_A = 9101;
    private const PROD_B = 9102;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
    }

    /**
     * Create a paid USD order of $type carrying one line item for $postId with
     * the given line_total (dollars) and quantity.
     */
    private function orderWithItem(int $cid, string $type, int $postId, $lineDollars, int $qty): void
    {
        $this->orderFactory
            ->withItems([[
                'post_id'      => $postId,
                'object_id'    => $postId * 10,
                'post_title'   => 'Product ' . $postId,
                'quantity'     => $qty,
                'line_total'   => Helper::toCent($lineDollars),
                'refund_total' => 0,
                'payment_type' => 'onetime',
            ]])
            ->create([
                'customer_id'    => $cid,
                'type'           => $type,
                'payment_status' => Status::PAYMENT_PAID,
                'currency'       => 'USD',
                'total_amount'   => Helper::toCent($lineDollars),
                'total_paid'     => Helper::toCent($lineDollars),
                'created_at'     => self::IN_WINDOW,
            ]);
    }

    /** Returns expected per-product, per-type line_revenue (cents). */
    private function seed(): array
    {
        $cid = $this->customerFactory->create()->id;

        // Product A: payment $100 + $20, subscription $200, renewal $30.
        $this->orderWithItem($cid, Status::ORDER_TYPE_PAYMENT, self::PROD_A, 100, 1);
        $this->orderWithItem($cid, Status::ORDER_TYPE_PAYMENT, self::PROD_A, 20, 1);
        $this->orderWithItem($cid, Status::ORDER_TYPE_SUBSCRIPTION, self::PROD_A, 200, 1);
        $this->orderWithItem($cid, Status::ORDER_TYPE_RENEWAL, self::PROD_A, 30, 1);

        // Product B: renewal $50 only — proves buckets don't bleed across products.
        $this->orderWithItem($cid, Status::ORDER_TYPE_RENEWAL, self::PROD_B, 50, 1);

        // Noise: unpaid, other-currency, out-of-window — must all be excluded.
        $this->orderFactory
            ->withItems([['post_id' => self::PROD_A, 'object_id' => self::PROD_A * 10, 'post_title' => 'Product', 'quantity' => 1, 'line_total' => Helper::toCent(999), 'refund_total' => 0, 'payment_type' => 'onetime']])
            ->create(['customer_id' => $cid, 'type' => Status::ORDER_TYPE_PAYMENT, 'payment_status' => Status::PAYMENT_PENDING, 'currency' => 'USD', 'total_amount' => Helper::toCent(999), 'created_at' => self::IN_WINDOW]);
        $this->orderFactory
            ->withItems([['post_id' => self::PROD_A, 'object_id' => self::PROD_A * 10, 'post_title' => 'Product', 'quantity' => 1, 'line_total' => Helper::toCent(888), 'refund_total' => 0, 'payment_type' => 'onetime']])
            ->create(['customer_id' => $cid, 'type' => Status::ORDER_TYPE_RENEWAL, 'payment_status' => Status::PAYMENT_PAID, 'currency' => 'EUR', 'total_amount' => Helper::toCent(888), 'total_paid' => Helper::toCent(888), 'created_at' => self::IN_WINDOW]);
        $this->orderFactory
            ->withItems([['post_id' => self::PROD_A, 'object_id' => self::PROD_A * 10, 'post_title' => 'Product', 'quantity' => 1, 'line_total' => Helper::toCent(777), 'refund_total' => 0, 'payment_type' => 'onetime']])
            ->create(['customer_id' => $cid, 'type' => Status::ORDER_TYPE_SUBSCRIPTION, 'payment_status' => Status::PAYMENT_PAID, 'currency' => 'USD', 'total_amount' => Helper::toCent(777), 'total_paid' => Helper::toCent(777), 'created_at' => '2025-03-15 12:00:00']);

        return [
            self::PROD_A => [
                Status::ORDER_TYPE_PAYMENT      => Helper::toCent(120),
                Status::ORDER_TYPE_SUBSCRIPTION => Helper::toCent(200),
                Status::ORDER_TYPE_RENEWAL      => Helper::toCent(30),
                'total'                         => Helper::toCent(350),
            ],
            self::PROD_B => [
                Status::ORDER_TYPE_RENEWAL => Helper::toCent(50),
                'total'                    => Helper::toCent(50),
            ],
        ];
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

    public function test_per_type_line_revenue_sums_back_to_product_total(): void
    {
        $expected = $this->seed();

        $grouped = ReportTools::queryProducts($this->baseParams([
            'metrics'    => ['line_revenue'],
            'dimensions' => ['product', 'order_type'],
        ]));

        // Reduce grouped rows to product_id => sum(line_revenue cents).
        $sumByProduct = [];
        foreach ($grouped['data']['rows'] as $row) {
            $this->assertArrayHasKey('order_type', $row);
            $this->assertArrayHasKey('product_id', $row);
            $pid = (int) $row['product_id'];
            $sumByProduct[$pid] = ($sumByProduct[$pid] ?? 0) + $this->toCents($row['line_revenue']);
        }

        // The headline assertion for Product A: per-type revenue sums to its total.
        $this->assertSame($expected[self::PROD_A]['total'], $sumByProduct[self::PROD_A], 'Product A per-type line_revenue sums to its total.');
        $this->assertSame($expected[self::PROD_B]['total'], $sumByProduct[self::PROD_B], 'Product B per-type line_revenue sums to its total.');

        // And it must equal the ungrouped per-product total from query-products.
        $ungrouped = ReportTools::queryProducts($this->baseParams([
            'metrics'    => ['line_revenue'],
            'dimensions' => ['product'],
        ]));
        $ungroupedByProduct = [];
        foreach ($ungrouped['data']['rows'] as $row) {
            $ungroupedByProduct[(int) $row['product_id']] = $this->toCents($row['line_revenue']);
        }
        $this->assertSame($ungroupedByProduct[self::PROD_A], $sumByProduct[self::PROD_A], 'Grouped sum matches the ungrouped Product A total.');
        $this->assertSame($ungroupedByProduct[self::PROD_B], $sumByProduct[self::PROD_B], 'Grouped sum matches the ungrouped Product B total.');
    }

    public function test_per_type_breakdown_values_for_product_a(): void
    {
        $expected = $this->seed();

        $grouped = ReportTools::queryProducts($this->baseParams([
            'metrics'    => ['line_revenue'],
            'dimensions' => ['product', 'order_type'],
        ]));

        $byKey = [];
        foreach ($grouped['data']['rows'] as $row) {
            $byKey[(int) $row['product_id'] . ':' . $row['order_type']] = $this->toCents($row['line_revenue']);
        }

        foreach ([Status::ORDER_TYPE_PAYMENT, Status::ORDER_TYPE_SUBSCRIPTION, Status::ORDER_TYPE_RENEWAL] as $type) {
            $key = self::PROD_A . ':' . $type;
            $this->assertArrayHasKey($key, $byKey, "Product A has a {$type} bucket.");
            $this->assertSame($expected[self::PROD_A][$type], $byKey[$key], "Product A {$type} line_revenue");
        }
    }

    public function test_all_metrics_survive_an_order_type_grouping(): void
    {
        $this->seed();

        // EVERY metric enum value, together with the order_type grouping that joins
        // fct_orders. list_price_sum (SUM(subtotal)) and discount_amount
        // (SUM(discount_total)) are the ones that previously threw SQL 1052 because
        // subtotal exists on both fct_order_items and fct_orders — this asserts the
        // whole metric surface survives the join, so a new metric can't regress it.
        $allMetrics = ['units_sold', 'line_revenue', 'list_price_sum', 'discount_amount', 'net_revenue', 'order_count', 'avg_unit_price', 'refund_amount'];

        $res = ReportTools::queryProducts($this->baseParams([
            'metrics'    => $allMetrics,
            'dimensions' => ['product', 'order_type'],
        ]));

        $rows = $res['data']['rows'];
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            foreach (array_merge(['product_id', 'product_title', 'order_type'], $allMetrics) as $key) {
                $this->assertArrayHasKey($key, $row, "metric/dimension {$key} is present when grouped by product x order_type");
            }
        }
    }
}
