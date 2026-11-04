<?php

namespace Tests\Integration\Resources;

use FluentCart\Api\Resource\ShopResource;
use FluentCart\App\App;
use FluentCart\App\Hooks\Handlers\BlockEditors\ShopApp\InnerBlocks\InnerBlocks;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * Every shop query goes through ShopResource::get(), so the page-size cap
 * lives there. The block and shortcode read per_page from the request or
 * their own config and must not get past it (F11 bypass found in review).
 */
class ShopResourcePageSizeTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Table resets truncate fct_* only, so product posts from earlier runs
        // survive without a details row and would land in this unsorted page.
        $orphans = get_posts(['post_type' => 'fluent-products', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']);
        foreach ($orphans as $orphanId) {
            wp_delete_post($orphanId, true);
        }
        for ($i = 0; $i < 105; $i++) {
            (new ProductFactory())->create(['post_title' => 'Page Size ' . $i, 'post_status' => 'publish']);
        }
    }

    public function tearDown(): void
    {
        App::request()->set('per_page', null);
        parent::tearDown();
    }

    private function publishedParams(array $extra = []): array
    {
        return array_merge([
            'select'          => '*',
            'selected_status' => true,
            'status'          => ['post_status' => ['column' => 'post_status', 'operator' => 'in', 'value' => ['publish']]],
        ], $extra);
    }

    public function test_get_caps_an_oversized_page_at_100(): void
    {
        $products = ShopResource::get($this->publishedParams(['per_page' => 100000]))['products'];

        $this->assertSame(100, (int) $products->perPage());
        $this->assertCount(100, $products->items());
    }

    public function test_get_caps_an_oversized_cursor_page_at_100(): void
    {
        $products = ShopResource::get($this->publishedParams(['per_page' => 100000, 'paginate_using' => 'cursor']))['products'];

        $this->assertSame(100, (int) $products->perPage());
        $this->assertCount(100, $products->items());
    }

    public function test_get_falls_back_to_the_default_for_a_non_positive_page_size(): void
    {
        $products = ShopResource::get($this->publishedParams(['per_page' => 0]))['products'];

        $this->assertSame(10, (int) $products->perPage());
    }

    public function test_block_request_per_page_cannot_bypass_the_cap(): void
    {
        App::request()->set('per_page', 100000);
        $block = (object) ['context' => ['fluent-cart/per_page' => 10]];

        $products = (new InnerBlocks())->getInitialProducts($block)['products'];

        $this->assertLessThanOrEqual(100, count($products->items()));
    }

    // The first render and the AJAX pages must agree, or page 2 repeats items.
    public function test_shortcode_renders_the_capped_page_size_for_the_paginator(): void
    {
        $html = do_shortcode('[fluent_cart_products per_page="150"]');

        $this->assertMatchesRegularExpression('/data-per-page="100"/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-per-page="150"/', $html);
    }
}
