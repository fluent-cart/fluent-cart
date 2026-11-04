<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\Framework\Support\Arr;
use PHPUnit\Framework\Assert;
use Tests\Support\Doubles\CheckoutGateway;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Place-order prices the order for the email it is placed under.
 *
 * Checkout saves the typed email onto the cart as the shopper types, but the
 * order is created from the email the form submits. Those can differ — a save
 * still waiting, or a crafted request — so place-order records the order's
 * email on the cart before anything prices it, and the listeners that do (the
 * order shipping charge, before_order_processing) see it.
 */
class PlaceOrderCheckoutEmailCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        GatewayManager::getInstance()->register('wp_browser_stub', new CheckoutGateway());
    }

    public function _after(FunctionalTester $I): void
    {
        remove_all_filters('fluent_cart/checkout/order_shipping_charge');
        remove_all_actions('fluent_cart/checkout/before_order_processing');

        $gateway = GatewayManager::getInstance('wp_browser_stub');
        if ($gateway) {
            remove_filter('fluent_cart/transaction/url_wp_browser_stub', [$gateway, 'getTransactionUrl'], 10);
            remove_filter('fluent_cart/subscription/url_wp_browser_stub', [$gateway, 'getSubscriptionUrl'], 10);
        }
        GatewayManager::getInstance()->remove('wp_browser_stub');
        $I->disarmStockListeners();
    }

    public function theOrderIsPricedForTheSubmittedEmailNotAnEarlierSavedOne(FunctionalTester $I): void
    {
        $I->logout();

        $product = (new ProductFactory())->withVariantAttributes(['item_price' => 5000])->create();
        $variation = $product->variants->first();

        // The cart saved one email; the form below submits another.
        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 1),
        ])->create([
            'cart_group' => 'instant',
            'email'      => 'saved@acme.com',
        ]);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        $seen = [];

        add_filter('fluent_cart/checkout/order_shipping_charge', function ($charge, $data) use (&$seen) {
            $hookCart = Arr::get($data, 'cart');
            $seen['shipping'] = $hookCart instanceof Cart ? $hookCart->email : null;

            return $charge;
        }, 10, 2);

        add_action('fluent_cart/checkout/before_order_processing', function ($data) use (&$seen) {
            $hookCart = Arr::get($data, 'cart');
            $seen['order'] = $hookCart instanceof Cart ? $hookCart->email : null;
        });

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', [
            'fct_cart_hash'      => $cart->cart_hash,
            '_fct_pay_method'    => 'wp_browser_stub',
            'billing_full_name'  => 'Email Buyer',
            'billing_first_name' => 'Email',
            'billing_last_name'  => 'Buyer',
            'billing_email'      => 'submitted@example.test',
            'billing_address_1'  => '10 Checkout Way',
            'billing_city'       => 'New York',
            'billing_state'      => 'NY',
            'billing_postcode'   => '10001',
            'billing_country'    => 'US',
            'ship_to_different'  => 'no',
            'agree_terms'        => 'yes',
        ]);

        Assert::assertSame(200, $status, 'place-order must succeed. Body: ' . wp_json_encode($body));

        Assert::assertSame('submitted@example.test', $seen['shipping'] ?? null, 'The order shipping charge is priced for the submitted email');
        Assert::assertSame('submitted@example.test', $seen['order'] ?? null, 'And so is everything before_order_processing writes into the order');
    }
}
