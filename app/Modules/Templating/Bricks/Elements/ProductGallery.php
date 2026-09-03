<?php

namespace FluentCart\App\Modules\Templating\Bricks\Elements;


use Bricks\Element;
use Bricks\Setup;
use FluentCart\App\Modules\Data\ProductDataSetup;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Modules\Templating\Bricks\BricksLoader;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class ProductGallery extends Element
{
    public $category = 'fluent-cart';
    public $name = 'fct-product-gallery';
    public $icon = 'ti-gallery fluent-cart-element-icon';

    public function enqueue_scripts()
    {
        AssetLoader::loadSingleProductAssets();
    }

    public function get_label()
    {
        return esc_html__('Product Gallery', 'fluent-cart');
    }

    public function set_controls()
    {
        $this->controls['_width']['rerender'] = true;
        $this->controls['_widthMin']['rerender'] = true;
        $this->controls['_widthMax']['rerender'] = true;

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
            'rerender'    => true,
        ];

        $this->controls['scrollableThumbs'] = [
            'tab'      => 'content',
            'label'    => esc_html__('Scrollable thumbnails', 'fluent-cart'),
            'type'     => 'checkbox',
            'inline'   => true,
            'rerender' => true,
        ];

        $this->controls['maxThumbnails'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Max thumbnails', 'fluent-cart'),
            'type'        => 'number',
            'min'         => 1,
            'max'         => 50,
            'placeholder' => esc_html__('No limit', 'fluent-cart'),
            'rerender'    => true,
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

        // Thumbnail position
        $thumbnail_position = !empty($settings['thumbnailPosition']) ? $settings['thumbnailPosition'] : 'bottom';
        if (!in_array($thumbnail_position, ['bottom', 'top', 'left', 'right'], true)) {
            $thumbnail_position = 'bottom';
        }
        $this->set_attribute('_root', 'data-pos', esc_attr($thumbnail_position));

        $scrollable_thumbs = !empty($settings['scrollableThumbs']) ? 'yes' : 'no';

        $max_thumbnails = !empty($settings['maxThumbnails']) ? (int) $settings['maxThumbnails'] : null;
        if ($max_thumbnails !== null && $max_thumbnails <= 0) {
            $max_thumbnails = null;
        }

        ?>

            <div
                <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping
                    echo $this->render_attributes( '_root' );
                ?>
            >
        
                <?php 
                    (new ProductRenderer($product))->renderGallery([
                        'thumb_position'    => $thumbnail_position,
                        'scrollable_thumbs' => $scrollable_thumbs,
                        'max_thumbnails'    => $max_thumbnails,
                    ]);
                ?>
        
            </div>

        <?php
    }

}
