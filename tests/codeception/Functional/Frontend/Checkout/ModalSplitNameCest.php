<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Services\Renderer\CheckoutFieldsSchema;
use FluentCart\App\Services\Renderer\ModalCheckoutRenderer;
use PHPUnit\Framework\Assert;
use Tests\Support\Doubles\CheckoutGateway;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * With First/Last name enabled and required in the checkout-field settings the
 * modal renders those inputs (never a single Full name) and the order is placed
 * from them.
 */
class ModalSplitNameCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        GatewayManager::getInstance()->register('wp_browser_stub', new CheckoutGateway());

        fluent_cart_update_option('_fc_checkout_fields', [
            'basic_info' => [
                'full_name'  => ['enabled' => 'no', 'required' => 'no'],
                'first_name' => ['enabled' => 'yes', 'required' => 'yes'],
                'last_name'  => ['enabled' => 'yes', 'required' => 'yes'],
            ],
        ]);
        CheckoutFieldsSchema::resetFieldsCache();
    }

    public function _after(FunctionalTester $I): void
    {
        $gateway = GatewayManager::getInstance('wp_browser_stub');
        if ($gateway) {
            remove_filter('fluent_cart/transaction/url_wp_browser_stub', [$gateway, 'getTransactionUrl'], 10);
            remove_filter('fluent_cart/subscription/url_wp_browser_stub', [$gateway, 'getSubscriptionUrl'], 10);
        }
        GatewayManager::getInstance()->remove('wp_browser_stub');
        fluent_cart_update_option('_fc_checkout_fields', []);
        CheckoutFieldsSchema::resetFieldsCache();
    }

    private function cart(FunctionalTester $I)
    {
        $variation = (new ProductFactory())->create()->variants->first();
        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 1),
        ])->create(['cart_group' => 'instant']);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);
        return $cart;
    }

    private function payload(string $cartHash, array $name): array
    {
        return $name + [
            'fct_cart_hash'     => $cartHash,
            '_fct_pay_method'   => 'wp_browser_stub',
            'billing_email'     => 'modal-split@example.test',
            'billing_address_1' => '5 Modal Way',
            'billing_city'      => 'New York',
            'billing_state'     => 'NY',
            'billing_postcode'  => '10001',
            'billing_country'   => 'US',
            'ship_to_different' => 'no',
            'agree_terms'       => 'yes',
        ];
    }

    public function modalRendersFirstAndLastNameInsteadOfFullName(FunctionalTester $I): void
    {
        $I->logout();
        $cart = $this->cart($I);

        ob_start();
        (new ModalCheckoutRenderer($cart))->renderCheckoutBilling();
        $html = ob_get_clean();

        Assert::assertStringContainsString('name="billing_first_name"', $html);
        Assert::assertStringContainsString('name="billing_last_name"', $html);
        Assert::assertStringNotContainsString('name="billing_full_name"', $html);
        Assert::assertMatchesRegularExpression('/fct-modal-form-field col-12">\s*<div[^>]*>.*?id="billing_email"/s', $html, 'email takes its own row beside first/last name');
    }

    public function orderIsPlacedFromFirstAndLastNameWithoutFullName(FunctionalTester $I): void
    {
        $I->logout();
        $cart = $this->cart($I);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->payload($cart->cart_hash, [
            'billing_first_name' => 'Alex',
            'billing_last_name'  => 'Smith',
        ]));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), wp_json_encode($body));
        $order = Order::query()->first();
        Assert::assertSame('Alex', (string) $order->billing_address->first_name);
        Assert::assertSame('Smith', (string) $order->billing_address->last_name);
    }

    public function missingLastNameIsRejectedWhenRequired(FunctionalTester $I): void
    {
        $I->logout();
        $cart = $this->cart($I);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->payload($cart->cart_hash, [
            'billing_first_name' => 'Alex',
        ]));

        Assert::assertSame('failed', (string) ($body['status'] ?? ''));
        Assert::assertNotEmpty($body['errors']['billing_last_name'] ?? null);
        Assert::assertSame(0, Order::query()->count());
    }

    public function fullNameOnlyRequestIsRejectedInFirstLastMode(FunctionalTester $I): void
    {
        $I->logout();
        $cart = $this->cart($I);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->payload($cart->cart_hash, [
            'billing_full_name' => 'Alex Smith',
        ]));

        Assert::assertSame('failed', (string) ($body['status'] ?? ''));
        Assert::assertNotEmpty($body['errors']['billing_first_name'] ?? null, 'a posted full name must not be split into first/last');
        Assert::assertSame(0, Order::query()->count());
    }

    public function modalRendersFullNameWhenFirstAndLastAreDisabled(FunctionalTester $I): void
    {
        fluent_cart_update_option('_fc_checkout_fields', []);
        CheckoutFieldsSchema::resetFieldsCache();
        $I->logout();
        $cart = $this->cart($I);

        ob_start();
        (new ModalCheckoutRenderer($cart))->renderCheckoutBilling();
        $html = ob_get_clean();

        Assert::assertStringContainsString('name="billing_full_name"', $html);
        Assert::assertStringNotContainsString('name="billing_first_name"', $html);
        Assert::assertStringNotContainsString('name="billing_last_name"', $html);
        Assert::assertStringNotContainsString('col-12', $html, 'email stays half width beside the single full name field');
    }

    public function orderIsPlacedFromFullNameWhenFirstAndLastAreDisabled(FunctionalTester $I): void
    {
        fluent_cart_update_option('_fc_checkout_fields', []);
        CheckoutFieldsSchema::resetFieldsCache();
        $I->logout();
        $cart = $this->cart($I);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->payload($cart->cart_hash, [
            'billing_full_name' => 'Alex Smith',
        ]));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), wp_json_encode($body));
        Assert::assertSame('Alex Smith', trim((string) Order::query()->first()->billing_address->name));
    }

    /**
     * An older saved configuration can have Last name on and First name off.
     * Checkout validation always asks for a first name in that mode, so the
     * modal must render it or the customer cannot place an order.
     */
    public function lastNameOnlyConfigStillRendersAndAcceptsFirstName(FunctionalTester $I): void
    {
        fluent_cart_update_option('_fc_checkout_fields', [
            'basic_info' => [
                'full_name'  => ['enabled' => 'no', 'required' => 'no'],
                'first_name' => ['enabled' => 'no', 'required' => 'no'],
                'last_name'  => ['enabled' => 'yes', 'required' => 'yes'],
            ],
        ]);
        CheckoutFieldsSchema::resetFieldsCache();
        $I->logout();
        $cart = $this->cart($I);

        ob_start();
        (new ModalCheckoutRenderer($cart))->renderCheckoutBilling();
        $html = ob_get_clean();

        Assert::assertStringContainsString('name="billing_first_name"', $html);
        Assert::assertStringContainsString('name="billing_last_name"', $html);
        Assert::assertStringNotContainsString('name="billing_full_name"', $html);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->payload($cart->cart_hash, [
            'billing_first_name' => 'Alex',
            'billing_last_name'  => 'Smith',
        ]));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), wp_json_encode($body));
        $order = Order::query()->first();
        Assert::assertSame('Alex', (string) $order->billing_address->first_name);
        Assert::assertSame('Smith', (string) $order->billing_address->last_name);
    }

    /**
     * Explicit names must be stored as typed. Re-guessing them from the joined
     * full name turned "Alex" + "de Silva" into "Alex de" + "Silva" on the
     * customer record. Order addresses keep a single `name` column, so the
     * assertion there is on the full name.
     */
    public function compoundSurnameIsStoredExactlyAsTyped(FunctionalTester $I): void
    {
        $I->logout();
        $cart = $this->cart($I);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->payload($cart->cart_hash, [
            'billing_first_name' => 'Alex',
            'billing_last_name'  => 'de Silva',
        ]));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), wp_json_encode($body));
        $order = Order::query()->first();
        Assert::assertSame('Alex de Silva', (string) $order->billing_address->name);
        Assert::assertSame('Alex', (string) $order->customer->first_name, 'customer first name');
        Assert::assertSame('de Silva', (string) $order->customer->last_name, 'customer last name');
    }
}
