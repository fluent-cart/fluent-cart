<?php

namespace Tests\Integration\Blocks;

use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Models\AttributeRelation;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Services\Renderer\ProductCardSwatchesRenderer;
use Tests\Support\Factory\ProductFactory;
use lucatume\WPBrowser\TestCase\WPTestCase;

class CardSwatchEligibilityTest extends WPTestCase
{
    private $product;
    private $group;
    private $term;

    public function setUp(): void
    {
        parent::setUp();
        $this->product = (new ProductFactory())->createWithVariants([
            ['variation_title' => 'Red one'], ['variation_title' => 'Red two'],
        ]);
        ProductDetail::query()->where('post_id', $this->product->ID)->update(['variation_type' => 'advanced_variations']);
        $this->product->load('detail');
        $this->group = AttributeGroup::query()->create(['title' => 'Colour', 'slug' => 'colour-' . wp_generate_password(6, false)]);
        $this->term = AttributeTerm::query()->create(['title' => 'Red', 'slug' => 'red', 'group_id' => $this->group->id]);
        foreach ($this->product->variants as $variant) {
            AttributeRelation::query()->create(['group_id' => $this->group->id, 'term_id' => $this->term->id, 'object_id' => $variant->id]);
        }
    }

    public function test_duplicate_combinations_keep_view_options(): void
    {
        $html = ProductCardSwatchesRenderer::render($this->product, []);
        $this->assertStringNotContainsString('data-card-button=', $html, 'A swatch must identify exactly one variant before it can purchase');
    }

    public function test_incomplete_variant_assignments_keep_view_options(): void
    {
        AttributeRelation::query()->where('object_id', $this->product->variants->last()->id)->delete();
        $html = ProductCardSwatchesRenderer::render($this->product, []);
        $this->assertStringNotContainsString('data-card-button=', $html, 'A missing attribute assignment must not become an arbitrary purchase');
    }
    public function test_card_suffixes_do_not_load_checkout_tax_registrations(): void
    {
        $queries = [];
        $collect = function ($query) use (&$queries) {
            if (strpos($query, 'eu_vat_registration') !== false) {
                $queries[] = $query;
            }
            return $query;
        };
        add_filter('query', $collect);
        try {
            ProductCardSwatchesRenderer::rowsForProduct($this->product);
        } finally {
            remove_filter('query', $collect);
        }
        $this->assertCount(0, $queries, 'Rendering card price suffixes needs only cached store options');
    }

    public function test_only_options_without_visible_text_carry_a_tooltip(): void
    {
        // A native tooltip turns the hand cursor into an arrow; a text option already shows its name.
        $html = ProductCardSwatchesRenderer::render($this->product, []);
        $this->assertStringContainsString('aria-label="Red"', $html);
        $this->assertStringNotContainsString('title="Red"', $html);

        // A fresh product: the renderer caches each product's groups for the request.
        $product = (new ProductFactory())->createWithVariants([['variation_title' => 'Red'], ['variation_title' => 'Blue']]);
        ProductDetail::query()->where('post_id', $product->ID)->update(['variation_type' => 'advanced_variations']);
        $product->load('detail');
        $colour = AttributeGroup::query()->create(['title' => 'Shade', 'slug' => 'shade-' . wp_generate_password(6, false), 'settings' => ['type' => 'color']]);
        foreach ($product->variants->values() as $index => $variant) {
            $term = AttributeTerm::query()->create(['title' => ['Red', 'Blue'][$index], 'slug' => 'shade-' . $index, 'group_id' => $colour->id]);
            AttributeRelation::query()->create(['group_id' => $colour->id, 'term_id' => $term->id, 'object_id' => $variant->id]);
        }
        $html = ProductCardSwatchesRenderer::render($product, []);
        $this->assertStringContainsString('title="Red"', $html, 'A colour swatch has no visible name, so it keeps the tooltip');
    }
}
