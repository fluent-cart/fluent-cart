<?php

namespace Tests\Integration\Blocks;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Models\AttributeRelation;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\Renderer\ProductCardSwatchesRenderer;
use Tests\Support\Factory\ProductFactory;
use lucatume\WPBrowser\TestCase\WPTestCase;

class CardSwatchSoldOutTest extends WPTestCase
{
    private $product;
    private $savedModules;

    public function setUp(): void
    {
        parent::setUp();
        // Card stock reads the stock module; without it every value counts as in stock. Turn it on
        // for this test; getAllSettings(false) refreshes the cached copy isActive() reads.
        $this->savedModules = get_option(ModuleSettings::MODULE_SETTINGS_OPTION, null);
        $modules = ModuleSettings::getAllSettings(false);
        $modules['stock_management']['active'] = 'yes';
        update_option(ModuleSettings::MODULE_SETTINGS_OPTION, $modules);
        ModuleSettings::getAllSettings(false);
        $this->assertTrue(ModuleSettings::isActive('stock_management'), 'Stock management must be on for this test');

        // One Size row: S, M, XL. Only XL is sold out.
        $this->product = (new ProductFactory())->createWithVariants([['variation_title' => 'S'], ['variation_title' => 'M'], ['variation_title' => 'XL']]);
        ProductDetail::query()->where('post_id', $this->product->ID)->update(['variation_type' => 'advanced_variations']);
        $size = AttributeGroup::query()->create(['title' => 'Size', 'slug' => 'size-' . wp_generate_password(6, false)]);
        foreach ($this->product->variants->values() as $index => $variant) {
            $title = ['S', 'M', 'XL'][$index];
            ProductVariation::query()->where('id', $variant->id)->update([
                'manage_stock' => 1,
                'available'    => $title === 'XL' ? 0 : 5,
                'stock_status' => $title === 'XL' ? Helper::OUT_OF_STOCK : Helper::IN_STOCK,
            ]);
            $term = AttributeTerm::query()->create(['title' => $title, 'slug' => 'size-' . strtolower($title) . '-' . $size->id, 'group_id' => $size->id]);
            AttributeRelation::query()->create(['group_id' => $size->id, 'term_id' => $term->id, 'object_id' => $variant->id]);
        }
        $this->product->load('detail', 'variants');
    }

    public function tearDown(): void
    {
        if ($this->savedModules === null) {
            delete_option(ModuleSettings::MODULE_SETTINGS_OPTION);
        } else {
            update_option(ModuleSettings::MODULE_SETTINGS_OPTION, $this->savedModules);
        }
        ModuleSettings::getAllSettings(false);
        parent::tearDown();
    }

    private function swatches(array $settings): array
    {
        $html = ProductCardSwatchesRenderer::render($this->product, $settings + ['max_visible' => 0]);
        preg_match_all('/<button[^>]*data-fct-card-swatch\b[^>]*data-label="([^"]+)"[^>]*>/', $html, $matches, PREG_SET_ORDER);
        return array_map(function ($match) {
            $tag = $match[0];
            return $match[1]
                . (strpos($tag, 'data-sold-out') !== false ? ' crossed' : '')
                . (strpos($tag, 'aria-pressed="true"') !== false ? ' picked' : '');
        }, $matches);
    }

    public function test_no_change_keeps_todays_swatches(): void
    {
        $this->assertSame(['S picked', 'M', 'XL'], $this->swatches([]), 'Unset, as saved pages and builders send it');
        $this->assertSame(['S picked', 'M', 'XL'], $this->swatches(['sold_out_display' => 'normal']));
    }

    public function test_cross_out_keeps_the_value_but_marks_it_and_never_starts_on_it(): void
    {
        $this->assertSame(['S picked', 'M', 'XL crossed'], $this->swatches(['sold_out_display' => 'cross']));
        $html = ProductCardSwatchesRenderer::render($this->product, ['sold_out_display' => 'cross', 'max_visible' => 0]);
        $this->assertStringContainsString('aria-disabled="true"', $html);
        $this->assertStringContainsString('aria-label="XL, sold out"', $html);

        // Even when the default variant is the sold-out one, the card starts on a value in stock.
        $xl = $this->product->variants->values()[2];
        ProductDetail::query()->where('post_id', $this->product->ID)->update(['default_variation_id' => $xl->id]);
        $this->product->load('detail');
        $this->assertSame(['S picked', 'M', 'XL crossed'], $this->swatches(['sold_out_display' => 'cross']));
    }

    public function test_hide_drops_sold_out_values_before_counting(): void
    {
        $this->assertSame(['S picked', 'M'], $this->swatches(['sold_out_display' => 'hide']));
        // How many to show counts what is left, so no "+1" for the hidden XL.
        $html = ProductCardSwatchesRenderer::render($this->product, ['sold_out_display' => 'hide', 'max_visible' => 2]);
        $this->assertStringNotContainsString('data-fct-card-swatch-more', $html);
    }

