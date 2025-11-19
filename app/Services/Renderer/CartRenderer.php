<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Api\StoreSettings;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Vite;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Models\Product;

class CartRenderer
{
    protected $cartItems;
    protected $storeSettings;

    protected $cartItem = [];
    protected $url = [];

    protected $product = [];


    public function __construct($cartItems = [])
    {
        $this->storeSettings = new StoreSettings();
        $this->cartItems = $cartItems;
    }

    public function render()
    {

        ?>

        <section class="fct-cart-page" aria-labelledby="fct-cart-page-title" role="region">
            <h2 id="fct-cart-page-title" class="screen-reader-text">
                <?php esc_html_e('Your Shopping Cart', 'fluent-cart'); ?>
            </h2>

            <?php $this->renderItems(); ?>

            <?php $this->renderTotal(); ?>

            <div class="fluent-cart-cart-cart-button-wrap" data-fluent-cart-cart-checkout-button-wrap>
                <?php $this->renderCheckoutButton(); ?>
            </div>


        </section>

        <?php
    }


    public function renderItems()
    {
        ?>

        <div class="fct-cart-drawer-content-wrapper" data-fluent-cart-cart-content-wrapper role="presentation">
            <?php $this->renderList(); ?>
        </div>

        <?php

    }

    public function renderDummyItems()
    {
        $dummy = [
                [
                        'id'                       => 0,
                        'post_id'                  => 0,
                        'fulfillment_type'         => '',
                        'other_info'               => [
                                'payment_type' => '',
                        ],
                        'quantity'                 => 0,
                        'price'                    => 0,
                        'unit_price'               => 0,
                        'line_total'               => 0,
                        'subtotal'                 => 0,
                        'line_total_formatted'     => '&#36;0',
                        'object_id'                => 0,
                        'title'                    => '',
                        'post_title'               => '',
                        'coupon_discount'          => 0,
                        'cost'                     => 0,
                        'featured_media'           => '',
                        'view_url'                 => '',
                        'variation_type'           => '',
                        'shipping_charge'          => 0,
                        'itemwise_shipping_charge' => 0,
                ]
        ];

        $this->renderList($dummy);
    }

    public function renderList($items = [])
    {
        $items = empty($items) ? $this->cartItems : $items;
        ?>
        <div class="fct-cart-drawer-list" data-fluent-cart-cart-list>
            <ul class="fct-cart-drawer-list-content" data-fluent-cart-items-wrapper role="list">
                <?php

                if (!empty($items)) {
                    foreach ($items as $cartItem) {
                        $this->cartItem = $cartItem;
                        $this->url = Arr::get($cartItem, 'view_url', '');
                        $this->renderItem();
                    }
                }

                ?>
            </ul>
        </div>
        <?php

    }

    public function renderItem()
    {
        $title = Arr::get($this->cartItem, 'title', '');

        ?>
        <li data-cart-items class="fct-cart-item" role="listitem"
            aria-label="<?php echo esc_attr($title); ?>">
            <div class="fct-cart-item-info">
                <?php $this->renderImage(); ?>
                <?php $this->renderDetails(); ?>
            </div>
            <?php $this->renderSummary(); ?>
        </li>

        <?php
    }

    public function renderImage()
    {
        $image = Arr::get($this->cartItem, 'featured_media');
        $title = Arr::get($this->cartItem, 'title', __('Product Image', 'fluent-cart'));

        if (!$image) {
            $image = Vite::getAssetUrl('images/placeholder.svg');
        }

        ?>

        <div class="fct-cart-item-image">
            <a href="<?php echo esc_url($this->url); ?>">
                <img src="<?php echo esc_url($image); ?>"
                     data-fluent-cart-cart-list-item-image
                     alt="<?php echo esc_attr($title); ?>"
                     loading="lazy"
                />
            </a>

        </div>

        <?php
    }

    public function renderDetails()
    {
        ?>

        <div class="fct-cart-item-details" role="group"
             aria-label="<?php esc_attr_e('Product Details', 'fluent-cart'); ?>">
            <?php $this->renderTitles(); ?>
            <?php $this->renderItemPrice(); ?>
            <?php $this->renderQuantity(); ?>
        </div>

        <?php
    }

