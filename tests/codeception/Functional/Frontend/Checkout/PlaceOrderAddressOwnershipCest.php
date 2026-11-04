<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * A guest placing an order must never pull another customer's stored or order
 * address into their own order by posting its id. Replays the requests from
 * CVE-2026-103525 (Wordfence) and the WordPress.org report of 2026-10-15.
 */
class PlaceOrderAddressOwnershipCest
{
    use PlacesCheckoutOrders;

    const VICTIM_STREET = '999 Victim Ave';
    const VICTIM_PHONE = '555-9999';
    const ATTACKER_STREET = '1 Attacker St';

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

    public function guestPostingNoAddressIdGetsOwnAddressWhileAnotherCustomerHasOne(FunctionalTester $I): void
    {
        $this->seedVictimStoredAddress();

        $order = $this->placeAttackerOrder($I, []);

        $this->assertAttackerKeptOwnAddress($order, 'billing');
    }

    public function signedInCustomerCanUseTheirSavedAddressForBillingAndShipping(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);
        $saved = (new CustomerAddressFactory())->create((int) $customer->id, ['address_1' => '20 Saved Street']);

        $order = $this->placeAttackerOrder($I, [
            'billing_address' => $saved->id,
            'shipping_address' => $saved->id,
            'ship_to_different' => 'yes',
            'shipping_full_name' => 'Address Owner',
            'shipping_address_1' => self::ATTACKER_STREET,
            'shipping_city' => 'Boston',
            'shipping_state' => 'MA',
            'shipping_postcode' => '02108',
            'shipping_country' => 'US',
        ]);

