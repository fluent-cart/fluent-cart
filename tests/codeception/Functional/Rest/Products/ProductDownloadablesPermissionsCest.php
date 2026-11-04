<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductDownload;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductDownloadFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Managing downloadable files" section, items 118-123.
 *
 * Per-action permission denials for the four downloadables routes:
 *   POST   /products/{postId}/sync-downloadable-files  (perm products/edit)
 *   PUT    /products/{downloadableId}/update           (perm products/edit)
 *   DELETE /products/{downloadableId}/delete           (perm products/delete)
 */
class ProductDownloadablesPermissionsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [118]
     *   "A worker without the "edit products" permission cannot attach downloadable files."
     */
    public function workerWithoutEditPermissionCannotAttachDownloadableFiles(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'WorkerAttach118']);
        $I->loginAsWorker();

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/sync-downloadable-files', [
            'downloadable_files' => [[
                'title'                => 'Should Not Persist',
                'type'                 => 'local',
                'driver'               => 'local',
                'file_name'            => 'worker-118.zip',
                'file_path'            => '/uploads/worker-118.zip',
                'file_url'             => 'http://example.com/worker-118.zip',
                'serial'               => 1,
                'product_variation_id' => [],
                'settings'             => ['download_limit' => -1, 'download_expiry' => -1],
            ]],
        ]);

        Assert::assertSame(403, $status, 'worker without products/edit must be rejected with 403 from sync-downloadable-files');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        Assert::assertSame(0, ProductDownload::query()->where('post_id', $product->ID)->count(), 'rejected sync must not persist any fct_product_downloads rows');
    }

    /**
     * Spec: Cases/Functional/products.md [119]
     *   "A worker without the "edit products" permission cannot update a downloadable file."
     */
    public function workerWithoutEditPermissionCannotUpdateDownloadableFile(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'WorkerUpdate119']);
        $download = (new ProductDownloadFactory())->create((int) $product->ID, [
            'title'  => 'Original Title',
            'driver' => 'local',
        ]);
        $before = ProductDownload::query()->find($download->id)->toArray();
        $I->loginAsWorker();

        [$status, $body] = $I->put('/fluent-cart/v2/products/' . $download->id . '/update', [
            'title'                => 'Worker Attempted Rename',
            'type'                 => 'remote',
            'driver'               => 's3',
            'file_name'            => 'worker-119.zip',
            'file_path'            => '/uploads/worker-119.zip',
            'file_url'             => 'http://example.com/worker-119.zip',
            'serial'               => 99,
            'product_variation_id' => [42],
            'settings'             => ['download_limit' => 5, 'download_expiry' => 30],
        ]);

        Assert::assertSame(403, $status, 'worker without products/edit must be rejected with 403 from downloadable update');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $after = ProductDownload::query()->find($download->id)->toArray();
        unset($before['updated_at'], $after['updated_at']);
        Assert::assertSame($before, $after, 'rejected update must not mutate any column on fct_product_downloads (full-row snapshot equality proves no field was changed)');
    }

    /**
     * Spec: Cases/Functional/products.md [120]
     *   "A worker without the "delete products" permission cannot delete a downloadable file."
     */
    public function workerWithoutDeletePermissionCannotDeleteDownloadableFile(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'WorkerDelete120']);
        $download = (new ProductDownloadFactory())->create((int) $product->ID, ['title' => 'Sticky Download']);
        $I->loginAsWorker();

        [$status, $body] = $I->delete('/fluent-cart/v2/products/' . $download->id . '/delete');

        Assert::assertSame(403, $status, 'worker without products/delete must be rejected with 403 from downloadable delete');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        Assert::assertSame(1, ProductDownload::query()->where('id', $download->id)->count(), 'rejected delete must leave the fct_product_downloads row in place');
    }

    /**
     * Spec: Cases/Functional/products.md [121]
     *   "An accountant cannot attach downloadable files."
     */
    public function accountantCannotAttachDownloadableFiles(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'AccountantAttach121']);
        $I->loginAsAccountant();

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/sync-downloadable-files', [
            'downloadable_files' => [[
                'title'                => 'Accountant Attempt',
                'type'                 => 'local',
                'driver'               => 'local',
                'file_name'            => 'acct-121.zip',
                'file_path'            => '/uploads/acct-121.zip',
                'file_url'             => 'http://example.com/acct-121.zip',
                'serial'               => 1,
                'product_variation_id' => [],
                'settings'             => ['download_limit' => -1, 'download_expiry' => -1],
            ]],
        ]);

        Assert::assertSame(403, $status, 'accountant must be rejected with 403 from sync-downloadable-files');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        Assert::assertSame(0, ProductDownload::query()->where('post_id', $product->ID)->count(), 'rejected sync must not persist any fct_product_downloads rows');
    }

    /**
     * Spec: Cases/Functional/products.md [122]
     *   "An accountant cannot update a downloadable file."
     */
    public function accountantCannotUpdateDownloadableFile(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'AccountantUpdate122']);
        $download = (new ProductDownloadFactory())->create((int) $product->ID, [
            'title'  => 'Original Accountant',
            'driver' => 'local',
        ]);
        $before = ProductDownload::query()->find($download->id)->toArray();
        $I->loginAsAccountant();

        [$status, $body] = $I->put('/fluent-cart/v2/products/' . $download->id . '/update', [
            'title'                => 'Accountant Attempted Rename',
            'type'                 => 'remote',
            'driver'               => 's3',
            'file_name'            => 'acct-122.zip',
            'file_path'            => '/uploads/acct-122.zip',
            'file_url'             => 'http://example.com/acct-122.zip',
            'serial'               => 99,
            'product_variation_id' => [42],
            'settings'             => ['download_limit' => 5, 'download_expiry' => 30],
        ]);

        Assert::assertSame(403, $status, 'accountant must be rejected with 403 from downloadable update');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $after = ProductDownload::query()->find($download->id)->toArray();
        unset($before['updated_at'], $after['updated_at']);
        Assert::assertSame($before, $after, 'rejected update must not mutate any column on fct_product_downloads (full-row snapshot equality proves no field was changed)');
    }

    /**
     * Spec: Cases/Functional/products.md [123]
     *   "An accountant cannot delete a downloadable file."
     */
    public function accountantCannotDeleteDownloadableFile(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'AccountantDelete123']);
        $download = (new ProductDownloadFactory())->create((int) $product->ID, ['title' => 'Sticky Accountant']);
        $I->loginAsAccountant();

        [$status, $body] = $I->delete('/fluent-cart/v2/products/' . $download->id . '/delete');

        Assert::assertSame(403, $status, 'accountant must be rejected with 403 from downloadable delete');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        Assert::assertSame(1, ProductDownload::query()->where('id', $download->id)->count(), 'rejected delete must leave the fct_product_downloads row in place');
    }
}
