<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Api\PaymentMethods;
use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\Resource\FrontendResource\CustomerAddressResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\App\Services\URL;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\ProductMeta;

class ModalCheckoutRenderer
{
    private $cart;

    private $requireShipping;

    private $hasSubscription = false;

    private $config = [];

    private $storeSettings;

    private $productMeta;

    private $matchedVariation;

    private $checkoutRenderer;

    private $cartSummaryRenderer;

    private $customer;

    /**
     * Memoised result of the before_payment_methods placement filter. Null until
     * resolved; see beforePaymentMethodsPlacement() for why it is asked only once.
     *
     * @var string|null
     */
    private $paymentMethodsHookPlacement = null;

    public function __construct(Cart $cart, $config = [])
    {
        $this->cart = $cart;
        $this->config = $config;
        $this->requireShipping = $cart->requireShipping();
        $this->storeSettings = new StoreSettings();
        $this->checkoutRenderer = new CheckoutRenderer($cart);
        $this->cartSummaryRenderer = new CartSummaryRender($cart);
        $this->hasSubscription = $cart->hasSubscription();
        $this->customer = CustomerResource::getCurrentCustomer();

        $this->productMeta = ProductMeta::query()->where('object_id', Arr::get($this->cart->cart_data, '0.post_id'))->where('meta_key', 'license_settings')->first();

        $variations = $this->productMeta->meta_value['variations'] ?? [];

        $cartItem = $this->cart->cart_data[0] ?? [];
        $objectId = $cartItem['object_id'] ?? null;

        $this->matchedVariation = $objectId && isset($variations[$objectId])
                ? $variations[$objectId]
                : null;
    }

    public function getFragment($fragmentName) 
    {
        $maps = [
                'payment_methods' => 'renderPaymentMethods',
                'summary_group' => 'renderSummaryGroup',
                'checkout_summary' => 'renderCheckoutSummary'
        ];

        if(isset($maps[$fragmentName])) {
            ob_start();
            $this->{$maps[$fragmentName]}();
            return ob_get_clean();
        }

        return '';

    }

    public function render() 
    {
        ?>
            <div class="fct-checkout-modal-container" data-fct-checkout-modal-container style="opacity: 0; visibility: hidden;">
                <div class="fct-checkout-modal" data-fct-checkout-modal>
                    <div class="fct-checkout-modal-loader" data-fct-checkout-modal-loader>
                        <div class="fct-checkout-modal-loader-spinner"></div>
                    </div>
                    <button class="fct-checkout-modal-close" data-fct-checkout-modal-close aria-label="<?php echo esc_attr__('Close checkout', 'fluent-cart');?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                    <div class="fct-checkout-modal-content">
                        <?php $this->renderIframe();?>
                    </div>
                </div>
            </div>
        <?php
    }

    public function renderIframe() 
    {
        ?>
            <iframe class="fct-checkout-modal-iframe" data-fct-checkout-modal-iframe title="<?php echo esc_attr__('Checkout', 'fluent-cart');?>" frameborder="0" allowtransparency="true" spellcheck="false"></iframe>
        <?php
    }

    public function renderForm()
    {
        // Without a billing country in checkout_data, the tax recalc below
        // produces no per-item tax. Pre-fill from the current customer's
        // primary billing address (if any) so the per-item tax badge can
        // render on the very first modal open instead of only after the
        // customer interacts with the address form.
        $this->maybePopulateFormDataFromCustomer();

        // Trigger the recalc only when the cart hasn't been taxed yet. Once
        // line_meta.tax_config is on the item, the badge already renders from
        // existing data — another recalc here would just duplicate work
        // (Tax + Shipping each call $cart->save() on this action).
        // Payload shape matches Cart::addItem() so every handler receives the
        // data it expects.
        if (empty(Arr::get($this->cart->cart_data, '0.line_meta.tax_config'))) {
            do_action('fluent_cart/cart/cart_data_items_updated', [
                'cart'       => $this->cart,
                'scope'      => 'modal_open',
                'scope_data' => null,
            ]);
        }
        ?>
            <div class="fct-modal-checkout-form-wrapper" data-fluent-cart-checkout-page>
                <form
                    class="fct-modal-checkout-form"
                    action="<?php echo esc_url(home_url('/')); ?>"
                    method="POST"
                    data-fluent-cart-checkout-page-checkout-form
                    enctype="multipart/form-data"
                    aria-label="<?php esc_attr_e('Checkout Form', 'fluent-cart'); ?>"
                >
                    <div class="fct-modal-checkout-form-inner" data-fct-modal-checkout-form-inner>
                        <?php $this->renderCheckoutDetails(); ?>
                        <?php $this->renderCheckoutBilling(); ?>
                    </div>
                </form>
            </div>
        <?php
    }


