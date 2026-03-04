<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors\ProductCarousel\InnerBlocks;

use FluentCart\Api\Contracts\CanEnqueue;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\TemplateService;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Vite;

class InnerBlocks
{
    use CanEnqueue;

    public static $parentBlock = 'fluent-cart/product-carousel';

    public array $blocks = [];

    public static function textBlockSupport(): array
    {
        return [
            'html'       => false,
            'align'      => ['left', 'center', 'right'],
            'typography' => [
                    'fontSize'   => true,
                    'lineHeight' => true
            ],
            'spacing'    => [
                    'margin' => true,
                'padding' => true
            ],
            'color'      => [
                    'text' => true,
            ]
        ];
    }

    public static function buttonBlockSupport(): array
    {
        return [
            'html'       => false,
            'align'      => ['left', 'center', 'right'],
            'typography' => [
                    'fontSize'      => true,
                    'lineHeight'    => true,
                    'fontWeight'    => true,
                    'textTransform' => true,
            ],
            'spacing'    => [
                    'margin'  => true,
                    'padding' => true,
            ],
            'color'      => [
                    'text'       => true,
                    'background' => true,
            ],
            'border'     => [
                    'radius' => true,
                    'color'  => true,
                    'width'  => true,
            ],
            'shadow'     => true,
        ];
    }

    public static function register()
    {
        $self = new self();
        $blocks = $self->getInnerBlocks();
        
        foreach ($blocks as $block) {
            // register_block_type($block['slug'], [
            //     'apiVersion'      => 3,
            //     'api_version'     => 3,
            //     'version'         => 3,
            //     'title'           => $block['title'],
            //     'parent'          => array_merge($block['parent'] ?? [], [static::$parentBlock]),
            //     'render_callback' => $block['callback'],
            //     'supports'        => Arr::get($block, 'supports', []),
            //     'attributes'      => Arr::get($block, 'attributes', []),
            //     'uses_context'    => Arr::get($block, 'uses_context', []),
            // ]);
            $args = [
                'apiVersion'   => 3,
                'api_version'  => 3,
                'version'      => 3,
                'title'        => $block['title'],
                'category'     => 'fluent-cart',
                'parent'       => array_merge($block['parent'] ?? [], [static::$parentBlock]),
                'supports'     => Arr::get($block, 'supports', []),
                'attributes'   => Arr::get($block, 'attributes', []),
                'uses_context' => Arr::get($block, 'uses_context', []),
            ];

            // Only add render_callback if it exists
            if (!empty($block['callback']) && is_callable($block['callback'])) {
                $args['render_callback'] = $block['callback'];
            }

            register_block_type($block['slug'], $args);

        }

        add_action('enqueue_block_editor_assets', function () use ($self) {
            $self->enqueueScripts();
        });
    }

    public function getInnerBlocks(): array
    {
        return [
            [
                'title'        => __('Product Carousel Loop', 'fluent-cart'),
                'slug'         => 'fluent-cart/product-carousel-loop',
                'callback'     => [$this, 'renderProductCarouselLoop'],
                'component'    => 'ProductCarouselLoopBlock',
                'icon'         => 'screenoptions',
                'parent'       => ['fluent-cart/product-carousel'],
                'supports'     => [
                    'align' => ['wide', 'full'],
                    'html'  => false,
                ],
                'attributes'   => array_merge(
                    \WP_Block_Type_Registry::get_instance()->get_registered('core/group')->attributes,
                    [
                        'customAttr' => ['type' => 'string'],
                    ]
                ),
                'uses_context' => [
                    'fluent-cart/carousel_settings',
                    'fluent-cart/product_ids',
                    'fluent-cart/has_controls',
                    'fluent-cart/has_pagination',
                ],
            ],
            [
                'title'        => __('Carousel Controls', 'fluent-cart'),
                'slug'         => 'fluent-cart/product-carousel-controls',
                'component'    => 'CarouselControlsBlock',
                'icon'         => 'controls-repeat',
                'parent'       => ['fluent-cart/product-carousel'],
                'supports'     => ['html' => false],
                'uses_context' => [
                    'fluent-cart/carousel_settings',
                ],
            ],
            [
                'title'        => __('Carousel Pagination', 'fluent-cart'),
                'slug'         => 'fluent-cart/product-carousel-pagination',
                'component'    => 'CarouselPaginationBlock',
                'icon'         => 'ellipsis',
                'parent'       => ['fluent-cart/product-carousel'],
                'supports'     => ['html' => false],
                'uses_context' => [
                    'fluent-cart/carousel_settings',
                ],
            ],

        ];
    }

