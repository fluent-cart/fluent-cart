<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;

class ProductReviewSummaryGroupBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-review-summary-group';

    public function supports(): array
    {
        return [
            'html'                 => false,
            'align'                => true,
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
     * WordPress renders the inner blocks before the callback runs, outside
     * the custom-product context.
     */
    protected function skipInnerBlocks(): bool
    {
        return true;
    }

    /**
     * Children detect nesting server-side by this key's presence (the
     * related_product_ids pattern) and follow the group's product. The
     * group also consumes the key itself: nested under Product Reviews it
     * defers to that container's product the same way its children do.
     */
    public function provideContext()
    {
        return [
            'fluent-cart/review_container_query' => 'query_type',
        ];
    }

    public function useContext()
    {
        return ['fluent-cart/review_container_query'];
    }

    public function blockAttributes(): array
    {
        return [
            // Must mirror the JS registration — WordPress prepares dynamic
            // block attributes against this server-side schema, so an
            // attribute missing here never reaches the render callback.
            'query_type' => ['type' => 'string', 'default' => 'default'],
            'product_id' => ['type' => ['string', 'number'], 'default' => ''],
        ];
    }

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductReviewSummaryGroup/ProductReviewSummaryGroupBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components', 'wp-data', 'wp-block-editor', 'wp-element']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            // The review block editor previews share one stylesheet — it is
            // scoped to every review block wrapper. Declaring it here keeps
            // this block styled on production even if the Product Reviews
            // container block ever stops enqueuing it.
            'admin/BlockEditor/ProductReviews/style/product-reviews-block-editor.scss'
        ];
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'        => $this->slugPrefix,
                'name'        => static::getEditorName(),
                'title'       => __('Rating Summary with Review', 'fluent-cart'),
                'description' => __('Rating summary card with a Write a Review button for a product.', 'fluent-cart'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        // Nested under a Product Reviews container (server-visible via the
        // provided context key), the parent owns the product — the group's
        // own saved query must not win.
        $insideContainer = $block instanceof \WP_Block
            && isset($block->context['fluent-cart/review_container_query']);

        $product = $insideContainer ? fluent_cart_get_current_product() : $this->resolveProduct($shortCodeAttribute);

        if (!$product) {
            return '';
        }

        AssetLoader::loadSingleProductAssets();

        // A custom pick swaps the product context through setup_postdata()
        // and restores the previously current product the same way the
        // Product Reviews container does, keeping nesting stack-safe with
        // no globals written.
        $isCustom = !$insideContainer && Arr::get($shortCodeAttribute, 'query_type', 'default') === 'custom';
        $previousProduct = $isCustom ? fluent_cart_get_current_product() : null;

        if ($isCustom) {
            setup_postdata($product->ID);
        }

        $innerContent = '';
        if ($block instanceof \WP_Block && !empty($block->inner_blocks)) {
            foreach ($block->inner_blocks as $innerBlock) {
                if (isset($innerBlock->parsed_block)) {
                    $innerContent .= $innerBlock->render();
                }
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
            'class' => 'fct-review-summary-group',
        ]);

        return '<div ' . $wrapper_attributes . '>' . $innerContent . '</div>';
    }
}
