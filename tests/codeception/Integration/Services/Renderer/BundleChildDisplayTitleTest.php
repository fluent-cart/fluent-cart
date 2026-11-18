<?php

namespace Tests\Integration\Services\Renderer;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\Renderer\CartItemRenderer;
use FluentCart\App\Services\Renderer\ProductRenderer;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

class BundleChildDisplayTitleTest extends WPTestCase
{
    public function test_cart_bundle_children_omit_duplicate_simple_variant_titles(): void
    {
        $item = [
            'child_variants' => [
                [
                    'post_title'      => 'Simple Child',
                    'variation_title' => 'Simple Child',
                ],
                [
                    'post_title'      => 'Variable Child',
                    'variation_title' => 'Red',
                ],
                [
                    'post_title'      => 'Third Simple Child',
                    'variation_title' => 'Third Simple Child',
                ],
            ],
        ];

        ob_start();
        (new CartItemRenderer($item))->renderChildVariants();
        $html = ob_get_clean();

        $this->assertStringContainsString('Simple Child', $html, 'A simple child keeps its product title');
        $this->assertStringNotContainsString('Simple Child - Simple Child', $html, 'A simple child must not repeat its identical variant title');
        $this->assertStringContainsString('Variable Child - Red', $html, 'A variable child keeps the product and variant titles');
        $this->assertStringNotContainsString('Third Simple Child - Third Simple Child', $html, 'Expanded bundle children use the same simple-title rule');
    }

    public function test_product_bundle_page_uses_the_same_child_title_rule(): void
    {
        $simpleChild = (new ProductFactory())
            ->withVariantAttributes(['variation_title' => 'Simple Child'])
            ->create(['post_title' => 'Simple Child']);
        $variableChild = (new ProductFactory())
            ->withVariantAttributes(['variation_title' => 'Red'])
            ->create(['post_title' => 'Variable Child']);
        $thirdChild = (new ProductFactory())
            ->withVariantAttributes(['variation_title' => 'Third Simple Child'])
            ->create(['post_title' => 'Third Simple Child']);
        $bundle = (new ProductFactory())->createBundle([
            $simpleChild->variants->first()->id,
            $variableChild->variants->first()->id,
            $thirdChild->variants->first()->id,
        ], ['post_title' => 'Bundle Product']);

        ob_start();
        (new ProductRenderer($bundle))->renderVariationsBundleProduct($bundle->variants->first());
        $html = ob_get_clean();

        $this->assertStringNotContainsString('Simple Child - Simple Child', $html, 'The product page must not repeat a simple child title');
        $this->assertStringContainsString('Variable Child - Red', $html, 'The product page must preserve distinct child variant titles');
        $this->assertStringNotContainsString('Third Simple Child - Third Simple Child', $html, 'The product page expanded list uses the same simple-title rule');
    }

    /**
     * @dataProvider bundleChildTitleCases
     */
    public function test_bundle_child_title_format_handles_empty_labels($productTitle, $variationTitle, $expected): void
    {
        $this->assertSame(
            $expected,
            Helper::formatBundleChildTitle($productTitle, $variationTitle),
            'Bundle child display titles should not contain a dangling separator'
        );
    }

    public function bundleChildTitleCases(): array
    {
        return [
            ['Simple Child', 'Simple Child', 'Simple Child'],
            ['Variable Child', 'Red', 'Variable Child - Red'],
            ['Simple Child', '', 'Simple Child'],
            ['', 'Red', 'Red'],
            ['', '', ''],
        ];
    }
}
