<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes\Checkout;


use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Hooks\Handlers\ShortCodes\ShortCode;
use FluentCart\App\Services\Renderer\CheckoutRenderer;


class CheckoutShippingMethodsShortCode extends ShortCode
{
    protected static string $shortCodeName = 'fluent_cart_checkout_shipping_methods';


    public function render($viewData = null)
    {
        $cart = CartHelper::getCart();
        (new CheckoutRenderer($cart))->getFragment('shipping_methods');
    }
}
