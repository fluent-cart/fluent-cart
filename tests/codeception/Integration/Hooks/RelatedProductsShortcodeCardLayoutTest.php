<?php

namespace Tests\Integration\Hooks;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\StoreSettings;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * [fluent_cart_related_products card_layout="..."] lets page builders show related products
 * with the same card layouts as the Product Card block.
 */
class RelatedProductsShortcodeCardLayoutTest extends WPTestCase
{
    /** @var int */
    private $productId;

    public function setUp(): void
    {
        parent::setUp();
        $categoryId = (int) wp_insert_term('Related Layout ' . wp_generate_password(6, false), 'product-categories')['term_id'];
        $factory = new ProductFactory();
        $ids = [];
        foreach (['Main', 'Alpha', 'Bravo'] as $name) {
            $ids[] = $id = (int) $factory->create(['post_title' => 'RelatedLayout ' . $name])->ID;
            wp_set_object_terms($id, [$categoryId], 'product-categories');
        }
        $this->productId = $ids[0];
    }

    private function ratingSettings(string $shop, string $relevant): void
    {
        $modules = ModuleSettings::getAllSettings();
        $modules['reviews']['active'] = 'yes';
        ModuleSettings::saveSettings($modules);
        ModuleSettings::getAllSettings(false);
        update_option('fluent_cart_store_settings', array_merge((array) get_option('fluent_cart_store_settings', []), [
            'show_rating_in_shop'     => $shop,
            'show_rating_in_relevant' => $relevant,
        ]));
        StoreSettings::clearCache();
    }

    public function test_layout_cards_follow_the_related_products_rating_setting(): void
    {
        // Related products answer to show_rating_in_relevant, not the shop's setting, in layout cards too.
        $this->ratingSettings('yes', 'no');
        $this->assertStringNotContainsString('fct-product-card-rating', $this->render('card_layout="compact"'));

        $this->ratingSettings('no', 'yes');
        $this->assertSame(2, substr_count($this->render('card_layout="compact"'), 'class="fct-product-card-rating'));
    }

    private function render(string $attributes = ''): string
    {
        return do_shortcode('[fluent_cart_related_products id="' . $this->productId . '" ' . $attributes . ']');
    }

    public function test_card_layout_renders_every_related_product_with_that_layout(): void
    {
        $output = $this->render('card_layout="framed"');

        $this->assertSame(2, substr_count($output, 'fct-card-layout--framed'));
        $this->assertStringContainsString('RelatedLayout Alpha', $output);
        $this->assertStringNotContainsString('RelatedLayout Main', $output, 'the product itself is not related to itself');
    }

    public function test_classic_layout_uses_the_template_card(): void
    {
        $this->assertSame(2, substr_count($this->render('card_layout="classic"'), 'fct-product-card-template'));
    }

    public function test_without_or_with_an_unknown_layout_the_card_is_unchanged(): void
    {
        foreach (['', 'card_layout="nope"'] as $attributes) {
            $output = $this->render($attributes);
            $this->assertStringNotContainsString('fct-card-layout', $output);
            $this->assertStringNotContainsString('fct-product-card-template', $output);
            $this->assertStringContainsString('RelatedLayout Alpha', $output);
        }
    }

    public function test_masonry_staggers_the_layout_cards(): void
    {
        $this->assertSame(2, substr_count($this->render('card_layout="compact" masonry="yes"'), 'fct-card-masonry'));
        $this->assertStringNotContainsString('fct-card-masonry', $this->render('card_layout="compact"'));
        $this->assertStringNotContainsString('fct-card-masonry', $this->render('masonry="yes"'));
    }

    public function test_card_options_reach_the_related_layout_cards(): void
    {
        $factory = new ProductFactory();
        foreach (['One', 'Two'] as $name) {
            $id = (int) $factory->createWithVariants([['variation_title' => 'Small'], ['variation_title' => 'Large']], ['post_title' => 'RelatedLayout Var ' . $name])->ID;
            \FluentCart\App\Models\ProductDetail::query()->where('post_id', $id)->update(['variation_type' => \FluentCart\App\Helpers\Helper::PRODUCT_TYPE_SIMPLE_VARIATION]);
            wp_set_object_terms($id, wp_get_object_terms($this->productId, 'product-categories', ['fields' => 'ids']), 'product-categories');
        }
        $this->assertStringNotContainsString('fct-card-swatches', $this->render('card_layout="compact" layout_hide="swatches"'));
        $this->assertSame(0, substr_count($this->render('card_layout="compact" layout_hide="quick_view"'), 'fct-card-quick-view-button'));
        $this->assertGreaterThan(0, substr_count($this->render('card_layout="compact" quick_view="button"'), 'data-position="button"'));
        $this->assertGreaterThan(0, substr_count($this->render('card_layout="compact" swatch_count="1"'), 'data-fct-card-swatch-more'));
        $this->assertSame(4, substr_count($this->render('card_layout="compact" masonry="yes"'), 'fct-card-masonry'), 'the two products of the setup and the two added here');
    }

    public function test_layout_hide_turns_off_a_layout_cards_parts(): void
    {
        $this->ratingSettings('yes', 'yes');
        \FluentCart\App\Models\ProductVariation::query()->where('post_id', '!=', $this->productId)->update(['compare_price' => 9900, 'item_price' => 4500]);
        $shown = $this->render('card_layout="compact"');
        $this->assertStringContainsString('class="fct-sale-badge', $shown);
        $this->assertStringContainsString('class="fct-product-card-rating', $shown);

        // A plain list, since brackets and quotes would break the shortcode's attribute.
        $hidden = $this->render('card_layout="compact" layout_hide="sale_badge, rating,unknown"');
        $this->assertStringNotContainsString('class="fct-sale-badge', $hidden);
        $this->assertStringNotContainsString('class="fct-product-card-rating', $hidden);
        $this->assertSame(2, substr_count($hidden, 'fct-card-layout--compact'), 'the cards still render');
    }
}
