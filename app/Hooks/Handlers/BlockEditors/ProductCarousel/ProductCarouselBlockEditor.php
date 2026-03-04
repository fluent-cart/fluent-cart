<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors\ProductCarousel;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Hooks\Handlers\BlockEditors\BlockEditor;
use FluentCart\App\Hooks\Handlers\BlockEditors\ProductCarousel\InnerBlocks\InnerBlocks;
use FluentCart\App\Hooks\Handlers\ShortCodes\ShopAppHandler;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class ProductCarouselBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-carousel';

    protected ?string $localizationKey = 'fluent_cart_product_carousel_block_editor_data';

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductCarousel/ProductCarouselBlockEditor.jsx',
                'dependencies' => [
                    'wp-blocks', 
                    'wp-components', 
                    'wp-block-editor',
                    'wp-data',
                    'wp-element',
                ]
            ]
        ];
    }

    protected function getStyles(): array
    {
        return [
            'admin/BlockEditor/ShopApp/style/shop-app-block-editor.css',
            'admin/BlockEditor/ProductInfo/style/product-info-block-editor.scss',
            'admin/BlockEditor/ProductCarousel/style/product-carousel-block-editor.scss'
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerInnerBlocks();
    }

    public function registerInnerBlocks()
    {
        InnerBlocks::register();
    }

    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey()      => [
                'rest'               => Helper::getRestInfo(),
                'slug'               => $this->slugPrefix,
                'name'               => static::getEditorName(),
                'trans'              => TransStrings::getShopAppBlockEditorString(),
                'title'             => __('Product Carousel', 'fluent-cart'),
                'description'       => __('This block will display the product carousel.', 'fluent-cart'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg')
            ],
            'fluent_cart_block_editor_asset' => [
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg'),
            ],
            'fluent_cart_block_translation'  => TransStrings::blockStrings(),
            'fluentCartCarouselVars' => [
                'defaultSlides' => 3,
                'spaceBetween' => 16,
                'breakpoints' => [
                    0    => ['slidesPerView' => 1],
                    768  => ['slidesPerView' => 2],
                    1024 => ['slidesPerView' => 3],
                    1280 => ['slidesPerView' => 4],
                ],
                'rtl' => is_rtl(),
            ],
        ];
    }

    public function provideContext(): array
    {
        // in which name the data will be received => which attr
        return [
            'fluent-cart/carousel_settings'       => 'carousel_settings',
            'fluent-cart/product_ids'            => 'product_ids',
            'fluent-cart/has_controls'           => 'has_controls',
            'fluent-cart/has_pagination'         => 'has_pagination',
        ];
    }

    public function render(array $shortCodeAttribute, $block = null, $content = null): string
    {
        AssetLoader::loadProductArchiveAssets();

        $app = fluentCart();
        $slug = $app->config->get('app.slug');

        Vite::enqueueStaticScript(
            $slug . '-fluentcart-swiper-js',
            'public/lib/swiper/swiper-bundle.min.js',
            [$slug . '-app',]
        );

        Vite::enqueueStaticStyle(
            $slug . '-fluentcart-swiper-css',
            'public/lib/swiper/swiper-bundle.min.css',
        );

        Vite::enqueueStyle(
                'fluentcart-product-carousel',
                'public/carousel/products/style/product-carousel.scss',
        );

        Vite::enqueueScript(
                'fluentcart-product-carousel',
                'public/carousel/products/product-carousel.js',
                []
        );

        return $content;
    }
}
