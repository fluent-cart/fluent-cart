<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * Spec: Cases/checkout-functional.md → "Retrying after a failed payment — the address on the form" section, items 81-84.
 *
 * Ticket #5548. The first payment fails, then the same cart is submitted again. The
 * retry must use the address on the form, as the first attempt does, and a saved
 * address only when the shopper picked it.
 */
class PlaceOrderRetryAddressCest
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
        $I->logout();
    }

    /**
     * Spec: Cases/checkout-functional.md [81]
     *   "A signed-in customer whose saved billing address has a country but no state can retry
     *    after a failed payment; the retry is accepted and the order carries the address they typed."
     */
    public function signedInRetryWithAStatelessSavedAddressIsAcceptedWithTheTypedAddress(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);
        (new CustomerAddressFactory())->create((int) $customer->id, [
            'is_primary' => 1,
            'country'    => 'BD',
            'state'      => null,
            'city'       => null,
            'address_1'  => null,
            'postcode'   => null,
            'phone'      => null,
        ]);
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1000])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $payload = $this->checkoutPayload($cart->cart_hash, [
            'billing_country'   => 'MX',
            'billing_state'     => 'DF',
            'billing_city'      => 'Ciudad de México',
            'billing_address_1' => 'Oaxaca 21',
            'billing_postcode'  => '06700',
        ]);

        $this->declineStubPayments();
        [$firstStatus, $firstBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);
        Assert::assertSame(422, $firstStatus, 'precondition: the first payment must fail at the gateway. Body: ' . wp_json_encode($firstBody));

        $this->acceptStubPayments();
        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);

        Assert::assertSame(200, $status, 'the retry must answer HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'the retry with the same valid address must pass validation. Body: ' . wp_json_encode($body));
        Assert::assertSame(1, Order::query()->count(), 'the retry must reuse the first order');
        $billing = OrderAddress::query()->where('order_id', (int) Order::query()->value('id'))->where('type', 'billing')->first();
        Assert::assertNotNull($billing, 'the order must have a billing address');
        Assert::assertSame('MX', (string) $billing->country, 'the order must keep the typed country, not the saved one');
        Assert::assertSame('DF', (string) $billing->state, 'the order must keep the typed state');
        Assert::assertSame('Oaxaca 21', (string) $billing->address_1, 'the order must keep the typed street');
    }

    /**
     * Spec: Cases/checkout-functional.md [82]
     *   "A signed-in customer who typed an address keeps it on the order after a failed payment
     *    and a retry, even with a complete saved address in another country on their profile."
     */
    public function signedInRetryKeepsTheTypedAddressOverACompleteSavedOne(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);
        (new CustomerAddressFactory())->create((int) $customer->id, ['is_primary' => 1, 'address_1' => '20 Saved Street']);
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1000])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $payload = $this->checkoutPayload($cart->cart_hash, [
            'billing_country'   => 'MX',
            'billing_state'     => 'DF',
            'billing_city'      => 'Ciudad de México',
            'billing_address_1' => 'Oaxaca 21',
            'billing_postcode'  => '06700',
            'billing_phone'     => '5585722442',
        ]);

        $this->declineStubPayments();
        [$firstStatus, $firstBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);
        Assert::assertSame(422, $firstStatus, 'precondition: the first payment must fail at the gateway. Body: ' . wp_json_encode($firstBody));

        $this->acceptStubPayments();
        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);

        Assert::assertSame(200, $status, 'the retry must answer HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'the retry must be accepted. Body: ' . wp_json_encode($body));
        Assert::assertSame(1, Order::query()->count(), 'the retry must reuse the first order');
        $billing = OrderAddress::query()->where('order_id', (int) Order::query()->value('id'))->where('type', 'billing')->first();
        Assert::assertNotNull($billing, 'the order must have a billing address');
        Assert::assertSame('Oaxaca 21', (string) $billing->address_1, 'the retry must not swap in the saved street');
        Assert::assertSame('MX', (string) $billing->country, 'the retry must not swap in the saved country');
        Assert::assertSame('5585722442', (string) $billing->phone, 'the retry must not swap in the saved phone');
    }

    /**
     * Spec: Cases/checkout-functional.md [83]
     *   "A signed-in customer who picked a saved address keeps that saved address on the order
     *    after a failed payment and a retry."
     */
    public function signedInRetryKeepsThePickedSavedAddress(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);
        $saved = (new CustomerAddressFactory())->create((int) $customer->id, ['is_primary' => 1, 'address_1' => '20 Saved Street']);
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1000])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $payload = $this->checkoutPayload($cart->cart_hash, ['billing_address_id' => $saved->id]);

        $this->declineStubPayments();
        [$firstStatus, $firstBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);
        Assert::assertSame(422, $firstStatus, 'precondition: the first payment must fail at the gateway. Body: ' . wp_json_encode($firstBody));

        $this->acceptStubPayments();
        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);

        Assert::assertSame(200, $status, 'the retry must answer HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'the retry must be accepted. Body: ' . wp_json_encode($body));
        Assert::assertSame(1, Order::query()->count(), 'the retry must reuse the first order');
        $billing = OrderAddress::query()->where('order_id', (int) Order::query()->value('id'))->where('type', 'billing')->first();
        Assert::assertNotNull($billing, 'the order must have a billing address');
        Assert::assertSame('20 Saved Street', (string) $billing->address_1, 'the retry must keep the saved address the customer picked');
    }

    /**
     * Spec: Cases/checkout-functional.md [84]
     *   "A guest using a saved customer's email keeps the address they typed after a failed payment
     *    and a retry; the saved customer's street and phone never reach the order."
     */
    public function guestRetryWithASavedCustomersEmailKeepsTheTypedAddress(FunctionalTester $I): void
    {
        $owner = (new CustomerFactory())->create(['email' => 'owner@example.test']);
        (new CustomerAddressFactory())->create((int) $owner->id, [
            'is_primary' => 1,
            'address_1'  => '999 Owner Ave',
            'phone'      => '555-9999',
        ]);
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1000])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $payload = $this->checkoutPayload($cart->cart_hash, [
            'billing_email'     => 'owner@example.test',
            'billing_address_1' => '1 Typed Street',
            'billing_phone'     => '555-0000',
        ]);

        $this->declineStubPayments();
        [$firstStatus, $firstBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);
        Assert::assertSame(422, $firstStatus, 'precondition: the first payment must fail at the gateway. Body: ' . wp_json_encode($firstBody));

        $this->acceptStubPayments();
        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);

        Assert::assertSame(200, $status, 'the guest retry must answer HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'the guest retry must be accepted. Body: ' . wp_json_encode($body));
        Assert::assertSame(1, Order::query()->count(), 'the retry must reuse the first order');
        $billing = OrderAddress::query()->where('order_id', (int) Order::query()->value('id'))->where('type', 'billing')->first();
        Assert::assertNotNull($billing, 'the order must have a billing address');
        Assert::assertSame('1 Typed Street', (string) $billing->address_1, "the saved customer's street must not reach the guest's order");
        Assert::assertSame('555-0000', (string) $billing->phone, "the saved customer's phone must not reach the guest's order");
    }
}
