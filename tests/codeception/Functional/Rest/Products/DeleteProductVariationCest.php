<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\Activity;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ProductMetaFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Working with variations" section, items 94, 97, 100.
 *
 * Action-named Cest covering DELETE /fluent-cart/v2/products/variants/{variantId}.
 * Variation-delete permission tests (manager allowed, accountant forbidden) live next
 * to the happy-path method, mirroring DeleteProductCest.
 */
class DeleteProductVariationCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [94]
     *   "Admin can delete a variation."
     */
    public function adminCanDeleteVariation(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        // Two-variant fixture so the delete cannot remove the last row of the product
        // (resource short-circuits on order-attached rows; we need a variation that
        // is genuinely deletable to prove the happy path).
        $product = (new ProductFactory())->createWithVariants(
            [
                ['variation_title' => 'Keep94'],
                ['variation_title' => 'Drop94'],
            ],
            ['post_title' => 'Delete94']
        );
        $variants = $product->variants;
        $drop = $variants->firstWhere('variation_title', 'Drop94');
        $keep = $variants->firstWhere('variation_title', 'Keep94');
        Assert::assertNotNull($drop, 'fixture must include the variant marked for deletion');
        Assert::assertNotNull($keep, 'fixture must include the variant that should survive deletion');
        // Seed a media row so the cascading media()->delete() inside the resource
        // is exercised (the resource removes thumbnails alongside the variation row).
        (new ProductMetaFactory())->create((int) $drop->id, [
            'object_type' => 'product_variant_info',
            'meta_key'    => 'product_thumbnail',
            'meta_value'  => [['id' => 9001, 'url' => 'https://example.test/del94.jpg', 'title' => 'Doomed']],
        ]);

        [$status, $body] = $I->delete('/fluent-cart/v2/products/variants/' . $drop->id);

        $I->seeResponseCodeIs(200);
        Assert::assertNull(
            ProductVariation::query()->find($drop->id),
            'fct_product_variations row must be removed after DELETE /products/variants/{id}'
        );
        Assert::assertNotNull(
            ProductVariation::query()->find($keep->id),
            'unrelated variation must be preserved (delete must be targeted, not table-wide)'
        );
        $remainingMedia = ProductMeta::query()
            ->where('object_id', $drop->id)
            ->where('meta_key', 'product_thumbnail')
            ->count();
        Assert::assertSame(0, (int) $remainingMedia, 'cascading delete must remove the variant\'s thumbnail meta row(s) too');
        $log = Activity::query()
            ->where('module_name', 'Product')
            ->where('status', 'success')
            ->where('title', 'Pricing deleted')
            ->orderBy('id', 'DESC')
            ->first();
        Assert::assertNotNull($log, 'fct_activity must contain a success entry titled "Pricing deleted" after variation delete (logged via fluent_cart_success_log in ProductVariationResource::delete)');
        Assert::assertStringContainsString('Drop94', (string) $log->content, 'activity log content must reference the deleted variation title (Drop94)');
    }

    /**
     * Spec: Cases/Functional/products.md [97]
     *   "A store manager with the "delete products" permission can delete a variation."
     */
    public function storeManagerWithDeletePermissionCanDeleteVariation(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->createWithVariants(
            [
                ['variation_title' => 'KeepMgr97'],
                ['variation_title' => 'DropMgr97'],
            ],
            ['post_title' => 'Manager97']
        );
        $drop = $product->variants->firstWhere('variation_title', 'DropMgr97');
        Assert::assertNotNull($drop, 'fixture must include the manager-targeted variation');
        $I->loginAsManager();

        [$status, $body] = $I->delete('/fluent-cart/v2/products/variants/' . $drop->id);

        $I->seeResponseCodeIs(200);
        Assert::assertNull(
            ProductVariation::query()->find($drop->id),
            'manager with products/delete must be able to remove the variation row'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [100]
     *   "An accountant cannot delete a variation."
     */
    public function accountantCannotDeleteVariation(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->createWithVariants(
            [
                ['variation_title' => 'KeepAcct100'],
                ['variation_title' => 'DropAcct100'],
            ],
            ['post_title' => 'Acct100']
        );
        $drop = $product->variants->firstWhere('variation_title', 'DropAcct100');
        Assert::assertNotNull($drop, 'fixture must include the accountant-targeted variation');
        $I->loginAsAccountant();

        [$status, $body] = $I->delete('/fluent-cart/v2/products/variants/' . $drop->id);

        Assert::assertSame(403, $status, 'accountant must be rejected with 403 from DELETE /products/variants/{id}');
        Assert::assertSame('rest_forbidden', (string) ($body['code'] ?? ''), 'WP REST permission_callback denial must surface the rest_forbidden code on the body');
        Assert::assertNotEmpty($body['message'] ?? '', 'rejected variant delete must include a permission-denied message');
        Assert::assertNotNull(
            ProductVariation::query()->find($drop->id),
            'rejected delete must leave the fct_product_variations row in place'
        );
    }
}
