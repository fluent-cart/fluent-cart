<?php

namespace Tests\Integration\Templating;

use FluentCart\App\Modules\Templating\Bricks\Elements\ProductContent;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

class BricksProductContentTest extends WPTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/Support/Stubs/BricksElementStubs.php';
    }

    public function test_block_content_renders_without_empty_paragraphs_and_classic_content_keeps_paragraphs(): void
    {
        $blockContent = "<!-- wp:heading -->\n<h2>Block heading</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Block copy</p>\n<!-- /wp:paragraph -->";
        $blockProduct = (new ProductFactory())->create(['post_content' => $blockContent]);
        $blockElement = new ProductContent();
        $blockElement->post_id = (int) $blockProduct->ID;

        ob_start();
        $blockElement->render();
        $blockHtml = (string) ob_get_clean();

        $this->assertMatchesRegularExpression('/<h2\b[^>]*>Block heading<\/h2>/', $blockHtml, 'The Gutenberg heading stays rendered.');
        $this->assertStringContainsString('Block copy', $blockHtml, 'The Gutenberg paragraph stays visible.');
        $this->assertStringNotContainsString('<!-- wp:', $blockHtml, 'Opening block delimiters are removed.');
        $this->assertStringNotContainsString('<!-- /wp:', $blockHtml, 'Closing block delimiters are removed.');
        $this->assertDoesNotMatchRegularExpression('/<p>\s*<\/p>/', $blockHtml, 'Block rendering leaves no empty paragraphs.');

        $classicProduct = (new ProductFactory())->create(['post_content' => "Classic first\n\nClassic second"]);
        $classicElement = new ProductContent();
        $classicElement->post_id = (int) $classicProduct->ID;

        ob_start();
        $classicElement->render();
        $classicHtml = (string) ob_get_clean();

        $this->assertStringContainsString('<p>Classic first</p>', $classicHtml, 'Classic content keeps paragraph formatting.');
        $this->assertStringContainsString('<p>Classic second</p>', $classicHtml, 'A second classic paragraph remains separate.');
    }
}
