<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes\Checkout;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Hooks\Handlers\ShortCodes\ShortCode;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\CartRenderer;
use FluentCart\App\Services\Renderer\CheckoutRenderer;
use FluentCart\Framework\Support\Arr;

class CheckoutPageHandler extends ShortCode
{
    const SHORT_CODE = 'fluent_cart_checkout';
    protected static string $shortCodeName = 'fluent_cart_checkout';

    public static function register()
    {
        parent::register();
    }

    public function render(?array $viewData = null)
    {

        $cart = CartHelper::getCart();

        if (!$cart || empty($cart->cart_data)) {
            return (new CartRenderer())->renderEmpty();
        }

        // Push the shipping and billing address from id
        $checkoutData = $cart->checkout_data;
        $formData = Arr::get($checkoutData, 'form_data', []);

        $currentCustomer = CustomerResource::getCurrentCustomer();

        if ($currentCustomer && empty($formData['billing_country']) && empty($formData['billing_address_id'])) {
            // this is a new cart. So we should fill the address id if any
            $primaryBillingAddress = $currentCustomer->primary_billing_address;
            if ($primaryBillingAddress) {
                $formData['billing_address_id'] = $primaryBillingAddress->id;
            } else {
                // Case 3: no primary address — use the first available billing address
                $firstBillingAddress = $currentCustomer->billing_address()
                    ->where('status', 'active')
                    ->orderBy('id', 'ASC')
                    ->first();
                if ($firstBillingAddress) {
                    $formData['billing_address_id'] = $firstBillingAddress->id;
                }
            }

            if ($cart->isShipToDifferent()) {
                $primaryShippingAddress = $currentCustomer->primary_shipping_address;
                if ($primaryShippingAddress) {
                    $formData['shipping_address_id'] = $primaryShippingAddress->id;
                } else {
                    $firstShippingAddress = $currentCustomer->shipping_address()
                        ->where('status', 'active')
                        ->orderBy('id', 'ASC')
                        ->first();
                    if ($firstShippingAddress) {
                        $formData['shipping_address_id'] = $firstShippingAddress->id;
                    }
                }
            }
        }

        $formData = AddressHelper::maybePushAddressDataForCheckout($formData, 'billing');
        if ($cart->isShipToDifferent()) {
            $formData = AddressHelper::maybePushAddressDataForCheckout($formData, 'shipping');
        }

        if(empty($formData['billing_country'])) {
            $formData['billing_country'] = AddressHelper::getDefaultBillingCountryForCheckout();
        }
        
        $checkoutData['form_data'] = $formData;
        $cart->checkout_data = $checkoutData;
        $cart->save();

        do_action('fluent_cart/cart/cart_data_items_updated', [
            'cart'       => $cart,
            'scope'      => 'loading',
            'scope_data' => ''
        ]);


        AssetLoader::loadCheckoutAssets($cart);

        (new CheckoutRenderer($cart))->render();

    }
}
