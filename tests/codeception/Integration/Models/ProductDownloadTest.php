<?php

namespace Tests\Integration\Models;

use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Models\ProductMeta;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductDownloadFactory;
use Tests\Support\Factory\ProductFactory;

class ProductDownloadTest extends WPTestCase
{
    private ProductFactory $productFactory;
    private ProductDownloadFactory $downloadFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
        $this->downloadFactory = new ProductDownloadFactory();
    }

    // =========================================================================
    // Spec 81: Table & Columns
    // =========================================================================

    public function test_product_downloads_table_has_expected_columns(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fct_product_downloads';
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $columnNames = array_column($columns, 'Field');

        $expected = [
            'id', 'post_id', 'product_variation_id', 'download_identifier',
            'title', 'type', 'driver', 'file_name', 'file_path', 'file_url',
            'file_size', 'settings', 'serial',
        ];

        foreach ($expected as $col) {
            $this->assertContains($col, $columnNames, "Column {$col} should exist");
        }
    }

    // =========================================================================
    // Spec 82: settings mutator/accessor
    // =========================================================================

    public function test_settings_json_encodes_and_decodes(): void
    {
        $product = $this->productFactory->create();
        $download = $this->downloadFactory->create($product->ID, [
            'settings' => ['download_limit' => 5, 'download_expiry' => 30],
        ]);

        $refreshed = ProductDownload::query()->find($download->id);
        $this->assertIsArray($refreshed->settings);
        $this->assertSame(5, $refreshed->settings['download_limit']);
        $this->assertSame(30, $refreshed->settings['download_expiry']);
    }

    // =========================================================================
    // Spec 83: product_variation_id mutator/accessor
    // =========================================================================

    public function test_product_variation_id_converts_array_to_json_and_back(): void
    {
        $product = $this->productFactory->create();
        $variantId = $product->variants->first()->id;
        $download = $this->downloadFactory->create($product->ID, [
            'product_variation_id' => [$variantId],
        ]);

        $refreshed = ProductDownload::query()->find($download->id);
        $this->assertIsArray($refreshed->product_variation_id);
        $this->assertContains($variantId, $refreshed->product_variation_id);
    }

    public function test_product_variation_id_converts_numeric_to_array(): void
    {
        $product = $this->productFactory->create();
        $variantId = $product->variants->first()->id;
        $download = $this->downloadFactory->create($product->ID, [
            'product_variation_id' => $variantId,
        ]);

        $refreshed = ProductDownload::query()->find($download->id);
        $this->assertIsArray($refreshed->product_variation_id);
        $this->assertSame([$variantId], $refreshed->product_variation_id);
    }

    public function test_product_variation_id_returns_empty_array_for_null(): void
    {
        $product = $this->productFactory->create();
        $download = $this->downloadFactory->create($product->ID, [
            'product_variation_id' => null,
        ]);

        $refreshed = ProductDownload::query()->find($download->id);
        $this->assertSame([], $refreshed->product_variation_id);
    }

    // =========================================================================
    // Spec 84: product() relationship
    // =========================================================================

    public function test_product_relationship(): void
    {
        $product = $this->productFactory->create();
        $download = $this->downloadFactory->create($product->ID);

        $related = $download->product;
        $this->assertInstanceOf(Product::class, $related);
        $this->assertSame($product->ID, $related->ID);
    }

    // =========================================================================
    // Spec 85: download_permissions() relationship
    // =========================================================================

    public function test_download_permissions_relationship_returns_empty_when_none(): void
    {
        // Positive-path coverage for download_permissions requires an Order with
        // an OrderDownloadPermission record, which depends on the orders domain.
        // This test verifies the relationship is wired and returns an empty
        // collection when no permissions exist.
        $product = $this->productFactory->create();
        $download = $this->downloadFactory->create($product->ID);
        $permissions = $download->download_permissions;

        $this->assertCount(0, $permissions);
        // Verify the relationship targets the correct model
        $relation = $download->download_permissions();
        $this->assertSame('download_id', $relation->getForeignKeyName());
    }

    // =========================================================================
    // Spec 86: getSignedDownloadUrl
    // =========================================================================

    public function test_get_signed_download_url_is_a_signed_url(): void
    {
        $product = $this->productFactory->create();
        $download = $this->downloadFactory->create($product->ID);
        $url = $download->getSignedDownloadUrl();

        $this->assertIsString($url);
        $this->assertStringStartsWith('http', $url, 'Should be a full URL');
        // DownloadService encrypts the download data and appends a signature
        $this->assertStringContainsString('_signature=', $url, 'URL should contain a signature parameter');
        $this->assertStringContainsString('fluent-cart=download', $url, 'URL should contain the download route param');
    }
}
