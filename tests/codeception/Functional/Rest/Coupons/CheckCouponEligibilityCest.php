<?php

namespace Tests\Functional\Rest\Coupons;

use FluentCart\App\Models\Product;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ProductTermFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/coupons.md → "Checking product eligibility" section + appendix
 * eligibility items, cases 50-51, 102, 115-116.
 *
 * Route: POST /fluent-cart/v2/coupons/checkProductEligibility (meta: orders/create OR orders/manage)
 * Controller: CouponsController::isProductEligible — pulls productId, appliedCoupons,
 * origin from the request and calls CouponResource::checkProductEligibilityForCodes(...).
 * The eligibility check is meant to consult the coupon's conditions (included_products,
 * excluded_products, included_categories, excluded_categories) and report whether the
 * product is eligible for the applied coupon(s).
 */
class CheckCouponEligibilityCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/coupons.md [50]
     *   "Admin can ask whether a given product is eligible for an applied coupon and receive a clear answer."
     */
    public function adminCanAskWhetherProductIsEligibleForAppliedCouponAndReceiveClearAnswer(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();

        (new CouponFactory())->create([
            'code'       => 'ELIGIBLE-OK',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [],
        ]);
        $product = (new ProductFactory())->create();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/checkProductEligibility', [
            'productId'      => (int) $product->ID,
            'appliedCoupons' => ['ELIGIBLE-OK'],
            'origin'         => 'admin',
        ]);

        Assert::assertSame(
            200,
            $status,
            'check eligibility must return 200. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey(
            'isApplicable',
            (array) $body,
            'response must surface an isApplicable verdict (clear answer). Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            true,
            $body['isApplicable'] ?? null,
            'a coupon with no product/category restrictions must report the product as applicable (strict bool true). Body: ' . wp_json_encode($body)
        );
    }

    /**
     * The applied-coupon list is caller-controlled and feeds a whereIn, so an oversized
     * list is rejected outright rather than truncated to a silently different question.
     */
    public function checkEligibilityRejectsAnOversizedAppliedCouponsList(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $product = (new ProductFactory())->create();
        $codes = [];
        for ($i = 0; $i <= 50; $i++) {
            $codes[] = 'BULK-' . $i;
        }

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/checkProductEligibility', [
            'productId'      => (int) $product->ID,
            'appliedCoupons' => $codes,
            'origin'         => 'admin',
        ]);

        Assert::assertSame(400, $status, 'an oversized appliedCoupons list must be rejected with 400. Body: ' . wp_json_encode($body));
        Assert::assertArrayNotHasKey(
            'isApplicable',
            (array) $body,
            'a rejected request must not report an eligibility verdict. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * The contract is codes only. An id-shaped payload (a number no coupon carries as its
     * code) must read as an unknown code and refuse eligibility — not silently resolve by id.
     */
    public function checkEligibilityTreatsAnIdOnlyPayloadAsAnUnknownCode(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $product = (new ProductFactory())->create();
        $coupon = (new CouponFactory())->create([
            'code'       => 'ID-PAYLOAD',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [],
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/checkProductEligibility', [
            'productId'      => (int) $product->ID,
            'appliedCoupons' => [(int) $coupon->id],
            'origin'         => 'admin',
        ]);

        Assert::assertSame(200, $status, 'id payload must return 200. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            false,
            $body['isApplicable'] ?? null,
            'an id that matches no coupon code must be refused as unknown, not resolved by id. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * A numeric coupon code is legal. Under the codes-only contract an integer payload is
     * still a code: it must resolve to the coupon carrying that number as its code, never to
     * the coupon holding it as an id.
     */
    public function checkEligibilityResolvesAnIntegerPayloadAsACode(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $product = (new ProductFactory())->create();

        $targetCoupon = (new CouponFactory())->create([
            'code'       => 'PROVENANCE-TARGET',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [],
        ]);

        // Its code is the other coupon's id, and it excludes the product.
        (new CouponFactory())->create([
            'code'       => (string) $targetCoupon->id,
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [
                'excluded_products' => [(int) $product->ID],
            ],
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/checkProductEligibility', [
            'productId'      => (int) $product->ID,
            'appliedCoupons' => [(int) $targetCoupon->id],
            'origin'         => 'admin',
        ]);

        Assert::assertSame(200, $status, 'integer payload must return 200. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            false,
            $body['isApplicable'] ?? null,
            'an integer payload is a code: it must resolve to the coupon whose code is that number, honoring its restriction. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * The mirror case: the same number sent as a string is a code, and must resolve to the
     * coupon carrying that code rather than the coupon holding it as an id.
     */
    public function checkEligibilityResolvesNumericStringToTheCouponWhoseCodeItIs(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $product = (new ProductFactory())->create();

        $targetCoupon = (new CouponFactory())->create([
            'code'       => 'PROVENANCE-TARGET-2',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [],
        ]);

        (new CouponFactory())->create([
            'code'       => (string) $targetCoupon->id,
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [
                'excluded_products' => [(int) $product->ID],
            ],
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/checkProductEligibility', [
            'productId'      => (int) $product->ID,
            'appliedCoupons' => [(string) $targetCoupon->id],
            'origin'         => 'admin',
        ]);

        Assert::assertSame(200, $status, 'code payload must return 200. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            false,
            $body['isApplicable'] ?? null,
            'a string identifier must resolve by code, honoring that coupon restriction. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [51]
     *   "Checking a product whose category is excluded by a coupon reports the product as ineligible for that coupon."
     */
    public function checkingProductWhoseCategoryIsExcludedReportsIneligible(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();

        $product = (new ProductFactory())->create();
        $term = (new ProductTermFactory())->create(ProductTermFactory::TAXONOMY_CATEGORY);
        wp_set_object_terms((int) $product->ID, [(int) $term['term_id']], ProductTermFactory::TAXONOMY_CATEGORY);
        $taxTermId = (int) get_term_field('term_taxonomy_id', (int) $term['term_id'], ProductTermFactory::TAXONOMY_CATEGORY);

        (new CouponFactory())->create([
            'code'       => 'CAT-EXCLUDED',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [
                'excluded_categories' => [$taxTermId],
            ],
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/checkProductEligibility', [
            'productId'      => (int) $product->ID,
            'appliedCoupons' => ['CAT-EXCLUDED'],
            'origin'         => 'admin',
        ]);

        Assert::assertSame(
            200,
            $status,
            'check eligibility for excluded-category product must return 200. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey(
            'isApplicable',
            (array) $body,
            'response must surface an isApplicable verdict. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            false,
            $body['isApplicable'] ?? null,
            'product whose category is in conditions.excluded_categories must report ineligible (strict bool false). Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [102]
     *   "Checking a product listed in a coupon's excluded-products restriction reports the product as ineligible for that coupon."
     */
    public function checkingProductInExcludedProductsReportsIneligible(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();

        $product = (new ProductFactory())->create();
        (new CouponFactory())->create([
            'code'       => 'PROD-EXCLUDED',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [
                'excluded_products' => [(int) $product->ID],
            ],
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/checkProductEligibility', [
            'productId'      => (int) $product->ID,
            'appliedCoupons' => ['PROD-EXCLUDED'],
            'origin'         => 'admin',
        ]);

        Assert::assertSame(
            200,
            $status,
            'check eligibility for excluded-products listed product must return 200. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey(
            'isApplicable',
            (array) $body,
            'response must surface an isApplicable verdict. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            false,
            $body['isApplicable'] ?? null,
            'product listed in conditions.excluded_products must report ineligible (strict bool false). Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [115]
     *   "Checking a product that is not on a coupon's included-products list reports the product as ineligible for that coupon."
     */
    public function checkingProductNotInIncludedProductsReportsIneligible(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();

        $allowedProduct = (new ProductFactory())->create();
        $otherProduct = (new ProductFactory())->create();

        (new CouponFactory())->create([
            'code'       => 'INCLUDED-ONLY',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [
                'included_products' => [(int) $allowedProduct->ID],
            ],
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/checkProductEligibility', [
            'productId'      => (int) $otherProduct->ID,
            'appliedCoupons' => ['INCLUDED-ONLY'],
            'origin'         => 'admin',
        ]);

        Assert::assertSame(
            200,
            $status,
            'check eligibility for non-included product must return 200. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey(
            'isApplicable',
            (array) $body,
            'response must surface an isApplicable verdict. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            false,
            $body['isApplicable'] ?? null,
            'product not on conditions.included_products must report ineligible (strict bool false). Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [116]
     *   "Checking a product whose category is on a coupon's included-categories list reports the product as eligible for that coupon."
     */
    public function checkingProductWhoseCategoryIsIncludedReportsEligible(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();

        $product = (new ProductFactory())->create();
        $term = (new ProductTermFactory())->create(ProductTermFactory::TAXONOMY_CATEGORY);
        wp_set_object_terms((int) $product->ID, [(int) $term['term_id']], ProductTermFactory::TAXONOMY_CATEGORY);
        $taxTermId = (int) get_term_field('term_taxonomy_id', (int) $term['term_id'], ProductTermFactory::TAXONOMY_CATEGORY);

        (new CouponFactory())->create([
            'code'       => 'CAT-INCLUDED',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [
                'included_categories' => [$taxTermId],
            ],
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/checkProductEligibility', [
            'productId'      => (int) $product->ID,
            'appliedCoupons' => ['CAT-INCLUDED'],
            'origin'         => 'admin',
        ]);

        Assert::assertSame(
            200,
            $status,
            'check eligibility for included-category product must return 200. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey(
            'isApplicable',
            (array) $body,
            'response must surface an isApplicable verdict. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            true,
            $body['isApplicable'] ?? null,
            'product whose category is on conditions.included_categories must report eligible (strict bool true). Body: ' . wp_json_encode($body)
        );
    }
}
