<?php

namespace Tests\Integration\Services;

use FluentCart\App\Modules\PaymentMethods\Cod\Cod;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\PaymentMethods\Core\PaymentGatewayInterface;
use lucatume\WPBrowser\TestCase\WPTestCase;

class GatewayManagerTest extends WPTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!GatewayManager::has('offline_payment')) {
            GatewayManager::getInstance()->register('offline_payment', new Cod());
        }
    }

    // =========================================================================
    // Spec 165: Singleton consistency
    // =========================================================================

    public function test_get_instance_returns_singleton(): void
    {
        $a = GatewayManager::getInstance();
        $b = GatewayManager::getInstance();
        $this->assertSame($a, $b);
    }

    // =========================================================================
    // Spec 166: getInstance($name) returns specific gateway
    // =========================================================================

    public function test_get_instance_by_name_returns_registered_gateway(): void
    {
        $gateway = GatewayManager::getInstance('offline_payment');
        $this->assertInstanceOf(PaymentGatewayInterface::class, $gateway);
        $this->assertInstanceOf(Cod::class, $gateway);
    }

    // =========================================================================
    // Spec 167-168: register stores + calls boot
    // =========================================================================

    public function test_register_stores_gateway_and_calls_boot(): void
    {
        $bootCalled = false;
        $gateway = new class($bootCalled) implements PaymentGatewayInterface {
            public array $supportedFeatures = [];
            private $ref;
            public function __construct(&$bootRef)
            {
                $this->ref = &$bootRef;
            }
            public function boot()
            {
                $this->ref = true;
            }
            public function has(string $feature): bool { return false; }
            public function meta(): array { return []; }
            public function makePaymentFromPaymentInstance($p) { return []; }
            public function handleIPN() {}
            public function getOrderInfo(array $d) {}
            public function fields() { return []; }
            public function getMeta($key = '') { return $key ? '' : []; }
        };

        GatewayManager::getInstance()->register('test_gw_boot', $gateway);

        $this->assertSame($gateway, GatewayManager::getInstance()->get('test_gw_boot'));
        $this->assertTrue($bootCalled);

        GatewayManager::getInstance()->remove('test_gw_boot');
    }

    // =========================================================================
    // Spec 169: get('unknown') returns null
    // =========================================================================

    public function test_get_unknown_returns_null(): void
    {
        $this->assertNull(GatewayManager::getInstance()->get('non_existent_gateway_xyz'));
    }

    // =========================================================================
    // Spec 170: has returns true after registration
    // =========================================================================

    public function test_has_returns_true_for_registered_gateway(): void
    {
        $this->assertTrue(GatewayManager::has('offline_payment'));
    }

    // =========================================================================
    // Spec 171: enabled() filters by isEnabled()
    // =========================================================================

    public function test_enabled_returns_only_is_enabled_gateways(): void
    {
        $enabledGateway = new class implements PaymentGatewayInterface {
            public function isEnabled(): bool { return true; }
            public function has(string $feature): bool { return false; }
            public function meta(): array { return []; }
            public function makePaymentFromPaymentInstance($p) { return []; }
            public function handleIPN() {}
            public function getOrderInfo(array $d) {}
            public function fields() { return []; }
            public function getMeta($key = '') { return $key ? '' : []; }
        };
        $disabledGateway = new class implements PaymentGatewayInterface {
            public function isEnabled(): bool { return false; }
            public function has(string $feature): bool { return false; }
            public function meta(): array { return []; }
            public function makePaymentFromPaymentInstance($p) { return []; }
            public function handleIPN() {}
            public function getOrderInfo(array $d) {}
            public function fields() { return []; }
            public function getMeta($key = '') { return $key ? '' : []; }
        };

        GatewayManager::getInstance()->register('test_enabled_gw', $enabledGateway);
        GatewayManager::getInstance()->register('test_disabled_gw', $disabledGateway);

        $enabled = GatewayManager::getInstance()->enabled();

        GatewayManager::getInstance()->remove('test_enabled_gw');
        GatewayManager::getInstance()->remove('test_disabled_gw');

        $this->assertArrayHasKey('test_enabled_gw', $enabled);
        $this->assertArrayNotHasKey('test_disabled_gw', $enabled);
    }

    // =========================================================================
    // Spec 172: getAllMeta throws on missing required key
    // =========================================================================

    public function test_get_all_meta_throws_when_required_key_missing(): void
    {
        $incompleteGateway = new class implements PaymentGatewayInterface {
            public function has(string $feature): bool { return false; }
            public function meta(): array { return ['title' => 'T']; }
            public function makePaymentFromPaymentInstance($p) { return []; }
            public function handleIPN() {}
            public function getOrderInfo(array $d) {}
            public function fields() { return []; }
            public function getMeta($key = '') { return $key ? '' : ['title' => 'T']; }
        };

        GatewayManager::getInstance()->register('test_incomplete_meta', $incompleteGateway);

        try {
            GatewayManager::getInstance()->getAllMeta();
            GatewayManager::getInstance()->remove('test_incomplete_meta');
            $this->fail('Expected Exception for missing meta key');
        } catch (\Exception $e) {
            GatewayManager::getInstance()->remove('test_incomplete_meta');
            $this->assertStringContainsString('Missing required meta key', $e->getMessage());
        }
    }

    // =========================================================================
    // Spec 173: getRoutes returns [path, name, upcoming, meta]
    // =========================================================================

    public function test_get_routes_returns_route_shape(): void
    {
        $routes = GatewayManager::getInstance()->getRoutes();
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $this->assertArrayHasKey('path', $route);
            $this->assertArrayHasKey('name', $route);
            $this->assertArrayHasKey('upcoming', $route);
            $this->assertArrayHasKey('meta', $route);
        }
    }
}
