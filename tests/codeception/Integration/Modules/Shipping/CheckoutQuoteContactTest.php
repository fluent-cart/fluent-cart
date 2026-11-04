<?php

namespace Tests\Integration\Modules\Shipping;

use FluentCart\Api\Checkout\CheckoutApi;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Modules\Shipping\ShippingModule;
use FluentCart\App\Services\Renderer\CheckoutFieldsSchema;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Shipping\SourcedStubCarrier;

/**
 * Live carriers such as DHL Express quote only with the recipient's name, street
 * and phone. Checkout sends them once every one the form shows is filled, and
 * re-quotes when that full contact changes.
 */
class CheckoutQuoteContactTest extends DatabaseTestCase
{
    private const AREA = ['postcode' => '10001', 'city' => 'New York'];
    private const EMPTY_CONTACT = ['name' => '', 'address_1' => '', 'phone' => ''];

    public function setUp(): void
    {
        parent::setUp();
        AddressHelper::resetRateCache();
        $this->clearLiveRateTransients();
        SourcedStubCarrier::install([], ['' => [SourcedStubCarrier::rate('carrier:stub_sourced:dhl', 4200)]]);
        $this->useFields([]);
    }

    public function tearDown(): void
    {
        SourcedStubCarrier::uninstall();
        AddressHelper::resetRateCache();
        $this->clearLiveRateTransients();
        $this->useFields([]);
        parent::tearDown();
    }

    public function test_contact_counts_only_once_every_shown_field_is_filled(): void
    {
        // Defaults show the full name and street; phone is off.
        $this->assertSame(self::EMPTY_CONTACT, AddressHelper::quoteContact(['billing_full_name' => 'Jane Roe']));
        $this->assertSame(
            ['name' => 'Jane Roe', 'address_1' => '1 Main St', 'phone' => ''],
            AddressHelper::quoteContact(['billing_full_name' => 'Jane Roe', 'billing_address_1' => '1 Main St', 'billing_phone' => '555', 'billing_company_name' => 'Acme'])
        );

        $this->useFields(['billing_address' => ['phone' => ['enabled' => 'yes', 'required' => 'no']]]);
        $this->assertSame(self::EMPTY_CONTACT, AddressHelper::quoteContact(['billing_full_name' => 'Jane Roe', 'billing_address_1' => '1 Main St']));
        $this->assertSame(
            ['name' => 'Jane Roe', 'address_1' => '1 Main St', 'phone' => '+1 555 0100'],
            AddressHelper::quoteContact(['billing_full_name' => 'Jane Roe', 'billing_address_1' => '1 Main St', 'billing_phone' => '+1 555 0100'])
        );
    }

    public function test_contact_follows_ship_to_different_and_split_names(): void
    {
        $this->assertSame(
            ['name' => 'Sam Poe', 'address_1' => '9 Elm Rd', 'phone' => ''],
            AddressHelper::quoteContact([
                'ship_to_different'  => 'yes',
                'billing_full_name'  => 'Jane Roe',
                'billing_address_1'  => '1 Main St',
                'shipping_full_name' => 'Sam Poe',
                'shipping_address_1' => '9 Elm Rd',
            ])
        );

        $this->useFields(['basic_info' => [
            'full_name'  => ['enabled' => 'no', 'required' => 'no'],
            'first_name' => ['enabled' => 'yes', 'required' => 'yes'],
            'last_name'  => ['enabled' => 'yes', 'required' => 'no'],
        ]]);
        $this->assertSame(self::EMPTY_CONTACT, AddressHelper::quoteContact(['billing_first_name' => 'Jane', 'billing_address_1' => '1 Main St']));
        $this->assertSame(
            ['name' => 'Jane Roe', 'address_1' => '1 Main St', 'phone' => ''],
            AddressHelper::quoteContact(['billing_first_name' => 'Jane', 'billing_last_name' => 'Roe', 'billing_address_1' => '1 Main St'])
        );
    }

