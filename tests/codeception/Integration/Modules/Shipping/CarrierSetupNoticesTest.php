<?php

namespace Tests\Integration\Modules\Shipping;

use FluentCart\App\Modules\Shipping\Carriers\Contracts\CarrierContract;
use FluentCart\App\Modules\Shipping\Carriers\Core\BaseCarrierSettings;
use FluentCart\App\Modules\Shipping\Carriers\Core\CarrierManager;
use FluentCart\App\Services\Shipments\ShipmentInstance;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Shipping\StubCarrierSettings;
use Tests\Support\Shipping\StubShippingCarrier;

/**
 * describeCarrier() carries the carrier's setup notices to the settings pages.
 * Vue renders them as text, so the payload must be well-formed: malformed
 * entries are dropped rather than rendered half-empty.
 */
class CarrierSetupNoticesTest extends DatabaseTestCase
{
    private const DIRECT_SLUG = 'stub_direct';

    public function setUp(): void
    {
        parent::setUp();
        StubShippingCarrier::install();
    }

    public function tearDown(): void
    {
        $this->setRegisteredCarrier(self::DIRECT_SLUG, null);
        StubShippingCarrier::uninstall();
        parent::tearDown();
    }

    private function describe(string $slug): array
    {
        $described = CarrierManager::getInstance()->describeCarrier($slug);
        $this->assertIsArray($described, 'the carrier must be described');

        return $described;
    }

    // CarrierManager cannot unregister, so the direct carrier goes in and out by reflection.
    private function setRegisteredCarrier(string $slug, $carrier): void
    {
        $manager  = CarrierManager::getInstance();
        $property = new \ReflectionProperty(CarrierManager::class, 'carriers');
        $property->setAccessible(true);

        $carriers = $property->getValue($manager);
        if ($carrier) {
            $carriers[$slug] = $carrier;
        } else {
            unset($carriers[$slug]);
        }
        $property->setValue($manager, $carriers);
    }

    public function test_notices_default_to_an_empty_list(): void
    {
        $this->assertSame([], $this->describe(StubShippingCarrier::SLUG)['notices'], 'a carrier with nothing to report must send an empty list');
        $this->assertSame([], $this->describe('manual')['notices'], 'the abstract default must be an empty list');
    }

    public function test_well_formed_notices_pass_through(): void
    {
        StubShippingCarrier::$setupNotices = [
            [
                'type'    => 'warning',
                'message' => 'Add your store address to get live rates.',
                'action'  => ['label' => 'Store settings', 'route' => 'settings/store-settings'],
            ],
            ['type' => 'info', 'message' => 'Test mode is on.', 'action' => null],
            ['type' => 'error', 'message' => 'The API token was rejected.'],
        ];

        $this->assertSame([
            [
                'type'    => 'warning',
                'message' => 'Add your store address to get live rates.',
                'action'  => ['label' => 'Store settings', 'route' => 'settings/store-settings'],
            ],
            ['type' => 'info', 'message' => 'Test mode is on.', 'action' => null],
            ['type' => 'error', 'message' => 'The API token was rejected.', 'action' => null],
        ], $this->describe(StubShippingCarrier::SLUG)['notices'], 'well-formed notices must reach Vue as declared, with a missing action as null');
    }

    public function test_message_is_not_escaped(): void
    {
        StubShippingCarrier::$setupNotices = [['type' => 'warning', 'message' => 'Rates & labels <off>']];

        $notices = $this->describe(StubShippingCarrier::SLUG)['notices'];

        $this->assertSame('Rates & labels <off>', $notices[0]['message'], 'Vue renders the message as text, so PHP must not escape it');
    }

    public function test_malformed_notices_are_dropped(): void
    {
        StubShippingCarrier::$setupNotices = [
            'not an array',
            ['type' => 'danger', 'message' => 'Unknown type'],
            ['message' => 'No type'],
            ['type' => 'warning'],
            ['type' => 'warning', 'message' => '   '],
            ['type' => 'warning', 'message' => ['nested']],
            ['type' => ['warning'], 'message' => 'Array type'],
            ['type' => 'warning', 'message' => 'Kept'],
        ];

        $this->assertSame(
            [['type' => 'warning', 'message' => 'Kept', 'action' => null]],
            $this->describe(StubShippingCarrier::SLUG)['notices'],
            'only the well-formed notice may remain, re-indexed'
        );
    }

    public function test_values_are_cast_and_a_malformed_action_becomes_null(): void
    {
        StubShippingCarrier::$setupNotices = [
            ['type' => 'WARNING', 'message' => 42, 'action' => ['label' => 'Fix', 'route' => 'settings/store-settings'], 'extra' => 'dropped'],
            ['type' => 'info', 'message' => 'No route', 'action' => ['label' => 'Fix']],
            ['type' => 'info', 'message' => 'Empty label', 'action' => ['label' => '', 'route' => 'settings']],
            ['type' => 'info', 'message' => 'Array route', 'action' => ['label' => 'Fix', 'route' => ['settings']]],
            ['type' => 'info', 'message' => 'String action', 'action' => 'settings'],
        ];

        $notices = $this->describe(StubShippingCarrier::SLUG)['notices'];

        $this->assertSame(
            ['type' => 'warning', 'message' => '42', 'action' => ['label' => 'Fix', 'route' => 'settings/store-settings']],
            $notices[0],
            'type must be lower-cased, message cast to string, and unknown keys dropped'
        );

        foreach (array_slice($notices, 1) as $notice) {
            $this->assertNull($notice['action'], 'a malformed action must become null, keeping the notice: ' . $notice['message']);
        }
        $this->assertCount(5, $notices, 'a malformed action must not drop its notice');
    }

    public function test_a_carrier_implementing_the_contract_directly_has_no_notices(): void
    {
        $this->setRegisteredCarrier(self::DIRECT_SLUG, $this->directCarrier());

        $this->assertSame([], $this->describe(self::DIRECT_SLUG)['notices'], 'a carrier without getSetupNotices() must still be described');
    }

    private function directCarrier(): CarrierContract
    {
        return new class implements CarrierContract {
            public function meta(): array
            {
                return ['slug' => 'stub_direct', 'name' => 'Direct Carrier', 'supports' => []];
            }

            public function fields(): array
            {
                return [];
            }

            public function isEnabled(): bool
            {
                return false;
            }

            public function getSettings(): BaseCarrierSettings
            {
                return new StubCarrierSettings();
            }

            public function getRates(array $args): array
            {
                return [];
            }

            public function createLabel(array $shipment)
            {
                return null;
            }

            public function getTrackingUrl(string $trackingNumber): string
            {
                return '';
            }

            public function getTrackingInfo(string $trackingNumber): array
            {
                return [];
            }

            public function validateSettings(array $settings)
            {
                return $settings;
            }

            public function boot(): void
            {
            }

            public function getWebhookUrl(): string
            {
                return '';
            }

            public function handleWebhook(): void
            {
            }

            public function supportsTrackingSync(): bool
            {
                return false;
            }

            public function syncTrackingFromShipmentInstance(ShipmentInstance $instance)
            {
                return null;
            }

            public function makeShipmentFromShipmentInstance(ShipmentInstance $instance)
            {
                return null;
            }

            public function cancelShipmentFromShipmentInstance(ShipmentInstance $instance)
            {
                return null;
            }

            public function getShipmentQuote(ShipmentInstance $instance)
            {
                return null;
            }
        };
    }
}
