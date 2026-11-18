<?php

namespace Tests\Integration\Templating;

use FluentCart\App\Modules\Templating\TemplateActions;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * Classic themes draw the product archive and the single product's Related Products from PHP templates,
 * which keep the standard card. A site opts into a card layout with two filters, so no saved page changes.
 */
class ClassicTemplateCardLayoutTest extends DatabaseTestCase
{
    private $termId;

    private $productIds = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->termId = (int) wp_insert_term('Classic Layout ' . wp_generate_password(6, false), 'product-categories')['term_id'];
        foreach (['Main', 'Alpha', 'Bravo'] as $name) {
            $id = (int) (new ProductFactory())->create(['post_title' => 'ClassicLayout ' . $name, 'post_status' => 'publish'])->ID;
            wp_set_object_terms($id, [$this->termId], 'product-categories');
            $this->productIds[] = $id;
        }
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/product_archive/card_layout');
        remove_all_filters('fluent_cart/single_product_page/related_card_layout');
        \FluentCart\Api\StoreSettings::clearCache();
        parent::tearDown();
    }

    private function archive(): string
    {
        $this->go_to(get_term_link($this->termId, 'product-categories'));
        ob_start();
        (new TemplateActions())->renderProductArchive();
        return (string) ob_get_clean();
    }

    private function relatedProducts(): string
    {
        $this->go_to(get_permalink($this->productIds[0]));
        update_option('fluent_cart_store_settings', array_merge((array) get_option('fluent_cart_store_settings', []), ['show_relevant_product_in_single_page' => 'yes']));
        \FluentCart\Api\StoreSettings::clearCache();
        $GLOBALS['post'] = get_post($this->productIds[0]);
        return (new TemplateActions())->filterSingleProductContent('');
    }

    public function test_the_archive_keeps_the_standard_card_unless_a_filter_picks_a_layout(): void
    {
        $plain = $this->archive();
        $this->assertStringNotContainsString('fct-card-layout', $plain);
        $this->assertStringNotContainsString('data-card-layout', $plain);

        $terms = [];
        add_filter('fluent_cart/product_archive/card_layout', static function ($layout, $context) use (&$terms) {
            $terms[] = $context['term']->term_id ?? null;
            return 'framed';
        }, 10, 2);
        $layout = $this->archive();
        $this->assertSame(3, substr_count($layout, 'fct-card-layout--framed'));
        $this->assertStringContainsString('data-card-layout="framed"', $layout, 'AJAX pages need the layout too');
        $this->assertContains($this->termId, $terms, 'the callback gets the archive term');

        add_filter('fluent_cart/product_archive/card_layout', static function () {
            return 'nope';
        }, 20);
        $this->assertStringNotContainsString('fct-card-layout', $this->archive(), 'an unknown layout keeps the standard card');

        add_filter('fluent_cart/product_archive/card_layout', static function () {
            return ['layout' => 'framed'];
        }, 30);
        $this->assertStringNotContainsString('fct-card-layout', $this->archive(), 'a non-string return keeps the standard card');
    }

    public function test_the_related_list_keeps_the_standard_card_unless_a_filter_picks_a_layout(): void
    {
        $this->assertStringNotContainsString('fct-card-layout', $this->relatedProducts());

        $seen = [];
        add_filter('fluent_cart/single_product_page/related_card_layout', static function ($layout, $context) use (&$seen) {
            $seen[] = $context['product_id'] ?? null;
            return 'compact';
        }, 10, 2);
        $html = $this->relatedProducts();
        $this->assertSame(2, substr_count($html, 'fct-card-layout--compact'), 'the two other products in the category');
        $this->assertContains($this->productIds[0], $seen);
    }

    public function test_the_related_shortcode_follows_the_filter_unless_it_names_a_layout(): void
    {
        $this->go_to(get_permalink($this->productIds[0]));
        $render = function (string $attributes = ''): string {
            return do_shortcode('[fluent_cart_related_products id="' . $this->productIds[0] . '" ' . $attributes . ']');
        };
        $this->assertStringNotContainsString('fct-card-layout', $render());

        add_filter('fluent_cart/single_product_page/related_card_layout', static function () {
            return 'compact';
        });
        $this->assertSame(2, substr_count($render(), 'fct-card-layout--compact'));
        $this->assertSame(2, substr_count($render('card_layout="framed"'), 'fct-card-layout--framed'), 'the attribute wins');
    }
}
