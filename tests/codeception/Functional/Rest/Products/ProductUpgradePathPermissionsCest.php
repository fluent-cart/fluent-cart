<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\Meta;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Managing upgrade paths", items 150-155.
 *
 * Per-action permission denials for upgrade-path write/delete routes:
 *   POST   /products/{id}/upgrade-path             perm products/edit
 *   POST   /products/upgrade-path/{id}/update      perm products/edit
 *   DELETE /products/upgrade-path/{id}/delete      perm products/delete
 */
class ProductUpgradePathPermissionsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [150]
     *   "A worker without the "edit products" permission cannot add an upgrade path."
     */
    public function workerWithoutEditProductsPermissionCannotAddUpgradePath(FunctionalTester $I): void
    {
        $fromProduct = (new ProductFactory())->create(['post_title' => 'WorkerAddUpgrade150From']);
        $toProduct   = (new ProductFactory())->create(['post_title' => 'WorkerAddUpgrade150To']);
        $fromVariantId = (int) $fromProduct->variants->first()->id;
        $toVariantId   = (int) $toProduct->variants->first()->id;
        Assert::assertSame(0, Meta::query()->where('object_type', 'variant_upgrade')->count(), 'fixture must start with no upgrade-path rows so we can prove a rejected request did not persist anything');
        $I->loginAsWorker();

        [, $body] = $I->post(
            '/fluent-cart/v2/products/' . (int) $fromProduct->ID . '/upgrade-path',
            [
                'from_variant'    => $fromVariantId,
                'to_variants'     => [$toVariantId],
                'is_prorate'      => 0,
                'discount_amount' => 5,
            ]
        );

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        Assert::assertSame(0, Meta::query()->where('object_type', 'variant_upgrade')->count(), 'rejected save-upgrade must not persist any fct_meta rows');
    }

    /**
     * Spec: Cases/Functional/products.md [151]
     *   "A worker without the "edit products" permission cannot update an upgrade path."
     */
    public function workerWithoutEditProductsPermissionCannotUpdateUpgradePath(FunctionalTester $I): void
    {
        $fromProduct = (new ProductFactory())->create(['post_title' => 'WorkerUpdateUpgrade151From']);
        $toProduct   = (new ProductFactory())->create(['post_title' => 'WorkerUpdateUpgrade151To']);
        $fromVariantId = (int) $fromProduct->variants->first()->id;
        $toVariantId   = (int) $toProduct->variants->first()->id;
        $meta = Meta::query()->create([
            'object_id'   => $fromVariantId,
            'object_type' => 'variant_upgrade',
            'meta_key'    => 'variant_upgrade_path',
            'meta_value'  => ['to_variants' => [$toVariantId], 'is_prorate' => 0, 'discount_amount' => 1],
        ]);
        $before = Meta::query()->find($meta->id)->toArray();
        $I->loginAsWorker();

        [, $body] = $I->post('/fluent-cart/v2/products/upgrade-path/' . (int) $meta->id . '/update', [
            'from_variant'    => $fromVariantId,
            'to_variants'     => [$toVariantId],
            'is_prorate'      => 1,
            'discount_amount' => 99,
        ]);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $after = Meta::query()->find($meta->id)->toArray();
        Assert::assertSame($before, $after, 'rejected upgrade-path update must not mutate any column on fct_meta — including updated_at — so a forbidden write that only bumps the timestamp cannot pass');
    }

    /**
     * Spec: Cases/Functional/products.md [152]
     *   "A worker without the "delete products" permission cannot delete an upgrade path."
     */
    public function workerWithoutDeleteProductsPermissionCannotDeleteUpgradePath(FunctionalTester $I): void
    {
        $fromProduct = (new ProductFactory())->create(['post_title' => 'WorkerDeleteUpgrade152From']);
        $toProduct   = (new ProductFactory())->create(['post_title' => 'WorkerDeleteUpgrade152To']);
        $fromVariantId = (int) $fromProduct->variants->first()->id;
        $toVariantId   = (int) $toProduct->variants->first()->id;
        $meta = Meta::query()->create([
            'object_id'   => $fromVariantId,
            'object_type' => 'variant_upgrade',
            'meta_key'    => 'variant_upgrade_path',
            'meta_value'  => ['to_variants' => [$toVariantId], 'is_prorate' => 0, 'discount_amount' => 1],
        ]);
        $I->loginAsWorker();

        [, $body] = $I->delete('/fluent-cart/v2/products/upgrade-path/' . (int) $meta->id . '/delete');

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        Assert::assertSame(1, Meta::query()->where('id', $meta->id)->count(), 'rejected delete must leave the fct_meta row in place');
    }

    /**
     * Spec: Cases/Functional/products.md [153]
     *   "An accountant cannot add an upgrade path."
     */
    public function accountantCannotAddUpgradePath(FunctionalTester $I): void
    {
        $fromProduct = (new ProductFactory())->create(['post_title' => 'AccountantAddUpgrade153From']);
        $toProduct   = (new ProductFactory())->create(['post_title' => 'AccountantAddUpgrade153To']);
        $fromVariantId = (int) $fromProduct->variants->first()->id;
        $toVariantId   = (int) $toProduct->variants->first()->id;
        Assert::assertSame(0, Meta::query()->where('object_type', 'variant_upgrade')->count(), 'fixture must start with no upgrade-path rows');
        $I->loginAsAccountant();

        [, $body] = $I->post(
            '/fluent-cart/v2/products/' . (int) $fromProduct->ID . '/upgrade-path',
            [
                'from_variant'    => $fromVariantId,
                'to_variants'     => [$toVariantId],
                'is_prorate'      => 0,
                'discount_amount' => 5,
            ]
        );

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        Assert::assertSame(0, Meta::query()->where('object_type', 'variant_upgrade')->count(), 'rejected save-upgrade must not persist any fct_meta rows');
    }

    /**
     * Spec: Cases/Functional/products.md [154]
     *   "An accountant cannot update an upgrade path."
     */
    public function accountantCannotUpdateUpgradePath(FunctionalTester $I): void
    {
        $fromProduct = (new ProductFactory())->create(['post_title' => 'AccountantUpdateUpgrade154From']);
        $toProduct   = (new ProductFactory())->create(['post_title' => 'AccountantUpdateUpgrade154To']);
        $fromVariantId = (int) $fromProduct->variants->first()->id;
        $toVariantId   = (int) $toProduct->variants->first()->id;
        $meta = Meta::query()->create([
            'object_id'   => $fromVariantId,
            'object_type' => 'variant_upgrade',
            'meta_key'    => 'variant_upgrade_path',
            'meta_value'  => ['to_variants' => [$toVariantId], 'is_prorate' => 0, 'discount_amount' => 1],
        ]);
        $before = Meta::query()->find($meta->id)->toArray();
        $I->loginAsAccountant();

        [, $body] = $I->post('/fluent-cart/v2/products/upgrade-path/' . (int) $meta->id . '/update', [
            'from_variant'    => $fromVariantId,
            'to_variants'     => [$toVariantId],
            'is_prorate'      => 1,
            'discount_amount' => 99,
        ]);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $after = Meta::query()->find($meta->id)->toArray();
        Assert::assertSame($before, $after, 'rejected upgrade-path update must not mutate any column on fct_meta — including updated_at — so a forbidden write that only bumps the timestamp cannot pass');
    }

    /**
     * Spec: Cases/Functional/products.md [155]
     *   "An accountant cannot delete an upgrade path."
     */
    public function accountantCannotDeleteUpgradePath(FunctionalTester $I): void
    {
        $fromProduct = (new ProductFactory())->create(['post_title' => 'AccountantDeleteUpgrade155From']);
        $toProduct   = (new ProductFactory())->create(['post_title' => 'AccountantDeleteUpgrade155To']);
        $fromVariantId = (int) $fromProduct->variants->first()->id;
        $toVariantId   = (int) $toProduct->variants->first()->id;
        $meta = Meta::query()->create([
            'object_id'   => $fromVariantId,
            'object_type' => 'variant_upgrade',
            'meta_key'    => 'variant_upgrade_path',
            'meta_value'  => ['to_variants' => [$toVariantId], 'is_prorate' => 0, 'discount_amount' => 1],
        ]);
        $I->loginAsAccountant();

        [, $body] = $I->delete('/fluent-cart/v2/products/upgrade-path/' . (int) $meta->id . '/delete');

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        Assert::assertSame(1, Meta::query()->where('id', $meta->id)->count(), 'rejected delete must leave the fct_meta row in place');
    }
}
