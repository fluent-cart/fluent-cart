<?php

namespace FluentCart\App\Modules\Templating\Bricks;


use FluentCart\App\Hooks\Handlers\CPTHandler;
use FluentCart\App\Modules\Data\ProductDataSetup;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductCardRender;

class DynamicData
{
    public function register()
    {
        add_filter('bricks/dynamic_tags_list', function ($tags) {
            $fctTags = $this->getTagsPairs();
            foreach ($fctTags as $name => $label) {
                $tags[] = [
                    'name'  => '{' . $name . '}',
                    'label' => $label,
                    'group' => 'Product - FluentCart',
                ];
            }
            return $tags;
        });

        add_filter('bricks/dynamic_data/render_tag', [$this, 'renderValue'], 20, 3);
        add_filter('bricks/dynamic_data/render_content', [$this, 'renderValue'], 20, 3);
        add_filter('bricks/frontend/render_data', [$this, 'renderValue'], 20, 3);
    }

    public function getTagsPairs()
    {
        return [
            'fct_product_title:linked' => __('Product Title', 'fluent-cart'),
            'fct_product_image'        => __('Product Image', 'fluent-cart'),
            'fct_product_excerpt'      => __('Product Excerpt', 'fluent-cart'),
            'fct_product_price'        => __('Product Price', 'fluent-cart'),
            'fct_product_button'       => __('Product Button', 'fluent-cart'),
            'fct_product_view_button'  => __('Product Button', 'fluent-cart'), // Legacy alias for backward compatibility
        ];
    }

    public function renderValue($tag, $post, $context = 'text')
    {
        if (!is_string($tag)) {
            return $tag;
        }

        if (strpos($tag, 'fct_product_') === false) {
            return $tag;
        }

        if (!$post) {
            $post = BricksHelper::getFormCurrentPost();
        }

        if (!$post || $post->post_type !== 'fluent-products') {
            return $tag;
        }

        $tagKey = str_replace(['{', '}'], '', $tag);

        // explode :
        $tagParts = explode(':', $tagKey);
        $tagName = array_shift($tagParts);

        switch ($tagName) {
            case 'fct_product_title':
                $productName = $post->post_title;
                if (in_array('linked', $tagParts)) {
                    $productName = '<a href="' . get_permalink($post) . '" data-fct-field="product-title">' . $productName . '</a>';
                } else {
                    $productName = '<span data-fct-field="product-title">' . $productName . '</span>';
                }
                return $productName;
            case 'fct_product_image':
                $productModel = ProductDataSetup::getProductModel($post->ID);
                if ($productModel) {
                    ob_start();
                    (new ProductCardRender($productModel))->renderProductImage();
                    $content = ob_get_clean();
                    return '<span data-fct-field="product-image">' . $content . '</span>';
                }
                break;
            case 'fct_product_excerpt':
                $excerpt = $post->post_excerpt;
                if ($excerpt) {
                    return '<span data-fct-field="product-excerpt">' . wp_kses_post($excerpt) . '</span>';
                }
                return '';
            case 'fct_product_price':
                $productModel = ProductDataSetup::getProductModel($post->ID);
                if ($productModel) {
                    // Price markup relies on the .fct-sr-only rule shipped with the
                    // single product styles; without it the labels render visibly.
                    AssetLoader::loadSingleProductAssets();
                    ob_start();
                    (new ProductCardRender($productModel))->renderPrices();
                    $content = ob_get_clean();
                    return '<span data-fct-field="product-price">' . $content . '</span>';
                }
                break;
            case 'fct_product_view_button': // Legacy alias for backward compatibility
            case 'fct_product_button':
                $productModel = ProductDataSetup::getProductModel($post->ID);
                if ($productModel) {
                    ob_start();
                    (new ProductCardRender($productModel))->showBuyButton();
                    $content = ob_get_clean();
                    return '<span data-fct-field="product-button">' . $content . '</span>';
                }
                break;
        }

        return $tag;
    }
}
