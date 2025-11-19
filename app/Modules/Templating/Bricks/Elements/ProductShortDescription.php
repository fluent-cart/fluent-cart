<?php

namespace FluentCart\App\Modules\Templating\Bricks\Elements;

use Bricks\Element;
use Bricks\Helpers;
use FluentCart\App\Modules\Data\ProductDataSetup;
use FluentCart\App\Services\Renderer\ProductRenderer;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class ProductShortDescription extends Element
{
    public $category = 'fluent_cart_product';
    public $name = 'fct-product-short-description';
    public $icon = 'ti-paragraph';

    public function get_label()
    {
        return esc_html__('Product short description', 'fluent-cart');
    }

    public function set_controls()
    {
        $edit_link = Helpers::get_preview_post_link(get_the_ID());
        $label = esc_html__('Edit product short description in WordPress.', 'fluent-cart');

        $this->controls['info'] = [
            'tab'     => 'content',
            'type'    => 'info',
            'content' => $edit_link ? '<a href="' . esc_url($edit_link) . '" target="_blank">' . $label . '</a>' : $label,
        ];
    }

    public function render()
    {

        $product = ProductDataSetup::getProductModel($this->post_id);

        if (empty($product)) {
            return $this->render_element_placeholder(
                [
                    'title'       => esc_html__('For better preview select content to show.', 'fluent-cart'),
                    'description' => esc_html__('Go to: Settings > Template Settings > Populate Content', 'fluent-cart'),
                ]
            );
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping
        echo "<div {$this->render_attributes( '_root' )}>";
        (new ProductRenderer($product))->renderExcerpt();
        echo '</div>';
    }
}
