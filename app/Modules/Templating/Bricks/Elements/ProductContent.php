<?php

namespace FluentCart\App\Modules\Templating\Bricks\Elements;

use Bricks\Element;
use Bricks\Helpers;
use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Modules\Templating\Bricks\BricksLoader;
use FluentCart\App\Modules\Templating\Bricks\BricksHelper;
use FluentCart\App\Modules\Data\ProductDataSetup;
use FluentCart\App\Services\Renderer\ProductRenderer;

class ProductContent extends Element
{
    public $category = 'fluent-cart';
    public $name = 'fct-product-content';
    public $icon = 'ion-md-list-box fluent-cart-element-icon';

    public function get_label()
    {
        return esc_html__('Product Content', 'fluent-cart');
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
            'tab'     => 'content',
            'type'    => 'info',
            'content' => esc_html__('Edit product content in FluentCart.', 'fluent-cart'),
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
                'title' => esc_html__('Select a product', 'fluent-cart'),
            ]);
        }

        $content = $product->post_content;
       

        if (!$content) {
            return $this->render_element_placeholder([
                'title' => esc_html__('Product content is empty.', 'fluent-cart'),
            ]);
        }

        $content = $this->render_dynamic_data($content);
        $content = Helpers::parse_editor_content($content);
        $content = str_replace(']]>', ']]&gt;', $content);

        ?>
        <div
            <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping
                echo $this->render_attributes( '_root' );
            ?>
        >
            <?php echo wp_kses($content, BricksHelper::getAllowedHtmlForContent()); ?>
        </div>

        <?php
    }
}
