<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Services\Tax\TaxManager;
use FluentCart\Framework\Support\Arr;

class CartSummaryRender
{

    protected $cart;

    public function __construct(Cart $cart)
    {
        $this->cart = $cart;
    }

    public function render($withWrapper = true)
    {
        if (!$this->cart) {
            return '';
        }
        ?>
        <?php if($withWrapper): ?>
            <div class="fct_summary_box" data-fluent-cart-checkout-page-cart-items-wrapper>
        <?php endif; ?>
                <div class="fct_checkout_form_section">
                    <div class="fct_form_section_header" role="heading" aria-level="2">
                        <div
                            data-fluent-cart-checkout-cart-items-toggle
                            class="fct_toggle_content"
                            aria-expanded="true"
                            aria-controls="order_summary_panel"
                        >
                            <h4 id="order_summary_label"><?php echo esc_html__('Order summary', 'fluent-cart'); ?></h4>
                            <div class="fct_toggle_icon" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="6" viewBox="0 0 10 6"
                                        fill="none">
                                    <path
                                        d="M1 1L4.29289 4.29289C4.62623 4.62623 4.79289 4.79289 5 4.79289C5.20711 4.79289 5.37377 4.62623 5.70711 4.29289L9 1"
                                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                        stroke-linejoin="round"></path>
                                </svg>
                            </div>
                        </div><!-- .fct_toggle_content -->

                        <div class="fct_summary_toggle_total">
                            <span
                                id="order_summary_total"
                                class="value"
                                data-fluent-cart-checkout-estimated-total
                                aria-labelledby="order_summary_label"
                            >
                                <?php 
                                echo esc_html(Helper::toDecimal($this->cart->getEstimatedTotal())); ?>
                            </span>
                        </div>
                    </div><!-- .fct_form_section_header -->

                    <div class="fct_form_section_body" id="order_summary_panel" role="region" aria-labelledby="order_summary_label">
                        <div class="fct_form_section_body_inner">
                            <div data-fluent-cart-checkout-item-wrapper class="fct_items_wrapper">
                                <?php $this->renderItemsLists(); ?>
                            </div>
                            <?php $this->renderItemsFooter(); ?>
                        </div>
                    </div><!-- .fct_form_section_body -->
                </div>
        <?php if($withWrapper): ?>
            </div>
        <?php endif; ?>
        <?php
    }

    public function renderItemsLists()
    {
        $items = $this->cart->cart_data;
        if (!$items) {
            return '';
        }
        ?>
        <div class="fct_line_items">
            <?php foreach ($items as $item) {
                (new CartItemRenderer($item, $this->cart))->render();
            }
            ?>
        </div>
        <?php
    }

    public function renderItemsFooter()
    {
        $hideCouponField = (new StoreSettings())->get('hide_coupon_field') === 'yes';
        $hideCouponField = $hideCouponField || Arr::get($this->cart->checkout_data, 'disable_coupons', 'no') === 'yes';
        ?>
        <div class="fct_summary_items">
            <ul class="fct_summary_items_list">
                <li>
                    <span class="fct_summary_label"> <?php esc_html_e('Subtotal', 'fluent-cart'); ?></span>
                    <span class="fct_summary_value" data-fluent-cart-checkout-subtotal>
                         <?php echo esc_html(Helper::toDecimal($this->cart->getItemsSubtotal())); ?>
                    </span>
                </li>

                <?php $this->maybeShowCustomCartSummaries(); ?>

                <?php if ($this->cart->requireShipping()): ?>
                    <li class="<?php echo $this->cart->getShippingTotal() === 0 ? 'shipping-charge-hidden' : ''; ?>" data-fluent-cart-checkout-shipping-amount-wrapper>
                        <span class="fct_summary_label"><?php esc_html_e('Shipping', 'fluent-cart'); ?></span>
                        <span class="fct_summary_value" data-fluent-cart-checkout-shipping-amount data-shipping-method-id="">
                            <?php
                            echo esc_html(Helper::toDecimal($this->cart->getShippingTotal()));
                            ?>
                        </span>
                    </li>
                <?php endif ?>

                <li data-fluent-cart-checkout-page-applied-coupon>
                    <?php $this->showCouponApplied(); ?>
                </li>

                <?php $this->showManualDiscount(); ?>

                <?php do_action('fluent_cart/checkout/before_summary_total', [ 'cart' => $this->cart ]); ?>

                <?php if (!$hideCouponField): ?>
                    <li>
                        <?php $this->showCouponField(); ?>
                    </li>
                <?php endif ?>

                <li class="fct_summary_items_total"
                    data-fluent-cart-checkout-page-current-total>
                    <span class="fct_summary_label"><?php echo esc_html__('Total','fluent-cart'); ?></span>
                    <span class="fct_summary_value" data-fluent-cart-checkout-estimated-total>
                        <?php
                        echo esc_html(Helper::toDecimal($this->cart->getEstimatedTotal()));
                        ?>
                    </span>
                </li>
            </ul>
        </div>
        <?php
    }

