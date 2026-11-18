<?php

namespace Tests\Integration\Modules\Shipping;

use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Modules\Shipping\Carriers\Concerns\ProvidesLiveRates;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Shipping\SourcedStubCarrier;

/**
 * A live carrier that builds its API request (liveRatesRequest()) is cached by
 * that request: a form field it sends changes the quote, one it ignores does not.
 * A carrier without one keeps the old destination key.
 */
class LiveRatesRequestKeyTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        AddressHelper::resetRateCache();
        $this->clearLiveRateTransients();
        SourcedStubCarrier::install([], ['' => [SourcedStubCarrier::rate('carrier:stub_sourced:ground', 1500)]]);
    }

    public function tearDown(): void
    {
        SourcedStubCarrier::uninstall();
        AddressHelper::resetRateCache();
        $this->clearLiveRateTransients();
        parent::tearDown();
    }

    private function quote(array $form): array
    {
        AddressHelper::resetRateCache();
        $rates = AddressHelper::getShippingMethods('US', 'NY', null, [], [
            'postcode'  => '10001',
            'city'      => 'New York',
            'form_data' => $form,
        ]);

        return is_wp_error($rates) ? [] : array_column($rates, 'id');
    }

    public function test_a_field_the_request_ignores_reuses_the_cached_quote(): void
    {
        SourcedStubCarrier::$requestFields = ['billing_address_2'];

        $this->assertSame(['carrier:stub_sourced:ground'], $this->quote(['billing_email' => 'a@example.com']));
        $this->assertSame(['carrier:stub_sourced:ground'], $this->quote(['billing_email' => 'b@example.com']));

        $this->assertCount(1, SourcedStubCarrier::$requests, 'the email is not in the request, so one call');
        $this->assertSame([], SourcedStubCarrier::$destinations, 'fetchLiveRates() is not called');
    }

    public function test_a_field_the_request_sends_gets_its_own_quote(): void
    {
        SourcedStubCarrier::$requestFields = ['billing_email'];

        $this->quote(['billing_email' => 'a@example.com']);
        $this->quote(['billing_email' => 'b@example.com']);
        $this->quote(['billing_email' => 'a@example.com']);

        $this->assertSame([
            ['to' => '10001', 'form' => ['billing_email' => 'a@example.com']],
            ['to' => '10001', 'form' => ['billing_email' => 'b@example.com']],
        ], SourcedStubCarrier::$requests, 'a and b each quoted once; a again is cached');
    }

    public function test_a_carrier_without_a_request_keeps_the_destination_key(): void
    {
        $this->quote(['billing_email' => 'a@example.com']);
        $this->quote(['billing_email' => 'b@example.com']);

        $this->assertCount(1, SourcedStubCarrier::$destinations, 'old path: the form does not key the cache');
        $this->assertSame([], SourcedStubCarrier::$requests);
    }

    public function test_the_form_reaches_the_carrier_as_plain_bounded_text(): void
    {
        SourcedStubCarrier::$requestFields = [];

        $this->quote([
            'billing_email'     => 'a@example.com',
            'billing_address_2' => '<b>Flat 2</b>',
            'billing_company'   => str_repeat('x', 300),
            'nested'            => ['not' => 'scalar'],
        ]);

        $form = SourcedStubCarrier::$requestArgs[0]['form_data'];
        $this->assertSame('a@example.com', $form['billing_email']);
        $this->assertSame('Flat 2', $form['billing_address_2']);
        $this->assertSame(255, strlen($form['billing_company']));
        $this->assertArrayNotHasKey('nested', $form);
    }

    public function test_display_and_place_order_give_the_carrier_the_same_form(): void
    {
        // Display reads the saved cart; the VAT number is stored outside form_data.
        $saved = AddressHelper::quoteFormData(AddressHelper::checkoutFormFields([
            'form_data' => ['billing_email' => 'a@example.com', 'billing_address_2' => 'Flat 2'],
            'tax_data'  => ['vat_number' => 'DE123'],
        ]));
        // Place Order reads the submitted form, with fields carriers never get.
        $submitted = AddressHelper::quoteFormData([
            'fct_billing_tax_id'          => 'DE123',
            'billing_address_2'           => 'Flat 2',
            'billing_email'               => 'a@example.com',
            'fc_selected_shipping_method' => 'carrier:stub_sourced:ground',
            '_wpnonce'                    => 'abc',
        ]);

        $this->assertSame(['billing_email' => 'a@example.com', 'fct_billing_tax_id' => 'DE123', 'billing_address_2' => 'Flat 2'], $saved);
        $this->assertSame($saved, $submitted, 'same fields in the same order: the same request');
    }

    public function test_the_carrier_gets_the_visitors_cart_and_it_never_keys_the_cache(): void
    {
        SourcedStubCarrier::$requestFields = [];
        $first = new Cart(['cart_hash' => 'cart-one']);
        $second = new Cart(['cart_hash' => 'cart-two']);

        try {
            $this->useCart($first);
            $this->quote(['billing_email' => 'a@example.com']);
            $this->useCart($second);
            $this->quote(['billing_email' => 'a@example.com']);
            $this->useCart(null);
            $this->quote(['billing_email' => 'a@example.com']);
        } finally {
            CartResource::resetCartCache();
        }

        $this->assertSame($first, SourcedStubCarrier::$requestArgs[0]['cart']);
        $this->assertSame($second, SourcedStubCarrier::$requestArgs[1]['cart']);
        $this->assertNull(SourcedStubCarrier::$requestArgs[2]['cart'], 'no visitor cart: null');
        $this->assertCount(1, SourcedStubCarrier::$requests, 'the same request is one quote, whatever the cart');
    }

    private function useCart(?Cart $cart): void
    {
        $cache = new \ReflectionProperty(CartResource::class, 'cartCache');
        $cache->setAccessible(true);
        $cache->setValue(null, $cart);
    }

    public function test_many_nested_fields_do_not_push_a_text_field_out(): void
    {
        $form = [];
        for ($i = 0; $i < 150; $i++) {
            $form['extra_' . $i] = ['nested' => $i];
        }
        $form['billing_email'] = 'a@example.com';

        $this->assertSame(['billing_email' => 'a@example.com'], AddressHelper::quoteFormData($form));
    }

    public function test_the_progressive_list_key_follows_the_form_only_for_a_request_carrier(): void
    {
        SourcedStubCarrier::install(['ups' => 'UPS'], ['ups' => [SourcedStubCarrier::rate('carrier:stub_sourced:ups:ground', 1500)]]);

        $keyFor = function (array $form) {
            $this->quote($form);
            return AddressHelper::rateSourceState()['key'];
        };

        $this->assertSame($keyFor(['billing_email' => 'a@example.com']), $keyFor(['billing_email' => 'b@example.com']), 'no request carrier: one key per address');

        SourcedStubCarrier::$requestFields = ['billing_email'];
        $this->assertNotSame($keyFor(['billing_email' => 'a@example.com']), $keyFor(['billing_email' => 'b@example.com']), 'a stale in-flight fetch for a no longer matches');
    }

    public function test_a_carrier_builds_requests_only_once_it_declares_one(): void
    {
        $plain = new class {
            use ProvidesLiveRates;

            protected function fetchLiveRates(array $destination, array $items, string $currency)
            {
                return [];
            }

            public function canQuoteInCurrency(string $currency): ?bool
            {
                return null;
            }
        };

        $requesting = new class {
            use ProvidesLiveRates;

            protected function fetchLiveRates(array $destination, array $items, string $currency)
            {
                return [];
            }

            public function canQuoteInCurrency(string $currency): ?bool
            {
                return null;
            }

            protected function liveRatesRequest(array $destination, array $items, string $currency, array $args, string $source): ?array
            {
                return [];
            }
        };

        $this->assertFalse($plain->buildsLiveRatesRequests());
        $this->assertTrue($requesting->buildsLiveRatesRequests());
    }

    private function clearLiveRateTransients(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%fluent\\_cart\\_live\\_rates\\_%'");
    }
}
