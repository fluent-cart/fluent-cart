<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes\Buttons;

use FluentCart\App\Hooks\Handlers\ShortCodes\ShortCode;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\Framework\Support\Arr;

class AddToCartShortcode extends ShortCode
{
    protected static string $shortCodeName = 'fluent_cart_add_to_cart_button';

    protected function getStyles(): array
    {
        return [
            'public/buttons/add-to-cart/style/style.scss',
        ];
    }

    /**
     * Example:
     * [fluent_cart_add_to_cart_button
     *   button_text="Add to Cart"
     *   variation_id="123"
     * ]
     *
     * Supported attributes:
     * - button_text
     * - variation_id
     */
    public function render(?array $viewData = null)
    {
        $data = $viewData ?? $this->shortCodeAttributes ?? [];

        $buttonText  = Arr::get($data, 'button_text', __('Add to Cart', 'fluent-cart'));
        $buttonClass = trim(Arr::get($data, 'class', ''));
        $variationId = (int) Arr::get($data, 'variation_id', 0);

        if (!$variationId) {
            return '';
        }

        AssetLoader::loadSingleProductAssets();

        $rendererConfig = [];
        $product        = null;

        // 1) Try treating variation_id as the actual variation primary ID
        $variant = ProductVariation::query()->find($variationId);

        // 2) Fallback: treat variation_id as variation post_id
        if (!$variant) {
            $variant = ProductVariation::query()
                ->where('post_id', $variationId)
                ->first();
        }

        if ($variant) {
            /** @var Product|null $product */
            $product = Product::query()->find($variant->post_id);

            if ($product) {
                $rendererConfig['default_variation_id'] = $variant->id;
            }
        }

        if (!$product) {
            if (Helper::isAdminUser()) {
                return '<p class="fct-admin-notice">' . esc_html__('Invalid product/variant for Add to Cart button shortcode.', 'fluent-cart') . '</p>';
            }

            return '';
        }

        $atts = [
            'text'        => $buttonText,
            'is_shortcode'=> true,
        ];

        if ($buttonClass) {
            $atts['class'] = $buttonClass;
        }

        return (new ProductRenderer($product, $rendererConfig))->renderAddToCartButtonBlock($atts);
    }
}

