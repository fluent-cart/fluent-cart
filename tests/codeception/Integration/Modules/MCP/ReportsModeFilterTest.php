<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * Reports mode filter (live / test / all).
 *
 * Reports historically counted BOTH live and test orders, so the mode filter
 * defaults to 'all' (numbers unchanged). An agent can pass live/test to scope
 * revenue, and the effective mode is always echoed as meta.mode so a figure is
 * never silently polluted by test-mode orders.
 */
class ReportsModeFilterTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    private const WIN_START = '2025-05-01';
    private const WIN_END   = '2025-05-31';
    private const IN_WINDOW = '2025-05-10 12:00:00';

    private const LIVE_DOLLARS = 100;
    private const TEST_DOLLARS = 40;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
    }

    /** One live paid order and one test-mode paid order, both in-window, same currency. */
    private function seed(): void
    {
        $cid = $this->customerFactory->create()->id;

        foreach ([
            [self::LIVE_DOLLARS, Status::ORDER_MODE_LIVE],
            [self::TEST_DOLLARS, Status::ORDER_MODE_TEST],
        ] as [$dollars, $mode]) {
            $this->orderFactory->create([
                'customer_id'    => $cid,
                'type'           => Status::ORDER_TYPE_PAYMENT,
                'payment_status' => Status::PAYMENT_PAID,
                'currency'       => 'USD',
                'mode'           => $mode,
                'total_amount'   => Helper::toCent($dollars),
                'total_paid'     => Helper::toCent($dollars),
                'created_at'     => self::IN_WINDOW,
            ]);
        }
    }

    private function salesParams(array $extra): array
    {
        return array_merge([
            'currency'   => 'USD',
            'start_date' => self::WIN_START,
            'end_date'   => self::WIN_END,
            'compare'    => false,
        ], $extra);
    }

    private function grossCents(array $res): int
    {
        return (int) $res['data']['metrics']['gross_revenue']['amount_cents'];
    }

    public function test_default_counts_both_and_discloses_mode_all(): void
    {
        $this->seed();

        $res = ReportTools::getSalesReport($this->salesParams([]));

        $this->assertSame('all', $res['meta']['mode'], 'Default mode is disclosed as all.');
        $this->assertSame(
            Helper::toCent(self::LIVE_DOLLARS + self::TEST_DOLLARS),
            $this->grossCents($res),
            'Default counts both live and test orders (unchanged behavior).'
        );
    }

    public function test_live_excludes_test_orders(): void
    {
        $this->seed();

        $res = ReportTools::getSalesReport($this->salesParams(['mode' => 'live']));

        $this->assertSame('live', $res['meta']['mode']);
        $this->assertSame(
            Helper::toCent(self::LIVE_DOLLARS),
            $this->grossCents($res),
            'mode=live drops the test-mode order.'
        );
    }

    public function test_test_mode_excludes_live_orders(): void
    {
        $this->seed();

        $res = ReportTools::getSalesReport($this->salesParams(['mode' => 'test']));

        $this->assertSame('test', $res['meta']['mode']);
        $this->assertSame(
            Helper::toCent(self::TEST_DOLLARS),
            $this->grossCents($res),
            'mode=test keeps only the test-mode order.'
        );
    }

    public function test_query_orders_honors_and_discloses_mode(): void
    {
        $this->seed();

        $res = ReportTools::queryOrders(array_merge($this->salesParams([]), [
            'mode'    => 'live',
            'metrics' => ['gross_revenue'],
        ]));

        $this->assertSame('live', $res['meta']['mode']);
        // Single ungrouped total row.
        $rows = $res['data']['rows'];
        $this->assertNotEmpty($rows);
        $this->assertSame(
            (float) Helper::toDecimalWithoutComma(Helper::toCent(self::LIVE_DOLLARS)),
            (float) $rows[0]['gross_revenue'],
            'query-orders mode=live excludes the test-mode order.'
        );
    }
}
