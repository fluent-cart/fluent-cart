<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Managing inventory and stock", items 107-108.
 *
 * Per-action permission denials for inventory write routes:
 *   PUT /products/{postId}/update-inventory/{variantId} (perm products/edit)
 *   PUT /products/{postId}/update-manage-stock          (perm products/edit)
 *
 * Each role test exercises BOTH inventory write routes so a regression that
 * loses products/edit on either one is detected (single-route coverage would
 * leave the sibling route silently unprotected).
 */
class ManageProductStockPermissionsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [107]
     *   "A worker without the "edit products" permission cannot update inventory."
     */
    public function workerWithoutEditProductsPermissionCannotUpdateInventory(FunctionalTester $I): void
    {
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => 'in-stock'])
            ->withVariantAttributes(['manage_stock' => 1, 'stock_status' => 'in-stock', 'available' => 5, 'total_stock' => 5])
            ->create(['post_title' => 'WorkerInventory107']);
        $variant         = $product->variants->first();
        $variantBefore   = ProductVariation::query()->find($variant->id)->toArray();
        $detailBefore    = ProductDetail::query()->where('post_id', $product->ID)->first()->toArray();
        $I->loginAsWorker();

        // Route 1: PUT /products/{postId}/update-inventory/{variantId}
        [, $body] = $I->put(
            '/fluent-cart/v2/products/' . $product->ID . '/update-inventory/' . $variant->id,
            ['total_stock' => 999, 'available' => 999]
        );
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response (worker / update-inventory) must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response (worker / update-inventory) must include a permission-denied message');

        // Route 2: PUT /products/{postId}/update-manage-stock
        [, $body2] = $I->put(
            '/fluent-cart/v2/products/' . $product->ID . '/update-manage-stock',
            ['manage_stock' => 0]
        );
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body2['code'] ?? '', 'denial response (worker / update-manage-stock) must surface the rest_forbidden code');
        Assert::assertNotEmpty($body2['message'] ?? '', 'denial response (worker / update-manage-stock) must include a permission-denied message');

        // Side effect proof: nothing mutated.
        $variantAfter = ProductVariation::query()->find($variant->id)->toArray();
        $detailAfter  = ProductDetail::query()->where('post_id', $product->ID)->first()->toArray();
        unset($variantBefore['updated_at'], $variantAfter['updated_at'], $detailBefore['updated_at'], $detailAfter['updated_at']);
        Assert::assertSame($variantBefore, $variantAfter, 'rejected inventory writes must not mutate any column on fct_product_variations (full-row snapshot equality)');
        Assert::assertSame($detailBefore, $detailAfter, 'rejected inventory writes must not mutate any column on fct_product_details (full-row snapshot equality)');
    }

    /**
     * Spec: Cases/Functional/products.md [108]
     *   "An accountant cannot update inventory."
     */
    public function accountantCannotUpdateInventory(FunctionalTester $I): void
    {
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => 'in-stock'])
            ->withVariantAttributes(['manage_stock' => 1, 'stock_status' => 'in-stock', 'available' => 5, 'total_stock' => 5])
            ->create(['post_title' => 'AccountantInventory108']);
        $variant       = $product->variants->first();
        $variantBefore = ProductVariation::query()->find($variant->id)->toArray();
        $detailBefore  = ProductDetail::query()->where('post_id', $product->ID)->first()->toArray();
        $I->loginAsAccountant();

        [, $body] = $I->put(
            '/fluent-cart/v2/products/' . $product->ID . '/update-inventory/' . $variant->id,
            ['total_stock' => 999, 'available' => 999]
        );
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response (accountant / update-inventory) must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response (accountant / update-inventory) must include a permission-denied message');

        [, $body2] = $I->put(
            '/fluent-cart/v2/products/' . $product->ID . '/update-manage-stock',
            ['manage_stock' => 0]
        );
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body2['code'] ?? '', 'denial response (accountant / update-manage-stock) must surface the rest_forbidden code');
        Assert::assertNotEmpty($body2['message'] ?? '', 'denial response (accountant / update-manage-stock) must include a permission-denied message');

        $variantAfter = ProductVariation::query()->find($variant->id)->toArray();
        $detailAfter  = ProductDetail::query()->where('post_id', $product->ID)->first()->toArray();
        unset($variantBefore['updated_at'], $variantAfter['updated_at'], $detailBefore['updated_at'], $detailAfter['updated_at']);
        Assert::assertSame($variantBefore, $variantAfter, 'rejected inventory writes must not mutate any column on fct_product_variations (full-row snapshot equality)');
        Assert::assertSame($detailBefore, $detailAfter, 'rejected inventory writes must not mutate any column on fct_product_details (full-row snapshot equality)');
    }
}
