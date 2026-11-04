<?php

namespace Tests\Integration\Models;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerMeta;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;

class CustomerMetaTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
    }

    // =========================================================================
    // Spec 45: Table & Columns
    // =========================================================================

    public function test_customer_meta_table_has_expected_columns(): void
    {
        $expected = ['id', 'customer_id', 'meta_key', 'meta_value'];
        $this->assertColumnsExist('fct_customer_meta', $expected);
    }

    // =========================================================================
    // Spec 46: meta_value mutator encodes arrays with UNESCAPED_UNICODE
    // =========================================================================

    public function test_meta_value_encodes_arrays_and_decodes_back(): void
    {
        $customer = $this->customerFactory->create();
        $meta = CustomerMeta::query()->create([
            'customer_id' => $customer->id,
            'meta_key'    => 'complex_data',
            'meta_value'  => ['name' => 'Test', 'nested' => ['a' => 1]],
        ]);

        $fresh = CustomerMeta::find($meta->id);
        $this->assertIsArray($fresh->meta_value);
        $this->assertSame('Test', $fresh->meta_value['name']);
        $this->assertSame(1, $fresh->meta_value['nested']['a']);
    }

    public function test_meta_value_encodes_unicode_unescaped(): void
    {
        $customer = $this->customerFactory->create();
        $meta = CustomerMeta::query()->create([
            'customer_id' => $customer->id,
            'meta_key'    => 'unicode_test',
            'meta_value'  => ['text' => 'Straße München'],
        ]);

        // Read raw from DB to verify UNESCAPED_UNICODE
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}fct_customer_meta WHERE id = %d",
            $meta->id
        ));
        $this->assertStringContainsString('Straße', $raw);
        $this->assertStringContainsString('München', $raw);
    }

    // =========================================================================
    // Spec 47: meta_value getter returns original for non-JSON strings
    // =========================================================================

    public function test_meta_value_returns_plain_string_when_not_json(): void
    {
        $customer = $this->customerFactory->create();
        $meta = CustomerMeta::query()->create([
            'customer_id' => $customer->id,
            'meta_key'    => 'plain_string',
            'meta_value'  => 'just a string',
        ]);

        $fresh = CustomerMeta::find($meta->id);
        $this->assertSame('just a string', $fresh->meta_value);
    }

    // =========================================================================
    // Spec 48: customer() belongsTo
    // =========================================================================

    public function test_customer_meta_belongs_to_customer(): void
    {
        $customer = $this->customerFactory->create();
        $meta = CustomerMeta::query()->create([
            'customer_id' => $customer->id,
            'meta_key'    => 'rel_test',
            'meta_value'  => 'val',
        ]);

        $this->assertInstanceOf(Customer::class, $meta->customer);
        $this->assertSame($customer->id, $meta->customer->id);
    }

    // =========================================================================
    // Spec 142: Edge case — nested JSON round-trip with UNESCAPED_UNICODE
    // =========================================================================

    public function test_nested_json_with_unicode_round_trips(): void
    {
        $customer = $this->customerFactory->create();
        $data = [
            'company'  => 'Müller GmbH',
            'address'  => ['street' => 'Königstraße 10', 'city' => 'Zürich'],
            'tags'     => ['café', 'naïve'],
        ];

        $meta = CustomerMeta::query()->create([
            'customer_id' => $customer->id,
            'meta_key'    => 'nested_unicode',
            'meta_value'  => $data,
        ]);

        $fresh = CustomerMeta::find($meta->id);
        $this->assertSame('Müller GmbH', $fresh->meta_value['company']);
        $this->assertSame('Königstraße 10', $fresh->meta_value['address']['street']);
        $this->assertSame('café', $fresh->meta_value['tags'][0]);
    }
}