    private function maybePopulateFormDataFromCustomer()
    {
        if (!$this->customer) {
            return;
        }

        $checkoutData = is_array($this->cart->checkout_data) ? $this->cart->checkout_data : [];
        $formData     = Arr::get($checkoutData, 'form_data', []);

        // Never clobber an answer the customer has actively chosen; only
        // pre-fill when the cart has no billing country yet.
        if (!empty(Arr::get($formData, 'billing_country'))) {
            return;
        }

        $primaryBilling = $this->customer->primary_billing_address;
        if (!$primaryBilling) {
            return;
        }

        foreach ($primaryBilling->getFormattedDataForCheckout('billing_') as $key => $value) {
            if (!isset($formData[$key]) || $formData[$key] === '' || $formData[$key] === null) {
                $formData[$key] = $value;
            }
        }

        $checkoutData['form_data'] = $formData;
        $this->cart->checkout_data = $checkoutData;
        // No explicit save — the recalc action that follows persists this
        // together with the newly computed tax data, avoiding a double write.
    }

    /**
     * Where `fluent_cart/before_payment_methods` fires in the modal.
     *
     * 'payment' (default) wraps the payment-method list, matching CheckoutRenderer.
     * 'details' restores the pre-fix position in the address/shipping pane, for a
     * store whose add-on positioned itself around that spot.
     *
     * Resolved ONCE per renderer and memoised. The two call sites are mutually
     * exclusive, so the hook must fire exactly once — but that only holds if both
     * sites agree, and a filter is free to be stateful or context-sensitive. Asking
     * it twice would let it answer differently and either fire the hook twice or
     * swallow it entirely. Ask once, remember the answer.
     *
     * @return string 'payment'|'details'
     */
    private function beforePaymentMethodsPlacement(): string
    {
        if ($this->paymentMethodsHookPlacement !== null) {
            return $this->paymentMethodsHookPlacement;
        }

        $placement = apply_filters('fluent_cart/modal_checkout/before_payment_methods_placement', 'payment', [
            'cart' => $this->cart,
        ]);

        // Anything unrecognised falls back to the correct placement rather than
        // silently dropping the hook.
        $this->paymentMethodsHookPlacement = $placement === 'details' ? 'details' : 'payment';

        return $this->paymentMethodsHookPlacement;
    }

    public function renderCheckoutDetails()
    {
        ?>
            <div class="fct-modal-checkout-details">
                <!-- Stable swap target: tax recalculation re-renders the item card
                     here via the checkout_summary fragment. -->
                <div data-fct-modal-checkout-summary>
                    <?php $this->renderCheckoutSummary(); ?>
                </div>

                <?php $this->checkoutRenderer->renderAddressFields(); ?>

                <div class="fct_checkout_shipping_methods <?php echo $this->requireShipping ? '' : 'is-hidden' ?>">
                    <?php $this->checkoutRenderer->renderShippingOptions(); ?>
                </div>

                <?php
                    /**
                     * The position `fluent_cart/before_payment_methods` used to
                     * occupy in the modal. Given its own name so an add-on that
                     * genuinely wants this pane — under shipping, above the terms
                     * — has a stable place to render, instead of relying on a hook
                     * whose name promises it sits before the payment methods.
                     *
                     * @param array $data ['cart' => Cart]
                     */
                    do_action('fluent_cart/modal_checkout/after_shipping_methods', ['cart' => $this->cart]);

                    // Opt-in restoration of the old placement, for a store whose
                    // add-on positioned itself around the previous spot. Mutually
                    // exclusive with the call site in renderCheckoutBilling().
                    if ($this->beforePaymentMethodsPlacement() === 'details') {
                        do_action('fluent_cart/before_payment_methods', ['cart' => $this->cart]);
                    }
                ?>

                <?php $this->checkoutRenderer->agreeTerms(); ?>

                <?php $this->renderSummaryGroup(); ?>

                <?php //$this->renderCheckoutReviews();?>
            </div>
        <?php
    }

