<?php

namespace FluentCart\App\Modules\Templating\Bricks\Elements;

use Bricks\Element;
use Bricks\Helpers;
use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Services\URL;

class ProductContent extends Element
{
    public $category = 'fluent_cart_product';
    public $name = 'fct-product-content';
    public $icon = 'ti-wordpress';

    public function get_label()
    {
        return esc_html__('Product content', 'fluent-cart');
    }

    public function set_controls()
    {
        $edit_link = Helpers::get_preview_post_link( get_the_ID() );
        $label = esc_html__('Edit product content in FluentCart.', 'fluent-cart');

        $this->controls['info'] = [
            'tab'     => 'content',
            'type'    => 'info',
            'content' => $edit_link ? '<a href="' . esc_url($edit_link) . '" target="_blank">' . $label . '</a>' : $label,
        ];
    }

    public function render()
    {
        $settings = $this->settings;

        $product = get_post($this->post_id);

        if (empty($product) || $product->post_type !== FluentProducts::CPT_NAME) {
            return $this->render_element_placeholder(
                [
                    'title'       => esc_html__('For better preview select content to show.', 'fluent-cart'),
                    'description' => esc_html__('Go to: Settings > Template Settings > Populate Content', 'fluent-cart'),
                ]
            );
        }

        $content = get_post_field('post_content', $this->post_id);

        if (!$content) {
            return $this->render_element_placeholder(
                [
                    'title' => esc_html__('Product content is empty.', 'fluent-cart'),
                ]
            );
        }

        $content = $this->render_dynamic_data($content);
        $content = Helpers::parse_editor_content($content);
        $content = str_replace(']]>', ']]&gt;', $content);

        echo "<div {$this->render_attributes( '_root' )}>" . wp_kses_post($content) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping
    }
}
