<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\App;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use FluentCart\App\Services\Reviews\LayoutPresets;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;

class ProductReviewsBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-reviews';

    public function supports(): array
    {
        return [
            'html'                 => false,
            'align'                => true,
            'typography'           => [
                'fontSize'                      => true,
                'lineHeight'                    => true,
                '__experimentalFontFamily'      => true,
                '__experimentalFontWeight'      => true,
                '__experimentalDefaultControls' => [
                    'fontSize' => true,
                ],
            ],
            'color'                => [
                'text'       => true,
                'background' => true,
            ],
            'spacing'              => [
                'margin'  => true,
                'padding' => true,
            ],
            '__experimentalBorder' => [
                'color'  => true,
                'radius' => true,
                'style'  => true,
                'width'  => true,
            ],
        ];
    }

    /**
     * Render children only inside renderContainer(), after setting product context.
     * WordPress's initial pass would otherwise render them twice.
     */
    protected function skipInnerBlocks(): bool
    {
        return true;
    }

    /**
     * This context key makes children inherit the container's product,
     * overriding any saved child product selection without editor-side pinning.
     */
    public function provideContext()
    {
        return [
            'fluent-cart/review_container_query' => 'query_type',
        ];
    }

    public function blockAttributes(): array
    {
        return [
            // Mirror the JS registration: WordPress drops attributes missing here before render.
            'query_type'         => ['type' => 'string', 'default' => 'default'],
            'product_id'         => ['type' => ['string', 'number'], 'default' => ''],
            // Picker state only; the saved child blocks define the rendered layout.
            'preset'             => ['type' => 'string', 'default' => 'classic'],
        ];
    }

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductReviews/ProductReviewsBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components', 'wp-data', 'wp-block-editor', 'wp-element']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            'admin/BlockEditor/ProductReviews/style/product-reviews-block-editor.scss'
        ];
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'        => $this->slugPrefix,
                'name'        => static::getEditorName(),
                'title'       => __('Product Reviews', 'fluent-cart'),
                'description' => __('Display customer reviews and ratings for a product.', 'fluent-cart'),
                // Gate preset insertion, not saved layouts or manual block composition.
                'is_pro'      => App::isProActive(),
                // Share preset definitions with other block composers.
                'presets'     => LayoutPresets::all(),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        $product = $this->resolveProduct($shortCodeAttribute);

        if (!$product) {
            return '';
        }

        AssetLoader::loadSingleProductAssets();

        // Child blocks render within the resolved product context.
        if ($block instanceof \WP_Block && !empty($block->inner_blocks)) {
            return $this->renderContainer($shortCodeAttribute, $block, $product);
        }

        // Without children, use the shortcode's default section layout.
        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => 'fct-product-reviews-block',
        ]);

        ob_start();
        (new ProductReviewRenderer($product->ID, []))->render();
        $inner = ob_get_clean();

        return static::wrapUnlessEmpty($wrapper_attributes, $inner);
    }

    protected function renderContainer(array $shortCodeAttribute, \WP_Block $block, $product)
    {
        // setup_postdata() updates ProductDataSetup through the_post.
        // Restore the outer product when nested; wp_reset_postdata() alone
        // would give following siblings the main-query post instead.
        $isCustom = Arr::get($shortCodeAttribute, 'query_type', 'default') === 'custom';
        $previousProduct = $isCustom ? fluent_cart_get_current_product() : null;

        if ($isCustom) {
            setup_postdata($product->ID);
        }

        $innerContent = '';
        foreach ($block->inner_blocks as $innerBlock) {
            if (isset($innerBlock->parsed_block)) {
                $innerContent .= $innerBlock->render();
            }
        }

        if ($isCustom) {
            if ($previousProduct) {
                setup_postdata($previousProduct->ID);
            } else {
                wp_reset_postdata();
            }
        }

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => 'fct-product-reviews-block fct-product-reviews-container',
        ]);

        return static::wrapUnlessEmpty($wrapper_attributes, $innerContent);
    }
}
