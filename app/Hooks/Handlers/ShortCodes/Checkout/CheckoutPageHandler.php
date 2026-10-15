<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes\Checkout;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Hooks\Handlers\ShortCodes\ShortCode;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\CartRenderer;
use FluentCart\App\Services\Renderer\CheckoutRenderContext;
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
        $attributes = $viewData ?? $this->shortCodeAttributes ?? [];

        /**
         * Let an add-on supply the cart this checkout renders from. Same filter
         * the checkout block fires, so one listener covers both surfaces.
         * Returning null keeps the shipped behaviour.
         *
         * @param \FluentCart\App\Models\Cart|null $cart
         * @param array $context ['surface' => string, 'attributes' => array, 'block' => null]
         */
        $context = [
            'surface'    => 'checkout-shortcode',
            'attributes' => $attributes,
            'block'      => null,
        ];

        $cart = apply_filters('fluent_cart/checkout/render_cart', null, $context);

        if (!$cart && CheckoutRenderer::isCustomProductCheckout($attributes)) {
            echo CheckoutRenderer::renderCustomProductCheckout($context); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered markup
            return;
        }

        if (!$cart) {
            $cart = CartHelper::getCart();
        }

        $this->renderCart($cart, $attributes);
    }

    /**
     * Render the checkout shortcode for an already resolved cart.
     *
     * @param \FluentCart\App\Models\Cart|null $cart
     * @param array $attributes
     * @return void
     */
    public function renderCart($cart, array $attributes = [])
    {
        if (!$cart || empty($cart->cart_data)) {
            /**
             * The message an empty checkout shows. Lets the add-on that supplied
             * the cart explain why (e.g. its products are unavailable).
             *
             * @param string|null $message null keeps the default empty-cart message.
             * @param array $context ['surface' => string, 'attributes' => array]
             */
            $emptyMessage = apply_filters('fluent_cart/checkout/empty_message', null, [
                'surface'    => 'checkout-shortcode',
                'attributes' => $attributes,
            ]);

            return (new CartRenderer())->renderEmpty($emptyMessage);
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

        // Rendering the checkout must not be what first writes the cart row.
        // A caller that handed us an in-memory cart owns the decision to
        // persist it; defaulting the billing country for display does not.
        if ($cart->exists) {
            $cart->save();
        }

        do_action('fluent_cart/cart/cart_data_items_updated', [
            'cart'       => $cart,
            'scope'      => 'loading',
            'scope_data' => ''
        ]);


        AssetLoader::loadCheckoutAssets($cart);

        // Same context the checkout block publishes, so anything rendering
        // inside the shortcode sees the cart this checkout actually resolved.
        CheckoutRenderContext::rendering(
            $cart,
            ['surface' => 'checkout-shortcode', 'attributes' => $attributes],
            function () use ($cart, $attributes) {
                (new CheckoutRenderer($cart, [
                    'field_label_style' => CheckoutRenderer::resolveFieldLabelStyle(Arr::get($attributes, 'field_label_style', ''))
                ]))->render();
            }
        );
    }
}