    public function test_a_value_sold_out_in_one_combination_stays_but_the_script_gets_what_it_needs(): void
    {
        // Two rows: Shade (Navy, Red) x Weight (250g, 500g). Only Navy-250g is sold out, so no value
        // is sold out everywhere and nothing is crossed out on the page itself.
        $product = (new ProductFactory())->createWithVariants([['variation_title' => 'Navy 250g'], ['variation_title' => 'Navy 500g'], ['variation_title' => 'Red 250g'], ['variation_title' => 'Red 500g']]);
        ProductDetail::query()->where('post_id', $product->ID)->update(['variation_type' => 'advanced_variations']);
        $shade = AttributeGroup::query()->create(['title' => 'Shade', 'slug' => 'shade-' . wp_generate_password(6, false), 'serial' => 1]);
        $weight = AttributeGroup::query()->create(['title' => 'Weight', 'slug' => 'weight-' . wp_generate_password(6, false), 'serial' => 2]);
        $terms = [];
        foreach ([[$shade, 'Navy'], [$shade, 'Red'], [$weight, '250g'], [$weight, '500g']] as [$group, $title]) {
            $terms[$title] = AttributeTerm::query()->create(['title' => $title, 'slug' => strtolower($title) . '-' . $group->id, 'group_id' => $group->id]);
        }
        foreach ($product->variants->values() as $index => $variant) {
            [$colour, $size] = [['Navy', '250g'], ['Navy', '500g'], ['Red', '250g'], ['Red', '500g']][$index];
            ProductVariation::query()->where('id', $variant->id)->update([
                'manage_stock' => 1,
                'available'    => $index === 0 ? 0 : 5,
                'stock_status' => $index === 0 ? Helper::OUT_OF_STOCK : Helper::IN_STOCK,
            ]);
            foreach ([$colour, $size] as $title) {
                AttributeRelation::query()->create(['group_id' => $terms[$title]->group_id, 'term_id' => $terms[$title]->id, 'object_id' => $variant->id]);
            }
        }
        $product->load('detail', 'variants');

        $html = ProductCardSwatchesRenderer::render($product, ['sold_out_display' => 'cross']);
        $this->assertStringNotContainsString('data-sold-out aria-disabled', $html, 'No value is sold out everywhere');
        $this->assertStringContainsString('data-sold-out-display="cross"', $html);
        $this->assertStringContainsString('data-sold-out-label="%s, sold out"', $html);
        preg_match('/data-variants="([^"]+)"/', $html, $match);
        $variants = json_decode(html_entity_decode($match[1]), true);
        // In stock first, so the script's first match for a clicked value is one it can sell.
        $this->assertSame([true, true, true, false], array_column($variants, 'in_stock'));
        // The card starts on a combination in stock, not the sold-out Navy-250g.
        preg_match_all('/data-label="([^"]+)"\s*aria-pressed="true"/', $html, $picked);
        $this->assertCount(2, $picked[1]);
        $this->assertNotSame(['Navy', '250g'], $picked[1]);

        $this->assertStringNotContainsString('data-sold-out-display', ProductCardSwatchesRenderer::render($product, []), 'No change: no marking');
    }

    // A list product (simple variations): A and B in stock, C sold out.
    private function listProduct()
    {
        $product = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([['variation_title' => 'A'], ['variation_title' => 'B'], ['variation_title' => 'C']], ['post_title' => 'List sold out', 'post_status' => 'publish']);
        foreach ($product->variants->values() as $index => $variant) {
            $soldOut = $index === 2;
            ProductVariation::query()->where('id', $variant->id)->update([
                'manage_stock' => 1,
                'available'    => $soldOut ? 0 : 5,
                'stock_status' => $soldOut ? Helper::OUT_OF_STOCK : Helper::IN_STOCK,
            ]);
        }
        $product->load('detail', 'variants');

        return $product;
    }

    private function listValues(array $settings): array
    {
        $html = ProductCardSwatchesRenderer::render($this->listProduct(), $settings + ['simple_max_visible' => 0]);
        preg_match_all('/<button[^>]*data-fct-card-swatch\b[^>]*>/', $html, $matches);
        $tags = $matches[0];
        preg_match_all('/data-label="([^"]+)"/', implode('', $tags), $labels);

        return array_map(function ($tag, $label) {
            return $label . (strpos($tag, 'data-sold-out') !== false ? ' crossed' : '');
        }, $tags, $labels[1]);
    }

    public function test_list_products_can_have_their_own_sold_out_option(): void
    {
        // Gutenberg's one setting reaches both types, as before.
        $this->assertSame(['A', 'B', 'C crossed'], $this->listValues(['sold_out_display' => 'cross']), 'follows sold_out_display until set');
        // Bricks has the setting under each product type: the list's own wins for list products only.
        $this->assertSame(['A', 'B', 'C'], $this->listValues(['sold_out_display' => 'cross', 'simple_sold_out_display' => 'normal']), 'the list says No change');
        $this->assertSame(['A', 'B'], $this->listValues(['simple_sold_out_display' => 'hide']), 'the list hides sold-out options');
        $this->assertSame(['S picked', 'M', 'XL'], $this->swatches(['simple_sold_out_display' => 'hide']), 'attribute products ignore the list setting');
        $this->assertSame(['S picked', 'M', 'XL crossed'], $this->swatches(['sold_out_display' => 'cross', 'simple_sold_out_display' => 'hide']), 'and keep their own');
        $this->assertSame(['A', 'B', 'C'], $this->listValues(['simple_sold_out_display' => 'nonsense']), 'an unknown value is ignored');
    }

    public function test_a_product_marked_out_of_stock_has_every_value_sold_out(): void
    {
        // Every variant is in stock, but the product itself is marked out of stock, so the card's
        // buy button says Not Available; the swatches must agree.
        ProductVariation::query()->where('post_id', $this->product->ID)->update(['available' => 5, 'stock_status' => Helper::IN_STOCK]);
        ProductDetail::query()->where('post_id', $this->product->ID)->update(['manage_stock' => 1, 'stock_availability' => Helper::OUT_OF_STOCK]);
        $this->product->load('detail', 'variants');

        $this->assertSame(['S crossed', 'M crossed', 'XL crossed'], $this->swatches(['sold_out_display' => 'cross']), 'Nothing starts picked');
        $this->assertSame('', ProductCardSwatchesRenderer::render($this->product, ['sold_out_display' => 'hide']), 'Hide leaves no swatches');
        $this->assertSame(['S picked', 'M', 'XL'], $this->swatches([]), 'No change keeps today\'s swatches');
    }
}
