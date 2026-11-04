<?php

namespace Tests\Functional\Rest\Coupons;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/coupons.md → "Deleting a coupon" section, items 34-35.
 */
class DeleteCouponCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/coupons.md [34]
     *   "Admin can delete a coupon; the coupon is removed and no longer appears in the coupon list."
     */
    public function adminCanDeleteCouponAndItIsRemovedFromTheCouponList(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $coupon = (new CouponFactory())->create([
            'title' => 'Deletable Coupon',
            'code'  => 'DELETE-ME',
        ]);
        $couponId = (int) $coupon->id;

        [$status, $body] = $I->delete('/fluent-cart/v2/coupons/' . $couponId);

        Assert::assertSame(200, $status, 'admin delete-coupon must return 200. Body: ' . wp_json_encode($body));

        global $wpdb;
        Assert::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_coupons WHERE id = %d",
                $couponId
            )),
            'delete must remove the underlying fct_coupons row. Body: ' . wp_json_encode($body)
        );

        [, $listBody] = $I->get('/fluent-cart/v2/coupons');
        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('coupons', (array) $listBody, 'list payload must surface the coupons key. Body: ' . wp_json_encode($listBody));
        Assert::assertArrayHasKey('data', (array) $listBody['coupons'], 'list payload coupons must surface a paginator data array. Body: ' . wp_json_encode($listBody));
        Assert::assertIsArray($listBody['coupons']['data'], 'list payload coupons.data must be an array. Body: ' . wp_json_encode($listBody));
        $listIds = array_map('intval', array_column((array) $listBody['coupons']['data'], 'id'));
        Assert::assertNotContains(
            $couponId,
            $listIds,
            'deleted coupon id must no longer appear in the coupon listing'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [35]
     *   "Deleting a coupon that does not exist returns a clear \"not found\" error."
     */
    public function deletingCouponThatDoesNotExistReturnsNotFoundError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $missingId = 999999;

        [$status, $body] = $I->delete('/fluent-cart/v2/coupons/' . $missingId);

        Assert::assertNotSame(200, $status, 'delete of a non-existent coupon must not return 200. Body: ' . wp_json_encode($body));
        Assert::assertSame(404, $status, 'delete of a non-existent coupon must return 404 not-found. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            404,
            (int) ($body['code'] ?? 0),
            'not-found error body must surface code=404 to match the WP_Error built by CouponResource::delete. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey('data', (array) $body, 'not-found error body must include the data key produced by makeErrorResponse. Body: ' . wp_json_encode($body));
        $message = (string) ($body['message'] ?? '');
        Assert::assertNotEmpty(
            $message,
            'not-found error response must include a non-empty message field. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsStringIgnoringCase(
            'not found',
            $message,
            'not-found error message must explicitly identify the coupon as not found. Body: ' . wp_json_encode($body)
        );
    }
}
