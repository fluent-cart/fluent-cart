<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Cart;
use FluentCart\Framework\Support\Arr;

class CartItemRenderer
{
    protected $item = [];
    protected $cart = null;

    protected $product = null;

    protected $variant = null;

    public function __construct($item = [], ?Cart $cart = null)
    {
        $this->item = $item;
        $this->cart = $cart;
    }

    protected function getEventInfo()
    {
        return [
            'item'    => $this->item,
            'cart'    => $this->cart,
            'product' => $this->product,
            'variant' => $this->variant,
        ];
    }

    public function render()
    {
        $wrapperClassAttributes = [
            'fct_line_item',
            'fct_product_id_' . Arr::get($this->item, 'post_id', ''),
            'fct_item_id_' . Arr::get($this->item, 'id', ''),
            'fct_item_type_' . Arr::get($this->item, 'other_info.payment_type', ''),
        ];

        if (Arr::get($this->item, 'featured_media')) {
            $wrapperClassAttributes[] = 'fct_has_image';
        }

        $promoPriceOriginal = Arr::get($this->item, 'other_info.promo_price_original', 0);
        if ($promoPriceOriginal && $promoPriceOriginal > $this->item['unit_price']) {
            $promoPriceOriginal = $promoPriceOriginal * Arr::get($this->item, 'quantity', 1);
        } else {
            $promoPriceOriginal = '';
        }
        ?>
        <div class="<?php $this->renderCssAtts($wrapperClassAttributes); ?>" role="listitem">
            <div class="fct_line_item_info">
                <?php $this->renderImage(); ?>
                <div class="fct_item_content">
                    <?php $this->renderTitle(); ?>
                    <?php $this->renderChildVariants(); ?>
                    <?php do_action('fluent_cart/cart/line_item/line_meta', $this->getEventInfo()); ?>
                </div>
            </div><!-- .fct_line_item_info -->

            <div class="fct_line_item_price" aria-label="<?php esc_attr_e('Price information', 'fluent-cart'); ?>">
                <?php do_action('fluent_cart/cart/line_item/before_total', $this->getEventInfo()); ?>
                <?php if($promoPriceOriginal) : ?>
                <div style="text-decoration: line-through;" class="fct_line_item_total fct_promo_price" aria-label="<?php esc_attr_e('Original price', 'fluent-cart'); ?>">
                    <?php echo esc_html(Helper::toDecimal($promoPriceOriginal)); ?>
                </div>
                <?php endif; ?>
                <span class="fct_line_item_total" aria-label="<?php esc_attr_e('Total price', 'fluent-cart'); ?>">
                    <?php echo esc_html(Helper::toDecimal(Arr::get($this->item, 'subtotal', 0))); ?>
                </span>
                <?php do_action('fluent_cart/cart/line_item/after_total', $this->getEventInfo()); ?>
            </div><!-- .fct_line_item_price -->
        </div>
        <?php
    }

