<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductMeta;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ProductMetaFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\RegistersStubProductIntegration;

/**
 * Spec: Cases/Functional/products.md → "Managing product integrations" section, item 164.
 *
 * Route under test:
 *   DELETE /fluent-cart/v2/products/{product_id}/integrations/{integration_id}
 *   meta(['permissions' => 'products/delete']) → ProductIntegrationsController::deleteProductIntegration
 */
class DeleteProductIntegrationCest
{
    use RegistersStubProductIntegration;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    public function _after(FunctionalTester $I): void
    {
        $this->unregisterStubProductIntegrations();
    }

    /**
     * Spec: Cases/Functional/products.md [164]
     *   "Admin can delete an integration feed from a product."
     */
    public function adminCanDeleteAnIntegrationFeedFromAProduct(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'IntegDelete164']);
        $providerKey = $this->registerStubProductIntegration('stub_delete_164');

        $target = (new ProductMetaFactory())->createIntegrationFeed(
            (int) $product->ID,
            $providerKey,
            ['name' => 'To be deleted 164'],
            true
        );
        $survivor = (new ProductMetaFactory())->createIntegrationFeed(
            (int) $product->ID,
            'stub_other_164',
            ['name' => 'Should survive 164'],
            true
        );

        Assert::assertSame(
            2,
            ProductMeta::query()->where('object_id', $product->ID)->where('object_type', 'product_integration')->count(),
            'precondition: two product_integration rows must exist so we can prove only the target is removed'
        );

        [, $body] = $I->delete('/fluent-cart/v2/products/' . $product->ID . '/integrations/' . $target->id);

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty($body['message'] ?? '', 'delete response must surface a non-empty confirmation message');

        Assert::assertNull(
            ProductMeta::query()->where('id', $target->id)->first(),
            'deleted feed row must no longer exist in fct_product_meta'
        );
        Assert::assertNotNull(
            ProductMeta::query()->where('id', $survivor->id)->first(),
            'unrelated feed row on the same product must remain intact after delete'
        );
        Assert::assertSame(
            1,
            ProductMeta::query()->where('object_id', $product->ID)->where('object_type', 'product_integration')->count(),
            'exactly one product_integration row must remain for this product after delete'
        );
    }
}