    protected function maybeShowCustomCartSummaries()
    {
        $isCustomCheckout = Arr::get($this->cart->checkout_data, 'custom_checkout') === 'yes';
        if (!$isCustomCheckout) {
            return '';
        }

        $customerDiscountAmount = Arr::get($this->cart->checkout_data, 'custom_checkout_data.discount_total', 0);
        $formattedCustomDiscountAmount = Helper::toDecimal($customerDiscountAmount);
        $customShippingAmount = Arr::get($this->cart->checkout_data, 'custom_checkout_data.shipping_total', 0);
        $formattedCustomShippingAmount = Helper::toDecimal($customShippingAmount);

        $checkoutShippingData = $this->cart->checkout_data['shipping_data'] ?? [];
        $shippingMethodId = Arr::get($checkoutShippingData, 'shipping_method_id', '');
        $shippingCharge = Arr::get($checkoutShippingData, 'shipping_charge', 0); // checking if shipping charge is again set from checkout

        ?>
        <?php if ($customerDiscountAmount): ?>
        <li>
            <span class="fct_summary_label"> <?php esc_html_e('Discount', 'fluent-cart'); ?></span>
            <span class="fct_summary_value" data-fluent-cart-checkout-subtotal>
                 -<?php echo esc_html($formattedCustomDiscountAmount); ?>
            </span>
        </li>
        <?php endif ?>
        <?php if ($customShippingAmount && !$shippingCharge): ?>
            <li>
                <span class="fct_summary_label"> <?php esc_html_e('Shipping
                ', 'fluent-cart'); ?></span>
                <span class="fct_summary_value" data-fluent-cart-checkout-subtotal>
                    <?php echo esc_html($formattedCustomShippingAmount); ?>
                </span>
            </li>
        <?php endif ?>
        <?php
    }

    public function showCouponApplied()
    {
        $discounts = $this->cart->getDiscountLines();

        if (!$discounts) {
            return;
        }

        ?>

        <div class="fct_coupon_applied" data-fluent-cart-checkout-page-discount-container>
            <?php foreach ($discounts as $couponCode => $discount_data): ?>
                <div class="fct_coupon_applied_item">
                    <div class="fct_coupon_info">
                        <span class="fct_coupon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16" fill="none">
                            <ellipse cx="1" cy="1" rx="1" ry="1" transform="matrix(1 0 0 -1 10.6667 5.3335)" stroke="#2F3448"
                                    stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <path
                                d="M1.8495 7.42921C1.18073 8.17611 1.16635 9.30298 1.78012 10.0957C2.99809 11.6688 4.33117 13.0018 5.90423 14.2198C6.69694 14.8336 7.82382 14.8192 8.57072 14.1504C10.5986 12.3347 12.4557 10.4372 14.2479 8.35189C14.4251 8.14572 14.536 7.89304 14.5608 7.62234C14.6708 6.42524 14.8968 2.97637 13.9602 2.03975C13.0236 1.10313 9.57469 1.3291 8.37759 1.43909C8.10689 1.46397 7.8542 1.57481 7.64804 1.75199C5.56276 3.54422 3.66521 5.40132 1.8495 7.42921Z"
                                stroke="#2F3448" stroke-width="1.5"/>
                            <path d="M4.66669 9.3335L6.66669 11.3335" stroke="#2F3448" stroke-width="1.5"
                                    stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>

                            <?php echo esc_html($discount_data['formatted_title']) ?>
                        </span>
                        <a
                            href="#"
                            class="fct_remove_coupon"
                            data-remove-coupon
                            data-coupon="<?php echo esc_attr($couponCode); ?>"
                        >
                            <?php echo esc_html('Remove', 'fluent-cart'); ?>
                        </a>
                    </div>
                    <div class="fct_coupon_price">
                        &#8211; <?php echo esc_html($discount_data['actual_formatted_discount']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php
    }

    public function showManualDiscount()
    {
        $manualDiscount = Arr::get($this->cart->checkout_data, 'manual_discount', []);
      
        if (!$manualDiscount || !Arr::get($manualDiscount, 'amount', 0)) {
            return;
        }

        $title = Arr::get($manualDiscount, 'title', __('Manual Discount', 'fluent-cart'));
        $amount = Arr::get($manualDiscount, 'amount', 0);
        $formattedAmount = Helper::toDecimal($amount);
        ?>
        <li>
            <span class="fct_summary_label" aria-label="<?php echo esc_attr($title); ?>"> <?php echo esc_html($title); ?></span>
            <span class="fct_summary_value" data-fluent-cart-checkout-manual-discount aria-label="<?php echo esc_attr($formattedAmount); ?>">
                -<?php echo esc_html($formattedAmount); ?>
            </span>
        </li>
        <?php
    }

    public function showCouponField()
    {
        ?>
        <div class="fct_coupon">
            <div class="fct_coupon_toggle">
                <a
                    aria-expanded="false"
                    aria-controls="coupon_section"
                    href="#"
                    data-fluent-cart-checkout-page-coupon-field-toggle
                >
                    <?php echo esc_html__('Have a Coupon?', 'fluent-cart'); ?>
                </a>
            </div>

            <div
                id="coupon_section"
                class="fct_coupon_field"
                hidden
                data-fluent-cart-checkout-page-coupon-container
                role="region"
                aria-label="<?php esc_attr_e('Coupon entry area', 'fluent-cart'); ?>"
            >
                <label for="coupon" class="sr-only">
                    <?php echo esc_html__('Enter coupon code', 'fluent-cart'); ?>
                </label>

                <span class="fct_coupon_icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18" fill="none">
                        <g clip-path="url(#clip0_3365_10538)">
                            <ellipse cx="1.125" cy="1.125" rx="1.125" ry="1.125" transform="matrix(1 0 0 -1 12 6)"
                                        stroke="#8b8d9a" stroke-width="1.5" stroke-linecap="round"
                                        stroke-linejoin="round"/>
                            <path
                                d="M2.08067 8.35795C1.32831 9.19822 1.31213 10.4659 2.00262 11.3578C3.37284 13.1274 4.87255 14.6272 6.64225 15.9974C7.53405 16.6879 8.80178 16.6717 9.64205 15.9193C11.9234 13.8766 14.0127 11.7419 16.0289 9.39596C16.2282 9.16403 16.3529 8.87976 16.3809 8.57522C16.5047 7.22849 16.7589 3.3485 15.7052 2.29481C14.6515 1.24111 10.7715 1.49532 9.42478 1.61907C9.12024 1.64706 8.83597 1.77175 8.60404 1.97109C6.25809 3.98734 4.12335 6.07658 2.08067 8.35795Z"
                                stroke="#8b8d9a" stroke-width="1.5"/>
                            <path d="M5.25002 10.5L7.50002 12.75" stroke="#8b8d9a" stroke-width="1.5"
                                    stroke-linecap="round" stroke-linejoin="round"/>
                        </g>
                        <defs>
                            <clipPath id="clip0_3365_10538">
                                <rect width="18" height="18" fill="white"/>
                            </clipPath>
                        </defs>
                    </svg>
                </span>

                <div data-fluent-cart-checkout-page-form-input-wrapper class="fct_coupon_input_wrapper">
                    <input 
                        class="fct_coupon_input"
                        type=text 
                        name=coupon 
                        placeholder="<?php esc_attr_e('Apply Here', 'fluent-cart'); ?>"
                        data-required=no 
                        data-type=input 
                        id=coupon
                        autocomplete="off"
                    />
                </div>

                <button type='submit' data-fluent-cart-checkout-page-coupon-validate>
                    <?php echo esc_html__('Apply', 'fluent-cart'); ?>
                </button>
            </div>
        </div>
        <?php
    }

}
