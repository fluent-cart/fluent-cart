<?php

namespace Tests\Integration\Resources;

use FluentCart\Api\Resource\CouponResource;
use FluentCart\App\Helpers\CouponHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Coupon;
use FluentCart\Framework\Support\Arr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CouponFactory;

class CouponResourceTest extends DatabaseTestCase
{
    private CouponFactory $couponFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->couponFactory = new CouponFactory();
    }

    // =========================================================================
    // Spec 78-79: create — amount conversion
    // =========================================================================

    public function test_create_fixed_converts_amount_to_cents(): void
    {
        $result = CouponResource::create([
            'title'      => 'Fixed Coupon',
            'code'       => 'FIX_CENTS',
            'status'     => 'active',
            'type'       => 'fixed',
            'amount'     => 10, // $10
            'stackable'  => 'yes',
            'conditions' => ['min_purchase_amount' => 0],
            'notes'      => '',
        ]);

        $this->assertArrayHasKey('data', $result);
        $coupon = Coupon::query()->find($result['data']['id']);
        $this->assertEquals(Helper::toCent(10), $coupon->amount);
    }

    public function test_create_percentage_does_not_convert_amount(): void
    {
        $result = CouponResource::create([
            'title'      => 'Percent Coupon',
            'code'       => 'PCT_RAW',
            'status'     => 'active',
            'type'       => 'percentage',
            'amount'     => 15, // 15%
            'stackable'  => 'yes',
            'conditions' => ['min_purchase_amount' => 0],
            'notes'      => '',
        ]);

        $coupon = Coupon::query()->find($result['data']['id']);
        $this->assertEquals(15, $coupon->amount); // Not converted to cents
    }

    // =========================================================================
    // Spec 80-81: create — condition amount conversion
    // =========================================================================

    public function test_create_converts_min_purchase_amount_to_cents(): void
    {
        $result = CouponResource::create([
            'title'      => 'Min Purchase',
            'code'       => 'MINP',
            'status'     => 'active',
            'type'       => 'fixed',
            'amount'     => 5,
            'stackable'  => 'yes',
            'conditions' => ['min_purchase_amount' => 50], // $50
            'notes'      => '',
        ]);

        $coupon = Coupon::query()->find($result['data']['id']);
        $this->assertEquals(Helper::toCent(50), $coupon->conditions['min_purchase_amount']);
    }

    public function test_create_converts_max_discount_amount_to_cents(): void
    {
        $result = CouponResource::create([
            'title'      => 'Max Discount',
            'code'       => 'MAXD',
            'status'     => 'active',
            'type'       => 'percentage',
            'amount'     => 20,
            'stackable'  => 'yes',
            'conditions' => ['min_purchase_amount' => 0, 'max_discount_amount' => 25], // $25
            'notes'      => '',
        ]);

        $coupon = Coupon::query()->find($result['data']['id']);
        $this->assertEquals(Helper::toCent(25), $coupon->conditions['max_discount_amount']);
    }

    // =========================================================================
    // Spec 82-84: update
    // =========================================================================

    public function test_update_coupon(): void
    {
        $coupon = $this->couponFactory->create(['title' => 'Original']);
        $result = CouponResource::update([
            'title'      => 'Updated',
            'type'       => 'fixed',
            'amount'     => 20,
            'conditions' => ['min_purchase_amount' => 0],
        ], $coupon->id);

        $this->assertArrayHasKey('data', $result);
        $reloaded = Coupon::query()->find($coupon->id);
        $this->assertSame('Updated', $reloaded->title);
    }

    public function test_update_returns_error_without_id(): void
    {
        $result = CouponResource::update(['title' => 'X'], null);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_update_returns_error_for_nonexistent_coupon(): void
    {
        $result = CouponResource::update(['title' => 'X'], 999999);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // =========================================================================
    // Spec 85-87: delete
    // =========================================================================

    public function test_delete_coupon(): void
    {
        $coupon = $this->couponFactory->create();
        $result = CouponResource::delete($coupon->id);

        $this->assertNotInstanceOf(\WP_Error::class, $result);
        $this->assertNull(Coupon::query()->find($coupon->id));
    }

    public function test_delete_returns_error_without_id(): void
    {
        $result = CouponResource::delete(null);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_delete_returns_error_for_nonexistent(): void
    {
        $result = CouponResource::delete(999999);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // =========================================================================
    // Spec 88-89: find
    // =========================================================================

    public function test_find_by_id(): void
    {
        $coupon = $this->couponFactory->create();
        $found = CouponResource::find($coupon->id);
        $this->assertInstanceOf(Coupon::class, $found);
        $this->assertEquals($coupon->id, $found->id);
    }

    public function test_find_by_code_is_case_sensitive(): void
    {
        $this->couponFactory->create(['code' => 'CaseSensitive']);
        $found = CouponResource::find(null, ['type' => 'byCode', 'code' => 'CaseSensitive']);
        $this->assertNotNull($found);

        $notFound = CouponResource::find(null, ['type' => 'byCode', 'code' => 'casesensitive']);
        $this->assertNull($notFound);
    }

    // =========================================================================
    // Spec 90: listCoupon
    // =========================================================================

    public function test_list_coupon_returns_active_codes_only(): void
    {
        $this->couponFactory->create(['code' => 'ACTIVE1', 'status' => 'active']);
        $this->couponFactory->create(['code' => 'ACTIVE2', 'status' => 'active']);
        $this->couponFactory->create(['code' => 'INACTIVE', 'status' => 'inactive']);

        $codes = CouponResource::listCoupon();
        $this->assertContains('ACTIVE1', $codes);
        $this->assertContains('ACTIVE2', $codes);
        $this->assertNotContains('INACTIVE', $codes);
    }

    // =========================================================================
    // Spec 91-94: CouponHelper::updateCouponStatus
    // =========================================================================

    public function test_update_status_marks_expired(): void
    {
        $coupon = $this->couponFactory->create([
            'status'   => 'active',
            'end_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        CouponHelper::updateCouponStatus($coupon);

        // Verify persisted state, not just in-memory
        $reloaded = Coupon::query()->find($coupon->id);
        $this->assertSame('expired', $reloaded->getStatus());
    }

    public function test_update_status_marks_scheduled(): void
    {
        $coupon = $this->couponFactory->create([
            'status'     => 'active',
            'start_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        CouponHelper::updateCouponStatus($coupon);

        $reloaded = Coupon::query()->find($coupon->id);
        $this->assertSame('scheduled', $reloaded->getStatus());
    }

    public function test_update_status_activates_past_start_date(): void
    {
        $coupon = $this->couponFactory->create([
            'status'     => 'scheduled',
            'start_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        CouponHelper::updateCouponStatus($coupon);

        $reloaded = Coupon::query()->find($coupon->id);
        $this->assertSame('active', $reloaded->getStatus());
    }

    // =========================================================================
    // Spec 95-97: CouponHelper::checkStackability
    // =========================================================================

    public function test_stackability_rejects_duplicate(): void
    {
        $coupon = $this->couponFactory->create(['code' => 'DUP']);
        $result = CouponHelper::checkStackability('DUP', ['DUP'], $coupon);
        $this->assertNotNull($result);
        $this->assertSame(400, $result['code']);
    }

    public function test_stackability_rejects_when_last_is_non_stackable(): void
    {
        $this->couponFactory->create(['code' => 'NOSTACK', 'stackable' => 'no']);
        $newCoupon = $this->couponFactory->create(['code' => 'NEW', 'stackable' => 'yes']);

        $result = CouponHelper::checkStackability('NEW', ['NOSTACK'], $newCoupon);
        $this->assertNotNull($result);
        $this->assertSame(400, $result['code']);
    }

    public function test_stackability_rejects_non_stackable_new_coupon(): void
    {
        $this->couponFactory->create(['code' => 'EXISTING', 'stackable' => 'yes']);
        $newCoupon = $this->couponFactory->create(['code' => 'NONSTACKNEW', 'stackable' => 'no']);

        $result = CouponHelper::checkStackability('NONSTACKNEW', ['EXISTING'], $newCoupon);
        $this->assertNotNull($result);
        $this->assertSame(400, $result['code']);
    }

    // =========================================================================
    // Spec 136: Edge — formatAmount does NOT convert percentage to cents
    // =========================================================================

    public function test_format_amount_does_not_convert_percentage(): void
    {
        $resultFixed = CouponResource::create([
            'title' => 'F', 'code' => 'FMTFIX', 'status' => 'active',
            'type' => 'fixed', 'amount' => 10, 'stackable' => 'yes',
            'conditions' => ['min_purchase_amount' => 0], 'notes' => '',
        ]);
        $resultPct = CouponResource::create([
            'title' => 'P', 'code' => 'FMTPCT', 'status' => 'active',
            'type' => 'percentage', 'amount' => 10, 'stackable' => 'yes',
            'conditions' => ['min_purchase_amount' => 0], 'notes' => '',
        ]);

        $fixedCoupon = Coupon::query()->find($resultFixed['data']['id']);
        $pctCoupon = Coupon::query()->find($resultPct['data']['id']);

        // Fixed: 10 dollars = 1000 cents
        $this->assertEquals(1000, $fixedCoupon->amount);
        // Percentage: 10% stays as 10
        $this->assertEquals(10, $pctCoupon->amount);
    }
}
