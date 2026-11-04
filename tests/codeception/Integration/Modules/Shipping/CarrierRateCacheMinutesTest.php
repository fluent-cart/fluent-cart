<?php

namespace Tests\Integration\Modules\Shipping;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Modules\Shipping\Carriers\Manual\ManualCarrier;
use FluentCart\App\Modules\Shipping\Carriers\Manual\ManualCarrierSettings;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Shipping\StubCarrierSettings;
use Tests\Support\Shipping\StubShippingCarrier;

/**
 * How long a carrier's quoted rates may be reused, and the reuse_quote arg
 * Place Order sends so a live carrier answers from its last quote.
 */
class CarrierRateCacheMinutesTest extends DatabaseTestCase
{
    const FILTER = 'fluent_cart/shipping/carrier_rate_cache_minutes';

    public function setUp(): void
    {
        parent::setUp();
        StubShippingCarrier::install();
        AddressHelper::resetRateCache();
    }

    public function tearDown(): void
    {
        remove_all_filters(self::FILTER);
        AddressHelper::resetRateCache();
        StubShippingCarrier::uninstall();
        parent::tearDown();
    }

    private function stub(): StubShippingCarrier
    {
        return new StubShippingCarrier(new StubCarrierSettings());
    }

    public function test_default_is_ten_minutes(): void
    {
        $this->assertSame(10, $this->stub()->getRateCacheMinutes());
        $this->assertSame(10, (new ManualCarrier(new ManualCarrierSettings()))->getRateCacheMinutes());
    }

    public function test_filter_overrides_one_carrier_by_its_key(): void
    {
        $seen = [];
        add_filter(self::FILTER, function ($minutes, $context) use (&$seen) {
            $seen[] = $context;
            return $context['carrier'] === StubShippingCarrier::SLUG ? 30 : $minutes;
        }, 10, 2);

        $stub = $this->stub();
        $this->assertSame(30, $stub->getRateCacheMinutes());
        $this->assertSame(10, (new ManualCarrier(new ManualCarrierSettings()))->getRateCacheMinutes(), 'other carriers keep theirs');
        $this->assertSame($stub, $seen[0]['carrier_instance']);
    }

    public function test_a_carrier_can_change_its_own_default(): void
    {
        $carrier = new class(new StubCarrierSettings()) extends StubShippingCarrier {
            protected function defaultRateCacheMinutes(): int
            {
                return 45;
            }
        };

        $received = null;
        add_filter(self::FILTER, function ($minutes) use (&$received) {
            $received = $minutes;
            return $minutes;
        });

        $this->assertSame(45, $carrier->getRateCacheMinutes());
        $this->assertSame(45, $received, 'the filter starts from the carrier\'s default');
    }

    /**
     * @dataProvider filteredValues
     */
    public function test_filtered_value_is_clamped_to_a_whole_number_of_minutes($filtered, int $expected): void
    {
        add_filter(self::FILTER, function () use ($filtered) {
            return $filtered;
        });

        $this->assertSame($expected, $this->stub()->getRateCacheMinutes());
    }

    public function filteredValues(): array
    {
        return [
            'zero'         => [0, 1],
            'negative'     => [-5, 1],
            'over a day'   => [5000, 1440],
            'one day'      => [1440, 1440],
            'numeric text' => ['15', 15],
            'fraction'     => [2.7, 2],
            'not a number' => ['soon', 10],
            'null'         => [null, 10],
        ];
    }

    public function test_rates_are_asked_for_without_reuse_quote_by_default(): void
    {
        AddressHelper::getShippingMethods('US', 'NY', null, [], ['postcode' => '10001', 'city' => 'New York']);

        $this->assertNotEmpty(StubShippingCarrier::$calls);
        $this->assertArrayNotHasKey('reuse_quote', StubShippingCarrier::$calls[0]);
    }

    public function test_reuse_quote_reaches_the_carrier_once_switched_on(): void
    {
        AddressHelper::reuseCarrierQuotes();
        AddressHelper::getShippingMethods('US', 'NY', null, [], ['postcode' => '10001', 'city' => 'New York']);

        $this->assertNotEmpty(StubShippingCarrier::$calls);
        $this->assertTrue(StubShippingCarrier::$calls[0]['reuse_quote'] ?? null);

        AddressHelper::resetRateCache();
        StubShippingCarrier::$calls = [];
        AddressHelper::getShippingMethods('US', 'NY', null, [], ['postcode' => '10001', 'city' => 'New York']);
        $this->assertArrayNotHasKey('reuse_quote', StubShippingCarrier::$calls[0], 'resetRateCache() switches it off');
    }
}
