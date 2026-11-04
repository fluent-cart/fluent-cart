<?php

namespace Tests\Functional\Rest\Products;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Listing and searching products" section, item 226.
 */
class ListProductVariationsCest
{
    /**
     * Spec: Cases/Functional/products.md [226]
     *   "Admin can request the product variations listing without supplying any
     *    query parameters and receives an empty list instead of a server error."
     *
     * Regression: ProductVariationController::index passed the absent `params`
     * query arg (null) straight into ProductVariationResource::get(array $params),
     * which threw a TypeError and produced a 500 (GitHub issue #11). The endpoint
     * must now respond 200 with a variants array even when called with no params.
     */
    public function adminCanListVariationsWithoutParamsWithoutServerError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        // Seed a product with variants so the table is non-empty; the no-params
        // request must still succeed (and, with no variant_ids filter, return an
        // empty list) rather than crash.
        (new ProductFactory())->createWithVariants([
            ['variation_title' => 'NoParamsVariantA'],
            ['variation_title' => 'NoParamsVariantB'],
        ], ['post_title' => 'NoParamsListingProduct']);

        [$status, $body] = $I->get('/fluent-cart/v2/products/variants');

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey(
            'variants',
            $body,
            'response must contain a variants key when called without params'
        );
        Assert::assertIsArray(
            $body['variants'],
            'variants value must be an array, not a server error, when no params are supplied'
        );
        // With no variant_ids filter the resource applies whereIn('id', []) which
        // compiles to `0 = 1`, so the listing is deterministically empty — the
        // endpoint degrades to an empty list rather than crashing or dumping every
        // variant in the store.
        Assert::assertCount(
            0,
            $body['variants'],
            'no-params listing must return an empty list, not every variant in the store'
        );
    }
}