    public function renderTitles()
    {
        $postTitle = Arr::get($this->cartItem, 'post_title', '');
        $variationTitle = Arr::get($this->cartItem, 'title', '');

        $aria_label = sprintf(
        /* translators: 1: Post or product title */
                esc_attr__('View %1$s', 'fluent-cart'),
                esc_attr($postTitle)
        );


        ?>

        <h3 class="fct-cart-item-title" data-fluent-cart-cart-list-item-title>
            <a href="<?php echo esc_url($this->url); ?>"
               aria-label="<?php echo esc_attr($aria_label); ?>"
               data-fluent-cart-cart-list-item-title-url>
            <span data-fluent-cart-cart-list-item-title-element>
                <?php echo esc_html($postTitle); ?>
            </span>
            </a>
        </h3>

        <p class="fct-cart-item-variant"
           data-fluent-cart-cart-list-item-variation-title>
            - <?php echo esc_html($variationTitle); ?>
        </p>

        <?php
    }

    public function renderItemPrice()
    {
        $price = Helper::toDecimal(Arr::get($this->cartItem, 'unit_price', 0));

        ?>
        <div class="fct-cart-item-price">
            <span><?php esc_html_e('Price:', 'fluent-cart'); ?></span>
            <span data-fluent-cart-cart-list-item-price aria-live="polite">
                <?php echo esc_html($price); ?>
            </span>
        </div>

        <?php
    }

    public function renderQuantity()
    {
        $quantity = Arr::get($this->cartItem, 'quantity', 0);

        $productId = Arr::get($this->cartItem, 'post_id');
        $this->product = Product::query()->with(['detail'])->find($productId);
        $soldIndividually = false;
        if ($this->product) {
            $soldIndividually = $this->product->soldIndividually();
        }

        if ($soldIndividually) {
            return;
        }

        ?>

        <div class="fct-cart-item-quantity"
             data-fluent-cart-cart-list-item-quantity-wrapper
             role="group"
             aria-label="<?php esc_attr_e('Change Quantity', 'fluent-cart'); ?>"
        >

            <button
                    class="qty-btn decrease-btn"
                    data-item-id="<?php echo esc_attr($this->cartItem['object_id']); ?>"
                    data-fluent-cart-cart-list-item-decrease-button
                    title="<?php esc_attr_e('Decrease Quantity', 'fluent-cart'); ?>"
                    aria-label="<?php esc_attr_e('Decrease Quantity', 'fluent-cart'); ?>"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="2" viewBox="0 0 12 2" fill="none">
                    <path d="M11.3333 1L0.666662 1" stroke="currentColor" stroke-linecap="round"
                          stroke-linejoin="round"/>
                </svg>
            </button>

            <input
                    class="qty-value"
                    min="1"
                    data-item-id="<?php echo esc_attr($this->cartItem['object_id']); ?>"
                    data-fluent-cart-cart-list-item-quantity-input
                    value="<?php echo esc_attr($quantity); ?>"
                    aria-label="<?php esc_attr_e('Product Quantity', 'fluent-cart'); ?>"
                    aria-live="polite"
            />

            <button
                    class="qty-btn increase-btn"
                    data-fluent-cart-cart-list-item-increase-button
                    data-item-id="<?php echo esc_attr($this->cartItem['object_id']); ?>"
                    title="<?php esc_attr_e('Increase Quantity', 'fluent-cart'); ?>"
                    aria-label="<?php esc_attr_e('Increase Quantity', 'fluent-cart'); ?>"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="none">
                    <path d="M6 0.666748L6 11.3334M11.3333 6.00008L0.666672 6.00008" stroke="currentColor"
                          stroke-linecap="round" stroke-linejoin="round"></path>
                </svg>
            </button>
        </div>

        <?php
    }


    public function renderSummary()
    {
        ?>
        <div class="fct-cart-item-summary" role="group"
             aria-label="<?php esc_attr_e('Cart Item Summary', 'fluent-cart'); ?>">
            <?php $this->renderItemTotal(); ?>
            <?php $this->renderRemove(); ?>
        </div>

        <?php
    }