    public function getProductFromBlockContext($block)
    {
        // Editor preview
        if (!empty($block->context['fluent_cart_current_product'])) {
            return $block->context['fluent_cart_current_product'];
        }

        // Frontend fallback
        return fluent_cart_get_current_product();
    }

    protected static function getDefaultCarouselSettings(): array
    {
        return [
            // Core
            'slidesToShow'     => 3,
            'spaceBetween'     => 4,
            'infinite'         => 'no',

            // Autoplay
            'autoplay'        => 'yes',
            'autoplayDelay'   => 3000,

            // Controls
            'arrows'          => 'yes',
            'arrowsSize'      => 'md',        // sm | md | lg

            // Pagination
            'pagination'          => 'yes',
            'paginationType'      => 'bullets', // bullets | fraction | progress | segmented
        ];
    }

    protected function getCarouselSettingsFromContext($block): array
    {
        $contextSettings = Arr::get($block->context, 'fluent-cart/carousel_settings', []);

        return array_merge(
            static::getDefaultCarouselSettings(),
            is_array($contextSettings) ? $contextSettings : []
        );
    }

    public function renderTitle($attributes, $content, $block): string
    {

        $product = $this->getProductFromBlockContext($block);

        $isLink = Arr::get($attributes, 'isLink', true);
        $target = Arr::get($attributes, 'linkTarget', '_self');


        if (empty($product)) {
            return 'not found';
        }

        $wrapper_attributes = get_block_wrapper_attributes(
            [
                'class' => 'fct-product-card-title wc-block-grid__product-title',
            ]
        );

        $render = new \FluentCart\App\Services\Renderer\ProductCardRender($product);
        ob_start();
        $render->renderTitle($wrapper_attributes, [
            'isLink' => $isLink,
            'target' => $target,
        ]);
        return ob_get_clean();
    }

    public function renderPrice($attributes, $content, $block): string
    {
        $product = $this->getProductFromBlockContext($block);

        $defaultPriceFormat = 'starts_from';
        if (is_singular('fluent-products') && TemplateService::getCurrentFcPageType() === 'single_product') {
            $defaultPriceFormat = 'range';
        }
        $priceFormat = Arr::get($block->context, 'fluent-cart/price_format', $defaultPriceFormat);

        if (empty($product)) {
            return '';
        }

        $wrapper_attributes = get_block_wrapper_attributes(
            [
                'class' => 'fct-product-card-prices',
            ]
        );

        $render = new \FluentCart\App\Services\Renderer\ProductCardRender($product, [
            'price_format' => $priceFormat,
        ]);
        ob_start();
        $render->renderPrices($wrapper_attributes);
        return ob_get_clean();
    }

    public function renderImage($attributes, $content, $block): string
    {
        $product = $this->getProductFromBlockContext($block);
        if (empty($product)) {
            return '';
        }
        $render = new \FluentCart\App\Services\Renderer\ProductCardRender($product);
        $renderedImage = '';

        $innerBlocksContent = '';
        if ($block instanceof \WP_Block && !empty($block->inner_blocks)) {
            $blockContext = $block->context;
            $innerBlocksContent .= '<div class="fct-product-image-inner-blocks">';
            foreach ($block->inner_blocks as $inner_block) {
                if (isset($inner_block->parsed_block)) {
                    $innerContext = array_merge($inner_block->context, $blockContext);
                    $instance = new \WP_Block($inner_block->parsed_block, $innerContext);
                    $innerBlocksContent .= $instance->render();
                }
            }
            $innerBlocksContent .= '</div>';
        }
        ob_start();
        $render->renderProductImage();
        $renderedImage = ob_get_clean();

        if (!empty($innerBlocksContent)) {
            return sprintf(
                "<div class='fct-product-image-wrap' style='position: relative;'>
                    <div>%s</div>
                    
                    <div style='position: absolute; top: 0; left: 0; width: 100%%; height: 100%%;'>
                        %s
                    </div>
                </div>",
                $renderedImage,
                $innerBlocksContent
            );
        }

        return $renderedImage;
    }

