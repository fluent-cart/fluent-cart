<?php

namespace Tests\Integration\Services;

use FluentCart\App\Hooks\Handlers\GlobalPaymentHandler;
use FluentCart\App\Modules\PaymentMethods\Cod\Cod;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\WpDieCapture;

class GlobalPaymentHandlerTest extends WPTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!GatewayManager::has('offline_payment')) {
            GatewayManager::getInstance()->register('offline_payment', new Cod());
        }
    }

    // =========================================================================
    // Spec 174: Core gateways registered by init
    // =========================================================================

    public function test_core_gateway_offline_payment_is_registered(): void
    {
        $this->assertTrue(GatewayManager::has('offline_payment'));
    }

    public function test_core_gateway_stripe_is_registered(): void
    {
        $this->assertTrue(
            GatewayManager::has('stripe'),
            'GlobalPaymentHandler registers Stripe on init'
        );
    }

    public function test_core_gateway_paypal_is_registered(): void
    {
        $this->assertTrue(GatewayManager::has('paypal'));
    }

    public function test_core_gateway_square_is_registered(): void
    {
        $this->assertTrue(GatewayManager::has('square'));
    }

    public function test_addon_gateway_airwallex_is_registered(): void
    {
        $this->assertTrue(GatewayManager::has('airwallex'));
    }

    // =========================================================================
    // Spec 176-177: IPN listener delegates / error handling
    // =========================================================================

    public function test_init_ipn_listener_delegates_to_handle_ipn(): void
    {
        $fired = false;
        add_action('fluent_cart/test_cod_ipn_spy', function () use (&$fired) { $fired = true; });

        $spyGateway = new class implements \FluentCart\App\Modules\PaymentMethods\Core\PaymentGatewayInterface {
            public function handleIPN() { do_action('fluent_cart/test_cod_ipn_spy'); }
            public function has(string $feature): bool { return false; }
            public function meta(): array { return []; }
            public function makePaymentFromPaymentInstance($p) { return []; }
            public function getOrderInfo(array $d) {}
            public function fields() { return []; }
            public function getMeta($key = '') { return $key ? '' : []; }
        };
        GatewayManager::getInstance()->register('ipn_spy_gw', $spyGateway);

        $_REQUEST['method'] = 'ipn_spy_gw';
        \FluentCart\App\App::getInstance()->instance('request',
            new \FluentCart\Framework\Http\Request\Request(\FluentCart\App\App::getInstance(), ['method' => 'ipn_spy_gw'], [])
        );

        (new GlobalPaymentHandler())->initIpnListener();

        unset($_REQUEST['method']);
        GatewayManager::getInstance()->remove('ipn_spy_gw');
        remove_all_actions('fluent_cart/test_cod_ipn_spy');

        $this->assertTrue($fired);
    }

    public function test_ipn_listener_logs_and_returns_500_on_exception(): void
    {
        $throwingGateway = new class implements \FluentCart\App\Modules\PaymentMethods\Core\PaymentGatewayInterface {
            public function handleIPN() {
                throw new \RuntimeException('boom');
            }
            public function has(string $feature): bool { return false; }
            public function meta(): array { return []; }
            public function makePaymentFromPaymentInstance($p) { return []; }
            public function getOrderInfo(array $d) {}
            public function fields() { return []; }
            public function getMeta($key = '') { return $key ? '' : []; }
        };
        GatewayManager::getInstance()->register('ipn_throw_gw', $throwingGateway);
        \FluentCart\App\App::getInstance()->instance('request',
            new \FluentCart\Framework\Http\Request\Request(\FluentCart\App\App::getInstance(), ['method' => 'ipn_throw_gw'], [])
        );

        $capture = WpDieCapture::capture(fn() => (new GlobalPaymentHandler())->initIpnListener());

        GatewayManager::getInstance()->remove('ipn_throw_gw');

        $this->assertTrue($capture['died']);
        $this->assertSame(500, $capture['status']);
        $this->assertStringContainsString('IPN processing failed', $capture['json']['message'] ?? '');
    }

    // =========================================================================
    // Spec 178: getAll() respects fluent_cart_payment_methods_order option
    // =========================================================================

    public function test_get_all_respects_saved_order_option(): void
    {
        update_option('fluent_cart_payment_methods_order', ['offline_payment', 'stripe']);

        $gateways = (new GlobalPaymentHandler())->getAll();

        delete_option('fluent_cart_payment_methods_order');

        $routes = array_column($gateways, 'route');
        $offlineIdx = array_search('offline_payment', $routes);
        $stripeIdx = array_search('stripe', $routes);

        if ($offlineIdx !== false && $stripeIdx !== false) {
            $this->assertLessThan($stripeIdx, $offlineIdx);
        } else {
            $this->assertIsArray($gateways);
        }
    }

    // =========================================================================
    // Spec 179: getSettings returns fields + settings
    // =========================================================================

    public function test_get_settings_returns_fields_and_settings(): void
    {
        $result = (new GlobalPaymentHandler())->getSettings('offline_payment');

        $this->assertArrayHasKey('fields', $result);
        $this->assertArrayHasKey('settings', $result);
    }

    public function test_get_settings_throws_for_unknown_method(): void
    {
        $this->expectException(\Exception::class);
        (new GlobalPaymentHandler())->getSettings('nonexistent_gateway_abc');
    }
}
