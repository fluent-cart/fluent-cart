<?php

namespace Tests\Integration\Resources;

use FluentCart\Api\Resource\CustomerAddressResource;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\CustomerAddressFactory;

class CustomerAddressResourceTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private CustomerAddressFactory $addressFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->addressFactory = new CustomerAddressFactory();
    }

    // =========================================================================
    // Spec 76: create() — auto-sets is_primary=1 if no primary exists
    // =========================================================================

    public function test_create_first_address_auto_sets_primary(): void
    {
        $customer = $this->customerFactory->create();

        $result = CustomerAddressResource::create([
            'name'      => 'John Doe',
            'email'     => $customer->email,
            'phone'     => '555-0100',
            'address_1' => '123 Main St',
            'city'      => 'New York',
            'state'     => 'NY',
            'postcode'  => '10001',
            'country'   => 'US',
            'type'      => 'billing',
        ], ['id' => $customer->id]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertSame(1, (int)$result['data']->is_primary);
    }

    // =========================================================================
    // Spec 77: create() — second address is not primary
    // =========================================================================

    public function test_create_second_address_is_not_primary(): void
    {
        $customer = $this->customerFactory->create();
        $this->addressFactory->create($customer->id, [
            'type'       => 'billing',
            'is_primary' => 1,
            'email'      => $customer->email,
        ]);

        $result = CustomerAddressResource::create([
            'name'      => 'Second Address',
            'email'     => $customer->email,
            'phone'     => '555-0200',
            'address_1' => '456 Other St',
            'city'      => 'Chicago',
            'state'     => 'IL',
            'postcode'  => '60601',
            'country'   => 'US',
            'type'      => 'billing',
        ], ['id' => $customer->id]);

        $this->assertSame(0, (int)$result['data']->is_primary);
    }

    // =========================================================================
    // Spec 79: find() — returns address fields
    // =========================================================================

    public function test_find_returns_address_fields_by_type(): void
    {
        $customer = $this->customerFactory->create();
        $this->addressFactory->create($customer->id, [
            'type'       => 'billing',
            'is_primary' => 1,
            'address_1'  => '123 Main St',
            'city'       => 'New York',
            'country'    => 'US',
            'email'      => $customer->email,
        ]);

        $result = CustomerAddressResource::find($customer->id, ['type' => 'billing']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('address_1', $result);
        $this->assertArrayHasKey('city', $result);
        $this->assertArrayHasKey('country', $result);
        $this->assertSame('123 Main St', $result['address_1']);
    }

    // =========================================================================
    // Spec 80: find() — empty when no addresses
    // =========================================================================

    public function test_find_returns_empty_when_no_addresses(): void
    {
        $customer = $this->customerFactory->create();
        $result = CustomerAddressResource::find($customer->id, ['type' => 'billing']);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // Spec 81: update() — updates fields
    // =========================================================================

    public function test_update_address_fields(): void
    {
        $customer = $this->customerFactory->create();
        $address = $this->addressFactory->create($customer->id, ['email' => $customer->email]);

        $result = CustomerAddressResource::update([
            'city' => 'Updated City',
        ], $address->id);

        $this->assertIsArray($result);
        $this->assertSame('Updated City', $result['data']->city);
    }

    // =========================================================================
    // Spec 82: update() — null ID returns error
    // =========================================================================

    public function test_update_null_id_returns_error(): void
    {
        $result = CustomerAddressResource::update(['city' => 'X'], null);
        $this->assertTrue(is_wp_error($result));
    }

    // =========================================================================
    // Spec 83: update() — non-existent ID returns error
    // =========================================================================

    public function test_update_nonexistent_id_returns_error(): void
    {
        $result = CustomerAddressResource::update(['city' => 'X'], 999999);
        $this->assertTrue(is_wp_error($result));
    }

    // =========================================================================
    // Spec 85: delete() — cannot delete primary address
    // =========================================================================

    public function test_delete_primary_address_returns_error(): void
    {
        $customer = $this->customerFactory->create();
        $address = $this->addressFactory->create($customer->id, [
            'is_primary' => 1,
            'email'      => $customer->email,
        ]);
        // Need at least 2 addresses so the "at least one" check doesn't fire first
        $this->addressFactory->create($customer->id, [
            'is_primary' => 0,
            'email'      => $customer->email,
            'address_1'  => '456 Other St',
        ]);

        $result = CustomerAddressResource::delete($address->id);
        $this->assertTrue(is_wp_error($result));
    }

    // =========================================================================
    // Spec 86: delete() — cannot delete last address
    // =========================================================================

    public function test_delete_last_remaining_address_returns_error(): void
    {
        $customer = $this->customerFactory->create();
        $address = $this->addressFactory->create($customer->id, [
            'is_primary' => 0,
            'email'      => $customer->email,
        ]);

        $result = CustomerAddressResource::delete($address->id);
        $this->assertTrue(is_wp_error($result));
    }

    // =========================================================================
    // Spec 87: delete() — deletes non-primary when multiple exist
    // =========================================================================

    public function test_delete_non_primary_address_succeeds(): void
    {
        $customer = $this->customerFactory->create();
        $this->addressFactory->create($customer->id, [
            'is_primary' => 1,
            'email'      => $customer->email,
        ]);
        $secondary = $this->addressFactory->create($customer->id, [
            'is_primary' => 0,
            'email'      => $customer->email,
            'address_1'  => '456 Other St',
        ]);

        $result = CustomerAddressResource::delete($secondary->id);
        $this->assertIsArray($result);
        $this->assertNull(CustomerAddresses::find($secondary->id));
    }

    // =========================================================================
    // Spec 88: delete() — null ID returns error
    // =========================================================================

    public function test_delete_null_id_returns_error(): void
    {
        $result = CustomerAddressResource::delete(null);
        $this->assertTrue(is_wp_error($result));
    }

    public function test_delete_nonexistent_id_returns_error(): void
    {
        $result = CustomerAddressResource::delete(999999);
        $this->assertTrue(is_wp_error($result));
    }

    // =========================================================================
    // Spec 89: makePrimary() — switches primary
    // =========================================================================

    public function test_make_primary_switches_primary_address(): void
    {
        $customer = $this->customerFactory->create();
        $addr1 = $this->addressFactory->create($customer->id, [
            'type'       => 'billing',
            'is_primary' => 1,
            'email'      => $customer->email,
        ]);
        $addr2 = $this->addressFactory->create($customer->id, [
            'type'       => 'billing',
            'is_primary' => 0,
            'email'      => $customer->email,
            'address_1'  => '456 Other St',
        ]);

        $result = CustomerAddressResource::makePrimary($customer->id, $addr2->id, 'billing');

        $this->assertIsArray($result);
        $this->assertSame(0, (int)CustomerAddresses::find($addr1->id)->is_primary);
        $this->assertSame(1, (int)CustomerAddresses::find($addr2->id)->is_primary);
    }

    // =========================================================================
    // Spec 90: makePrimary() — error if address doesn't match
    // =========================================================================

    public function test_make_primary_wrong_customer_returns_error(): void
    {
        $c1 = $this->customerFactory->create();
        $c2 = $this->customerFactory->create();
        $addr = $this->addressFactory->create($c1->id, [
            'type'  => 'billing',
            'email' => $c1->email,
        ]);

        // Try to make it primary for a different customer
        $result = CustomerAddressResource::makePrimary($c2->id, $addr->id, 'billing');
        $this->assertTrue(is_wp_error($result));
    }

    // =========================================================================
    // Spec 78: create() with order_id creates OrderAddress
    // =========================================================================

    public function test_create_primary_address_with_order_id_creates_order_address(): void
    {
        $customer = $this->customerFactory->create();
        $order = Order::query()->create([
            'customer_id'    => $customer->id,
            'payment_status' => 'pending',
            'total_amount'   => 1000,
            'order_status'   => 'pending',
            'currency'       => 'USD',
        ]);

        // No primary billing address exists, so this will auto-set is_primary=1
        $result = CustomerAddressResource::create([
            'name'      => 'John Doe',
            'email'     => $customer->email,
            'phone'     => '555-0100',
            'address_1' => '123 Main St',
            'city'      => 'New York',
            'state'     => 'NY',
            'postcode'  => '10001',
            'country'   => 'US',
            'type'      => 'billing',
        ], ['id' => $customer->id, 'order_id' => $order->id]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);

        // Verify an OrderAddress was created for the order
        $orderAddress = OrderAddress::query()
            ->where('order_id', $order->id)
            ->where('type', 'billing')
            ->first();

        $this->assertNotNull($orderAddress, 'OrderAddress should be created when order_id is provided and address is primary');
        $this->assertSame('123 Main St', $orderAddress->address_1);
    }

    public function test_create_non_primary_address_with_order_id_does_not_sync(): void
    {
        $customer = $this->customerFactory->create();
        // Create existing primary so new one won't be auto-primary
        $this->addressFactory->create($customer->id, [
            'type'       => 'billing',
            'is_primary' => 1,
            'email'      => $customer->email,
        ]);

        $order = Order::query()->create([
            'customer_id'    => $customer->id,
            'payment_status' => 'pending',
            'total_amount'   => 1000,
            'order_status'   => 'pending',
            'currency'       => 'USD',
        ]);

        $result = CustomerAddressResource::create([
            'name'      => 'Secondary',
            'email'     => $customer->email,
            'phone'     => '555-0200',
            'address_1' => '456 Other St',
            'city'      => 'Chicago',
            'state'     => 'IL',
            'postcode'  => '60601',
            'country'   => 'US',
            'type'      => 'billing',
        ], ['id' => $customer->id, 'order_id' => $order->id]);

        $this->assertIsArray($result);
        // Non-primary address should NOT sync to order
        $orderAddress = OrderAddress::query()
            ->where('order_id', $order->id)
            ->where('type', 'billing')
            ->first();

        $this->assertNull($orderAddress, 'Non-primary address should not create OrderAddress');
    }

    // =========================================================================
    // Spec 84: update() with order_id syncs to OrderAddress
    // =========================================================================

    public function test_update_address_with_order_id_updates_order_address(): void
    {
        $customer = $this->customerFactory->create();
        $address = $this->addressFactory->create($customer->id, [
            'type'       => 'billing',
            'is_primary' => 1,
            'city'       => 'Old City',
            'email'      => $customer->email,
        ]);

        $order = Order::query()->create([
            'customer_id'    => $customer->id,
            'payment_status' => 'pending',
            'total_amount'   => 1000,
            'order_status'   => 'pending',
            'currency'       => 'USD',
        ]);

        // Create an existing order address to be updated
        OrderAddress::query()->create([
            'order_id'  => $order->id,
            'type'      => 'billing',
            'name'      => 'Old Name',
            'address_1' => '999 Old St',
            'city'      => 'Old City',
            'country'   => 'US',
        ]);

        $result = CustomerAddressResource::update([
            'city' => 'New City',
            'name' => 'Updated Name',
            'type' => 'billing',
        ], $address->id, ['order_id' => $order->id]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);

        // Verify the OrderAddress was updated too
        $orderAddress = OrderAddress::query()
            ->where('order_id', $order->id)
            ->where('type', 'billing')
            ->first();

        $this->assertNotNull($orderAddress);
        $this->assertSame('New City', $orderAddress->city);
    }

    // =========================================================================
    // Spec 144: create with order_id — updates existing OrderAddress of same type
    // =========================================================================

    public function test_create_primary_address_with_order_id_updates_existing_order_address(): void
    {
        $customer = $this->customerFactory->create();
        $order = Order::query()->create([
            'customer_id'    => $customer->id,
            'payment_status' => 'pending',
            'total_amount'   => 1000,
            'order_status'   => 'pending',
            'currency'       => 'USD',
        ]);

        // Pre-existing order address
        $existingOA = OrderAddress::query()->create([
            'order_id'  => $order->id,
            'type'      => 'shipping',
            'name'      => 'Original Name',
            'address_1' => '999 Original St',
            'city'      => 'Original City',
            'country'   => 'US',
        ]);

        // Create a new customer address that is primary (first of type shipping)
        $result = CustomerAddressResource::create([
            'name'      => 'New Name',
            'email'     => $customer->email,
            'phone'     => '555-0300',
            'address_1' => '123 New St',
            'city'      => 'New City',
            'state'     => 'NY',
            'postcode'  => '10001',
            'country'   => 'US',
            'type'      => 'shipping',
        ], ['id' => $customer->id, 'order_id' => $order->id]);

        $this->assertIsArray($result);

        // The existing order address should have been updated, not a new one created
        $orderAddresses = OrderAddress::query()
            ->where('order_id', $order->id)
            ->where('type', 'shipping')
            ->get();

        // Should still have only one shipping address for this order
        $this->assertSame(1, $orderAddresses->count(), 'Should update existing OrderAddress, not create a duplicate');
        $this->assertSame('New City', $orderAddresses->first()->city);
    }
}