    public function renderButtons($attributes, $content, $block): string
    {
        $product = $this->getProductFromBlockContext($block);
        if (empty($product)) {
            return '';
        }
        $wrapper_attributes = get_block_wrapper_attributes();
        $render = new \FluentCart\App\Services\Renderer\ProductCardRender($product);
        ob_start();
        $render->showBuyButton($wrapper_attributes);
        return ob_get_clean();
    }

    public function renderExcerpt($attributes, $content, $block): string
    {
        $product = $this->getProductFromBlockContext($block);
        if (empty($product) || empty($product->post_excerpt)) {
            return '';
        }
        $wrapper_attributes = get_block_wrapper_attributes(
            [
                'class' => 'fct-product-card-excerpt',
            ]
        );
        $render = new \FluentCart\App\Services\Renderer\ProductCardRender($product);
        ob_start();
        $render->renderExcerpt($wrapper_attributes);
        return ob_get_clean();
    }

    protected function isSlideBlock($block): bool
    {
        return !in_array($block->name, [
            'fluent-cart/product-carousel-controls',
            'fluent-cart/product-carousel-pagination',
        ], true);
    }

    public function renderProductCarouselLoop($attributes, $content, $block): string
    {
        $carouselSettings = $this->getCarouselSettingsFromContext($block);

        $hasControls   = Arr::get($block->context, 'fluent-cart/has_controls', 'yes');
        $hasPagination = Arr::get($block->context, 'fluent-cart/has_pagination', 'yes');

        if ($hasControls !== 'yes') {
            $carouselSettings['arrows'] = 'no';
        }

        if ($hasPagination !== 'yes') {
            $carouselSettings['pagination'] = 'no';
        }

        $productIds = Arr::get($block->context,'fluent-cart/product_ids', []);

        $clientId = Arr::get($attributes, 'wp_client_id', '');

        $products = Product::query()->whereIn('id', $productIds)->get();
        if ($products->count() === 0) {
            return '';
        }

        $blockContext = $block->context;

        $innerBlocksContent  = '<div class="fct-product-carousel-wrapper">';
        $innerBlocksContent .= '<div class="swiper fct-product-carousel"
            data-fluent-cart-product-carousel
            data-carousel-settings="' . esc_attr(wp_json_encode($carouselSettings)) . '">';
        $innerBlocksContent .= '<div class="swiper-wrapper">';
        foreach ($products as $key => $product) {
            setup_postdata($product->ID);
            if ($block instanceof \WP_Block && !empty($block->inner_blocks)) {
                $innerBlocksContent .= '<div class="swiper-slide" data-id="' . esc_attr($product->ID) . '">';
                if ($key === 0) {
                    //add attribute provider with value wp-block
                    $innerBlocksContent .= '<div class="fct-product-card" data-template-provider="wp-block" data-fct-product-card data-fluent-client-id="' . esc_attr($clientId) . '">';
                } else {
                    $innerBlocksContent .= '<div class="fct-product-card" data-fct-product-card>';
                }

                // foreach ($block->inner_blocks as $inner_block) {
                //     if (isset($inner_block->parsed_block)) {
                //         $innerContext = array_merge($inner_block->context, $blockContext);
                //         $instance = new \WP_Block($inner_block->parsed_block, $innerContext);
                //         $innerBlocksContent .= $instance->render();
                //     }
                // }
                foreach ($block->inner_blocks as $inner_block) {
                    if (
                        !isset($inner_block->parsed_block) ||
                        !$this->isSlideBlock($inner_block)
                    ) {
                        continue;
                    }

                    $innerContext = array_merge($inner_block->context, $blockContext);
                    $instance = new \WP_Block($inner_block->parsed_block, $innerContext);
                    $innerBlocksContent .= $instance->render();
                }
                $innerBlocksContent .= '</div></div>';
            }
        }
        $innerBlocksContent .= '</div>';

        // Navigation arrows (INSIDE swiper, OUTSIDE wrapper)
        if ($hasControls === 'yes') {
            $innerBlocksContent .= $this->renderCarouselControls([], '', $block);
        }

        // Pagination (INSIDE swiper, OUTSIDE wrapper)
        if ($hasPagination === 'yes') {
            $innerBlocksContent .= $this->renderCarouselPagination([], '', $block);
        }

        $innerBlocksContent .= '</div></div>';

        wp_reset_postdata();

        return $innerBlocksContent;
    }

