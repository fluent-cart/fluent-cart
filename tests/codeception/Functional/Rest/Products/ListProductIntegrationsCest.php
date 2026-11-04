<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductMeta;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ProductMetaFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\RegistersStubProductIntegration;

/**
 * Spec: Cases/Functional/products.md → "Managing product integrations" section, items 156-157.
 *
 * Route under test:
 *   GET /fluent-cart/v2/products/{productId}/integrations
 *   meta(['permissions' => 'products/view']) → ProductIntegrationsController::getFeeds
 */
class ListProductIntegrationsCest
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
     * Spec: Cases/Functional/products.md [156]
     *   "Admin can list the integration feeds configured on a product."
     */
    public function adminCanListIntegrationFeedsConfiguredOnProduct(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'IntegList156']);
        $otherProduct = (new ProductFactory())->create(['post_title' => 'IntegList156Other']);

        $this->registerStubProductIntegration('stub_alpha_156', ['title' => 'Stub Alpha 156']);
        $this->registerStubProductIntegration('stub_beta_156', ['title' => 'Stub Beta 156']);
        $this->registerStubProductIntegration('stub_foreign_156', ['title' => 'Stub Foreign 156']);

        $alpha = (new ProductMetaFactory())->createIntegrationFeed(
            (int) $product->ID,
            'stub_alpha_156',
            ['name' => 'Alpha feed'],
            true
        );
        $beta = (new ProductMetaFactory())->createIntegrationFeed(
            (int) $product->ID,
            'stub_beta_156',
            ['name' => 'Beta feed'],
            false
        );
        $foreign = (new ProductMetaFactory())->createIntegrationFeed(
            (int) $otherProduct->ID,
            'stub_foreign_156',
            ['name' => 'Foreign product feed'],
            true
        );

        [, $body] = $I->get('/fluent-cart/v2/products/' . $product->ID . '/integrations');

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('feeds', $body, 'list response must include a feeds key');

        $feeds = $body['feeds'];
        Assert::assertCount(2, $feeds, 'list must return exactly the two configured feeds for this product');

        $byProvider = [];
        foreach ($feeds as $feed) {
            Assert::assertArrayHasKey('id', $feed, 'each feed entry must expose its meta row id');
            Assert::assertArrayHasKey('provider', $feed, 'each feed entry must expose its provider key');
            Assert::assertArrayHasKey('enabled', $feed, 'each feed entry must expose its enabled flag');
            Assert::assertArrayHasKey('name', $feed, 'each feed entry must expose its display name');
            Assert::assertArrayHasKey('feed', $feed, 'each feed entry must expose its raw feed payload');
            Assert::assertSame('product', $feed['scope'] ?? null, 'feed scope must be product on this endpoint');
            $byProvider[$feed['provider']] = $feed;
        }

        Assert::assertArrayHasKey('stub_alpha_156', $byProvider, 'configured stub_alpha_156 feed must appear in the list');
        Assert::assertArrayHasKey('stub_beta_156', $byProvider, 'configured stub_beta_156 feed must appear in the list');
        Assert::assertArrayNotHasKey('stub_foreign_156', $byProvider, 'a feed configured on a different product must not leak into this product\'s integrations list');
        $listedFeedIds = array_column($feeds, 'id');
        Assert::assertNotContains((int) $foreign->id, array_map('intval', $listedFeedIds), 'the foreign product\'s feed row id must not appear in the requested product\'s response');
        Assert::assertSame((int) $alpha->id, (int) $byProvider['stub_alpha_156']['id'], 'alpha feed id must match its persisted meta row id');
        Assert::assertSame((int) $beta->id, (int) $byProvider['stub_beta_156']['id'], 'beta feed id must match its persisted meta row id');
        Assert::assertSame('Alpha feed', (string) $byProvider['stub_alpha_156']['name'], 'alpha feed name must echo the persisted display name');
        Assert::assertSame('yes', (string) $byProvider['stub_alpha_156']['enabled'], 'enabled stub_alpha_156 must surface "yes" status');
        Assert::assertSame('no', (string) $byProvider['stub_beta_156']['enabled'], 'disabled stub_beta_156 must surface "no" status');
    }

    /**
     * Spec: Cases/Functional/products.md [157]
     *   "Admin sees the integration feed list filtered to integrations that are
     *    enabled and apply to products."
     */
    public function adminSeesIntegrationFeedListFilteredToEnabledProductScopedIntegrations(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'IntegFilter157']);

        $this->registerStubProductIntegration('stub_visible_157', [
            'title'   => 'Visible 157',
            'scopes'  => ['product'],
            'enabled' => true,
        ]);
        $this->registerStubProductIntegration('stub_global_only_157', [
            'title'   => 'Global Only 157',
            'scopes'  => ['global'],
            'enabled' => true,
        ]);
        $this->registerStubProductIntegration('stub_disabled_157', [
            'title'   => 'Disabled 157',
            'scopes'  => ['product'],
            'enabled' => false,
        ]);

        (new ProductMetaFactory())->createIntegrationFeed((int) $product->ID, 'stub_visible_157', ['name' => 'Visible feed'], true);
        (new ProductMetaFactory())->createIntegrationFeed((int) $product->ID, 'stub_global_only_157', ['name' => 'Global only feed'], true);
        (new ProductMetaFactory())->createIntegrationFeed((int) $product->ID, 'stub_disabled_157', ['name' => 'Disabled feed'], true);

        Assert::assertSame(
            3,
            ProductMeta::query()->where('object_id', $product->ID)->where('object_type', 'product_integration')->count(),
            'precondition: three product_integration rows must be persisted before the GET so the filter can hide two of them'
        );

        [, $body] = $I->get('/fluent-cart/v2/products/' . $product->ID . '/integrations');

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('feeds', $body, 'list response must include a feeds key');
        Assert::assertArrayHasKey('available_integrations', $body, 'list response must include the available_integrations catalog');

        $providers = array_column($body['feeds'], 'provider');
        Assert::assertContains('stub_visible_157', $providers, 'product-scoped enabled integration must appear in the visible feeds');
        Assert::assertNotContains('stub_global_only_157', $providers, 'global-only integration must be filtered out of the product-scoped feed list');
        Assert::assertNotContains('stub_disabled_157', $providers, 'disabled integration must be filtered out even when product-scoped');

        $availableKeys = array_keys($body['available_integrations']);
        Assert::assertContains('stub_visible_157', $availableKeys, 'available_integrations must include product-scoped enabled providers');
        Assert::assertNotContains('stub_global_only_157', $availableKeys, 'available_integrations must exclude global-only providers');
        Assert::assertNotContains('stub_disabled_157', $availableKeys, 'available_integrations must exclude disabled providers');
    }
}
