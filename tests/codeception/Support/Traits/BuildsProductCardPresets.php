<?php

namespace Tests\Support\Traits;

trait BuildsProductCardPresets
{
    protected function cardPresetMarkup(array $template): string
    {
        $html = '';
        foreach ($template as $node) {
            $name = $node[0];
            $attributes = (array) ($node[1] ?? []);
            $children = $this->cardPresetMarkup($node[2] ?? []);
            $inner = $children;
            if ($name === 'core/group') {
                $styles = wp_style_engine_get_styles($attributes['style'] ?? []);
                $inner = '<div class="wp-block-group ' . esc_attr($attributes['className'] ?? '') . '" style="' . esc_attr($styles['css'] ?? '') . '">' . $children . '</div>';
            }
            $html .= get_comment_delimited_block_content($name, $attributes, $inner);
        }
        return $html;
    }

    // A Products block with a view switcher in its action container and one product loop holding a v1 card.
    protected function productsMarkup(string $layout, array $productsAttributes = []): string
    {
        $switcher = get_comment_delimited_block_content('fluent-cart/shopapp-product-view-switcher', [], '');
        $actions = get_comment_delimited_block_content('fluent-cart/shopapp-product-action-container', [], $switcher);
        $card = get_comment_delimited_block_content('fluent-cart/product-card', ['template_version' => 1, 'card_layout' => $layout], '');
        $loop = get_comment_delimited_block_content('fluent-cart/shopapp-product-loop', [], $card);
        return get_comment_delimited_block_content('fluent-cart/products', $productsAttributes, $actions . $loop);
    }
}
