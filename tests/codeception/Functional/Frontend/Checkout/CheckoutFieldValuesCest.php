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
 * The checkout field values on the cart are the ones the order will carry.
 *
 * While the shopper types, the page saves the form onto the cart. At place
 * order the order is created from the submitted form, so place-order records
 * those values on the cart before anything prices the order — except the
 * ones tax is worked out from, which stay as the summary priced them.
 */
class CheckoutFieldValuesCest
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

    public function theOrderIsPricedForTheSubmittedFieldsNotEarlierSavedOnes(FunctionalTester $I): void
    {
        $I->logout();

        $product = (new ProductFactory())->withVariantAttributes(['item_price' => 5000])->create();
        $variation = $product->variants->first();

        // The cart saved earlier values; the form submits others.
        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 1),
        ])->create([
            'cart_group'    => 'instant',
            'checkout_data' => ['form_data' => [
                'billing_address_1'    => '1 Saved Street',
                'billing_city'         => 'Saved City',
                'billing_company_name' => 'Old Co',
                'billing_country'      => 'GB',
                'is_business'          => 'yes',
            ]],
        ]);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        $seen = [];

        add_filter('fluent_cart/checkout/order_shipping_charge', function ($charge, $data) use (&$seen) {
            $seen['shipping'] = $this->formOn(Arr::get($data, 'cart'));

            return $charge;
        }, 10, 2);

        add_action('fluent_cart/checkout/before_order_processing', function ($data) use (&$seen) {
            $seen['order'] = $this->formOn(Arr::get($data, 'cart'));
        });

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', [
            'fct_cart_hash'        => $cart->cart_hash,
            '_fct_pay_method'      => 'wp_browser_stub',
            'billing_full_name'    => 'Field Buyer',
            'billing_first_name'   => 'Field',
            'billing_last_name'    => 'Buyer',
            'billing_email'        => 'fields@example.test',
            'billing_address_1'    => '10 Checkout Way',
            'billing_city'         => 'Submitted City',
            'billing_state'        => 'NY',
            'billing_postcode'     => '10001',
            'billing_country'      => 'US',
            'is_business'          => 'yes',
            'billing_company_name' => 'Acme Ltd',
            'ship_to_different'    => 'no',
            'agree_terms'          => 'yes',
        ]);

        Assert::assertSame(200, $status, 'place-order must succeed. Body: ' . wp_json_encode($body));

        // Tax is worked out from the saved city and country, so a rule reads those too.
        $expected = [
            'billing_address_1'    => '10 Checkout Way',
            'billing_company_name' => 'Acme Ltd',
            'billing_city'         => 'Saved City',
            'billing_country'      => 'GB',
        ];

        Assert::assertSame($expected, $seen['shipping'] ?? null, 'The order shipping charge is priced for the submitted fields');
        Assert::assertSame($expected, $seen['order'] ?? null, 'And so is everything before_order_processing writes into the order');
    }

    public function savingSplitNameFieldsRecordsThemOnTheCart(FunctionalTester $I): void
    {
        $I->logout();

        $product = (new ProductFactory())->withVariantAttributes(['item_price' => 5000])->create();
        $variation = $product->variants->first();

        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 1),
        ])->create(['cart_group' => 'instant', 'first_name' => '', 'last_name' => '']);

        [$status, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'save_checkout_data',
            'fct_cart_hash'      => $cart->cart_hash,
            'billing_first_name' => 'Kamran',
            'billing_last_name'  => 'Hussen',
            'data_key'           => 'billing_last_name',
            'data_value'         => 'Hussen',
        ]);

        Assert::assertSame(200, $status, 'saving checkout data must succeed. Body: ' . wp_json_encode($body));

        $saved = Cart::query()->where('cart_hash', $cart->cart_hash)->first();

        Assert::assertSame('Kamran', $saved->first_name, 'The first name typed is on the cart');
        Assert::assertSame('Hussen', $saved->last_name, 'And the last name');
        Assert::assertSame('Kamran', Arr::get($saved->checkout_data, 'form_data.billing_first_name'));
        Assert::assertSame('Hussen', Arr::get($saved->checkout_data, 'form_data.billing_last_name'));
    }

    private function formOn($cart): array
    {
        $form = $cart instanceof Cart ? (array)Arr::get($cart->checkout_data, 'form_data', []) : [];

        return [
            'billing_address_1'    => Arr::get($form, 'billing_address_1'),
            'billing_company_name' => Arr::get($form, 'billing_company_name'),
            'billing_city'         => Arr::get($form, 'billing_city'),
            'billing_country'      => Arr::get($form, 'billing_country'),
        ];
    }
}
