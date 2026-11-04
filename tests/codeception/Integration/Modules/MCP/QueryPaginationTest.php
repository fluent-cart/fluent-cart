<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * query-* pagination: page / per_page walk grouped rows past the 200-row cap
 * with a deterministic tie-break, so pages never overlap or drop a group. Before
 * this, query-customers silently truncated at 200 with no signal at all.
 */
class QueryPaginationTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
    }

    public function test_query_customers_pages_cover_all_groups_without_overlap(): void
    {
        $countries = ['AA', 'BB', 'CC', 'DD', 'EE'];
        foreach ($countries as $c) {
            $this->customerFactory->create(['country' => $c]);
        }

        $seen    = [];
        $page    = 1;
        $guard   = 0;
        $hasMore = false;
        do {
            $res = ReportTools::queryCustomers([
                'metrics'    => ['customer_count'],
                'dimensions' => ['country'],
                'per_page'   => 2,
                'page'       => $page,
            ]);

            $this->assertSame(2, $res['meta']['page']['per_page']);
            $this->assertSame($page, $res['meta']['page']['current']);
            $this->assertLessThanOrEqual(2, count($res['data']['rows']), 'a page never exceeds per_page');

            foreach ($res['data']['rows'] as $row) {
                $this->assertArrayNotHasKey($row['country'], $seen, 'a group must not repeat across pages');
                $seen[$row['country']] = true;
            }

            $hasMore = $res['meta']['page']['has_more'];
            $page++;
            $guard++;
        } while ($hasMore && $guard < 10);

        $this->assertFalse($hasMore, 'paging terminates with has_more=false');
        foreach ($countries as $c) {
            $this->assertArrayHasKey($c, $seen, "country {$c} appears on exactly one page");
        }
    }

    public function test_query_orders_first_page_caps_and_flags_more(): void
    {
        $cid = $this->customerFactory->create()->id;
        $days = ['2025-07-01', '2025-07-02', '2025-07-03', '2025-07-04', '2025-07-05'];
        foreach ($days as $i => $d) {
            $this->orderFactory->create([
                'customer_id'    => $cid,
                'type'           => Status::ORDER_TYPE_PAYMENT,
                'payment_status' => Status::PAYMENT_PAID,
                'currency'       => 'USD',
                'total_amount'   => Helper::toCent(10 * ($i + 1)),
                'total_paid'     => Helper::toCent(10 * ($i + 1)),
                'created_at'     => $d . ' 12:00:00',
            ]);
        }

        $base = [
            'metrics'    => ['order_count'],
            'dimensions' => ['day'],
            'currency'   => 'USD',
            'date_from'  => '2025-07-01',
            'date_to'    => '2025-07-31',
            'per_page'   => 2,
        ];

        $page1 = ReportTools::queryOrders(array_merge($base, ['page' => 1]));
        $this->assertCount(2, $page1['data']['rows']);
        $this->assertTrue($page1['meta']['page']['has_more']);
        // truncated is kept as a backward-compatible alias of has_more.
        $this->assertTrue($page1['meta']['truncated']);

        $page3 = ReportTools::queryOrders(array_merge($base, ['page' => 3]));
        $this->assertCount(1, $page3['data']['rows']);
        $this->assertFalse($page3['meta']['page']['has_more']);
        $this->assertFalse($page3['meta']['truncated']);
    }
}
