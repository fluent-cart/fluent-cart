<?php

namespace Tests\Integration\Renderer;

use FluentCart\App\Models\Product;
use FluentCart\App\Services\Renderer\ProductListRenderer;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * The list's column count reaches the page as a CSS variable, clamped to 1–6.
 * Pins the markup so the Plugin Check escaping change keeps it byte-identical.
 */
class ProductListColumnsTest extends DatabaseTestCase
{
    private function renderWithColumns($columns): string
    {
        $created = (new ProductFactory())->create(['post_title' => 'Column Item', 'post_status' => 'publish']);
        $product = Product::query()->with(['detail', 'variants'])->find($created->ID);

        ob_start();
        (new ProductListRenderer([$product], 'Related', 'fct-test-list', ['columns' => $columns]))->render();

        return (string) ob_get_clean();
    }

    public function test_column_count_is_written_as_a_css_variable(): void
    {
        $this->assertStringContainsString('style="--fct-product-list-columns: 3;"', $this->renderWithColumns(3));
    }

    public function test_column_count_is_clamped_to_the_supported_range(): void
    {
        $this->assertStringContainsString('style="--fct-product-list-columns: 6;"', $this->renderWithColumns(9));
        $this->assertStringContainsString('style="--fct-product-list-columns: 1;"', $this->renderWithColumns('0; color:red'));
    }
}
