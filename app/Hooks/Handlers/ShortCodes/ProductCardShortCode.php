<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Models\Product;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductCardRender;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class ProductCardShortCode extends ShortCode
{
    const SHORT_CODE = 'fluent_cart_product_card';
    protected static string $shortCodeName = 'fluent_cart_product_card';

    public static function register()
    {
        parent::register();
        add_action('wp_enqueue_scripts', function () {
            if (App::request()->get('action') === 'elementor') {
                return;
            }
            if (has_shortcode(get_the_content(), static::SHORT_CODE) || has_block('fluent-cart/product-card')) {
                AssetLoader::loadSingleProductAssets();
            }
        }, 10);
    }

    public function viewData(): ?array
    {
        $productId = Arr::get($this->shortCodeAttributes, 'product_id');
        $priceFormat = Arr::get($this->shortCodeAttributes, 'price_format', 'starts_from');
        $cardWidth = Arr::get($this->shortCodeAttributes, 'card_width', 216);
        $renderAsAnchor = Arr::get($this->shortCodeAttributes, 'render_as_anchor', false);

        $product = Product::query()
            ->with([
                'detail',
                'variants' => function ($query) {
                    $query->with(['media'])
                        ->orderBy('serial_index', 'ASC');
                }
            ])
            ->where('ID', $productId)->first();

        return [
            'placeholder_image' => '',
            'cart_id'           => $productId,
            'product'           => $product,
            'store_settings'    => new StoreSettings(),
            'price_format'      => $priceFormat,
            'card_width'        => $cardWidth,
            'wrapper_class'     => 'single-product-card',
            'render_as_anchor'  => $renderAsAnchor,
        ];

    }

    public function render(?array $viewData = null)
    {
        //ProductCardRender::
        $product = Arr::get($viewData, 'product');
        if (empty($product)) {
            echo '<p>' . esc_html__('no content', 'fluent-cart') . '</p>';
        } else {
            (new ProductCardRender($product, [
                'price_format' => Arr::get($viewData, 'price_format'),
                'card_width'   => Arr::get($viewData, 'card_width'),
                'wrapper_class'    => Arr::get($viewData, 'wrapper_class'),
                'render_as_anchor' => Arr::get($viewData, 'render_as_anchor'),
            ]))->render();
        }
    }

}
