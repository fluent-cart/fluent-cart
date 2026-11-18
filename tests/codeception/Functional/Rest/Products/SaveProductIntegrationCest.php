<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductMeta;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ProductMetaFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\RegistersStubProductIntegration;

/**
 * Spec: Cases/Functional/products.md → "Managing product integrations" section, items 159, 160, 161, 165, 166.
 *
 * Route under test:
 *   POST /fluent-cart/v2/products/{product_id}/integrations
 *   meta(['permissions' => 'products/edit']) → ProductIntegrationsController::saveProductIntegration
 */
class SaveProductIntegrationCest
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
     * Spec: Cases/Functional/products.md [159]
     *   "Admin can save a new integration feed against a product with the chosen provider."
     */
    public function adminCanSaveNewIntegrationFeedAgainstProductWithChosenProvider(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'IntegSave159']);
        $providerKey = $this->registerStubProductIntegration('stub_save_159');

        Assert::assertSame(
            0,
            ProductMeta::query()->where('object_id', $product->ID)->where('object_type', 'product_integration')->count(),
            'precondition: no product_integration row should exist before the save'
        );

        [, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/integrations', [
            'integration_name' => $providerKey,
            'integration'      => json_encode([
                'name'    => 'New feed 159',
                'enabled' => 'yes',
                'list_id' => 'L159',
            ]),
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame($providerKey, $body['integration_name'] ?? null, 'response must echo the chosen provider key');
        Assert::assertTrue((bool) ($body['created'] ?? false), 'creating a new feed must surface a "created" flag of true');
        Assert::assertArrayHasKey('feedData', $body, 'response must surface the validated feed payload that was persisted');
        Assert::assertSame('L159', $body['feedData']['list_id'] ?? null, 'persisted feedData must echo the validated list_id submitted');

        $rows = ProductMeta::query()
            ->where('object_id', $product->ID)
            ->where('object_type', 'product_integration')
            ->where('meta_key', $providerKey)
            ->get();
        Assert::assertCount(1, $rows, 'save must persist exactly one fct_product_meta row for the chosen provider');
        $row = $rows->first();
        $stored = $row->meta_value;
        Assert::assertIsArray($stored, 'persisted meta_value must be a structured array, not a JSON string');
        Assert::assertSame('L159', $stored['list_id'] ?? null, 'persisted meta_value must contain the submitted list_id');
        Assert::assertSame('yes', (string) ($stored['enabled'] ?? ''), 'persisted meta_value must echo the submitted enabled flag');
    }

    /**
     * Spec: Cases/Functional/products.md [160]
     *   "Admin can update an existing integration feed on a product."
     */
    public function adminCanUpdateExistingIntegrationFeedOnProduct(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'IntegUpdate160']);
        $providerKey = $this->registerStubProductIntegration('stub_update_160');

        $existing = (new ProductMetaFactory())->createIntegrationFeed(
            (int) $product->ID,
            $providerKey,
            ['name' => 'Original feed', 'list_id' => 'OLD'],
            true
        );

        [, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/integrations', [
            'integration_id'   => (int) $existing->id,
            'integration_name' => $providerKey,
            'integration'      => json_encode([
                'name'    => 'Updated feed 160',
                'enabled' => 'yes',
                'list_id' => 'UPDATED-160',
            ]),
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertFalse((bool) ($body['created'] ?? true), 'updating an existing feed must not flag the response as newly created');
        Assert::assertSame((int) $existing->id, (int) ($body['integration_id'] ?? 0), 'response must echo the integration_id that was updated');

        $rows = ProductMeta::query()
            ->where('object_id', $product->ID)
            ->where('object_type', 'product_integration')
            ->get();
        Assert::assertCount(1, $rows, 'update must not create a duplicate row; the same fct_product_meta row is mutated in place');
        $row = $rows->first();
        Assert::assertSame((int) $existing->id, (int) $row->id, 'updated row must keep the original meta row id');
        $stored = $row->meta_value;
        Assert::assertSame('Updated feed 160', (string) ($stored['name'] ?? ''), 'updated meta_value must echo the new name');
        Assert::assertSame('UPDATED-160', (string) ($stored['list_id'] ?? ''), 'updated meta_value must echo the new list_id');
    }

    /**
     * Spec: Cases/Functional/products.md [161]
     *   "Admin sees previously saved integration settings replaced when a feed is updated."
     */
    public function adminSeesPreviouslySavedIntegrationSettingsReplacedWhenFeedIsUpdated(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'IntegReplace161']);
        $providerKey = $this->registerStubProductIntegration('stub_replace_161');

        $existing = (new ProductMetaFactory())->createIntegrationFeed(
            (int) $product->ID,
            $providerKey,
            ['name' => 'Stale name', 'list_id' => 'STALE-LIST'],
            true
        );
        $beforeStored = ProductMeta::query()->where('id', $existing->id)->first()->meta_value;
        Assert::assertSame('STALE-LIST', (string) ($beforeStored['list_id'] ?? ''), 'precondition: persisted list_id must be the stale value before update');

        [, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/integrations', [
            'integration_id'   => (int) $existing->id,
            'integration_name' => $providerKey,
            'integration'      => json_encode([
                'name'    => 'Fresh name',
                'enabled' => 'yes',
                'list_id' => 'FRESH-LIST',
            ]),
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame('FRESH-LIST', $body['feedData']['list_id'] ?? null, 'response feedData must surface the replacement list_id, not the prior one');

        $row = ProductMeta::query()->where('id', $existing->id)->first();
        $stored = $row->meta_value;
        Assert::assertSame('FRESH-LIST', (string) ($stored['list_id'] ?? ''), 'persisted list_id must be replaced by the new value, not merged with the stale one');
        Assert::assertSame('Fresh name', (string) ($stored['name'] ?? ''), 'persisted name must be replaced by the new value');
        Assert::assertNotSame('STALE-LIST', (string) ($stored['list_id'] ?? ''), 'replacement update must overwrite the prior list_id, not append a new entry');
    }

    /**
     * Spec: Cases/Functional/products.md [165]
     *   "Admin receives a clear "not found" error when saving an integration feed
     *    against a product that does not exist."
     */
    public function adminReceivesNotFoundErrorWhenSavingIntegrationAgainstNonExistentProduct(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $providerKey = $this->registerStubProductIntegration('stub_missing_165');

        $missingProductId = 999999;

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $missingProductId . '/integrations', [
            'integration_name' => $providerKey,
            'integration'      => json_encode([
                'name'    => 'Should not save',
                'enabled' => 'yes',
                'list_id' => 'L165',
            ]),
        ]);

        Assert::assertSame(422, $status, 'saving an integration against a non-existent product must be rejected with a 422');
        Assert::assertNotEmpty($body['message'] ?? '', 'rejection response must surface a non-empty error message');
        Assert::assertStringContainsStringIgnoringCase('not found', (string) ($body['message'] ?? ''), 'rejection message must clearly indicate the product was not found');

        Assert::assertSame(
            0,
            ProductMeta::query()->where('object_type', 'product_integration')->count(),
            'rejected save must not persist any product_integration meta row'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [166]
     *   "Admin is rejected when saving an integration feed referencing an unknown
     *    integration name."
     */
    public function adminIsRejectedWhenSavingIntegrationFeedReferencingUnknownIntegrationName(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'IntegUnknown166']);

        Assert::assertSame(
            0,
            ProductMeta::query()->where('object_id', $product->ID)->where('object_type', 'product_integration')->count(),
            'precondition: product must start with no product_integration rows so we can prove no row is created'
        );

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/integrations', [
            'integration_name' => 'totally_unknown_provider_166',
            'integration'      => json_encode([
                'name'    => 'Ghost feed',
                'enabled' => 'yes',
                'list_id' => 'L166',
            ]),
        ]);

        Assert::assertSame(422, $status, 'saving an integration with an unregistered provider key must be rejected with a 422');
        Assert::assertNotEmpty($body['message'] ?? '', 'rejection response must surface a non-empty error message');

        Assert::assertSame(
            0,
            ProductMeta::query()
                ->where('object_id', $product->ID)
                ->where('object_type', 'product_integration')
                ->count(),
            'rejected save must not persist any product_integration row for an unknown provider'
        );
    }

    /**
     * API callers may send the feed settings as a JSON object instead of a JSON string.
     */
    public function adminCanSaveIntegrationFeedSentAsAnObject(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'IntegObject']);
        $providerKey = $this->registerStubProductIntegration('stub_object');

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/integrations', [
            'integration_name' => $providerKey,
            'integration'      => [
                'name'    => 'Object feed',
                'enabled' => 'yes',
                'list_id' => 'LOBJ',
            ],
        ]);

        Assert::assertSame(200, $status, 'a feed sent as an object must save like one sent as a JSON string');
        Assert::assertSame('LOBJ', $body['feedData']['list_id'] ?? null);
        Assert::assertSame(
            1,
            ProductMeta::query()->where('object_id', $product->ID)->where('object_type', 'product_integration')->count()
        );
    }

    public function adminGetsAFieldErrorWhenIntegrationIsNotAnObject(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'IntegNotObject']);
        $providerKey = $this->registerStubProductIntegration('stub_not_object');

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/integrations', [
            'integration_name' => $providerKey,
            'integration'      => 'not json',
        ]);

        Assert::assertSame(422, $status, 'settings that are not an object must be rejected, not crash');
        Assert::assertArrayHasKey('integration', (array) ($body['errors'] ?? []));
        Assert::assertSame(
            0,
            ProductMeta::query()->where('object_id', $product->ID)->where('object_type', 'product_integration')->count()
        );
    }
}
