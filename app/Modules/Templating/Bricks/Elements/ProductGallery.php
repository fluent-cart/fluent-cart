<?php

namespace FluentCart\App\Modules\Templating\Bricks\Elements;

use Bricks\Database;
use Bricks\Element;
use Bricks\Setup;
//use FluentCart\App\Hooks\Handlers\ShortCodes\Buttons\AddToCartShortcode;
use FluentCart\App\Hooks\Handlers\ShortCodes\SingleProductShortCode;
use FluentCart\App\Modules\Data\ProductDataSetup;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Vite;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class ProductGallery extends Element
{
    public $category = 'fluent_cart_product';
    public $name = 'fct-product-gallery';
    public $icon = 'ti-gallery';

    public function enqueue_scripts()
    {
        AssetLoader::loadSingleProductAssets();
    }

    public function get_label()
    {
        return esc_html__('Product gallery', 'fluent-cart');
    }

    public function set_controls()
    {
        $this->controls['_width']['rerender'] = true;
        $this->controls['_widthMin']['rerender'] = true;
        $this->controls['_widthMax']['rerender'] = true;

        $this->controls['productImageSize'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Product', 'fluent-cart') . ': ' . esc_html__('Image size', 'fluent-cart'),
            'type'        => 'select',
            'options'     => Setup::get_image_sizes_options(),
            'placeholder' => 'image_size',
        ];

        $this->controls['thumbnailImageSize'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Thumbnail', 'fluent-cart') . ': ' . esc_html__('Image size', 'fluent-cart'),
            'type'        => 'select',
            'options'     => Setup::get_image_sizes_options(),
            'placeholder' => 'thumbnail',
        ];

        $this->controls['lightboxImageSize'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Lightbox', 'fluent-cart') . ': ' . esc_html__('Image size', 'fluent-cart'),
            'type'        => 'select',
            'options'     => Setup::get_image_sizes_options(),
            'placeholder' => 'full',
        ];

        // THUMBNAILS

        $this->controls['thumbnailSep'] = [
            'tab'   => 'content',
            'label' => esc_html__('Thumbnail navigation', 'fluent-cart'),
            'type'  => 'separator',
        ];

        $this->controls['thumbnailPosition'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Position', 'fluent-cart'),
            'type'        => 'select',
            'inline'      => true,
            'options'     => [
                'top'    => esc_html__('Top', 'fluent-cart'),
                'right'  => esc_html__('Right', 'fluent-cart'),
                'bottom' => esc_html__('Bottom', 'fluent-cart'),
                'left'   => esc_html__('Left', 'fluent-cart'),
            ],
            'placeholder' => esc_html__('Bottom', 'fluent-cart'),
        ];

        $this->controls['itemWidth'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Item width', 'fluent-cart') . ' (px)',
            'type'        => 'number',
            'units'       => true,
            'css'         => [
                [
                    'selector' => '.fct-gallery-thumb-controls .fct-gallery-thumb-control-button',
                    'property' => 'width',
                ]
            ],
            'placeholder' => '70px',
            'rerender'    => true
        ];
        $this->controls['itemHeight'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Item Height', 'fluent-cart') . ' (px)',
            'type'        => 'number',
            'units'       => true,
            'css'         => [
                [
                    'selector' => '.fct-gallery-thumb-controls .fct-gallery-thumb-control-button',
                    'property' => 'height',
                ]
            ],
            'placeholder' => '70px',
            'rerender'    => true
        ];

        $this->controls['gap'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Gap', 'fluent-cart'),
            'type'        => 'number',
            'units'       => true,
            'css'         => [
                [

                    'selector' => '.fct-gallery-thumb-controls',
                    'property' => 'gap',
                ]
            ],
            'placeholder' => '30px',
        ];

        $this->controls['thumbnailOpacity'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Opacity', 'fluent-cart'),
            'type'        => 'number',
            'step'        => 0.1,
            'css'         => [
                [
                    'selector' => '.fct-gallery-thumb-controls .fct-gallery-thumb-control-button:not(.active) img',
                    'property' => 'opacity',
                ]
            ],
            'placeholder' => '0.3',
        ];

        $this->controls['thumbnailActiveOpacity'] = [
            'tab'   => 'content',
            'label' => esc_html__('Opacity', 'fluent-cart') . ' (' . esc_html__('Active', 'fluent-cart') . ')',
            'type'  => 'number',
            'step'  => 0.1,
            'css'   => [
                [
                    'selector' => '.fct-gallery-thumb-controls .fct-gallery-thumb-control-button.active',
                    'property' => 'opacity',
                ]
            ],
        ];

        $this->controls['thumbnailBorder'] = [
            'tab'   => 'content',
            'label' => esc_html__('Border', 'fluent-cart'),
            'type'  => 'border',
            'css'   => [
                [
                    'selector' => '.fct-gallery-thumb-controls .fct-gallery-thumb-control-button',
                    'property' => 'border',
                ]
            ],
        ];

        $this->controls['thumbnailActiveBorder'] = [
            'tab'   => 'content',
            'label' => esc_html__('Border', 'fluent-cart') . ' (' . esc_html__('Active', 'fluent-cart') . ')',
            'type'  => 'border',
            'css'   => [
                [
                    'selector' => '.fct-gallery-thumb-controls .fct-gallery-thumb-control-button.active',
                    'property' => 'border',
                ]
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

        $settings = $this->settings;

        // Thumbnail position
        $thumbnail_position = !empty($settings['thumbnailPosition']) ? $settings['thumbnailPosition'] : 'bottom';
        $this->set_attribute('_root', 'data-pos', esc_attr($thumbnail_position));

        // Thumbnail slider enabled
        $thumbnail_slider = isset($settings['thumbnailSlider']) ? $settings['thumbnailSlider'] : false;

        if ($thumbnail_slider) {
            $this->set_attribute('_root', 'class', 'thumbnail-slider');
        }

        // STEP: Render
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping
        echo "<div {$this->render_attributes( '_root' )}>";

        (new ProductRenderer($product))->renderGallery([
            'thumb_position' => $thumbnail_position,
        ]);

        echo '</div>';
    }

}
