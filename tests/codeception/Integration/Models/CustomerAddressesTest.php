<?php

namespace Tests\Integration\Models;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\CustomerAddressFactory;

class CustomerAddressesTest extends DatabaseTestCase
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
    // Spec 37: Table & Columns (no company_name column)
    // =========================================================================

    public function test_customer_addresses_table_has_expected_columns(): void
    {
        $expected = [
            'id', 'customer_id', 'is_primary', 'type', 'status', 'label', 'name',
            'address_1', 'address_2', 'city', 'state', 'postcode', 'country',
            'phone', 'email', 'meta', 'created_at', 'updated_at',
        ];
        $this->assertColumnsExist('fct_customer_addresses', $expected);
    }

    public function test_customer_addresses_table_has_no_company_name_column(): void
    {
        $this->assertFalse(
            $this->hasColumn('fct_customer_addresses', 'company_name'),
            'company_name should NOT be a real column — it is virtual, derived from meta JSON'
        );
    }

    // =========================================================================
    // Spec 38: meta mutator/accessor
    // =========================================================================

    public function test_meta_mutator_encodes_and_decodes_json(): void
    {
        $customer = $this->customerFactory->create();
        $address = $this->addressFactory->create($customer->id, [
            'meta' => ['key' => 'value'],
            'email' => $customer->email,
        ]);

        $fresh = CustomerAddresses::find($address->id);
        $this->assertIsArray($fresh->meta);
        $this->assertSame('value', $fresh->meta['key']);
    }

    public function test_meta_null_returns_empty_array(): void
    {
        $customer = $this->customerFactory->create();
        $address = $this->addressFactory->create($customer->id, [
            'meta' => null,
            'email' => $customer->email,
        ]);

        $fresh = CustomerAddresses::find($address->id);
        $this->assertIsArray($fresh->meta);
        $this->assertEmpty($fresh->meta);
    }

    // =========================================================================
    // Spec 39: company_name virtual attribute
    // =========================================================================

    public function test_company_name_stores_in_meta_and_reads_back(): void
    {
        $customer = $this->customerFactory->create();
        $address = $this->addressFactory->create($customer->id, [
            'email' => $customer->email,
        ]);

        $address->company_name = 'Acme Corp';
        $address->save();

        $fresh = CustomerAddresses::find($address->id);
        $this->assertSame('Acme Corp', $fresh->company_name);

        // Verify it's stored inside meta JSON, not a separate column
        $meta = $fresh->meta;
        $this->assertSame('Acme Corp', $meta['other_data']['company_name'] ?? null);
    }

    // =========================================================================
    // Spec 40: formatted_address accessor
    // =========================================================================

    public function test_formatted_address_returns_expected_keys(): void
    {
        $customer = $this->customerFactory->create();
        $address = $this->addressFactory->create($customer->id, [
            'name'      => 'John Doe',
            'address_1' => '123 Main St',
            'address_2' => 'Apt 4',
            'city'      => 'New York',
            'state'     => 'NY',
            'postcode'  => '10001',
            'country'   => 'US',
            'phone'     => '555-0100',
            'label'     => 'Home',
            'email'     => $customer->email,
        ]);

        $formatted = $address->formatted_address;

        $this->assertArrayHasKey('country', $formatted);
        $this->assertArrayHasKey('state', $formatted);
        $this->assertArrayHasKey('city', $formatted);
        $this->assertArrayHasKey('postcode', $formatted);
        $this->assertArrayHasKey('address_1', $formatted);
        $this->assertArrayHasKey('address_2', $formatted);
        $this->assertArrayHasKey('type', $formatted);
        $this->assertArrayHasKey('name', $formatted);
        $this->assertArrayHasKey('company_name', $formatted);
        $this->assertArrayHasKey('label', $formatted);
        $this->assertArrayHasKey('phone', $formatted);
        $this->assertArrayHasKey('full_address', $formatted);
        $this->assertSame('New York', $formatted['city']);
        $this->assertStringContainsString('123 Main St', $formatted['full_address']);
    }

    // =========================================================================
    // Spec 41: Scopes ofActive / ofArchived
    // =========================================================================

    public function test_scope_of_active_and_archived(): void
    {
        $customer = $this->customerFactory->create();
        $this->addressFactory->create($customer->id, ['status' => 'active', 'email' => $customer->email]);
        $this->addressFactory->create($customer->id, ['status' => 'archived', 'email' => $customer->email, 'address_1' => '456 Other St']);

        $active = CustomerAddresses::query()->ofActive()->where('customer_id', $customer->id)->get();
        $archived = CustomerAddresses::query()->ofArchived()->where('customer_id', $customer->id)->get();

        $this->assertCount(1, $active);
        $this->assertCount(1, $archived);
    }

    // =========================================================================
    // Spec 42: customer() belongsTo
    // =========================================================================

    public function test_address_belongs_to_customer(): void
    {
        $customer = $this->customerFactory->create();
        $address = $this->addressFactory->create($customer->id, ['email' => $customer->email]);

        $this->assertInstanceOf(Customer::class, $address->customer);
        $this->assertSame($customer->id, $address->customer->id);
    }

    // =========================================================================
    // Spec 43-44: getFormattedDataForCheckout
    // =========================================================================

    public function test_formatted_data_for_checkout_billing_prefix(): void
    {
        $customer = $this->customerFactory->create();
        $address = $this->addressFactory->create($customer->id, [
            'name'      => 'Jane Doe',
            'address_1' => '789 Oak Ave',
            'city'      => 'Chicago',
            'country'   => 'US',
            'email'     => $customer->email,
        ]);

        $data = $address->getFormattedDataForCheckout('billing_');

        $this->assertArrayHasKey('billing_full_name', $data);
        $this->assertArrayHasKey('billing_address_1', $data);
        $this->assertArrayHasKey('billing_city', $data);
        $this->assertArrayHasKey('billing_country', $data);
        $this->assertArrayHasKey('billing_company_name', $data);
        $this->assertSame('Jane Doe', $data['billing_full_name']);
    }

    public function test_formatted_data_for_checkout_shipping_prefix(): void
    {
        $customer = $this->customerFactory->create();
        $address = $this->addressFactory->create($customer->id, [
            'name'      => 'Jane Doe',
            'address_1' => '789 Oak Ave',
            'city'      => 'Chicago',
            'country'   => 'US',
            'email'     => $customer->email,
        ]);

        $data = $address->getFormattedDataForCheckout('shipping_');

        $this->assertArrayHasKey('shipping_full_name', $data);
        $this->assertArrayHasKey('shipping_address_1', $data);
        $this->assertArrayHasKey('shipping_city', $data);
    }
}
