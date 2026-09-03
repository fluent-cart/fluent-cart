<?php

namespace FluentCart\App\Modules\Templating\Bricks\Elements;

use Bricks\Element;
use FluentCart\App\Modules\Data\ProductDataSetup;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Modules\Templating\Bricks\BricksLoader;
use FluentCart\App\Services\Renderer\ProductRenderer;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class BuySection extends Element
{
    public $category = 'fluent-cart';
    public $name = 'fct-product-buy-section';
    public $icon = 'ti-bag fluent-cart-element-icon';

    public function enqueue_scripts()
    {
        AssetLoader::loadSingleProductAssets();
    }

    public function get_label()
    {
        return esc_html__('Buy Section', 'fluent-cart');
    }

    public function set_control_groups()
    {
        $this->control_groups['buyButton'] = [
            'title' => esc_html__('Direct Buy Button', 'fluent-cart'),
            'tab'   => 'content',
        ];

        $this->control_groups['cartButton'] = [
            'title' => esc_html__('Add to Cart Button', 'fluent-cart'),
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

        // BUTTONS
        $this->controls['buttonInfo'] = [
            'tab'         => 'content',
            'group'       => 'cartButton',
            'type'        => 'info',
            'content'     => esc_html__('Add to Cart Button will be shown only for non-subscribable products.', 'fluent-cart'),
        ];
        $this->controls['buttonText'] = [
            'tab'         => 'content',
            'group'       => 'cartButton',
            'type'        => 'text',
            'inline'      => true,
            'label'       => esc_html__('Button Text', 'fluent-cart'),
            'placeholder' => esc_html__('Add To Cart', 'fluent-cart'),
        ];
        $this->controls['buttonMargin'] = [
            'tab'   => 'content',
            'group' => 'cartButton',
            'label' => esc_html__('Buttons Margin', 'fluent-cart'),
            'type'  => 'spacing',
            'css'   => [
                [
                    'selector' => '.fct-product-buttons-wrap .fluent-cart-add-to-cart-button',
                    'property' => 'margin',
                ],
            ],
        ];
        $this->controls['buttonPadding'] = [
            'tab'   => 'content',
            'group' => 'cartButton',
            'label' => esc_html__('Padding', 'fluent-cart'),
            'type'  => 'spacing',
            'css'   => [
                [
                    'selector' => '.fct-product-buttons-wrap .fluent-cart-add-to-cart-button',
                    'property' => 'padding',
                ],
            ],
        ];
        $this->controls['buttonWidth'] = [
            'tab'   => 'content',
            'group' => 'cartButton',
            'label' => esc_html__('Width', 'fluent-cart'),
            'type'  => 'number',
            'units' => true,
            'css'   => [
                [
                    'selector' => '.fct-product-buttons-wrap .fluent-cart-add-to-cart-button',
                    'property' => 'min-width',
                ],
            ],
        ];
        $this->controls['buttonBackgroundColor'] = [
            'tab'   => 'content',
            'group' => 'cartButton',
            'label' => esc_html__('Background color', 'fluent-cart'),
            'type'  => 'color',
            'css'   => [
                [
                    'selector' => '.fct-product-buttons-wrap .fluent-cart-add-to-cart-button',
                    'property' => 'background-color',
                ],
            ],
        ];
        $this->controls['buttonBorder'] = [
            'tab'   => 'content',
            'group' => 'cartButton',
            'label' => esc_html__('Border', 'fluent-cart'),
            'type'  => 'border',
            'css'   => [
                [
                    'property' => 'border',
                    'selector' => '.fct-product-buttons-wrap .fluent-cart-add-to-cart-button',
                ],
            ],
        ];
        $this->controls['buttonTypography'] = [
            'tab'   => 'content',
            'group' => 'cartButton',
            'label' => esc_html__('Typography', 'fluent-cart'),
            'type'  => 'typography',
            'css'   => [
                [
                    'selector' => '.fct-product-buttons-wrap .',
                    'property' => 'font',
                ],
            ],
        ];

        $this->controls['icon'] = [
            'tab'      => 'content',
            'group'    => 'cartButton',
            'label'    => esc_html__('Icon', 'fluent-cart'),
            'type'     => 'icon',
            'rerender' => true,
        ];

        $this->controls['iconTypography'] = [
            'tab'   => 'content',
            'group' => 'cartButton',
            'label' => esc_html__('Icon typography', 'fluent-cart'),
            'type'  => 'typography',
            'css'   => [
                [
                    'property' => 'font',
                    'selector' => '.icon',
                ],
            ],
        ];

        $this->controls['iconOnly'] = [
            'tab'         => 'content',
            'group'       => 'cartButton',
            'label'       => esc_html__('Icon only', 'fluent-cart'),
            'type'        => 'checkbox',
            'inline'      => true,
            'placeholder' => esc_html__('Yes', 'fluent-cart'),
            'required'    => ['icon', '!=', ''],
        ];

        $this->controls['iconPosition'] = [
            'tab'         => 'content',
            'group'       => 'cartButton',
            'label'       => esc_html__('Icon position', 'fluent-cart'),
            'type'        => 'select',
            'options'     => $this->control_options['iconPosition'],
            'inline'      => true,
            'placeholder' => esc_html__('Left', 'fluent-cart'),
            'required'    => [
                ['icon', '!=', ''],
                ['iconOnly', '=', ''],
            ],
        ];

        // Buy Now BUTTON
        $this->controls['directButtonText'] = [
            'tab'         => 'content',
            'group'       => 'buyButton',
            'type'        => 'text',
            'inline'      => true,
            'label'       => esc_html__('Button Text', 'fluent-cart'),
            'placeholder' => esc_html__('Buy Now', 'fluent-cart'),
        ];
        $this->controls['directButtonMargin'] = [
            'tab'   => 'content',
            'group' => 'buyButton',
            'label' => esc_html__('Buttons Margin', 'fluent-cart'),
            'type'  => 'spacing',
            'css'   => [
                [
                    'selector' => '.fct-product-buttons-wrap .fluent-cart-direct-checkout-button',
                    'property' => 'margin',
                ],
            ],
        ];
        $this->controls['directButtonPadding'] = [
            'tab'   => 'content',
            'group' => 'buyButton',
            'label' => esc_html__('Padding', 'fluent-cart'),
            'type'  => 'spacing',
            'css'   => [
                [
                    'selector' => '.fct-product-buttons-wrap .fluent-cart-direct-checkout-button',
                    'property' => 'padding',
                ],
            ],
        ];
        $this->controls['directButtonWidth'] = [
            'tab'   => 'content',
            'group' => 'buyButton',
            'label' => esc_html__('Width', 'fluent-cart'),
            'type'  => 'number',
            'units' => true,
            'css'   => [
                [
                    'selector' => '.fct-product-buttons-wrap .fluent-cart-direct-checkout-button',
                    'property' => 'min-width',
                ],
            ],
        ];
        $this->controls['directButtonBackgroundColor'] = [
            'tab'   => 'content',
            'group' => 'buyButton',
            'label' => esc_html__('Background color', 'fluent-cart'),
            'type'  => 'color',
            'css'   => [
                [
                    'selector' => '.fct-product-buttons-wrap .fluent-cart-direct-checkout-button',
                    'property' => 'background-color',
                ],
            ],
        ];
        $this->controls['directButtonBorder'] = [
            'tab'   => 'content',
            'group' => 'buyButton',
            'label' => esc_html__('Border', 'fluent-cart'),
            'type'  => 'border',
            'css'   => [
                [
                    'property' => 'border',
                    'selector' => '.fct-product-buttons-wrap .fluent-cart-direct-checkout-button',
                ],
            ],
        ];
        $this->controls['directButtonTypography'] = [
            'tab'   => 'content',
            'group' => 'buyButton',
            'label' => esc_html__('Typography', 'fluent-cart'),
            'type'  => 'typography',
            'css'   => [
                [
                    'selector' => '.fct-product-buttons-wrap .fluent-cart-direct-checkout-button',
                    'property' => 'font',
                ],
            ],
        ];

        $this->controls['directButtonIcon'] = [
            'tab'      => 'content',
            'group'    => 'buyButton',
            'label'    => esc_html__('Icon', 'fluent-cart'),
            'type'     => 'icon',
            'rerender' => true,
        ];

        $this->controls['directButtonIconTypography'] = [
            'tab'   => 'content',
            'group' => 'buyButton',
            'label' => esc_html__('Icon typography', 'fluent-cart'),
            'type'  => 'typography',
            'css'   => [
                [
                    'property' => 'font',
                    'selector' => '.icon',
                ],
            ],
        ];

        $this->controls['directButtonIconOnly'] = [
            'tab'         => 'content',
            'group'       => 'buyButton',
            'label'       => esc_html__('Icon only', 'fluent-cart'),
            'type'        => 'checkbox',
            'inline'      => true,
            'placeholder' => esc_html__('Yes', 'fluent-cart'),
            'required'    => ['directButtonIcon', '!=', ''],
        ];

        $this->controls['directButtonIconPosition'] = [
            'tab'         => 'content',
            'group'       => 'buyButton',
            'label'       => esc_html__('Icon position', 'fluent-cart'),
            'type'        => 'select',
            'options'     => $this->control_options['iconPosition'],
            'inline'      => true,
            'placeholder' => esc_html__('Left', 'fluent-cart'),
            'required'    => [
                ['directButtonIcon', '!=', ''],
                ['directButtonIconOnly', '=', ''],
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
                'title' => esc_html__('Select a product', 'fluent-cart'),
            ]);
        }

        ?>
        <div
            <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping internally
                echo $this->render_attributes( '_root' );
            ?>
        >
            <?php (new ProductRenderer($product))->renderBuySection([
                'button_atts' => array_filter([
                    'buy_now_text'     => $this->getBuyNowText(),
                    'add_to_cart_text' => $this->getAddToCartText(),
                    'is_icon_only'     => isset($this->settings['iconOnly']),
                ]),
                'variation_atts' => [
                    'wrapper_class' => 'bricks-fct-variation-swatches'
                ],
            ]); ?>
        </div>

        <?php
    }

    public function getAddToCartText()
    {
        $settings = $this->settings;
        $buttonText = !empty($settings['buttonText']) ? $settings['buttonText'] : __('Add To Cart', 'fluent-cart');

        $icon = !empty($settings['icon']) ? self::render_icon($settings['icon'], ['icon']) : false;
        $icon_position = isset($settings['iconPosition']) ? $settings['iconPosition'] : 'left';
        $icon_only = isset($settings['iconOnly']);

        // Build HTML
        $output = '';

        if ($icon_only && $icon) {
            // Icon only (@since 1.12.2)
            $output = $icon;
        } else {
            if (!$icon) {
                $output = $buttonText;
            } else if ($icon_position === 'left') {
                $output .= $icon;
                $output .= "<span>$buttonText</span>";
            } else if ($icon_position === 'right') {
                $output .= "<span>$buttonText</span>" . $icon;
            }
        }

        return $output;
    }

    public function getBuyNowText()
    {
        $settings = $this->settings;
        $buttonText = !empty($settings['directButtonText']) ? $settings['directButtonText'] : __('Buy Now', 'fluent-cart');

        $icon = !empty($settings['directButtonIcon']) ? self::render_icon($settings['directButtonIcon'], ['icon']) : false;
        $icon_position = isset($settings['directButtonIconPosition']) ? $settings['directButtonIconPosition'] : 'left';
        $icon_only = isset($settings['directButtonIconOnly']);

        // Build HTML
        $output = '';

        if ($icon_only && $icon) {
            // Icon only (@since 1.12.2)
            $output = $icon;
        } else {
            if (!$icon) {
                $output = $buttonText;
            } else if ($icon_position === 'left') {
                $output .= $icon;
                $output .= "<span>$buttonText</span>";
            } else if ($icon_position === 'right') {
                $output .= "<span>$buttonText</span>" . $icon;
            }
        }

        return $output;
    }
}
