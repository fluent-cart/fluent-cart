<?php

namespace FluentCart\App\Modules\Templating\Bricks\Elements;

use Bricks\Element;
use FluentCart\App\Modules\Data\ProductDataSetup;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductRenderer;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class ProductAddToCart extends Element
{
    public $category = 'fluent_cart_product';
    public $name = 'fct-product-buy-section';
    public $icon = 'ti-shopping-cart';

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
        $this->control_groups['variation-swatches'] = [
            'title' => esc_html__('Variation swatches', 'fluent-cart'),
            'tab'   => 'content',
        ];

        $this->control_groups['quantity'] = [
            'title' => esc_html__('Quantity', 'fluent-cart'),
            'tab'   => 'content',
        ];

        $this->control_groups['buy_button'] = [
            'title' => esc_html__('Direct Buy Button', 'fluent-cart'),
            'tab'   => 'content',
        ];

        $this->control_groups['button'] = [
            'title' => esc_html__('Add to Cart Button', 'fluent-cart'),
            'tab'   => 'content',
        ];

    }

    public function set_controls()
    {

        // Common swatch controls (for all types) (@since 2.0)
        $this->controls['swatchesWrap'] = [
            'group'   => 'variation-swatches',
            'label'   => esc_html__('Wrap', 'fluent-cart'),
            'type'    => 'select',
            'inline'  => true,
            'options' => [
                'nowrap'       => esc_html__('No wrap', 'fluent-cart'),
                'wrap'         => esc_html__('Wrap', 'fluent-cart'),
                'wrap-reverse' => esc_html__('Wrap reverse', 'fluent-cart'),
            ],
            'css'     => [
                [
                    'property' => 'flex-wrap',
                    'selector' => '.bricks-fct-variation-swatches',
                ],
            ],
        ];

        $this->controls['swatchesDirection'] = [
            'group'  => 'variation-swatches',
            'label'  => esc_html__('Direction', 'fluent-cart'),
            'type'   => 'direction',
            'css'    => [
                [
                    'property' => 'flex-direction',
                    'selector' => '.bricks-fct-variation-swatches',
                ],
            ],
            'inline' => true,
        ];

        $this->controls['swatchesJustifyContent'] = [
            'group' => 'variation-swatches',
            'label' => esc_html__('Align main axis', 'fluent-cart'),
            'type'  => 'justify-content',
            'css'   => [
                [
                    'property' => 'justify-content',
                    'selector' => '.bricks-fct-variation-swatches',
                ],
            ],
        ];

        $this->controls['swatchesAlignItems'] = [
            'group' => 'variation-swatches',
            'label' => esc_html__('Align cross axis', 'fluent-cart'),
            'type'  => 'align-items',
            'css'   => [
                [
                    'property' => 'align-items',
                    'selector' => '.bricks-fct-variation-swatches',
                ],
            ],
        ];

        $this->controls['swatchesColumnGap'] = [
            'group'       => 'variation-swatches',
            'label'       => esc_html__('Column gap', 'fluent-cart'),
            'type'        => 'number',
            'units'       => true,
            'css'         => [
                [
                    'property' => 'column-gap',
                    'selector' => '.bricks-fct-variation-swatches',
                ],
            ],
            'placeholder' => '8px',
        ];

        $this->controls['swatchesRowGap'] = [
            'group'       => 'variation-swatches',
            'label'       => esc_html__('Row gap', 'fluent-cart'),
            'type'        => 'number',
            'units'       => true,
            'css'         => [
                [
                    'property' => 'row-gap',
                    'selector' => '.bricks-fct-variation-swatches',
                ],
            ],
            'placeholder' => '8px',
        ];

        $this->controls['colorSwatchBorder'] = [
            'group' => 'variation-swatches',
            'label' => esc_html__('Border', 'fluent-cart'),
            'type'  => 'border',
            'css'   => [
                [
                    'property' => 'border',
                    'selector' => '.bricks-fct-variation-swatches .fct-product-variant-item',
                ],
            ],
        ];

        $this->controls['colorSwatchActiveBorder'] = [
            'group' => 'variation-swatches',
            'label' => esc_html__('Border', 'fluent-cart') . ' (' . esc_html__('Active', 'fluent-cart') . ')',
            'type'  => 'border',
            'css'   => [
                [
                    'property' => 'border',
                    'selector' => '.bricks-fct-variation-swatches .fct-product-variant-item.selected',
                ],
            ],
        ];

        // Label swatch specific controls
        $this->controls['labelSwatchSeparator'] = [
            'group' => 'variation-swatches',
            'label' => esc_html__('Type', 'fluent-cart') . ': ' . esc_html__('Label', 'fluent-cart'),
            'type'  => 'separator',
        ];

        $this->controls['labelSwatchPadding'] = [
            'group'       => 'variation-swatches',
            'label'       => esc_html__('Padding', 'fluent-cart'),
            'type'        => 'spacing',
            'css'         => [
                [
                    'property' => 'padding',
                    'selector' => '.bricks-fct-variation-swatches .fct-product-variant-item .fct-product-variant-title',
                ],
            ],
            'placeholder' => [
                'top'    => '0',
                'right'  => '10px',
                'bottom' => '0',
                'left'   => '10px',
            ],
        ];

        $this->controls['labelSwatchTypography'] = [
            'group' => 'variation-swatches',
            'label' => esc_html__('Typography', 'fluent-cart'),
            'type'  => 'typography',
            'css'   => [
                [
                    'property' => 'font',
                    'selector' => '.bricks-fct-variation-swatches .fct-product-variant-item .fct-product-variant-title',
                ],
            ],
        ];

        $this->controls['labelSwatchActiveTypography'] = [
            'group' => 'variation-swatches',
            'label' => esc_html__('Typography', 'fluent-cart') . ' (' . esc_html__('Active', 'fluent-cart') . ')',
            'type'  => 'typography',
            'css'   => [
                [
                    'property' => 'font',
                    'selector' => '.bricks-fct-variation-swatches .fct-product-variant-item.selected .fct-product-variant-title',
                ],
            ],
        ];

        $this->controls['labelSwatchBackgroundColor'] = [
            'group' => 'variation-swatches',
            'label' => esc_html__('Background color', 'fluent-cart'),
            'type'  => 'color',
            'css'   => [
                [
                    'property' => 'background-color',
                    'selector' => '.bricks-fct-variation-swatches .fct-product-variant-item',
                ],
            ],
        ];

        $this->controls['labelSwatchActiveBackgroundColor'] = [
            'group' => 'variation-swatches',
            'label' => esc_html__('Background color', 'fluent-cart') . ' (' . esc_html__('Active', 'fluent-cart') . ')',
            'type'  => 'color',
            'css'   => [
                [
                    'property' => 'background-color',
                    'selector' => '.bricks-fct-variation-swatches .fct-product-variant-item.selected',
                ],
            ],
        ];

        $this->controls['imageSwatchWidth'] = [
            'group'       => 'variation-swatches',
            'label'       => esc_html__('Swatch Image Width', 'fluent-cart'),
            'type'        => 'number',
            'units'       => true,
            'css'         => [
                [
                    'property' => 'width',
                    'selector' => '.bricks-fct-variation-swatches .fct-product-variant-item img',
                ],
            ],
            'placeholder' => '32px',
        ];

        $this->controls['imageSwatchHeight'] = [
            'group'       => 'variation-swatches',
            'label'       => esc_html__('Swatch Image Height', 'fluent-cart'),
            'type'        => 'number',
            'units'       => true,
            'css'         => [
                [
                    'property' => 'height',
                    'selector' => '.bricks-fct-variation-swatches .fct-product-variant-item img',
                ],
            ],
            'placeholder' => '32px',
        ];

        // QUANTITY
        $this->controls['quantityWidth'] = [
            'tab'   => 'content',
            'group' => 'quantity',
            'type'  => 'number',
            'units' => true,
            'label' => esc_html__('Width', 'fluent-cart'),
            'css'   => [
                [
                    'selector' => '.fct-product-quantity',
                    'property' => 'width',
                ],
            ],
        ];

        $this->controls['quantityBackground'] = [
            'tab'   => 'content',
            'group' => 'quantity',
            'type'  => 'color',
            'label' => esc_html__('Background', 'fluent-cart'),
            'css'   => [
                [
                    'selector' => '.fct-product-quantity',
                    'property' => 'background-color',
                ],
            ],
        ];

        $this->controls['quantityBorder'] = [
            'tab'   => 'content',
            'group' => 'quantity',
            'type'  => 'border',
            'label' => esc_html__('Border', 'fluent-cart'),
            'css'   => [
                [
                    'selector' => '.fct-product-quantity .fct-quantity-input',
                    'property' => 'border',
                ],
                [
                    'selector' => '.fct-product-quantity .fct-quantity-decrease-button',
                    'property' => 'border',
                ],
                [
                    'selector' => '.fct-product-quantity .fct-quantity-increase-button',
                    'property' => 'border',
                ],
            ],
        ];

        // BUTTONS
        $this->controls['buttonInfo'] = [
            'tab'     => 'content',
            'group'   => 'button',
            'type'    => 'info',
            'content' => esc_html__('Add to Cart Button will be shown only for non-subscribable products.', 'fluent-cart'),
        ];
        $this->controls['buttonText'] = [
            'tab'         => 'content',
            'group'       => 'button',
            'type'        => 'text',
            'inline'      => true,
            'label'       => esc_html__('Button Text', 'fluent-cart'),
            'placeholder' => esc_html__('Add to cart', 'fluent-cart'),
        ];
        $this->controls['buttonMargin'] = [
            'tab'   => 'content',
            'group' => 'button',
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
            'group' => 'button',
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
            'group' => 'button',
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
            'group' => 'button',
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
            'group' => 'button',
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
            'group' => 'button',
            'label' => esc_html__('Typography', 'fluent-cart'),
            'type'  => 'typography',
            'css'   => [
                [
                    'selector' => '.fct-product-buttons-wrap .fluent-cart-add-to-cart-button',
                    'property' => 'font',
                ],
            ],
        ];
        // Button icon
        $this->controls['icon'] = [
            'tab'      => 'content',
            'group'    => 'button',
            'label'    => esc_html__('Icon', 'fluent-cart'),
            'type'     => 'icon',
            'rerender' => true,
        ];
        $this->controls['iconTypography'] = [
            'tab'   => 'content',
            'group' => 'button',
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
            'group'       => 'button',
            'label'       => esc_html__('Icon only', 'fluent-cart'),
            'type'        => 'checkbox',
            'inline'      => true,
            'placeholder' => esc_html__('Yes', 'fluent-cart'),
            'required'    => ['icon', '!=', ''],
        ];
        $this->controls['iconPosition'] = [
            'tab'         => 'content',
            'group'       => 'button',
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
            'group'       => 'buy_button',
            'type'        => 'text',
            'inline'      => true,
            'label'       => esc_html__('Button Text', 'fluent-cart'),
            'placeholder' => esc_html__('Buy Now', 'fluent-cart'),
        ];
        $this->controls['directButtonMargin'] = [
            'tab'   => 'content',
            'group' => 'buy_button',
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
            'group' => 'buy_button',
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
            'group' => 'buy_button',
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
            'group' => 'buy_button',
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
            'group' => 'buy_button',
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
            'group' => 'buy_button',
            'label' => esc_html__('Typography', 'fluent-cart'),
            'type'  => 'typography',
            'css'   => [
                [
                    'selector' => '.fct-product-buttons-wrap .fluent-cart-direct-checkout-button',
                    'property' => 'font',
                ],
            ],
        ];
        // Button icon
        $this->controls['directButtonIcon'] = [
            'tab'      => 'content',
            'group'    => 'buy_button',
            'label'    => esc_html__('Icon', 'fluent-cart'),
            'type'     => 'icon',
            'rerender' => true,
        ];
        $this->controls['directButtonIconTypography'] = [
            'tab'   => 'content',
            'group' => 'buy_button',
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
            'group'       => 'buy_button',
            'label'       => esc_html__('Icon only', 'fluent-cart'),
            'type'        => 'checkbox',
            'inline'      => true,
            'placeholder' => esc_html__('Yes', 'fluent-cart'),
            'required'    => ['icon', '!=', ''],
        ];
        $this->controls['directButtonIconPosition'] = [
            'tab'         => 'content',
            'group'       => 'buy_button',
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

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping internally
        echo "<div {$this->render_attributes( '_root' )}>";
        (new ProductRenderer($product))->renderBuySection([
            'button_atts' => array_filter([
                'buy_now_text'     => $this->getBuyNowText(),
                'add_to_cart_text' => $this->getAddToCartText(),
            ]),
            'variation_atts' => [
                'wrapper_class' => 'bricks-fct-variation-swatches'
            ],
        ]);
        echo '</div>';
    }

    public function getAddToCartText()
    {
        $settings = $this->settings;
        $buttonText = !empty($settings['buttonText']) ? $settings['buttonText'] : __('Add to Cart', 'fluent-cart');

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
                $output .= "<span style='margin-left: 8px;'>$buttonText</span>";
            } else if ($icon_position === 'right') {
                $output .= "<span style='margin-right: 8px;'>$buttonText</span>" . $icon;
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
                $output .= "<span style='margin-left: 8px;'>$buttonText</span>";
            } else if ($icon_position === 'right') {
                $output .= "<span style='margin-right: 8px;'>$buttonText</span>" . $icon;
            }
        }

        return $output;
    }
}