    public function renderCheckoutSummary() 
    {
        $title = Arr::get($this->cart->cart_data, '0.title', '');
        $postTitle = Arr::get($this->cart->cart_data, '0.post_title', '');
        $subTotal          = Helper::toDecimal(Arr::get($this->cart->cart_data, '0.subtotal', 0));
        $couponDiscount    = (int) Arr::get($this->cart->cart_data, '0.coupon_discount', 0);
        $subtotalRaw       = (int) Arr::get($this->cart->cart_data, '0.subtotal', 0);
        $lineTotalRaw      = (int) Arr::get($this->cart->cart_data, '0.line_total', 0);
        $hasCouponDiscount = $couponDiscount > 0 && $lineTotalRaw < $subtotalRaw;
        $media = Arr::get($this->cart->cart_data, '0.featured_media', '');

        // Mirror CartItemRenderer's event payload so the shared line-item hooks
        // (e.g. the per-item tax breakdown) also fire in modal checkout.
        $lineItemEventInfo = [
            'item'    => Arr::get($this->cart->cart_data, '0', []),
            'cart'    => $this->cart,
            'product' => null,
            'variant' => null,
        ];

        ?>
            <div class="fct-modal-checkout-summary">
                <div class="fct-modal-cs-img">
                    <img src="<?php echo esc_url($media);?>" alt="<?php echo esc_attr($postTitle);?>">
                </div>

                <div class="fct-modal-cs-content">
                    <div class="fct-modal-cs-line-item">
                        <div class="fct-modal-cs-item-content">
                            <h2 class="fct-modal-cs-item-name">
                                 <?php echo esc_html($postTitle);?>
                            </h2>
                            <h3 class="fct-modal-cs-item-variation">
                                - <?php echo esc_html($title);?>
                            </h3>
                        </div>

                        <?php if ($hasCouponDiscount) : ?>
                            <div class="fct-modal-cs-price-wrapper">
                                <span class="fct-modal-cs-line-price fct-modal-cs-line-price--original" aria-label="<?php esc_attr_e('Original price', 'fluent-cart'); ?>">
                                    <?php echo esc_html(Helper::toDecimal($subtotalRaw)); ?>
                                </span>
                                <span class="fct-modal-cs-line-price fct-modal-cs-line-price--discounted" aria-label="<?php esc_attr_e('Discounted price', 'fluent-cart'); ?>">
                                    <?php echo esc_html(Helper::toDecimal($lineTotalRaw)); ?>
                                </span>
                            </div>
                        <?php else : ?>
                            <span class="fct-modal-cs-line-price">
                                <?php echo esc_html($subTotal); ?>
                            </span>
                        <?php endif; ?>
                        <?php do_action('fluent_cart/cart/line_item/after_total', $lineItemEventInfo); ?>
                    </div>

                    <?php do_action('fluent_cart/cart/line_item/footer_start', $lineItemEventInfo); ?>

                    <?php $this->renderPaymentTypeInfo(); ?>

                    <?php if($this->matchedVariation) :?>
                        <div class="fct-modal-cs-license">
                            <?php
                            $limit = Arr::get($this->matchedVariation, 'activation_limit');

                            if (empty($limit) || (int) $limit === 0) {
                                echo esc_html__('Unlimited Activation', 'fluent-cart');
                            } else {
                                echo esc_html(sprintf(__('Activation limit %d', 'fluent-cart'), $limit));
                            }
                            ?>
                        </div>
                    <?php endif;?>


                </div>
            </div>

        <?php

    }

