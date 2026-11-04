<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductDownload;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductDownloadFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * A downloadable file's `file_path` must not be able to address a file outside
 * the storage directory.
 *
 * The column is written from request input on both write routes and is later
 * composed onto the storage directory to stream the file back. A `..` segment
 * stored here turned the customer download into an arbitrary file read —
 * wp-config.php included — and both routes are reachable with `products/edit`,
 * which the Manager role holds without needing manage_options.
 */
class ProductDownloadablePathTraversalCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/storage-path-traversal.md [23]
     *   "Attaching a downloadable file whose path escapes the storage directory is rejected."
     */
    public function syncRejectsFilePathThatEscapesStorageDirectory(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'TraversalSync']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/sync-downloadable-files', [
            'downloadable_files' => [
                [
                    'title'                => 'Escaping file',
                    'type'                 => 'local',
                    'driver'               => 'local',
                    'file_name'            => 'config.php',
                    'file_path'            => '../../../wp-config.php',
                    'file_url'             => 'http://example.com/config.php',
                    'file_size'            => '2048',
                    'serial'               => 1,
                    'product_variation_id' => [],
                    'settings'             => ['download_limit' => -1, 'download_expiry' => -1],
                ],
            ],
        ]);

        Assert::assertSame(422, $status, 'a file_path escaping the storage directory must be refused');
        Assert::assertCount(
            0,
            ProductDownload::query()->where('post_id', $product->ID)->get(),
            'no downloadable row may be persisted for a rejected file_path'
        );
    }

    /**
     * Spec: Cases/storage-path-traversal.md [25]
     *   "An escaping file name is rejected once it becomes the file path."
     */
    public function updateRejectsEscapingFileNameUsedAsFilePathFallback(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'TraversalFallback']);
        $download = (new ProductDownloadFactory())->create((int) $product->ID, [
            'title'     => 'Manual',
            'driver'    => 'local',
            'type'      => 'local',
            'file_name' => 'manual.pdf',
            'file_path' => '/uploads/manual.pdf',
            'file_url'  => 'http://example.com/manual.pdf',
            'serial'    => 1,
        ]);

        // update() does not require file_path — an absent one falls back to
        // file_name, so file_name reaches the same column and has to clear the
        // same bar. (The sync route requires file_path, so its fallback is
        // unreachable and is not worth a case.)
        [$status, $body] = $I->put('/fluent-cart/v2/products/' . $download->id . '/update', [
            'title'                => 'Manual',
            'type'                 => 'local',
            'driver'               => 'local',
            'file_name'            => '../../../wp-config.php',
            'file_url'             => 'http://example.com/manual.pdf',
            'serial'               => 1,
            'product_variation_id' => [],
            'settings'             => ['download_limit' => -1, 'download_expiry' => -1],
        ]);

        Assert::assertSame(422, $status, 'an escaping file_name must be refused once it becomes the file_path');

        $stored = ProductDownload::query()->find($download->id);
        Assert::assertSame(
            '/uploads/manual.pdf',
            (string) $stored->file_path,
            'the stored file_path must be left untouched by a rejected update'
        );
    }

    /**
     * Spec: Cases/storage-path-traversal.md [26]
     *   "Attaching a downloadable file with a plain path still works."
     */
    public function syncStillAcceptsAPlainFilePath(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'TraversalHappy']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/sync-downloadable-files', [
            'downloadable_files' => [
                [
                    'title'                => 'Manual PDF',
                    'type'                 => 'local',
                    'driver'               => 'local',
                    'file_name'            => 'manual.pdf',
                    'file_path'            => '/uploads/manual.pdf',
                    'file_url'             => 'http://example.com/manual.pdf',
                    'file_size'            => '2048',
                    'serial'               => 1,
                    'product_variation_id' => [],
                    'settings'             => ['download_limit' => -1, 'download_expiry' => -1],
                ],
            ],
        ]);

        Assert::assertSame(200, $status, 'a leading slash was never an escape and must keep being accepted');
        Assert::assertCount(
            1,
            ProductDownload::query()->where('post_id', $product->ID)->get(),
            'a contained file_path must still persist its downloadable row'
        );
    }

    /**
     * Spec: Cases/storage-path-traversal.md [24]
     *   "Renaming a downloadable file to a path that escapes the storage directory is rejected."
     */
    public function updateRejectsFilePathThatEscapesStorageDirectory(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'TraversalUpdate']);
        $download = (new ProductDownloadFactory())->create((int) $product->ID, [
            'title'     => 'Manual',
            'driver'    => 'local',
            'type'      => 'local',
            'file_name' => 'manual.pdf',
            'file_path' => '/uploads/manual.pdf',
            'file_url'  => 'http://example.com/manual.pdf',
            'serial'    => 1,
        ]);

        [$status, $body] = $I->put('/fluent-cart/v2/products/' . $download->id . '/update', [
            'title'                => 'Manual',
            'type'                 => 'local',
            'driver'               => 'local',
            'file_name'            => 'manual.pdf',
            'file_path'            => '../../../wp-config.php',
            'file_url'             => 'http://example.com/manual.pdf',
            'serial'               => 1,
            'product_variation_id' => [],
            'settings'             => ['download_limit' => -1, 'download_expiry' => -1],
        ]);

        Assert::assertSame(422, $status, 'updating a downloadable to an escaping file_path must be refused');

        $stored = ProductDownload::query()->find($download->id);
        Assert::assertSame(
            '/uploads/manual.pdf',
            (string) $stored->file_path,
            'the stored file_path must be left untouched by a rejected update'
        );
    }
}
