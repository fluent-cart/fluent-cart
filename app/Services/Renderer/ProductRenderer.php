<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\App\App;
use FluentCart\App\Services\FrontendView;
use FluentCart\App\Vite;
use FluentCart\Api\StoreSettings;
use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Http\Routes\WebRoutes;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Support\Collection;
use FluentCart\App\Modules\Templating\AssetLoader;

class ProductRenderer
{
    protected $product;

    protected $variants;

    protected $storeSettings;

    protected $defaultVariant = null;

    protected $hasOnetime = false;

    protected $hasSubscription = false;

    protected $viewType = '';

    protected $columnType = '';

    protected $defaultVariationId = '';

    protected $defaultGalleryImageId = 0;

    protected $galleryActiveSet = false;

    protected $paymentTypes = [];

    protected $variantsByPaymentTypes = [];

    protected $activeTab = 'onetime';

    protected $images = [];

    protected $variantTermMap = [];

    protected $defaultImageUrl = null;

    protected $defaultImageAlt = null;

    public function __construct(Product $product, $config = [])
    {

        $this->product = $product;
        $this->variants = $product->variants;

        $this->storeSettings = new StoreSettings();
        $this->viewType = $this->storeSettings->get('variation_view', 'both');
        $this->columnType = $this->storeSettings->get('variation_columns', 'masonry');

        $defaultVariationId = $config['default_variation_id'] ?? '';

        // 'image', 'text','both'
        $this->viewType = apply_filters('fluent_cart/single_product/variation_view_type', $this->viewType, [
                'product'            => $product,
                'variants'           => $this->variants,
                'defaultVariationId' => $defaultVariationId,
        ]);

        // 'one', 'two','three', 'four', 'masonry'
        $this->columnType = apply_filters('fluent_cart/single_product/variation_column_type', $this->columnType, [
                'product'            => $product,
                'variants'           => $this->variants,
                'defaultVariationId' => $defaultVariationId,
        ]);


        $hasExplicitDefault = true;

        if (!$defaultVariationId) {
            $variationIds = $product->variants->pluck('id')->toArray();
            $defaultVariationId = $product->detail->default_variation_id;

            // For advanced variations the storefront selector only exposes ACTIVE
            // variants (AdvancedVariationRenderer skips item_status != active), so
            // resolve the default against the same set. default_variation_id is
            // maintained stock/status-agnostically, so it can legitimately point
            // at an inactive variant — accepting it here would render the inactive
            // default's price/stock/button while the selector omits that id and
            // falls back to a different active variant. Scoped to advanced so
            // simple / simple_variations keep their existing default handling.
            $isAdvanced = $product->detail
                && $product->detail->variation_type === Helper::PRODUCT_TYPE_ADVANCE_VARIATION;
            $eligibleVariants = $isAdvanced
                ? $product->variants->where('item_status', 'active')
                : $product->variants;
            $eligibleIds = $eligibleVariants->pluck('id')->toArray();

            if (!$defaultVariationId || !in_array($defaultVariationId, $eligibleIds)) {
                // No valid stored default — fall back to the FIRST eligible
                // combination by serial_index (active-only for advanced), not the
                // first by DB/id order, so the server-rendered price/stock/button
                // match what the storefront selector highlights. Stock is ignored
                // — first by order wins. A NULL serial_index (legacy/malformed row
                // the merchant never ordered) is pushed AFTER numbered variants so
                // it can't become the default ahead of an explicitly ordered one —
                // PHP's default null-first sort would otherwise promote it, and the
                // frontend mirrors this by treating null serial as last too.
                $firstBySerial = $eligibleVariants
                    ->sortBy(function ($variant) {
                        return is_null($variant->serial_index)
                            ? PHP_INT_MAX
                            : (int) $variant->serial_index;
                    })
                    ->first();
                $defaultVariationId = $firstBySerial ? (int) $firstBySerial->id : Arr::get($variationIds, '0');
                $hasExplicitDefault = false;
            }
        }

        // Always set resolved default variation id
        $this->defaultVariationId = $defaultVariationId;

        // Gallery defaults to featured image (key 0) when no explicit default variation is set
        $this->defaultGalleryImageId = $hasExplicitDefault ? $defaultVariationId : 0;


        $this->product->variants->load('bundleChildren.product');



        foreach ($this->product->variants as $variant) {
            if ($variant->id == $this->defaultVariationId) {
                $this->defaultVariant = $variant;
            }
            // Read the authoritative payment_type column, not other_info. The
            // ProductVariation accessor only injects payment_type into other_info
            // for subscriptions, so one-time variants (notably advanced-variation
            // combinations generated by Pro) leave other_info['payment_type']
            // unset. Anything that is not a subscription is a one-time path.
            if ($variant->payment_type === 'subscription') {
                $this->hasSubscription = true;
            } else {
                $this->hasOnetime = true;
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
        <div class="fct-single-product-page" data-fluent-cart-single-product-page data-product-id="<?php echo esc_attr($this->product->ID); ?>">
            <div class="fct-single-product-page-row">
                <?php $this->renderGallery(); ?>
                <div class="fct-product-summary">
                    <?php
                    $this->renderTitle();
                    $this->renderProductMeta();
                    $this->renderExcerpt();
                    $this->renderPrices();

                    if ($this->product->detail->variation_type === 'simple' && !$this->hasSubscription) {
                        foreach ($this->product->variants as $variant) {
                            $this->renderVariationsBundleProduct($variant);
                        }
                    }

                    $this->renderPackageDescription();
                    $this->renderBuySection();
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function renderProductMeta() {
        ?>
            <div class="fct-product-meta">
                <?php $this->renderStockAvailability(); ?>
                <?php $this->renderSku(); ?>
            </div>

        <?php
    }

    public function renderBuySectionWrapperStart()
    {
        ?>
        <div aria-labelledby="fct-product-summary-title" data-fluent-cart-product-pricing-section data-product-id="<?php echo esc_attr($this->product->ID); ?>" class="fct_buy_section">
        <?php
    }

    public function renderBuySectionWrapperEnd()
    {
        ?>
        </div>
        <?php
    }

    public function renderBuySection($atts = [])
    {
        $gateContext = RenderGate::context($this->product, RenderGate::SCOPE_SINGLE, $this->defaultVariant);

        // The buy section is the root the variation-selector JS binds to
        // (data-fluent-cart-product-pricing-section). Gating it removes the
        // variation picker along with the buttons, which is what a full catalog
        // mode wants; to keep shoppers able to browse options while hiding only
        // the purchase affordances, gate 'actions' instead.
        if (!RenderGate::shouldRender('buy_section', $gateContext)) {
            return;
        }

        // Render no buy section when there is nothing purchasable — avoids a
        // broken quantity + "Not Available" block. Two cases:
        //  - no variants at all (any product type), or
        //  - an advanced-variation product with no attribute_config yet (e.g.
        //    switched on before options were configured). Its variants are kept
        //    until generation, so we key on the config, not the variant count,
        //    to hide it until real combinations exist.
        $isUnconfiguredAdvanced = $this->product->detail->variation_type === Helper::PRODUCT_TYPE_ADVANCE_VARIATION
            && empty(Arr::get((array) $this->product->detail->other_info, 'attribute_config'));

        if ($this->product->variants->isEmpty() || $isUnconfiguredAdvanced) {
            return;
        }

        $otherInfo = (array)Arr::get($this->product->detail, 'other_info');
        $groupBy = Arr::get($otherInfo, 'group_pricing_by', 'repeat_interval'); //repeat_interval,payment_type,none

        $this->renderBuySectionWrapperStart();

        $this->renderVariationDisplay($atts);

        $this->renderItemPrice();

        $this->renderQuantity();
        ?>
        <div class="fct-product-buttons-wrap">
            <?php $this->renderPurchaseButtons(Arr::get($atts, 'button_atts', [])); ?>
        </div>
        <?php
        $this->renderBuySectionWrapperEnd();
    }

    public function renderVariationDisplay($atts = [])
    {
        // Hand off rendering to the advanced-variation selector when the
        // product is configured for advanced variations. The handler returns
        // the filtered array with rendered=true after emitting its markup; if
        // no listener handles it (e.g. an unconfigured advanced product), the
        // filter is a no-op and we fall through to the simple-variation
        // rendering below.
        if ($this->product->detail->variation_type === Helper::PRODUCT_TYPE_ADVANCE_VARIATION) {
            $result = apply_filters('fluent_cart/product/render_advanced_variation', [
                'product'        => $this->product,
                'selector_style' => Arr::get($atts, 'selector_style', 'auto'),
                'rendered'       => false,
            ]);
            if (!empty($result['rendered'])) {
                return;
            }
        }

        $otherInfo = (array)Arr::get($this->product->detail, 'other_info');
        $groupBy = Arr::get($otherInfo, 'group_pricing_by', 'repeat_interval'); //repeat_interval,payment_type,none

        if (count($this->paymentTypes) === 1 || $groupBy === 'none') {
            $this->renderVariants(Arr::get($atts, 'variation_atts', []));
        } else {
            $this->renderTab(Arr::get($atts, 'variation_atts', []));
        }
    }

    public function renderGalleryThumb()
    {
        $thumbnails = [];

        $featuredMedia = $this->product->thumbnail ?? Vite::getAssetUrl('images/placeholder.svg');

        // thumbnail can be an empty string (not null), so ?? above doesn't catch
        // it — fall back to the placeholder so $featuredMedia is always a usable
        // image URL for both the main <img> and data-default-image-url.
        if (!$featuredMedia || !\is_string($featuredMedia)) {
            $featuredMedia = Vite::getAssetUrl('images/placeholder.svg');
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
            $imageId = $this->defaultGalleryImageId;

            if (isset($images[$imageId])) {
                $imageMetaValue = $images[$imageId];
                $this->defaultImageUrl = Arr::get($imageMetaValue, 'media.0.url', '');
                $this->defaultImageAlt = Arr::get($imageMetaValue, 'media.0.title', '');
            } else {
                // Fallback to the first available thumbnail. Advanced-variation
                // products often have per-variant images but no explicit
                // default_variation_id — the thumbnails are keyed by real
                // variant IDs while defaultGalleryImageId is 0, so the lookup
                // above misses and the main image area renders blank with a
                // broken-image icon.
                $firstImage = reset($images);
                $fallbackUrl = Arr::get($firstImage, 'media.0.url', '');
                $this->defaultImageUrl = $fallbackUrl ?: ($featuredMedia ?: '');
                $this->defaultImageAlt = $this->defaultImageAlt ?: Arr::get($firstImage, 'media.0.title', '');
            }
        }

        // Nothing set a main image — e.g. a product with no variants, or no
        // variant/gallery media. Fall back to the featured/placeholder image so
        // the main area shows the placeholder instead of a broken <img src="">.
        if (empty($this->defaultImageUrl)) {
            $this->defaultImageUrl = $featuredMedia;
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

    public function renderGalleryThumbControls($maxThumbnails = null)
    {
        $totalThumbImages = Arr::pluck($this->images, 'media.*.url');

        if(count($totalThumbImages) == 1 && is_countable($totalThumbImages[0]) && count($totalThumbImages[0]) == 1){

            return '';
        }

        // Build variantTermMap and variantFirstMediaMap.
        // For advanced variations, Pro builds both maps via the gallery_variation_data
        // filter — it has access to AttributeGroup types (color/image) that free cannot
        // query directly. For all other product types, free builds variantFirstMediaMap
        // from the already-loaded $this->images; variantTermMap stays empty.
        $this->variantTermMap = [];
        $variantFirstMediaMap = [];

        if ($this->product->detail->variation_type === Helper::PRODUCT_TYPE_ADVANCE_VARIATION) {
            $galleryVariationData = apply_filters('fluent_cart/product/gallery_variation_data', [
                'variant_term_map'        => [],
                'variant_first_media_map' => [],
            ], $this->product);
            $this->variantTermMap = (array) Arr::get($galleryVariationData, 'variant_term_map', []);
            $variantFirstMediaMap = (array) Arr::get($galleryVariationData, 'variant_first_media_map', []);
        } else {
            foreach ($this->images as $imageId => $image) {
                if (empty($image['media']) || !is_array($image['media'])) {
                    continue;
                }
                $firstItem = $image['media'][0] ?? null;
                if (!$firstItem) {
                    continue;
                }
                $firstUrl     = Arr::get($firstItem, 'url', '');
                $firstMediaId = (int) Arr::get($firstItem, 'id', 0);
                if ($firstUrl) {
                    $variantFirstMediaMap[(int) $imageId] = [
                        'id'  => $firstMediaId,
                        'url' => $firstUrl,
                    ];
                }
            }
        }

        // Collect ALL gallery images as JSON for lightbox (even when max thumbnails limits visible thumbs).
        // For advanced variations, deduplicate by media ID (when > 0) or by URL (for imported images).
        $allGalleryImages = [];
        $addedMediaKeys   = [];
        $isAdvVariation   = !empty($this->variantTermMap);
        foreach ($this->images as $imageId => $image) {
            if (empty($image['media']) || !is_array($image['media'])) {
                continue;
            }
            foreach ($image['media'] as $item) {
                $url     = Arr::get($item, 'url', '');
                $mediaId = (int) Arr::get($item, 'id', 0);
                if (empty($url)) {
                    continue;
                }
                if ($isAdvVariation) {
                    $dedupeKey = $mediaId > 0 ? 'i:' . $mediaId : 'u:' . $url;
                    if (isset($addedMediaKeys[$dedupeKey])) {
                        continue; // skip — already added this image
                    }
                    $addedMediaKeys[$dedupeKey] = true;
                }
                $allGalleryImages[] = [
                    'url'          => $url,
                    'title'        => Arr::get($item, 'title', ''),
                    'variation_id' => (string) $imageId,
                    'term_id'      => (int) ($this->variantTermMap[(int) $imageId] ?? 0),
                    'media_id'     => $mediaId,
                ];
            }
        }

        ?>

        <div class="fct-gallery-thumb-controls"
             role="toolbar"
             aria-label="<?php echo esc_attr__('Product image thumbnails', 'fluent-cart'); ?>"
             data-fluent-cart-single-product-page-product-thumbnail-controls
             data-all-gallery-images="<?php echo esc_attr(wp_json_encode($allGalleryImages) ?: '[]'); ?>"
             data-variant-first-media-map="<?php echo esc_attr(wp_json_encode($variantFirstMediaMap) ?: '{}'); ?>">

            <?php $this->renderGalleryThumbControl($maxThumbnails); ?>

        </div>

        <?php

    }

    public function renderGalleryThumbControl($maxThumbnails = null)
    {
        if ($maxThumbnails !== null && $maxThumbnails <= 0) {
            $maxThumbnails = null; // treat invalid value as "no limit"
        }

        $isAdvVariation    = !empty($this->variantTermMap);
        $countedMediaKeys  = [];
        $count             = 0;
        $totalImages       = 0;

        // Count unique images to render. For advanced variations, deduplicate by WP media ID
        // when available, or by URL for externally imported images (media ID = 0).
        foreach ($this->images as $imageId => $image) {
            if (empty($image['media']) || !is_array($image['media'])) {
                continue;
            }
            foreach ($image['media'] as $item) {
                $url     = Arr::get($item, 'url', '');
                $mediaId = (int) Arr::get($item, 'id', 0);
                if (empty($url)) {
                    continue;
                }
                if ($isAdvVariation) {
                    $mediaDedupeKey = $mediaId > 0 ? 'i:' . $mediaId : 'u:' . $url;
                    if (isset($countedMediaKeys[$mediaDedupeKey])) {
                        continue;
                    }
                    $countedMediaKeys[$mediaDedupeKey] = true;
                }
                $totalImages++;
            }
        }

        $renderedMediaKeys = [];
        // Render up to max; skip already-rendered media for advanced variation products.
        foreach ($this->images as $imageId => $image) {
            if (empty($image['media']) || !is_array($image['media'])) {
                continue;
            }
            foreach ($image['media'] as $item) {
                $url     = Arr::get($item, 'url', '');
                $mediaId = (int) Arr::get($item, 'id', 0);
                if (empty($url)) {
                    continue;
                }
                if ($isAdvVariation) {
                    $mediaDedupeKey = $mediaId > 0 ? 'i:' . $mediaId : 'u:' . $url;
                    if (isset($renderedMediaKeys[$mediaDedupeKey])) {
                        continue;
                    }
                    $renderedMediaKeys[$mediaDedupeKey] = true;
                }
                if ($maxThumbnails !== null && $count >= (int) $maxThumbnails) {
                    $this->renderGallerySeeMoreButton($totalImages - (int) $maxThumbnails);
                    return;
                }
                $termId = (int) ($this->variantTermMap[(int) $imageId] ?? 0);
                $this->renderGalleryThumbControlButton($item, $imageId, $termId, $mediaId);
                $count++;
            }
        }
    }

    public function renderGallerySeeMoreButton($remainingCount)
    {
        ?>
        <button
            type="button"
            class="fct-gallery-see-more-button"
            data-fluent-cart-gallery-see-more
            aria-label="<?php echo esc_attr(
                sprintf(
                    /* translators: %d number of remaining images */
                    __('View all %d more images', 'fluent-cart'),
                    $remainingCount
                )
            ); ?>"
        >
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M2.58078 19.0103L2.56078 19.0303C2.29078 18.4403 2.12078 17.7703 2.05078 17.0303C2.12078 17.7603 2.31078 18.4203 2.58078 19.0103Z" fill="#9D9FAC"/>
                <path d="M8.99914 10.3801C10.3136 10.3801 11.3791 9.31456 11.3791 8.00012C11.3791 6.68568 10.3136 5.62012 8.99914 5.62012C7.6847 5.62012 6.61914 6.68568 6.61914 8.00012C6.61914 9.31456 7.6847 10.3801 8.99914 10.3801Z" fill="#9D9FAC"/>
                <path d="M16.19 2H7.81C4.17 2 2 4.17 2 7.81V16.19C2 17.28 2.19 18.23 2.56 19.03C3.42 20.93 5.26 22 7.81 22H16.19C19.83 22 22 19.83 22 16.19V13.9V7.81C22 4.17 19.83 2 16.19 2ZM20.37 12.5C19.59 11.83 18.33 11.83 17.55 12.5L13.39 16.07C12.61 16.74 11.35 16.74 10.57 16.07L10.23 15.79C9.52 15.17 8.39 15.11 7.59 15.65L3.85 18.16C3.63 17.6 3.5 16.95 3.5 16.19V7.81C3.5 4.99 4.99 3.5 7.81 3.5H16.19C19.01 3.5 20.5 4.99 20.5 7.81V12.61L20.37 12.5Z" fill="#9D9FAC"/>
                <script xmlns=""/></svg>

            <span class="fct-see-more-text">
                <?php echo esc_html__('See', 'fluent-cart'); ?>
                <span class="fct-see-more-count"><?php echo esc_html($remainingCount); ?></span>
                <?php echo esc_html__('More', 'fluent-cart'); ?>
            </span>
        </button>
        <?php
    }

    public function renderGalleryThumbControlButton($item, $imageId, $termId = 0, $mediaId = 0)
    {

        $isHidden = ''; //$imageId != $this->defaultVariationId ? 'is-hidden' : '';
        $itemUrl = Arr::get($item, 'url', '');
        $itemTitle = Arr::get($item, 'title', '');
        $isSelected = !$this->galleryActiveSet && $imageId == $this->defaultGalleryImageId;
        if ($isSelected) {
            $this->galleryActiveSet = true;
        }
        ?>

        <button
                type="button"
                class="fct-gallery-thumb-control-button <?php echo $isSelected ? 'active' : ''; ?> <?php echo esc_attr($isHidden); ?>"
                data-fluent-cart-thumb-control-button
                data-url="<?php echo esc_url($itemUrl); ?>"
                data-variation-id="<?php echo esc_attr($imageId); ?>"
                data-term-id="<?php echo esc_attr((string) $termId); ?>"
                data-media-id="<?php echo esc_attr((string) $mediaId); ?>"
                aria-label="<?php echo
                    /* translators: %1$s: image title */
                esc_attr(sprintf(__('View %1$s image', 'fluent-cart'), $itemTitle));
                ?>"
                aria-pressed="<?php echo $isSelected ? 'true' : 'false'; ?>"
                tabindex="<?php echo $isSelected ? '0' : '-1'; ?>"
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
        $gateContext = RenderGate::context($this->product, RenderGate::SCOPE_SINGLE, $this->defaultVariant);

        if (!RenderGate::shouldRender('image', $gateContext)) {
            return;
        }

        $defaults = [
                'thumbnail_mode'    => 'all', // horizontal, vertical
                'thumb_position'    => 'bottom', // bottom, left, right, top
                'scrollable_thumbs' => 'no', // yes / no
                'max_thumbnails'    => null, // null = no limit, integer = max visible
        ];

        $atts = wp_parse_args($args, $defaults);

        $thumbnailMode = $atts['thumbnail_mode'];

        $wrapperAtts = [
                'class'                                    => 'fct-product-gallery-wrapper ' . 'thumb-pos-' . $atts['thumb_position'] . ' thumb-mode-' . $thumbnailMode,
                'data-fct-product-gallery'                 => '',
                'data-fluent-cart-product-gallery-wrapper' => '',
                'data-thumbnail-mode'                      => $thumbnailMode,
                'data-product-id'                          => $this->product->ID,
                'data-scrollable-thumbs'                   => $atts['scrollable_thumbs'],
        ];

        ?>

        <div <?php RenderHelper::renderAtts($wrapperAtts); ?>>

            <?php
                $this->renderGalleryThumb();
                $this->renderGalleryThumbControls($atts['max_thumbnails']);
            ?>
        </div>

        <?php
    }

    public function renderTitle()
    {
        $gateContext = RenderGate::context($this->product, RenderGate::SCOPE_SINGLE, $this->defaultVariant);

        if (!RenderGate::shouldRender('title', $gateContext)) {
            return;
        }

        do_action('fluent_cart/product/single/before_title_block', $gateContext);
        ?>
        <div class="fct-product-title">
            <h1 id="fct-product-summary-title"><?php echo esc_html($this->product->post_title); ?></h1>
        </div>
        <?php
        do_action('fluent_cart/product/single/after_title_block', $gateContext);
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

        $isStock = $this->product->isStock();

        // Check default variant stock for both simple and variable products
        if ($this->defaultVariant) {
            $isStock = $isStock && $this->defaultVariant->isStock();
        }

        $stockLabel = Arr::get($stockAvailability, 'availability');
        $statusClass = $stockAvailability['class'] ?? '';

        // Optional per-status custom labels (e.g. set on the Bricks Product Stock
        // element via the fluent_cart/product_stock_availability filter). Emitted as
        // data-attributes so the frontend JS, which re-derives the badge text on load
        // and on variant switches, prefers them over the generic label map instead of
        // overwriting them. Absent for the default template, so behavior is unchanged.
        $inStockText = Arr::get($stockAvailability, 'in_stock_text');
        $outOfStockText = Arr::get($stockAvailability, 'out_of_stock_text');

        // The variant-level check above can override the aggregate stock_availability
        // used for $stockLabel/$statusClass (e.g. this specific default variant is out
        // of stock even though the product overall has other in-stock variants) — keep
        // the label and class in sync so the badge never shows mismatched text/color.
        // Honor the custom out-of-stock label here too so it survives this override on
        // first load, matching what the frontend JS shows after a variant switch.
        if (!$isStock) {
            $statusClass = 'out-of-stock';
            $stockLabel = !empty($outOfStockText) ? $outOfStockText : __('Out of Stock', 'fluent-cart');
        }

        $badgeAttributes = '';
        if (!empty($inStockText)) {
            $badgeAttributes .= sprintf(' data-in-stock-text="%s"', esc_attr($inStockText));
        }
        if (!empty($outOfStockText)) {
            $badgeAttributes .= sprintf(' data-out-of-stock-text="%s"', esc_attr($outOfStockText));
        }

        echo sprintf(
                '<div class="fct-product-stock %1$s" role="status" aria-live="polite">
                    <div %2$s>
                        <span class="fct-stock-label">%3$s</span>
                        <span class="fct-stock-badge fct_status_badge_%1$s" data-fluent-cart-product-stock%5$s>
                            %4$s
                        </span>
                    </div>
                </div>',
                esc_attr($statusClass),
                $wrapper_attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                esc_html__('Availability:', 'fluent-cart'),
                esc_html($stockLabel),
                $badgeAttributes // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values passed through esc_attr() above
        );
    }

    public function renderSku($wrapper_attributes = '', $showLabel = true, $label = '', $variant = null)
    {
        if (!$label) {
            $label = __('SKU:', 'fluent-cart');
        }

        $labelHtml = '';
        if ($showLabel && $label) {
            $labelHtml = sprintf('<span class="fct-product-sku__label">%s</span> ', esc_html($label));
        }

        if ($variant) {
            if (empty($variant->sku)) {
                return;
            }
            echo sprintf(
                '<div class="fct-product-sku">
                    <div %s>
                        %s<span class="fct-product-sku__value" data-fluent-cart-product-sku>%s</span>
                    </div>
                </div>',
                $wrapper_attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                $labelHtml,
                esc_html($variant->sku)
            );
            return;
        }

        foreach ($this->product->variants as $v) {
            if (empty($v->sku)) {
                continue;
            }
            $isHidden = ($this->defaultVariant && $this->defaultVariant->id != $v->id) ? ' is-hidden' : '';
            echo sprintf(
                '<div class="fct-product-sku fluent-cart-product-variation-content%s" data-variation-id="%s">
                    <div %s>
                        %s<span class="fct-product-sku__value" data-fluent-cart-product-sku>%s</span>
                    </div>
                </div>',
                $isHidden,
                esc_attr($v->id),
                $wrapper_attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                $labelHtml,
                esc_html($v->sku)
            );
        }
    }

    /**
     * @deprecated Use PackageDescriptionRenderer::renderPackageDescription() directly.
     * Kept as a compatibility shim for external callers (themes, extensions) —
     * package-description rendering now lives in PackageDescriptionRenderer.
     */
    public function renderPackageDescription($wrapper_attributes = '', $showName = true, $showDimensions = true, $showProductWeight = true, $showTotalWeight = true, $variant = null)
    {
        (new PackageDescriptionRenderer($this->product))->renderPackageDescription(
            $wrapper_attributes,
            $showName,
            $showDimensions,
            $showProductWeight,
            $showTotalWeight,
            $variant,
            $this->defaultVariant
        );
    }

    /**
     * Build a JSON string of package info for a variant (used as data attribute for JS switching).
     */
    private function getVariantPackageInfoJson(ProductVariation $variant)
    {
        if ($variant->fulfillment_type !== 'physical') {
            return '';
        }

        $otherInfo = $variant->other_info ?: [];
        $packageSlug = Arr::get($otherInfo, 'package_slug', '');
        $package = Helper::getPackageBySlug($packageSlug);

        if (!$package) {
            return '';
        }

        static $storeWeightUnit = null;

        if ($storeWeightUnit === null) {
            $storeWeightUnit = Helper::shopConfig('weight_unit') ?: 'kg';
        }

        // Format dimensions
        $length = Arr::get($package, 'length', '');
        $width = Arr::get($package, 'width', '');
        $height = Arr::get($package, 'height', '');
        $dimensionUnit = Arr::get($package, 'dimension_unit', 'cm');
        $dimensionParts = array_filter([$length, $width, $height], function ($val) {
            return $val !== '' && $val !== null && $val != 0;
        });
        $formattedDimensions = $dimensionParts
            ? implode(' × ', $dimensionParts) . ' ' . $dimensionUnit
            : '';

        // Calculate weights
        $productWeight = floatval(Arr::get($otherInfo, 'weight', 0));
        $productWeightUnit = Arr::get($otherInfo, 'weight_unit', $storeWeightUnit);
        $convertedProductWeight = Helper::convertWeight($productWeight, $productWeightUnit, $storeWeightUnit);

        $packageWeight = floatval(Arr::get($package, 'weight', 0));
        $packageWeightUnit = Arr::get($package, 'weight_unit', $storeWeightUnit);
        $convertedPackageWeight = Helper::convertWeight($packageWeight, $packageWeightUnit, $storeWeightUnit);
        $totalWeight = $convertedProductWeight + $convertedPackageWeight;

        // Format weights
        $formattedProductWeight = $convertedProductWeight
            ? rtrim(rtrim(number_format($convertedProductWeight, 2), '0'), '.') . ' ' . $storeWeightUnit
            : '';

        $formattedShippingWeight = ($totalWeight && $convertedPackageWeight)
            ? rtrim(rtrim(number_format($totalWeight, 2), '0'), '.') . ' ' . $storeWeightUnit
            : '';

        return wp_json_encode([
            'name'            => Arr::get($package, 'name', ''),
            'dimensions'      => $formattedDimensions,
            'product_weight'  => $formattedProductWeight,
            'shipping_weight' => $formattedShippingWeight,
        ]);
    }

    public function renderExcerpt()
    {
        $excerpt = $this->product->post_excerpt;
        if (!$excerpt) {
            return;
        }

        $gateContext = RenderGate::context($this->product, RenderGate::SCOPE_SINGLE, $this->defaultVariant);

        if (!RenderGate::shouldRender('excerpt', $gateContext)) {
            return;
        }

        do_action('fluent_cart/product/single/before_excerpt_block', $gateContext);
        ?>
        <div class="fct-product-excerpt" aria-labelledby="fct-product-summary-title">
            <p><?php echo wp_kses_post($excerpt); ?></p>
        </div>
        <?php
        do_action('fluent_cart/product/single/after_excerpt_block', $gateContext);
    }

    public function renderDescription()
    {
        $productPost = get_post($this->product->ID);
        if (!$productPost || empty($productPost->post_content)) {
            return;
        }

        global $post;
        $originalPost = $post;
        $post = $productPost;
        setup_postdata($post);

        $content = apply_filters('the_content', $productPost->post_content);

        $post = $originalPost;
        if ($originalPost) {
            setup_postdata($originalPost);
        } else {
            wp_reset_postdata();
        }
        ?>
        <div class="fct-product-description">
            <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php
    }

    public function renderPrices()
    {
        $gateContext = RenderGate::context($this->product, RenderGate::SCOPE_SINGLE, $this->defaultVariant);

        if (!RenderGate::shouldRender('price', $gateContext)) {
            return;
        }

        if ($this->product->detail->variation_type === 'simple') {
            // we have to render for the simple product

            $first_price = $this->product->variants()->first();

            $itemPrice = $first_price ? $first_price->item_price : 0;
            $itemPrice = apply_filters('fluent_cart/product/display_price', $itemPrice, [
                'product'   => $this->product,
                'variation' => $first_price,
            ]);
            $itemPrice = (int)$itemPrice;
            $comparePrice = $first_price ? (int)$first_price->compare_price : 0;
            if ($comparePrice <= $itemPrice) {
                $comparePrice = 0;
            }
            do_action('fluent_cart/product/single/before_price_block', RenderContext::decorate([
                    'product'       => $this->product,
                    'current_price' => $itemPrice,
                    'scope'         => 'price_range'
            ]));
            ?>
            <div class="fct-price-range fct-product-prices">

                <?php if ($comparePrice): ?>
                    <span class="fct-compare-price">
                        <span class="fct-sr-only"><?php echo esc_html__('Original price:', 'fluent-cart'); ?></span>
                        <del><?php echo esc_html(Helper::toDecimal($comparePrice)); ?></del>
                    </span>
                <?php endif; ?>
                <span class="fct-item-price">
                    <span class="fct-sr-only"><?php echo $comparePrice ? esc_html__('Sale price:', 'fluent-cart') : esc_html__('Price:', 'fluent-cart'); ?></span>
                    <?php echo esc_html(Helper::toDecimal($itemPrice)); ?>
                    <?php do_action('fluent_cart/product/after_price', RenderContext::decorate([
                            'product'       => $this->product,
                            'current_price' => $itemPrice,
                            'scope'         => 'price_range'
                    ])); ?>
                </span>
            </div>
            <?php
            do_action('fluent_cart/product/single/after_price_block', RenderContext::decorate([
                    'product'       => $this->product,
                    'current_price' => $itemPrice,
                    'scope'         => 'price_range'
            ]));
            return;
        }
        $min_price = $this->product->detail->min_price;
        $max_price = $this->product->detail->max_price;

        do_action('fluent_cart/product/single/before_price_range_block', RenderContext::decorate([
                'product'       => $this->product,
                'current_price' => $min_price,
                'scope'         => 'price_range'
        ]));
        ?>
        <div class="fct-product-prices fct-price-range">

            <?php if ($max_price && $max_price != $min_price && $max_price > $min_price): ?>
                <span class="fct-sr-only"><?php echo esc_html__('Price range:', 'fluent-cart'); ?></span>
                <span class="fct-min-price"><?php echo esc_html(Helper::toDecimal($min_price)); ?></span>
                <span class="fct-price-separator" aria-hidden="true">-</span>
                <span class="fct-sr-only"><?php echo esc_html__('to', 'fluent-cart'); ?></span>
            <?php endif; ?>
            <span class="fct-max-price">
                <?php echo esc_html(Helper::toDecimal($max_price)); ?>
            </span>

            <?php do_action('fluent_cart/product/after_price', RenderContext::decorate([
                    'product'       => $this->product,
                    'current_price' => $min_price,
                    'scope'         => 'price_range'
            ])); ?>

        </div>
        <?php
        do_action('fluent_cart/product/single/after_price_range_block', RenderContext::decorate([
                'product'       => $this->product,
                'current_price' => $min_price,
                'scope'         => 'price_range'
        ]));
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
                do_action('fluent_cart/product/single/before_variant_item', RenderContext::decorate([
                        'product' => $this->product,
                        'variant' => $variant,
                        'scope'   => 'product_variant_item'
                ]));
                $this->renderVariationItem($variant, $this->defaultVariationId);
                do_action('fluent_cart/product/single/after_variant_item', RenderContext::decorate([
                        'product' => $this->product,
                        'variant' => $variant,
                        'scope'   => 'product_variant_item'
                ]));
            } ?>
        </div>
        <?php
    }

    public function renderItemPrice()
    {
        // Same 'price' gate as renderPrices(): one filter hides the price
        // wherever it appears, rather than making callers hunt for the second
        // place the single product page prints one.
        if (!RenderGate::shouldRender('price', RenderGate::context($this->product, RenderGate::SCOPE_SINGLE, $this->defaultVariant))) {
            return;
        }

        if ($this->product->detail->variation_type === 'simple' && !$this->hasSubscription) {
            return; // for simple product we already rendered the price
        }

        do_action('fluent_cart/product/single/before_price_block', RenderContext::decorate([
                'product'       => $this->product,
                'current_price' => $this->defaultVariant ? $this->defaultVariant->item_price : 0,
                'scope'         => 'product_variant_price'
        ]));

        foreach ($this->product->variants as $variant) {
            if ($this->shouldRenderPriceInPriceSection()) {
                $this->renderVariantPricingWrapperStart($variant);
                $paymentType = Arr::get($variant->other_info, 'payment_type', 'onetime');
                if (!$this->hasSubscription) {
                    $this->renderVariationComparePrice($variant);
                    $this->applyVariationPriceFilter($variant, $paymentType);
                } else {

                    $atts = [
                            'class'                                 => 'fct-product-payment-type fluent-cart-product-variation-content' . ($this->defaultVariant->id != $variant->id ? ' is-hidden' : ''),
                            'data-fluent-cart-product-payment-type' => '',
                            'data-variation-id'                     => $variant->id
                    ];

                    $this->renderComparePriceWrapperStart($atts);
                    $this->renderVariationComparePrice($variant);
                    $this->applyVariationPriceFilter($variant, $paymentType);
                    $this->renderComparePriceWrapperEnd();
                }

                $this->renderVariantPricingWrapperEnd();
            }

        }


        foreach ($this->product->variants as $variant) {
            $this->renderVariationsBundleProduct($variant);
        }




        do_action('fluent_cart/product/single/after_price_block', RenderContext::decorate([
                'product'       => $this->product,
                'current_price' => $this->defaultVariant ? $this->defaultVariant->item_price : 0,
                'scope'         => 'product_variant_price'
        ]));
    }

    public function shouldRenderPriceInPriceSection(): bool
    {
        // simple_variations keeps the legacy inline price flow for the
        // one-column layouts that render the variant title (text-only and
        // image+text), where the price sits on each variant row.
        // Every other variation type always renders the price in the dedicated
        // below-block so the data-fluent-cart-product-item-price element keeps
        // a consistent position across every view/column combination.
        if ($this->product->detail->variation_type === Helper::PRODUCT_TYPE_SIMPLE_VARIATION) {
            $isInlineRowLayout = in_array($this->viewType, ['text', 'both'], true)
                && $this->columnType === 'one';

            return !$isInlineRowLayout;
        }

        return true;
    }

    public function applyVariationPriceFilter($variant, $paymentType = 'onetime')
    {
        $priceText = $paymentType === 'onetime' ? Helper::toDecimal($variant->item_price) : $variant->getSubscriptionTermsText(true);
        echo wp_kses_post(apply_filters('fluent_cart/single_product/variation_price', esc_html($priceText), [
                'product' => $this->product,
                'variant' => $variant,
                'scope'   => 'product_variant_price'
        ]));
        do_action('fluent_cart/product/after_price', RenderContext::decorate([
                'product'       => $this->product,
                'variant'       => $variant,
                'current_price' => $variant->item_price,
                'scope'         => 'product_variant_price'
        ]));
        RenderHelper::renderPriceSuffix($this->product, $variant, 'product_variant_price');
    }

    public function renderComparePriceWrapperStart($atts = [])
    {
        ?>
        <div <?php $this->renderAttributes($atts); ?> >
        <?php
    }

    public function renderComparePriceWrapperEnd()
    {
        ?>
        </div>
        <?php
    }

    public function renderVariationComparePrice($variant)
    {
        if (!$variant->compare_price) {
            return;
        } ?>

        <span class="fct-compare-price">
            <span class="fct-sr-only"><?php echo esc_html__('Original price:', 'fluent-cart'); ?></span>
            <del><?php echo esc_html(Helper::toDecimal($variant->compare_price)); ?></del>
        </span>
        <?php
    }

    public function renderVariantPricingWrapperStart($variant)
    { ?>
        <div
        class="fct-product-item-price fluent-cart-product-variation-content <?php echo esc_attr($this->defaultVariant->id != $variant->id ? ' is-hidden' : ''); ?>"
        data-fluent-cart-product-item-price
        data-variation-id="<?php echo esc_attr($variant->id); ?>"
        aria-live="polite"
        role="status"
        >
    <?php }


    public function renderVariantPricingWrapperEnd()
    {
        ?> </div> <?php
    }

    public function renderVariationsBundleProduct($variant)
    {
        if (count($variant->bundleChildren) == 0) {
            return;
        }

        if(is_object($variant->bundleChildren)) {
            $bundleProducts = $variant->bundleChildren->toArray();
        }else{
            $bundleProducts = $variant->bundleChildren;
        }

        $total = count($bundleProducts);
        ?>
        <div class="fluent-cart-product-variation-content fct-bundle-products <?php echo esc_attr($this->defaultVariant->id != $variant->id ? ' is-hidden' : ''); ?>"
             data-variation-id="<?php echo esc_attr($variant->id); ?>"
             data-fluent-cart-collapsibles
        >
            <h4 class="fct-bundle-products-title">
                <?php echo esc_html__('Bundle of', 'fluent-cart') . ':'; ?>
            </h4>

            <div class="fct-bundle-products-list">
                <?php foreach (array_slice($bundleProducts, 0, 2) as $product): ?>
                    <p>
                        <?php echo esc_html(Arr::get($product, 'product.post_title')); ?> -
                        <?php echo esc_html($product['variation_title']); ?>
                    </p>
                <?php endforeach; ?>

                <?php if($total > 2): ?>
                    <div class="fct-bundle-products-more">
                        <div class="fct-bundle-products-more-list">
                            <?php foreach (array_slice($bundleProducts, 2) as $product): ?>
                                <p>
                                    <?php echo esc_html(Arr::get($product, 'product.post_title')); ?> -
                                    <?php echo esc_html($product['variation_title']); ?>
                                </p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif;?>
            </div>

            <?php if ($total > 2) : ?>
                <button type="button" class="fct-see-more-btn" data-fluent-cart-collapsible-toggle>
                    <span class="see-more-text">
                        <?php echo esc_html__('See More', 'fluent-cart'); ?>
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="7" viewBox="0 0 12 7" fill="none">
                            <path d="M0.75 0.75L5.04289 5.04289C5.37623 5.37623 5.54289 5.54289 5.75 5.54289C5.95711 5.54289 6.12377 5.37623 6.45711 5.04289L10.75 0.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="see-less-text">
                        <?php echo esc_html__('See Less', 'fluent-cart'); ?>
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="7" viewBox="0 0 14 8" fill="none">
                            <path d="M0.75 6.54297L6.04289 1.25008C6.37623 0.916742 6.54289 0.750076 6.75 0.750076C6.95711 0.750076 7.12377 0.916742 7.45711 1.25008L12.75 6.54297" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                </button>
            <?php endif; ?>
        </div>
    <?php }

    public function renderQuantity()
    {
        $gateContext = RenderGate::context($this->product, RenderGate::SCOPE_SINGLE, $this->defaultVariant);

        if (!RenderGate::shouldRender('quantity', $gateContext)) {
            return;
        }

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

        do_action('fluent_cart/product/single/before_quantity_block', RenderContext::decorate([
                'product' => $this->product,
                'scope'   => 'product_quantity_block'
        ]));
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
                        type="number"
                        inputmode="numeric"
                        pattern="[0-9]*"
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
        do_action('fluent_cart/product/single/after_quantity_block', RenderContext::decorate([
                'product' => $this->product,
                'scope'   => 'product_quantity_block'
        ]));
    }

    public function renderPurchaseButtons($atts = [])
    {
        $gateContext = RenderGate::context($this->product, RenderGate::SCOPE_SINGLE, $this->defaultVariant);

        if (!RenderGate::shouldRender('actions', $gateContext)) {
            return;
        }

        do_action('fluent_cart/product/single/before_actions_block', $gateContext);

        $buyNowButtonAtts = $atts;
        $this->renderBuyNowButton($buyNowButtonAtts);
        $this->renderAddToCartButton($atts);

        do_action('fluent_cart/product/single/after_actions_block', $gateContext);
    }

    public function renderBuyNowButton($atts = [])
    {
        $gateContext = RenderGate::context($this->product, RenderGate::SCOPE_SINGLE, $this->defaultVariant);

        if (!RenderGate::shouldRenderPurchaseButton('buy_now_button', $gateContext)) {
            return;
        }

        // Stock management check using isStock() method
//        if (ModuleSettings::isActive('stock_management')) {
//            if ($this->product->detail->variation_type === 'simple' && $this->defaultVariant) {
//                if (!$this->defaultVariant->isStock()) {
//                    echo '<span aria-disabled="true">' . esc_html__('Out of stock', 'fluent-cart') . '</span>';
//                    return;
//                }
//            }
//        }

        $defaults = [
                'buy_now_text'     => __('Buy Now', 'fluent-cart'),
                'add_to_cart_text' => __('Add To Cart', 'fluent-cart'),
        ];

        $atts = wp_parse_args($atts, $defaults);

        $enableModalCheckout = Helper::isModalCheckoutEnabled();

        $isInStock = true;
        if (ModuleSettings::isActive('stock_management')) {
            $isInStock = $this->product->isStock() && ($this->defaultVariant && $this->defaultVariant->isStock());
        }

        $stockStatus = $isInStock ? 'in-stock' : 'out-of-stock';

        $variationClass = 'fluent-cart-direct-checkout-button';
        if (!$isInStock) {
            $variationClass .= ' is-hidden';
        }

        $buyNowAttributes = [
                'data-fluent-cart-direct-checkout-button' => '',
                'data-variation-type'                     => $this->product->detail->variation_type,
                'class'                                   => $variationClass,
                'data-stock-availability'                 => $stockStatus,
                'data-quantity'                           => '1',
                'data-cart-id'                            => $this->defaultVariant ? $this->defaultVariant->id : '',
                'data-url'                                => site_url('?fluent-cart=instant_checkout&item_id='),
        ];

        if ($isInStock) {
            $buyNowAttributes['href'] = site_url('?fluent-cart=instant_checkout&item_id=') . ($this->defaultVariant ? $this->defaultVariant->id : '') . '&quantity=1';
        }

        if ($enableModalCheckout) {
            $buyNowAttributes['data-fct-instant-checkout-button'] = '';
            $buyNowAttributes['data-enable-modal-checkout'] = 'yes';
        }

        $isShortcode = !empty($atts['is_shortcode']);
        if ($isShortcode) {
            ob_start();
            $this->renderAttributes($buyNowAttributes);
            $wrapperAttributes = ob_get_clean();
        } else {
            $wrapperAttributes = RenderHelper::getBlockWrapperAttributes($buyNowAttributes);
        }

        $buyButtonText = apply_filters('fluent_cart/product/buy_now_button_text', $atts['buy_now_text'], [
                'product' => $this->product
        ]);

        ?>
        <?php
        $variantTitle = $this->defaultVariant ? $this->defaultVariant->variation_title : '';
        $buyNowAriaLabel = $variantTitle
            ? sprintf(
                /* translators: 1: Button text (e.g. "Buy Now"), 2: Variant name */
                __('%1$s - %2$s', 'fluent-cart'),
                $buyButtonText,
                $variantTitle
            )
            : $buyButtonText;
        ?>
        <a <?php echo $wrapperAttributes; ?> aria-label="<?php echo esc_attr($buyNowAriaLabel); ?>">
            <?php echo wp_kses_post($buyButtonText); ?>
        </a>
        <?php
    }

    public function renderBuyNowButtonBlock($atts = [])
    {
        $gateContext = RenderGate::context($this->product, RenderGate::SCOPE_SINGLE, $this->defaultVariant);

        // Same gate as the in-section button: a page assembled from standalone
        // button blocks must honour catalog mode too, or the gate leaks.
        if (!RenderGate::shouldRenderPurchaseButton('buy_now_button', $gateContext)) {
            return;
        }

        $text = Arr::get($atts, 'text', __('Buy Now', 'fluent-cart'));
        $variantIds = Arr::get($atts, 'variant_ids', []);
        $variantId  = Arr::get($variantIds, 0);
        $customClass = trim(Arr::get($atts, 'class', ''));
        $extraClass = trim(Arr::get($atts, 'extra_class', ''));

        $defaults = [
                'buy_now_text' => $text,
                'target'       => '',
                'rel'          => '',
                'is_shortcode' => false,
        ];

        $atts = wp_parse_args($atts, $defaults);

        $enableModalCheckout = Arr::get($atts, 'enable_modal_checkout', false);

        $isInStock = true;
        if (ModuleSettings::isActive('stock_management')) {
            $isInStock = $this->product->isStock() && ($this->defaultVariant && $this->defaultVariant->isStock());
        }
        $stockStatus = $isInStock ? 'in-stock' : 'out-of-stock';

        $checkoutUrl = add_query_arg([
                'fluent-cart' => $enableModalCheckout ? 'modal_checkout' : 'instant_checkout',
                'item_id'     => $variantId ?? '',
                'quantity'    => 1
        ], site_url());

        $buyNowClass = $customClass ?: 'wp-block-button__link wp-element-button';
        if ($extraClass) {
            $buyNowClass .= ' ' . $extraClass;
        }
        $buyNowClass = trim($buyNowClass);
        if ($stockStatus === 'out-of-stock') {
            $buyNowClass .= ' out-of-stock';
        }

        $buyNowAttributes = [
                'data-fluent-cart-direct-checkout-button' => '',
                'data-variation-type'                     => $this->product->detail->variation_type,
                'class'                                   => $buyNowClass,
                'data-stock-availability'                 => $stockStatus,
                'data-quantity'                           => '1',
                'data-cart-id'                            => $variantId ?? '',
                'data-url'                                => $checkoutUrl,
        ];

        if ($stockStatus === 'out-of-stock') {
            $buyNowAttributes['aria-disabled'] = 'true';
        } else {
            $buyNowAttributes['href'] = $checkoutUrl;
        }

        $target = Arr::get($atts, 'target');
        if ($target) {
            $buyNowAttributes['target'] = $target;
            if (strtolower($target) === '_blank') {
                $buyNowAttributes['rel'] = Arr::get($atts, 'rel', 'noopener noreferrer');
            }
        }
        if ($enableModalCheckout) {
            $buyNowAttributes['data-fct-instant-checkout-button'] = '';
            $buyNowAttributes['data-enable-modal-checkout'] = 'yes';
        }
        $isShortcode = !empty($atts['is_shortcode']);
        if ($isShortcode) {
            ob_start();
            $this->renderAttributes($buyNowAttributes);
            $wrapperAttributes = ob_get_clean();
        } else {
            $wrapperAttributes = RenderHelper::getBlockWrapperAttributes($buyNowAttributes);
        }

        $buyButtonText = apply_filters('fluent_cart/product/buy_now_button_text', $atts['buy_now_text'], [
                'product' => $this->product
        ]);
        ?>
        <a <?php echo($wrapperAttributes); ?> aria-label="<?php echo esc_attr($buyButtonText); ?>">
            <?php echo wp_kses_post($buyButtonText); ?>
        </a>
        <?php
    }

    public function renderAddToCartButton($atts = [])
    {
        $gateContext = RenderGate::context($this->product, RenderGate::SCOPE_SINGLE, $this->defaultVariant);

        if (!RenderGate::shouldRenderPurchaseButton('add_to_cart_button', $gateContext)) {
            return;
        }

        $defaults = [
                'buy_now_text'     => __('Buy Now', 'fluent-cart'),
                'add_to_cart_text' => __('Add To Cart', 'fluent-cart'),
        ];

        $atts = wp_parse_args($atts, $defaults);

        $cartAttributes = [
                'data-fluent-cart-add-to-cart-button' => '',
                'data-cart-id'                        => $this->defaultVariant ? $this->defaultVariant->id : '',
                'data-product-id'                     => $this->product->ID,
                'class'                               => 'fluent-cart-add-to-cart-button',
                'data-variation-type'                 => $this->product->detail->variation_type,
                'data-icon-only'                      => !empty($atts['is_icon_only']) ? 'true' : 'false',
        ];

        $defaultVariantData = $this->getDefaultVariantData();

        // If product is subscription-only, hide add-to-cart
        if ($this->hasSubscription && Arr::get($defaultVariantData, 'payment_type') !== 'onetime') {
            $cartAttributes['class'] .= ' is-hidden';
        }

        // Check stock availability using both product-level and variant-level
        $isOutOfStock = false;
        if (ModuleSettings::isActive('stock_management')) {
            if (!$this->product->isStock() || ($this->defaultVariant && !$this->defaultVariant->isStock())) {
                $isOutOfStock = true;
                $cartAttributes['disabled'] = 'disabled';
                $cartAttributes['class'] .= ' out-of-stock';
                $cartAttributes['aria-disabled'] = 'true';
                $atts['add_to_cart_text'] = __('Not Available', 'fluent-cart');
            }
        }

        $addToCartText = apply_filters('fluent_cart/product/add_to_cart_text', $atts['add_to_cart_text'], [
                'product' => $this->product
        ]);

        $isShortcode = !empty($atts['is_shortcode']);
        if ($isShortcode) {
            ob_start();
            $this->renderAttributes($cartAttributes);
            $wrapperAttributes = ob_get_clean();
        } else {
            $wrapperAttributes = RenderHelper::getBlockWrapperAttributes($cartAttributes);
        }


        // Render add to cart when the product supports a one-time path, when out
        // of stock (to show "Not Available"), or for advanced-variation products.
        // The advanced selector toggles this button's visibility / disabled /
        // payment-type state per selection, so it must exist in the DOM even for
        // a subscription-only product whose in-stock default starts hidden via
        // the is-hidden class applied above — otherwise an out-of-stock
        // subscription combination has no button to surface "Not Available".
        $isAdvancedVariation = $this->product->detail->variation_type === Helper::PRODUCT_TYPE_ADVANCE_VARIATION;
        if ($this->hasOnetime || $isOutOfStock || $isAdvancedVariation) :
            ?>
            <?php
            $variantTitle = $this->defaultVariant ? $this->defaultVariant->variation_title : '';
            $addToCartAriaLabel = $variantTitle
                ? sprintf(
                    /* translators: 1: Button text (e.g. "Add To Cart"), 2: Variant name */
                    __('%1$s - %2$s', 'fluent-cart'),
                    $addToCartText,
                    $variantTitle
                )
                : $addToCartText;
            ?>
            <button <?php echo $wrapperAttributes; ?>
                    aria-label="<?php echo esc_attr($addToCartAriaLabel); ?>">
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
        <?php
        endif;
    }

    public function renderAddToCartButtonBlock($atts = [])
    {
        $gateContext = RenderGate::context($this->product, RenderGate::SCOPE_SINGLE, $this->defaultVariant);

        // Same gate as the in-section button — see renderBuyNowButtonBlock().
        if (!RenderGate::shouldRenderPurchaseButton('add_to_cart_button', $gateContext)) {
            return;
        }

        $text = Arr::get($atts, 'text', __('Add To Cart', 'fluent-cart'));
        $customClass = trim(Arr::get($atts, 'class', ''));
        $extraClass = trim(Arr::get($atts, 'extra_class', ''));

        $defaults = [
                'add_to_cart_text' => $text,
        ];

        $atts = wp_parse_args($atts, $defaults);

        $buttonClasses = ['fct-loader', 'wp-block-button__link wp-element-button'];
        $buttonClass = $customClass ?: implode(' ', $buttonClasses);

        $cartAttributes = [
                'data-fluent-cart-add-to-cart-button'  => '',
                'class'                               => $buttonClass,
                'data-cart-id'                        => $this->defaultVariant ? $this->defaultVariant->id : '',
                'data-product-id'                     => $this->product->ID,
                'data-variation-type'                 => $this->product->detail->variation_type,
                'data-icon-only'                      => !empty($atts['is_icon_only']) ? 'true' : 'false',
        ];

        if ($extraClass) {
            $cartAttributes['class'] .= ' ' . $extraClass;
        }

        // If the product does NOT support one-time purchase
        if (!$this->hasOnetime) {
            if (Helper::isAdminUser()) {
                $view = '<p class="fct-admin-notice">' . esc_html__('Add to Cart is not supported for subscription product', 'fluent-cart') . '</p>';

                FrontendView::make('', $view);
                return;
            }

            return;
        }

        // Check stock availability using both product-level and variant-level
        if (ModuleSettings::isActive('stock_management')) {
            if (!$this->product->isStock() || ($this->defaultVariant && !$this->defaultVariant->isStock())) {
                $cartAttributes['disabled'] = 'disabled';
                $cartAttributes['class'] .= ' out-of-stock';
                $cartAttributes['aria-disabled'] = 'true';
                $atts['add_to_cart_text'] = __('Not Available', 'fluent-cart');
            }
        }

        $addToCartText = apply_filters('fluent_cart/product/add_to_cart_text', $atts['add_to_cart_text'], [
                'product' => $this->product
        ]);

        $isShortcode = !empty($atts['is_shortcode']);
        if ($isShortcode) {
            ob_start();
            $this->renderAttributes($cartAttributes);
            $wrapperAttributes = ob_get_clean();
        } else {
            $wrapperAttributes = RenderHelper::getBlockWrapperAttributes($cartAttributes);
        }

        ?>

            <button <?php echo $wrapperAttributes; ?>
                    aria-label="<?php echo esc_attr($addToCartText); ?>">
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

        <?php
    }

    public static function renderNoProductFound()
    {
        ?>
        <div class="fluent-cart-shop-no-result-found" data-fluent-cart-shop-no-result-found role="status"
             aria-live="polite">
            <p class="has-text-align-center has-large-font-size m-0">
                <?php echo esc_html__('No Product Found!', 'fluent-cart'); ?>
            </p>

            <p class="has-text-align-center m-0">
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

        $renderingAttributes = [
                'data-fluent-cart-product-variant' => '',
                'data-cart-id'                     => $variant->id,
                'data-item-stock'                  => $variant->isStock() ? 'in-stock' : 'out-of-stock',
                'data-default-variation-id'        => $defaultId,
                'data-payment-type'                => $paymentType,
                'data-available-stock'             => $availableStocks,
                'data-item-price'                  => Helper::toDecimal($variant->item_price),
                'data-compare-price'               => $comparePrice,
                'data-stock-management'            => ModuleSettings::isActive('stock_management') ? 'yes' : 'no',
                'data-sku'                         => $variant->sku ?? '',
                'data-package-info'                => $this->getVariantPackageInfoJson($variant),
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
                tabindex="<?php echo $variant->id == $defaultId ? '0' : '-1'; ?>"
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
                <?php if ($this->viewType === 'both' || $this->viewType === 'text'): ?>
                    <div class="fct-product-variant-text">
                        <div class="fct-product-variant-title"><?php echo esc_html($variant->variation_title); ?></div>
                        <?php if (!$this->shouldRenderPriceInPriceSection() && $paymentType === 'subscription'): ?>
                            <?php $this->renderSubscriptionInfo($variant); ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$this->shouldRenderPriceInPriceSection()): ?>
                <div class="fct-product-variant-price">
                    <?php if ($comparePrice): ?>
                        <div class="fct-product-variant-compare-price">
                            <span class="fct-sr-only"><?php echo esc_html__('Original price:', 'fluent-cart'); ?></span>
                            <del>
                                <span><?php echo esc_html(Helper::toDecimal($comparePrice)); ?></span></del>
                        </div>
                    <?php endif; ?>
                    <div class="fct-product-variant-item-price">
                        <span class="fct-sr-only"><?php echo $comparePrice ? esc_html__('Sale price:', 'fluent-cart') : esc_html__('Price:', 'fluent-cart'); ?></span>
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

    public function renderVariantImage($variant)
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

    protected function renderSubscriptionInfo($variant = null)
    {

        if(!$variant){
            return '';
        }
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
                        tabindex="<?php echo $this->activeTab === $typeKey ? '0' : '-1'; ?>"
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
                <div class="<?php echo esc_attr(implode(' ', $variantsClasses)); ?>" role="radiogroup"
                     aria-label="<?php esc_attr_e('Product Variants', 'fluent-cart'); ?>">
                    <?php
                    //Convert to collection safely before sorting
                    $variants = (new Collection($variants))->sortBy('serial_index')->values();

                    foreach ($variants as $variant) {
                        do_action('fluent_cart/product/single/before_variant_item', RenderContext::decorate([
                                'product' => $this->product,
                                'variant' => $variant,
                                'scope'   => 'product_variant_item'
                        ]));

                        $this->renderVariationItem($variant, $this->defaultVariationId);

                        do_action('fluent_cart/product/single/after_variant_item', RenderContext::decorate([
                                'product' => $this->product,
                                'variant' => $variant,
                                'scope'   => 'product_variant_item'
                        ]));
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