    public function renderPaymentTypeInfo() {
        $otherInfo = Arr::get($this->cart->cart_data, '0.other_info', []);
        $paymentType = Arr::get($otherInfo, 'payment_type', '');
        $itemPrice = Arr::get($this->cart->cart_data, '0.unit_price', 0);

        if ($paymentType === 'subscription') {
            $subscriptionInfo = Helper::generateSubscriptionInfo($otherInfo, $itemPrice);
            $setupFeeInfo = Helper::generateSetupFeeInfo($otherInfo);
            $trialInfo = Helper::generateTrialInfo($otherInfo);
            ?>
                <div class="fct-modal-cs-payment-info">
                    <span class="sr-only">
                        <?php esc_html_e('Payment information', 'fluent-cart'); ?>
                    </span>

                    <span>
                        <?php echo wp_kses($subscriptionInfo, ['del' => true]); ?>&#44;
                    </span>

                    <?php if ($trialInfo): ?>
                        <span class="trial-days">
                            <?php echo esc_html($trialInfo); ?>&#44;
                        </span>
                    <?php endif; ?>

                    <?php if (!empty($setupFeeInfo)): ?>
                        <span class="setup-fee">
                            <?php echo esc_html($setupFeeInfo); ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php
            return;
        }

        $quantity = (int) Arr::get($this->cart->cart_data, '0.quantity', 1);
        if ($quantity < 2) {
            return;
        }

        $lineItemEventInfo = [
            'item'    => Arr::get($this->cart->cart_data, '0', []),
            'cart'    => $this->cart,
            'product' => null,
            'variant' => null,
        ];
        ?>
        <div class="fct-modal-cs-payment-info">
            <?php
            /* translators: %1$s: formatted unit price */
            printf(esc_html__('%1$s each', 'fluent-cart'), esc_html(Helper::toDecimal($itemPrice)));
            ?>
            <?php do_action('fluent_cart/cart/line_item/unit_price_hint', $lineItemEventInfo); ?>
        </div>
        <?php
    }

    public function renderPromoCode() {
        ?>
            <div class="fct-modal-checkout-promo-code">
                <div class="fct-modal-input-control-wrap" data-fct-modal-input-control-wrap>
                    <button type="button" class="fct-modal-input-toggle-btn" data-fct-modal-input-toggle-btn>
                        <?php echo esc_html__('Have a promotional code?', 'fluent-cart');?>
                    </button>

                    <div class="fct-modal-input-controls fct-hidden" data-fct-modal-input-controls>
                                <span class="fct-input-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                                    <path fill="currentColor" d="M336 352c97.2 0 176-78.8 176-176S433.2 0 336 0 160 78.8 160 176c0 18.7 2.9 36.8 8.3 53.7L7 391c-4.5 4.5-7 10.6-7 17l0 80c0 13.3 10.7 24 24 24l80 0c13.3 0 24-10.7 24-24l0-40 40 0c13.3 0 24-10.7 24-24l0-40 40 0c6.4 0 12.5-2.5 17-7l33.3-33.3c16.9 5.4 35 8.3 53.7 8.3zM376 96a40 40 0 1 1 0 80 40 40 0 1 1 0-80z"/></svg>
                                </span>

                        <input
                                class="fct-input-field"
                                id="coupon"
                                type="text"
                                name="coupon"
                                placeholder="<?php echo esc_attr__('Enter promotion code', 'fluent-cart');?>"
                        >


                        <button type="button" class="fct-input-submit-btn" data-fluent-cart-checkout-page-coupon-validate>
                            <?php echo esc_html__('Apply', 'fluent-cart');?>
                        </button>
                    </div>
                </div>
            </div>
        <?php
    }
    public function renderSummaryGroup() {
        ?>

            <div class="fct-modal-checkout-summary-group" data-fct-modal-checkout-summary-group>
                <?php
                    $this->cartSummaryRenderer->renderItemsFooter();
                ?>
            </div>

        <?php

    }


    public function showCouponApplied()
    {
        $discounts = $this->cart->getDiscountLines();

        if (!$discounts) {
            return;
        }

        ?>

        <div class="fct-modal-coupon-applied" data-fluent-cart-checkout-page-discount-container>
            <?php foreach ($discounts as $couponCode => $discount_data): ?>
                <div class="fct-coupon-applied-item">
                    <div class="fct-coupon-info">
                        <span class="fct-coupon">
                            <span class="fct-coupon-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M32.5 96l0 149.5c0 17 6.7 33.3 18.7 45.3l192 192c25 25 65.5 25 90.5 0L483.2 333.3c25-25 25-65.5 0-90.5l-192-192C279.2 38.7 263 32 246 32L96.5 32c-35.3 0-64 28.7-64 64zm112 16a32 32 0 1 1 0 64 32 32 0 1 1 0-64z"/></svg>
                            </span>

                            <?php echo esc_html($discount_data['formatted_title']) ?>

                            <a
                                href="#"
                                class="fct-remove-coupon"
                                data-remove-coupon
                                data-coupon="<?php echo esc_attr($couponCode); ?>"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 384 512"><path d="M55.1 73.4c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L147.2 256 9.9 393.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0L192.5 301.3 329.9 438.6c12.5 12.5 32.8 12.5 45.3 0s12.5-32.8 0-45.3L237.8 256 375.1 118.6c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0L192.5 210.7 55.1 73.4z"/></svg>
                            </a>
                        </span>
                        <span class="fct-coupon-text">
                           <?php echo esc_html__( 'Coupon discount', 'fluent-cart'); ?>
                        </span>
                    </div>
                    <div class="fct-coupon-price">
                        &#8211;<?php echo esc_html($discount_data['actual_formatted_discount']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php
    }

