<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\Api\Resource\ShopResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Hooks\Handlers\ShortCodes\Buttons\DirectCheckoutShortcode;

class SingleProductShortCode extends ShortCode
{

    protected static string $shortCodeName = 'fluent_cart_single_product';

    public static function register()
    {
        add_action('wp_enqueue_scripts', function () {
            if (
                is_singular(FluentProducts::CPT_NAME) ||
                has_shortcode(get_the_content(), static::$shortCodeName)
            ) {
                (new static())->enqueueStyles();
            }
        }, 10);
        parent::register();
    }

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'public/single-product/xzoom/xzoom.js',
                'dependencies' => [],
                'inFooter'     => true,
            ],
            [
                'source'       => 'public/single-product/SingleProduct.js',
                'dependencies' => [],
                'inFooter'     => true
            ],
        ];
    }

    protected function getStyles(): array
    {

        //DirectCheckoutShortcode::make()->enqueueAssets();

        return [
            'public/single-product/single-product.scss',
            'public/single-product/similar-product.scss',
            'public/product-card/style/product-card.scss',
            'public/single-product/xzoom/xzoom.css'
        ];
    }


    public function viewData(): ?array
    {
        $product = ShopResource::find($this->shortCodeAttributes['productId']);
        if (empty($product)) {
            return null;
        } else {
            return [
                'product' => $product
            ];
        }
    }

    protected function localizeData(): array
    {
        return [
            'fluentcart_single_product_vars' => [
                'trans'                      => TransStrings::singleProductPageString(),
                'cart_button_text'           => apply_filters('fluent_cart/product/add_to_cart_text', __('Add To Cart', 'fluent-cart'), []),
                // App::storeSettings()->get('cart_button_text', __('Add to Cart', 'fluent-cart')),
                'out_of_stock_button_text'   => App::storeSettings()->get('out_of_stock_button_text', __('Out of Stock', 'fluent-cart')),
                'in_stock_status'            => Helper::IN_STOCK,
                'out_of_stock_status'        => Helper::OUT_OF_STOCK,
                'enable_image_zoom'          => (new StoreSettings())->get('enable_image_zoom_in_single_product'),
                'enable_image_zoom_in_modal' => (new StoreSettings())->get('enable_image_zoom_in_modal')
            ]
        ];
    }

    public function render(?array $viewData = null)
    {
        if (empty($viewData['product'])) {
            return 'Product not found';
        } else {

            wp_reset_postdata();

            $storeSettings = new StoreSettings();
            $product = Product::query()->find($viewData['product']['ID']);
            ob_start();
            (new ProductRenderer($product, [
                'view_type'   => $storeSettings->get('variation_view', 'both'),
                'column_type' => $storeSettings->get('variation_columns', 'masonry')
            ]))
                ->render();

            return ob_get_clean();
        }

    }
}
