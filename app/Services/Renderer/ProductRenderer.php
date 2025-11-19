<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\App;
use FluentCart\Framework\Support\Collection;

class ProductRenderer
{
    protected $product;

    protected $variants;

    protected $defaultVariant = null;

    protected $hasOnetime = false;

    protected $hasSubscription = false;

    protected $viewType = '';

    protected $columnType = '';

    protected $defaultVariationId = '';

    protected $paymentTypes = [];

    protected $variantsByPaymentTypes = [];

    protected $activeTab = 'onetime';

    protected $images = [];

    protected $defaultImageUrl = null;

    protected $defaultImageAlt = null;

    public function __construct(Product $product, $config = [])
    {
        $this->product = $product;
        $this->variants = $product->variants;
        $this->viewType = $config['view_type'] ?? 'both';
        $this->columnType = $config['column_type'] ?? 'masonry';
        $defaultVariationId = $config['default_variation_id'] ?? '';

        if (!$defaultVariationId) {
            $variationIds = $product->variants->pluck('id')->toArray();
            $defaultVariationId = $product->detail->default_variation_id;

            if (!$defaultVariationId || !in_array($defaultVariationId, $variationIds)) {
                $defaultVariationId = Arr::get($variationIds, '0');
            }

            $this->defaultVariationId = $defaultVariationId;
        }

        foreach ($this->product->variants as $variant) {
            if ($variant->id == $this->defaultVariationId) {
                $this->defaultVariant = $variant;
            }
            $paymentType = Arr::get($variant->other_info, 'payment_type');
            if ($paymentType === 'onetime') {
                $this->hasOnetime = true;
            } else if ($paymentType === 'subscription') {
                $this->hasSubscription = true;
            }
        }

        $this->buildProductGroups();
    }

    public function buildProductGroups()
    {
        $groupKey = 'repeat_interval';
        $otherInfo = (array)Arr::get($this->product->detail, 'other_info');
        $groupBy = Arr::get($otherInfo, 'group_pricing_by', 'repeat_interval'); //repeat_interval,payment_type,none


        if ($groupBy !== 'none') {
            if ($groupBy === 'payment_type') {
                $groupKey = 'payment_type';
            }

            $paymentTypes = [];

            if ($groupBy === 'repeat_interval') {
                foreach ($this->variants as $key => $variant) {
                    $paymentType = 'onetime';
                    $type = Arr::get($variant, 'payment_type');
                    if ($type === 'subscription') {
                        $isInstallment = Arr::get($variant, 'other_info.installment', 'no');
                        if ($isInstallment === 'yes' && App::isProActive()) {
                            $paymentType = 'installment';
                        } else {
                            $paymentType = Arr::get($variant, 'other_info.repeat_interval', 'onetime');;
                        }
                    }

                    $paymentTypes[] = $paymentType;

                    if (!isset($this->variantsByPaymentTypes[$paymentType])) {
                        $this->variantsByPaymentTypes[$paymentType] = [];
                    }

                    $this->variantsByPaymentTypes[$paymentType][] = $variant;

                    if ($this->defaultVariationId == $variant['id']) {
                        $this->activeTab = $paymentType;
                    }

                }
            } else {
                foreach ($this->variants as $key => $variant) {
                    $paymentType = 'onetime';
                    $type = Arr::get($variant, 'payment_type');
                    if ($type === 'subscription') {
                        $isInstallment = Arr::get($variant, 'other_info.installment');
                        if ($isInstallment === 'yes' && App::isProActive()) {
                            $paymentType = 'installment';
                        } else {
                            $paymentType = 'subscription';
                        }
                    }
                    $paymentTypes[] = $paymentType;

                    if (!isset($this->variantsByPaymentTypes[$paymentType])) {
                        $this->variantsByPaymentTypes[$paymentType] = [];
                    }

                    $this->variantsByPaymentTypes[$paymentType][] = $variant;

                    if ($this->defaultVariationId == $variant['id']) {
                        $this->activeTab = $paymentType;
                    }

                }
            }

            $paymentTypes = array_unique($paymentTypes);


            $intervalOptions = Helper::getAvailableSubscriptionIntervalOptions();
           
            $groupLanguageMap = [
                    'onetime'      => __('One Time', 'fluent-cart'),
                    'subscription' => __('Subscription', 'fluent-cart'),
                    'installment'  => __('Installment', 'fluent-cart'),
            ];

            foreach ($intervalOptions as $interval) {
                $groupLanguageMap[$interval['value']] = $interval['label'];
            }

            foreach ($paymentTypes as $paymentType) {
                $this->paymentTypes[$paymentType ?: 'onetime'] = Arr::get($groupLanguageMap, $paymentType ?: 'onetime');
            }
        }
    }

