<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductDetail;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\TaxClassFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Managing tax and shipping classes",
 * tax-class items 124-127 + 131-132 + 135-136.
 *
 * Routes (perm products/edit):
 *   POST /products/{postId}/tax-class         updateTaxClass
 *   POST /products/{postId}/tax-class/remove  removeTaxClass
 *
 * The controller stores the assignment as `other_info.tax_class` on the
 * fct_product_details row keyed by post_id. Removal re-saves the same JSON
 * column with the field set to an empty string.
 */
class ManageProductTaxClassCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [124]
     *   "Admin can assign a tax class to a product."
     */
    public function adminCanAssignTaxClassToProduct(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $taxClass = (new TaxClassFactory())->create(['title' => 'Reduced Tax 124']);
        $product  = (new ProductFactory())->create(['post_title' => 'AssignTax124']);
        Assert::assertSame('', (string) ($product->detail->other_info['tax_class'] ?? ''), 'fixture must start with no tax_class assignment so the route-applied delta is observable');

        [, $body] = $I->post('/fluent-cart/v2/products/' . (int) $product->ID . '/tax-class', [
            'tax_class' => (int) $taxClass->id,
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty($body['message'] ?? '', 'success response must include a confirmation message');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame((int) $taxClass->id, (int) ($detail->other_info['tax_class'] ?? 0), 'detail.other_info.tax_class must persist the assigned tax class id');
    }

    /**
     * Spec: Cases/Functional/products.md [125]
     *   "Admin sees a previously assigned tax class replaced when a new one is set."
     */
    public function adminSeesPreviouslyAssignedTaxClassReplacedWhenNewOneIsSet(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $oldTaxClass = (new TaxClassFactory())->create(['title' => 'Old Tax 125']);
        $newTaxClass = (new TaxClassFactory())->create(['title' => 'New Tax 125']);
        $product = (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['tax_class' => (int) $oldTaxClass->id]])
            ->create(['post_title' => 'ReplaceTax125']);
        Assert::assertSame((int) $oldTaxClass->id, (int) ($product->detail->other_info['tax_class'] ?? 0), 'fixture must start with the OLD tax class assigned so the replacement delta is observable');

        [, $body] = $I->post('/fluent-cart/v2/products/' . (int) $product->ID . '/tax-class', [
            'tax_class' => (int) $newTaxClass->id,
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty($body['message'] ?? '', 'success response must include a confirmation message');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame((int) $newTaxClass->id, (int) ($detail->other_info['tax_class'] ?? 0), 'detail.other_info.tax_class must replace the previous assignment with the new tax class id (proves no stale value lingers)');
        Assert::assertNotSame((int) $oldTaxClass->id, (int) ($detail->other_info['tax_class'] ?? 0), 'detail.other_info.tax_class must NOT still equal the old tax class id after replacement');
    }

    /**
     * Spec: Cases/Functional/products.md [126]
     *   "Admin can remove a product's tax class so it falls back to the store default."
     */
    public function adminCanRemoveProductTaxClassSoItFallsBackToStoreDefault(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $taxClass = (new TaxClassFactory())->create(['title' => 'Removable Tax 126']);
        $product = (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['tax_class' => (int) $taxClass->id]])
            ->create(['post_title' => 'RemoveTax126']);
        Assert::assertSame((int) $taxClass->id, (int) ($product->detail->other_info['tax_class'] ?? 0), 'fixture must start with a tax class assigned so the removal delta is observable');

        [, $body] = $I->post('/fluent-cart/v2/products/' . (int) $product->ID . '/tax-class/remove', []);

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty($body['message'] ?? '', 'success response must include a confirmation message');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        $stored = $detail->other_info['tax_class'] ?? null;
        Assert::assertSame('', (string) $stored, 'detail.other_info.tax_class must be cleared (empty string) so the product falls back to the store-default tax class');
    }

    /**
     * Spec: Cases/Functional/products.md [127]
     *   "Admin receives a clear "not found" error when assigning a tax class that does not exist."
     */
    public function adminReceivesNotFoundErrorAssigningMissingTaxClass(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'MissingTax127']);
        $missingTaxClassId = 999_999;
        Assert::assertSame(0, \FluentCart\App\Models\TaxClass::query()->where('id', $missingTaxClassId)->count(), 'fixture must guarantee the target tax class id does not exist before the request');
        $beforeOtherInfo = (array) ($product->detail->other_info ?? []);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . (int) $product->ID . '/tax-class', [
            'tax_class' => $missingTaxClassId,
        ]);

        $I->seeResponseCodeIs(404);
        $message = (string) ($body['message'] ?? ($body['data']['message'] ?? ''));
        Assert::assertNotEmpty($message, 'response must include a clear not-found message so the admin UI can surface a useful error');
        Assert::assertMatchesRegularExpression(
            '/(not\s+found|no\s+query\s+results)/i',
            $message,
            'message must communicate not-found semantics (literal "not found" or Eloquent\'s "No query results for model ...")'
        );
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame($beforeOtherInfo, (array) ($detail->other_info ?? []), 'rejected assign must not mutate detail.other_info — the existing value must remain unchanged');
    }

    /**
     * Spec: Cases/Functional/products.md [131]
     *   "A worker without the "edit products" permission cannot assign a tax class to a product."
     */
    public function workerWithoutEditProductsPermissionCannotAssignTaxClassToProduct(FunctionalTester $I): void
    {
        $taxClass = (new TaxClassFactory())->create(['title' => 'Worker Assign Tax 131']);
        $product  = (new ProductFactory())->create(['post_title' => 'WorkerAssignTax131']);
        $beforeOtherInfo = (array) ($product->detail->other_info ?? []);
        $I->loginAsWorker();

        [, $body] = $I->post('/fluent-cart/v2/products/' . (int) $product->ID . '/tax-class', [
            'tax_class' => (int) $taxClass->id,
        ]);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame($beforeOtherInfo, (array) ($detail->other_info ?? []), 'rejected assign must not mutate detail.other_info');
    }

    /**
     * Spec: Cases/Functional/products.md [132]
     *   "A worker without the "edit products" permission cannot remove a tax class from a product."
     */
    public function workerWithoutEditProductsPermissionCannotRemoveTaxClassFromProduct(FunctionalTester $I): void
    {
        $taxClass = (new TaxClassFactory())->create(['title' => 'Worker Remove Tax 132']);
        $product = (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['tax_class' => (int) $taxClass->id]])
            ->create(['post_title' => 'WorkerRemoveTax132']);
        $beforeOtherInfo = (array) ($product->detail->other_info ?? []);
        $I->loginAsWorker();

        [, $body] = $I->post('/fluent-cart/v2/products/' . (int) $product->ID . '/tax-class/remove', []);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame($beforeOtherInfo, (array) ($detail->other_info ?? []), 'rejected remove must not mutate detail.other_info — the tax_class must remain assigned');
        Assert::assertSame((int) $taxClass->id, (int) ($detail->other_info['tax_class'] ?? 0), 'tax_class must still equal the seeded tax class id after the rejected remove');
    }

    /**
     * Spec: Cases/Functional/products.md [135]
     *   "An accountant cannot assign a tax class to a product."
     */
    public function accountantCannotAssignTaxClassToProduct(FunctionalTester $I): void
    {
        $taxClass = (new TaxClassFactory())->create(['title' => 'Accountant Assign Tax 135']);
        $product  = (new ProductFactory())->create(['post_title' => 'AccountantAssignTax135']);
        $beforeOtherInfo = (array) ($product->detail->other_info ?? []);
        $I->loginAsAccountant();

        [, $body] = $I->post('/fluent-cart/v2/products/' . (int) $product->ID . '/tax-class', [
            'tax_class' => (int) $taxClass->id,
        ]);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame($beforeOtherInfo, (array) ($detail->other_info ?? []), 'rejected assign must not mutate detail.other_info');
    }

    /**
     * Spec: Cases/Functional/products.md [136]
     *   "An accountant cannot remove a tax class from a product."
     */
    public function accountantCannotRemoveTaxClassFromProduct(FunctionalTester $I): void
    {
        $taxClass = (new TaxClassFactory())->create(['title' => 'Accountant Remove Tax 136']);
        $product = (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['tax_class' => (int) $taxClass->id]])
            ->create(['post_title' => 'AccountantRemoveTax136']);
        $beforeOtherInfo = (array) ($product->detail->other_info ?? []);
        $I->loginAsAccountant();

        [, $body] = $I->post('/fluent-cart/v2/products/' . (int) $product->ID . '/tax-class/remove', []);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame($beforeOtherInfo, (array) ($detail->other_info ?? []), 'rejected remove must not mutate detail.other_info');
        Assert::assertSame((int) $taxClass->id, (int) ($detail->other_info['tax_class'] ?? 0), 'tax_class must still equal the seeded tax class id after the rejected remove');
    }
}
