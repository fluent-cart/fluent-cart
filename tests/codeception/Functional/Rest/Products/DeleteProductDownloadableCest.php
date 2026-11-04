<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductDownload;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductDownloadFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Managing downloadable files" section, item 114.
 */
class DeleteProductDownloadableCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [114]
     *   "Admin can delete a downloadable file from a product."
     */
    public function adminCanDeleteDownloadableFileFromProduct(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Delete114']);
        $factory = new ProductDownloadFactory();
        $target = $factory->create((int) $product->ID, ['title' => 'TargetFile']);
        $sibling = $factory->create((int) $product->ID, ['title' => 'SiblingFile']);

        Assert::assertSame(2, ProductDownload::query()->where('post_id', $product->ID)->count(), 'precondition: two downloadable rows must exist before delete');

        [$status, $body] = $I->delete('/fluent-cart/v2/products/' . $target->id . '/delete');

        $I->seeResponseCodeIs(200);
        Assert::assertSame(0, ProductDownload::query()->where('id', $target->id)->count(), 'targeted downloadable row must be removed from fct_product_downloads');
        Assert::assertSame(1, ProductDownload::query()->where('id', $sibling->id)->count(), 'sibling downloadable row must remain after a targeted delete');
    }
}
