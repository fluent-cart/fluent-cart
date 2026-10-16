<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors\Checkout;

use FluentCart\App\Helpers\Helper;
use FluentCart\Api\CurrencySettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Hooks\Handlers\BlockEditors\BlockEditor;
use FluentCart\App\Hooks\Handlers\BlockEditors\Checkout\InnerBlocks\InnerBlocks;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\CartRenderer;
use FluentCart\App\Services\Renderer\CheckoutRenderContext;
use FluentCart\App\Services\Renderer\CheckoutRenderer;
use FluentCart\App\Services\Renderer\RenderHelper;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;


class CheckoutBlockEditor extends BlockEditor
{
    protected static string $editorName = 'checkout';

    public function getScripts(): array
    {
        return [
                [
                        'source'       => 'admin/BlockEditor/Checkout/CheckoutBlockEditor.jsx',
                        'dependencies' => ['wp-blocks', 'wp-components']
                ]
        ];
    }

    public function getStyles(): array
    {
        return [
                'admin/BlockEditor/Checkout/style/checkout-block-editor.scss',
                'public/checkout/style/checkout.scss'
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerInnerBlocks();
    }

    public function registerInnerBlocks()
    {
        InnerBlocks::register();
    }

    public function localizeData(): array
    {

        return [
                $this->getLocalizationKey()     => [
                        'slug'              => $this->slugPrefix,
                        'name'              => static::getEditorName(),
                        'title'             => __('Checkout Page', 'fluent-cart'),
                        'description'       => __('This block will display the checkout page.', 'fluent-cart'),
                        'placeholder_image' => Helper::getProductPlaceholderUrl(),

                        // Whether the add-on that supplies the product-selector
                        // panel is present. When it is not, this block shows a
                        // short description of what the panel would do instead
                        // of silently offering nothing — the checkout is where
                        // an author goes looking for it.
                        'is_pro_active'     => App::isProActive(),
                        'upgrade_url'       => 'https://fluentcart.com/pricing/',
                ],
                'fluent_cart_block_translation' => TransStrings::blockStrings()
        ];
    }

    protected function skipInnerBlocks(): bool
    {
        return true;
    }

    public function render(array $shortCodeAttribute, $block = null, $content = null): string
    {
        /**
         * Let an add-on supply the cart this checkout renders from — an offer
         * page with its own basket, a one-click upsell, anything that is not
         * the device's cart. Returning null keeps the shipped behaviour.
         *
         * Resolved BEFORE loadCheckoutAssets() on purpose: the asset loader
         * localizes cart_hash and the totals against whatever cart it is given,
         * and localizeCheckoutData() runs once per request. Resolving after it
         * would ship the shopper's own cart to a page rendering someone else's.
         *
         * @param \FluentCart\App\Models\Cart|null $cart
         * @param array $context ['surface' => string, 'attributes' => array, 'block' => \WP_Block|null]
         */
        $context = [
                'surface'    => 'checkout-block',
                'attributes' => $shortCodeAttribute,
                'block'      => $block,
        ];

        $cart = apply_filters('fluent_cart/checkout/render_cart', null, $context);

        if (!$cart && CheckoutRenderer::isCustomProductCheckout($shortCodeAttribute)) {
            return CheckoutRenderer::renderCustomProductCheckout($context);
        }

        AssetLoader::loadCheckoutAssets($cart ?: null);

        if (!$cart) {
            $cart = CartHelper::getCart();
        }

        return static::renderCart($cart, $shortCodeAttribute, $block);
    }

    /**
     * Render the checkout block for an already resolved cart. Checkout assets
     * must already be loaded for that cart.
     *
     * @param \FluentCart\App\Models\Cart|null $cart
     * @param array $shortCodeAttribute
     * @param \WP_Block|null $block
     * @return string
     */
    public static function renderCart($cart, array $shortCodeAttribute, $block = null): string
    {
//        return '[fluent_cart_checkout]';
        global $wp;
        $current_url = home_url(add_query_arg([], $wp->request));
        $current_url = add_query_arg(array_map('sanitize_text_field', wp_unslash($_GET)), $current_url);

        if (!$cart || empty(Arr::get($cart, 'cart_data', []))) {
            // Same filter as the shortcode checkout; see CheckoutPageHandler::render().
            $emptyMessage = apply_filters('fluent_cart/checkout/empty_message', null, [
                'surface'    => 'checkout-block',
                'attributes' => $shortCodeAttribute,
            ]);

            ob_start();
            (new CartRenderer())->renderEmpty($emptyMessage);
            return ob_get_clean();
        }


        $classNames = [
                'fluent-cart-checkout-page',
                'fct-checkout alignwide',
                'fct-checkout-type-' . $cart->cart_group
        ];

        $atts = [
                'class'                          => implode(' ', $classNames),
                'data-fluent-cart-checkout-page' => '',
        ];

        $formAttributes = [
                'method'                                       => 'POST',
                'data-fluent-cart-checkout-page-checkout-form' => '',
                'class'                                        => 'fct_checkout fluent-cart-checkout-page-checkout-form',
                'action'                                       => $current_url,
                'enctype'                                      => 'multipart/form-data',
        ];


        $labelStyle = CheckoutRenderer::resolveFieldLabelStyle(Arr::get($shortCodeAttribute, 'field_label_style', ''));

        // Inner blocks resolve their cart through CheckoutRenderContext rather
        // than each re-reading the device's cart, so a checkout rendering from
        // a cart supplied by the filter above stays coherent all the way down.
        return CheckoutRenderContext::rendering(
                $cart,
                ['surface' => 'checkout-block', 'attributes' => $shortCodeAttribute],
                function () use ($cart, $block, $formAttributes, $labelStyle) {
                    ob_start();
                    (new CheckoutRenderer($cart, ['field_label_style' => $labelStyle]))->wrapperStart();
                    $wrapperStartHtml = ob_get_clean();

                    $innerBlocksContent = '';

                    if (empty($block->inner_blocks) && version_compare(FLUENTCART_VERSION, '1.3.0', '>')) {
                        return '[fluent_cart_checkout field_label_style="' . esc_attr($labelStyle) . '"]';
                    } else if ($block instanceof \WP_Block && !empty($block->inner_blocks)) {
                        ob_start();
                        ?>
            <form <?php RenderHelper::renderAtts($formAttributes); ?>>
                <div class="fct_block_checkout">
                    <?php
                    foreach ($block->inner_blocks as $inner_block) {
                        if (isset($inner_block->parsed_block)) { ?>
                            <div class="fluent-cart-checkout-block-child-wrap">
                                <?php echo $inner_block->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP_Block::render() output ?>
                            </div>
                            <?php
                        }
                    }
                    ?>
                </div>
            </form>
            <?php
                        $innerBlocksContent = ob_get_clean();
                    }

                    ob_start();
                    (new CheckoutRenderer($cart))->wrapperEnd();
                    $wrapperEndHtml = ob_get_clean();

                    return $wrapperStartHtml . $innerBlocksContent . $wrapperEndHtml;
                }
        );
    }

    /**
     * Returns the default `addressModal`
     *
     * @return array
     */
    public static function getDefaultAddressModal(): array
    {
        return [
                'billingAddress'   => __('Billing Address', 'fluent-cart'),
                'shippingAddress'  => __('Shipping Address', 'fluent-cart'),
                'openButtonText'   => __('Change', 'fluent-cart'),
                'addButtonText'    => __('Add Address', 'fluent-cart'),
                'applyButtonText'  => __('Apply', 'fluent-cart'),
                'submitButtonText' => __('Submit', 'fluent-cart'),
                'cancelButtonText' => __('Cancel', 'fluent-cart')
        ];
    }

    /**
     * Returns the default `SippingMethods`
     *
     * @return array
     */
    public static function getDefaultSippingMethods(): array
    {
        return [
                'heading' => __('Shipping Method', 'fluent-cart')
        ];
    }

    /**
     * Returns the default `PaymentMethods`
     *
     * @return array
     */
    public static function getDefaultPaymentMethods(): array
    {
        return [
                'heading' => __('Payment', 'fluent-cart')
        ];
    }

    /**
     * Returns the default `orderSummary`
     *
     * @return array
     */
    public static function getDefaultOrderSummary(): array
    {
        return [
                'toggleButtonText' => __('View Items', 'fluent-cart'),
                'removeButtonText' => __('Remove', 'fluent-cart'),
                'totalText'        => __('Total', 'fluent-cart'),
                'heading'          => __('Summary', 'fluent-cart'),
                'maxVisibleItems'  => 2,
                'showRemoveButton' => true,
                'coupons'          => self::getDefaultCoupons()
        ];
    }

    /**
     * Returns the default `coupons`
     *
     * @return array
     */
    public static function getDefaultCoupons(): array
    {
        return [
                'iconVisibility' => true,
                'placeholder'    => __('Apply Here', 'fluent-cart'),
                'applyButton'    => __('Apply', 'fluent-cart'),
                'label'          => __('Have a Coupon?', 'fluent-cart'),
                'collapsible'    => true
        ];
    }

    /**
     * Returns the default `submitButton`
     *
     * @return array
     */
    public static function getDefaultSubmitButton(): array
    {
        return [
                'text'      => __('Place Order', 'fluent-cart'),
                'alignment' => 'left',
                'size'      => 'large',
                'full'      => true
        ];
    }

    /**
     * Returns the default `AllowCreateAccount`
     *
     * @return array
     */
    public static function getDefaultAllowCreateAccount(): array
    {
        return [
                'label'    => __('Create my user account', 'fluent-cart'),
                'infoText' => __('By checking this box, you agree to create an account with us to manage your subscription and order details. This is mandatory for subscription-based purchases.', 'fluent-cart')
        ];
    }

    /**
     * Safely encode JSON strings if needed
     *
     * @param mixed $value The value to process
     * @return mixed The processed value (encoded if it was an array or object)
     */
    protected function maybeEncodeJson($value)
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        return $value;
    }

}