    public function renderCheckoutReviews() {
        ?>
        <div class="fct-modal-checkout-reviews-wrap">
            <div class="fct-modal-checkout-reviews">
                <div class="fct-reviews-picture">
                    <img width="40" height="40" src="https://images.pexels.com/photos/23885849/pexels-photo-23885849.jpeg" alt="">
                </div>
                <div class="fct-reviews-content">
                    <div class="fct-reviews-text">
                        I got the annual unlimited websites premium version and I'm upgrading to the Lifetime Unlimited Websites plan real soon. Building WordPress website
                    </div>
                    <div class="fct-reviews-meta">
                        <div class="fct-review-stars">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512">
                                <path fill="currentColor" d="M309.5-18.9c-4.1-8-12.4-13.1-21.4-13.1s-17.3 5.1-21.4 13.1L193.1 125.3 33.2 150.7c-8.9 1.4-16.3 7.7-19.1 16.3s-.5 18 5.8 24.4l114.4 114.5-25.2 159.9c-1.4 8.9 2.3 17.9 9.6 23.2s16.9 6.1 25 2L288.1 417.6 432.4 491c8 4.1 17.7 3.3 25-2s11-14.2 9.6-23.2L441.7 305.9 556.1 191.4c6.4-6.4 8.6-15.8 5.8-24.4s-10.1-14.9-19.1-16.3L383 125.3 309.5-18.9z"/>
                            </svg>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512">
                                <path fill="currentColor" d="M309.5-18.9c-4.1-8-12.4-13.1-21.4-13.1s-17.3 5.1-21.4 13.1L193.1 125.3 33.2 150.7c-8.9 1.4-16.3 7.7-19.1 16.3s-.5 18 5.8 24.4l114.4 114.5-25.2 159.9c-1.4 8.9 2.3 17.9 9.6 23.2s16.9 6.1 25 2L288.1 417.6 432.4 491c8 4.1 17.7 3.3 25-2s11-14.2 9.6-23.2L441.7 305.9 556.1 191.4c6.4-6.4 8.6-15.8 5.8-24.4s-10.1-14.9-19.1-16.3L383 125.3 309.5-18.9z"/>
                            </svg>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512">
                                <path fill="currentColor" d="M309.5-18.9c-4.1-8-12.4-13.1-21.4-13.1s-17.3 5.1-21.4 13.1L193.1 125.3 33.2 150.7c-8.9 1.4-16.3 7.7-19.1 16.3s-.5 18 5.8 24.4l114.4 114.5-25.2 159.9c-1.4 8.9 2.3 17.9 9.6 23.2s16.9 6.1 25 2L288.1 417.6 432.4 491c8 4.1 17.7 3.3 25-2s11-14.2 9.6-23.2L441.7 305.9 556.1 191.4c6.4-6.4 8.6-15.8 5.8-24.4s-10.1-14.9-19.1-16.3L383 125.3 309.5-18.9z"/>
                            </svg>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512">
                                <path fill="currentColor" d="M309.5-18.9c-4.1-8-12.4-13.1-21.4-13.1s-17.3 5.1-21.4 13.1L193.1 125.3 33.2 150.7c-8.9 1.4-16.3 7.7-19.1 16.3s-.5 18 5.8 24.4l114.4 114.5-25.2 159.9c-1.4 8.9 2.3 17.9 9.6 23.2s16.9 6.1 25 2L288.1 417.6 432.4 491c8 4.1 17.7 3.3 25-2s11-14.2 9.6-23.2L441.7 305.9 556.1 191.4c6.4-6.4 8.6-15.8 5.8-24.4s-10.1-14.9-19.1-16.3L383 125.3 309.5-18.9z"/>
                            </svg>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512">
                                <path fill="currentColor" d="M309.5-18.9c-4.1-8-12.4-13.1-21.4-13.1s-17.3 5.1-21.4 13.1L193.1 125.3 33.2 150.7c-8.9 1.4-16.3 7.7-19.1 16.3s-.5 18 5.8 24.4l114.4 114.5-25.2 159.9c-1.4 8.9 2.3 17.9 9.6 23.2s16.9 6.1 25 2L288.1 417.6 432.4 491c8 4.1 17.7 3.3 25-2s11-14.2 9.6-23.2L441.7 305.9 556.1 191.4c6.4-6.4 8.6-15.8 5.8-24.4s-10.1-14.9-19.1-16.3L383 125.3 309.5-18.9z"/>
                            </svg>
                        </div>
                        <div class="fct-review-author-info">
                            <span class="fct-author-name">Bryson Suleiman,</span>
                            <span class="fct-author-role">Founder and WordPress Expert at Webbryson</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php

    }


