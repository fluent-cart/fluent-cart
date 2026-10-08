<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Services\Renderer\ProductCardRender;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;

class ProductRatingBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-rating';

    public function supports(): array
    {
        return [
            // Out of the inserter while the reviews module is off; the block
            // is still registered so the card templates that carry it keep
            // parsing (see actions.php). Mirrored in the JS registration.
            'inserter'             => ModuleSettings::isActive('reviews'),
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
            // How many reviews a product needs before its rating is worth
            // showing. 0 shows it always, including the empty five stars a
            // product with no reviews has.
            'minReviewCount' => ['type' => 'number', 'default' => 0],
            // And how good that rating has to be. 0 shows every rating; 4
            // shows only products averaging four stars or better.
            'minAverageRating' => ['type' => 'number', 'default' => 0],
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
                // The editor draws nothing while the module is off.
                'reviews_active' => ModuleSettings::isActive('reviews'),
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
        // No reviews module, no rating — whatever template carries the block.
        if (!ModuleSettings::isActive('reviews')) {
            return '';
        }

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
        (new ProductCardRender($product))->renderStarRatingBlock($wrapper_attributes, $shortCodeAttribute, $block);
        return ob_get_clean();
    }

    /**
     * Reviews counted for this product, from the aggregate the stars are
     * drawn from. A product whose detail row has not been created yet counts
     * as none rather than as an error.
     *
     * @param  \FluentCart\App\Models\Product $product
     * @return int
     */
    protected function reviewCount($product): int
    {
        return (int) Arr::get($this->ratingInfo($product), 'review_count', 0);
    }

    /**
     * The average the stars are drawn from, on the same terms.
     *
     * @param  \FluentCart\App\Models\Product $product
     * @return float
     */
    protected function averageRating($product): float
    {
        return (float) Arr::get($this->ratingInfo($product), 'average_rating', 0);
    }

    /**
     * @param  \FluentCart\App\Models\Product $product
     * @return array
     */
    protected function ratingInfo($product): array
    {
        $detail = $product->detail;

        return $detail ? ($detail->other_info ?: []) : [];
    }
}
