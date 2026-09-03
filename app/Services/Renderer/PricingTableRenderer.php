<?php

namespace FluentCart\App\Services\Renderer;


use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;
use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Hooks\Handlers\ShortCodes\Buttons\AddToCartShortcode;
use FluentCart\App\Hooks\Handlers\ShortCodes\Buttons\DirectCheckoutShortcode;

class PricingTableRenderer
{
    protected $viewData;

    protected $variants;

    protected $storeSettings;

    protected $sign;

    protected $repeatInterval = null;

    protected $itemPrice = null;

    protected $comparePrice = null;

    protected $paymentInfo = '';

    protected $setupFeeInfo = '';

    protected $featureItems = [];

    protected $showCartButton = 1;

    protected $paymentType = '';

    protected $licenseEnable = 'no';


    protected $productPerRow = 4;


    public function __construct($viewData)
    {
        $this->viewData = $viewData;
        $this->variants = $this->viewData['variants'];
        $this->storeSettings = new StoreSettings();

        $signName = $this->storeSettings->get('currency');

        $this->sign = CurrenciesHelper::getCurrencySign($signName);

        $this->showCartButton = Arr::get($this->viewData, 'show_cart_button', 1);
        $productPerRow = absint(Arr::get($this->viewData, 'product_per_row', 4));
        $this->productPerRow = $productPerRow > 0 ? $productPerRow : 4;
    }

