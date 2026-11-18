<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\CouponHelper;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Modules\MCP\Tools\CouponTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CouponFactory;

/**
 * manage-coupon turns a coupon off with the status the admin uses, "disabled".
 * The admin does not know "inactive": its coupon list turns such a coupon with a
 * past start date back to active.
 */
class ManageCouponStatusTest extends DatabaseTestCase
{
    private CouponFactory $couponFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->couponFactory = new CouponFactory();
    }

    public function test_deactivate_stores_disabled_and_the_admin_list_keeps_it_off(): void
    {
        $coupon = $this->couponFactory->create([
            'code'       => 'OFFNOW',
            'status'     => 'active',
            'start_date' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
        ]);

        $res = CouponTools::manageCoupon(['action' => 'deactivate', 'coupon_id' => $coupon->id]);
        $this->assertSame('disabled', $res['data']['status']);

        // What CouponsController::index runs for every coupon on the list.
        CouponHelper::updateCouponStatus(Coupon::query()->find($coupon->id));
        $this->assertSame('disabled', Coupon::query()->find($coupon->id)->status);
    }

    public function test_the_old_inactive_value_is_stored_as_disabled(): void
    {
        $coupon = $this->couponFactory->create(['code' => 'OLDNAME', 'status' => 'active']);

        CouponTools::manageCoupon(['action' => 'update', 'coupon_id' => $coupon->id, 'status' => 'inactive']);

        $this->assertSame('disabled', Coupon::query()->find($coupon->id)->status);
    }

    /**
     * @dataProvider startDates
     */
    public function test_the_admin_coupon_list_keeps_a_coupon_stored_as_inactive_off(int $startOffset): void
    {
        // Stored by manage-coupon before it wrote "disabled"; no tool touches it again
        $coupon = $this->couponFactory->create([
            'code'       => 'OLDOFF' . abs($startOffset),
            'status'     => 'inactive',
            'start_date' => gmdate('Y-m-d H:i:s', time() + $startOffset),
            'end_date'   => gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS),
        ]);

        // What CouponsController::index runs for every coupon on the list
        CouponHelper::updateCouponStatus(Coupon::query()->find($coupon->id));

        $this->assertSame('inactive', Coupon::query()->find($coupon->id)->status);
    }

    public function startDates(): array
    {
        return ['started yesterday' => [-DAY_IN_SECONDS], 'starts tomorrow' => [DAY_IN_SECONDS]];
    }

    public function test_renaming_a_coupon_stored_as_inactive_does_not_turn_it_on(): void
    {
        $coupon = $this->couponFactory->create([
            'code'       => 'LEGACYOFF',
            'status'     => 'inactive',
            'start_date' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
            'end_date'   => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
        ]);

        $res = CouponTools::manageCoupon(['action' => 'update', 'coupon_id' => $coupon->id, 'title' => 'Renamed']);

        $this->assertSame('disabled', $res['data']['status'], 'Still off, under the name the admin knows');
        $this->assertSame('disabled', Coupon::query()->find($coupon->id)->status);
    }

    public function test_listing_turned_off_coupons_finds_rows_stored_under_either_name(): void
    {
        $this->couponFactory->create(['code' => 'NEWOFF', 'status' => 'disabled']);
        $this->couponFactory->create(['code' => 'OLDOFF', 'status' => 'inactive']);
        $this->couponFactory->create(['code' => 'STILLON', 'status' => 'active']);

        foreach (['disabled', 'inactive'] as $status) {
            $codes = array_column(CouponTools::listCoupons(['status' => $status])['data']['coupons'], 'code');
            sort($codes);
            $this->assertSame(['NEWOFF', 'OLDOFF'], $codes, "status=$status");
        }
    }

    public function test_active_now_and_valid_now_agree_on_a_coupon_with_a_zero_end_date(): void
    {
        $coupon = $this->couponFactory->create(['code' => 'NOEND', 'status' => 'active']);
        // What a coupon saved with an empty end date holds on a MySQL that allows zero dates
        Coupon::query()->where('id', $coupon->id)->update(['end_date' => '0000-00-00 00:00:00']);

        $rows = CouponTools::listCoupons(['active_now' => true, 'code' => 'NOEND'])['data']['coupons'];

        $this->assertCount(1, $rows, 'A zero end date is no end date');
        $this->assertTrue($rows[0]['valid_now']);
    }

    public function test_an_unreadable_coupon_date_is_an_error_and_creates_nothing(): void
    {
        $res = CouponTools::manageCoupon(['action' => 'create', 'code' => 'NEXTWEEK', 'type' => 'percentage', 'amount' => 20, 'start_date' => 'next monday']);

        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame('invalid_param', $res->get_error_code());
        $this->assertSame(['start_date'], $res->get_error_data()['fields']);
        $this->assertFalse(Coupon::query()->where('code', 'NEXTWEEK')->exists(), 'No coupon that is live at once');
    }

    public function test_a_coupon_that_ends_before_it_starts_is_an_error(): void
    {
        $res = CouponTools::manageCoupon(['action' => 'create', 'code' => 'BACKWARDS', 'type' => 'percentage', 'amount' => 20, 'start_date' => '2031-02-10', 'end_date' => '2031-02-01']);

        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame('invalid_param', $res->get_error_code());
        $this->assertFalse(Coupon::query()->where('code', 'BACKWARDS')->exists());
    }

    public function test_a_coupon_with_a_future_start_is_scheduled_and_reports_its_dates(): void
    {
        $start = gmdate('Y-m-d', time() + 7 * DAY_IN_SECONDS);
        $res = CouponTools::manageCoupon(['action' => 'create', 'code' => 'SOON', 'type' => 'percentage', 'amount' => 20, 'start_date' => $start]);

        $this->assertIsArray($res);
        $this->assertSame('scheduled', $res['data']['status'], 'Not usable before its start');
        $this->assertSame($start . ' 00:00:00', Coupon::query()->where('code', 'SOON')->value('start_date'));
        $this->assertStringStartsWith($start, (string) $res['data']['start_date'], 'The result says when it starts');
    }
}
