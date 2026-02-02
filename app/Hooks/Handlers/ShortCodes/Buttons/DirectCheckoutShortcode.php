<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes\Buttons;

use FluentCart\App\Hooks\Handlers\ShortCodes\ShortCode;
use FluentCart\App\Http\Routes\WebRoutes;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\Framework\Support\Arr;

class DirectCheckoutShortcode extends ShortCode
{
    protected static string $shortCodeName = 'fluent_cart_checkout_button';

    protected function getStyles(): array
    {
        return [
            'public/buttons/direct-checkout/style/style.scss'
        ];
    }

    /*
     * Example:
     * [fluent_cart_checkout_button
     *   button_text="Buy Now"
     *   variation_id="123"
     *   instant_checkout="yes"
     * ]
     *
     * Supported attributes:
     * - button_text
     * - variation_id
     * - target
     * - instant_checkout (yes|no)
     */
    public function render(?array $viewData = null)
    {
        $data = $viewData ?? $this->shortCodeAttributes ?? [];
        $buttonText = Arr::get($data, 'button_text', __('Buy Now', 'fluent-cart'));
        $variationId = (int) Arr::get($data, 'variation_id', 0);
        $target = Arr::get($data, 'target', '');
        $buttonClass = trim(Arr::get($data, 'class', ''));

        $instantCheckout = Arr::get($data, 'instant_checkout', false);
        if (is_string($instantCheckout)) {
            $instantCheckout = in_array(strtolower($instantCheckout), ['1', 'true', 'yes', 'on'], true);
        }
        $instantCheckout = (bool)$instantCheckout;

        if ($instantCheckout) {
            add_action('wp_footer', function () {
                WebRoutes::renderModalCheckout();
                AssetLoader::loadModalCheckoutAssets();
            });
        }

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
                return '<p class="fct-admin-notice">' . esc_html__('Invalid product/variant for Checkout button shortcode.', 'fluent-cart') . '</p>';
            }

            return '';
        }

        $atts = [
            'text'                 => $buttonText,
            'is_shortcode'         => true,
            'enable_modal_checkout'=> $instantCheckout,
        ];

        if ($buttonClass) {
            $atts['class'] = $buttonClass;
        }

        if ($target) {
            $atts['target'] = $target;
        }

        return (new ProductRenderer($product, $rendererConfig))->renderBuyNowButtonBlock($atts);
    }

    protected function renderAttributes($atts = [])
    {
        foreach ($atts as $attr => $value) {
            if ($value !== '') {
                echo esc_attr($attr) . '="' . esc_attr((string)$value) . '" ';
            } else {
                echo esc_attr($attr) . ' ';
            }
        }
    }

}
