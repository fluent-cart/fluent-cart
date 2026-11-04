<?php

namespace Tests\Functional\Rest\Products;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\RegistersStubProductIntegration;

/**
 * Spec: Cases/Functional/products.md → "Managing product integrations" section, item 158.
 *
 * Route under test:
 *   GET /fluent-cart/v2/products/{product_id}/integrations/{integration_name}/settings
 *   meta(['permissions' => 'products/view']) → ProductIntegrationsController::getProductIntegrationSettings
 */
class GetProductIntegrationSettingsCest
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
     * Spec: Cases/Functional/products.md [158]
     *   "Admin can open the settings panel for a specific product-scoped
     *    integration on a product."
     */
    public function adminCanOpenSettingsPanelForSpecificProductScopedIntegrationOnAProduct(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'IntegSettings158']);

        $stubFields = [
            'fields' => [
                ['key' => 'name', 'label' => 'Feed Name', 'required' => true],
                ['key' => 'list_id', 'label' => 'List ID', 'required' => false],
            ],
        ];
        $providerKey = $this->registerStubProductIntegration(
            'stub_settings_158',
            ['title' => 'Settings 158', 'scopes' => ['product'], 'enabled' => true],
            $stubFields
        );

        [, $body] = $I->get('/fluent-cart/v2/products/' . $product->ID . '/integrations/' . $providerKey . '/settings');

        $I->seeResponseCodeIs(200);
        Assert::assertSame('product', $body['scope'] ?? null, 'response scope must be product so the editor renders product-only inputs');
        Assert::assertArrayHasKey('settings', $body, 'response must surface a settings payload to seed the form');
        Assert::assertArrayHasKey('settings_fields', $body, 'response must surface the integration-specific settings_fields schema');
        Assert::assertSame($stubFields, $body['settings_fields'], 'settings_fields must echo the schema returned by the provider filter');
        Assert::assertArrayHasKey('product_variations', $body, 'response must surface the product variations for conditional targeting');
        Assert::assertIsArray($body['product_variations'], 'product_variations must be an array');
        $variants = $product->variants;
        Assert::assertGreaterThan(0, $variants->count(), 'precondition: ProductFactory must seed at least one variation so we can assert it surfaces in the response');
        Assert::assertCount(
            $variants->count(),
            $body['product_variations'],
            'product_variations must contain exactly one entry per persisted variation on the requested product'
        );
        $expectedVariation = $variants->first();
        $variationIds = array_map('intval', array_column($body['product_variations'], 'id'));
        Assert::assertContains((int) $expectedVariation->id, $variationIds, 'product_variations must include the persisted variation id for the requested product');
        $variationTitlesById = [];
        foreach ($body['product_variations'] as $variation) {
            $variationTitlesById[(int) ($variation['id'] ?? 0)] = (string) ($variation['title'] ?? '');
        }
        Assert::assertSame(
            (string) $expectedVariation->variation_title,
            $variationTitlesById[(int) $expectedVariation->id] ?? '',
            'product_variations must surface the persisted variation_title for the requested product\'s variation'
        );
        Assert::assertArrayHasKey('settings', $body, 'response must include a settings array for the editor');
        Assert::assertArrayHasKey('conditional_variation_ids', $body['settings'], 'settings must default conditional_variation_ids so the form binds without a key error');
        Assert::assertSame([], $body['settings']['conditional_variation_ids'], 'a brand-new feed must default conditional_variation_ids to an empty array');
    }
}
