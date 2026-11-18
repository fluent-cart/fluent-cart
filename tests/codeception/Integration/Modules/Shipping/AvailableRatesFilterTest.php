<?php

namespace Tests\Integration\Modules\Shipping;

use FluentCart\App\Helpers\AddressHelper;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Shipping\SourcedStubCarrier;
use Tests\Support\Shipping\StubShippingCarrier;

/**
 * fluent_cart/shipping/available_rates: a plugin may hide or reorder rates for
 * a cart, never add one or change its price, and checkout and Place Order see
 * the same filtered list.
 */
class AvailableRatesFilterTest extends DatabaseTestCase
{
    const FILTER = 'fluent_cart/shipping/available_rates';
    const DESTINATION = ['postcode' => '10001', 'city' => 'New York'];

    public function setUp(): void
    {
        parent::setUp();
        AddressHelper::resetRateCache();
        StubShippingCarrier::install([
            ['id' => 'stub:ground', 'title' => 'Stub Ground', 'cost' => 1000, 'currency' => 'USD', 'states' => []],
            ['id' => 'stub:express', 'title' => 'Stub Express', 'cost' => 2500, 'currency' => 'USD', 'states' => []],
        ]);
    }

    public function tearDown(): void
    {
        remove_all_filters(self::FILTER);
        AddressHelper::resetRateCache();
        StubShippingCarrier::uninstall();
        parent::tearDown();
    }

    private function stubRateIds($rates): array
    {
        $this->assertIsArray($rates);
        $ids = array_column($rates, 'id');

        return array_values(array_filter($ids, function ($id) {
            return strpos((string) $id, 'stub:') === 0;
        }));
    }

    public function test_a_hidden_rate_is_not_listed_and_cannot_be_picked(): void
    {
        add_filter(self::FILTER, function ($rates) {
            return array_values(array_filter($rates, function ($rate) {
                return $rate['id'] !== 'stub:ground';
            }));
        });

        $rates = AddressHelper::getShippingMethods('US', 'NY', null, [], self::DESTINATION);

        $this->assertSame(['stub:express'], $this->stubRateIds($rates));
        $this->assertNull(AddressHelper::resolveSelectedRate('US', 'NY', [], 'stub:ground', self::DESTINATION), 'Place Order refuses the hidden rate');
        $this->assertNotNull(AddressHelper::resolveSelectedRate('US', 'NY', [], 'stub:express', self::DESTINATION));
    }

    public function test_a_filter_cannot_add_a_rate_or_change_a_price(): void
    {
        add_filter(self::FILTER, function ($rates) {
            foreach ($rates as &$rate) {
                $rate['cost'] = 1;
            }
            unset($rate);
            $rates[] = ['id' => 'stub:free', 'carrier' => 'stub', 'title' => 'Free', 'cost' => 0, 'currency' => 'USD'];
            $rates[] = 'not a rate';

            return $rates;
        });

        $rates = AddressHelper::getShippingMethods('US', 'NY', null, [], self::DESTINATION);

        $this->assertSame(['stub:ground', 'stub:express'], $this->stubRateIds($rates));
        $costs = array_column(array_filter($rates, function ($rate) {
            return $rate['carrier'] === StubShippingCarrier::SLUG;
        }), 'cost');
        $this->assertSame([1000, 2500], array_map('intval', array_values($costs)));
    }

    public function test_a_filter_may_reorder_rates(): void
    {
        add_filter(self::FILTER, function ($rates) {
            return array_reverse($rates);
        });

        $rates = AddressHelper::getShippingMethods('US', 'NY', null, [], self::DESTINATION);

        $this->assertSame(['stub:express', 'stub:ground'], $this->stubRateIds($rates));
    }

    public function test_the_filter_gets_the_items_destination_and_currency(): void
    {
        $context = null;
        add_filter(self::FILTER, function ($rates, $ctx) use (&$context) {
            $context = $ctx;
            return $rates;
        }, 10, 2);

        $items = [['object_id' => 7, 'quantity' => 2, 'fulfillment_type' => 'physical']];
        AddressHelper::getShippingMethods('US', 'NY', null, $items, self::DESTINATION);

        $this->assertSame($items, $context['items']);
        $this->assertSame('US', $context['destination']['country']);
        $this->assertSame('10001', $context['destination']['postcode']);
        $this->assertArrayNotHasKey('items', $context['destination']);
        $this->assertArrayNotHasKey('form_data', $context['destination'], 'the form is carrier input, not the address');
        $this->assertArrayNotHasKey('cart', $context['destination']);
        $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $context['currency']);
    }

    public function test_the_filter_sees_only_rates_the_state_can_use(): void
    {
        StubShippingCarrier::$rates[] = ['id' => 'stub:ca_only', 'title' => 'CA only', 'cost' => 500, 'currency' => 'USD', 'states' => ['CA']];

        $seen = [];
        add_filter(self::FILTER, function ($rates) use (&$seen) {
            $seen = array_column($rates, 'id');
            return $rates;
        });

        $rates = AddressHelper::getShippingMethods('US', 'NY', null, [], self::DESTINATION);
        $this->assertNotContains('stub:ca_only', $seen, 'a CA-only rate is not offered to NY');
        $this->assertSame(['stub:ground', 'stub:express'], $this->stubRateIds($rates));

        AddressHelper::resetRateCache();
        $rates = AddressHelper::getShippingMethods('US', 'CA', null, [], self::DESTINATION);
        $this->assertContains('stub:ca_only', $seen);
        $this->assertContains('stub:ca_only', $this->stubRateIds($rates));
    }

    public function test_a_source_fetch_reports_the_whole_filtered_list(): void
    {
        SourcedStubCarrier::install(['ups' => 'UPS'], ['ups' => [SourcedStubCarrier::rate('carrier:stub_sourced:ups:ground', 1500)]]);
        // Once the UPS rate exists, the stub's express rate is hidden.
        add_filter(self::FILTER, function ($rates) {
            $hasUps = in_array('carrier:stub_sourced:ups:ground', array_column($rates, 'id'), true);
            return array_values(array_filter($rates, function ($rate) use ($hasUps) {
                return !($hasUps && $rate['id'] === 'stub:express');
            }));
        });

        try {
            $rates = AddressHelper::fetchRateSource(SourcedStubCarrier::SLUG, 'ups', 'US', 'NY', [], self::DESTINATION);

            $this->assertSame(['carrier:stub_sourced:ups:ground'], array_column($rates, 'id'));
            $ids = AddressHelper::rateSourceState()['ids'];
            $this->assertContains('stub:ground', $ids);
            $this->assertContains('carrier:stub_sourced:ups:ground', $ids);
            $this->assertNotContains('stub:express', $ids, 'checkout drops the shown express row');
        } finally {
            SourcedStubCarrier::uninstall();
        }
    }

    public function test_a_non_array_result_keeps_every_rate(): void
    {
        add_filter(self::FILTER, function () {
            return null;
        });

        $rates = AddressHelper::getShippingMethods('US', 'NY', null, [], self::DESTINATION);

        $this->assertSame(['stub:ground', 'stub:express'], $this->stubRateIds($rates));
    }
}
