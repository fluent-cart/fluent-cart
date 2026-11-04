<?php

namespace Tests\Integration\Services\Renderer;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\Payments\PaymentReceipt;
use FluentCart\App\Services\Renderer\CartItemRenderer;
use FluentCart\App\Services\Renderer\CartRenderer;
use FluentCart\App\Services\Renderer\ProductCardRender;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\RendersProductMarkup;

/**
 * Every surface that shows a product without an image shows the product
 * placeholder illustration (an open box holding a picture), not the generic
 * grey image icon (images/placeholder.svg).
 *
 * One helper owns the URL: Helper::getProductPlaceholderUrl() for the web
 * (1200px WebP) and Helper::getProductPlaceholderEmailUrl() for email clients
 * and PDFs (a 300px PNG — Outlook does not render WebP).
 *
 * Carts store the placeholder URL in cart_data.featured_media, so a cart built
 * before this change still carries images/placeholder.svg. The renderers map
 * that stored URL to the new illustration too.
 */
class ProductPlaceholderEverywhereTest extends WPTestCase
{
    use RendersProductMarkup;

    const OLD = 'images/placeholder.svg';
    const WEB = 'images/product-placeholder.webp';
    const EMAIL = 'images/product-placeholder.png';

    public function test_the_helper_returns_the_web_and_email_placeholders(): void
    {
        $this->assertStringEndsWith(self::WEB, Helper::getProductPlaceholderUrl());
        $this->assertStringEndsWith(self::EMAIL, Helper::getProductPlaceholderEmailUrl());
    }

    public function test_a_product_without_an_image_has_the_illustration_as_its_thumbnail(): void
    {
        $product = (new ProductFactory())->create();

        $this->assertStringEndsWith(self::WEB, $product->thumbnail, 'the thumbnail accessor feeds REST, admin and carts');
    }

    public function test_the_single_product_gallery_shows_the_illustration(): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'Gallery Without Images']);
        $html = $this->renderSingleProduct($product);

        $this->assertStringContainsString(self::WEB, $html);
        $this->assertStringNotContainsString(self::OLD, $html);
    }

    public function test_the_card_uses_the_helper(): void
    {
        $product = (new ProductFactory())->create();

        ob_start();
        (new ProductCardRender($product))->renderProductImage();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString(Helper::getProductPlaceholderUrl(), $html);
    }

    public function test_a_checkout_line_without_an_image_shows_the_illustration(): void
    {
        $this->assertStringContainsString(self::WEB, $this->checkoutLine(['title' => 'No Image']));
    }

    public function test_a_checkout_line_stored_with_the_old_placeholder_shows_the_illustration(): void
    {
        $html = $this->checkoutLine(['title' => 'Old Cart', 'featured_media' => $this->oldPlaceholderUrl()]);

        $this->assertStringContainsString(self::WEB, $html);
        $this->assertStringNotContainsString(self::OLD, $html);
    }

    public function test_a_checkout_line_keeps_a_real_product_image(): void
    {
        $html = $this->checkoutLine(['title' => 'Photo', 'featured_media' => 'https://example.com/shirt.jpg']);

        $this->assertStringContainsString('https://example.com/shirt.jpg', $html);
        $this->assertStringNotContainsString(self::WEB, $html);
    }

    public function test_a_cart_drawer_line_shows_the_illustration_for_empty_and_old_images(): void
    {
        foreach (['', $this->oldPlaceholderUrl()] as $stored) {
            $html = $this->cartLine(['title' => 'Drawer Item', 'featured_media' => $stored]);

            $this->assertStringContainsString(self::WEB, $html);
            $this->assertStringNotContainsString(self::OLD, $html);
        }
    }

    public function test_the_payment_receipt_uses_the_email_safe_png(): void
    {
        $product = (new ProductFactory())->create();
        $variantId = (int) $product->variants->first()->id;

        $receipt = new PaymentReceipt([
            'order_items' => [
                ['object_id' => $variantId, 'payment_type' => 'onetime'],
                ['object_id' => 999999999, 'payment_type' => 'onetime'],
            ],
        ]);

        $items = array_values($receipt->getItems());

        $this->assertStringEndsWith(self::EMAIL, $items[0]['media_url'], 'an image-less product gets the PNG');
        $this->assertStringEndsWith(self::EMAIL, $items[1]['media_url'], 'a deleted variation gets the PNG');
    }

    public function test_the_email_png_is_shipped_small_and_square(): void
    {
        $file = FLUENTCART_PLUGIN_PATH . 'resources/' . self::EMAIL;

        $this->assertFileExists($file);
        $this->assertLessThan(24 * 1024, filesize($file));
        $this->assertSame("\x89PNG", (string) file_get_contents($file, false, null, 0, 4), 'it is a real PNG');

        $size = getimagesize($file);
        $this->assertSame([300, 300], [$size[0], $size[1]]);
    }

    private function oldPlaceholderUrl(): string
    {
        return FLUENTCART_URL . 'assets/' . self::OLD;
    }

    private function checkoutLine(array $item): string
    {
        ob_start();
        (new CartItemRenderer($item))->renderImage();

        return (string) ob_get_clean();
    }

    private function cartLine(array $item): string
    {
        $renderer = new CartRenderer([]);
        // render() sets both while it loops over the cart items.
        foreach (['currentCartItem' => $item, 'url' => 'https://example.com/item'] as $name => $value) {
            $property = new \ReflectionProperty(CartRenderer::class, $name);
            $property->setAccessible(true);
            $property->setValue($renderer, $value);
        }

        ob_start();
        $renderer->renderImage();

        return (string) ob_get_clean();
    }
}
