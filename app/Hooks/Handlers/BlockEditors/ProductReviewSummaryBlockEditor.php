<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;

class ProductReviewSummaryBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-review-summary';

    /**
     * The bare card lives only inside Rating Summary with Review — the
     * inserter offers that group at the top level instead.
     */
    public function ancestor(): array
    {
        return [
            'fluent-cart/product-review-summary-group',
        ];
    }

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

    public function blockAttributes(): array
    {
        return [
            // Must mirror the JS registration — WordPress prepares dynamic
            // block attributes against this server-side schema, so an
            // attribute missing here never reaches the render callback.
            'query_type'             => ['type' => 'string', 'default' => 'default'],
            'product_id'             => ['type' => ['string', 'number'], 'default' => ''],
            'starColor'              => ['type' => 'string', 'default' => '#f59e0b'],
        ];
    }

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductReviewSummary/ProductReviewSummaryBlockEditor.jsx',
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
                'title'       => __('Rating Summary', 'fluent-cart'),
                'description' => __('Average rating and star distribution for a product.', 'fluent-cart'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function useContext()
    {
        return ['fluent-cart/review_container_query'];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        // Nested under a review container (server-visible via the provided
        // context key) the parent owns the product — a stale custom pick
        // saved on this child must not win. Standalone, the block resolves
        // its own query.
        $insideContainer = $block instanceof \WP_Block
            && isset($block->context['fluent-cart/review_container_query']);

        $product = $insideContainer ? fluent_cart_get_current_product() : $this->resolveProduct($shortCodeAttribute);

        if (!$product) {
            return '';
        }

        AssetLoader::loadSingleProductAssets();

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => 'fct-review-summary-block',
        ]);

        ob_start();
        echo '<div ' . $wrapper_attributes . '>';
        (new ProductReviewRenderer($product->ID, [
            'starColor' => Arr::get($shortCodeAttribute, 'starColor', '#f59e0b'),
        ]))->renderSummarySection();
        echo '</div>';

        return ob_get_clean();
    }
}
