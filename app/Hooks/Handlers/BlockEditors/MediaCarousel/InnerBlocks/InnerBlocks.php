<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors\MediaCarousel\InnerBlocks;

use FluentCart\Api\Contracts\CanEnqueue;
use FluentCart\Api\Resource\ProductResource;
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

    public static $parentBlock = 'fluent-cart/media-carousel';

    public array $blocks = [];

    public static function register()
    {
        $self = new self();
        $blocks = $self->getInnerBlocks();
        
        foreach ($blocks as $block) {
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
                'title'        => __('Media Carousel Loop', 'fluent-cart'),
                'slug'         => 'fluent-cart/media-carousel-loop',
                'callback'     => [$this, 'renderMediaCarouselLoop'],
                'component'    => 'MediaCarouselLoopBlock',
                'icon'         => 'slides',
                'parent'       => [
                    'fluent-cart/media-carousel',
                    'fluent-cart/shopapp-product-loop',
                ],
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
                    'fluent-cart/query_type',
                    'fluent-cart/carousel_settings',
                    'fluent-cart/product_id',
                    'fluent-cart/variation_ids',
                    'fluent-cart/has_controls',
                    'fluent-cart/has_pagination',
                ],
            ],
            [
                'title'     => __('Product Image', 'fluent-cart'),
                'slug'      => 'fluent-cart/media-carousel-product-image',
                'parent'    => [
                    'fluent-cart/media-carousel-loop',
                    'fluent-cart/shopapp-product-loop',
                ],
                'callback'  => [$this, 'renderImage'],
                'component' => 'ProductImageBlockEditor',
                'icon'      => 'format-image',
                'supports'  => [
                    'html'  => false,
                    'align' => ['left', 'center', 'right', 'wide', 'full'],
                    '__experimentalBorder' => [
                        'color'  => true,
                        'radius' => true,
                        'style'  => true,
                        'width'  => true,
                    ],
                    'spacing' => [
                        'margin'  => true,
                        'padding' => true,
                    ],
                    'shadow' => true,
                    '__experimentalFilter' => [
                        'duotone' => true,
                    ],
                ],
                'uses_context' => [
                    'fluent-cart/query_type',
                    'fluent-cart/current_image',
                    'fluent-cart/image_index',
                ],
            ],
            [
                'title'        => __('Carousel Controls', 'fluent-cart'),
                'slug'         => 'fluent-cart/media-carousel-controls',
                'component'    => 'CarouselControlsBlock',
                'icon'         => 'controls-repeat',
                'parent'       => [
                    'fluent-cart/media-carousel',
                    'fluent-cart/shopapp-product-loop',
                ],
                'supports'     => ['html' => false],
                'uses_context' => [
                    'fluent-cart/carousel_settings',
                ],
            ],
            [
                'title'        => __('Carousel Pagination', 'fluent-cart'),
                'slug'         => 'fluent-cart/media-carousel-pagination',
                'component'    => 'CarouselPaginationBlock',
                'icon'         => 'ellipsis',
                'parent'       => [
                    'fluent-cart/media-carousel',
                    'fluent-cart/shopapp-product-loop',
                ],
                'supports'     => ['html' => false],
                'uses_context' => [
                    'fluent-cart/carousel_settings',
                ],
            ],

        ];
    }

    public function renderImage($attributes, $content, $block): string
    {
        // Gutenberg frontend supports
        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => 'fct-media-carousel-product-image',
        ]);

        // Image from carousel loop context
        $image = Arr::get($block->context, 'fluent-cart/current_image');

        // Fallback: product image
        if (!$image) {
            $product = $this->getProductFromBlockContext($block);
            if (!$product) {
                return '';
            }

            $image = [
                'url'   => $product->thumbnail ?: Vite::getAssetUrl('images/placeholder.svg'),
                'title' => $product->post_title,
                'link'  => $product->view_url ?? '',
            ];
        }

        $alt = $image['title'] ?? '';

        ob_start();
        ?>
        <div <?php echo $wrapper_attributes; ?>>
            <a
                class="fct-product-image-link"
                href="<?php echo esc_url($image['link'] ?? '#'); ?>"
                aria-label="<?php echo esc_attr($alt); ?>"
            >
                <img
                    class="fct-product-image"
                    src="<?php echo esc_url($image['url']); ?>"
                    alt="<?php echo esc_attr($alt); ?>"
                    loading="lazy"
                />
            </a>
        </div>
        <?php

        return ob_get_clean();
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
            'autoplay'        => 'yes', // 'no' | 'yes' | 'hover'
            'autoplayDelay'   => 3000,

            // Controls
            'arrows'          => 'yes',
            'arrowsSize'      => 'md', // sm | md | lg

            // Pagination
            'pagination'      => 'yes',
            'paginationType'  => 'bullets', // bullets | fraction | progress | segmented
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

    protected function isSlideBlock($block): bool
    {
        return !in_array($block->name, [
            'fluent-cart/media-carousel-controls',
            'fluent-cart/media-carousel-pagination',
        ], true);
    }

    public function renderMediaCarouselLoop($attributes, $content, $block): string
    {
        $carouselSettings = $this->getCarouselSettingsFromContext($block);
        $productId    = Arr::get($block->context, 'fluent-cart/product_id');
        $variationIds = Arr::get($block->context, 'fluent-cart/variation_ids', []);
        $queryType    = Arr::get($block->context, 'fluent-cart/query_type', 'default');
        
        $hasControls = Arr::get($block->context, 'fluent-cart/has_controls', 'yes');
        $hasPagination = Arr::get($block->context, 'fluent-cart/has_pagination', 'yes');
        if (!$hasControls) {
            $carouselSettings['arrows'] = 'no';
        }

        if (!$hasPagination) {
            $carouselSettings['pagination'] = 'no';
        }

        $product = null;
        if ($queryType === 'default') {
            $product = fluent_cart_get_current_product();
        }
        else {
            $product = ProductResource::findByProductAndVariants([
                'product_id'  => $productId,
                'variant_ids' => $variationIds,
            ]);
        }

        if (empty($product)) {
            return '';
        }
        
        $product = $product->toArray();

        /**
         * ---------------------------------------------------------
         * Build IMAGE LIST
         * ---------------------------------------------------------
         */
        $images = [];

        // Product gallery images
        $gallery = Arr::get($product, 'detail.gallery_image.meta_value', []);

        foreach ($gallery as $img) {
            if (Arr::get($img, 'url')) {
                $images[] = $img;
            }
        }

        // Variant images
        $variants = Arr::get($product, 'variants', []);

        foreach ($variants as $variant) {
            $variantImages = Arr::get($variant, 'media.meta_value', []);

            foreach ($variantImages as $img) {
                if (Arr::get($img, 'url')) {
                    $images[] = $img;
                }
            }
        }

        if (empty($images)) {
            $placeholder = Vite::getAssetUrl('images/placeholder.svg');

            $images[] = [
                'url'   => $placeholder,
                'title' => sprintf(
                    /* translators: %s: product title */
                    __('Placeholder image for %s', 'fluent-cart'),
                    Arr::get($product, 'post_title', '')
                ),
                'is_placeholder' => true,
            ];
        }

        /**
         * Hide pagination and arrows if there's only one image or only placeholder
         */
        $isOnlyPlaceholder = count($images) === 1 && Arr::get($images[0], 'is_placeholder');
        $isSingleImage = count($images) === 1;

        if ($isOnlyPlaceholder || $isSingleImage) {
            $hasControls = 'no';
            $hasPagination = 'no';
            $carouselSettings['arrows'] = 'no';
            $carouselSettings['pagination'] = 'no';
        }

        /**
         * ---------------------------------------------------------
         * Render Swiper (IMAGE-LEVEL, INNER BLOCK DRIVEN)
         * ---------------------------------------------------------
         */
        $blockContext = array_merge(
            $block->context,
            [
                'fluent-cart/product_id'    => Arr::get($block->context, 'fluent-cart/product_id'),
                'fluent-cart/variation_ids' => Arr::get($block->context, 'fluent-cart/variation_ids', []),
            ]
        );

        $html  = '<div class="fct-product-carousel-wrapper">';
        $html .= '<div class="swiper fct-product-carousel"
            data-fluent-cart-product-carousel
            data-carousel-settings="' . esc_attr(wp_json_encode($carouselSettings)) . '">';

        $html .= '<div class="swiper-wrapper">';

        foreach ($images as $index => $image) {

            $html .= '<div class="swiper-slide"
                data-image-index="' . esc_attr($index) . '">';

            // Inject CURRENT IMAGE into context
            $slideContext = array_merge($blockContext, [
                'fluent-cart/current_image' => $image,
                'fluent-cart/image_index'   => $index,
            ]);

            // Render INNER BLOCKS (Product Image block, overlays, etc.)
            foreach ($block->inner_blocks as $inner_block) {

                if (
                    !isset($inner_block->parsed_block) ||
                    !$this->isSlideBlock($inner_block)
                ) {
                    continue;
                }

                $instance = new \WP_Block(
                    $inner_block->parsed_block,
                    $slideContext
                );

                $html .= $instance->render();
            }

            $html .= '</div>';
        }

        $html .= '</div>';

        // Controls & pagination
        if ($hasControls === 'yes') {
            $html .= $this->renderCarouselControls([], '', $block);
        }

        if ($hasPagination === 'yes') {
            $html .= $this->renderCarouselPagination([], '', $block);
        }

        $html .= '</div></div>';

        return $html;
    }

    public function renderCarouselControls($attributes, $content, $block): string
    {
        $settings = $this->getCarouselSettingsFromContext($block);

        if (Arr::get($settings, 'arrows') !== 'yes') {
            return '';
        }

        $size = Arr::get($settings, 'arrowsSize');

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

        if (Arr::get($settings, 'pagination') !== 'yes') {
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
        return 'fluent_cart_media_carousel_inner_blocks';
    }

    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey()      => [
                'blocks' => Arr::except($this->getInnerBlocks(), ['callback']),
            ],
            'fluentcart_single_product_vars' => [
                'trans'                      => TransStrings::singleProductPageString(),
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
                    'source'       => 'admin/BlockEditor/MediaCarousel/InnerBlocks/InnerBlocks.jsx',
                    'dependencies' => ['wp-blocks', 'wp-components', 'wp-block-editor']
            ],
        ];

        return $scripts;
    }

    protected function generateEnqueueSlug(): string
    {
        return 'fluent_cart_media_carousel_inner_blocks';
    }
}
