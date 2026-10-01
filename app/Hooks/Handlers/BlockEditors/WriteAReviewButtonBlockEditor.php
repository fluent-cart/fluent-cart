<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;

class WriteAReviewButtonBlockEditor extends BlockEditor
{
    protected static string $editorName = 'write-a-review-button';

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
            // The block is the trigger; these two decide what it opens.
            // 'container' is where the form appears, 'layout' is how it
            // paces the fields once it is there.
            'container'  => ['type' => 'string', 'default' => 'drawer'],
            'layout'     => ['type' => 'string', 'default' => 'inline'],
            'addReviewButtonText'   => ['type' => 'string', 'default' => ''],
            'editReviewButtonText'  => ['type' => 'string', 'default' => ''],
            'loginReviewButtonText' => ['type' => 'string', 'default' => ''],
        ];
    }

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/WriteAReviewButton/WriteAReviewButtonBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components', 'wp-data', 'wp-block-editor', 'wp-element']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            'admin/BlockEditor/WriteAReviewButton/style/write-a-review-button-block-editor.scss',
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

        // The form's own script and stylesheet: the block is a trigger and
        // the form it opens, and needs none of the gallery, product card or
        // review list the full single-product bundle carries. On a product
        // page that bundle is enqueued anyway, under the same form handle.
        AssetLoader::loadReviewSubmissionFormAssets();

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => 'fct-write-a-review-button-block',
        ]);

        $container = Arr::get($shortCodeAttribute, 'container', 'drawer');
        if (!\in_array($container, ['drawer', 'modal'], true)) {
            $container = 'drawer';
        }

        $layout = Arr::get($shortCodeAttribute, 'layout', 'inline');
        if (!\in_array($layout, ['steps', 'inline'], true)) {
            $layout = 'inline';
        }

        ob_start();
        // The block is a button plus the overlay it opens: the trigger always
        // renders (it is the block's whole purpose), and the section below
        // carries the drawer or modal — clicking any CTA opens it via
        // delegation. The form itself, standalone, is its own block.
        $renderer = new ProductReviewRenderer($product->ID, [
            'container'    => $container,
            'layout'       => $layout,
            'ctaAddText'   => sanitize_text_field(Arr::get($shortCodeAttribute, 'addReviewButtonText', '')),
            'ctaEditText'  => sanitize_text_field(Arr::get($shortCodeAttribute, 'editReviewButtonText', '')),
            'ctaLoginText' => sanitize_text_field(Arr::get($shortCodeAttribute, 'loginReviewButtonText', '')),
        ]);
        $renderer->renderWriteReviewCta();
        $renderer->renderForm();
        $inner = ob_get_clean();

        return static::wrapUnlessEmpty($wrapper_attributes, $inner);
    }
}
