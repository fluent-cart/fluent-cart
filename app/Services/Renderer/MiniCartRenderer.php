<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\Framework\Support\Arr;

class MiniCartRenderer
{
    protected $cartItems;
    protected $itemCount = 0;

    public function __construct($cartItems, $config = [])
    {

        $this->cartItems = $cartItems;
        $this->itemCount = Arr::get($config, 'item_count') ?? count($this->cartItems);


    }
    public function renderMiniCart($atts = [])
    {
        $defaults = [
                'is_shortcode' => false,
        ];

        $atts = wp_parse_args($atts, $defaults);

        $cartIcon = Arr::get($atts, 'cart_icon', 'cart');
        $showItemCount = Arr::get($atts, 'show_item_count', 'has_items');
        $showTotalPrice = filter_var(Arr::get($atts, 'show_total_price', true), FILTER_VALIDATE_BOOLEAN);
        $iconColor = Arr::get($atts, 'icon_color', '');
        $priceColor = Arr::get($atts, 'price_color', '');
        $productCountColor = Arr::get($atts, 'product_count_color', '');
        $buttonClass = Arr::get($atts, 'button_class', '');


        // Build style attribute
        $iconStyle = $iconColor ? 'style="color: ' . esc_attr($iconColor) . ';"' : '';
        $priceStyle = $priceColor ? 'style="color: ' . esc_attr($priceColor) . ';"' : '';
        $countStyle = $productCountColor ? 'style="background-color: ' . esc_attr($productCountColor) . ';"' : '';

        $isShortcode = Arr::get($atts, 'is_shortcode', false);
        $wrapperAttributes = '';
        if(!$isShortcode){
            $wrapperAttributes = get_block_wrapper_attributes();
        }



        $subtotal = CartCheckoutHelper::make()->getItemsAmountSubtotal(true, true);
        $aria_label = sprintf(
        /* translators: 1: Total price */
            __('Total cart price: %1$s', 'fluent-cart'),
            esc_attr($subtotal)
        );

        $showBadge = false;
        if ($showItemCount === 'always') {
            $showBadge = true;
        } elseif ($showItemCount === 'has_items' && $this->itemCount > 0) {
            $showBadge = true;
        }


        ?>
        <div <?php echo $wrapperAttributes; ?>>
            <button class="fct-mini-cart-button <?php echo $buttonClass ?>" data-fluent-cart-cart-expand-button
                    aria-label="<?php esc_attr_e('Open Shopping Cart', 'fluent-cart'); ?>">
                    <span class="fct-mini-cart-wrap" <?php echo $iconStyle; ?>>
                        <?php $this->renderCartIcon($cartIcon); ?>

                        <?php if ($showBadge) : ?>
                            <span
                                class="fct-mini-cart-badge"
                                data-cart-badge-count
                                <?php echo $countStyle; ?>
                            >
                                <?php
                                if ($this->itemCount > 0) {
                                    echo esc_html($this->itemCount);
                                } else {
                                    echo '$0.00';
                                }
                                ?>
                            </span>
                        <?php endif; ?>
                    </span>

                <?php if ($showTotalPrice) : ?>
                    <span
                        data-fluent-cart-cart-total-price
                        class="fct-mini-cart-amount"
                        aria-live="polite"
                        aria-label="<?php echo esc_attr($aria_label); ?>"
                                <?php echo $priceStyle; ?>
                        >
                             <?php echo esc_html($subtotal); ?>
                        </span>
                <?php endif; ?>
            </button>
        </div>
        <?php

    }


    public function renderCartIcon(string $cartIcon = 'cart')
    {
        if (filter_var($cartIcon, FILTER_VALIDATE_URL)) {
            echo '<img src="' . esc_url($cartIcon) . '" alt="' . esc_attr__('Cart', 'fluent-cart') . '" class="fct-mini-cart-icon-img" />';
            return;
        }

        switch ($cartIcon) {
            case 'cart':
                $this->renderShoppingCartIcon();
                break;
            case 'bag':
                $this->renderShoppingBagIcon();
                break;
            case 'bag-alt':
            default:
                $this->renderShoppingBagAltIcon();
                break;
        }
    }


    public function renderShoppingCartIcon()
    {
        ?>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">
            <path fill="currentColor"
                  d="M4.00488 16V4H2.00488V2H5.00488C5.55717 2 6.00488 2.44772 6.00488 3V15H18.4433L20.4433 7H8.00488V5H21.7241C22.2764 5 22.7241 5.44772 22.7241 6C22.7241 6.08176 22.7141 6.16322 22.6942 6.24254L20.1942 16.2425C20.083 16.6877 19.683 17 19.2241 17H5.00488C4.4526 17 4.00488 16.5523 4.00488 16ZM6.00488 23C4.90031 23 4.00488 22.1046 4.00488 21C4.00488 19.8954 4.90031 19 6.00488 19C7.10945 19 8.00488 19.8954 8.00488 21C8.00488 22.1046 7.10945 23 6.00488 23ZM18.0049 23C16.9003 23 16.0049 22.1046 16.0049 21C16.0049 19.8954 16.9003 19 18.0049 19C19.1095 19 20.0049 19.8954 20.0049 21C20.0049 22.1046 19.1095 23 18.0049 23Z"></path>
        </svg>
        <?php
    }

    public function renderShoppingBagIcon()
    {
        ?>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
            <path d="M6.50488 2H17.5049C17.8196 2 18.116 2.14819 18.3049 2.4L21.0049 6V21C21.0049 21.5523 20.5572 22 20.0049 22H4.00488C3.4526 22 3.00488 21.5523 3.00488 21V6L5.70488 2.4C5.89374 2.14819 6.19013 2 6.50488 2ZM19.0049 8H5.00488V20H19.0049V8ZM18.5049 6L17.0049 4H7.00488L5.50488 6H18.5049ZM9.00488 10V12C9.00488 13.6569 10.348 15 12.0049 15C13.6617 15 15.0049 13.6569 15.0049 12V10H17.0049V12C17.0049 14.7614 14.7663 17 12.0049 17C9.24346 17 7.00488 14.7614 7.00488 12V10H9.00488Z"></path>
        </svg>
        <?php
    }

    public function renderShoppingBagAltIcon()
    {
        ?>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
            <path d="M7.00488 7.99966V5.99966C7.00488 3.23824 9.24346 0.999664 12.0049 0.999664C14.7663 0.999664 17.0049 3.23824 17.0049 5.99966V7.99966H20.0049C20.5572 7.99966 21.0049 8.44738 21.0049 8.99966V20.9997C21.0049 21.5519 20.5572 21.9997 20.0049 21.9997H4.00488C3.4526 21.9997 3.00488 21.5519 3.00488 20.9997V8.99966C3.00488 8.44738 3.4526 7.99966 4.00488 7.99966H7.00488ZM7.00488 9.99966H5.00488V19.9997H19.0049V9.99966H17.0049V11.9997H15.0049V9.99966H9.00488V11.9997H7.00488V9.99966ZM9.00488 7.99966H15.0049V5.99966C15.0049 4.34281 13.6617 2.99966 12.0049 2.99966C10.348 2.99966 9.00488 4.34281 9.00488 5.99966V7.99966Z"></path>
        </svg>

        <?php
    }
}
