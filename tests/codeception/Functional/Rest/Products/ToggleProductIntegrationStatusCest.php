<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductMeta;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ProductMetaFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\RegistersStubProductIntegration;

/**
 * Spec: Cases/Functional/products.md → "Managing product integrations" section, items 162, 163.
 *
 * Route under test:
 *   POST /fluent-cart/v2/products/{product_id}/integrations/feed/change-status
 *   meta(['permissions' => 'products/edit']) → ProductIntegrationsController::changeStatus
 */
class ToggleProductIntegrationStatusCest
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
     * Spec: Cases/Functional/products.md [162]
     *   "Admin can enable an integration feed on a product without deleting it."
     */
    public function adminCanEnableIntegrationFeedOnProductWithoutDeletingIt(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'IntegEnable162']);
        $providerKey = $this->registerStubProductIntegration('stub_enable_162');

        $feed = (new ProductMetaFactory())->createIntegrationFeed(
            (int) $product->ID,
            $providerKey,
            ['name' => 'Toggle target 162'],
            false
        );
        $beforeRowCount = ProductMeta::query()
            ->where('object_id', $product->ID)
            ->where('object_type', 'product_integration')
            ->count();
        Assert::assertSame(1, $beforeRowCount, 'precondition: exactly one product_integration row must exist before the toggle');
        Assert::assertSame('no', (string) ($feed->meta_value['enabled'] ?? ''), 'precondition: persisted feed must start in disabled state so the toggle proves the flip');

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/integrations/feed/change-status', [
            'product_id'      => (int) $product->ID,
            'notification_id' => (int) $feed->id,
            'status'          => 'yes',
        ]);

        Assert::assertSame(200, $status, 'enable must return 200 after toggling the integration feed on');
        Assert::assertNotEmpty($body['message'] ?? '', 'enable response must surface a non-empty confirmation message');

        $afterRow = ProductMeta::query()->where('id', $feed->id)->first();
        Assert::assertNotNull($afterRow, 'enable must not delete the feed row; toggling status preserves the same fct_product_meta row');
        Assert::assertSame('yes', (string) ($afterRow->meta_value['enabled'] ?? ''), 'enable must flip persisted meta_value.enabled to "yes"');
        Assert::assertSame(
            $beforeRowCount,
            ProductMeta::query()->where('object_id', $product->ID)->where('object_type', 'product_integration')->count(),
            'enable must not change the count of product_integration rows for the product'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [163]
     *   "Admin can disable an integration feed on a product without deleting it."
     */
    public function adminCanDisableIntegrationFeedOnProductWithoutDeletingIt(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'IntegDisable163']);
        $providerKey = $this->registerStubProductIntegration('stub_disable_163');

        $feed = (new ProductMetaFactory())->createIntegrationFeed(
            (int) $product->ID,
            $providerKey,
            ['name' => 'Toggle target 163'],
            true
        );
        $beforeRowCount = ProductMeta::query()
            ->where('object_id', $product->ID)
            ->where('object_type', 'product_integration')
            ->count();
        Assert::assertSame(1, $beforeRowCount, 'precondition: exactly one product_integration row must exist before the toggle');
        Assert::assertSame('yes', (string) ($feed->meta_value['enabled'] ?? ''), 'precondition: persisted feed must start in enabled state so the toggle proves the flip');

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/integrations/feed/change-status', [
            'product_id'      => (int) $product->ID,
            'notification_id' => (int) $feed->id,
            'status'          => 'no',
        ]);

        Assert::assertSame(200, $status, 'disable must return 200 after toggling the integration feed off');
        Assert::assertNotEmpty($body['message'] ?? '', 'disable response must surface a non-empty confirmation message');

        $afterRow = ProductMeta::query()->where('id', $feed->id)->first();
        Assert::assertNotNull($afterRow, 'disable must not delete the feed row; toggling status preserves the same fct_product_meta row');
        Assert::assertSame('no', (string) ($afterRow->meta_value['enabled'] ?? ''), 'disable must flip persisted meta_value.enabled to "no"');
        Assert::assertSame(
            $beforeRowCount,
            ProductMeta::query()->where('object_id', $product->ID)->where('object_type', 'product_integration')->count(),
            'disable must not change the count of product_integration rows for the product'
        );
    }
}
