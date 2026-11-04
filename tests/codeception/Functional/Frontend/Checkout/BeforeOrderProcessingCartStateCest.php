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
 * What a `before_order_processing` listener saves must not undo place-order.
 *
 * placeOrder() writes the payment method, recalculated tax and the restored
 * shipping charge through its own cart instance. The hook hands a listener the
 * checkout helper's instance of the same row, so a listener that saves it
 * could write an older snapshot straight back over all of that.
 */
class BeforeOrderProcessingCartStateCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        GatewayManager::getInstance()->register('wp_browser_stub', new CheckoutGateway());
    }

    public function _after(FunctionalTester $I): void
    {
        remove_all_actions('fluent_cart/checkout/before_order_processing');

        $gateway = GatewayManager::getInstance('wp_browser_stub');
        if ($gateway) {
            remove_filter('fluent_cart/transaction/url_wp_browser_stub', [$gateway, 'getTransactionUrl'], 10);
            remove_filter('fluent_cart/subscription/url_wp_browser_stub', [$gateway, 'getSubscriptionUrl'], 10);
        }
        GatewayManager::getInstance()->remove('wp_browser_stub');
        $I->disarmStockListeners();
    }

    public function aListenerSavingTheCartKeepsThePlaceOrderMetadata(FunctionalTester $I): void
    {
        $I->logout();

        $product = (new ProductFactory())->withVariantAttributes(['item_price' => 5000])->create();
        $variation = $product->variants->first();

        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 1),
        ])->create(['cart_group' => 'instant']);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        // Mirrors the pricing module's persister: write the discount bookkeeping
        // into checkout_data and save the cart the hook handed over.
        add_action('fluent_cart/checkout/before_order_processing', function ($data) {
            $hookCart = Arr::get($data, 'cart');
            if (!$hookCart instanceof Cart) {
                return;
            }

            $checkoutData = $hookCart->checkout_data ?? [];
            $checkoutData['__dynamic_pricing_discounts'] = [0 => 500];
            $hookCart->checkout_data = $checkoutData;
            $hookCart->save();
        });

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', [
            'fct_cart_hash'      => $cart->cart_hash,
            '_fct_pay_method'    => 'wp_browser_stub',
            'billing_full_name'  => 'State Buyer',
            'billing_first_name' => 'State',
            'billing_last_name'  => 'Buyer',
            'billing_email'      => 'order-processing-state@example.test',
            'billing_address_1'  => '10 Checkout Way',
            'billing_city'       => 'New York',
            'billing_state'      => 'NY',
            'billing_postcode'   => '10001',
            'billing_country'    => 'US',
            'ship_to_different'  => 'no',
            'agree_terms'        => 'yes',
        ]);

        Assert::assertSame(200, $status, 'place-order must succeed. Body: ' . wp_json_encode($body));

        $saved = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertNotNull($saved, 'the cart row must still exist');

        Assert::assertSame(
            [0 => 500],
            Arr::get($saved->checkout_data, '__dynamic_pricing_discounts'),
            "the listener's bookkeeping must be saved"
        );

        Assert::assertSame(
            'wp_browser_stub',
            Arr::get($saved->checkout_data, 'payment_method'),
            'the payment method placeOrder wrote must survive the listener saving the cart'
        );
    }
}