    public function renderCheckoutBilling() {
        $user = wp_get_current_user();
        $formRender = new FormFieldRenderer();
        $fullName = trim(
                ($this->customer->first_name ?? '') . ' ' . ($this->customer->last_name ?? '')
        );

        if (!$fullName && $user) {
            $fullName = $user->display_name;
        }

        $fieldsSchema = CheckoutFieldsSchema::getFieldsSettings();

        $fullNameField = Arr::get($fieldsSchema, 'basic_info.full_name', []);
        $emailField = Arr::get($fieldsSchema, 'basic_info.email', []);
        $isRequiredFullName = Arr::get($fullNameField, 'required', 'no') === 'yes' ? 'yes' : '';
        $isRequiredEmail = Arr::get($emailField, 'required', 'no') === 'yes' ? 'yes' : '';

        ?>
        <div class="fct-modal-checkout-billing-wrap">
            <!-- Account Details -->
            <div class="fct-modal-account-details" data-fct-checkout-form-section>
                <div class="fct-modal-form-info">
                    <div class="fct-modal-form-field col-6">
                        <?php
                        $formRender->renderField([
                                'label' => esc_attr__('Full name', 'fluent-cart') . ($isRequiredFullName ? ' *' : ''),
                                'id'             => 'billing_full_name',
                                'type'           => 'text',
                                'placeholder'    => __('Jon Doe', 'fluent-cart'),
                                'name'           => 'billing_full_name',
                                'autocomplete'   => 'given-name',
                                'aria-label' => esc_attr__('Full name', 'fluent-cart'),
                                'required'       => $isRequiredFullName,
                                'value'          => $fullName
                        ]);
                        ?>
                    </div>
                    <div class="fct-modal-form-field col-6">
                        <?php
                        $formRender->renderField([
                                'label' => esc_attr__('Email address', 'fluent-cart') . ($isRequiredEmail ? ' *' : ''),
                                'id'             => 'billing_email',
                                'type'           => 'text',
                                'placeholder'    => 'jon.doe@example.com',
                                'name'           => 'billing_email',
                                'autocomplete'   => 'email',
                                'required'       => $isRequiredEmail,
                                'aria-label' => esc_attr__('Email address', 'fluent-cart'),
                                'value'          => $this->customer->email ?? $user->user_email,
                                'disabled'       => (bool)($this->customer->email ?? $user->user_email)
                        ]);
                        ?>
                    </div>

<!--                    <div class="fct-modal-form-field col-3">-->
<!--                        --><?php
//                        $formRender->renderField([
//                                'label'          => __('Last name', 'fluent-cart'),
//                                'id'             => 'billing_last_name',
//                                'type'           => 'text',
//                                'placeholder'    => __('Doe', 'fluent-cart'),
//                                'name'           => 'billing_last_name',
//                                'autocomplete'   => 'given-name',
//                                'required'       => true,
//                                'value'          => $this->customer->last_name ?? ''
//                        ]);
//                        ?>
<!--                    </div>-->
                </div>

                <?php $this->checkoutRenderer->renderCreateAccountField(); ?>

                <!-- Error Message -->
                <div class="fct_form_error fct_error_billing_personal_information_section"></div>
            </div>

            <!-- Billing Details -->
            <div class="fct-modal-billing-details">
                <header class="fct-modal-billing-header">
                    <h4 class="fct-modal-billing-title">
                        <?php echo esc_html__('Billing information', 'fluent-cart');?>
                    </h4>
                </header>

                <?php
                    /**
                     * Fired here, wrapping the payment methods, exactly as
                     * CheckoutRenderer does. It used to fire from
                     * renderCheckoutDetails() — the address/shipping pane — which
                     * is a different part of the modal entirely, so anything
                     * hooked to it (the saved-payment-method picker, Turnstile)
                     * rendered detached from the methods it belongs to. A hook
                     * named "before payment methods" has to fire before the
                     * payment methods.
                     */
                    if ($this->beforePaymentMethodsPlacement() === 'payment') {
                        do_action('fluent_cart/before_payment_methods', ['cart' => $this->cart]);
                    }
                ?>

                <div class="fct_checkout_payment_methods" data-fluent-cart-checkout-payment-methods>
                    <?php $this->renderPaymentMethods(); ?>
                </div>

                <?php
                    // The modal never fired this at all, so add-ons rendering
                    // below the methods (the save-my-card consent box) were
                    // simply absent from quick checkout.
                    do_action('fluent_cart/after_payment_methods', ['cart' => $this->cart]);
                ?>

                <div class="fct-modal-checkout-btn-wrap">
                    <?php (new CheckoutRenderer($this->cart))->renderCheckoutButton(); ?>
                </div>
            </div>
        </div>

        <?php

    }

