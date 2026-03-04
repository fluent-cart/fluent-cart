<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors\RelatedProduct\InnerBlocks;

use FluentCart\Api\Resource\ShopResource;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\Framework\Support\Arr;

class InnerBlocks
{
    public static $parentBlock = 'fluent-cart/related-product';

    private const MIN_COLUMNS = 1;

    private const MAX_COLUMNS = 6;

    public static function register()
    {
        $self = new self();
        $blocks = $self->getInnerBlocks();

        foreach ($blocks as $block) {
            $args = [
                'apiVersion'   => 3,
                'api_version'  => 3,
                'version'      => 3,
                'title'        => $block['title'],
                'category'     => 'fluent-cart',
                'parent'       => array_merge($block['parent'] ?? [], [static::$parentBlock]),
                'supports'     => Arr::get($block, 'supports', []),
                'attributes'   => Arr::get($block, 'attributes', []),
                'uses_context' => Arr::get($block, 'uses_context', []),
            ];

            // Only add render_callback if it exists
            if (!empty($block['callback']) && is_callable($block['callback'])) {
                $args['render_callback'] = $block['callback'];
            }

            register_block_type($block['slug'], $args);
        }
    }

    public function getInnerBlocks(): array
    {
        return [
            [
                'title'        => __('Product Template', 'fluent-cart'),
                'slug'         => 'fluent-cart/product-template',
                'callback'     => [$this, 'renderProductTemplate'],
                'component'    => 'ProductTemplateBlock',
                'icon'         => 'screenoptions',
                'parent'       => ['fluent-cart/related-product'],
                'supports'     => [
                    'reusable' => false,
                    'html'     => false,
                ],
                'attributes'   => [],
                'uses_context' => [
                    'fluent-cart/related_product_ids',
                    'fluent-cart/order_by',
                    'fluent-cart/query_type',
                    'fluent-cart/product_id',
                    'fluent-cart/related_by_categories',
                    'fluent-cart/related_by_brands',
                    'fluent-cart/columns',
                    'fluent-cart/posts_per_page',
                    'fluent-cart/show_image',
                    'fluent-cart/show_title',
                    'fluent-cart/show_price',
                    'fluent-cart/show_button',
                ],
            ],
        ];
    }

    public function renderProductTemplate($attributes, $content, $block): string
    {
        $queryType = Arr::get($block->context, 'fluent-cart/query_type', 'custom');
        $orderBy = Arr::get($block->context, 'fluent-cart/order_by', 'title_asc');
        $postsPerPage = absint(Arr::get($block->context, 'fluent-cart/posts_per_page', 6));

        // Build relatedBy taxonomies from context
        $relatedBy = [];
        if (Arr::get($block->context, 'fluent-cart/related_by_categories', true)) {
            $relatedBy[] = 'product-categories';
        }
        if (Arr::get($block->context, 'fluent-cart/related_by_brands', true)) {
            $relatedBy[] = 'product-brands';
        }

        if ($queryType === 'default') {
            // Default mode: get related products from the current product context
            $currentProduct = fluent_cart_get_current_product();

            if (!$currentProduct) {
                return '<div class="fct-no-related-products"><p>' .
                       __('No related products found.', 'fluent-cart') .
                       '</p></div>';
            }

            $relatedProducts = ShopResource::getSimilarProducts(
                $currentProduct->ID,
                false,
                [
                    'related_by'     => $relatedBy,
                    'order_by'       => $orderBy,
                    'posts_per_page' => $postsPerPage,
                ]
            );
        } else {
            // Custom mode: get related products from the user-picked product_id
            $productId = absint(Arr::get($block->context, 'fluent-cart/product_id', 0));

            if (!$productId) {
                return '<div class="fct-no-related-products"><p>' .
                       __('No related products found.', 'fluent-cart') .
                       '</p></div>';
            }

            $relatedProducts = ShopResource::getSimilarProducts(
                $productId,
                false,
                [
                    'related_by'     => $relatedBy,
                    'order_by'       => $orderBy,
                    'posts_per_page' => $postsPerPage,
                ]
            );
        }

        if (empty($relatedProducts)) {
            return '<div class="fct-no-related-products"><p>' .
                   __('No related products found.', 'fluent-cart') .
                   '</p></div>';
        }

        $blockContext = $block->context;
        $columns = absint(Arr::get($block->context, 'fluent-cart/columns', 4));
        $columns = max(self::MIN_COLUMNS, min(self::MAX_COLUMNS, $columns));

        // Build list of hidden block slugs based on show/hide toggles
        $hiddenBlocks = [];
        if (!Arr::get($block->context, 'fluent-cart/show_image', true)) {
            $hiddenBlocks[] = 'fluent-cart/shopapp-product-image';
        }
        if (!Arr::get($block->context, 'fluent-cart/show_title', true)) {
            $hiddenBlocks[] = 'fluent-cart/shopapp-product-title';
        }
        if (!Arr::get($block->context, 'fluent-cart/show_price', true)) {
            $hiddenBlocks[] = 'fluent-cart/shopapp-product-price';
        }
        if (!Arr::get($block->context, 'fluent-cart/show_button', true)) {
            $hiddenBlocks[] = 'fluent-cart/shopapp-product-buttons';
        }
        $innerBlocksContent = '';

        // Wrap products in a grid
        $innerBlocksContent .= '<div class="fct-related-products-grid" style="' . '--fct-related-product-columns: ' . $columns . '">';

        foreach ($relatedProducts as $product) {
            setup_postdata($product->ID);

            if ($block instanceof \WP_Block && !empty($block->inner_blocks)) {
                $innerBlocksContent .= '<div class="fct-product-card">';

                // Render each inner block with the product context
                foreach ($block->inner_blocks as $inner_block) {
                    if (isset($inner_block->parsed_block)) {
                        $blockName = Arr::get($inner_block->parsed_block, 'blockName', '');
                        if (in_array($blockName, $hiddenBlocks, true)) {
                            continue;
                        }

                        $innerContext = array_merge($inner_block->context, $blockContext);
                        $instance = new \WP_Block($inner_block->parsed_block, $innerContext);
                        $innerBlocksContent .= $instance->render();
                    }
                }

                $innerBlocksContent .= '</div>';
            }
        }

        $innerBlocksContent .= '</div>';

        wp_reset_postdata();

        return $innerBlocksContent;
    }
}