    public function renderItemTotal()
    {
        $quantity = Arr::get($this->cartItem, 'quantity', 0);
        $totalPrice = Arr::get($this->cartItem, 'unit_price', 0) * $quantity;

        if (Arr::get($this->cartItem, 'other_info.payment_type', 'onetime') == 'subscription') {
            if (Arr::get($this->cartItem, 'other_info.manage_setup_fee') == 'yes') {
                $signupFee = Arr::get($this->cartItem, 'other_info.signup_fee', 0);

                if (Arr::get($this->cartItem, 'other_info.setup_fee_per_item', 'no') == 'yes') {
                    $signupFee = Arr::get($this->cartItem, 'other_info.signup_fee', 0) * $quantity;
                }

                $totalPrice += $signupFee;
            }
        }

        $totalPrice = Helper::toDecimal($totalPrice);
        $aria_label = sprintf(
        /* translators: 1: Total price */
                __('Total price for this item: %1$s', 'fluent-cart'),
                $totalPrice
        );

        ?>
        <div class="fct-cart-item-total">
                <span
                        data-fluent-cart-cart-list-item-total-price
                        aria-live="polite"
                        aria-label="<?php echo esc_attr($aria_label); ?>"
                >
                     <?php echo esc_html($totalPrice); ?>
                </span>
        </div>

        <?php
    }

    public function renderTotal()
    {

        $subtotal = CartCheckoutHelper::make()->getItemsAmountSubtotal(true, true);
        $aria_label = sprintf(
        /* translators: 1: Total price */
                __('Total cart price: %1$s', 'fluent-cart'),
                esc_attr($subtotal)
        );
        ?>

        <div
                data-fluent-cart-cart-total-wrapper
                class="fct-cart-total-wrapper"
                role="region"
                aria-label="<?php esc_attr_e('Cart Total', 'fluent-cart'); ?>"
        >
            <span><?php echo esc_html__('Total', 'fluent-cart'); ?>:</span>
            <span
                    data-fluent-cart-cart-total-price
                    aria-live="polite"
                    aria-label="<?php echo esc_attr($aria_label); ?>"
            >
                     <?php echo esc_html($subtotal); ?>
                </span>
        </div>

        <?php

    }

    public function renderRemove()
    {

        ?>
        <div class="fct-cart-item-remove">
            <button
                    type="button"
                    class="fct-cart-item-delete-button"
                    data-fluent-cart-cart-list-item-delete-button
                    data-item-id="<?php echo esc_attr($this->cartItem['object_id']); ?>"
                    aria-label="<?php esc_attr_e('Remove this item from cart', 'fluent-cart'); ?>"
                    title="<?php esc_attr_e('Remove From Cart', 'fluent-cart'); ?>"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="16" viewBox="0 0 14 16" fill="none">
                    <path d="M12 3.6665L11.5868 10.3499C11.4813 12.0575 11.4285 12.9113 11.0005 13.5251C10.7889 13.8286 10.5164 14.0847 10.2005 14.2772C9.56141 14.6665 8.70599 14.6665 6.99516 14.6665C5.28208 14.6665 4.42554 14.6665 3.78604 14.2765C3.46987 14.0836 3.19733 13.827 2.98579 13.5231C2.55792 12.9082 2.5063 12.0532 2.40307 10.3433L2 3.6665"
                          stroke="currentColor" stroke-linecap="round"></path>
                    <path d="M1 3.66659H13M9.70382 3.66659L9.2487 2.72774C8.94638 2.10409 8.79522 1.79227 8.53448 1.59779C8.47664 1.55465 8.4154 1.51628 8.35135 1.48305C8.06261 1.33325 7.71608 1.33325 7.02302 1.33325C6.31255 1.33325 5.95732 1.33325 5.66379 1.48933C5.59873 1.52392 5.53666 1.56385 5.4782 1.6087C5.21443 1.81105 5.06709 2.13429 4.7724 2.78076L4.36862 3.66659"
                          stroke="currentColor" stroke-linecap="round"></path>
                    <path d="M5.33334 11L5.33334 7" stroke="currentColor" stroke-linecap="round"></path>
                    <path d="M8.66666 11L8.66666 7" stroke="currentColor" stroke-linecap="round"></path>
                </svg>
            </button>
        </div>

        <?php
    }