    public function renderPaymentMethods() 
    {
        if ($this->cart->getEstimatedTotal() <= 0) {
            if (!$this->cart->hasSubscription() || $this->cart->getEstimatedRecurringTotal() <= 0) {
                return '';
            }
        }

        $selectedPaymentMethod = Arr::get($this->cart->checkout_data, 'form_data._fct_pay_method', '');
        $activePaymentMethods = PaymentMethods::getActiveMethodInstance($this->cart);
        $hadActiveMethods = !empty($activePaymentMethods);

        $activePaymentMethods = apply_filters('fluent_cart/checkout_active_payment_methods', $activePaymentMethods, [
                'cart' => $this->cart
        ]);

        // filter the active payment methods to only include the selected payment method
        $selectedModalPaymentMethod = apply_filters('fluent_cart/modal_checkout/filter_active_payment_methods', []);
        if (!empty($selectedModalPaymentMethod)) {
            $activePaymentMethods = array_filter($activePaymentMethods, function ($method) use ($selectedModalPaymentMethod) {
                return in_array($method->getMeta('route'), $selectedModalPaymentMethod);
            });
        }

        if (!$selectedPaymentMethod && !empty($activePaymentMethods)) {
            // reset() not [0] — array_filter above preserves keys, so key 0 may be gone.
            $firstMethod = reset($activePaymentMethods);
            $selectedPaymentMethod = $firstMethod ? $firstMethod->getMeta('route') : '';
        }

        $checkoutMethodStyle = $this->storeSettings->get('checkout_method_style', 'logo');

        ?>
        <div id="fluent_payment_methods" class="fct_modal_payment_methods fluent_payment_methods">
            <!-- Payment Methods -->
            <div class="fct_payment_methods_list fct_payment_method_mode_<?php echo esc_attr($checkoutMethodStyle); ?>">
                <?php if (!empty($activePaymentMethods)): ?>
                    <?php foreach ($activePaymentMethods as $method) {
                        $isSelected = ($selectedPaymentMethod === $method->getMeta('route'));

                        $this->renderPaymentMethod($method, [
                                'selected_id' => $selectedPaymentMethod,
                                'style' => $checkoutMethodStyle,
                                'aria_checked' => $isSelected ? 'true' : 'false',
                                'role' => 'radio'
                        ]);
                    } ?>
                <?php else: ?>
                    <?php
                        if ($hadActiveMethods) {
                            $emptyText = esc_html__('None of the available payment methods can process this order. Please contact the store.', 'fluent-cart');
                        } else {
                            $emptyText = esc_html__('No Payment method is activated for this site yet.', 'fluent-cart');
                        }
                        if (current_user_can('manage_options')) {
                            $emptyText .= '<a href="' . esc_url(URL::getDashboardUrl('settings/payments')) . '" target="_blank">' . esc_html__('Activate from settings.', 'fluent-cart') . '</a>';
                        }
                        echo '<div class="fct-empty-state">' . wp_kses_post($emptyText) . '</div>';
                    ?>
                <?php endif; ?>
            </div>

            <!-- Payment Method Content -->
            <div class="fct_payment_method_contents">
                <?php foreach ($activePaymentMethods as $method) {
                    $this->renderPaymentMethodContent($method, [
                            'style' => $checkoutMethodStyle
                    ]);
                } ?>
            </div>



        </div>
        <?php
    }