    public function renderTitle()
    {
        $href = Arr::get($this->item, 'view_url', '');

        $mainTitle = (string) Arr::get($this->item, 'post_title', '');
        $subtitle = (string) Arr::get($this->item, 'title', '');

        $quantity = Arr::get($this->item, 'quantity', 1);

        ?>
        <div class="fct_item_title">
            <?php do_action('fluent_cart/cart/line_item/before_main_title', $this->getEventInfo()); ?>
            <?php if ($quantity > 1): ?>
                <span class="fct_item_quantity" aria-label="<?php echo esc_attr(sprintf(
                        /* translators: %d: quantity */
                        __('Quantity %d', 'fluent-cart'), $quantity)); ?>">
                    <?php echo esc_attr($quantity); ?> <span aria-hidden="true">x</span>
                </span>
            <?php endif; ?>
            <?php if ($href): ?>
                <a
                   href="<?php echo esc_url($href); ?>"
                   aria-label="<?php echo esc_attr(sprintf(
                           /* translators: %s: product title */
                           __('View details for %s', 'fluent-cart'), $mainTitle)); ?>"
                >
                    <?php echo wp_kses_post($mainTitle); ?>
                </a>
            <?php else: ?>
                <?php echo wp_kses_post($mainTitle); ?>
            <?php endif; ?>

            <?php if ($mainTitle != $subtitle && $subtitle): ?>
                <div class="fct_item_variant_title" aria-label="<?php esc_attr_e('Variant', 'fluent-cart'); ?>">
                    - <?php echo wp_kses_post($subtitle); ?>
                </div>
            <?php endif; ?>
            <?php $this->maybeRenderPaymentTypeInfo(); ?>
            <?php $this->maybeRenderPackageInfo(); ?>

            <?php do_action('fluent_cart/cart/line_item/after_main_title', $this->getEventInfo()); ?>
        </div>
        <?php
    }

    public function renderImage()
    {
        $image = Arr::get($this->item, 'featured_media');
        if (!$image) {
            return;
        }
        $href = Arr::get($this->item, 'view_url', '');
        $altText = sprintf(
                /* translators: %s: product title */
                __('Image of %s', 'fluent-cart'), Arr::get($this->item, 'title', __('product', 'fluent-cart')));
        ?>
        <div class="fct_item_image">
            <?php if ($href): ?>
                <a href="<?php echo esc_url($href); ?>">
                    <img src="<?php echo esc_url($image); ?>"
                         alt="<?php echo esc_attr($altText); ?>"/>
                </a>
            <?php else: ?>
                <img src="<?php echo esc_url($image); ?>"
                     alt="<?php echo esc_attr($altText); ?>"/>
            <?php endif; ?>
        </div>
        <?php
    }

    protected function renderCssAtts($atts)
    {
        echo esc_attr(implode(' ', $atts));
    }

    protected function maybeRenderPaymentTypeInfo()
    {
        $otherInfo = Arr::get($this->item, 'other_info', []);
        $paymentType = Arr::get($otherInfo, 'payment_type', '');
        $itemPrice = Arr::get($this->item, 'unit_price', 0);
        
        if (isset($this->item['recurring_discounts'])) {
            $otherInfo['recurring_discounts'] = $this->item['recurring_discounts'];
        }

        if ($paymentType === 'subscription') {
            $subscriptionInfo = Helper::generateSubscriptionInfo($otherInfo, $itemPrice);
            $setupFeeInfo = Helper::generateSetupFeeInfo($otherInfo);
            $trialInfo = Helper::generateTrialInfo($otherInfo);
            ?>
            <div class="fct_item_payment_info">
                <span class="sr-only"><?php esc_html_e('Payment information', 'fluent-cart'); ?></span>

                <span> <?php echo wp_kses($subscriptionInfo, ['del' => true]); ?> </span>
                <?php if ($trialInfo): ?>
                    <span class="trial-days"> <?php echo esc_html($trialInfo); ?> </span>
                <?php endif; ?>
                <?php if (!empty($setupFeeInfo)): ?>
                    <span class="setup-fee"> <?php echo esc_html($setupFeeInfo); ?> </span>
                <?php endif; ?>
            </div>
            <?php
            return;
        }

        $quantity = Arr::get($this->item, 'quantity', 1);

        if ($quantity < 2) {
            return;
        }
        ?>
        <div class="fct_item_payment_info">
            <?php
            /* translators: %s is the item price */
            printf(esc_html__('%s each', 'fluent-cart'), esc_html(Helper::toDecimal($itemPrice)));
            ?>
        </div>
        <?php
    }

    protected function maybeRenderPackageInfo()
    {
        $otherInfo = Arr::get($this->item, 'other_info', []);
        if (!is_array($otherInfo)) {
            return;
        }

        $packageSlug = Arr::get($otherInfo, 'package_slug', '');
        $package = Helper::getPackageBySlug($packageSlug);
        if (!$package) {
            return;
        }

        $name = Arr::get($package, 'name', '');
        $type = Arr::get($package, 'type', '');

        $parts = [];
        if ($name) {
            $parts[] = $name;
        }

        $storeWeightUnit = Helper::shopConfig('weight_unit') ?: 'kg';
        $productWeight = floatval(Arr::get($otherInfo, 'weight', 0));
        $productWeightUnit = Arr::get($otherInfo, 'weight_unit', $storeWeightUnit);
        $convertedProductWeight = Helper::convertWeight($productWeight, $productWeightUnit, $storeWeightUnit);
        $packageWeight = floatval(Arr::get($package, 'weight', 0));
        $packageWeightUnit = Arr::get($package, 'weight_unit', $storeWeightUnit);
        $convertedPackageWeight = Helper::convertWeight($packageWeight, $packageWeightUnit, $storeWeightUnit);
        $totalWeight = $convertedProductWeight + $convertedPackageWeight;

        if ($totalWeight) {
            $formatted = rtrim(rtrim(number_format($totalWeight, 2), '0'), '.');
            $parts[] = $formatted . ' ' . $storeWeightUnit;
        }

        if (!$parts) {
            return;
        }

        $icon = ShippingMethodsRender::getPackageTypeIcon($type ?: 'box');
        ?>
        <div class="fct_item_package_info">
            <?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <span><?php echo esc_html(implode(' · ', $parts)); ?></span>
        </div>
        <?php
    }

    public function renderChildVariants(){
        $childVariants = Arr::get($this->item, 'child_variants', []);

        if (empty($childVariants)) {
            return;
        }

        $total = count($childVariants);

        ?>
        <div class="fct-bundle-products" data-fluent-cart-collapsibles>
            <h4 class="fct-bundle-products-title">
                <?php echo esc_html__('Bundle of', 'fluent-cart') . ':'; ?>
            </h4>
            <div class="fct-bundle-products-list">
                <?php foreach (array_slice($childVariants, 0, 2) as $childVariant): ?>
                    <p>
                        <?php echo esc_html(Arr::get($childVariant, 'post_title')) . ' - ' . esc_html(Arr::get($childVariant, 'variation_title')); ?>
                    </p>
                <?php endforeach; ?>

                <?php if ($total > 2): ?>
                    <div class="fct-bundle-products-more">
                        <div class="fct-bundle-products-more-list">
                            <?php foreach (array_slice($childVariants, 2) as $childVariant): ?>
                                <p>
                                    <?php echo esc_html(Arr::get($childVariant, 'post_title')) . ' - ' . esc_html(Arr::get($childVariant, 'variation_title')); ?>
                                </p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif;?>
            </div>

            <?php if ($total > 2) : ?>
                <a href="#" class="fct-see-more-btn" data-fluent-cart-collapsible-toggle>
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
                </a>
            <?php endif; ?>
        </div>
    <?php }

}