    public function renderCheckoutButton()
    {
        ?>

        <a class="checkout-button"
           href="<?php echo esc_attr($this->storeSettings->getCheckoutPage()); ?>"
           role="button"
           aria-label="<?php esc_attr_e('Go to checkout page', 'fluent-cart'); ?>">
            <?php esc_html_e('Go to Checkout', 'fluent-cart'); ?>
        </a>

        <?php

    }

    public function renderViewCartButton()
    {
        return;
        ?>

        <a class="view-cart-button"
           href="<?php echo esc_attr($this->storeSettings->getCartPage()); ?>"
           role="button"
           aria-label="<?php esc_attr_e('View your shopping cart', 'fluent-cart'); ?>">
            <?php esc_html_e('View Cart', 'fluent-cart'); ?>
        </a>

        <?php

    }

    public function renderEmptyCartIcon()
    { ?>
        <svg xmlns="http://www.w3.org/2000/svg" width="157" height="137" viewBox="0 0 157 137" fill="none">
            <path d="M21.5017 135H134.91" stroke="#D6DCE8" stroke-width="2" stroke-miterlimit="10"
                  stroke-linejoin="round"/>
            <path d="M8.60278 135H18.2925" stroke="#D6DCE8" stroke-width="2" stroke-miterlimit="10"
                  stroke-linejoin="round"/>
            <path d="M23.1192 26.0612L18.0879 27.0789L21.8382 46.0468L26.8695 45.0291L23.1192 26.0612Z"
                  fill="#D5DDEA"/>
            <path d="M41.6122 46.1949L38.3471 29.4465H11.9932C10.827 29.4465 9.89417 28.9747 9.1945 28.2671L7.32873 26.144C6.62907 25.4364 7.09551 24.021 8.26162 24.021H40.4461C41.6122 24.021 42.7783 24.9646 43.0116 26.144L46.7431 45.0155L41.6122 46.1949Z"
                  fill="#D5DDEA"/>
            <path d="M118.342 134.655C123.365 134.655 127.437 130.536 127.437 125.455C127.437 120.374 123.365 116.255 118.342 116.255C113.318 116.255 109.246 120.374 109.246 125.455C109.246 130.536 113.318 134.655 118.342 134.655Z"
                  fill="url(#paint0_linear_2971_9229)"/>
            <path d="M54.2057 134.655C59.2291 134.655 63.3014 130.536 63.3014 125.455C63.3014 120.374 59.2291 116.255 54.2057 116.255C49.1824 116.255 45.1101 120.374 45.1101 125.455C45.1101 130.536 49.1824 134.655 54.2057 134.655Z"
                  fill="url(#paint1_linear_2971_9229)"/>
            <path d="M53.4147 103.68L48.3865 104.713L52.6584 125.981L57.6867 124.948L53.4147 103.68Z"
                  fill="#D5DDEA"/>
            <path d="M118.109 128.05H39.28C38.1139 128.05 36.9478 127.106 36.7146 125.927L31.1173 98.5629C30.884 97.1476 31.8169 95.7322 33.2162 95.4963C34.6156 95.2604 36.0149 96.204 36.2481 97.6194L41.379 122.86H118.342C119.741 122.86 120.907 124.039 120.907 125.455C120.907 126.87 119.741 128.05 118.109 128.05Z"
                  fill="#D5DDEA"/>
            <path d="M38.8136 134.655C43.837 134.655 47.9093 130.536 47.9093 125.455C47.9093 120.374 43.837 116.255 38.8136 116.255C33.7903 116.255 29.718 120.374 29.718 125.455C29.718 130.536 33.7903 134.655 38.8136 134.655Z"
                  fill="url(#paint2_linear_2971_9229)"/>
            <path d="M38.8135 129.937C41.2608 129.937 43.2447 127.93 43.2447 125.455C43.2447 122.98 41.2608 120.973 38.8135 120.973C36.3662 120.973 34.3823 122.98 34.3823 125.455C34.3823 127.93 36.3662 129.937 38.8135 129.937Z"
                  fill="#8691AA"/>
            <path d="M102.949 134.655C107.973 134.655 112.045 130.536 112.045 125.455C112.045 120.374 107.973 116.255 102.949 116.255C97.9258 116.255 93.8535 120.374 93.8535 125.455C93.8535 130.536 97.9258 134.655 102.949 134.655Z"
                  fill="url(#paint3_linear_2971_9229)"/>
            <path d="M102.949 129.937C105.397 129.937 107.38 127.93 107.38 125.455C107.38 122.98 105.397 120.973 102.949 120.973C100.502 120.973 98.5181 122.98 98.5181 125.455C98.5181 127.93 100.502 129.937 102.949 129.937Z"
                  fill="#8691AA"/>
            <g filter="url(#filter0_d_2971_9229)">
                <path d="M136.999 44.3079L127.437 99.7427C126.738 103.281 123.939 105.64 120.441 105.64H38.3469C34.8486 105.64 32.0499 103.281 31.3502 99.7427L20.6221 44.3079H136.999Z"
                      fill="url(#paint4_linear_2971_9229)"/>
            </g>
            <path d="M69.3652 98.0914C70.5313 98.0914 71.6974 97.1478 71.6974 95.7325V54.4512C71.6974 53.2717 70.7645 52.0923 69.3652 52.0923C68.1991 52.0923 67.033 53.0359 67.033 54.4512V95.7325C67.033 97.1478 67.9658 98.0914 69.3652 98.0914Z"
                  fill="#E7EAF4"/>
            <path d="M81.2596 98.0914C82.4257 98.3272 83.5918 97.3837 83.8251 95.9683L87.7898 54.9229C88.023 53.7435 87.0902 52.564 85.6908 52.3281C84.5247 52.0922 83.3586 53.0358 83.1254 54.4512L79.1606 95.4965C79.1606 96.9119 79.8603 97.8555 81.2596 98.0914Z"
                  fill="#E7EAF4"/>
            <path d="M93.3871 97.8554C94.5532 98.0913 95.7193 97.1477 95.9525 95.9682L103.416 55.3946C103.649 54.2152 102.716 53.0357 101.55 52.7998C100.384 52.5639 99.2176 53.5075 98.9844 54.687L91.7545 95.2606C91.2881 96.44 92.2209 97.6195 93.3871 97.8554Z"
                  fill="#E7EAF4"/>
            <path d="M57.2378 98.0914C56.0717 98.3272 54.9056 97.3837 54.6723 95.9683L50.7076 54.9229C50.4744 53.7435 51.4073 52.564 52.8066 52.3281C53.9727 52.0922 55.1388 53.0358 55.372 54.4512L59.3368 95.4965C59.3368 96.9119 58.6371 97.8555 57.2378 98.0914Z"
                  fill="#E7EAF4"/>
            <path d="M45.1104 97.8554C43.9443 98.0913 42.7782 97.1477 42.5449 95.9682L35.0819 55.3946C34.8487 54.2152 35.7815 53.0357 36.9476 52.7998C38.1137 52.5639 39.2799 53.5075 39.5131 54.687L46.7429 95.2606C47.2094 96.44 46.2765 97.6195 45.1104 97.8554Z"
                  fill="#E7EAF4"/>
            <path d="M137 44.3069L127.438 99.7417C126.738 103.28 123.94 105.639 120.441 105.639H98.7517C102.716 105.639 105.982 102.808 106.681 99.0341L116.71 44.3069H137Z"
                  fill="#DBDFEC"/>
            <defs>
                <filter id="filter0_d_2971_9229" x="0.62207" y="35.3079" width="156.377" height="101.332"
                        filterUnits="userSpaceOnUse" color-interpolation-filters="sRGB">
                    <feFlood flood-opacity="0" result="BackgroundImageFix"/>
                    <feColorMatrix in="SourceAlpha" type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
                                   result="hardAlpha"/>
                    <feOffset dy="11"/>
                    <feGaussianBlur stdDeviation="10"/>
                    <feColorMatrix type="matrix"
                                   values="0 0 0 0 0.397708 0 0 0 0 0.47749 0 0 0 0 0.575 0 0 0 0.27 0"/>
                    <feBlend mode="normal" in2="BackgroundImageFix" result="effect1_dropShadow_2971_9229"/>
                    <feBlend mode="normal" in="SourceGraphic" in2="effect1_dropShadow_2971_9229" result="shape"/>
                </filter>
                <linearGradient id="paint0_linear_2971_9229" x1="109.238" y1="125.457" x2="127.441" y2="125.457"
                                gradientUnits="userSpaceOnUse">
                    <stop stop-color="#B0BACC"/>
                    <stop offset="1" stop-color="#969EAE"/>
                </linearGradient>
                <linearGradient id="paint1_linear_2971_9229" x1="45.1018" y1="125.457" x2="63.3047" y2="125.457"
                                gradientUnits="userSpaceOnUse">
                    <stop stop-color="#B0BACC"/>
                    <stop offset="1" stop-color="#969EAE"/>
                </linearGradient>
                <linearGradient id="paint2_linear_2971_9229" x1="29.7097" y1="125.457" x2="47.9126" y2="125.457"
                                gradientUnits="userSpaceOnUse">
                    <stop stop-color="#B0BACC"/>
                    <stop offset="1" stop-color="#969EAE"/>
                </linearGradient>
                <linearGradient id="paint3_linear_2971_9229" x1="93.8452" y1="125.457" x2="112.048" y2="125.457"
                                gradientUnits="userSpaceOnUse">
                    <stop stop-color="#B0BACC"/>
                    <stop offset="1" stop-color="#969EAE"/>
                </linearGradient>
                <linearGradient id="paint4_linear_2971_9229" x1="78.7728" y1="42.8892" x2="78.7728" y2="106.301"
                                gradientUnits="userSpaceOnUse">
                    <stop stop-color="#FDFEFF"/>
                    <stop offset="0.9964" stop-color="#ECF0F5"/>
                </linearGradient>
            </defs>
        </svg>
    <?php }