    public function test_no_contact_fields_without_a_live_carrier(): void
    {
        SourcedStubCarrier::$enabled = false;

        $this->assertSame([], AddressHelper::quoteContactFields(false));
        $this->assertSame(self::EMPTY_CONTACT, AddressHelper::quoteContact(['billing_full_name' => 'Jane Roe', 'billing_address_1' => '1 Main St']));
    }

    public function test_the_carrier_gets_the_contact_and_a_full_quote_is_cached_apart(): void
    {
        $contact = ['name' => 'Jane Roe', 'address_1' => '1 Main St', 'phone' => '+1 555 0100'];

        AddressHelper::getShippingMethods('US', 'NY', null, [], self::AREA);
        AddressHelper::resetRateCache();
        AddressHelper::getShippingMethods('US', 'NY', null, [], self::AREA + $contact);

        $this->assertSame(['', ''], SourcedStubCarrier::$fetches, 'the area-only quote is not served for the full address');
        $this->assertSame(self::EMPTY_CONTACT, array_intersect_key(SourcedStubCarrier::$destinations[0], self::EMPTY_CONTACT));
        $this->assertSame($contact, array_intersect_key(SourcedStubCarrier::$destinations[1], self::EMPTY_CONTACT));

        AddressHelper::resetRateCache();
        AddressHelper::getShippingMethods('US', 'NY', null, [], self::AREA + $contact);
        $this->assertSame(['', ''], SourcedStubCarrier::$fetches, 'the same full address answers from the cache');
    }

    public function test_place_order_quotes_the_same_contact(): void
    {
        $method = new \ReflectionMethod(CheckoutApi::class, 'shippingDestination');
        $method->setAccessible(true);

        [, , $destination] = $method->invoke(null, [
            'billing_country'   => 'US',
            'billing_full_name' => 'Jane Roe',
            'billing_address_1' => '1 Main St',
            'billing_postcode'  => '10001',
            'billing_city'      => 'New York',
        ]);

        $this->assertSame('Jane Roe', $destination['name']);
        $this->assertSame('1 Main St', $destination['address_1']);
    }

    public function test_a_contact_change_re_quotes_only_when_the_contact_is_complete(): void
    {
        $before = ['billing_country' => 'US', 'billing_state' => 'NY', 'billing_postcode' => '10001', 'billing_city' => 'New York', 'billing_full_name' => 'Jane Roe'];

        // Name alone, street still empty: nothing to re-quote.
        $this->assertFalse($this->requoted($before, ['billing_full_name' => 'Jane Q Roe']));
        // A field the quote does not use.
        $this->assertFalse($this->requoted($before + ['billing_address_1' => '1 Main St'], ['billing_company_name' => 'Acme']));
        // The street completes the contact.
        $this->assertTrue($this->requoted($before, ['billing_address_1' => '1 Main St']));
        // A later change to a filled field.
        $this->assertTrue($this->requoted($before + ['billing_address_1' => '1 Main St'], ['billing_full_name' => 'Jane Q Roe']));
        $this->assertSame('Jane Q Roe', end(SourcedStubCarrier::$destinations)['name']);

        SourcedStubCarrier::$enabled = false;
        $this->assertFalse($this->requoted($before, ['billing_address_1' => '1 Main St']), 'zone-only stores get no extra re-quote');
    }

    private function requoted(array $before, array $changes): bool
    {
        $cart = new class {
            public $checkout_data = [];

            public function requireShipping(): bool
            {
                return true;
            }
        };
        $cart->checkout_data = ['form_data' => $before];

        $fillData = ['cart_data' => [], 'checkout_data' => ['form_data' => array_merge($before, $changes)]];
        $out = (new ShippingModule())->maybeRecalculateShippingCharges($fillData, ['changes' => $changes, 'cart' => $cart]);

        return !empty($out['hook_changes']['shipping']);
    }

    private function useFields(array $overrides): void
    {
        fluent_cart_update_option('_fc_checkout_fields', $overrides ?: ['agree_terms' => ['enabled' => 'no']]);
        fluent_cart_get_option('_fc_checkout_fields', [], false);
        CheckoutFieldsSchema::resetFieldsCache();
    }

    private function clearLiveRateTransients(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%fluent\\_cart\\_live\\_rates\\_%'");
        wp_cache_flush();
    }
}
