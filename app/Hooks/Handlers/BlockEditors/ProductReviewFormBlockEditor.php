<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;

/**
 * The review submission form on its own — no trigger, no overlay, just the
 * fields printed where the block is placed.
 *
 * The sibling "Write a Review" block (write-a-review-button) is the trigger
 * half: a button that opens the same form in a drawer or a modal. Splitting
 * them lets a store put the button on a product page and the bare form on a
 * dedicated review page, each paced independently.
 */
class ProductReviewFormBlockEditor extends BlockEditor
{
    /**
     * Reused on purpose: this slug was the button-and-drawer block during
     * development (now write-a-review-button). Reviews have not shipped, so
     * no saved content carries the old block.
     */
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
            'query_type' => ['type' => 'string', 'default' => 'default'],
            'product_id' => ['type' => ['string', 'number'], 'default' => ''],
            // 'steps' walks the same wizard the drawer does, in place;
            // 'inline' puts every field on screen at once.
            'layout'     => ['type' => 'string', 'default' => 'inline'],
            'starColor'  => ['type' => 'string', 'default' => '#f59e0b'],
        ];
    }

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductReviewForm/ProductReviewFormBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components', 'wp-data', 'wp-block-editor', 'wp-element', 'wp-server-side-render']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            'admin/BlockEditor/ProductReviewForm/style/product-review-form-block-editor.scss',
            // The canvas renders the block's own markup, so it needs the
            // storefront stylesheet that markup is written against. The base
            // class re-enqueues styles on enqueue_block_assets, which is what
            // carries them into the WP 6.3+ editor iframe.
            'public/single-product/reviews.scss',
        ];
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'        => $this->slugPrefix,
                'name'        => static::getEditorName(),
                'title'       => __('Review Form', 'fluent-cart'),
                'description' => __('The review submission form itself, printed on the page.', 'fluent-cart'),
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

        // Just the form's own assets. This block is the whole reason a review
        // page exists; it has no gallery, no product card and no review list
        // to serve, and loading their scripts and styles would cost every
        // visitor to that page for nothing.
        AssetLoader::loadReviewSubmissionFormAssets();

        $layout = Arr::get($shortCodeAttribute, 'layout', 'inline');
        if (!\in_array($layout, ['steps', 'inline'], true)) {
            $layout = 'inline';
        }

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => 'fct-review-form-block',
        ]);

        ob_start();
        // container 'none' is what makes this block the form rather than a
        // trigger: no CTA is rendered and nothing is hidden behind an overlay.
        (new ProductReviewRenderer($product->ID, [
            'container' => 'none',
            'layout'    => $layout,
            'starColor' => sanitize_hex_color(Arr::get($shortCodeAttribute, 'starColor', '')) ?: '#f59e0b',
        ]))->renderForm();
        $inner = ob_get_clean();

        return static::wrapUnlessEmpty($wrapper_attributes, $inner);
    }
}