    public function renderCarouselControls($attributes, $content, $block): string
    {
        $settings = $this->getCarouselSettingsFromContext($block);

        if (Arr::get($settings, 'arrows') !== 'yes') {
            return '';
        }

        $size     = Arr::get($settings, 'arrowsSize');

        // Navigation arrows (INSIDE swiper, OUTSIDE wrapper)
        return sprintf(
            '<div class="fct-carousel-controls fct-arrows-%s">
                <div class="swiper-button-prev" aria-label="%s">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 18 9 12 15 6"></polyline>
                    </svg>
                </div>
                <div class="swiper-button-next" aria-label="%s">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </div>
            </div>',
            esc_attr($size),
            esc_attr__('Previous slide', 'fluent-cart'),
            esc_attr__('Next slide', 'fluent-cart')
        );
    }

    public function renderCarouselPagination($attributes, $content, $block): string
    {
        $settings = $this->getCarouselSettingsFromContext($block);

        // Backward compatibility: check both 'pagination' and legacy 'dots'
        $hasPagination = Arr::get($settings, 'pagination', Arr::get($settings, 'dots', 'no'));
        if ($hasPagination !== 'yes') {
            return '';
        }

        $type = Arr::get($settings, 'paginationType');

        return sprintf(
            '<div class="fct-carousel-pagination fct-pagination-%s">
                <div class="swiper-pagination"></div>
            </div>',
            esc_attr($type)
        );
    }

    protected function getLocalizationKey(): string
    {
        return 'fluent_cart_product_carousel_inner_blocks';
    }

    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey()      => [
                'blocks' => Arr::except($this->getInnerBlocks(), ['callback']),
            ],
            'fluentcart_single_product_vars' => [
                'trans'                      => TransStrings::singleProductPageString(),
                'cart_button_text'           => apply_filters('fluent_cart/product/add_to_cart_text', __('Add To Cart', 'fluent-cart'), []),
                // App::storeSettings()->get('cart_button_text', __('Add to Cart', 'fluent-cart')),
                'out_of_stock_button_text'   => apply_filters('fluent_cart/product/out_of_stock_text', __('Not Available', 'fluent-cart'), []),
                'in_stock_status'            => Helper::IN_STOCK,
                'out_of_stock_status'        => Helper::OUT_OF_STOCK,
                'enable_image_zoom'          => (new StoreSettings())->get('enable_image_zoom_in_single_product'),
                'enable_image_zoom_in_modal' => (new StoreSettings())->get('enable_image_zoom_in_modal')
            ]
        ];
    }

    protected function getStyles(): array
    {
        return [
            'public/single-product/single-product.scss',
        ];
    }

    protected function getScripts(): array
    {
        $scripts = [
            [
                    'source'       => 'admin/BlockEditor/ReactSupport.js',
                    'dependencies' => ['wp-blocks', 'wp-components']
            ],
            [
                    'source'       => 'admin/BlockEditor/ProductCarousel/InnerBlocks/InnerBlocks.jsx',
                    'dependencies' => ['wp-blocks', 'wp-components', 'wp-block-editor']
            ],
        ];

        if (App::isDevMode() || true) {
            $scripts[] = [
                'source'       => 'admin/BlockEditor/ReactSupport.js',
                'dependencies' => ['wp-blocks', 'wp-components']
            ];
        }
        return $scripts;
    }

    protected function generateEnqueueSlug(): string
    {
        return 'fluent_cart_product_carousel_inner_blocks';
    }
}
