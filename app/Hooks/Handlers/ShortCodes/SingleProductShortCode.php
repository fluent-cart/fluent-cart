<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\Api\Resource\ShopResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Modules\Templating\AssetLoader;

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

    public function getScripts(): array
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

    public function getStyles(): array
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
        $productId = Arr::get($this->shortCodeAttributes, 'productid')
            ?: Arr::get($this->shortCodeAttributes, 'productId')
            ?: Arr::get($this->shortCodeAttributes, 'product_id')
            ?: Arr::get($this->shortCodeAttributes, 'id');

        if (!$productId) {
            return null;
        }

        $product = ShopResource::find($productId);
        if (empty($product)) {
            return null;
        }

        return [
            'product' => $product
        ];
    }

    public function localizeData(): array
    {
        return [
            'fluentcart_single_product_vars' => [
                'trans'                      => TransStrings::singleProductPageString(),
                'cart_button_text'           => apply_filters('fluent_cart/product/add_to_cart_text', __('Add To Cart', 'fluent-cart'), []),
                // App::storeSettings()->get('cart_button_text', __('Add to Cart', 'fluent-cart')),
                'out_of_stock_button_text'   => apply_filters('fluent_cart/product/out_of_stock_text', __('Not Available', 'fluent-cart'), []),
                'in_stock_status'            => Helper::IN_STOCK,
                'out_of_stock_status'        => Helper::OUT_OF_STOCK,
                'enable_image_zoom'          => (new StoreSettings())->get('enable_image_zoom_in_single_product'),
                'enable_image_zoom_in_modal' => (new StoreSettings())->get('enable_image_zoom_in_modal')
            ]
        ];
    }

    public function render(?array $viewData = null)
    {
        AssetLoader::loadSingleProductAssets();
        if (empty($viewData['product'])) {
            echo esc_html__('Product not found', 'fluent-cart');
            return;
        }

        // Single-product shortcode renders the full purchase UI, incl. Pro's
        // advanced-variation selector — fire the hook so Pro enqueues its assets.
        do_action('fluent_cart/advanced_variation/enqueue_assets');

        wp_reset_postdata();

        $storeSettings = new StoreSettings();
        $product = Product::query()->find($viewData['product']['ID']);
        (new ProductRenderer($product, [
            'view_type'   => $storeSettings->get('variation_view', 'both'),
            'column_type' => $storeSettings->get('variation_columns', 'masonry')
        ]))->render();
    }
}
