<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;

class ProductReviewFormBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-review-form';

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
            // starColor was dropped with the trigger-button redesign, so it
            // is deliberately absent here too.
            'query_type' => ['type' => 'string', 'default' => 'default'],
            'product_id' => ['type' => ['string', 'number'], 'default' => ''],
            'addReviewButtonText'   => ['type' => 'string', 'default' => ''],
            'editReviewButtonText'  => ['type' => 'string', 'default' => ''],
            'loginReviewButtonText' => ['type' => 'string', 'default' => ''],
        ];
    }

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductReviewForm/ProductReviewFormBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components', 'wp-data', 'wp-block-editor', 'wp-element']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            'admin/BlockEditor/ProductReviewForm/style/product-review-form-block-editor.scss',
            // Shared review-block preview stylesheet — the standalone
            // skeleton preview uses its classes.
            'admin/BlockEditor/ProductReviews/style/product-reviews-block-editor.scss'
        ];
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'        => $this->slugPrefix,
                'name'        => static::getEditorName(),
                'title'       => __('Write a Review', 'fluent-cart'),
                'description' => __('A button that opens the review submission drawer for a product.', 'fluent-cart'),
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
            'class' => 'fct-review-form-block',
        ]);

        ob_start();
        echo '<div ' . $wrapper_attributes . '>';
        // The block is a button plus the drawer it opens: the trigger always
        // renders (it is the block's whole purpose), and the section below
        // carries the drawer — clicking any CTA opens it via delegation.
        $renderer = new ProductReviewRenderer($product->ID, [
            'ctaAddText'   => sanitize_text_field(Arr::get($shortCodeAttribute, 'addReviewButtonText', '')),
            'ctaEditText'  => sanitize_text_field(Arr::get($shortCodeAttribute, 'editReviewButtonText', '')),
            'ctaLoginText' => sanitize_text_field(Arr::get($shortCodeAttribute, 'loginReviewButtonText', '')),
        ]);
        $renderer->renderWriteReviewCta();
        $renderer->renderForm();
        echo '</div>';

        return ob_get_clean();
    }
}
