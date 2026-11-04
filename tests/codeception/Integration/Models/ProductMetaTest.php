<?php

namespace Tests\Integration\Models;

use FluentCart\App\Models\ProductMeta;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

class ProductMetaTest extends WPTestCase
{
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
    }

    // =========================================================================
    // Spec 87: Table & Columns
    // =========================================================================

    public function test_product_meta_table_has_expected_columns(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fct_product_meta';
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $columnNames = array_column($columns, 'Field');

        $expected = ['id', 'object_id', 'object_type', 'meta_key', 'meta_value'];

        foreach ($expected as $col) {
            $this->assertContains($col, $columnNames, "Column {$col} should exist");
        }
    }

    // =========================================================================
    // Spec 88: meta_value mutator/accessor
    // =========================================================================

    public function test_meta_value_json_encodes_arrays_and_decodes_back(): void
    {
        $product = $this->productFactory->create();
        $meta = ProductMeta::query()->create([
            'object_id'  => $product->ID,
            'meta_key'   => 'test_array',
            'meta_value' => ['key' => 'value', 'nested' => ['a' => 1]],
        ]);

        $refreshed = ProductMeta::query()->find($meta->id);
        $this->assertIsArray($refreshed->meta_value);
        $this->assertSame('value', $refreshed->meta_value['key']);
        $this->assertSame(1, $refreshed->meta_value['nested']['a']);
    }

    public function test_meta_value_returns_string_as_is_for_non_json(): void
    {
        $product = $this->productFactory->create();
        $meta = ProductMeta::query()->create([
            'object_id'  => $product->ID,
            'meta_key'   => 'test_string',
            'meta_value' => 'plain text value',
        ]);

        $refreshed = ProductMeta::query()->find($meta->id);
        $this->assertSame('plain text value', $refreshed->meta_value);
    }

    public function test_meta_value_handles_unicode(): void
    {
        $product = $this->productFactory->create();
        $meta = ProductMeta::query()->create([
            'object_id'  => $product->ID,
            'meta_key'   => 'test_unicode',
            'meta_value' => ['name' => 'Ünïcödé Tëst'],
        ]);

        $refreshed = ProductMeta::query()->find($meta->id);
        $this->assertSame('Ünïcödé Tëst', $refreshed->meta_value['name']);
    }
}
