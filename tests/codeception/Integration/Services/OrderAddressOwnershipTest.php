<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Models\OrderAddress;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

class OrderAddressOwnershipTest extends WPTestCase
{
    public function addressScenarios(): array
    {
        return [
            'owned billing' => ['billing', 'owned', true],
            'owned shipping' => ['shipping', 'owned', true],
            'foreign billing' => ['billing', 'foreign', false],
            'foreign shipping' => ['shipping', 'foreign', false],
            'missing billing' => ['billing', 'missing', false],
            'missing shipping' => ['shipping', 'missing', false],
            'typed billing' => ['billing', 'typed', false],
            'typed shipping' => ['shipping', 'typed', false],
        ];
    }

    /** @dataProvider addressScenarios */
    public function test_only_an_owned_saved_address_replaces_typed_order_details(string $type, string $selection, bool $useSaved): void
    {
        $customer = (new CustomerFactory())->create();
        $owner = $selection === 'owned' ? $customer : (new CustomerFactory())->create();
        $saved = (new CustomerAddressFactory())->create((int) $owner->id, [
            'type' => 'billing',
            'address_1' => '999 Saved Street',
            'phone' => '555-9999',
            'meta' => ['other_data' => [
                'company_name' => 'Saved Company',
                'vat_number' => 'SAVED-VAT',
                'legal_registration_id' => 'SAVED-REG',
            ]],
        ]);
        $snapshot = $saved->fresh()->toArray();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $typed = [
            'type' => $type,
            'full_name' => 'Typed Buyer',
            'address_1' => '1 Typed Street',
            'city' => 'Boston',
            'state' => 'MA',
            'postcode' => '02108',
            'country' => 'US',
            'phone' => '555-0000',
            'company_name' => 'Typed Company',
            'vat_number' => 'TYPED-VAT',
            'legal_registration_id' => 'TYPED-REG',
        ];
        if ($selection !== 'typed') {
            $typed['address'] = $selection === 'missing' ? $saved->id + 1000000 : $saved->id;
        }

        AddressHelper::createOrderAddress($order->id, $typed);

        $address = OrderAddress::query()->where('order_id', $order->id)->where('type', $type)->first();
        $this->assertNotNull($address, 'The order must retain an address even when the saved ID is unavailable.');
        $expected = $useSaved ? $snapshot : $typed;
        foreach (['address_1', 'city', 'state', 'postcode', 'country', 'phone', 'company_name', 'vat_number', 'legal_registration_id'] as $field) {
            $this->assertSame($expected[$field], $address->{$field}, 'The order must use only authorized ' . $field . ' data.');
        }
        $this->assertSame($type, $address->type, 'The requested billing or shipping role must be preserved.');
        if (!$useSaved) {
            $this->assertSame('Typed Buyer', $address->name, 'Fallback must preserve the typed name.');
        }
        $this->assertSame($snapshot, $saved->fresh()->toArray(), 'Creating an order snapshot must not modify the saved address.');
    }

    public function test_an_order_without_a_customer_cannot_copy_an_unowned_saved_address(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $order->update(['customer_id' => null]);
        $saved = (new CustomerAddressFactory())->create((int) $customer->id, ['address_1' => '999 Unowned Street']);
        $saved->update(['customer_id' => null]);

        AddressHelper::createOrderAddress($order->id, [
            'address' => $saved->id,
            'type' => 'billing',
            'full_name' => 'Typed Buyer',
            'address_1' => '1 Typed Street',
            'country' => 'US',
        ]);

        $address = OrderAddress::query()->where('order_id', $order->id)->where('type', 'billing')->first();
        $this->assertNotNull($address, 'Typed address data must remain available without a customer link.');
        $this->assertSame('1 Typed Street', $address->address_1, 'Missing customer IDs must not count as matching ownership.');
    }
}
