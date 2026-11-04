<?php

namespace Tests\Integration\Hooks;

use FluentCart\App\Hooks\Handlers\AddonGatewaysHandler;
use FluentCart\App\Hooks\Handlers\PromoGatewaysHandler;
use Tests\Support\DatabaseTestCase;

class GatewayRegistrationFailureTestBrokenGateway
{
    public function __construct()
    {
        throw new \RuntimeException('broken gateway constructor');
    }
}

/**
 * A gateway that throws while registering is skipped so the others still load.
 * The failure must still reach a hook, or it disappears without a trace.
 */
class GatewayRegistrationFailureTest extends DatabaseTestCase
{
    private $failures;

    public function setUp(): void
    {
        parent::setUp();
        $this->failures = new \ArrayObject();
        $failures = $this->failures;
        add_action('fluent_cart/payment_gateway/registration_failed', function ($payload) use ($failures) {
            $failures[] = $payload;
        });
    }

    public function tearDown(): void
    {
        remove_all_actions('fluent_cart/payment_gateway/registration_failed');
        remove_all_filters('fluent_cart/addon_gateways');
        remove_all_filters('fluent_cart/promo_gateways');
        parent::tearDown();
    }

    private function onlyTheBrokenGateway(string $hook): void
    {
        add_filter($hook, function () {
            return ['fct_broken_test_gateway' => GatewayRegistrationFailureTestBrokenGateway::class];
        });
    }

    public function test_addon_gateway_failure_fires_the_hook(): void
    {
        $this->onlyTheBrokenGateway('fluent_cart/addon_gateways');

        (new AddonGatewaysHandler())->registerPromoGateways();

        $this->assertCount(1, $this->failures);
        $this->assertSame('fct_broken_test_gateway', $this->failures[0]['slug']);
        $this->assertSame('addon', $this->failures[0]['source']);
        $this->assertInstanceOf(\RuntimeException::class, $this->failures[0]['exception']);
    }

    public function test_promo_gateway_failure_fires_the_hook(): void
    {
        $this->onlyTheBrokenGateway('fluent_cart/promo_gateways');

        (new PromoGatewaysHandler())->registerPromoGateways();

        $this->assertCount(1, $this->failures);
        $this->assertSame('promo', $this->failures[0]['source']);
    }
}
