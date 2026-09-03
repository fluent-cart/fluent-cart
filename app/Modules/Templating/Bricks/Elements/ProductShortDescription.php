<?php

namespace FluentCart\App\Modules\Templating\Bricks\Elements;

use Bricks\Element;
use FluentCart\App\Modules\Templating\Bricks\BricksLoader;
use FluentCart\App\Modules\Data\ProductDataSetup;
use FluentCart\App\Services\Renderer\ProductRenderer;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class ProductShortDescription extends Element
{
    public $category = 'fluent-cart';
    public $name = 'fct-product-short-description';
    public $icon = 'ti-paragraph fluent-cart-element-icon';

    public function get_label()
    {
        return esc_html__('Product Excerpt', 'fluent-cart');
    }

    public function set_controls()
    {

        $this->controls['queryType'] = [
            'tab'      => 'content',
            'type'     => 'select',
            'label'    => esc_html__('Query Type', 'fluent-cart'),
            'options'  => [
                'default' => esc_html__('Default', 'fluent-cart'),
                'custom'  => esc_html__('Custom', 'fluent-cart'),
            ],
            'default'  => 'default',
            'inline'   => true,
        ];

        $this->controls['productId'] = [
            'tab'         => 'content',
            'type'        => 'select',
            'label'       => esc_html__('Product', 'fluent-cart'),
            'options'     => BricksLoader::getProductOptions(),
            'placeholder' => esc_html__('Select a product', 'fluent-cart'),
            'searchable'  => true,
            'rerender'    => true,
            'required'    => ['queryType', '=', 'custom'],
        ];

        $this->controls['manualProductId'] = [
            'tab'         => 'content',
            'type'        => 'text',
            'label'       => esc_html__('Manual Product ID', 'fluent-cart'),
            'description' => esc_html__('Use this if the product is not available in dropdown.', 'fluent-cart'),
            'required'    => [['queryType', '=', 'custom'], ['productId', '=', '']],
        ];

        $this->controls['info'] = [
            'tab'      => 'content',
            'type'     => 'info',
            'content'  => esc_html__('Edit product short description in WordPress.', 'fluent-cart'),
        ];
    }

    public function render()
    {
        $settings = $this->settings;
        $queryType = $settings['queryType'] ?? 'default';

        if ($queryType === 'default') {
            $productId = $this->post_id;
        } else {
            $productId = !empty($settings['productId']) ? \intval($settings['productId']) : 0;
            $manualProductId = !empty($settings['manualProductId']) ? \intval($settings['manualProductId']) : 0;

            if (!$productId && $manualProductId) {
                $productId = $manualProductId;
            }
        }

        $product = ProductDataSetup::getProductModel($productId);

        if (!$product) {
            return $this->render_element_placeholder([
                'title' => esc_html__(
                    'Select a product',
                    'fluent-cart'
                ),
            ]);
        }

        if (!$product->post_excerpt) {
            return $this->render_element_placeholder([
                'title' => esc_html__(
                    'No excerpt found.',
                    'fluent-cart'
                ),
            ]);
        }

        ?>
        <div
            <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping
                echo $this->render_attributes( '_root' );
            ?>
        >
            <?php (new ProductRenderer($product))->renderExcerpt(); ?>
        </div>

        <?php
    }
}