    public function render()
    {
        ?>
        <div class="fct-single-product-page" data-fluent-cart-single-product-page>
            <div class="fct-single-product-page-row">
                <?php $this->renderGallery(); ?>
                <div class="fct-product-summary">
                    <?php
                    $this->renderTitle();
                    $this->renderStockAvailability();
                    $this->renderExcerpt();
                    $this->renderPrices();
                    $this->renderBuySection();
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function renderBuySection($atts = [])
    {
        $otherInfo = (array)Arr::get($this->product->detail, 'other_info');
        $groupBy = Arr::get($otherInfo, 'group_pricing_by', 'repeat_interval'); //repeat_interval,payment_type,none

        echo '<div aria-labelledby="fct-product-summary-title" data-fluent-cart-product-pricing-section data-product-id="' . esc_attr($this->product->ID) . '" class="fct_buy_section">';

        if (count($this->paymentTypes) === 1 || $groupBy === 'none') {
            $this->renderVariants(Arr::get($atts, 'variation_atts', []));
        } else {
            $this->renderTab(Arr::get($atts, 'variation_atts', []));
        }

        $this->renderItemPrice();
        $this->renderQuantity();
        ?>
        <div class="fct-product-buttons-wrap">
            <?php $this->renderPurchaseButtons(Arr::get($atts, 'button_atts', [])); ?>
        </div>
        </div>
        <?php
    }

    public function renderGalleryThumb()
    {
        $thumbnails = [];

        $featuredMedia = $this->product->thumbnail ?? Vite::getAssetUrl('images/placeholder.svg');

        if (!$featuredMedia) {
            $featuredMedia = [];
        }

        $galleryImage = get_post_meta($this->product->ID, 'fluent-products-gallery-image', true);

        if (!empty($galleryImage)) {
            $thumbnails[0] = [
                    'media' => $galleryImage,
            ];
        }

        foreach ($this->variants as $variant) {
            if (!empty($variant['media']['meta_value'])) {
                $thumbnails[$variant['id']] = [
                        'media' => $variant['media']['meta_value'],
                ];
            } else {
                $this->defaultImageUrl = $featuredMedia;
                $this->defaultImageAlt = Arr::get($variant, 'variation_title', '');
            }
        }

        $images = empty($thumbnails) ? [] : $thumbnails;

        $this->images = $images;

        if (!empty($images)) {
            $variationId = $this->defaultVariationId;
            $imageId = $variationId;

            if (isset($images[$imageId])) {
                $imageMetaValue = $images[$imageId];
                $this->defaultImageUrl = Arr::get($imageMetaValue, 'media.0.url', '');
                $this->defaultImageAlt = Arr::get($imageMetaValue, 'media.0.title', '');
            }
        }

        ?>
        <div class="fct-product-gallery-thumb" role="region"
             aria-label="<?php echo esc_attr($this->product->post_title . ' gallery'); ?>">
            <img
                    src="<?php echo esc_url($this->defaultImageUrl ?? '') ?>"
                    alt="<?php echo esc_attr($this->defaultImageAlt) ?>"
                    data-fluent-cart-single-product-page-product-thumbnail
                    data-default-image-url="<?php echo esc_url($featuredMedia) ?>"
            />
        </div>
        <?php
    }

    public function renderGalleryThumbControls()
    {
        ?>

        <div class="fct-gallery-thumb-controls" data-fluent-cart-single-product-page-product-thumbnail-controls>

            <?php $this->renderGalleryThumbControl(); ?>

        </div>

        <?php

    }

    public function renderGalleryThumbControl()
    {
        foreach ($this->images as $imageId => $image) {
            if (empty($image['media']) || !is_array($image['media'])) {
                continue;
            }

            foreach ($image['media'] as $item) {
                if (empty(Arr::get($item, 'url', ''))) {
                    continue;
                }

                $this->renderGalleryThumbControlButton($item, $imageId);

            }

        }

    }

    public function renderGalleryThumbControlButton($item, $imageId)
    {

        $isHidden = ''; //$imageId != $this->defaultVariationId ? 'is-hidden' : '';
        $itemUrl = Arr::get($item, 'url', '');
        $itemTitle = Arr::get($item, 'title', '');
        $isSelected = $imageId == $this->defaultVariationId ? 'true' : 'false';
        ?>

        <button
                type="button"
                class="fct-gallery-thumb-control-button <?php echo esc_attr($isHidden); ?>"
                data-fluent-cart-thumb-control-button
                data-url="<?php echo esc_url($itemUrl); ?>"
                data-variation-id="<?php echo esc_attr($imageId); ?>"
                aria-label="<?php echo
                    /* translators: %s image title */
                esc_attr(sprintf(__('View %s image', 'fluent-cart'), $itemTitle));
                ?>"
                aria-pressed="<?php echo esc_attr($isSelected); ?>"
        >
            <img
                    class="fct-gallery-control-thumb"
                    data-fluent-cart-single-product-page-product-thumbnail-controls-thumb
                    src="<?php echo esc_url($itemUrl); ?>"
                    alt="<?php echo esc_attr($itemTitle); ?>"
            />
        </button>

        <?php


    }

    public function renderGallery($args = [])
    {

        $defaults = [
                'thumbnail_mode' => 'all', // horizontal, vertical
                'thumb_position' => 'bottom' // bottom, left, right, top
        ];

        $atts = wp_parse_args($args, $defaults);

        $thumbnailMode = $atts['thumbnail_mode'];

        $wrapperAtts = [
                'class'                                    => 'fct-product-gallery-wrapper ' . 'thumb-pos-' . $atts['thumb_position'] . ' thumb-mode-' . $thumbnailMode,
                'data-fct-product-gallery'                 => '',
                'data-fluent-cart-product-gallery-wrapper' => '',
                'data-thumbnail-mode'                      => $thumbnailMode,
                'data-product-id'                          => $this->product->ID,
        ];

        ?>

        <div <?php RenderHelper::renderAtts($wrapperAtts); ?>>
            <?php $this->renderGalleryThumb(); ?>
            <?php $this->renderGalleryThumbControls(); ?>
        </div>

        <?php
    }

    public function renderTitle()
    {
        ?>
        <div class="fct-product-title">
            <h1 id="fct-product-summary-title"><?php echo esc_html($this->product->post_title); ?></h1>
        </div>
        <?php
    }

    public function renderStockAvailability($wrapper_attributes = '')
    {
        if (!ModuleSettings::isActive('stock_management')) {
            return '';
        }

        $stockAvailability = $this->product->detail->getStockAvailability();

        if (!Arr::get($stockAvailability, 'manage_stock')) {
            return '';
        }

        $stockLabel = $stockAvailability['availability'];

        $hasInStock = $this->product->variants()
                ->where('stock_status', Helper::IN_STOCK)->exists();

        if (!$hasInStock) {
            $stockLabel = __('Out of stock', 'fluent-cart');
        }

        $statusClass = $stockAvailability['class'] ?? '';

        echo sprintf(
                '<div class="fct-product-stock %1$s" role="status" aria-live="polite">
                    <div %2$s>
                        <span class="fct-stock-status fct_status_badge_%1$s" data-fluent-cart-product-stock>
                            %3$s
                        </span>
                    </div>
                </div>',
                esc_attr($statusClass),
                $wrapper_attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                esc_html($stockLabel)
        );
    }

    public function renderExcerpt()
    {
        $excerpt = $this->product->post_excerpt;
        if (!$excerpt) {
            return;
        }
        ?>
        <div class="fct-product-excerpt" aria-labelledby="fct-product-summary-title">
            <p><?php echo wp_kses_post($excerpt); ?></p>
        </div>
        <?php

    }

    public function renderPrices()
    {
        if ($this->product->detail->variation_type === 'simple') {
            // we have to render for the simple product

            $first_price = $this->product->variants()->first();

            $itemPrice = $first_price ? $first_price->item_price : 0;
            $comparePrice = $first_price ? $first_price->compare_price : 0;
            if ($comparePrice <= $itemPrice) {
                $comparePrice = 0;
            }
            do_action('fluent_cart/product/single/before_price_block', [
                    'product'       => $this->product,
                    'current_price' => $itemPrice,
                    'scope'         => 'price_range'
            ]);
            ?>
            <?php

            if ($comparePrice) {
                $aria_label = sprintf(
                /* translators: 1: Original price, 2: Current item price */
                        __('Original Price: %1$s, Price: %2$s', 'fluent-cart'),
                        Helper::toDecimal($comparePrice),
                        Helper::toDecimal($itemPrice)
                );
            } else {
                $aria_label = sprintf(
                /* translators: 1: Current item price */
                        __('Price: %1$s', 'fluent-cart'),
                        Helper::toDecimal($itemPrice)
                );
            }

            ?>
            <div class="fct-price-range fct-product-prices" role="term"
                 aria-label="<?php echo esc_attr($aria_label); ?>">

                <?php if ($comparePrice): ?>
                    <span class="fct-compare-price">
                        <del aria-label="<?php echo esc_attr(__('Original price', 'fluent-cart')); ?>"><?php echo esc_html(Helper::toDecimal($comparePrice)); ?></del>
                    </span>
                <?php endif; ?>
                <span class="fct-item-price" aria-label="<?php echo esc_attr(__('Current price', 'fluent-cart')); ?>">
                    <?php echo esc_html(Helper::toDecimal($itemPrice)); ?>
                    <?php do_action('fluent_cart/product/after_price', [
                            'product'       => $this->product,
                            'current_price' => $itemPrice,
                            'scope'         => 'price_range'
                    ]); ?>
                </span>
            </div>
            <?php
            do_action('fluent_cart/product/single/after_price_block', [
                    'product'       => $this->product,
                    'current_price' => $itemPrice,
                    'scope'         => 'price_range'
            ]);
            return;
        }
        $min_price = $this->product->detail->min_price;
        $max_price = $this->product->detail->max_price;

        do_action('fluent_cart/product/single/before_price_range_block', [
                'product'       => $this->product,
                'current_price' => $min_price,
                'scope'         => 'price_range'
        ]);
        ?>
        <?php
        $aria_label = sprintf(
        /* translators: 1: Minimum price, 2: Maximum price */
                __('Price range: %1$s - %2$s', 'fluent-cart'),
                Helper::toDecimal($min_price),
                Helper::toDecimal($max_price)
        );
        ?>
        <div class="fct-product-prices fct-price-range" role="term" aria-label="<?php echo esc_attr($aria_label); ?>">

            <?php if ($max_price && $max_price != $min_price && $max_price > $min_price): ?>
                <span class="fct-min-price"><?php echo esc_html(Helper::toDecimal($min_price)); ?></span>
                <span class="fct-price-separator" aria-hidden="true">-</span>
            <?php endif; ?>
            <span class="fct-max-price">
                <?php echo esc_html(Helper::toDecimal($max_price)); ?>
            </span>

            <?php do_action('fluent_cart/product/after_price', [
                    'product'       => $this->product,
                    'current_price' => $min_price,
                    'scope'         => 'price_range'
            ]); ?>

        </div>
        <?php
        do_action('fluent_cart/product/single/after_price_range_block', [
                'product'       => $this->product,
                'current_price' => $min_price,
                'scope'         => 'price_range'
        ]);
    }

    public function renderVariants($atts = [])
    {
        if ($this->product->detail->variation_type === 'simple') {
            return;
        }

        $variants = $this->product->variants;
        if (!$variants || $variants->isEmpty()) {
            return;
        }

        // Sort by serial_index ascending
        $variants = $variants->sortBy('serial_index')->values();

        $classes = array_filter([
                'fct-product-variants',
                'column-type-' . $this->columnType,
                Arr::get($atts, 'wrapper_class', ''),
        ]);

        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>" role="radiogroup"
             aria-label="<?php esc_attr_e('Product Variants', 'fluent-cart'); ?>">
            <?php foreach ($variants as $variant) {
                do_action('fluent_cart/product/single/before_variant_item', [
                        'product' => $this->product,
                        'variant' => $variant,
                        'scope'   => 'product_variant_item'
                ]);
                $this->renderVariationItem($variant, $this->defaultVariationId);
                do_action('fluent_cart/product/single/after_variant_item', [
                        'product' => $this->product,
                        'variant' => $variant,
                        'scope'   => 'product_variant_item'
                ]);
            } ?>
        </div>
        <?php
    }

    public function renderItemPrice()
    {
        if ($this->product->detail->variation_type === 'simple' && !$this->hasSubscription) {
            return; // for simple product we already rendered the price
        }

        $defaultPaymentType = $this->defaultVariant ? Arr::get($this->defaultVariant->other_info, 'payment_type', 'onetime') : 'onetime';


        if ($defaultPaymentType !== 'subscription') {

        }
        do_action('fluent_cart/product/single/before_price_block', [
                'product'       => $this->product,
                'current_price' => $this->defaultVariant ? $this->defaultVariant->item_price : 0,
                'scope'         => 'product_variant_price'
        ]);
        ?>
        <?php if ($this->viewType !== 'text' || $this->columnType !== 'one'): ?>

        <?php
        foreach ($this->product->variants as $variant): ?>
            <div
                    class="fct-product-item-price fluent-cart-product-variation-content <?php echo $this->defaultVariant->id != $variant->id ? ' is-hidden' : '' ?>"
                    data-fluent-cart-product-item-price
                    data-variation-id="<?php echo esc_attr($variant->id); ?>"
            >

                <?php if ($this->defaultVariant && !$this->hasSubscription) {
                    if ($variant->compare_price): ?>
                        <span class="fct-compare-price">
                            <del><?php echo esc_html(Helper::toDecimal($variant->compare_price)); ?></del>
                        </span>
                    <?php endif;

                    echo wp_kses_post(apply_filters('fluent_cart/single_product/variation_price', esc_html(Helper::toDecimal($variant->item_price)), [
                            'product' => $this->product,
                            'variant' => $variant,
                            'scope'   => 'product_variant_price'
                    ]));
                    do_action('fluent_cart/product/after_price', [
                            'product'       => $this->product,
                            'current_price' => $variant->item_price,
                            'scope'         => 'product_variant_price'
                    ]);
                } ?>
            </div>
        <?php endforeach; ?>
    <?php
    endif; ?>
        <?php if ($this->hasSubscription && $this->viewType !== 'text' && $this->columnType !== 'one'): ?>

        <?php
        foreach ($this->product->variants as $variant): ?>
            <?php
            $paymentType = Arr::get($variant->other_info, 'payment_type', 'onetime');
            $atts = [
                    'class'                                 => 'fct-product-payment-type fluent-cart-product-variation-content ' . ($paymentType !== 'subscription' || $this->defaultVariant->id != $variant->id ? ' is-hidden' : ''),
                    'data-fluent-cart-product-payment-type' => '',
                    'data-variation-id'                     => $variant->id
            ];
            ?>
            <div <?php $this->renderAttributes($atts); ?>>
                <?php if ($variant->compare_price): ?>
                    <span class="fct-compare-price">
                        <del><?php echo esc_html(Helper::toDecimal($variant->compare_price)); ?></del>
                    </span>
                <?php endif; ?>
                <?php


                if ($paymentType === 'onetime') {
                    echo esc_html(Helper::toDecimal($variant->item_price));

                } else {
                    echo wp_kses_post(apply_filters('fluent_cart/single_product/variation_price', esc_html($variant->getSubscriptionTermsText(true)), [
                            'product' => $this->product,
                            'variant' => $variant,
                            'scope'   => 'product_variant_price'
                    ]));
                }

                ?>
            </div>
        <?php endforeach; ?>
    <?php endif;

        do_action('fluent_cart/product/single/after_price_block', [
                'product'       => $this->product,
                'current_price' => $this->defaultVariant ? $this->defaultVariant->item_price : 0,
                'scope'         => 'product_variant_price'
        ]);
    }

    public function renderQuantity()
    {
        $soldIndividually = $this->product->soldIndividually();

        if (!$this->hasOnetime || $soldIndividually) {
            return;
        }

        $attributes = [
                'data-fluent-cart-product-quantity-container' => '',
                'data-cart-id'                                => $this->defaultVariant ? $this->defaultVariant->id : '',
                'data-variation-type'                         => $this->product->detail->variation_type,
                'data-payment-type'                           => 'onetime',
                'class'                                       => 'fct-product-quantity-container'
        ];

        $defaultVariantData = $this->getDefaultVariantData();

        if ($this->hasSubscription && Arr::get($defaultVariantData, 'payment_type') !== 'onetime') {
            $attributes['class'] .= ' is-hidden';
        }

        do_action('fluent_cart/product/single/before_quantity_block', [
                'product' => $this->product,
                'scope'   => 'product_quantity_block'
        ]);
        ?>
        <div <?php $this->renderAttributes($attributes); ?>>
            <label for="fct-product-qty-input" class="quantity-title">
                <?php esc_html_e('Quantity', 'fluent-cart'); ?>
            </label>

            <div class="fct-product-quantity">
                <button class="fct-quantity-decrease-button"
                        data-fluent-cart-product-qty-decrease-button
                        title="<?php esc_html_e('Decrease Quantity', 'fluent-cart'); ?>"
                        aria-label="<?php esc_attr_e('Decrease Quantity', 'fluent-cart'); ?>"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="2" viewBox="0 0 14 2" fill="none">
                        <path d="M12.3333 1L1.66659 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                              stroke-linejoin="round"></path>
                    </svg>
                </button>

                <input
                        id="fct-product-qty-input"
                        min="1"
                        <?php echo $soldIndividually ? 'max="1"' : ''; ?>
                        class="fct-quantity-input"
                        data-fluent-cart-single-product-page-product-quantity-input
                        type="text"
                        placeholder="<?php esc_attr_e('Quantity', 'fluent-cart'); ?>"
                        value="1"
                        aria-label="<?php esc_attr_e('Product quantity', 'fluent-cart'); ?>"
                />

                <button class="fct-quantity-increase-button"
                        data-fluent-cart-product-qty-increase-button
                        title="<?php esc_attr_e('Increase Quantity', 'fluent-cart'); ?>"
                        aria-label="<?php esc_attr_e('Increase Quantity', 'fluent-cart'); ?>"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none">
                        <path d="M6.99996 1.66666L6.99996 12.3333M12.3333 6.99999L1.66663 6.99999" stroke="currentColor"
                              stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                    </svg>
                </button>
            </div>
        </div>
        <?php
        do_action('fluent_cart/product/single/after_quantity_block', [
                'product' => $this->product,
                'scope'   => 'product_quantity_block'
        ]);
    }

    public function renderPurchaseButtons($atts = [])
    {
        if (ModuleSettings::isActive('stock_management')) {
            if ($this->product->detail->variation_type === 'simple' && $this->defaultVariant) {
                if ($this->product->detail->manage_stock && $this->defaultVariant->stock_status !== Helper::IN_STOCK) {
                    echo '<span aria-disabled="true">' . esc_html__('Out of stock', 'fluent-cart') . '</span>';
                    return;
                }
            }
        }

        $defaults = [
                'buy_now_text'     => __('Buy Now', 'fluent-cart'),
                'add_to_cart_text' => __('Add To Cart', 'fluent-cart'),
        ];

        $atts = wp_parse_args($atts, $defaults);

        $buyNowAttributes = [
                'data-fluent-cart-direct-checkout-button' => '',
                'data-variation-type'                     => $this->product->detail->variation_type,
                'class'                                   => 'fluent-cart-direct-checkout-button',
                'data-stock-availability'                 => 'in-stock',
                'data-quantity'                           => '1',
                'href'                                    => site_url('?fluent-cart=instant_checkout&item_id=') . ($this->defaultVariant ? $this->defaultVariant->id : '') . '&quantity=1',
                'data-cart-id'                            => $this->defaultVariant ? $this->defaultVariant->id : '',
                'data-url'                                => site_url('?fluent-cart=instant_checkout&item_id='),
        ];

        $cartAttributes = [
                'data-fluent-cart-add-to-cart-button' => '',
                'data-cart-id'                        => $this->defaultVariant ? $this->defaultVariant->id : '',
                'data-product-id'                     => $this->product->ID,
                'class'                               => 'fluent-cart-add-to-cart-button ',
                'data-variation-type'                 => $this->product->detail->variation_type,
        ];

        $defaultVariantData = $this->getDefaultVariantData();

        if ($this->hasSubscription && Arr::get($defaultVariantData, 'payment_type') !== 'onetime') {
            $cartAttributes['class'] .= ' is-hidden';
        }

        $buyButtonText = apply_filters('fluent_cart/product/buy_now_button_text', $atts['buy_now_text'], [
                'product' => $this->product
        ]);

        $addToCartText = apply_filters('fluent_cart/product/add_to_cart_text', $atts['add_to_cart_text'], [
                'product' => $this->product
        ]);
        ?>
        <a <?php $this->renderAttributes($buyNowAttributes); ?> aria-label="<?php echo esc_attr($buyButtonText); ?>">
            <?php echo wp_kses_post($buyButtonText); ?>
        </a>
        <?php if ($this->hasOnetime): ?>
        <button <?php $this->renderAttributes($cartAttributes); ?> aria-label="<?php echo esc_attr($addToCartText); ?>">
            <span class="text">
                <?php echo wp_kses_post($addToCartText); ?>
            </span>
            <span class="fluent-cart-loader" role="status">
                    <svg aria-hidden="true"
                         width="20"
                         height="20"
                         class="w-5 h-5 text-gray-200 animate-spin fill-blue-600"
                         viewBox="0 0 100 101" fill="none" xmlns="http://www.w3.org/2000/svg">
                          <path
                                  d="M100 50.5908C100 78.2051 77.6142 100.591 50 100.591C22.3858 100.591 0 78.2051 0 50.5908C0 22.9766 22.3858 0.59082 50 0.59082C77.6142 0.59082 100 22.9766 100 50.5908ZM9.08144 50.5908C9.08144 73.1895 27.4013 91.5094 50 91.5094C72.5987 91.5094 90.9186 73.1895 90.9186 50.5908C90.9186 27.9921 72.5987 9.67226 50 9.67226C27.4013 9.67226 9.08144 27.9921 9.08144 50.5908Z"
                                  fill="currentColor"/>
                          <path
                                  d="M93.9676 39.0409C96.393 38.4038 97.8624 35.9116 97.0079 33.5539C95.2932 28.8227 92.871 24.3692 89.8167 20.348C85.8452 15.1192 80.8826 10.7238 75.2124 7.41289C69.5422 4.10194 63.2754 1.94025 56.7698 1.05124C51.7666 0.367541 46.6976 0.446843 41.7345 1.27873C39.2613 1.69328 37.813 4.19778 38.4501 6.62326C39.0873 9.04874 41.5694 10.4717 44.0505 10.1071C47.8511 9.54855 51.7191 9.52689 55.5402 10.0491C60.8642 10.7766 65.9928 12.5457 70.6331 15.2552C75.2735 17.9648 79.3347 21.5619 82.5849 25.841C84.9175 28.9121 86.7997 32.2913 88.1811 35.8758C89.083 38.2158 91.5421 39.6781 93.9676 39.0409Z"
                                  fill="currentFill"/>
                    </svg>
                </span>
        </button>
    <?php endif;
    }


    public static function renderNoProductFound()
    {
        ?>
        <div class="fluent-cart-shop-no-result-found" data-fluent-cart-shop-no-result-found role="status"
             aria-live="polite">
            <p class="has-text-align-center has-large-font-size m-0">
                <?php echo esc_html__('No Product Found!', 'fluent-cart'); ?>
            </p>

            <p class="has-text-align-center">
                <?php echo esc_html__('You can try clearing any filters.', 'fluent-cart'); ?>
            </p>
        </div>
        <?php
    }

    protected function renderVariationItem(ProductVariation $variant, $defaultId = '', $extraClasses = [])
    {
        $availableStocks = $variant->available;
        if (!$variant->manage_stock) {
            $availableStocks = 'unlimited';
        }

        $comparePrice = $variant->compare_price;
        if ($comparePrice <= $variant->item_price) {
            $comparePrice = '';
        }

        if ($comparePrice) {
            $comparePrice = Helper::toDecimal($comparePrice);
        }

        $paymentType = Arr::get($variant->other_info, 'payment_type');

        $itemClasses = [
                'fct-product-variant-item',
                'fct_price_type_' . $paymentType,
                'fct_variation_view_type_' . $this->viewType,
        ];

        if ($variant->media_id) {
            $itemClasses[] = 'fct-item-has-image';
        }

        if ($variant->id == $defaultId) {
            $itemClasses[] = 'selected';
        }

        $priceSuffix = apply_filters('fluent_cart/product/price_suffix_atts', '', [
                'product' => $this->product,
                'variant' => $variant,
                'scope'   => 'variant_item'
        ]);

        $renderingAttributes = [
                'data-fluent-cart-product-variant' => '',
                'data-cart-id'                     => $variant->id,
                'data-item-stock'                  => $variant->stock_status,
                'data-default-variation-id'        => $defaultId,
                'data-payment-type'                => $paymentType,
                'data-available-stock'             => $availableStocks,
                'data-item-price'                  => Helper::toDecimal($variant->item_price),
                'data-compare-price'               => $comparePrice,
                'data-price-suffix'                => $priceSuffix,
                'data-stock-management'            => ModuleSettings::isActive('stock_management') ? 'yes' : 'no',
        ];

        if ($paymentType === 'subscription') {
            $renderingAttributes['data-subscription-terms'] = $variant->getSubscriptionTermsText(true);
            $repeatInterval = Arr::get($variant->other_info, 'repeat_interval', '');
            $hasInstallment = Arr::get($variant->other_info, 'has_installment') === 'yes';

            $itemClasses[] = 'fct_sub_interval_' . $repeatInterval;
            if ($hasInstallment) {
                $itemClasses[] = 'fct_sub_has_installment';
            }
        }

        if ($extraClasses) {
            $itemClasses = array_merge($itemClasses, $extraClasses);
        }

        $itemClasses = array_filter($itemClasses);
        $renderingAttributes['class'] = implode(' ', $itemClasses);

        $itemPrice = $variant->item_price;
        $comparePrice = $variant->compare_price;
        if (!$comparePrice || $comparePrice <= $itemPrice) {
            $comparePrice = 0;
        }

        ?>
        <div
                <?php $this->renderAttributes($renderingAttributes); ?>
                role="radio"
                tabindex="0"
                aria-checked="<?php echo $variant->id == $defaultId ? 'true' : 'false'; ?>"
                aria-label="<?php echo esc_attr($variant->variation_title); ?>"
        >
            <?php if ($this->viewType === 'image'): ?>
                <?php $this->renderTooltip($variant); ?>
            <?php endif; ?>

            <div class="variant-content">
                <?php
                if ($this->viewType === 'both' || $this->viewType === 'image') {
                    $this->renderVariantImage($variant);
                }
                ?>
                <?php
                if ($this->viewType === 'both' || $this->viewType === 'text') {
                    echo '<div class="fct-product-variant-title" aria-label="' . esc_attr(__('Variant title', 'fluent-cart')) . '">' . esc_html($variant->variation_title) . '</div>';
                }
                ?>
            </div>

            <?php if ($this->viewType === 'text' && $paymentType === 'subscription' && $this->columnType === 'one'): ?>
                <?php $this->renderSubscriptionInfo($variant); ?>
            <?php endif; ?>

            <?php if ($this->viewType === 'text' && $this->columnType === 'one'): ?>
                <div class="fct-product-variant-price">
                    <?php if ($comparePrice): ?>
                        <div class="fct-product-variant-compare-price">
                            <del aria-label="<?php echo esc_attr(__('Original price', 'fluent-cart')); ?>">
                                <span><?php echo esc_html(Helper::toDecimal($comparePrice)); ?></span></del>
                        </div>
                    <?php endif; ?>
                    <div class="fct-product-variant-item-price"
                         aria-label="<?php echo esc_attr(__('Current price', 'fluent-cart')); ?>">
                        <span><?php echo esc_html(Helper::toDecimal($itemPrice)); ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    protected function renderTooltip($variant)
    {
        ?>
        <div class="fct-product-variant-tooltip" role="tooltip" id="tooltip-<?php echo esc_attr($variant->id); ?>">
            <?php echo esc_html($variant->variation_title); ?>
        </div>
        <?php
    }

    protected function renderVariantImage($variant)
    {
        $image = $variant->thumbnail;
        if (!$image) {
            $image = Vite::getAssetUrl('images/placeholder.svg');
        }
        ?>
        <div class="fct-product-variant-image">
            <img role="img" alt="<?php echo esc_attr($variant->variation_title); ?>"
                 src="<?php echo esc_url($image); ?>"/>
        </div>
        <?php
    }

    protected function renderSubscriptionInfo($variant)
    {
        $info = $variant->getSubscriptionTermsText(true);

        if (!$info) {
            return '';
        }

        ?>
        <div class="fct-product-variant-payment-type" aria-live="polite">
            <div class="additional-info">
                <span><?php echo esc_html($info); ?></span>
            </div>
        </div>
        <?php
    }

    protected function renderAttributes($atts = [])
    {
        foreach ($atts as $attr => $value) {
            if ($value !== '') {
                echo esc_attr($attr) . '="' . esc_attr((string)$value) . '" ';
            } else {
                echo esc_attr($attr) . ' ';
            }
        }
    }

    protected function renderTab($atts = [])
    {
        ?>
        <div class="fct-product-tab" data-fluent-cart-product-tab>
            <?php $this->renderTabNav(); ?>

            <div class="fct-product-tab-content" data-tab-contents>
                <?php $this->renderTabPane($atts); ?>
            </div>
        </div>
        <?php

    }

    protected function renderTabNav()
    {
        ?>

        <div class="fct-product-tab-nav" role="tablist">
            <div class="tab-active-bar" data-tab-active-bar></div>
            <?php
            foreach ($this->paymentTypes as $typeKey => $typeLabel) : ?>
                <div
                        class="fct-product-tab-nav-item <?php echo esc_attr($this->activeTab === $typeKey ? 'active' : ''); ?>"
                        data-tab="<?php echo esc_attr($typeKey); ?>"
                        role="tab"
                        tabindex="0"
                        aria-selected="<?php echo $this->activeTab === $typeKey ? 'true' : 'false'; ?>"
                        aria-controls="<?php echo esc_attr($typeKey); ?>"
                >
                    <?php echo esc_html($typeLabel); ?>
                </div>
            <?php endforeach;
            ?>
        </div>

        <?php
    }

    protected function renderTabPane($atts = [])
    {
        $variantsClasses = [
                'fct-product-variants',
                'column-type-' . $this->columnType,
                Arr::get($atts, 'wrapper_class', ''),
        ];

        foreach ($this->variantsByPaymentTypes as $variantKey => $variants): ?>
            <div
                    data-tab-content
                    id="<?php echo esc_attr($variantKey); ?>"
                    class="fct-product-tab-pane <?php echo esc_attr($this->activeTab === $variantKey ? 'active' : ''); ?>"
                    role="tabpanel"
                    aria-labelledby="<?php echo esc_attr($variantKey); ?>"
            >
                <div class="<?php echo esc_attr(implode(' ', $variantsClasses)); ?>">
                    <?php
                    //Convert to collection safely before sorting
                    $variants = (new Collection($variants))->sortBy('serial_index')->values();

                    foreach ($variants as $variant) {
                        do_action('fluent_cart/product/single/before_variant_item', [
                                'product' => $this->product,
                                'variant' => $variant,
                                'scope'   => 'product_variant_item'
                        ]);

                        $this->renderVariationItem($variant, $this->defaultVariationId);

                        do_action('fluent_cart/product/single/after_variant_item', [
                                'product' => $this->product,
                                'variant' => $variant,
                                'scope'   => 'product_variant_item'
                        ]);
                    }
                    ?>
                </div>

            </div>
        <?php endforeach; ?>

        <?php
    }

    protected function getDefaultVariantData()
    {
        if (empty($this->variants) || !$this->defaultVariationId) {
            return null;
        }

        foreach ($this->variants as $variant) {
            if ($variant['id'] == $this->defaultVariationId) {
                return $variant;
            }
        }

        return null;
    }
}
