<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductMeta;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ProductMetaFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\RegistersStubProductIntegration;

/**
 * Spec: Cases/Functional/products.md → "Managing product integrations" section, items 167-174.
 *
 * Per-action permission denials for the four product-integration write routes:
 *   POST   /products/{product_id}/integrations                       (perm products/edit)
 *   POST   /products/{product_id}/integrations/feed/change-status    (perm products/edit)
 *   DELETE /products/{product_id}/integrations/{integration_id}      (perm products/delete)
 */
class ProductIntegrationsPermissionsCest
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
     * Spec: Cases/Functional/products.md [167]
     *   "A worker without the "edit products" permission cannot save a new integration feed."
     */
    public function workerWithoutEditPermissionCannotSaveNewIntegrationFeed(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'WorkerSave167']);
        $providerKey = $this->registerStubProductIntegration('stub_worker_save_167');
        $I->loginAsWorker();

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/integrations', [
            'integration_name' => $providerKey,
            'integration'      => json_encode([
                'name'    => 'Should not persist',
                'enabled' => 'yes',
                'list_id' => 'L167',
            ]),
        ]);

        Assert::assertSame(403, $status, 'worker without products/edit must be rejected with 403 when saving a new integration feed');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        Assert::assertSame(
            0,
            ProductMeta::query()->where('object_id', $product->ID)->where('object_type', 'product_integration')->count(),
            'rejected save must not persist any product_integration row'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [168]
     *   "A worker without the "edit products" permission cannot update an existing integration feed."
     */
    public function workerWithoutEditPermissionCannotUpdateExistingIntegrationFeed(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'WorkerUpdate168']);
        $providerKey = $this->registerStubProductIntegration('stub_worker_update_168');
        $existing = (new ProductMetaFactory())->createIntegrationFeed(
            (int) $product->ID,
            $providerKey,
            ['name' => 'Original 168', 'list_id' => 'KEEP-168'],
            true
        );
        $I->loginAsWorker();

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/integrations', [
            'integration_id'   => (int) $existing->id,
            'integration_name' => $providerKey,
            'integration'      => json_encode([
                'name'    => 'Should not overwrite',
                'enabled' => 'yes',
                'list_id' => 'OVERWRITE-168',
            ]),
        ]);

        Assert::assertSame(403, $status, 'worker without products/edit must be rejected with 403 when updating an integration feed');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');

        $row = ProductMeta::query()->where('id', $existing->id)->first();
        Assert::assertNotNull($row, 'rejected update must leave the existing fct_product_meta row in place');
        Assert::assertSame('KEEP-168', (string) ($row->meta_value['list_id'] ?? ''), 'rejected update must not overwrite the persisted list_id');
        Assert::assertSame('Original 168', (string) ($row->meta_value['name'] ?? ''), 'rejected update must not overwrite the persisted name');
    }

    /**
     * Spec: Cases/Functional/products.md [169]
     *   "A worker without the "edit products" permission cannot toggle the enabled state of an integration feed."
     */
    public function workerWithoutEditPermissionCannotToggleEnabledStateOfIntegrationFeed(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'WorkerToggle169']);
        $providerKey = $this->registerStubProductIntegration('stub_worker_toggle_169');
        $feed = (new ProductMetaFactory())->createIntegrationFeed(
            (int) $product->ID,
            $providerKey,
            ['name' => 'Toggle target 169'],
            false
        );
        $I->loginAsWorker();

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/integrations/feed/change-status', [
            'product_id'      => (int) $product->ID,
            'notification_id' => (int) $feed->id,
            'status'          => 'yes',
        ]);

        Assert::assertSame(403, $status, 'worker without products/edit must be rejected with 403 when toggling an integration feed');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');

        $row = ProductMeta::query()->where('id', $feed->id)->first();
        Assert::assertNotNull($row, 'rejected toggle must leave the persisted feed row in place');
        Assert::assertSame('no', (string) ($row->meta_value['enabled'] ?? ''), 'rejected toggle must not flip the persisted enabled flag');
    }

    /**
     * Spec: Cases/Functional/products.md [170]
     *   "A worker without the "delete products" permission cannot delete an integration feed."
     */
    public function workerWithoutDeletePermissionCannotDeleteIntegrationFeed(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'WorkerDelete170']);
        $providerKey = $this->registerStubProductIntegration('stub_worker_delete_170');
        $feed = (new ProductMetaFactory())->createIntegrationFeed(
            (int) $product->ID,
            $providerKey,
            ['name' => 'Survive worker 170'],
            true
        );
        $beforeMetaValue = ProductMeta::query()->where('id', $feed->id)->first()->meta_value;
        $I->loginAsWorker();

        [$status, $body] = $I->delete('/fluent-cart/v2/products/' . $product->ID . '/integrations/' . $feed->id);

        Assert::assertSame(403, $status, 'worker without products/delete must be rejected with 403 when deleting an integration feed');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $afterRow = ProductMeta::query()->where('id', $feed->id)->first();
        Assert::assertNotNull($afterRow, 'rejected delete must leave the persisted feed row in place');
        Assert::assertSame((int) $product->ID, (int) $afterRow->object_id, 'rejected delete must not change the row\'s object_id');
        Assert::assertSame('product_integration', (string) $afterRow->object_type, 'rejected delete must not change the row\'s object_type');
        Assert::assertSame($providerKey, (string) $afterRow->meta_key, 'rejected delete must not change the row\'s meta_key');
        Assert::assertSame($beforeMetaValue, $afterRow->meta_value, 'rejected delete must leave meta_value byte-identical to the pre-request state');
    }

    /**
     * Spec: Cases/Functional/products.md [171]
     *   "An accountant cannot save a new integration feed."
     */
    public function accountantCannotSaveNewIntegrationFeed(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'AccountantSave171']);
        $providerKey = $this->registerStubProductIntegration('stub_accountant_save_171');
        $I->loginAsAccountant();

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/integrations', [
            'integration_name' => $providerKey,
            'integration'      => json_encode([
                'name'    => 'Should not persist',
                'enabled' => 'yes',
                'list_id' => 'L171',
            ]),
        ]);

        Assert::assertSame(403, $status, 'accountant must be rejected with 403 when saving a new integration feed');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        Assert::assertSame(
            0,
            ProductMeta::query()->where('object_id', $product->ID)->where('object_type', 'product_integration')->count(),
            'rejected save must not persist any product_integration row'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [172]
     *   "An accountant cannot update an existing integration feed."
     */
    public function accountantCannotUpdateExistingIntegrationFeed(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'AccountantUpdate172']);
        $providerKey = $this->registerStubProductIntegration('stub_accountant_update_172');
        $existing = (new ProductMetaFactory())->createIntegrationFeed(
            (int) $product->ID,
            $providerKey,
            ['name' => 'Original 172', 'list_id' => 'KEEP-172'],
            true
        );
        $I->loginAsAccountant();

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/integrations', [
            'integration_id'   => (int) $existing->id,
            'integration_name' => $providerKey,
            'integration'      => json_encode([
                'name'    => 'Should not overwrite',
                'enabled' => 'yes',
                'list_id' => 'OVERWRITE-172',
            ]),
        ]);

        Assert::assertSame(403, $status, 'accountant must be rejected with 403 when updating an integration feed');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');

        $row = ProductMeta::query()->where('id', $existing->id)->first();
        Assert::assertNotNull($row, 'rejected update must leave the existing fct_product_meta row in place');
        Assert::assertSame('KEEP-172', (string) ($row->meta_value['list_id'] ?? ''), 'rejected update must not overwrite the persisted list_id');
        Assert::assertSame('Original 172', (string) ($row->meta_value['name'] ?? ''), 'rejected update must not overwrite the persisted name');
    }

    /**
     * Spec: Cases/Functional/products.md [173]
     *   "An accountant cannot toggle the enabled state of an integration feed."
     */
    public function accountantCannotToggleEnabledStateOfIntegrationFeed(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'AccountantToggle173']);
        $providerKey = $this->registerStubProductIntegration('stub_accountant_toggle_173');
        $feed = (new ProductMetaFactory())->createIntegrationFeed(
            (int) $product->ID,
            $providerKey,
            ['name' => 'Toggle target 173'],
            true
        );
        $I->loginAsAccountant();

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/integrations/feed/change-status', [
            'product_id'      => (int) $product->ID,
            'notification_id' => (int) $feed->id,
            'status'          => 'no',
        ]);

        Assert::assertSame(403, $status, 'accountant must be rejected with 403 when toggling an integration feed');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');

        $row = ProductMeta::query()->where('id', $feed->id)->first();
        Assert::assertNotNull($row, 'rejected toggle must leave the persisted feed row in place');
        Assert::assertSame('yes', (string) ($row->meta_value['enabled'] ?? ''), 'rejected toggle must not flip the persisted enabled flag');
    }

    /**
     * Spec: Cases/Functional/products.md [174]
     *   "An accountant cannot delete an integration feed."
     */
    public function accountantCannotDeleteIntegrationFeed(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'AccountantDelete174']);
        $providerKey = $this->registerStubProductIntegration('stub_accountant_delete_174');
        $feed = (new ProductMetaFactory())->createIntegrationFeed(
            (int) $product->ID,
            $providerKey,
            ['name' => 'Survive accountant 174'],
            true
        );
        $beforeMetaValue = ProductMeta::query()->where('id', $feed->id)->first()->meta_value;
        $I->loginAsAccountant();

        [$status, $body] = $I->delete('/fluent-cart/v2/products/' . $product->ID . '/integrations/' . $feed->id);

        Assert::assertSame(403, $status, 'accountant must be rejected with 403 when deleting an integration feed');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $afterRow = ProductMeta::query()->where('id', $feed->id)->first();
        Assert::assertNotNull($afterRow, 'rejected delete must leave the persisted feed row in place');
        Assert::assertSame((int) $product->ID, (int) $afterRow->object_id, 'rejected delete must not change the row\'s object_id');
        Assert::assertSame('product_integration', (string) $afterRow->object_type, 'rejected delete must not change the row\'s object_type');
        Assert::assertSame($providerKey, (string) $afterRow->meta_key, 'rejected delete must not change the row\'s meta_key');
        Assert::assertSame($beforeMetaValue, $afterRow->meta_value, 'rejected delete must leave meta_value byte-identical to the pre-request state');
    }
}
