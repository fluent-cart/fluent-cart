<?php

namespace Tests\Integration\Modules\Shipping;

use FluentCart\App\Modules\Shipping\Carriers\Core\CarrierManager;
use FluentCart\App\Modules\Shipping\ShippingModule;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Shipping\StubShippingCarrier;

/**
 * The public tracking-webhook listener only routes: the carrier authenticates and
 * answers. An unknown slug is a bare 404, and a throwing carrier is a 500 so the
 * carrier retries instead of losing the event.
 */
class CarrierWebhookListenerTest extends DatabaseTestCase
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

    public function test_listener_is_registered_on_the_web_route_action(): void
    {
        $this->assertNotFalse(has_action('fluent_cart_action_fct_shipping_listener_webhook'), 'the webhook listener must be wired to the web route');
    }

    public function test_known_carrier_handles_its_own_webhook(): void
    {
        $status = (new ShippingModule())->routeCarrierWebhook(StubShippingCarrier::SLUG);

        $this->assertNull($status, 'a handled webhook leaves the response to the carrier');
        $this->assertSame(1, StubShippingCarrier::$webhookCalls, 'the carrier must receive the webhook');
    }

    public function test_unknown_carrier_is_a_404(): void
    {
        $this->assertSame(404, (new ShippingModule())->routeCarrierWebhook('no_such_carrier'), 'an unknown slug must 404');
        $this->assertSame(0, StubShippingCarrier::$webhookCalls, 'no carrier may be called');
    }

    public function test_a_throwing_carrier_is_a_500(): void
    {
        StubShippingCarrier::$onWebhook = function () {
            throw new \RuntimeException('bad payload');
        };

        $this->assertSame(500, (new ShippingModule())->routeCarrierWebhook(StubShippingCarrier::SLUG), 'a failure must 500 so the carrier retries');
    }

    public function test_carrier_meta_exposes_the_webhook_url(): void
    {
        StubShippingCarrier::$webhookUrl = 'https://shop.test/?fluent-cart=fct_shipping_listener_webhook&carrier=stub';

        $carriers = array_column(CarrierManager::getInstance()->getAllMeta(), null, 'slug');

        $this->assertSame(StubShippingCarrier::$webhookUrl, $carriers[StubShippingCarrier::SLUG]['webhook_url'] ?? null, 'the settings payload must carry the webhook URL');
        $this->assertSame('', $carriers['manual']['webhook_url'] ?? null, 'a carrier without webhooks reports an empty URL');
    }
}
