<?php

namespace Tests\Integration\Blocks;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Models\AttributeRelation;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Services\Renderer\ProductCardSwatchesRenderer;
use Tests\Support\Factory\ProductFactory;
use lucatume\WPBrowser\TestCase\WPTestCase;

class CardSwatchSelectedChoiceTest extends WPTestCase
{
    private function product(string $type, array $titles)
    {
        $product = (new ProductFactory())->createWithVariants(array_map(function ($title) {
            return ['variation_title' => $title];
        }, $titles));
        ProductDetail::query()->where('post_id', $product->ID)->update(['variation_type' => $type]);
        $product->load('detail');
        return $product;
    }

    // Two attribute rows: Shade (Red, Blue) and Fit (Slim, Wide), one variant per pair.
    private function attributeProduct()
    {
        $product = $this->product('advanced_variations', ['Red Slim', 'Red Wide', 'Blue Slim', 'Blue Wide']);
        $shade = AttributeGroup::query()->create(['title' => 'Shade', 'slug' => 'shade-' . wp_generate_password(6, false)]);
        $fit = AttributeGroup::query()->create(['title' => 'Fit', 'slug' => 'fit-' . wp_generate_password(6, false)]);
        $terms = [];
        foreach ([[$shade, 'Red'], [$shade, 'Blue'], [$fit, 'Slim'], [$fit, 'Wide']] as [$group, $title]) {
            $terms[$title] = AttributeTerm::query()->create(['title' => $title, 'slug' => strtolower($title) . '-' . $group->id, 'group_id' => $group->id]);
        }
        foreach ($product->variants->values() as $index => $variant) {
            foreach ([['Red', 'Red', 'Blue', 'Blue'][$index], ['Slim', 'Wide', 'Slim', 'Wide'][$index]] as $title) {
                AttributeRelation::query()->create(['group_id' => $terms[$title]->group_id, 'term_id' => $terms[$title]->id, 'object_id' => $variant->id]);
            }
        }
        return $product;
    }

    public function test_attribute_products_show_the_choice_after_the_name_or_below_the_rows(): void
    {
        $product = $this->attributeProduct();

        $after = ProductCardSwatchesRenderer::render($product, ['show_label' => true]);
        $this->assertSame(2, substr_count($after, 'data-fct-card-swatch-label'), 'After the name: each row names its choice');
        $this->assertStringNotContainsString('fct-card-swatch-selected', $after);

        $below = ProductCardSwatchesRenderer::render($product, ['show_label' => true, 'selected_position' => 'below']);
        $this->assertStringNotContainsString('data-fct-card-swatch-label', $below, 'Below: no per-row choice');
        $this->assertStringContainsString('Selected: <span data-fct-card-swatch-selected-values>Red · Slim</span>', $below);

        $off = ProductCardSwatchesRenderer::render($product, ['show_label' => false, 'selected_position' => 'below']);
        $this->assertStringNotContainsString('fct-card-swatch-selected', $off, 'Switched off, no position applies');
    }

    public function test_list_products_follow_show_label_until_they_have_their_own_switch(): void
    {
        $product = $this->product(Helper::PRODUCT_TYPE_SIMPLE_VARIATION, ['6 pods', '12 pods']);
        $label = function (array $settings) use ($product) {
            return substr_count(ProductCardSwatchesRenderer::render($product, $settings), 'data-fct-card-swatch-label');
        };

        // Saved pages and builders send no list switch, so show_label still decides.
        $this->assertSame(1, $label(['show_label' => true]));
        $this->assertSame(1, $label(['show_label' => true, 'simple_show_selected' => '']));
        $this->assertSame(0, $label(['show_label' => false]));
        // Its own switch wins either way.
        $this->assertSame(0, $label(['show_label' => true, 'simple_show_selected' => false]));
        $this->assertSame(1, $label(['show_label' => false, 'simple_show_selected' => true]));

        $below = ProductCardSwatchesRenderer::render($product, ['simple_show_selected' => true, 'simple_selected_position' => 'below', 'selected_position' => 'name']);
        $this->assertStringContainsString('Selected: <span data-fct-card-swatch-selected-values>6 pods</span>', $below, 'The list uses its own position');
    }
}
