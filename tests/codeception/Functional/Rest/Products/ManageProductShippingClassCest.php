<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductDetail;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Managing tax and shipping classes",
 * shipping-class items 128-130 + 133-134 + 137-138.
 *
 * Routes (perm products/edit):
 *   POST /products/{postId}/shipping-class         updateShippingClass
 *   POST /products/{postId}/shipping-class/remove  removeShippingClass
 *
 * The controller stores the assignment as `other_info.shipping_class` on the
 * fct_product_details row keyed by post_id. Removal re-saves the same JSON
 * column with the field set to an empty string.
 */
class ManageProductShippingClassCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [128]
     *   "Admin can assign a shipping class to a product."
     */
    public function adminCanAssignShippingClassToProduct(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $shippingClass = (new ShippingClassFactory())->create(['name' => 'Express 128']);
        $product       = (new ProductFactory())->create(['post_title' => 'AssignShipping128']);
        Assert::assertSame('', (string) ($product->detail->other_info['shipping_class'] ?? ''), 'fixture must start with no shipping_class assignment so the route-applied delta is observable');

        [, $body] = $I->post('/fluent-cart/v2/products/' . (int) $product->ID . '/shipping-class', [
            'shipping_class' => (int) $shippingClass->id,
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty($body['message'] ?? '', 'success response must include a confirmation message');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame((int) $shippingClass->id, (int) ($detail->other_info['shipping_class'] ?? 0), 'detail.other_info.shipping_class must persist the assigned shipping class id');
    }

    /**
     * Spec: Cases/Functional/products.md [129]
     *   "Admin sees a previously assigned shipping class replaced when a new one is set."
     */
    public function adminSeesPreviouslyAssignedShippingClassReplacedWhenNewOneIsSet(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $oldShippingClass = (new ShippingClassFactory())->create(['name' => 'Old Shipping 129']);
        $newShippingClass = (new ShippingClassFactory())->create(['name' => 'New Shipping 129']);
        $product = (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['shipping_class' => (int) $oldShippingClass->id]])
            ->create(['post_title' => 'ReplaceShipping129']);
        Assert::assertSame((int) $oldShippingClass->id, (int) ($product->detail->other_info['shipping_class'] ?? 0), 'fixture must start with the OLD shipping class assigned so the replacement delta is observable');

        [, $body] = $I->post('/fluent-cart/v2/products/' . (int) $product->ID . '/shipping-class', [
            'shipping_class' => (int) $newShippingClass->id,
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty($body['message'] ?? '', 'success response must include a confirmation message');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame((int) $newShippingClass->id, (int) ($detail->other_info['shipping_class'] ?? 0), 'detail.other_info.shipping_class must replace the previous assignment with the new shipping class id');
        Assert::assertNotSame((int) $oldShippingClass->id, (int) ($detail->other_info['shipping_class'] ?? 0), 'detail.other_info.shipping_class must NOT still equal the old shipping class id after replacement');
    }

    /**
     * Spec: Cases/Functional/products.md [130]
     *   "Admin can remove a product's shipping class."
     */
    public function adminCanRemoveProductShippingClass(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $shippingClass = (new ShippingClassFactory())->create(['name' => 'Removable Shipping 130']);
        $product = (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['shipping_class' => (int) $shippingClass->id]])
            ->create(['post_title' => 'RemoveShipping130']);
        Assert::assertSame((int) $shippingClass->id, (int) ($product->detail->other_info['shipping_class'] ?? 0), 'fixture must start with a shipping class assigned so the removal delta is observable');

        [, $body] = $I->post('/fluent-cart/v2/products/' . (int) $product->ID . '/shipping-class/remove', []);

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty($body['message'] ?? '', 'success response must include a confirmation message');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        $stored = $detail->other_info['shipping_class'] ?? null;
        Assert::assertSame('', (string) $stored, 'detail.other_info.shipping_class must be cleared (empty string) so the product carries no shipping-class override');
    }

    /**
     * Spec: Cases/Functional/products.md [133]
     *   "A worker without the "edit products" permission cannot assign a shipping class to a product."
     */
    public function workerWithoutEditProductsPermissionCannotAssignShippingClassToProduct(FunctionalTester $I): void
    {
        $shippingClass = (new ShippingClassFactory())->create(['name' => 'Worker Assign Shipping 133']);
        $product       = (new ProductFactory())->create(['post_title' => 'WorkerAssignShipping133']);
        $beforeOtherInfo = (array) ($product->detail->other_info ?? []);
        $I->loginAsWorker();

        [, $body] = $I->post('/fluent-cart/v2/products/' . (int) $product->ID . '/shipping-class', [
            'shipping_class' => (int) $shippingClass->id,
        ]);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame($beforeOtherInfo, (array) ($detail->other_info ?? []), 'rejected assign must not mutate detail.other_info');
    }

    /**
     * Spec: Cases/Functional/products.md [134]
     *   "A worker without the "edit products" permission cannot remove a shipping class from a product."
     */
    public function workerWithoutEditProductsPermissionCannotRemoveShippingClassFromProduct(FunctionalTester $I): void
    {
        $shippingClass = (new ShippingClassFactory())->create(['name' => 'Worker Remove Shipping 134']);
        $product = (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['shipping_class' => (int) $shippingClass->id]])
            ->create(['post_title' => 'WorkerRemoveShipping134']);
        $beforeOtherInfo = (array) ($product->detail->other_info ?? []);
        $I->loginAsWorker();

        [, $body] = $I->post('/fluent-cart/v2/products/' . (int) $product->ID . '/shipping-class/remove', []);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame($beforeOtherInfo, (array) ($detail->other_info ?? []), 'rejected remove must not mutate detail.other_info — the shipping_class must remain assigned');
        Assert::assertSame((int) $shippingClass->id, (int) ($detail->other_info['shipping_class'] ?? 0), 'shipping_class must still equal the seeded shipping class id after the rejected remove');
    }

    /**
     * Spec: Cases/Functional/products.md [137]
     *   "An accountant cannot assign a shipping class to a product."
     */
    public function accountantCannotAssignShippingClassToProduct(FunctionalTester $I): void
    {
        $shippingClass = (new ShippingClassFactory())->create(['name' => 'Accountant Assign Shipping 137']);
        $product       = (new ProductFactory())->create(['post_title' => 'AccountantAssignShipping137']);
        $beforeOtherInfo = (array) ($product->detail->other_info ?? []);
        $I->loginAsAccountant();

        [, $body] = $I->post('/fluent-cart/v2/products/' . (int) $product->ID . '/shipping-class', [
            'shipping_class' => (int) $shippingClass->id,
        ]);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame($beforeOtherInfo, (array) ($detail->other_info ?? []), 'rejected assign must not mutate detail.other_info');
    }

    /**
     * Spec: Cases/Functional/products.md [138]
     *   "An accountant cannot remove a shipping class from a product."
     */
    public function accountantCannotRemoveShippingClassFromProduct(FunctionalTester $I): void
    {
        $shippingClass = (new ShippingClassFactory())->create(['name' => 'Accountant Remove Shipping 138']);
        $product = (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['shipping_class' => (int) $shippingClass->id]])
            ->create(['post_title' => 'AccountantRemoveShipping138']);
        $beforeOtherInfo = (array) ($product->detail->other_info ?? []);
        $I->loginAsAccountant();

        [, $body] = $I->post('/fluent-cart/v2/products/' . (int) $product->ID . '/shipping-class/remove', []);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame($beforeOtherInfo, (array) ($detail->other_info ?? []), 'rejected remove must not mutate detail.other_info');
        Assert::assertSame((int) $shippingClass->id, (int) ($detail->other_info['shipping_class'] ?? 0), 'shipping_class must still equal the seeded shipping class id after the rejected remove');
    }
}
