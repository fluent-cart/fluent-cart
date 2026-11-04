<?php

namespace Tests\Functional\Rest\Coupons;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\AppliedCouponFactory;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/coupons.md → "Listing and searching coupons" section, items 1-7.
 */
class ListCouponsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/coupons.md [2]
     *   "Admin can paginate the coupons list."
     */
    public function adminCanPaginateCouponsList(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $createdIds = [];
        for ($i = 0; $i < 4; $i++) {
            $createdIds[] = (int) (new CouponFactory())->create([
                'title' => 'Pagination Coupon ' . $i,
                'code'  => 'COUPON-PAGINATE-' . $i,
            ])->id;
        }
        rsort($createdIds);
        $expectedPage1 = array_slice($createdIds, 0, 2);
        $expectedPage2 = array_slice($createdIds, 2, 2);

        [, $page1] = $I->get('/fluent-cart/v2/coupons', ['per_page' => 2, 'page' => 1]);
        $I->seeResponseCodeIs(200);
        Assert::assertSame(1, (int) ($page1['coupons']['current_page'] ?? 0), 'page 1 response must report current_page=1');
        Assert::assertCount(2, $page1['coupons']['data'] ?? [], 'page 1 must contain exactly per_page items');

        [, $page2] = $I->get('/fluent-cart/v2/coupons', ['per_page' => 2, 'page' => 2]);
        Assert::assertSame(2, (int) ($page2['coupons']['current_page'] ?? 0), 'page 2 response must report current_page=2');
        Assert::assertCount(2, $page2['coupons']['data'] ?? [], 'page 2 must contain exactly per_page items');

        $page1Ids = array_map('intval', array_column($page1['coupons']['data'] ?? [], 'id'));
        $page2Ids = array_map('intval', array_column($page2['coupons']['data'] ?? [], 'id'));

        foreach ($expectedPage1 as $id) {
            Assert::assertContains($id, $page1Ids, "page 1 must contain seeded coupon id {$id}");
        }
        foreach ($expectedPage2 as $id) {
            Assert::assertContains($id, $page2Ids, "page 2 must contain seeded coupon id {$id}");
        }
        Assert::assertEmpty(
            array_intersect($page1Ids, $page2Ids),
            'page 1 and page 2 must contain disjoint coupons'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [3]
     *   "Admin can request a specific page size when listing coupons."
     */
    public function adminCanRequestSpecificPageSizeWhenListingCoupons(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        for ($i = 0; $i < 5; $i++) {
            (new CouponFactory())->create([
                'title' => 'PageSize Coupon ' . $i,
                'code'  => 'COUPON-PAGESIZE-' . $i,
            ]);
        }

        [, $body] = $I->get('/fluent-cart/v2/coupons', ['per_page' => 3]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(3, (int) ($body['coupons']['per_page'] ?? 0), 'response must echo back the requested per_page value');
        Assert::assertCount(3, $body['coupons']['data'] ?? [], 'data array must hold exactly per_page items');
    }

    /**
     * Spec: Cases/Functional/coupons.md [4]
     *   "Admin receives an empty list, not an error, when requesting a page beyond the available coupons."
     */
    public function adminReceivesEmptyListWhenRequestingPageBeyondAvailableCoupons(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        (new CouponFactory())->create(['title' => 'Beyond Coupon', 'code' => 'COUPON-BEYOND']);

        [, $body] = $I->get('/fluent-cart/v2/coupons', ['per_page' => 5, 'page' => 999]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame([], $body['coupons']['data'] ?? null, 'page beyond available results must return an empty data array, not an error');
    }

    /**
     * Spec: Cases/Functional/coupons.md [5]
     *   "Admin can list active coupon codes for use when creating an order."
     */
    public function adminCanListActiveCouponCodesForUseWhenCreatingOrder(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        (new CouponFactory())->create(['title' => 'Active A', 'code' => 'ACTIVE-CODE-A', 'status' => 'active']);
        (new CouponFactory())->create(['title' => 'Active B', 'code' => 'ACTIVE-CODE-B', 'status' => 'active']);
        (new CouponFactory())->create(['title' => 'Disabled', 'code' => 'DISABLED-CODE', 'status' => 'disabled']);
        (new CouponFactory())->create(['title' => 'Expired',  'code' => 'EXPIRED-CODE',  'status' => 'expired']);

        [, $body] = $I->get('/fluent-cart/v2/coupons/listCoupons');

        $I->seeResponseCodeIs(200);
        $codes = (array) ($body['coupons'] ?? []);
        Assert::assertContains('ACTIVE-CODE-A', $codes, 'active coupon code must appear in listCoupons response');
        Assert::assertContains('ACTIVE-CODE-B', $codes, 'second active coupon code must appear in listCoupons response');
        Assert::assertNotContains('DISABLED-CODE', $codes, 'disabled coupon must be excluded from active codes list');
        Assert::assertNotContains('EXPIRED-CODE', $codes, 'expired coupon must be excluded from active codes list');
    }

    /**
     * Spec: Cases/Functional/coupons.md [6]
     *   "The coupon list shows how many times each coupon has been applied so far."
     */
    public function couponListShowsHowManyTimesEachCouponHasBeenAppliedSoFar(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();

        $coupon = (new CouponFactory())->create([
            'title' => 'Use Count Coupon',
            'code'  => 'COUPON-USE-COUNT',
        ]);
        $customer = (new CustomerFactory())->create(['email' => 'use-count-list@example.test']);

        $applyTimes = 3;
        for ($i = 0; $i < $applyTimes; $i++) {
            $order = (new OrderFactory())->create([
                'customer_id' => $customer->id,
                'invoice_no'  => 'INV-USECOUNT-' . $i,
            ]);
            (new AppliedCouponFactory())->create([
                'order_id'  => (int) $order->id,
                'coupon_id' => (int) $coupon->id,
                'code'      => $coupon->code,
            ]);
        }

        [, $body] = $I->get('/fluent-cart/v2/coupons');

        $I->seeResponseCodeIs(200);
        $rows = (array) ($body['coupons']['data'] ?? []);
        Assert::assertNotEmpty($rows, 'coupon list payload must not be empty');

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) ($row['id'] ?? 0)] = $row;
        }

        Assert::assertArrayHasKey((int) $coupon->id, $byId, 'seeded coupon must appear in the listing');
        $entry = $byId[(int) $coupon->id];
        $candidates = ['use_count', 'total_items', 'applied_count', 'times_applied'];
        $surfacedCount = null;
        foreach ($candidates as $key) {
            if (array_key_exists($key, $entry) && (int) $entry[$key] === $applyTimes) {
                $surfacedCount = (int) $entry[$key];
                break;
            }
        }
        Assert::assertSame(
            $applyTimes,
            $surfacedCount,
            'coupon listing must surface a count field reflecting the actual number of fct_applied_coupons rows for this coupon'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [7]
     *   "Admin sees coupon search input that resembles an SQL injection attempt treated as plain text without causing a server error."
     */
    public function adminSeesCouponSearchInputThatResemblesAnSqlInjectionAttemptTreatedAsPlainTextWithoutCausingServerError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $a = (new CouponFactory())->create(['title' => 'SQLi Coupon A', 'code' => 'COUPON-SQLI-A']);
        $b = (new CouponFactory())->create(['title' => 'SQLi Coupon B', 'code' => 'COUPON-SQLI-B']);
        $c = (new CouponFactory())->create(['title' => 'SQLi Coupon C', 'code' => 'COUPON-SQLI-C']);
        $totalSeeded = 3;

        $payload = "COUPON-SQLI-X' OR '1'='1";
        [, $body] = $I->get('/fluent-cart/v2/coupons', ['search' => $payload]);

        $I->seeResponseCodeIs(200);
        $rows = $body['coupons']['data'] ?? null;
        Assert::assertIsArray($rows, 'injection-shaped search must still produce a paginator data array');

        $ids = array_map('intval', array_column((array) $rows, 'id'));
        Assert::assertLessThan(
            $totalSeeded,
            count($ids),
            'plain-text search of an injection-shaped payload must not OR-return every seeded coupon; got ' . count($ids) . ' rows of ' . $totalSeeded
        );
        Assert::assertNotContains((int) $a->id, $ids, 'coupon A must not match an injection-shaped payload that does not literally appear in any column');
        Assert::assertNotContains((int) $b->id, $ids, 'coupon B must not match an injection-shaped payload that does not literally appear in any column');
        Assert::assertNotContains((int) $c->id, $ids, 'coupon C must not match an injection-shaped payload that does not literally appear in any column');

        $literalPayload = 'COUPON-LITERAL-MARKER';
        $marked = (new CouponFactory())->create([
            'title' => 'Literal Marker Coupon',
            'code'  => $literalPayload,
        ]);

        [, $literalBody] = $I->get('/fluent-cart/v2/coupons', ['search' => $literalPayload]);
        $I->seeResponseCodeIs(200);
        $literalIds = array_map('intval', array_column((array) ($literalBody['coupons']['data'] ?? []), 'id'));
        Assert::assertContains(
            (int) $marked->id,
            $literalIds,
            'plain substring search must still locate a coupon whose code literally contains it (proves earlier empty result was due to plain-text comparison, not blanket sanitization-rejection)'
        );

        global $wpdb;
        $usersCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
        Assert::assertGreaterThan(0, $usersCount, 'wp_users must still exist after injection probe');
        $couponsCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_coupons");
        Assert::assertSame(4, $couponsCount, 'fct_coupons must still hold the 4 seeded rows after the injection probe');
    }
}
