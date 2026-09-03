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
        $suffixContext = apply_filters('fluent_cart/product/price_suffix_context', [
            'product' => $product,
            'variant' => $variant,
            'scope'   => $scope,
        ]);
        $priceSuffix = apply_filters('fluent_cart/product/price_suffix_atts', '', $suffixContext);
        if ($priceSuffix) {
            echo '<span class="fct_price_suffix">' . wp_kses_post($priceSuffix) . '</span>';
        }
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
}
