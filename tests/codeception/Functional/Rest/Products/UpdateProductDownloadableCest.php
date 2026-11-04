<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductDownload;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductDownloadFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Managing downloadable files" section, items 111-113.
 */
class UpdateProductDownloadableCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [111]
     *   "Admin can rename a downloadable file."
     */
    public function adminCanRenameDownloadableFile(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Rename111']);
        $download = (new ProductDownloadFactory())->create((int) $product->ID, [
            'title'     => 'Old Title',
            'driver'    => 'local',
            'type'      => 'local',
            'file_name' => 'rename-111.zip',
            'file_path' => '/uploads/rename-111.zip',
            'file_url'  => 'http://example.com/rename-111.zip',
            'serial'    => 1,
        ]);

        [$status, $body] = $I->put('/fluent-cart/v2/products/' . $download->id . '/update', [
            'title'                => 'New Title',
            'type'                 => 'local',
            'driver'               => 'local',
            'file_name'            => 'rename-111.zip',
            'file_path'            => '/uploads/rename-111.zip',
            'file_url'             => 'http://example.com/rename-111.zip',
            'serial'               => 1,
            'product_variation_id' => [],
            'settings'             => ['download_limit' => -1, 'download_expiry' => -1],
        ]);

        $I->seeResponseCodeIs(200);
        $refreshed = ProductDownload::query()->find($download->id);
        Assert::assertSame('New Title', (string) $refreshed->title, 'fct_product_downloads.title must persist the renamed value');
    }

    /**
     * Spec: Cases/Functional/products.md [112]
     *   "Admin can change a downloadable file's storage driver."
     */
    public function adminCanChangeDownloadableFileStorageDriver(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Driver112']);
        $download = (new ProductDownloadFactory())->create((int) $product->ID, [
            'title'     => 'Driver Probe',
            'driver'    => 'local',
            'type'      => 'local',
            'file_name' => 'driver-112.zip',
            'file_path' => '/uploads/driver-112.zip',
            'file_url'  => 'http://example.com/driver-112.zip',
            'serial'    => 1,
        ]);

        [$status, $body] = $I->put('/fluent-cart/v2/products/' . $download->id . '/update', [
            'title'                => 'Driver Probe',
            'type'                 => 'local',
            'driver'               => 's3',
            'file_name'            => 'driver-112.zip',
            'file_path'            => '/uploads/driver-112.zip',
            'file_url'             => 'http://example.com/driver-112.zip',
            'serial'               => 1,
            'product_variation_id' => [],
            'settings'             => ['download_limit' => -1, 'download_expiry' => -1],
        ]);

        $I->seeResponseCodeIs(200);
        $refreshed = ProductDownload::query()->find($download->id);
        Assert::assertSame('s3', (string) $refreshed->driver, 'fct_product_downloads.driver must persist the new storage driver value');
        Assert::assertSame('local', (string) $refreshed->type, 'fct_product_downloads.type must remain unchanged when only the driver changes');
    }

    /**
     * Spec: Cases/Functional/products.md [113]
     *   "Admin can update a downloadable file's variant assignments."
     */
    public function adminCanUpdateDownloadableFileVariantAssignments(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Variants113']);
        $download = (new ProductDownloadFactory())->create((int) $product->ID, [
            'title'                => 'Variant Probe',
            'driver'               => 'local',
            'type'                 => 'local',
            'file_name'            => 'variants-113.zip',
            'file_path'            => '/uploads/variants-113.zip',
            'file_url'             => 'http://example.com/variants-113.zip',
            'serial'               => 1,
            'product_variation_id' => [],
        ]);

        $variantA = (int) $product->variants->first()->id;

        [$status, $body] = $I->put('/fluent-cart/v2/products/' . $download->id . '/update', [
            'title'                => 'Variant Probe',
            'type'                 => 'local',
            'driver'               => 'local',
            'file_name'            => 'variants-113.zip',
            'file_path'            => '/uploads/variants-113.zip',
            'file_url'             => 'http://example.com/variants-113.zip',
            'serial'               => 1,
            'product_variation_id' => [$variantA],
            'settings'             => ['download_limit' => -1, 'download_expiry' => -1],
        ]);

        $I->seeResponseCodeIs(200);
        $refreshed = ProductDownload::query()->find($download->id);
        $assigned = $refreshed->product_variation_id;
        Assert::assertIsArray($assigned, 'fct_product_downloads.product_variation_id must round-trip as a JSON-decoded array');
        Assert::assertSame([$variantA], array_map('intval', $assigned), 'product_variation_id must persist exactly the submitted variant id list, replacing the empty initial assignment');
    }
}
