<?php

namespace Tests\Integration\Services\Renderer;

use FluentCart\App\Services\Renderer\ProductCardRender;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * A product with no image shows the product placeholder illustration in its
 * card — an open box holding a picture — instead of the generic image icon.
 *
 * The illustration ships as an optimised 1200px WebP (≈37 KB, sharp on 2x screens): the source
 * artwork was a ≈960 KB SVG wrapping a 1254px PNG, far too heavy for an
 * image every image-less card downloads.
 */
class ProductCardPlaceholderTest extends WPTestCase
{
    protected function capture(ProductCardRender $card): string
    {
        ob_start();
        $card->renderProductImage();

        return (string) ob_get_clean();
    }

    public function test_an_image_less_card_shows_the_product_placeholder(): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'No Image Product']);
        $html = $this->capture(new ProductCardRender($product));

        $this->assertStringContainsString('images/product-placeholder.webp', $html);
        $this->assertStringNotContainsString('images/placeholder.svg', $html);
        $this->assertStringContainsString('Placeholder image for No Image Product', $html, 'the placeholder keeps its descriptive alt text');
        $this->assertMatchesRegularExpression('#class="fct-product-card-image-wrap is-placeholder"#', $html, 'the wrapper is marked so a divider separates it from the details');
    }

    public function test_the_placeholder_asset_is_shipped_and_light(): void
    {
        $file = FLUENTCART_PLUGIN_PATH . 'resources/images/product-placeholder.webp';

        $this->assertFileExists($file);
        $this->assertLessThan(40 * 1024, filesize($file), 'a placeholder every image-less card loads stays small');
        $this->assertSame('RIFF', (string) file_get_contents($file, false, null, 0, 4), 'it is a real WebP');
    }
}
