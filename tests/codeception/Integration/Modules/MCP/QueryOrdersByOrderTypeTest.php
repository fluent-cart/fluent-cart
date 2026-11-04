<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * query-orders: the order_type dimension.
 *
 * Reconciliation guarantee: because the order_type buckets share the exact same
 * paid/currency/UTC-range filter as the ungrouped query and fct_orders.type is
 * NOT NULL (every order lands in exactly one bucket), the per-type gross_revenue
 * must sum back to the ungrouped total. These tests assert that, plus order_count
 * reconciliation and that all metrics survive an order_type grouping (alone and
 * combined with a time dimension).
 */
class QueryOrdersByOrderTypeTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    /** A fixed, in-the-past UTC window so the run is deterministic. */
    private const WIN_START = '2025-03-01';
    private const WIN_END   = '2025-03-31';
    private const IN_WINDOW = '2025-03-10 12:00:00';

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
    }

    /**
     * Seed paid orders of every type inside the window, plus noise that must be
     * excluded: a non-paid order, a different-currency order, and an out-of-window
     * order. Returns the expected USD gross (cents) and counts per type.
     */
    private function seed(): array
    {
        $customer = $this->customerFactory->create();
        $cid = $customer->id;

        $paidUsd = function ($type, $dollars, $createdAt = self::IN_WINDOW) use ($cid) {
            return $this->orderFactory->create([
                'customer_id'    => $cid,
                'type'           => $type,
                'payment_status' => Status::PAYMENT_PAID,
                'currency'       => 'USD',
                'total_amount'   => Helper::toCent($dollars),
                'total_paid'     => Helper::toCent($dollars),
                'created_at'     => $createdAt,
            ]);
        };

        // In-window, paid, USD — the set the report must count.
        $paidUsd(Status::ORDER_TYPE_PAYMENT, 100);
        $paidUsd(Status::ORDER_TYPE_PAYMENT, 40);
        $paidUsd(Status::ORDER_TYPE_SUBSCRIPTION, 200);
        $paidUsd(Status::ORDER_TYPE_RENEWAL, 30);

        // Noise — none of these may appear in the USD/paid/in-window totals.
        // Unpaid.
        $this->orderFactory->create([
            'customer_id'    => $cid,
            'type'           => Status::ORDER_TYPE_PAYMENT,
            'payment_status' => Status::PAYMENT_PENDING,
            'currency'       => 'USD',
            'total_amount'   => Helper::toCent(999),
            'created_at'     => self::IN_WINDOW,
        ]);
        // Different currency.
        $this->orderFactory->create([
            'customer_id'    => $cid,
            'type'           => Status::ORDER_TYPE_RENEWAL,
            'payment_status' => Status::PAYMENT_PAID,
            'currency'       => 'EUR',
            'total_amount'   => Helper::toCent(500),
            'total_paid'     => Helper::toCent(500),
            'created_at'     => self::IN_WINDOW,
        ]);
        // Out of window (the month before).
        $this->orderFactory->create([
            'customer_id'    => $cid,
            'type'           => Status::ORDER_TYPE_SUBSCRIPTION,
            'payment_status' => Status::PAYMENT_PAID,
            'currency'       => 'USD',
            'total_amount'   => Helper::toCent(777),
            'total_paid'     => Helper::toCent(777),
            'created_at'     => '2025-02-15 12:00:00',
        ]);

        return [
            'gross_cents' => [
                Status::ORDER_TYPE_PAYMENT      => Helper::toCent(140),
                Status::ORDER_TYPE_SUBSCRIPTION => Helper::toCent(200),
                Status::ORDER_TYPE_RENEWAL      => Helper::toCent(30),
            ],
            'counts' => [
                Status::ORDER_TYPE_PAYMENT      => 2,
                Status::ORDER_TYPE_SUBSCRIPTION => 1,
                Status::ORDER_TYPE_RENEWAL      => 1,
            ],
            'total_gross_cents' => Helper::toCent(370),
            'total_count'       => 4,
        ];
    }

    /** queryOrders returns gross_revenue as a compact decimal string; back to cents. */
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

    public function test_groups_by_order_type_with_per_type_revenue_and_count(): void
    {
        $expected = $this->seed();

        $res = ReportTools::queryOrders($this->baseParams([
            'metrics'    => ['gross_revenue', 'order_count'],
            'dimensions' => ['order_type'],
        ]));

        $rows = $res['data']['rows'];
        $this->assertCount(3, $rows, 'One row per order_type present in the window.');

        $byType = [];
        foreach ($rows as $row) {
            $this->assertArrayHasKey('order_type', $row, 'Row is keyed by order_type, not the raw type column.');
            $byType[$row['order_type']] = $row;
        }

        $this->assertSame(
            ['payment', 'renewal', 'subscription'],
            self::sorted(array_keys($byType)),
            'Buckets are exactly the three order types.'
        );

        foreach ($expected['gross_cents'] as $type => $cents) {
            $this->assertSame($cents, $this->toCents($byType[$type]['gross_revenue']), "gross_revenue for {$type}");
            $this->assertSame($expected['counts'][$type], $byType[$type]['order_count'], "order_count for {$type}");
        }
    }

    public function test_per_type_gross_sums_back_to_ungrouped_total(): void
    {
        $expected = $this->seed();

        $grouped = ReportTools::queryOrders($this->baseParams([
            'metrics'    => ['gross_revenue', 'order_count'],
            'dimensions' => ['order_type'],
        ]));
        $ungrouped = ReportTools::queryOrders($this->baseParams([
            'metrics'    => ['gross_revenue', 'order_count'],
            'dimensions' => [],
        ]));

        $sumGross = 0;
        $sumCount = 0;
        foreach ($grouped['data']['rows'] as $row) {
            $sumGross += $this->toCents($row['gross_revenue']);
            $sumCount += $row['order_count'];
        }

        $this->assertCount(1, $ungrouped['data']['rows'], 'Ungrouped query returns a single total row.');
        $totalRow = $ungrouped['data']['rows'][0];

        // The headline assertion: per-type revenue reconciles with the total.
        $this->assertSame($this->toCents($totalRow['gross_revenue']), $sumGross, 'Per-type gross_revenue sums to the ungrouped total.');
        $this->assertSame($totalRow['order_count'], $sumCount, 'Per-type order_count sums to the ungrouped total.');

        // And the total matches what get-sales-report would compute over the same
        // filter (same SUM(total_amount), same paid/currency/range scoping).
        $this->assertSame($expected['total_gross_cents'], $sumGross);
        $this->assertSame($expected['total_count'], $sumCount);
    }

    public function test_order_type_combined_with_month_reconciles(): void
    {
        $expected = $this->seed();

        $res = ReportTools::queryOrders($this->baseParams([
            'metrics'    => ['gross_revenue'],
            'dimensions' => ['order_type', 'month'],
        ]));

        $rows = $res['data']['rows'];
        // All in-window orders fall in 2025-03, so one row per type, all in that month.
        $this->assertCount(3, $rows);

        $sum = 0;
        foreach ($rows as $row) {
            $this->assertArrayHasKey('order_type', $row);
            $this->assertArrayHasKey('month', $row);
            $this->assertSame('2025-03', $row['month']);
            $sum += $this->toCents($row['gross_revenue']);
        }
        $this->assertSame($expected['total_gross_cents'], $sum, 'order_type x month still sums to the total.');
    }

    public function test_all_metrics_survive_an_order_type_grouping(): void
    {
        $this->seed();

        $res = ReportTools::queryOrders($this->baseParams([
            'metrics'    => ['order_count', 'gross_revenue', 'paid_revenue', 'refunded_amount', 'aov', 'unique_customers'],
            'dimensions' => ['order_type'],
        ]));

        $rows = $res['data']['rows'];
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            foreach (['order_type', 'order_count', 'gross_revenue', 'paid_revenue', 'refunded_amount', 'aov', 'unique_customers'] as $key) {
                $this->assertArrayHasKey($key, $row, "metric {$key} is present when grouped by order_type");
            }
            // One seed customer across the board.
            $this->assertSame(1, $row['unique_customers']);
        }
    }

    private static function sorted(array $values): array
    {
        sort($values);
        return $values;
    }
}
