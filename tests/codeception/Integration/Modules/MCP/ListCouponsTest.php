<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Modules\MCP\Tools\CouponTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CouponFactory;

/**
 * Priority-2 regression: coupons must be filterable/paginated with usage counts.
 *
 * list-coupons already paginates and filters; these tests lock the doc's acceptance
 * (status filter, code substring, meta.page, and a times_used field) so the
 * capability an agent needs to attribute discounted sales to a code can't regress.
 */
class ListCouponsTest extends DatabaseTestCase
{
    private CouponFactory $couponFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->couponFactory = new CouponFactory();
    }

    private function seed(): void
    {
        $this->couponFactory->create(['code' => 'VIP50', 'title' => 'VIP tier', 'status' => 'active', 'use_count' => 12]);
        $this->couponFactory->create(['code' => 'VIPLAUNCH', 'title' => 'Launch', 'status' => 'active', 'use_count' => 3]);
        $this->couponFactory->create(['code' => 'BLACKFRIDAY', 'title' => 'BF', 'status' => 'inactive', 'use_count' => 99]);
    }

    public function test_code_substring_filter(): void
    {
        $this->seed();

        $res  = CouponTools::listCoupons(['code' => 'VIP', 'per_page' => 50]);
        $codes = array_column($res['data']['coupons'], 'code');

        sort($codes);
        $this->assertSame(['VIP50', 'VIPLAUNCH'], $codes, 'code=VIP matches only the two VIP coupons.');
    }

    public function test_status_filter_returns_only_active(): void
    {
        $this->seed();

        $res = CouponTools::listCoupons(['status' => 'active', 'per_page' => 50]);
        foreach ($res['data']['coupons'] as $row) {
            $this->assertSame('active', $row['status']);
        }
        $codes = array_column($res['data']['coupons'], 'code');
        $this->assertNotContains('BLACKFRIDAY', $codes, 'The inactive coupon is excluded.');
    }

    public function test_rows_include_times_used_and_paging_meta(): void
    {
        $this->seed();

        $res = CouponTools::listCoupons(['code' => 'VIP50']);
        $row = $res['data']['coupons'][0];

        // times_used is the doc-facing usage count; it mirrors use_count.
        $this->assertArrayHasKey('times_used', $row);
        $this->assertSame(12, $row['times_used']);
        $this->assertSame($row['use_count'], $row['times_used']);

        // Paginated like the other list tools.
        $this->assertArrayHasKey('page', $res['meta']);
        $this->assertArrayHasKey('current', $res['meta']['page']);
        $this->assertArrayHasKey('total', $res['meta']['page']);
    }
}
