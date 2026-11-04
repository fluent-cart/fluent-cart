<?php

namespace Tests\Integration\Modules\Shipping;

use FluentCart\App\Modules\Shipping\Carriers\Manual\ManualCarrier;
use FluentCart\App\Modules\Shipping\Carriers\Manual\ManualCarrierSettings;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;

class ManualCarrierTest extends DatabaseTestCase
{
    // A carrier-level pattern left in the DB by older versions; it must be ignored.
    private function carrierWithStalePattern(string $pattern = 'https://stale.example/{tracking_number}'): ManualCarrier
    {
        $settings = new class($pattern) extends ManualCarrierSettings {
            private string $pattern;

            public function __construct(string $pattern)
            {
                $this->pattern = $pattern;
            }

            public function get($key = '')
            {
                $values = ['is_active' => 'yes', 'tracking_url_pattern' => $this->pattern];
                return $key ? ($values[$key] ?? null) : $values;
            }
        };

        return new ManualCarrier($settings);
    }

    private function methodWithPattern(string $pattern): int
    {
        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);
        $meta = $pattern !== '' ? ['tracking_url_pattern' => $pattern] : [];

        return (int) (new ShippingMethodFactory())->create(['zone_id' => (int) $zone->id, 'meta' => $meta])->id;
    }

    public function test_tracking_url_inserts_a_plain_tracking_number(): void
    {
        $methodId = $this->methodWithPattern('https://track.example/t/{tracking_number}');

        $this->assertSame('https://track.example/t/ABC123', $this->carrierWithStalePattern()->getTrackingUrl('ABC123', $methodId));
    }

    public function test_tracking_url_encodes_the_tracking_number(): void
    {
        $methodId = $this->methodWithPattern('https://track.example/t/{tracking_number}?src=fc');

        $this->assertSame(
            'https://track.example/t/A1%26admin%3D1%3Fx%23frag%2F..%2Fb?src=fc',
            $this->carrierWithStalePattern()->getTrackingUrl('A1&admin=1?x#frag/../b', $methodId)
        );
    }

    public function test_tracking_url_encodes_spaces(): void
    {
        $methodId = $this->methodWithPattern('https://track.example/t/{tracking_number}');

        $this->assertSame('https://track.example/t/AB%2012%20CD', $this->carrierWithStalePattern()->getTrackingUrl('AB 12 CD', $methodId));
    }

    public function test_no_method_pattern_gives_no_tracking_url(): void
    {
        $carrier = $this->carrierWithStalePattern();

        $this->assertSame('', $carrier->getTrackingUrl('ABC123', $this->methodWithPattern('')), 'a method without a pattern gives no URL');
        $this->assertSame('', $carrier->getTrackingUrl('ABC123'), 'no picked method gives no URL');
    }

    public function test_carrier_settings_have_no_tracking_url_pattern_field(): void
    {
        $keys = array_column($this->carrierWithStalePattern()->fields(), 'key');

        $this->assertNotContains('tracking_url_pattern', $keys);
        $this->assertArrayNotHasKey('tracking_url_pattern', (new ManualCarrierSettings())->getDefaults());
    }
}
