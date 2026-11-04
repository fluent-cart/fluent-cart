<?php

namespace Tests\Integration\Modules\Shipping;

use FluentCart\App\Modules\Shipping\Carriers\Core\CarrierManager;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Shipping\StubCarrierSettings;
use Tests\Support\Shipping\StubShippingCarrier;

/**
 * The dispatch-form payload. supports_labels / supports_rates decide what the
 * Create shipment form locks, and both need an enabled carrier: a disabled one
 * dispatches nothing, so claiming otherwise would leave the form unsubmittable.
 */
class CarrierManagerTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        StubShippingCarrier::install();
    }

    public function tearDown(): void
    {
        StubShippingCarrier::uninstall();
        parent::tearDown();
    }

    public function test_carrier_lookup_returns_registered_or_null(): void
    {
        $this->assertInstanceOf(StubShippingCarrier::class, CarrierManager::carrier(StubShippingCarrier::SLUG), 'carrier() must return the registered stub');
        $this->assertNull(CarrierManager::carrier('no_such_carrier'), 'carrier() must return null for an unregistered slug');
    }

    public function test_supports_labels_requires_enabled_supports_labels_and_toggle(): void
    {
        StubShippingCarrier::$supports     = ['rates', 'labels'];
        StubShippingCarrier::$enableLabels = true;
        $options = array_column(CarrierManager::getInstance()->getDispatchOptions(), null, 'slug');
        $this->assertTrue($options[StubShippingCarrier::SLUG]['supports_labels'], 'enabled + label support + toggle on must buy labels');

        StubShippingCarrier::$enableLabels = false;
        $options = array_column(CarrierManager::getInstance()->getDispatchOptions(), null, 'slug');
        $this->assertFalse($options[StubShippingCarrier::SLUG]['supports_labels'], 'the label toggle off must not buy labels');

        StubShippingCarrier::$supports     = ['rates'];
        StubShippingCarrier::$enableLabels = true;
        $options = array_column(CarrierManager::getInstance()->getDispatchOptions(), null, 'slug');
        $this->assertFalse($options[StubShippingCarrier::SLUG]['supports_labels'], 'a carrier without label support must not buy labels');
    }

    public function test_supports_rates_gating(): void
    {
        StubShippingCarrier::$supports     = ['rates', 'labels'];
        StubShippingCarrier::$enableLabels = true;
        $options = array_column(CarrierManager::getInstance()->getDispatchOptions(), null, 'slug');
        $this->assertTrue($options[StubShippingCarrier::SLUG]['supports_rates'], 'a label carrier with labels on must offer rates');

        StubShippingCarrier::$enableLabels = false;
        $options = array_column(CarrierManager::getInstance()->getDispatchOptions(), null, 'slug');
        $this->assertFalse($options[StubShippingCarrier::SLUG]['supports_rates'], 'a label carrier must not offer rates it cannot buy');

        StubShippingCarrier::$supports = ['rates'];
        $options = array_column(CarrierManager::getInstance()->getDispatchOptions(), null, 'slug');
        $this->assertTrue($options[StubShippingCarrier::SLUG]['supports_rates'], 'a rates-only carrier needs no label toggle');

        StubShippingCarrier::$supports = [];
        $options = array_column(CarrierManager::getInstance()->getDispatchOptions(), null, 'slug');
        $this->assertFalse($options[StubShippingCarrier::SLUG]['supports_rates'], 'a carrier without rate support must not offer rates');
    }

    public function test_disabled_carrier_supports_nothing(): void
    {
        StubShippingCarrier::$enabled      = false;
        StubShippingCarrier::$supports     = ['rates', 'labels'];
        StubShippingCarrier::$enableLabels = true;

        $options = array_column(CarrierManager::getInstance()->getDispatchOptions(), null, 'slug');
        $option  = $options[StubShippingCarrier::SLUG];

        $this->assertFalse($option['is_enabled'], 'a disabled carrier must say so');
        $this->assertFalse($option['supports_labels'], 'a disabled carrier must not buy labels');
        $this->assertFalse($option['supports_rates'], 'a disabled carrier must not offer rates');
    }

    public function test_manual_carrier_quotes_rates_but_buys_no_labels(): void
    {
        $options = array_column(CarrierManager::getInstance()->getDispatchOptions(), null, 'slug');
        $manual  = $options['manual'] ?? null;

        $this->assertNotNull($manual, 'Manual must be offered');
        $this->assertFalse($manual['supports_labels'], 'Manual buys nothing');
        $this->assertSame(CarrierManager::carrier('manual')->isEnabled(), $manual['supports_rates'], 'an enabled Manual quotes its zone methods');
    }

    public function test_register_rejects_duplicate_slug(): void
    {
        $this->setExpectedIncorrectUsage(CarrierManager::class . '::register');

        $original = CarrierManager::carrier(StubShippingCarrier::SLUG);
        CarrierManager::getInstance()->register(StubShippingCarrier::SLUG, new StubShippingCarrier(new StubCarrierSettings()));

        $this->assertSame($original, CarrierManager::carrier(StubShippingCarrier::SLUG), 'a duplicate register() must not overwrite the carrier');
    }
}
