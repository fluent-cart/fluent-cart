<?php

namespace Tests\Integration\Templating;

use FluentCart\App\Models\Product;
use FluentCart\App\Services\Renderer\ShopAppRenderer;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * The shop paginator's per-page selector names the page size the grid shows, including one
 * outside its 10 / 20 / 30 list (a builder's "Products per page" of 4, say).
 */
class ShopPaginatorPerPageTest extends WPTestCase
{
    private function paginator(int $perPage): string
    {
        $factory = new ProductFactory();
        for ($i = 0; $i < 3; $i++) {
            $factory->create(['post_title' => 'Paginator product ' . $i]);
        }
        $products = Product::query()->with(['detail', 'variants'])->paginate($perPage);
        ob_start();
        (new ShopAppRenderer($products, ['per_page' => $perPage, 'paginator' => 'numbers']))->renderPaginator();

        return (string) ob_get_clean();
    }

    public function test_a_page_size_outside_the_list_is_listed_and_selected(): void
    {
        $html = $this->paginator(4);

        $this->assertMatchesRegularExpression('/<option value="4"\s+selected/', $html, 'the selector shows the grid\'s own page size');
        $this->assertStringNotContainsString("value=\"10\"  selected", $html);
        $this->assertSame(1, substr_count($html, 'value="4"'));
    }

    public function test_a_listed_page_size_is_not_repeated(): void
    {
        $html = $this->paginator(20);

        $this->assertSame(1, substr_count($html, 'value="20"'));
        $this->assertSame(3, substr_count($html, '<option '), 'only the 10 / 20 / 30 options');
    }
}