    public function render()
    {
        $groupBy = Arr::get($this->viewData, 'group_by', 'repeat_interval');
        $groups = $this->groupVariants($groupBy);
        $useTabs = $groupBy !== 'none' && count($groups) > 1;

        ?>
        <div class="fluent-cart-pricing-table" data-fluent-cart-pricing-table role="region"
             aria-label="<?php esc_attr_e('Pricing table', 'fluent-cart'); ?>">
            <?php if ($useTabs) : ?>
                <?php $this->renderTabs($groups); ?>
            <?php else : ?>
                <div 
                    class="fluent-cart-pricing-table-variants-iterator" 
                    role="list" 
                    style="--fluent-cart-pricing-table-product-per-row: <?php echo esc_attr($this->productPerRow); ?>;"
                >
                    <?php $this->renderVariant($this->variants); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    protected function groupVariants(string $groupBy): array
    {
        if ($groupBy === 'none') {
            return ['onetime' => $this->variants];
        }

        $key = $groupBy === 'payment_type' ? 'payment_type' : 'repeat_interval';

        $groups = [];
        foreach ($this->variants as $variant) {
            $bucket = Arr::get($variant, "other_info.{$key}") ?: 'onetime';
            if (Arr::get($variant, 'other_info.installment') === 'yes') {
                $bucket = 'installment';
            }
            $groups[$bucket][] = $variant;
        }

        return $groups;
    }

    protected function renderTabs(array $groups)
    {
        $groupKeys = array_keys($groups);
        $activeIndex = (int) Arr::get($this->viewData, 'active_tab', 0);
        if (!isset($groupKeys[$activeIndex])) {
            $activeIndex = 0;
        }
        $activeKey = $groupKeys[$activeIndex];
        $activeVariantMap = Arr::get($this->viewData, 'active_variant', []);
        if (!\is_array($activeVariantMap)) {
            $activeVariantMap = [];
        }

        ?>
        <div class="fluent-cart-pricing-table-tab" data-fluent-cart-pricing-table-tab>
            <div class="fluent-cart-pricing-table-tab-nav">
                <div class="fluent-cart-pricing-table-tab-nav-inner-wrap">
                    <div class="fluent-cart-pricing-table-tab-nav-inner" role="tablist">
                        <div class="tab-active-bar" data-tab-active-bar></div>
                        <?php foreach ($groupKeys as $key) :
                            $isActive = $key === $activeKey;
                            ?>
                            <div class="fluent-cart-pricing-table-tab-nav-item<?php echo $isActive ? ' active' : ''; ?>"
                                 data-tab="<?php echo esc_attr($key); ?>">
                                <?php echo esc_html($this->getTabLabel($key)); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="fluent-cart-pricing-table-tab-content" data-tab-contents>
                <?php foreach ($groups as $key => $groupVariants) :
                    $isActive = $key === $activeKey;
                    $currentActiveVariant = Arr::get($activeVariantMap, $key, '');
                    ?>
                    <div id="<?php echo esc_attr($key); ?>"
                         class="fluent-cart-pricing-table-tab-pane<?php echo $isActive ? ' active' : ''; ?>"
                         data-tab-content
                         data-active_variant="<?php echo esc_attr($currentActiveVariant); ?>"
                         role="tabpanel">
                        <div 
                            class="fluent-cart-pricing-table-variants-iterator" 
                            role="list"
                            style="--fluent-cart-pricing-table-product-per-row: <?php echo esc_attr($this->productPerRow); ?>;"
                        >
                            <?php $this->renderVariant($groupVariants); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    protected function getTabLabel(string $key): string
    {
        $labels = [
            'daily'        => __('Daily', 'fluent-cart'),
            'weekly'       => __('Weekly', 'fluent-cart'),
            'monthly'      => __('Monthly', 'fluent-cart'),
            'yearly'       => __('Yearly', 'fluent-cart'),
            'onetime'      => __('One Time', 'fluent-cart'),
            'subscription' => __('Subscription', 'fluent-cart'),
            'installment'  => __('Installment', 'fluent-cart'),
        ];

        return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    protected function isVariantActive($variant): bool
    {
        $groupBy = Arr::get($this->viewData, 'group_by', 'repeat_interval');

        if ($groupBy === 'none') {
            $variantGroupKey = 'onetime';
        } else {
            $groupKey = $groupBy === 'payment_type' ? 'payment_type' : 'repeat_interval';

            $variantGroupKey = Arr::get($variant, "other_info.{$groupKey}") ?: 'onetime';

            if (Arr::get($variant, 'other_info.installment') === 'yes') {
                $variantGroupKey = 'installment';
            }
        }

        $activeVariantMap = Arr::get($this->viewData, 'active_variant', []);
        if (!\is_array($activeVariantMap)) {
            $activeVariantMap = [];
        }

        $variantId = Arr::get($variant, 'id');

        return isset($activeVariantMap[$variantGroupKey]) && $activeVariantMap[$variantGroupKey] == $variantId;
    }

    public function renderVariant(?array $variants = null)
    {
        $variants = $variants ?? $this->variants;

        foreach ($variants as $index => $variant) {
            $this->repeatInterval = Arr::get($variant, 'other_info.repeat_interval', null);

            $this->itemPrice = esc_html(Helper::toDecimal(Arr::get($variant, 'item_price', 0), false, false, false));

            $this->comparePrice = esc_html(Helper::toDecimal(Arr::get($variant, 'compare_price', 0), false, false, false, false));

            $otherInfo = Arr::get($variant, 'other_info', null);

            $this->paymentInfo = Helper::generateSubscriptionInfo($otherInfo, $this->itemPrice);

            $this->setupFeeInfo = Helper::generateSetupFeeInfo($otherInfo);

            $featureDescription = Arr::get($variant, 'other_info.description', '');

            $this->featureItems = explode("\n", $featureDescription);

            $this->paymentType = Arr::get($variant, 'payment_type');

            $licenseMeta = Arr::get($variant, 'product.licenses_meta.meta_value', null);

            $this->licenseEnable = Arr::get($licenseMeta, 'enabled', 'no');

            $isActiveVariant = $this->isVariantActive($variant);

            // Parse badge settings from viewData
            $badgeData = Arr::get($this->viewData, 'badge', '');
            $badgeConfig = is_string($badgeData) ? self::stringToAssocArray($badgeData) : (is_array($badgeData) ? $badgeData : []);
            $badgeText = Arr::get($badgeConfig, 'text', __('Recommended', 'fluent-cart'));
            $badgePosition = Arr::get($badgeConfig, 'position', 'right');

            ?>

            <div class="fluent-cart-pricing-table-variant<?php echo $isActiveVariant ? ' active' : ''; ?>" data-fluent-cart-pricing-table-variant role="listitem">
                <?php if ($isActiveVariant) : ?>
                    <div class="fluent-cart-pricing-table-variant-badge <?php echo esc_attr($badgePosition); ?>">
                        <?php echo esc_html($badgeText); ?>
                    </div>
                <?php endif; ?>

                <div class="fluent-cart-pricing-table-variant-contents">
                    <div class="fluent-cart-pricing-table-variant-title">
                        <?php echo esc_html(Arr::get($variant, 'product.post_title', '')); ?>
                    </div>

                    <div class="fluent-cart-pricing-table-variant-sub-title">
                        <?php echo esc_html(Arr::get($variant, 'variation_title', '')); ?>
                    </div>

                    <div class="variant-divider" aria-hidden="true"></div>

                    <div class="fluent-cart-pricing-table-variant-price-wrap">
                        <div
                                class="fluent-cart-pricing-table-variant-price"
                                aria-label="<?php
                                printf(
                                    /* translators: 1: Currency sign, 2: Item price, 3: Billing interval or unit */
                                        esc_attr__('Price: %1$s%2$s per %3$s', 'fluent-cart'),
                                        esc_attr($this->sign),
                                        esc_attr($this->itemPrice),
                                        esc_attr($this->repeatInterval ?? __('unit', 'fluent-cart'))
                                );
                                ?>">
                            <sup><?php echo esc_html($this->sign) ?></sup>

                            <?php echo esc_html($this->itemPrice) ?>

                            <?php if (isset($this->repeatInterval)): ?>
                                <span class="repeat-interval">
                                    /<?php echo esc_html($this->repeatInterval) ?>
                                </span>
                            <?php endif; ?>

                        </div>

                        <div class="fluent-cart-pricing-table-variant-compare-price">
                            <span><?php echo esc_html($this->sign) ?></span>
                            <?php
                            $aria_label = sprintf(
                                /* translators: 1: Currency sign, 2: Compare price */
                                    esc_attr__('Compare at: %1$s%2$s', 'fluent-cart'),
                                    $this->sign,
                                    $this->comparePrice
                            );
                            ?>
                            <span aria-label="<?php echo esc_attr($aria_label); ?>">
                                <del><?php echo esc_html($this->comparePrice); ?></del>
                            </span>
                        </div>
                    </div>

                    <div class="fluent-cart-pricing-table-variant-payment-type">
                        <span> <?php echo esc_html($this->paymentInfo); ?> </span>
                        <span class="setup-fee"
                              aria-label="<?php esc_attr_e('Setup fee information', 'fluent-cart'); ?>">
                            <?php echo esc_html($this->setupFeeInfo); ?> 
                        </span>
                    </div>

                    <div class="fluent-cart-pricing-table-variant-description">
                        <?php $this->renderFeatureItems(); ?>
                    </div>

                </div>

                <div class="fluent-cart-pricing-table-variant-buttons">
                    <?php
                    if (
                            $this->showCartButton == 1 &&
                            $this->paymentType !== 'subscription' &&
                            $this->licenseEnable !== 'yes'
                    ) {
                        $this->renderCartButton($variant);
                    }

                    if (Arr::get($this->viewData, 'show_checkout_button', 1) == 1) {
                        $this->renderDirectCheckoutButton($variant);
                    }


                    ?>
                </div>


            </div>

        <?php };
    }

    public function renderFeatureItems()
    {

        ?>
        <ul class="fluent-cart-pricing-table-variant-features" role="list">
            <?php
            foreach ($this->featureItems as $index => $featureItem) {
                if (!empty($featureItem)) : ?>

                    <li class="fluent-cart-pricing-table-variant-feature" role="listitem">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18" fill="none"
                             aria-hidden="true">
                            <path d="M17.3332 9.00004C17.3332 4.39767 13.6022 0.666706 8.99984 0.666706C4.39746 0.666706 0.666504 4.39767 0.666504 9.00004C0.666504 13.6024 4.39746 17.3334 8.99984 17.3334C13.6022 17.3334 17.3332 13.6024 17.3332 9.00004Z"
                                  fill="currentColor"></path>
                            <path d="M5.6665 9.62502C5.6665 9.62502 6.99984 10.3855 7.6665 11.5C7.6665 11.5 9.6665 7.12502 12.3332 5.66669"
                                  stroke="white" stroke-width="1.5" stroke-linecap="round"
                                  stroke-linejoin="round"></path>
                        </svg>

                        <?php echo esc_html($featureItem); ?>
                    </li>

                <?php endif;
            }
            ?>
        </ul>

        <?php

    }

    public function renderCartButton($variant)
    {
        $buttonOptions = Arr::get($this->viewData, 'button_options', '');
        $buttonValues = self::stringToAssocArray($buttonOptions);
        $buttonText = !empty($buttonValues['cartButtonText']) ? $buttonValues['cartButtonText'] : __('Add To Cart', 'fluent-cart');

        if ($variant['stock_status'] === Helper::IN_STOCK) {
            $data = [
                    'add_to_cart_button_text' => $buttonText,
                    'product_id'              => Arr::get($variant, 'id', ''),
                    'quantity'                => 1,
                    'variation_type'          => Arr::get($variant, 'variation_type', ''),
                    'payment_type'            => Arr::get($variant, 'payment_type', ''),
                    'variation_id'          => Arr::get($variant, 'id', ''),
            ];

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in view template
            echo AddToCartShortcode::make()->enqueueAssets()->render($data);
        } else {
            $this->renderOutOfStockButton();
        }

    }

    public function renderOutOfStockButton()
    {
        ?>
        <button
                class="fct-out-of-stock-button"
                disabled
                aria-disabled="true"
                aria-label="<?php
                /* translators: Button aria-label shown when a product is out of stock */
                esc_attr_e('This item is out of stock and cannot be added to cart', 'fluent-cart');
                ?>"
        >
            <?php
            echo esc_html(
                $this->storeSettings->get(
                    'out_of_stock_button_text',
                    __('Not Available', 'fluent-cart')
                )
            );
            ?>
        </button>
        <?php
    }


    public function renderDirectCheckoutButton($variant)
    {
        $directCheckoutButtonText = $this->storeSettings->get('direct_checkout_button_text', __('Buy Now', 'fluent-cart'));

        $buttonOptions = Arr::get($this->viewData, 'button_options', '');
        $buttonValues = self::stringToAssocArray($buttonOptions);
        $buttonText = !empty($buttonValues['text']) ? $buttonValues['text'] : $directCheckoutButtonText;

        $checkoutUrl = add_query_arg([
                'fluent-cart' => 'instant_checkout'
        ], site_url());

        if ($variant['stock_status'] === Helper::IN_STOCK) {

            // Handle product parameters
            $productId = Arr::get($variant, 'id');
            $quantity = Arr::get($this->viewData, 'quantity', 1);

            // Build base parameters
            $params = [
                    'item_id'  => $productId,
                    'quantity' => $quantity,
            ];

            // Merge in any additional params
            $extraParams = Arr::get($this->viewData, 'url_params', '');
            if (!empty($extraParams)) {
                // If it's a query string like "foo=bar&baz=qux"
                parse_str($extraParams, $extraArray);
                $params = array_merge($params, $extraArray);
            }

            $checkoutUrl = add_query_arg($params, $checkoutUrl);

            $data = [
                    'direct_checkout_button_text' => $buttonText,
                    'checkout_url'                => $checkoutUrl,
                    'product_id'                  => Arr::get($variant, 'id'),
                    'is_pricing_table'            => true,
                    'quantity'                    => 1,
                    'variation_type'              => Arr::get($variant, 'variation_type', ''),
                    'stock_availability'          => Arr::get($variant, 'stock_status', ''),
                    'variation_id'                => Arr::get($variant, 'id', ''),
            ];

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in view template
            echo DirectCheckoutShortcode::make()->enqueueAssets()->render($data);
        }
    }

    private static function stringToAssocArray($string): array
    {
        $result = [];

        if (!empty($string)) {
            $pairs = explode(', ', $string);

            foreach ($pairs as $pair) {
                // Check if $pair contains '=' using Str::contains() before splitting
                if (Str::contains($pair, '=')) {
                    list($key, $value) = explode('=', $pair);
                    $result[trim($key)] = trim($value);
                }
            }
        }

        return $result;
    }


}
