<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Modules\MCP\Tools\ProductTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * list-products filters by a product category slug.
 */
class ListProductsCategoryFilterTest extends DatabaseTestCase
{
    private int $boots;
    private int $sandals;
    private int $bag;

    public function setUp(): void
    {
        parent::setUp();

        $factory = new ProductFactory();
        $this->boots = (int) $factory->create(['post_title' => 'Boots'])->ID;
        $this->sandals = (int) $factory->create(['post_title' => 'Sandals'])->ID;
        $this->bag = (int) $factory->create(['post_title' => 'Bag'])->ID;

        $footwear = wp_insert_term('Footwear', 'product-categories', ['slug' => 'mcp-footwear']);
        wp_set_object_terms($this->boots, [(int) $footwear['term_id']], 'product-categories');
        wp_set_object_terms($this->sandals, [(int) $footwear['term_id']], 'product-categories');

        $brand = wp_insert_term('Gear', 'product-brands', ['slug' => 'mcp-gear']);
        wp_set_object_terms($this->bag, [(int) $brand['term_id']], 'product-brands');
    }

    private function list(array $params): array
    {
        $result = ProductTools::listProducts($params);
        $this->assertIsArray($result, 'list-products returns a result, not an error');

        return $result;
    }

    private function ids(array $result): array
    {
        return array_map('intval', array_column($result['data']['products'], 'product_id'));
    }

    public function test_a_category_slug_returns_only_its_products(): void
    {
        $ids = $this->ids($this->list(['category' => 'mcp-footwear', 'per_page' => 50]));
        sort($ids);

        $expected = [$this->boots, $this->sandals];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function test_an_unknown_category_returns_no_products(): void
    {
        $result = $this->list(['category' => 'mcp-no-such-category']);

        $this->assertSame([], $result['data']['products']);
        $this->assertSame(0, $result['meta']['page']['total']);
    }

    public function test_a_brand_slug_does_not_match_the_category_filter(): void
    {
        $this->assertSame([], $this->list(['category' => 'mcp-gear'])['data']['products']);
    }

    public function test_paging_and_sorting_stay_inside_the_category(): void
    {
        $result = $this->list(['category' => 'mcp-footwear', 'sort_by' => 'title', 'sort_type' => 'ASC', 'per_page' => 1, 'page' => 2]);

        $this->assertSame([$this->sandals], $this->ids($result));
        $this->assertSame(2, $result['meta']['page']['total']);
    }

    public function test_without_a_category_every_product_is_listed(): void
    {
        $ids = $this->ids($this->list(['per_page' => 100]));

        foreach ([$this->boots, $this->sandals, $this->bag] as $id) {
            $this->assertContains($id, $ids);
        }
    }
}
