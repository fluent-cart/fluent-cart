<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class ProductCardRender
{
    protected $product;

    protected $viewUrl = '';

    protected $config = [];

    public function __construct(Product $product, $config = [])
    {
        $this->product = $product;
        $this->viewUrl = $product->view_url;
        $this->config = $config;
    }

    public function renderWrapperStart()
    {

    }

    public function renderWrapperEnd()
    {

    }

    public function render()
    {
        AssetLoader::loadSingleProductAssets();
        // Lets extensions (e.g. Pro's advanced-variation selector) enqueue the
        // CSS/JS a product purchase UI needs. Backs the card surfaces that go
        // through this renderer's render() — Shop, Product List, the product
        // shortcode, and the quick-view modal. Surfaces that render card parts
        // via the individual render* methods instead of render() (Product
        // Carousel, Related Products) fire this same hook from their own block
        // render. Guarded with a static flag so a grid of N cards fires it once
        // per request, not once per card. No payload — assets are uniform.
        static $assetsHookFired = false;
        if (!$assetsHookFired) {
            $assetsHookFired = true;
            do_action('fluent_cart/advanced_variation/enqueue_assets');
        }
        $cursor = '';
        if (!empty($this->config['cursor'])) {
            $cursor = 'data-fluent-cart-cursor="' . esc_attr($this->config['cursor']) . '"';
        }

        $cardWidth = '';
        $cardWidthValue = Arr::get($this->config, 'card_width', '');
        if ($cardWidthValue) {
            $widthValue = $cardWidthValue === '100%' ? '100%' : intval($cardWidthValue) . 'px';
            $cardWidth = 'style="width: ' . esc_attr($widthValue) . ';"';
        }


        // Card wrapper classes are filterable so an integration can tag cards
        // (sale, low-stock, layout variants) without wrapping or re-rendering
        // the whole card. Sanitised in RenderGate, so listeners may return a
        // plain array of class names.
        $cardClasses = RenderGate::cardClasses(['fct-product-card'], [
                'product' => $this->product,
                'scope'   => RenderGate::SCOPE_CARD,
        ]);

        do_action('fluent_cart/product/group/before_card', RenderContext::decorate([
                'product' => $this->product,
                'scope'   => RenderGate::SCOPE_CARD,
        ]));
        ?>
        <article data-fluent-cart-shop-app-single-product data-fct-product-card=""
                 class="<?php echo esc_attr($cardClasses); ?>"
                <?php echo $cursor; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped ?>
                <?php echo $cardWidth; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped ?>
                 aria-label="<?php echo esc_attr(sprintf(
                 /* translators: %s: product title */
                         __('%s product card', 'fluent-cart'), $this->product->post_title));
                 ?>">
            <?php $this->renderProductImage(); ?>
            <?php $this->renderTitle(); ?>
            <?php $this->renderStarRating(); ?>
            <?php if (!Arr::get($this->config, 'hide_excerpt', false)) { $this->renderExcerpt(); } ?>
            <?php $this->renderPrices(); ?>
            <?php $this->showBuyButton(); ?>
        </article>
        <?php
        do_action('fluent_cart/product/group/after_card', RenderContext::decorate([
                'product' => $this->product,
                'scope'   => RenderGate::SCOPE_CARD,
        ]));
    }

    /**
     * @deprecated Use PackageDescriptionRenderer::buildPackageInfoFromOtherInfo() directly.
     * Kept as a compatibility shim for external callers (themes, extensions).
     *
     * @param array $otherInfo Order item's other_info array
     * @return string e.g. "Gift (Box) · 30 × 20 × 15 cm · Wt: 2 kg · Shipping: 2.5 kg"
     */
    public static function buildPackageInfoFromOtherInfo($otherInfo)
    {
        return PackageDescriptionRenderer::buildPackageInfoFromOtherInfo($otherInfo);
    }

    /**
     * @deprecated Use PackageDescriptionRenderer::renderPackageDescription() directly.
     * Kept as a compatibility shim for external callers (themes, extensions) —
     * package-description rendering now lives in PackageDescriptionRenderer.
     */
    public function renderPackageDescription(
        $wrapper_attributes = '',
        $showName = true,
        $showDimensions = true,
        $showProductWeight = true,
        $showTotalWeight = true,
        $variant = null,
        $defaultVariant = null
    ) {
        (new PackageDescriptionRenderer($this->product))->renderPackageDescription(
            $wrapper_attributes,
            $showName,
            $showDimensions,
            $showProductWeight,
            $showTotalWeight,
            $variant,
            $defaultVariant
        );
    }

    public function renderStarRating()
    {
        if (!ModuleSettings::isActive('reviews')) {
            return;
        }

        // Store-level toggles: Settings → Store Settings → Product Page →
        // Product Rating. Shop grid/carousels gate on show_rating_in_shop;
        // relevant/related product sections gate on show_rating_in_relevant
        // (callers tag those cards with config rating_context = 'relevant').
        // The explicit product-rating block (renderStarRatingBlock) stays
        // unaffected — it is user-placed.
        $ratingContext = Arr::get($this->config, 'rating_context', 'shop');

        $ratingVisibilitySettingKey = $ratingContext === 'relevant'
            ? 'show_rating_in_relevant'
            : 'show_rating_in_shop';

        if ((new \FluentCart\Api\StoreSettings())->get($ratingVisibilitySettingKey, 'yes') !== 'yes') {
            return;
        }

        $otherInfo = $this->product->detail->other_info ?: [];
        $avgRating = Arr::get($otherInfo, 'average_rating', 0);
        $reviewCount = (int) Arr::get($otherInfo, 'review_count', 0);

        if ($reviewCount < 1) {
            return;
        }

        $fullStars = (int) floor($avgRating);
        $halfStar = ($avgRating - $fullStars) >= 0.5;
        $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);

        ?>
        <div class="fct-product-card-rating" aria-label="<?php echo esc_attr(sprintf(
            /* translators: %1$s: average rating, %2$d: review count */
            __('Rated %1$s out of 5 based on %2$d reviews', 'fluent-cart'), $avgRating, $reviewCount)); ?>">
            <span class="fct-product-card-stars" aria-hidden="true">
                <?php
                for ($i = 0; $i < $fullStars; $i++) {
                    echo '<span class="fct-star fct-star-filled">&#9733;</span>';
                }
                if ($halfStar) {
                    echo '<span class="fct-star fct-star-half"><span class="fct-star-half-empty">&#9733;</span><span class="fct-star-half-fill">&#9733;</span></span>';
                }
                for ($i = 0; $i < $emptyStars; $i++) {
                    echo '<span class="fct-star fct-star-empty">&#9733;</span>';
                }
                ?>
            </span>
            <span class="fct-product-card-review-count">(<?php echo esc_html($reviewCount); ?>)</span>
        </div>
        <?php
    }

    public function renderStarRatingBlock($wrapperAttributes = '')
    {
        $otherInfo = $this->product->detail->other_info ?: [];
        $avgRating = Arr::get($otherInfo, 'average_rating', 0);
        $reviewCount = (int) Arr::get($otherInfo, 'review_count', 0);

        $fullStars = (int) floor($avgRating);
        $halfStar = ($avgRating - $fullStars) >= 0.5;
        $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);

        ?>
        <div <?php echo $wrapperAttributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-label="<?php echo esc_attr(sprintf(
            /* translators: %1$s: average rating, %2$d: review count */
            __('Rated %1$s out of 5 based on %2$d reviews', 'fluent-cart'), $avgRating, $reviewCount)); ?>">
            <span class="fct-product-card-stars" aria-hidden="true">
                <?php
                for ($i = 0; $i < $fullStars; $i++) {
                    echo '<span class="fct-star fct-star-filled">&#9733;</span>';
                }
                if ($halfStar) {
                    echo '<span class="fct-star fct-star-half"><span class="fct-star-half-empty">&#9733;</span><span class="fct-star-half-fill">&#9733;</span></span>';
                }
                for ($i = 0; $i < $emptyStars; $i++) {
                    echo '<span class="fct-star fct-star-empty">&#9733;</span>';
                }
                ?>
            </span>
            <span class="fct-product-card-review-count">(<?php echo esc_html($reviewCount); ?>)</span>
        </div>
        <?php
    }

    public function renderExcerpt($atts = '')
    {
        if (empty($this->product->post_excerpt)) {
            return;
        }

        $gateContext = RenderGate::context($this->product, RenderGate::SCOPE_CARD);

        if (!RenderGate::shouldRender('excerpt', $gateContext)) {
            return;
        }

        do_action('fluent_cart/product/group/before_excerpt_block', $gateContext);

        echo sprintf(
            '<p %1$s class="fct-product-card-excerpt">
                   %2$s
            </p>',
            $atts, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            wp_kses_post($this->product->post_excerpt),
        );

        do_action('fluent_cart/product/group/after_excerpt_block', $gateContext);
    }

    public function renderTitle($atts = '', $config = [])
    {
        $gateContext = RenderGate::context($this->product, RenderGate::SCOPE_CARD);

        if (!RenderGate::shouldRender('title', $gateContext)) {
            return;
        }

        do_action('fluent_cart/product/group/before_title_block', $gateContext);

        $link = Arr::get($config, 'isLink', true);
        $target = Arr::get($config, 'target', '_self');

        $titleText = esc_html($this->product->post_title);

        if ($link) {
            // Render as link
            $targetAttr = $target === '_blank' ? 'target="_blank" rel="noopener noreferrer"' : '';
            echo sprintf(
                    '<h3 class="fct-product-card-title">
                        <a %1$s data-fluent-cart-product-link 
                           data-product-id="%2$s" 
                           href="%3$s" 
                           aria-label="%4$s"
                           %5$s>%6$s</a>
                    </h3>',
                    $atts, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    esc_attr($this->product->ID),
                    esc_url($this->product->view_url),
                    esc_attr($this->product->post_title),
                    $targetAttr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    esc_html($titleText)
            );
        } else {
            // Render as plain text (no link)
            echo sprintf(
                '<h3 class="fct-product-card-title" %1$s>%2$s</h3>',
                $atts, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                esc_html($titleText)
            );
        }

        do_action('fluent_cart/product/group/after_title_block', $gateContext);
    }

    public function renderProductImage()
    {
        if (!RenderGate::shouldRender('image', RenderGate::context($this->product, RenderGate::SCOPE_CARD))) {
            return;
        }

        $image = $this->product->thumbnail;
        $isPlaceholder = false;

        if (!$image) {
            $image = Vite::getAssetUrl('images/placeholder.svg');
            $isPlaceholder = true;
        }

        $altText = $isPlaceholder
                ? sprintf(
                /* translators: %s: product title */
                        __('Placeholder image for %s', 'fluent-cart'), $this->product->post_title)
                : $this->product->post_title;

        do_action('fluent_cart/product/group/before_image_block', RenderContext::decorate([
                'product'       => $this->product,
                'scope'         => 'product_card'
        ]));
        ?>
        <a class="fct-product-card-image-wrap"
           href="<?php echo esc_url($this->viewUrl); ?>"
           style="display: block;"
           aria-label="<?php echo esc_attr(sprintf(
           /* translators: %s: product title */
                   __('View %s product image', 'fluent-cart'), $this->product->post_title)); ?>">
            <img class="fct-product-card-image"
                 data-fluent-cart-shop-app-single-product-image
                 src="<?php echo esc_url($image); ?>"
                 alt="<?php echo esc_attr($altText); ?>"
                 loading="lazy"
                 width="300"
                 height="300"/>
        </a>
        <?php

        do_action('fluent_cart/product/group/after_image_block', RenderContext::decorate([
                'product'       => $this->product,
                'scope'         => 'product_card'
        ]));
    }

    public function renderPrices($wrapper_attributes = '')
    {
        if (!RenderGate::shouldRender('price', RenderGate::context($this->product, RenderGate::SCOPE_CARD))) {
            return;
        }

        $priceFormat = Arr::get($this->config, 'price_format', 'starts_from');
        $isSimple = $this->product->detail->variation_type === 'simple';
        $minPrice = $this->product->detail->min_price;
        $maxPrice = $this->product->detail->max_price;
        $comparePrice = 0;
        $firstVariant = null;

        if ($isSimple) {
            $firstVariant = $this->product->variants->first();
            if ($firstVariant) {
                $minPrice = $firstVariant->item_price;
                $minPrice = apply_filters('fluent_cart/product/display_price', $minPrice, [
                    'product'   => $this->product,
                    'variation' => $firstVariant,
                ]);
                $minPrice = (int)$minPrice;
                if ($firstVariant->compare_price > $minPrice) {
                    $comparePrice = $firstVariant->compare_price;
                }
            }
        }

        $formattedMinPrice = Helper::toDecimal($minPrice);
        $formattedMaxPrice = Helper::toDecimal($maxPrice);
        $formattedComparePrice = Helper::toDecimal($comparePrice);

        do_action('fluent_cart/product/group/before_price_block', RenderContext::decorate([
                'product'       => $this->product,
                'current_price' => $minPrice,
                'scope'         => 'product_card'
        ]));
        ?>
        <div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                class="fct-product-card-prices"
                role="region"
                aria-label="<?php echo esc_attr__('Product pricing', 'fluent-cart'); ?>">
            <?php if ($comparePrice): ?>
                <span class="fct-compare-price">
                    <span class="fct-sr-only"><?php echo esc_html__('Original price:', 'fluent-cart'); ?></span>
                    <del><?php echo esc_html($formattedComparePrice); ?></del>
                </span>
            <?php endif; ?>

            <?php if (!$comparePrice && $maxPrice && $maxPrice > $minPrice): ?>
                <!-- Case 2: price range -->
                <?php if ($priceFormat === 'range'): ?>
                    <span class="fct-item-price">
                        <span class="fct-sr-only"><?php echo esc_html__('Price range:', 'fluent-cart'); ?></span>
                        <?php echo esc_html($formattedMinPrice); ?>
                        <span aria-hidden="true">-</span>
                        <span class="fct-sr-only"><?php echo esc_html__('to', 'fluent-cart'); ?></span>
                        <?php echo esc_html($formattedMaxPrice); ?>
                    </span>
                <?php else: ?>
                    <span class="fct-item-price"><?php
                            /* translators: %s is the minimum price */
                            printf(esc_html__('From %s', 'fluent-cart'), esc_html($formattedMinPrice));
                            ?></span>
                <?php endif; ?>

            <?php else: ?>
                <!-- Case 3: Simple or single price -->
                <span class="fct-item-price">
                    <span class="fct-sr-only"><?php echo esc_html__('Price:', 'fluent-cart'); ?></span>
                    <?php echo esc_html($formattedMinPrice); ?>
                </span>
            <?php endif; ?>

            <?php
            do_action('fluent_cart/product/after_price', RenderContext::decorate([
                    'product'       => $this->product,
                    'variant'       => $firstVariant,
                    'current_price' => $minPrice,
                    'scope'         => 'product_card'
            ]));
            RenderHelper::renderPriceSuffix($this->product, $firstVariant, 'product_card');
            ?>
        </div>
        <?php
        do_action('fluent_cart/product/group/after_price_block', RenderContext::decorate([
                'product'       => $this->product,
                'current_price' => $minPrice,
                'scope'         => 'product_card'
        ]));
    }

    public function showBuyButton($atts = '')
    {
        $gateContext = RenderGate::context($this->product, RenderGate::SCOPE_CARD);

        // 'actions' gates the whole affordance slot, including the disabled
        // out-of-stock placeholder — hiding the row should not leave a stray
        // "Not Available" button behind.
        if (!RenderGate::shouldRender('actions', $gateContext)) {
            return;
        }

        do_action('fluent_cart/product/group/before_actions_block', $gateContext);

        $this->renderBuyButtonMarkup($atts, $gateContext);

        do_action('fluent_cart/product/group/after_actions_block', $gateContext);
    }

    protected function renderBuyButtonMarkup($atts, array $gateContext)
    {
        $isOutOfStock = ModuleSettings::isActive('stock_management') && !$this->product->isStock();

        if ($isOutOfStock) {
            $soldOutText = __('Not Available', 'fluent-cart');
            ?>
            <button type="button" class="fct-product-view-button out-of-stock" disabled aria-disabled="true"
                    aria-label="<?php echo esc_attr($soldOutText); ?>">
                <span class="fct-button-text">
                    <?php echo esc_html($soldOutText); ?>
                </span>
            </button>
            <?php
            return;
        }

        $enableModalCheckout = Helper::isModalCheckoutEnabled();
        $isSimple = $this->product->detail->variation_type === 'simple';
        $firstVariant = null;
        $buttonHref = $this->viewUrl;

        if ($isSimple) {
            $firstVariant = $this->product->variants->first();
            if ($firstVariant) {
                // return '';
            }
        }

        $isInstantCheckout = false;
        $hasSubscription = $this->product->has_subscription;
        $buttonText = __('View Options', 'fluent-cart');
        $ariaLabel = sprintf(
        /* translators: %s: product title */
                __('View options for %s', 'fluent-cart'), $this->product->post_title);

        if ($isSimple) {
            if ($hasSubscription) {
                $buttonText = __('Buy Now', 'fluent-cart');
                $ariaLabel = sprintf(
                /* translators: %s: product title */
                        __('Buy %s now', 'fluent-cart'), $this->product->post_title);
                $buttonHref = $firstVariant->getPurchaseUrl();
                $isInstantCheckout = true;
            } else {
                $buttonText = __('Add To Cart', 'fluent-cart');
                $ariaLabel = sprintf(
                /* translators: %s: product title */
                        __('Add %s to cart', 'fluent-cart'), $this->product->post_title);
            }
        }

        // The card fills this slot with one of three things: an instant-checkout
        // Buy Now anchor (simple + subscription), an Add to Cart button (simple),
        // or a "View Options" link through to the product page (variable). The
        // first two are purchase affordances and answer to their own gates.
        // "View Options" is navigation, so it stays under 'actions' alone — a
        // catalog-mode listener hides the buying, not the browsing.
        if ($isInstantCheckout) {
            if (!RenderGate::shouldRender('buy_now_button', $gateContext)) {
                return;
            }
        } elseif ($firstVariant) {
            if (!RenderGate::shouldRender('add_to_cart_button', $gateContext)) {
                return;
            }
        }

        $buttonAttributes = [
                'class'                                            => 'fct-product-view-button fct-single-product-card-view-button',
                'data-product-id'                                  => $this->product->ID,
                'data-fluent-cart-single-product-card-view-button' => '',
                'aria-label'                                       => $ariaLabel
        ];

        if ($firstVariant) {
            $buttonAttributes = [
                    'data-cart-id'                        => $firstVariant->id,
                    'class'                               => 'fluent-cart-add-to-cart-button',
                    'data-variation-type'                 => $this->product->detail->variation_type,
                    'data-fluent-cart-add-to-cart-button' => '',
                    'data-is-custom'                      => false,
                    'aria-label'                          => $ariaLabel
            ];
        }
        $customAttributes = $this->parseAttributes($atts);
        if (!empty($customAttributes)) {
            if (isset($customAttributes['class']) && isset($buttonAttributes['class'])) {
                $buttonAttributes['class'] .= ' ' . $customAttributes['class'];
                unset($customAttributes['class']);
            }
            $buttonAttributes = array_merge($buttonAttributes, $customAttributes);
        }

        $anchorAttributes = [
                'href'       => $buttonHref,
                'class'      => 'fct-product-view-button',
                'aria-label' => $ariaLabel,
        ];

        if ($enableModalCheckout) {
            $anchorAttributes['data-fct-instant-checkout-button'] = '';
            $anchorAttributes['data-enable-modal-checkout'] = 'yes';
        }

        if ($isInstantCheckout) {
            $parsedCustomAttributes = $this->parseAttributes($atts);
            if (isset($parsedCustomAttributes['class']) && isset($anchorAttributes['class'])) {
                $anchorAttributes['class'] .= ' ' . $parsedCustomAttributes['class'];
                unset($parsedCustomAttributes['class']);
            }
            $anchorAttributes = array_merge($anchorAttributes, $parsedCustomAttributes);
        }
        ?>
        <?php if ($isInstantCheckout): ?>
        <a <?php $this->renderAttributes($anchorAttributes); ?>>
            <span aria-hidden="true">
                <?php echo esc_html($buttonText); ?>
            </span>
        </a>
    <?php else: ?>
        <button
                type="button"
                data-button-url="<?php echo esc_url($buttonHref); ?>"
                <?php $this->renderAttributes($buttonAttributes); ?>>
            <span class="fct-button-text">
                <?php echo esc_html($buttonText); ?>
            </span>
            <span
                  class="fluent-cart-loader"
                  role="status"
                  aria-live="polite"
                  aria-label="<?php echo esc_attr__('Loading', 'fluent-cart'); ?>">
                <svg aria-hidden="true"
                     viewBox="0 0 100 101"
                     fill="none"
                     xmlns="http://www.w3.org/2000/svg"
                     focusable="false">
                      <path
                              d="M100 50.5908C100 78.2051 77.6142 100.591 50 100.591C22.3858 100.591 0 78.2051 0 50.5908C0 22.9766 22.3858 0.59082 50 0.59082C77.6142 0.59082 100 22.9766 100 50.5908ZM9.08144 50.5908C9.08144 73.1895 27.4013 91.5094 50 91.5094C72.5987 91.5094 90.9186 73.1895 90.9186 50.5908C90.9186 27.9921 72.5987 9.67226 50 9.67226C27.4013 9.67226 9.08144 27.9921 9.08144 50.5908Z"
                              fill="currentColor"></path>
                      <path
                              d="M93.9676 39.0409C96.393 38.4038 97.8624 35.9116 97.0079 33.5539C95.2932 28.8227 92.871 24.3692 89.8167 20.348C85.8452 15.1192 80.8826 10.7238 75.2124 7.41289C69.5422 4.10194 63.2754 1.94025 56.7698 1.05124C51.7666 0.367541 46.6976 0.446843 41.7345 1.27873C39.2613 1.69328 37.813 4.19778 38.4501 6.62326C39.0873 9.04874 41.5694 10.4717 44.0505 10.1071C47.8511 9.54855 51.7191 9.52689 55.5402 10.0491C60.8642 10.7766 65.9928 12.5457 70.6331 15.2552C75.2735 17.9648 79.3347 21.5619 82.5849 25.841C84.9175 28.9121 86.7997 32.2913 88.1811 35.8758C89.083 38.2158 91.5421 39.6781 93.9676 39.0409Z"
                              fill="currentFill"></path>
                </svg>
            </span>
        </button>
    <?php endif; ?>
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

    private function parseAttributes($atts)
    {
        if (empty($atts)) {
            return [];
        }

        $attributes = [];

        // Match attribute="value" or attribute='value' or attribute=value
        preg_match_all('/(\w+(?:-\w+)*)=(["\'])(.*?)\2|\b(\w+(?:-\w+)*)=(\S+)/', $atts, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (!empty($match[1])) {
                // Quoted value
                $attributes[$match[1]] = $match[3];
            } elseif (!empty($match[4])) {
                // Unquoted value
                $attributes[$match[4]] = $match[5];
            }
        }

        return $attributes;
    }
}
