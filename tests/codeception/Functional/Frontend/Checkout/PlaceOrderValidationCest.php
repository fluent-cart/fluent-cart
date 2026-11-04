<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * Spec: Cases/checkout-functional.md → "Placing an order — validation" section, items 9-18 and 26.
 *
 * TC-102. Every rejection asserts the response envelope AND that no order (and,
 * where the rejection happens before customer creation, no customer) was written.
 * Checkout validation answers its field errors with HTTP 200 + `status: failed`;
 * product availability answers HTTP 422 with a message; payment method HTTP 423.
 */
class PlaceOrderValidationCest
{
    use PlacesCheckoutOrders;

    public function _before(FunctionalTester $I): void
    {
        $I->logout();
        $this->registerStubCheckoutGateway();
    }

    public function _after(FunctionalTester $I): void
    {
        $this->removeStubCheckoutGateway();
        $I->disarmStockListeners();
    }

    /**
     * Spec: Cases/checkout-functional.md [9]
     *   "Placing an order without a billing email is rejected, names the email field, and creates no order."
     */
    public function missingBillingEmailIsRejected(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $payload = $this->checkoutPayload($cart->cart_hash);
        unset($payload['billing_email']);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);

        Assert::assertSame(200, $status, 'field validation answers with its JSON envelope on HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('failed', (string) ($body['status'] ?? ''), 'missing-email checkout must report failed');
        Assert::assertNotEmpty($body['errors']['billing_email']['invalid'] ?? null, 'missing-email response must name billing_email');
        Assert::assertSame(0, Order::query()->count(), 'missing-email rejection must not create an order');
        Assert::assertSame(0, Customer::query()->count(), 'missing-email rejection must not create or resolve a customer');
        Assert::assertSame(0, OrderAddress::query()->count(), 'missing-email rejection must not write an order address');
        Assert::assertSame(0, OrderTransaction::query()->count(), 'missing-email rejection must not write a transaction');
    }

