<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductDownloadFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ProductMetaFactory;
use Tests\Support\Factory\ProductTermFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Deleting a product" section, items 71-83.
 */
class DeleteProductCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [71]
     *   "Admin can delete a product."
     */
    public function adminCanDeleteProduct(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Delete71']);
        $beforeCount = $I->countFluentProductPosts();

        [$status, $body] = $I->delete('/fluent-cart/v2/products/' . $product->ID);

        $I->seeResponseCodeIs(200);
        clean_post_cache((int) $product->ID);
        global $wpdb;
        $row = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $product->ID));
        Assert::assertNull($row, 'wp_posts row must be removed after delete (verified via direct DB query — get_post() may return WP object-cache stale entry because ProductResource::delete uses Eloquent delete, not wp_delete_post)');
        Assert::assertSame($beforeCount - 1, $I->countFluentProductPosts(), 'fluent-products post count must decrement by exactly one after delete');
    }

    /**
     * Spec: Cases/Functional/products.md [72]
     *   "Admin sees the product's variations, details, downloadable files, and licence metadata
     *    removed when the product is deleted."
     */
    public function adminSeesVariationsDetailsDownloadablesAndLicenseMetaRemovedOnDelete(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Cascade72']);
        (new ProductDownloadFactory())->create((int) $product->ID, ['title' => 'Cascade72-File']);
        (new ProductMetaFactory())->createLicenseSettings((int) $product->ID, ['enable_licensing' => 'yes']);

        $variantId = (int) $product->variants->first()->id;
        $detailId = (int) $product->detail->id;

        Assert::assertNotEmpty(ProductVariation::query()->where('id', $variantId)->first(), 'precondition: variation row must exist before delete');
        Assert::assertNotEmpty(ProductDetail::query()->where('id', $detailId)->first(), 'precondition: detail row must exist before delete');
        Assert::assertSame(1, ProductDownload::query()->where('post_id', $product->ID)->count(), 'precondition: one downloadable file must exist before delete');
        Assert::assertSame(1, ProductMeta::query()->where('object_id', $product->ID)->where('meta_key', 'license_settings')->count(), 'precondition: license_settings ProductMeta must exist before delete');

        [$status, $body] = $I->delete('/fluent-cart/v2/products/' . $product->ID);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(0, ProductVariation::query()->where('id', $variantId)->count(), 'variation rows must be cascade-deleted');
        Assert::assertSame(0, ProductDetail::query()->where('id', $detailId)->count(), 'detail row must be cascade-deleted');
        Assert::assertSame(0, ProductDownload::query()->where('post_id', $product->ID)->count(), 'downloadable file rows must be cascade-deleted');
        Assert::assertSame(0, ProductMeta::query()->where('object_id', $product->ID)->where('meta_key', 'license_settings')->count(), 'license_settings ProductMeta must be cascade-deleted');
    }

    /**
     * Spec: Cases/Functional/products.md [73]
     *   "Admin sees the product's taxonomy term assignments cleared for categories, tags, and
     *    brands when the product is deleted."
     *
     * Note on coverage: spec sentence names "categories, tags, and brands". FluentProducts::registerProductTaxonomies
     * registers ONLY product-categories and product-brands against the fluent-products CPT — there is no product-tags
     * taxonomy in production (won't-ship decision 2026-08-06, spec item 58). Because no tag taxonomy exists,
     * no tag term can ever be assigned to a product, so this test exhaustively asserts that ALL term_relationships
     * rows for the deleted product (across every registered taxonomy) are removed via a wildcard COUNT(*) query —
     * i.e. if a tag taxonomy were ever registered and a tag assigned, the same wildcard would catch its removal too.
     */
    public function adminSeesTaxonomyTermAssignmentsClearedOnDelete(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'TermsClear73']);
        $termFactory = new ProductTermFactory();
        $cat = $termFactory->createCategory(['name' => 'CatClear-' . wp_generate_password(4, false, false)]);
        $brand = $termFactory->createBrand(['name' => 'BrandClear-' . wp_generate_password(4, false, false)]);
        $termFactory->attachToProduct((int) $product->ID, ProductTermFactory::TAXONOMY_CATEGORY, [(int) $cat['term_id']]);
        $termFactory->attachToProduct((int) $product->ID, ProductTermFactory::TAXONOMY_BRAND, [(int) $brand['term_id']]);

        $beforeCats = wp_get_object_terms($product->ID, ProductTermFactory::TAXONOMY_CATEGORY, ['fields' => 'ids']);
        $beforeBrands = wp_get_object_terms($product->ID, ProductTermFactory::TAXONOMY_BRAND, ['fields' => 'ids']);
        Assert::assertSame([(int) $cat['term_id']], array_map('intval', (array) $beforeCats), 'precondition: product must have the seeded category attached');
        Assert::assertSame([(int) $brand['term_id']], array_map('intval', (array) $beforeBrands), 'precondition: product must have the seeded brand attached');

        global $wpdb;
        $beforeRels = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = %d",
            $product->ID
        ));
        Assert::assertSame(2, $beforeRels, 'precondition: exactly 2 term_relationships rows must exist for the product (1 category + 1 brand) — proves the seed is canonical and the after-delete wildcard count is meaningful');

        [$status, $body] = $I->delete('/fluent-cart/v2/products/' . $product->ID);

        $I->seeResponseCodeIs(200);
        $afterRels = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = %d",
            $product->ID
        ));
        Assert::assertSame(0, $afterRels, 'every term_relationships row for the deleted product must be removed regardless of taxonomy — wildcard count covers categories, brands, and any future registered taxonomy (incl. tags once item 58 is fixed)');

        $afterCats = wp_get_object_terms($product->ID, ProductTermFactory::TAXONOMY_CATEGORY, ['fields' => 'ids']);
        $afterBrands = wp_get_object_terms($product->ID, ProductTermFactory::TAXONOMY_BRAND, ['fields' => 'ids']);
        Assert::assertSame([], array_map('intval', (array) $afterCats), 'category assignments must be empty after delete');
        Assert::assertSame([], array_map('intval', (array) $afterBrands), 'brand assignments must be empty after delete');
    }

    /**
     * Spec: Cases/Functional/products.md [74]
     *   "Admin is rejected when deleting a product that has pending orders attached so order
     *    processing is not disrupted."
     */
    public function adminIsRejectedDeletingProductWithPendingOrders(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'WithPending74']);
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id' => (int) $customer->id,
            'status'      => Status::ORDER_PROCESSING,
        ]);
        (new OrderItemFactory())->create((int) $order->id, [
            'post_id' => (int) $product->ID,
            'title'   => $product->post_title,
        ]);

        [$status, $body] = $I->delete('/fluent-cart/v2/products/' . $product->ID);

        Assert::assertNotSame(200, $status, 'pending-order delete attempt must NOT succeed');
        Assert::assertGreaterThanOrEqual(400, $status, 'pending-order delete must return a client/server error status');
        Assert::assertArrayHasKey('message', $body, 'rejection body must include a "message" key (set by ProductResource::makeErrorResponse via WP_Error -> rest_convert_error_to_response)');
        Assert::assertStringContainsString(
            'pending orders associated',
            (string) $body['message'],
            'rejection message must contain the controller-level guard text "pending orders associated" so the admin learns WHY the delete was blocked (exact text: "This product cannot be deleted at the moment. There are pending orders associated with it...")'
        );
        $this->assertProductPostExists((int) $product->ID, 'wp_posts row must persist when delete is rejected');
        Assert::assertSame(1, ProductVariation::query()->where('post_id', $product->ID)->count(), 'rejected delete must NOT cascade-remove the variant');
    }

    /**
     * Spec: Cases/Functional/products.md [75]
     *   "Admin sees a success entry written to the activity log when a product is deleted."
     */
    public function adminSeesActivityLogEntryWrittenOnDelete(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'LogOnDelete75']);

        [$status, $body] = $I->delete('/fluent-cart/v2/products/' . $product->ID);

        $I->seeResponseCodeIs(200);
        $log = Activity::query()
            ->where('module_name', 'Product')
            ->where('status', 'success')
            ->where('title', 'Product deleted')
            ->orderBy('id', 'DESC')
            ->first();
        Assert::assertNotNull($log, 'fct_activity must contain a success entry titled "Product deleted" after delete (logged via fluent_cart_success_log in ProductResource::delete)');
        Assert::assertStringContainsString('LogOnDelete75', (string) $log->content, 'activity log content must reference the deleted product title');
    }

    /**
     * Spec: Cases/Functional/products.md [77]
     *   "Admin can run a bulk delete action across selected products."
     */
    public function adminCanRunBulkDeleteAcrossSelectedProducts(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ProductFactory();
        $a = $factory->create(['post_title' => 'BulkDel77A']);
        $b = $factory->create(['post_title' => 'BulkDel77B']);
        $c = $factory->create(['post_title' => 'BulkDel77C']);
        $unselected = $factory->create(['post_title' => 'BulkDel77-Unselected']);
        $beforeCount = $I->countFluentProductPosts();

        [$status, $body] = $I->post('/fluent-cart/v2/products/do-bulk-action', [
            'action'      => 'delete_products',
            'product_ids' => [(int) $a->ID, (int) $b->ID, (int) $c->ID],
        ]);

        $I->seeResponseCodeIs(200);
        global $wpdb;
        foreach ([$a->ID, $b->ID, $c->ID] as $deletedId) {
            clean_post_cache((int) $deletedId);
            $row = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $deletedId));
            Assert::assertNull($row, "wp_posts row {$deletedId} must be removed by bulk delete (verified via direct DB query)");
        }
        $this->assertProductPostExists((int) $unselected->ID, 'unselected product must remain after bulk delete');
        Assert::assertSame($beforeCount - 3, $I->countFluentProductPosts(), 'fluent-products post count must decrement by exactly the 3 selected ids');
    }

    /**
     * Spec: Cases/Functional/products.md [78]
     *   "Admin sees any product with pending orders excluded from a bulk delete action, with the
     *    reason listed in the response."
     */
    public function adminSeesPendingOrderProductsExcludedFromBulkDelete(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ProductFactory();
        $deletable = $factory->create(['post_title' => 'BulkDel78-Deletable']);
        $blocked = $factory->create(['post_title' => 'BulkDel78-Blocked']);

        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id' => (int) $customer->id,
            'status'      => Status::ORDER_PROCESSING,
        ]);
        (new OrderItemFactory())->create((int) $order->id, [
            'post_id' => (int) $blocked->ID,
            'title'   => $blocked->post_title,
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/do-bulk-action', [
            'action'      => 'delete_products',
            'product_ids' => [(int) $deletable->ID, (int) $blocked->ID],
        ]);

        // Mixed-result path: ProductResource::manageBulkActions returns makeSuccessResponse with a
        // message naming the failed (blocked) ID(s). Deletable rows are removed; blocked rows persist.
        $I->seeResponseCodeIs(200);
        clean_post_cache((int) $deletable->ID);
        clean_post_cache((int) $blocked->ID);
        global $wpdb;
        $deletableRow = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $deletable->ID));
        $blockedRow = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $blocked->ID));
        Assert::assertNull($deletableRow, 'product without pending orders must still be deleted');
        Assert::assertSame((int) $blocked->ID, (int) $blockedRow, 'product with a pending-order OrderItem must NOT be deleted');
        $message = (string) ($body['message'] ?? ($body['data']['message'] ?? ''));
        Assert::assertNotEmpty($message, 'mixed-result response must include an explanatory message');
        Assert::assertStringContainsString((string) $blocked->ID, $message, 'response message must reference the blocked product id so the admin knows which one was excluded');
        Assert::assertStringContainsStringIgnoringCase('pending', $message, 'response message must explain the exclusion reason as pending orders');
    }

    /**
     * Spec: Cases/Functional/products.md [79]
     *   "A store manager with the "delete products" permission can delete a product."
     */
    public function storeManagerWithDeletePermissionCanDeleteProduct(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'ManagerDel79']);
        $I->loginAsManager();

        [$status, $body] = $I->delete('/fluent-cart/v2/products/' . $product->ID);

        $I->seeResponseCodeIs(200);
        clean_post_cache((int) $product->ID);
        global $wpdb;
        $row = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $product->ID));
        Assert::assertNull($row, 'wp_posts row must be removed when manager (with products/delete) deletes it');
    }

    /**
     * Spec: Cases/Functional/products.md [80]
     *   "A worker without the "delete products" permission cannot delete a product."
     */
    public function workerWithoutDeletePermissionCannotDeleteProduct(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'WorkerDel80']);
        $I->loginAsWorker();

        [$status, $body] = $I->delete('/fluent-cart/v2/products/' . $product->ID);

        Assert::assertSame(403, $status, 'worker without products/delete must be rejected with 403');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $this->assertProductPostExists((int) $product->ID, 'rejected delete must leave the wp_posts row intact');
    }

    /**
     * Spec: Cases/Functional/products.md [81]
     *   "An accountant cannot delete a product."
     */
    public function accountantCannotDeleteProduct(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'AccountantDel81']);
        $I->loginAsAccountant();

        [$status, $body] = $I->delete('/fluent-cart/v2/products/' . $product->ID);

        Assert::assertSame(403, $status, 'accountant must be rejected with 403 from product delete');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $this->assertProductPostExists((int) $product->ID, 'rejected delete must leave the wp_posts row intact');
    }

    /**
     * Spec: Cases/Functional/products.md [82]
     *   "A worker without the "edit products" permission cannot run a bulk delete action."
     */
    public function workerWithoutEditPermissionCannotRunBulkDelete(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'WorkerBulkDel82']);
        $I->loginAsWorker();

        [$status, $body] = $I->post('/fluent-cart/v2/products/do-bulk-action', [
            'action'      => 'delete_products',
            'product_ids' => [(int) $product->ID],
        ]);

        Assert::assertSame(403, $status, 'worker without products/edit must be rejected with 403 from do-bulk-action');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $this->assertProductPostExists((int) $product->ID, 'rejected bulk-delete must leave the wp_posts row intact');
    }

    /**
     * Spec: Cases/Functional/products.md [83]
     *   "An accountant cannot run a bulk delete action."
     */
    public function accountantCannotRunBulkDelete(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'AccountantBulkDel83']);
        $I->loginAsAccountant();

        [$status, $body] = $I->post('/fluent-cart/v2/products/do-bulk-action', [
            'action'      => 'delete_products',
            'product_ids' => [(int) $product->ID],
        ]);

        Assert::assertSame(403, $status, 'accountant must be rejected with 403 from do-bulk-action');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $this->assertProductPostExists((int) $product->ID, 'rejected bulk-delete must leave the wp_posts row intact');
    }

    private function assertProductPostExists(int $postId, string $message): void
    {
        clean_post_cache($postId);
        global $wpdb;
        $row = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $postId));
        Assert::assertSame($postId, (int) $row, $message . ' (verified via direct DB query)');
    }
}
