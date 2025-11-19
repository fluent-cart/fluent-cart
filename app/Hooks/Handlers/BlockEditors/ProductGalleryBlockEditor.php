<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Hooks\Handlers\ShortCodes\ProductCardShortCode;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Product;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\App;
use FluentCart\Api\StoreSettings;

class ProductGalleryBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-gallery';

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductGallery/ProductGalleryBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    protected function getStyles(): array
    {
        return [
            'admin/BlockEditor/ProductGallery/style/product-gallery-block-editor.scss'
        ];
    }

    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'              => $this->slugPrefix,
                'name'              => static::getEditorName(),
                'title'             => __('Product Gallery', 'fluent-cart'),
                'description'       => __('This block will display the product gallery.', 'fluent-cart'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        AssetLoader::loadSingleProductAssets();

        $product = null;
        $insideProductInfo = Arr::get($shortCodeAttribute, 'inside_product_info', 'no');
        $queryType = Arr::get($shortCodeAttribute, 'query_type', 'default');
        $enableImageZoom = Arr::get($shortCodeAttribute, 'enableImageZoom', 'yes');
        
        if ($insideProductInfo === 'yes' || $queryType === 'default') {
            $product = fluent_cart_get_current_product();

        } else {
            $productId = Arr::get($shortCodeAttribute, 'product_id', false);
            if ($productId) {
                $product = Product::query()->with(['variants'])->find($productId);
            }
        }

        if (!$product) {
            return '';
        }
        // import xzoom
//        Vite::enqueueStaticScript(
//            'fluentcart-zoom-js',
//            'public/lib/xzoom/xzoom.js',
//            []
//        );
//        Vite::enqueueStaticStyle(
//            'fluentcart-zoom-css',
//            'public/lib/xzoom/xzoom.css',
//        );
//
//        wp_enqueue_style(
//            'fluentcart-single-product',
//            Vite::getAssetUrl('public/single-product/single-product.scss'),
//            [],
//            ''
//        );
//
//
//        Vite::enqueueStyle(
//            'fluentcart-add-to-cart-btn-css',
//            'public/buttons/add-to-cart/style/style.scss'
//        );
//        Vite::enqueueStyle(
//            'fluentcart-direct-checkout-btn-css',
//            'public/buttons/direct-checkout/style/style.scss'
//        );

        $thumbnailMode = 'all';
        ob_start();
        (new ProductRenderer($product))->renderGallery([
            'mode' => 'all'
        ]);
        return ob_get_clean();
    }


}