        Assert::assertSame((int) $customer->id, (int) $order->customer_id, 'checkout must keep the authenticated customer');
        foreach (['billing', 'shipping'] as $type) {
            $address = OrderAddress::query()->where('order_id', $order->id)->where('type', $type)->first();
            Assert::assertNotNull($address, 'checkout must persist the selected ' . $type . ' address');
            Assert::assertSame('20 Saved Street', $address->address_1, 'authenticated checkout must retain owned saved-address selection');
        }
    }

    public function guestPostingAStoredAddressIdAsBillingAddressGetsOwnAddress(FunctionalTester $I): void
    {
        $victimAddress = $this->seedVictimStoredAddress();

        $order = $this->placeAttackerOrder($I, ['billing_address' => $victimAddress->id]);

        $this->assertAttackerKeptOwnAddress($order, 'billing');
    }

    public function guestPostingAStoredAddressIdAsBillingAddressIdGetsOwnAddress(FunctionalTester $I): void
    {
        $victimAddress = $this->seedVictimStoredAddress();

        $order = $this->placeAttackerOrder($I, [
            'billing_address'    => $victimAddress->id,
            'billing_address_id' => $victimAddress->id,
        ]);

        $this->assertAttackerKeptOwnAddress($order, 'billing');
    }

    public function guestMatchingTheSavedAddressOwnersEmailStillGetsTypedBillingAddress(FunctionalTester $I): void
    {
        $victimAddress = $this->seedVictimStoredAddress();

        $order = $this->placeAttackerOrder($I, [
            'billing_email' => 'victim@example.test',
            'billing_address' => $victimAddress->id,
        ]);

        $this->assertAttackerKeptOwnAddress($order, 'billing');
    }

    public function guestMatchingTheSavedAddressOwnersEmailStillGetsTypedShippingAddress(FunctionalTester $I): void
    {
        $victimAddress = $this->seedVictimStoredAddress('shipping');

        $order = $this->placeAttackerOrder($I, [
            'billing_email' => 'victim@example.test',
            'shipping_address' => $victimAddress->id,
            'ship_to_different' => 'yes',
            'shipping_full_name' => 'Att Acker',
            'shipping_address_1' => self::ATTACKER_STREET,
            'shipping_city' => 'Attacker City',
            'shipping_state' => 'CA',
            'shipping_postcode' => '90001',
            'shipping_country' => 'US',
        ]);

        $this->assertAttackerKeptOwnAddress($order, 'shipping');
    }

    public function guestPostingAStoredAddressIdAsBillingAddressIdOnlyGetsOwnAddress(FunctionalTester $I): void
    {
        $victimAddress = $this->seedVictimStoredAddress();

        $order = $this->placeAttackerOrder($I, ['billing_address_id' => $victimAddress->id]);

        $this->assertAttackerKeptOwnAddress($order, 'billing');
    }

    public function guestPostingAStoredAddressIdAsShippingAddressGetsOwnAddress(FunctionalTester $I): void
    {
        $victimAddress = $this->seedVictimStoredAddress('shipping');

        $order = $this->placeAttackerOrder($I, [
            'shipping_address'   => $victimAddress->id,
            'ship_to_different'  => 'yes',
            'shipping_full_name' => 'Att Acker',
            'shipping_address_1' => self::ATTACKER_STREET,
            'shipping_city'      => 'Attacker City',
            'shipping_state'     => 'CA',
            'shipping_postcode'  => '90001',
            'shipping_country'   => 'US',
        ]);

        $this->assertAttackerKeptOwnAddress($order, 'shipping');
    }

    public function guestPostingAnotherOrdersIdAndBillingAddressIdGetsOwnAddress(FunctionalTester $I): void
    {
        $victimOrder = $this->placeVictimOrder($I);
        $victimBilling = OrderAddress::query()->where('order_id', $victimOrder->id)->where('type', 'billing')->first();
        Assert::assertNotNull($victimBilling, 'precondition: the victim order has a billing address');

        $order = $this->placeAttackerOrder($I, [
            'order_id'           => $victimOrder->id,
            'billing_address_id' => $victimBilling->id,
        ]);

        $this->assertAttackerKeptOwnAddress($order, 'billing');
    }

    public function guestPostingAnotherOrdersIdAndShippingAddressIdGetsOwnAddress(FunctionalTester $I): void
    {
        $victimOrder = $this->placeVictimOrder($I, [
            'ship_to_different'  => 'yes',
            'shipping_full_name' => 'Victim Shipper',
            'shipping_address_1' => self::VICTIM_STREET,
            'shipping_city'      => 'Boston',
            'shipping_state'     => 'MA',
            'shipping_postcode'  => '02108',
            'shipping_country'   => 'US',
            'shipping_phone'     => self::VICTIM_PHONE,
        ]);
        $victimShipping = OrderAddress::query()->where('order_id', $victimOrder->id)->where('type', 'shipping')->first();
        Assert::assertNotNull($victimShipping, 'precondition: the victim order has a shipping address');

        $order = $this->placeAttackerOrder($I, [
            'order_id'            => $victimOrder->id,
            'billing_address_id'  => 1,
            'shipping_address_id' => $victimShipping->id,
            'ship_to_different'   => 'yes',
            'shipping_full_name'  => 'Att Acker',
            'shipping_address_1'  => self::ATTACKER_STREET,
            'shipping_city'       => 'Attacker City',
            'shipping_state'      => 'CA',
            'shipping_postcode'   => '90001',
            'shipping_country'    => 'US',
            'shipping_phone'      => '555-0000',
        ]);

        $this->assertAttackerKeptOwnAddress($order, 'shipping');
    }

    private function seedVictimStoredAddress(string $type = 'billing'): CustomerAddresses
    {
        $victim = (new CustomerFactory())->create(['email' => 'victim@example.test']);

        return CustomerAddresses::query()->create([
            'customer_id' => $victim->id,
            'is_primary'  => 1,
            'type'        => $type,
            'status'      => 'active',
            'name'        => 'Victim Customer',
            'address_1'   => self::VICTIM_STREET,
            'city'        => 'Boston',
            'state'       => 'MA',
            'postcode'    => '02108',
            'country'     => 'US',
            'phone'       => self::VICTIM_PHONE,
        ]);
    }

    private function placeVictimOrder(FunctionalTester $I, array $overrides = []): Order
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1000])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, array_merge([
            'billing_email'     => 'victim@example.test',
            'billing_full_name' => 'Victim Customer',
            'billing_address_1' => self::VICTIM_STREET,
            'billing_city'      => 'Boston',
            'billing_state'     => 'MA',
            'billing_postcode'  => '02108',
            'billing_phone'     => self::VICTIM_PHONE,
        ], $overrides)));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'precondition: victim order placed. Body: ' . wp_json_encode($body));

        return Order::query()->orderBy('id', 'DESC')->first();
    }

    private function placeAttackerOrder(FunctionalTester $I, array $overrides): Order
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1000])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $before = (int) Order::query()->max('id');

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, array_merge([
            'billing_email'     => 'attacker@example.test',
            'billing_full_name' => 'Att Acker',
            'billing_address_1' => self::ATTACKER_STREET,
            'billing_city'      => 'Attacker City',
            'billing_state'     => 'CA',
            'billing_postcode'  => '90001',
            'billing_phone'     => '555-0000',
        ], $overrides)));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'the guest order is accepted. Body: ' . wp_json_encode($body));
        $order = Order::query()->where('id', '>', $before)->first();
        Assert::assertNotNull($order, 'the guest order was written');

        return $order;
    }

    private function assertAttackerKeptOwnAddress(Order $order, string $type): void
    {
        $address = OrderAddress::query()->where('order_id', $order->id)->where('type', $type)->first();
        Assert::assertNotNull($address, "the guest order has a {$type} address");
        Assert::assertSame(self::ATTACKER_STREET, (string) $address->address_1, "the guest's {$type} street must be their own, not the victim's");
        Assert::assertNotSame(self::VICTIM_PHONE, (string) $address->phone, "the victim's phone must not reach the guest's {$type} address");
    }
}
