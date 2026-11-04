<?php

namespace Tests\Functional\Permissions;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Accessing product administration",
 * items 216-223.
 *
 * Routes (under /products, ProductPolicy):
 *   GET /products             list  perm products/view
 *   GET /products/{product}   view  perm products/view
 *
 * Per PermissionManager::getAllRoles, every shop role except customer has
 * products/view: super_admin, manager, worker, accountant. Customer and
 * logged-out visitors must be denied.
 */
class ProductsPermissionsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [216]
     *   "A logged-out visitor is forbidden from accessing admin product endpoints."
     */
    public function logoutVisitorIsForbiddenFromAccessingAdminProductEndpoints(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'GuestDeny216']);
        $I->logout();

        // Admin list endpoint — unauthenticated guests get 401 with rest_forbidden code
        [$listStatus, $listBody] = $I->get('/fluent-cart/v2/products');
        Assert::assertSame(401, $listStatus, 'guest list-products must be rejected with 401 unauthorized. Body: ' . wp_json_encode($listBody));
        Assert::assertSame('rest_forbidden', $listBody['code'] ?? '', 'guest list-products denial must surface the rest_forbidden code');
        Assert::assertNotEmpty($listBody['message'] ?? '', 'guest list-products denial must include a permission-denied message');

        // Admin view endpoint — same expectation
        [$viewStatus, $viewBody] = $I->get('/fluent-cart/v2/products/' . (int) $product->ID);
        Assert::assertSame(401, $viewStatus, 'guest view-product must be rejected with 401 unauthorized. Body: ' . wp_json_encode($viewBody));
        Assert::assertSame('rest_forbidden', $viewBody['code'] ?? '', 'guest view-product denial must surface the rest_forbidden code');
        Assert::assertNotEmpty($viewBody['message'] ?? '', 'guest view-product denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/products.md [217]
     *   "Customer is forbidden from accessing admin product endpoints."
     */
    public function customerIsForbiddenFromAccessingAdminProductEndpoints(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'CustomerDeny217']);
        $I->loginAs('customer');

        [, $listBody] = $I->get('/fluent-cart/v2/products');
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $listBody['code'] ?? '', 'customer list-products denial must surface the rest_forbidden code');
        Assert::assertNotEmpty($listBody['message'] ?? '', 'customer list-products denial must include a permission-denied message');

        [, $viewBody] = $I->get('/fluent-cart/v2/products/' . (int) $product->ID);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $viewBody['code'] ?? '', 'customer view-product denial must surface the rest_forbidden code');
        Assert::assertNotEmpty($viewBody['message'] ?? '', 'customer view-product denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/products.md [218]
     *   "Worker can list products."
     */
    public function workerCanListProducts(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'WorkerList218']);
        $I->loginAsWorker();

        [, $body] = $I->get('/fluent-cart/v2/products');

        $I->seeResponseCodeIs(200);
        $rows = (array) ($body['products']['data'] ?? $body['products']['products']['data'] ?? []);
        Assert::assertNotEmpty($rows, 'worker with products/view permission must receive a non-empty product list payload');
        $ids = array_map(static fn ($r) => (int) ($r['ID'] ?? 0), $rows);
        Assert::assertContains((int) $product->ID, $ids, 'worker list response must include the seeded product (proves the response carries real data, not a stub)');
    }

    /**
     * Spec: Cases/Functional/products.md [219]
     *   "Worker can open a single product."
     */
    public function workerCanOpenSingleProduct(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'WorkerView219']);
        $I->loginAsWorker();

        [, $body] = $I->get('/fluent-cart/v2/products/' . (int) $product->ID);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('product', $body, 'worker view-product response must expose the product under the product key');
        Assert::assertSame((int) $product->ID, (int) ($body['product']['ID'] ?? 0), 'worker view-product response must echo the requested product id');
    }

    /**
     * Spec: Cases/Functional/products.md [220]
     *   "Accountant can list products."
     */
    public function accountantCanListProducts(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'AccountantList220']);
        $I->loginAsAccountant();

        [, $body] = $I->get('/fluent-cart/v2/products');

        $I->seeResponseCodeIs(200);
        $rows = (array) ($body['products']['data'] ?? $body['products']['products']['data'] ?? []);
        Assert::assertNotEmpty($rows, 'accountant with products/view permission must receive a non-empty product list payload');
        $ids = array_map(static fn ($r) => (int) ($r['ID'] ?? 0), $rows);
        Assert::assertContains((int) $product->ID, $ids, 'accountant list response must include the seeded product');
    }

    /**
     * Spec: Cases/Functional/products.md [221]
     *   "Accountant can open a single product."
     */
    public function accountantCanOpenSingleProduct(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'AccountantView221']);
        $I->loginAsAccountant();

        [, $body] = $I->get('/fluent-cart/v2/products/' . (int) $product->ID);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('product', $body, 'accountant view-product response must expose the product under the product key');
        Assert::assertSame((int) $product->ID, (int) ($body['product']['ID'] ?? 0), 'accountant view-product response must echo the requested product id');
    }

    /**
     * Spec: Cases/Functional/products.md [222]
     *   "Store manager can list products."
     */
    public function storeManagerCanListProducts(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'ManagerList222']);
        $I->loginAsManager();

        [, $body] = $I->get('/fluent-cart/v2/products');

        $I->seeResponseCodeIs(200);
        $rows = (array) ($body['products']['data'] ?? $body['products']['products']['data'] ?? []);
        Assert::assertNotEmpty($rows, 'store manager with products/view permission must receive a non-empty product list payload');
        $ids = array_map(static fn ($r) => (int) ($r['ID'] ?? 0), $rows);
        Assert::assertContains((int) $product->ID, $ids, 'store manager list response must include the seeded product');
    }

    /**
     * Spec: Cases/Functional/products.md [223]
     *   "Store manager can open a single product."
     */
    public function storeManagerCanOpenSingleProduct(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'ManagerView223']);
        $I->loginAsManager();

        [, $body] = $I->get('/fluent-cart/v2/products/' . (int) $product->ID);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('product', $body, 'store manager view-product response must expose the product under the product key');
        Assert::assertSame((int) $product->ID, (int) ($body['product']['ID'] ?? 0), 'store manager view-product response must echo the requested product id');
    }
}
