<?php

namespace Tests\Integration\Models;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderAddressFactory;

class OrderAddressTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderAddressFactory $addressFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->addressFactory = new OrderAddressFactory();
    }

    // =========================================================================
    // Spec 83: Columns
    // =========================================================================

    public function test_order_addresses_table_has_expected_columns(): void
    {
        $expected = [
            'id', 'order_id', 'type', 'name', 'address_1', 'address_2',
            'city', 'state', 'postcode', 'country', 'meta',
        ];
        $this->assertColumnsExist('fct_order_addresses', $expected);
    }

    // =========================================================================
    // Spec 84: Meta JSON
    // =========================================================================

    public function test_meta_is_json_encoded_and_decoded(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $address = $this->addressFactory->create($order->id, [
            'meta' => ['other_data' => ['company_name' => 'Acme Inc']],
        ]);

        $fresh = OrderAddress::query()->find($address->id);
        $this->assertIsArray($fresh->meta);
        $this->assertSame('Acme Inc', $fresh->meta['other_data']['company_name']);
    }

    // =========================================================================
    // Spec 85-90: Name and Meta-based Attributes
    // =========================================================================

    public function test_first_name_returns_first_word_of_name(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $address = $this->addressFactory->create($order->id, ['name' => 'John Doe']);

        $this->assertSame('John', $address->first_name);
    }

    public function test_last_name_returns_last_word_of_name(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $address = $this->addressFactory->create($order->id, ['name' => 'John Doe']);

        $this->assertSame('Doe', $address->last_name);
    }

    public function test_full_name_returns_name(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $address = $this->addressFactory->create($order->id, ['name' => 'Jane Smith']);

        $this->assertSame('Jane Smith', $address->full_name);
    }

    public function test_company_name_stored_in_meta(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $address = $this->addressFactory->create($order->id);

        $address->company_name = 'Acme Corp';
        $address->save();

        $fresh = OrderAddress::query()->find($address->id);
        $this->assertSame('Acme Corp', $fresh->company_name);
    }

    public function test_phone_stored_in_meta(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $address = $this->addressFactory->create($order->id);

        $address->phone = '555-1234';
        $address->save();

        $fresh = OrderAddress::query()->find($address->id);
        $this->assertSame('555-1234', $fresh->phone);
    }

    public function test_label_stored_in_meta(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $address = $this->addressFactory->create($order->id);

        $address->label = 'Home';
        $address->save();

        $fresh = OrderAddress::query()->find($address->id);
        $this->assertSame('Home', $fresh->label);
    }

    // =========================================================================
    // Spec 91: Relationships
    // =========================================================================

    public function test_order_address_belongs_to_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $address = $this->addressFactory->create($order->id);

        $this->assertInstanceOf(Order::class, $address->order);
        $this->assertSame($order->id, $address->order->id);
    }

    // =========================================================================
    // Spec 92-93: Address Formatting
    // =========================================================================

    public function test_get_formatted_address_returns_country_name_not_code(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $address = $this->addressFactory->create($order->id, [
            'country' => 'US',
            'state'   => 'NY',
            'city'    => 'New York',
        ]);

        $formatted = $address->getFormattedAddress();
        $this->assertIsArray($formatted);
        $this->assertArrayHasKey('country', $formatted);
        $this->assertArrayHasKey('state', $formatted);
        $this->assertArrayHasKey('full_address', $formatted);
        // Country should be resolved name, not code
        $this->assertNotSame('US', $formatted['country']);
    }

    public function test_get_address_as_text_returns_comma_separated(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $address = $this->addressFactory->create($order->id, [
            'name'      => 'John Doe',
            'address_1' => '123 Main St',
            'city'      => 'New York',
            'state'     => 'NY',
            'postcode'  => '10001',
            'country'   => 'US',
        ]);

        $text = $address->getAddressAsText();
        $this->assertIsString($text);
        $this->assertStringContainsString('John Doe', $text);
        $this->assertStringContainsString('123 Main St', $text);
        $this->assertStringContainsString(',', $text);
    }
}
