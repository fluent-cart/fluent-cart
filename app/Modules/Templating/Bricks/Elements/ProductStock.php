<?php

namespace FluentCart\App\Modules\Templating\Bricks\Elements;

use Bricks\Element;
use FluentCart\App\Modules\Data\ProductDataSetup;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Modules\Templating\Bricks\BricksLoader;
use FluentCart\Framework\Support\Arr;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class ProductStock extends Element
{
    public $category = 'fluent-cart';
    public $name = 'fct-product-stock';
    public $icon = 'ti-package fluent-cart-element-icon';

    public function get_label()
    {
        return esc_html__('Product Stock', 'fluent-cart');
    }

    public function set_control_groups()
    {
        $this->control_groups['inStock'] = [
            'title' => esc_html__('In stock', 'fluent-cart'),
            'tab'   => 'content',
        ];

//
//        $this->control_groups['lowStock'] = [
//            'title' => esc_html__('Low stock', 'fluent-cart') . '/' . esc_html__('On backorder', 'fluent-cart'),
//            'tab'   => 'content',
//        ];

        $this->control_groups['outOfStock'] = [
            'title' => esc_html__('Out of stock', 'fluent-cart'),
            'tab'   => 'content',
        ];
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

        // In Stock

        $this->controls['inStockText'] = [
            'tab'            => 'content',
            'group'          => 'inStock',
            'type'           => 'text',
            'hasDynamicData' => 'text',
            'placeholder'    => esc_html__('Custom text', 'fluent-cart'),
        ];

        $this->controls['inStockTypography'] = [
            'tab'   => 'content',
            'group' => 'inStock',
            'label' => esc_html__('Typography', 'fluent-cart'),
            'type'  => 'typography',
            'css'   => [
                [
                    'property' => 'font',
                    'selector' => '.in-stock',
                ],
            ],
        ];

        $this->controls['inStockBackgroundColor'] = [
            'tab'   => 'style',
            'group' => 'inStock',
            'label' => esc_html__('Background color', 'fluent-cart'),
            'type'  => 'color',
            'css'   => [
                [
                    'property' => 'background-color',
                    'selector' => '.in-stock',
                ]
            ],
        ];

        // Low Stock
        if (false) {
            $this->controls['lowStockText'] = [
                'tab'            => 'content',
                'group'          => 'lowStock',
                'type'           => 'text',
                'hasDynamicData' => 'text',
                'placeholder'    => esc_html__('Custom text', 'fluent-cart'),
            ];

            $this->controls['lowStockTypography'] = [
                'tab'   => 'content',
                'group' => 'lowStock',
                'label' => esc_html__('Typography', 'fluent-cart'),
                'type'  => 'typography',
                'css'   => [
                    [
                        'property' => 'font',
                        'selector' => '.low-stock, .available-on-backorder',
                    ],
                ],
            ];

            $this->controls['lowStockBackgroundColor'] = [
                'tab'   => 'style',
                'group' => 'lowStock',
                'label' => esc_html__('Background color', 'fluent-cart'),
                'type'  => 'color',
                'css'   => [
                    [
                        'property' => 'background-color',
                        'selector' => '.low-stock, .available-on-backorder',
                    ]
                ],
            ];
        }

        // Out of Stock
        $this->controls['outOfStockText'] = [
            'tab'            => 'content',
            'group'          => 'outOfStock',
            'type'           => 'text',
            'hasDynamicData' => 'text',
            'placeholder'    => esc_html__('Custom text', 'fluent-cart'),
        ];

        $this->controls['outOfStockTypography'] = [
            'tab'   => 'content',
            'group' => 'outOfStock',
            'label' => esc_html__('Typography', 'fluent-cart'),
            'type'  => 'typography',
            'css'   => [
                [
                    'property' => 'font',
                    'selector' => '.out-of-stock',
                ],
            ],
        ];

        $this->controls['outOfStockBackgroundColor'] = [
            'tab'   => 'style',
            'group' => 'outOfStock',
            'label' => esc_html__('Background color', 'fluent-cart'),
            'type'  => 'color',
            'css'   => [
                [
                    'property' => 'background-color',
                    'selector' => '.out-of-stock',
                ]
            ],
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

        add_filter('fluent_cart/product_stock_availability', [$this, 'simulateAvailability'], 10, 2);

        $productRender = new ProductRenderer($product);

        ob_start();
        $productRender->renderStockAvailability();
        $stock_html = ob_get_clean();
        remove_filter('fluent_cart/product_stock_availability', [$this, 'simulateAvailability'], 10);

        if (!$stock_html) {
            return $this->render_element_placeholder(
                [
                    'title' => esc_html__('Stock management not enabled for this product.', 'fluent-cart'),
                ]
            );
        }

        echo "<div {$this->render_attributes( '_root' )}>" . wp_kses_post($stock_html) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping
    }

    public function simulateAvailability($availability, $data)
    {
        $settings = $this->settings;
        $is_manage_stock = Arr::get($availability, 'manage_stock');
        if (!$is_manage_stock) {
            return $availability;
        }

        // Get stock via helper function if we don't manage stock on product level (to enable low stock for example)
        // $stock_quantity = Arr::get($productDetail, 'available_quantity');

        // Set availability text based on user input
        switch ($availability['class']) {
            case 'in-stock':
                $availability['availability'] = !empty($settings['inStockText']) ? $settings['inStockText'] : $availability['availability'];
                break;

            case 'available-on-backorder':
            case 'low-stock':
                $availability['availability'] = !empty($settings['lowStockText']) ? $settings['lowStockText'] : $availability['availability'];
                break;

            case 'out-of-stock':
                $availability['availability'] = !empty($settings['outOfStockText']) ? $settings['outOfStockText'] : $availability['availability'];
                break;
        }

        // Expose the configured custom labels for BOTH statuses (not just the one
        // rendered on load) so the frontend JS — which re-derives the badge text on
        // load and on every variant switch — can prefer them over its generic label
        // map instead of clobbering the server-rendered custom text.
        if (!empty($settings['inStockText'])) {
            $availability['in_stock_text'] = $settings['inStockText'];
        }
        if (!empty($settings['outOfStockText'])) {
            $availability['out_of_stock_text'] = $settings['outOfStockText'];
        }

        return $availability;
    }
}
