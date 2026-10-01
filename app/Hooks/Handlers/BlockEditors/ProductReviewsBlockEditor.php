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
     * Container pattern (same as ProductInfoBlockEditor): without this,
     * WordPress renders the inner blocks once before the render callback
     * runs — outside the custom-product context and with all their query
     * and hook side effects — and renderContainer() then renders them
     * again.
     */
    protected function skipInnerBlocks(): bool
    {
        return true;
    }

    /**
     * Children detect nesting server-side by this key's presence (the
     * related_product_ids pattern) and follow the container's product,
     * so a stale custom pick saved on a child can never win — saved
     * content renders without the editor's query pinning ever running.
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
            // Must mirror the JS registration — WordPress prepares dynamic
            // block attributes against this server-side schema, so an
            // attribute missing here never reaches the render callback.
            'query_type'         => ['type' => 'string', 'default' => 'default'],
            'product_id'         => ['type' => ['string', 'number'], 'default' => ''],
            // Mirrors the JS registration. Only remembers which layout the
            // picker last built the section from — nothing reads it when
            // rendering, because the blocks are the layout.
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
                // Which layout presets the picker may apply. An editor-side
                // gate on a convenience, not a lock on a capability: a preset
                // only assembles blocks that are free in their own right, so
                // the same arrangement stays buildable by hand and a site that
                // lapses keeps the layouts it already published.
                'is_pro'      => App::isProActive(),
                // The layouts the picker builds from. Declared in PHP so the
                // block editor and anything else that composes these blocks
                // read one description of them rather than keeping a copy each.
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

        // Container mode: the block holds child blocks (Rating Summary /
        // Write a Review / Review List), so it only resolves the product, sets
        // it as the current-product context, and renders the children. That is
        // what the editor scaffolds, so it is what a container normally is.
        if ($block instanceof \WP_Block && !empty($block->inner_blocks)) {
            return $this->renderContainer($shortCodeAttribute, $block, $product);
        }

        // Emptied of every child, it draws the whole section itself, to the
        // renderer's own defaults — the same thing the shortcode draws.
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
        // A custom pick swaps the product context through setup_postdata()
        // only — its the_post action drives ProductDataSetup, so no globals
        // are written here. Restoring goes through the same API: re-running
        // setup_postdata() for whatever product was current before keeps the
        // swap stack-safe when this container is nested inside another
        // custom-product context, where wp_reset_postdata() alone would hand
        // sibling blocks the main-query post instead of the outer product.
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
