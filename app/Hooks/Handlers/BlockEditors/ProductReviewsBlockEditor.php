<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductReviewRenderer;
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
            'showSummary'        => ['type' => 'boolean', 'default' => true],
            'showSortControls'   => ['type' => 'boolean', 'default' => true],
            'showVerifiedBadge'  => ['type' => 'boolean', 'default' => true],
            'showReviewDate'     => ['type' => 'boolean', 'default' => true],
            'showReviewerName'   => ['type' => 'boolean', 'default' => true],
            'showViewReply'      => ['type' => 'boolean', 'default' => true],
            'starColor'          => ['type' => 'string', 'default' => '#f59e0b'],
            'defaultSortBy'      => ['type' => 'string', 'default' => 'created_at'],
            'defaultSortOrder'   => ['type' => 'string', 'default' => 'DESC'],
            'perPage'            => ['type' => 'number', 'default' => 0],
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

        // Container mode: the block was saved with child blocks (Rating
        // Summary / Write a Review / Review List), so it only resolves the
        // product, sets it as the current-product context, and renders the
        // children. Blocks saved before the split are self-closing (no inner
        // blocks) and fall through to the legacy combined render below, so
        // existing pages keep working untouched.
        if ($block instanceof \WP_Block && !empty($block->inner_blocks)) {
            return $this->renderContainer($shortCodeAttribute, $block, $product);
        }

        $options = [
            'showSummary'       => Arr::get($shortCodeAttribute, 'showSummary', true),
            'showSortControls'  => Arr::get($shortCodeAttribute, 'showSortControls', true),
            'showVerifiedBadge' => Arr::get($shortCodeAttribute, 'showVerifiedBadge', true),
            'showReviewDate'    => Arr::get($shortCodeAttribute, 'showReviewDate', true),
            'showReviewerName'  => Arr::get($shortCodeAttribute, 'showReviewerName', true),
            'showViewReply'     => Arr::get($shortCodeAttribute, 'showViewReply', true),
            'starColor'         => Arr::get($shortCodeAttribute, 'starColor', '#f59e0b'),
            'defaultSortBy'     => Arr::get($shortCodeAttribute, 'defaultSortBy', 'created_at'),
            'defaultSortOrder'  => Arr::get($shortCodeAttribute, 'defaultSortOrder', 'DESC'),
            'perPage'           => (int) Arr::get($shortCodeAttribute, 'perPage', 0),
        ];

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => 'fct-product-reviews-block',
        ]);

        ob_start();
        echo '<div ' . $wrapper_attributes . '>';
        (new ProductReviewRenderer($product->ID, $options))->render();
        echo '</div>';

        return ob_get_clean();
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

        return '<div ' . $wrapper_attributes . '>' . $innerContent . '</div>';
    }
}
