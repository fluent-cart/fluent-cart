<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Services\Renderer\ProductCardRender;
use FluentCart\App\Services\Translations\TransStrings;

class ProductRatingBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-rating';

    public function supports(): array
    {
        return [
            'html'                 => false,
            'align'                => ['left', 'center', 'right'],
            'typography'           => [
                'fontSize'                      => true,
                'lineHeight'                    => true,
                '__experimentalDefaultControls' => [
                    'fontSize' => true,
                ],
            ],
            'color'                => [
                'text' => true,
            ],
            'spacing'              => [
                'margin'  => true,
                'padding' => true,
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
        ];
    }

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductRating/ProductRatingBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components', 'wp-data', 'wp-block-editor', 'wp-element']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            'admin/BlockEditor/ProductRating/style/product-rating-block-editor.scss'
        ];
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'        => $this->slugPrefix,
                'name'        => static::getEditorName(),
                'title'       => __('Product Rating', 'fluent-cart'),
                'description' => __('Display the star rating for a product.', 'fluent-cart'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function useContext()
    {
        // Only Related Products provides this key — its presence tells the
        // render which store toggle governs this placement.
        return ['fluent-cart/related_product_ids'];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        // Store-level kill switch: Settings → Store Settings → Product Page
        // → Product Rating. The block controls per-layout presence (it ships
        // in the loop templates and editors remove it freely); these toggles
        // turn ratings off store-wide without editing every page.
        $isRelevantContext = $block instanceof \WP_Block
            && isset($block->context['fluent-cart/related_product_ids']);

        $ratingVisibilitySettingKey = $isRelevantContext
            ? 'show_rating_in_relevant'
            : 'show_rating_in_shop';

        if ((new \FluentCart\Api\StoreSettings())->get($ratingVisibilitySettingKey, 'yes') !== 'yes') {
            return '';
        }

        $product = $this->resolveProduct($shortCodeAttribute);

        if (!$product) {
            return '';
        }

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => 'fct-product-card-rating',
        ]);

        ob_start();
        (new ProductCardRender($product))->renderStarRatingBlock($wrapper_attributes);
        return ob_get_clean();
    }
}
