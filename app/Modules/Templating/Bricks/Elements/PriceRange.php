<?php

namespace FluentCart\App\Modules\Templating\Bricks\Elements;

use Bricks\Element;
use FluentCart\App\Modules\Data\ProductDataSetup;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\Framework\Support\Arr;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class PriceRange extends Element
{

    public $category = 'fluent_cart_product';
    public $name = 'fct-price-range';
    public $icon = 'ti-money';

    public function get_label()
    {
        return esc_html__('Price range', 'fluent-cart');
    }

    public function set_controls()
    {
        $this->controls['hideNoRange'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Hide when max and mix is same', 'fluent-cart'),
            'type'    => 'checkbox'
        ];
        $this->controls['priceRangeTypography'] = [
            'tab'   => 'content',
            'label' => esc_html__('Price Range typography', 'fluent-cart'),
            'type'  => 'typography',
            'css'   => [
                [
                    'selector' => '.fct-price-range.fct-product-prices',
                    'property' => 'font',
                ],
            ],
        ];
    }

    public function render()
    {
        $settings = $this->settings;

        $product = ProductDataSetup::getProductModel($this->post_id);

        if (empty($product)) {
            return $this->render_element_placeholder(
                [
                    'title'       => esc_html__('For better preview select content to show.', 'fluent-cart'),
                    'description' => esc_html__('Go to: Settings > Template Settings > Populate Content', 'fluent-cart'),
                ]
            );
        }

        if(Arr::get($settings, 'hideNoRange')) {
            $hasPriceRange = $product->detail->variation_type !== 'simple';
            if(!$hasPriceRange) {
                $min_price = $product->detail->min_price;
                $max_price = $product->detail->max_price;
                $hasPriceRange = $max_price && $max_price > $min_price;
            }
            if(!$hasPriceRange) {
                return $this->render_element_placeholder(
                    [
                        'description' => esc_html__('Product does not have min-max range', 'fluent-cart'),
                    ]
                );
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping internally
        echo "<div {$this->render_attributes( '_root' )}>";
        (new ProductRenderer($product))->renderPrices();
        echo '</div>';
    }
}