    public function renderPaymentMethod($method, $config = [])
    {
        $route = $method->getMeta('route');
        $methodTitle = $method->getMeta('title');
        $methodStyle = Arr::get($config, 'style', 'logo');

        $inputAttributes = array_filter([
                'class' => 'form-radio-input',
                'type' => 'radio',
                'name' => '_fct_pay_method',
                'id' => 'fluent_cart_payment_method_' . $route,
                'value' => $route,
                'required' => true,
                'checked' => $route === Arr::get($config, 'selected_id', '') ? 'true' : '',
                'role' => Arr::get($config, 'role', 'radio'),
                'aria-checked' => Arr::get($config, 'aria_checked', 'false'),
        ]);

        $wrapperClass = $methodStyle === 'logo' ? 'fct_payment_method_logo' : 'fct_payment_method';

        $wrapperAttributes = [
                'class' => $wrapperClass . ' ' . 'fct_payment_method_wrapper fct_payment_method_' . $route,
                'tabindex' => '0',
                'role' => 'presentation'
        ];

        ?>
        <div <?php RenderHelper::renderAtts($wrapperAttributes); ?>>
            <span class="fct-payment-method-loader">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="10" opacity="0.2" fill="none" stroke="currentColor" stroke-miterlimit="10" stroke-width="2.5"></circle>

                    <path d="m12,2c5.52,0,10,4.48,10,10" fill="none" stroke="currentColor" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2.5">
                        <animateTransform attributeName="transform" attributeType="XML" type="rotate" dur="0.5s" from="0 12 12" to="360 12 12" repeatCount="indefinite"></animateTransform>
                    </path>
                </svg>
            </span>

            <input <?php RenderHelper::renderAtts($inputAttributes); ?>/>

            <label for="<?php echo esc_attr('fluent_cart_payment_method_' . $route); ?>">
                <?php
                if ($methodStyle === 'logo') {
                    $method->prepare('logo', $this->hasSubscription);
                } else {
                    $method->prepare('radio', $this->hasSubscription);
                }
                ?>

                <?php echo esc_html($methodTitle); ?>
            </label>
        </div>
        <?php
    }

    public function renderPaymentMethodContent($method, $config = []) {
        $route = $method->getMeta('route');
        $methodTitle = $method->getMeta('title');
        $methodStyle = Arr::get($config, 'style', 'logo');
        $pmContext = [
            'route' => $route,
            'method_title' => $methodTitle,
            'method_style' => $methodStyle,
        ];
        $paymentMethodClass = '';
        $paymentMethodClass = apply_filters('fluent_cart/payment_method_list_class', $paymentMethodClass, $pmContext);

        ?>
        <div class="fluent-cart-checkout_embed_payment_wrapper">
            <?php if ($method->getMeta('instructions')): ?>
                <div class="fct-modal-payment-method-instructions">
                    <?php echo wp_kses_post($method->getMeta('instructions')); ?>
                </div>
            <?php endif; ?>

            <div class="<?php echo "fluent-cart-checkout_embed_payment_container fluent-cart-checkout_embed_payment_container_" . esc_attr($route . ' ' . $paymentMethodClass); ?>"
                 aria-hidden="true">
                <?php do_action(
                        'fluent_cart/checkout_embed_payment_method_content',
                        [
                                'method' => $method,
                                'cart' => $this->cart,
                                'route' => $route
                        ]
                ); ?>
            </div>
        </div>

        <?php
    }










}