    public function renderEmpty($emptyMessage = null, $continueShoppingUrl = null, $continueShoppingLabel = null, $continueShoppingAriaLabel = null)
    {
        $emptyMessage ??= __('Your cart is empty.', 'fluent-cart');
        ?>

        <div
                class="fluent-cart-cart-empty-content"
                role="region"
                aria-label="<?php echo esc_attr($emptyMessage); ?>"
        >


            <?php
            $this->renderEmptyCartIcon();
            $this->renderEmptyCart(
                    $emptyMessage,
                    $continueShoppingUrl,
                    $continueShoppingLabel,
                    $continueShoppingAriaLabel
            ); ?>
        </div>

        <?php

    }

    public function renderEmptyCart(
            $emptyMessage = null,
            $continueShoppingUrl = null,
            $continueShoppingLabel = null,
            $continueShoppingAriaLabel = null
    )
    {
        $emptyMessage ??= __('Your cart is empty.', 'fluent-cart');
        $continueShoppingUrl ??= $this->storeSettings->getShopPage();
        $continueShoppingLabel ??= __('Continue Shopping', 'fluent-cart');
        $continueShoppingAriaLabel ??= __('Browse products and continue shopping', 'fluent-cart');
        ?>
        <div class="fluent-cart-cart-empty-content-text">
            <?php echo esc_html($emptyMessage); ?>

            <?php if ($continueShoppingUrl) : ?>

                <a class="continue-shopping-link"
                   href="<?php echo esc_url($continueShoppingUrl); ?>"
                   aria-label="<?php echo esc_attr($continueShoppingAriaLabel); ?>">
                    <?php echo esc_html($continueShoppingLabel); ?>
                </a>

            <?php endif; ?>
        </div>
    <?php }


}