    /**
     * Spec: Cases/checkout-functional.md [10]
     *   "Placing an order with every required billing address field left blank reports each
     *    missing field that can be checked without a country (country, street, city, postcode)
     *    at once and creates no order."
     */
    public function blankRequiredAddressFieldsAreAllReportedAtOnce(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $payload = $this->checkoutPayload($cart->cart_hash, [
            'billing_address_1' => '',
            'billing_city'      => '',
            'billing_postcode'  => '',
            'billing_country'   => '',
            'billing_state'     => '',
        ]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);

        Assert::assertSame(200, $status, 'field validation answers with its JSON envelope on HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('failed', (string) ($body['status'] ?? ''), 'blank-address checkout must report failed');
        foreach (['billing_country', 'billing_address_1', 'billing_city', 'billing_postcode'] as $field) {
            Assert::assertNotEmpty($body['errors'][$field]['required'] ?? null, "blank-address response must flag {$field} as required in the same response");
        }
        Assert::assertSame(0, Order::query()->count(), 'blank-address rejection must not create an order');
        Assert::assertSame(0, Customer::query()->count(), 'blank-address rejection must not create or resolve a customer');
        Assert::assertSame(0, OrderAddress::query()->count(), 'blank-address rejection must not write an order address');
        Assert::assertSame(0, OrderTransaction::query()->count(), 'blank-address rejection must not write a transaction');
    }

    /**
     * Spec: Cases/checkout-functional.md [11]
     *   "Placing an order with a country code the store does not know is rejected as an invalid country."
     */
    public function unknownCountryCodeIsRejected(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'billing_country' => 'ZZ',
        ]));

        Assert::assertSame(200, $status, 'field validation answers with its JSON envelope on HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('failed', (string) ($body['status'] ?? ''), 'unknown-country checkout must report failed');
        Assert::assertNotEmpty($body['errors']['billing_country']['invalid'] ?? null, 'unknown-country response must flag billing_country as invalid');
        Assert::assertSame(0, Order::query()->count(), 'unknown-country rejection must not create an order');
        Assert::assertSame(0, Customer::query()->count(), 'unknown-country rejection must not create or resolve a customer');
        Assert::assertSame(0, OrderAddress::query()->count(), 'unknown-country rejection must not write an order address');
        Assert::assertSame(0, OrderTransaction::query()->count(), 'unknown-country rejection must not write a transaction');
    }

    /**
     * Spec: Cases/checkout-functional.md [12]
     *   "Placing an order with a state that does not belong to the chosen country is rejected as an invalid state."
     */
    public function stateOutsideChosenCountryIsRejected(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'billing_country' => 'US',
            'billing_state'   => 'XX',
        ]));

        Assert::assertSame(200, $status, 'field validation answers with its JSON envelope on HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('failed', (string) ($body['status'] ?? ''), 'foreign-state checkout must report failed');
        Assert::assertNotEmpty($body['errors']['billing_state']['invalid'] ?? null, 'foreign-state response must flag billing_state as invalid');
        Assert::assertSame(0, Order::query()->count(), 'foreign-state rejection must not create an order');
        Assert::assertSame(0, Customer::query()->count(), 'foreign-state rejection must not create or resolve a customer');
        Assert::assertSame(0, OrderAddress::query()->count(), 'foreign-state rejection must not write an order address');
        Assert::assertSame(0, OrderTransaction::query()->count(), 'foreign-state rejection must not write a transaction');
    }

    /**
     * Spec: Cases/checkout-functional.md [13]
     *   "Placing an order with a payment method the store does not offer is rejected and creates no order."
     */
    public function unknownPaymentMethodIsRejected(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            '_fct_pay_method' => 'gateway_that_does_not_exist',
        ]));

        Assert::assertSame(423, $status, 'an unknown payment method must be refused with HTTP 423. Body: ' . wp_json_encode($body));
        Assert::assertSame('failed', (string) ($body['status'] ?? ''), 'unknown-gateway checkout must report failed');
        Assert::assertStringContainsString('No valid payment method', (string) ($body['message'] ?? ''), 'unknown-gateway response must say no valid payment method was found');
        Assert::assertSame(0, Order::query()->count(), 'unknown-gateway rejection must not create an order');
        Assert::assertSame(0, Customer::query()->count(), 'unknown-gateway rejection must not create or resolve a customer');
        Assert::assertSame(0, OrderAddress::query()->count(), 'unknown-gateway rejection must not write an order address');
        Assert::assertSame(0, OrderTransaction::query()->count(), 'unknown-gateway rejection must not write a transaction');
    }

    /**
     * Spec: Cases/checkout-functional.md [14]
     *   "Placing an order for a cart that does not exist is rejected and creates no order."
     */
    public function unknownCartIsRejected(FunctionalTester $I): void
    {
        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload('cart-hash-that-does-not-exist'));

        Assert::assertSame(200, $status, 'the empty-cart rejection uses the default wp_send_json HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('failed', (string) ($body['status'] ?? ''), 'unknown-cart checkout must report failed');
        Assert::assertStringContainsString('Cart is empty', (string) ($body['message'] ?? ''), 'unknown-cart response must explain that there is no cart to check out');
        Assert::assertSame(0, Order::query()->count(), 'unknown-cart rejection must not create an order');
        Assert::assertSame(0, Customer::query()->count(), 'unknown-cart rejection must not create or resolve a customer');
        Assert::assertSame(0, OrderAddress::query()->count(), 'unknown-cart rejection must not write an order address');
        Assert::assertSame(0, OrderTransaction::query()->count(), 'unknown-cart rejection must not write a transaction');
    }

    /**
     * Spec: Cases/checkout-functional.md [15]
     *   "Placing an order whose cart line points at a variation that no longer exists is rejected
     *    before any customer or order is created."
     */
    public function cartLineForDeletedVariationIsRejectedBeforeAnythingIsCreated(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        ProductVariation::query()->where('id', $variation->id)->delete();

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));

        Assert::assertSame(422, $status, 'an unavailable cart line must be refused with HTTP 422. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('is not available', (string) ($body['message'] ?? ''), 'deleted-variation response must say the item is not available');
        Assert::assertSame(0, Order::query()->count(), 'deleted-variation rejection must not create an order');
        Assert::assertSame(0, Customer::query()->count(), 'deleted-variation rejection must not create or resolve a customer');
        Assert::assertSame(0, OrderAddress::query()->count(), 'deleted-variation rejection must not write an order address');
        Assert::assertSame(0, OrderTransaction::query()->count(), 'deleted-variation rejection must not write a transaction');
    }

    /**
     * Spec: Cases/checkout-functional.md [16]
     *   "Placing an order for a product that was trashed after it was added to the cart is rejected as unavailable."
     */
    public function trashedProductInCartIsRejectedAsUnavailable(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create();
        $variation = $product->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        wp_update_post(['ID' => $product->ID, 'post_status' => 'trash']);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));

        Assert::assertSame(422, $status, 'a trashed product must be refused with HTTP 422. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('is not available', (string) ($body['message'] ?? ''), 'trashed-product response must say the item is not available');
        Assert::assertSame(0, Order::query()->count(), 'trashed-product rejection must not create an order');
        Assert::assertSame(0, Customer::query()->count(), 'trashed-product rejection must not create or resolve a customer');
        Assert::assertSame(0, OrderAddress::query()->count(), 'trashed-product rejection must not write an order address');
        Assert::assertSame(0, OrderTransaction::query()->count(), 'trashed-product rejection must not write a transaction');
    }

    /**
     * Spec: Cases/checkout-functional.md [17]
     *   "Placing an order for more units than a stock-managed variation has available is rejected
     *    and tells the shopper how many are left."
     */
    public function orderingMoreThanAvailableStockIsRejected(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $variation = (new ProductFactory())->withDetailAttributes(['manage_stock' => 1])->withVariantAttributes([
            'manage_stock' => 1,
            'total_stock'  => 1,
            'available'    => 1,
        ])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 3]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));

        Assert::assertSame(422, $status, 'over-ordering must be refused with HTTP 422. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('Only 1 left', (string) ($body['message'] ?? ''), 'over-ordering response must tell the shopper how many units remain');
        Assert::assertSame(0, Order::query()->count(), 'over-ordering rejection must not create an order');
        Assert::assertSame(0, Customer::query()->count(), 'over-ordering rejection must not create or resolve a customer');
        Assert::assertSame(0, OrderAddress::query()->count(), 'over-ordering rejection must not write an order address');
        Assert::assertSame(0, OrderTransaction::query()->count(), 'over-ordering rejection must not write a transaction');
        Assert::assertSame(1, (int) ProductVariation::query()->find($variation->id)->available, 'over-ordering rejection must leave available stock untouched');
    }

    /**
     * Spec: Cases/checkout-functional.md [18]
     *   "Markup submitted inside the billing name is stripped before the order and customer are stored."
     */
    public function markupInBillingNameIsStrippedBeforeStorage(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'billing_full_name'  => '<script>alert(1)</script>Mallory',
            'billing_first_name' => '<script>alert(1)</script>Mallory',
            'billing_last_name'  => '<b>Attacker</b>',
        ]));

        Assert::assertSame(200, $status, 'a name carrying markup is still a placeable order once stripped. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'markup-name checkout must succeed after sanitizing');

        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order to inspect');
        $order = Order::query()->first();
        $billing = OrderAddress::query()->where('order_id', $order->id)->where('type', 'billing')->first();
        // sanitize_text_field() strips the tags AND their script content, so exactly "Mallory" survives;
        // a naive strip_tags() would leave "alert(1)Mallory" and must fail here.
        Assert::assertSame('Mallory', (string) $billing->name, 'stored billing name must be exactly the sanitized human-readable part');

        Assert::assertSame(1, Customer::query()->count(), 'the markup-name checkout must create exactly one customer');
        $customer = Customer::query()->first();
        Assert::assertSame('Mallory', (string) $customer->first_name, 'stored customer first name must be exactly the sanitized value');
        // Default field settings run in full-name mode (first/last name fields disabled), so the
        // submitted billing_last_name is ignored entirely and the sanitized full name becomes first_name.
        Assert::assertSame('', (string) $customer->last_name, 'in full-name mode the disabled last-name field must not reach storage at all');
    }

    /**
     * Spec: Cases/checkout-functional.md [26]
     *   "Placing an order for a country that has states, with the state left blank, is rejected
     *    as a missing state and creates no order."
     */
    public function blankStateForCountryWithStatesIsRejected(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'billing_country' => 'US',
            'billing_state'   => '',
        ]));

        Assert::assertSame(200, $status, 'field validation answers with its JSON envelope on HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('failed', (string) ($body['status'] ?? ''), 'blank-state checkout must report failed');
        Assert::assertNotEmpty($body['errors']['billing_state']['required'] ?? null, 'blank-state response must flag billing_state as required for a country that has states');
        Assert::assertArrayNotHasKey('billing_country', $body['errors'] ?? [], 'a valid country must not be reported alongside the missing state');
        Assert::assertSame(0, Order::query()->count(), 'blank-state rejection must not create an order');
        Assert::assertSame(0, Customer::query()->count(), 'blank-state rejection must not create or resolve a customer');
        Assert::assertSame(0, OrderAddress::query()->count(), 'blank-state rejection must not write an order address');
        Assert::assertSame(0, OrderTransaction::query()->count(), 'blank-state rejection must not write a transaction');
    }
}
