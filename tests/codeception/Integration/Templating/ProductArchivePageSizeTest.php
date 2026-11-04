<?php

namespace Tests\Integration\Templating;

use FluentCart\App\Modules\Templating\TemplateActions;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * Taxonomy archives size their page from Settings > Reading. The renderer and
 * AJAX pages cap at 100, so the archive query must too, or page 2 repeats
 * products (found in the second Codex review).
 */
class ProductArchivePageSizeTest extends DatabaseTestCase
{
    private $previousPerPage;

    public function setUp(): void
    {
        parent::setUp();
        $this->previousPerPage = get_option('posts_per_page');
        update_option('posts_per_page', 150);
    }

    public function tearDown(): void
    {
        update_option('posts_per_page', $this->previousPerPage);
        parent::tearDown();
    }

    public function test_archive_query_and_paginator_agree_on_a_capped_page_size(): void
    {
        $term = wp_insert_term('Archive Page Size', 'product-categories');
        $termId = (int) $term['term_id'];
        for ($i = 0; $i < 105; $i++) {
            $product = (new ProductFactory())->create(['post_title' => 'Archive ' . $i, 'post_status' => 'publish']);
            wp_set_object_terms($product->ID, [$termId], 'product-categories');
        }

        $this->go_to(get_term_link($termId, 'product-categories'));

        ob_start();
        (new TemplateActions())->renderProductArchive();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('data-per-page="100"', $html);
        $this->assertSame(100, substr_count($html, 'data-fct-product-card'), 'the first page renders the same 100 products the paginator advertises');
    }
}
