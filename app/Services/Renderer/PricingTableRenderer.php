<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\App;
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


    public function __construct($viewData)
    {
        $this->viewData = $viewData;
        $this->variants = $this->viewData['variants'];
        $this->storeSettings = new StoreSettings();

        $signName = $this->storeSettings->get('currency');

        $this->sign = CurrenciesHelper::getCurrencySign($signName);

        $this->showCartButton = Arr::get($this->viewData, 'show_cart_button', 1);


    }

    public function render()
    {

        ?>
        <div class="fluent-cart-pricing-table" data-fluent-cart-pricing-table role="region"
             aria-label="<?php esc_attr_e('Pricing table', 'fluent-cart'); ?>">
            <div class="fluent-cart-pricing-table-variants-iterator" role="list">
                <?php $this->renderVariant(); ?>
            </div>
        </div>
        <?php
    }

    public function renderVariant()
    {


        foreach ($this->variants as $index => $variant) {
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

            ?>

            <div class="fluent-cart-pricing-table-variant" data-fluent-cart-pricing-table-variant role="listitem">

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
                    __('Out of stock', 'fluent-cart')
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
            ];
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
