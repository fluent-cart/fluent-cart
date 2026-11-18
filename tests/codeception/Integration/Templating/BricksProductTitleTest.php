<?php

namespace Tests\Integration\Templating;

use FluentCart\App\Modules\Templating\Bricks\Elements\ProductTitle;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * The Bricks Product Title element: the current product, or one picked like the other
 * single-product elements, so it works outside a product page too.
 */
class BricksProductTitleTest extends WPTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/Support/Stubs/BricksElementStubs.php';
    }

    private function render(array $settings, int $postId): string
    {
        $element = new ProductTitle();
        $element->settings = $settings;
        $element->post_id = $postId;
        ob_start();
        $element->render();

        return (string) ob_get_clean();
    }

    public function test_it_offers_the_same_product_source_as_the_other_single_product_elements(): void
    {
        $element = new ProductTitle();
        $element->set_controls();

        $this->assertSame('default', $element->controls['queryType']['default'] ?? null);
        $this->assertSame(['queryType', '=', 'custom'], $element->controls['productId']['required'] ?? null);
        $this->assertSame([['queryType', '=', 'custom'], ['productId', '=', '']], $element->controls['manualProductId']['required'] ?? null);
    }

    public function test_a_picked_product_shows_its_own_title_and_link(): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'Bricks Title Picked']);
        $pageId = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Bricks Title Page']);

        $picked = $this->render(['queryType' => 'custom', 'productId' => (string) $product->ID, 'linkToProduct' => true], $pageId);
        $this->assertStringContainsString('Bricks Title Picked', $picked);
        $this->assertStringNotContainsString('Bricks Title Page', $picked);
        $this->assertStringContainsString(esc_url(get_permalink($product->ID)), $picked);

        $manual = $this->render(['queryType' => 'custom', 'manualProductId' => (string) $product->ID], $pageId);
        $this->assertStringContainsString('Bricks Title Picked', $manual, 'the manual ID is used when nothing is picked');
    }

    public function test_by_default_it_shows_the_current_post(): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'Bricks Title Current']);

        $this->assertStringContainsString('Bricks Title Current', $this->render([], (int) $product->ID));
    }
}
