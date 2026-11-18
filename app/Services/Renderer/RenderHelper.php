<?php

namespace FluentCart\App\Services\Renderer;

class RenderHelper
{
    public static function renderAtts($atts = [])
    {
        foreach ($atts as $attr => $value) {
            if ($value !== '') {
                echo esc_attr($attr) . '="' . esc_attr((string)$value) . '" ';
            } else {
                echo esc_attr($attr) . ' ';
            }
        }
    }

    public static function renderPriceSuffix($product, $variant, $scope)
    {
        $priceSuffix = static::getPriceSuffix($product, $variant, $scope);
        if ($priceSuffix) {
            echo '<span class="fct_price_suffix">' . wp_kses_post($priceSuffix) . '</span>';
        }
    }

    public static function getPriceSuffix($product, $variant, $scope, string $default = '', array $context = []): string
    {
        $suffixContext = apply_filters('fluent_cart/product/price_suffix_context', array_merge($context, [
            'product' => $product,
            'variant' => $variant,
            'scope'   => $scope,
        ]));
        return wp_kses_post(apply_filters('fluent_cart/product/price_suffix_atts', $default, $suffixContext));
    }

    public static function getBlockWrapperAttributes(array $attributes = [])
    {
        if (
            !empty(\WP_Block_Supports::$block_to_render)
            && is_array(\WP_Block_Supports::$block_to_render)
            && !empty(\WP_Block_Supports::$block_to_render['blockName'])
        ) {
            return get_block_wrapper_attributes($attributes);
        }

        ob_start();
        static::renderAtts($attributes);
        return ob_get_clean();
    }

    /**
     * The quick view eye. It lives in app/Views/icons/quick-view.svg, which the block editor
     * imports too, so every quick view trigger draws the same eye. Decorative: the trigger carries the label.
     */
    public static function quickViewIconSvg(): string
    {
        static $svg = null;

        if ($svg === null) {
            $svg = trim((string) file_get_contents(FLUENTCART_PLUGIN_PATH . 'app/Views/icons/quick-view.svg'));
        }

        return $svg;
    }
}
