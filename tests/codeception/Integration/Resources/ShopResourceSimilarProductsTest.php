<?php

namespace Tests\Integration\Resources;

use FluentCart\Api\Resource\ShopResource;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

class ShopResourceSimilarProductsTest extends WPTestCase
{
    private ProductFactory $productFactory;

    /** @var int */
    private $currentId;

    /** @var int */
    private $siblingOneId;

    /** @var int */
    private $siblingTwoId;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();

        $this->currentId    = (int) $this->productFactory->create()->ID;
        $this->siblingOneId = (int) $this->productFactory->create()->ID;
        $this->siblingTwoId = (int) $this->productFactory->create()->ID;

        foreach ([$this->currentId, $this->siblingOneId, $this->siblingTwoId] as $postId) {
            wp_set_object_terms($postId, ['similar-products-cat'], 'product-categories');
        }
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/related_products/query_args');
        parent::tearDown();
    }

    private function relatedIds(array $config = []): array
    {
        $products = ShopResource::getSimilarProducts($this->currentId, false, $config);

        return array_map(function ($product) {
            return (int) $product->ID;
        }, $products);
    }

    public function test_similar_products_returns_siblings_sharing_a_term_without_any_filter(): void
    {
        $ids = $this->relatedIds();

        $this->assertContains($this->siblingOneId, $ids);
        $this->assertContains($this->siblingTwoId, $ids);
        $this->assertNotContains($this->currentId, $ids);
    }

    public function test_query_args_filter_can_exclude_a_product(): void
    {
        $excluded = $this->siblingOneId;

        add_filter('fluent_cart/related_products/query_args', function ($args) use ($excluded) {
            $args['post__not_in'][] = $excluded;
            return $args;
        });

        $ids = $this->relatedIds();

        $this->assertNotContains($excluded, $ids);
        $this->assertContains($this->siblingTwoId, $ids);
    }

    public function test_query_args_filter_receives_the_default_args_including_the_current_product(): void
    {
        $seen = null;

        add_filter('fluent_cart/related_products/query_args', function ($args) use (&$seen) {
            $seen = $args;
            return $args;
        });

        $this->relatedIds();

        $this->assertSame([$this->currentId], $seen['post__not_in']);
        $this->assertSame('publish', $seen['post_status']);
        $this->assertSame('ids', $seen['fields']);
        $this->assertSame(6, $seen['posts_per_page']);
        $this->assertArrayHasKey('tax_query', $seen);
        $this->assertSame('title', $seen['orderby']);
    }

    public function test_query_args_filter_receives_the_product_context(): void
    {
        $seen = null;

        add_filter('fluent_cart/related_products/query_args', function ($args, $context) use (&$seen) {
            $seen = $context;
            return $args;
        }, 10, 2);

        $this->relatedIds(['posts_per_page' => 3]);

        $this->assertIsArray($seen);
        $this->assertSame($this->currentId, (int) $seen['product_id']);
        $this->assertSame($this->currentId, (int) $seen['post']->ID);
        $this->assertSame(3, $seen['config']['posts_per_page']);
    }

    public function test_query_args_filter_can_change_any_argument(): void
    {
        add_filter('fluent_cart/related_products/query_args', function ($args) {
            $args['posts_per_page'] = 1;
            return $args;
        });

        $this->assertCount(1, $this->relatedIds());
    }
}
