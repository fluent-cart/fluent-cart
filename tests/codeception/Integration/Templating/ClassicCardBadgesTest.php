<?php

namespace Tests\Integration\Templating;

use FluentCart\App\Hooks\Handlers\BlockEditors\SoldOutBadgeBlockEditor;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\Renderer\ProductCardLayoutPresets;
use FluentCart\App\Services\Renderer\ProductCardRender;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * A discounted, sold-out product's Classic card draws both badges in its picture, sale first:
 * card-layouts.scss stacks the second under the first by that order.
 */
class ClassicCardBadgesTest extends WPTestCase
{
    private $registeredSoldOutBadge = false;

    public function setUp(): void
    {
        parent::setUp();
        // The block is registered only with Stock Management on, which the test store leaves off.
        if (!\WP_Block_Type_Registry::get_instance()->is_registered('fluent-cart/sold-out-badge')) {
            SoldOutBadgeBlockEditor::make()->init();
            $this->registeredSoldOutBadge = true;
        }
        // A card layout built by an earlier test, before this block was registered, lacks it.
        ProductCardLayoutPresets::resetCache();
    }

    public function tearDown(): void
    {
        if ($this->registeredSoldOutBadge) {
            unregister_block_type('fluent-cart/sold-out-badge');
        }
        ProductCardLayoutPresets::resetCache();
        parent::tearDown();
    }

    public function test_a_classic_card_draws_the_sale_badge_then_the_sold_out_badge_in_its_picture(): void
    {
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => 'out-of-stock'])
            ->withVariantAttributes(['compare_price' => 9900, 'item_price' => 4500, 'stock_status' => 'out-of-stock'])
            ->create(['post_title' => 'Sale And Sold Out', 'post_status' => 'publish']);
        $product = Product::query()->with(['detail', 'variants'])->find($product->ID);

        ob_start();
        (new ProductCardRender($product, ['card_layout' => 'classic']))->render();
        $html = (string) ob_get_clean();
        if (getenv('FCT_DUMP_CARD')) {
            file_put_contents(getenv('FCT_DUMP_CARD'), $html);
        }

        $this->assertStringContainsString('fct-product-card-template', $html, 'a Classic layout card');
        $this->assertMatchesRegularExpression(
            '#class="fct-product-image-inner-blocks">\s*<span class="fct-sale-badge[^"]*fct-sale-badge--top-left[^"]*">.*?</span>\s*<span class="fct-sold-out-badge[^"]*fct-sold-out-badge--top-left#s',
            $html,
            'both badges, top left, sale first, in the picture\'s inner blocks'
        );
    }
}
