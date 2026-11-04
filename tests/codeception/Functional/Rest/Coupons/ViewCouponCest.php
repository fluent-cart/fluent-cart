<?php

namespace Tests\Functional\Rest\Coupons;

use FluentCart\App\Models\Coupon;
use FluentCart\App\Services\DateTime\DateTime;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ActivityFactory;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/coupons.md → "Viewing a single coupon" section, items 8-10.
 */
class ViewCouponCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/coupons.md [8]
     *   "Admin can open a coupon by id and see its details along with its activity history."
     */
    public function adminCanOpenCouponByIdAndSeeItsDetailsAlongWithActivityHistory(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $coupon = (new CouponFactory())->create([
            'title' => 'View Details Coupon',
            'code'  => 'VIEW-DETAILS',
        ]);

        (new ActivityFactory())->create([
            'module_type' => Coupon::class,
            'module_name' => 'coupon',
            'module_id'   => (int) $coupon->id,
            'title'       => 'Coupon Created',
            'content'     => 'Coupon "View Details Coupon" created by admin',
        ]);

        [, $body] = $I->get('/fluent-cart/v2/coupons/' . (int) $coupon->id);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('coupon', (array) $body, 'view response must contain a top-level coupon key');
        $payload = (array) $body['coupon'];

        Assert::assertSame((int) $coupon->id, (int) ($payload['id'] ?? 0), 'view payload id must match the requested coupon id');
        Assert::assertSame('VIEW-DETAILS', (string) ($payload['code'] ?? ''), 'view payload must echo the stored coupon code');
        Assert::assertSame('View Details Coupon', (string) ($payload['title'] ?? ''), 'view payload must echo the stored coupon title');

        Assert::assertArrayHasKey('activities', $payload, 'view payload must include the activities relation');
        $activities = (array) $payload['activities'];
        Assert::assertCount(1, $activities, 'view payload must include exactly the one seeded activity row');
        Assert::assertSame('Coupon Created', (string) ($activities[0]['title'] ?? ''), 'first activity entry must surface its title');
    }

    /**
     * Spec: Cases/Functional/coupons.md [9]
     *   "Opening a coupon whose end date has already passed automatically marks it as expired."
     */
    public function openingCouponWhoseEndDateHasPassedAutomaticallyMarksItAsExpired(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $pastEndGmt = DateTime::gmtNow()->modify('-2 days')->format('Y-m-d H:i:s');
        $coupon = (new CouponFactory())->create([
            'title'    => 'Past End Coupon',
            'code'     => 'PAST-END',
            'status'   => 'active',
            'end_date' => $pastEndGmt,
        ]);

        Assert::assertSame('active', (string) $coupon->fresh()->status, 'precondition: seeded coupon must start in active status');

        [, $body] = $I->get('/fluent-cart/v2/coupons/' . (int) $coupon->id);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(
            'expired',
            (string) ($body['coupon']['status'] ?? ''),
            'view payload must reflect the auto-expired status when end_date has passed'
        );

        global $wpdb;
        $persistedStatus = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}fct_coupons WHERE id = %d",
            (int) $coupon->id
        ));
        Assert::assertSame(
            'expired',
            $persistedStatus,
            'auto-expire must persist the new status to fct_coupons.status, not just compute it on the fly'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [10]
     *   "Opening a coupon that does not exist returns a clear \"not found\" error."
     */
    public function openingCouponThatDoesNotExistReturnsNotFoundError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $missingId = 999999;

        [$status, $body] = $I->get('/fluent-cart/v2/coupons/' . $missingId);

        Assert::assertNotSame(200, $status, 'view of a non-existent coupon must not return 200. Body: ' . wp_json_encode($body));
        Assert::assertSame(404, $status, 'view of a non-existent coupon must return 404 not-found. Body: ' . wp_json_encode($body));
        $message = (string) ($body['message'] ?? '');
        Assert::assertNotEmpty(
            $message,
            'not-found error response must include a non-empty message field. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsStringIgnoringCase(
            'not found',
            $message,
            'not-found error message must explicitly identify the coupon as not found, not surface a generic error. Body: ' . wp_json_encode($body)
        );
    }
}
