<?php

namespace Tests\Integration\Http;

use FluentCart\App\App;
use FluentCart\App\Modules\PaymentMethods\Cod\Cod;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\Framework\Http\Request\Request as FluentRequest;
use FluentCart\Framework\Http\Route;
use Tests\Support\RestTestCase;
use Tests\Support\WpDieCapture;

class PaymentMethodControllerTest extends RestTestCase
{
    private string $routesSrc = '';

    public function setUp(): void
    {
        parent::setUp();
        if (!GatewayManager::has('offline_payment')) {
            GatewayManager::getInstance()->register('offline_payment', new Cod());
        }
        $this->routesSrc = file_get_contents(FLUENTCART_PLUGIN_PATH . 'app/Http/Routes/api.php');
    }

    public function tearDown(): void
    {
        wp_cache_flush();
        gc_collect_cycles();
        parent::tearDown();
    }

    // =========================================================================
    // Spec 180: GET /settings/payment-methods?method=offline_payment
    // =========================================================================

    public function test_get_settings_returns_fields_and_settings(): void
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/settings/payment-methods');
        $request->set_query_params(['method' => 'offline_payment']);

        $response = $this->dispatchRequest($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertArrayHasKey('fields', $data);
        $this->assertArrayHasKey('settings', $data);
    }

    // =========================================================================
    // Spec 181: POST /settings/payment-methods updates gateway settings
    // =========================================================================

    public function test_post_settings_updates_gateway_settings(): void
    {
        $fluentRequest = new FluentRequest(App::getInstance(), [
            'method'   => 'offline_payment',
            'settings' => [
                'is_active'      => 'no',
                'checkout_label' => 'Test COD Label',
                'provider'       => 'should_be_stripped',
            ],
        ], []);

        $controller = new \FluentCart\App\Http\Controllers\PaymentMethodController();
        $handler = new \FluentCart\App\Hooks\Handlers\GlobalPaymentHandler();

        $capture = WpDieCapture::capture(fn() => $controller->store($fluentRequest, $handler));

        $this->assertTrue($capture['died'], 'store() terminates via wp_send_json on success');
        $this->assertSame(200, $capture['status']);
        $this->assertIsArray($capture['json']);
        $this->assertArrayNotHasKey('provider', $capture['json'], 'provider key must be stripped');
        $this->assertSame('Test COD Label', $capture['json']['checkout_label'] ?? null);
    }

    // =========================================================================
    // Spec 182: GET /settings/payment-methods/all returns categorized
    // =========================================================================

    public function test_get_all_returns_categorized_gateways(): void
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/settings/payment-methods/all');
        $response = $this->dispatchRequest($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertArrayHasKey('gateways', $data);
        $this->assertIsArray($data['gateways']);

        // Every entry must carry the contract keys required by GatewayManager::getAllMeta
        $requiredKeys = ['brand_color', 'description', 'icon', 'logo', 'route', 'status', 'title'];
        foreach ($data['gateways'] as $gateway) {
            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $gateway, "gateway entry missing {$key}");
            }
        }

        $routes = array_column($data['gateways'], 'route');
        $this->assertContains('offline_payment', $routes, 'offline_payment must appear in /all');

        // available (non-upcoming, non-pro) gateways must sort before upcoming ones
        $lastNonUpcomingIdx = -1;
        $firstUpcomingIdx = PHP_INT_MAX;
        foreach ($data['gateways'] as $idx => $g) {
            $isUpcoming = !empty($g['upcoming']);
            if ($isUpcoming) {
                $firstUpcomingIdx = min($firstUpcomingIdx, $idx);
            } else {
                $lastNonUpcomingIdx = max($lastNonUpcomingIdx, $idx);
            }
        }
        if ($firstUpcomingIdx !== PHP_INT_MAX && $lastNonUpcomingIdx !== -1) {
            $this->assertLessThan($firstUpcomingIdx, $lastNonUpcomingIdx, 'upcoming gateways must come last');
        }
    }

    public function test_get_all_respects_saved_order_for_available_gateways(): void
    {
        update_option('fluent_cart_payment_methods_order', ['offline_payment', 'stripe', 'paypal']);

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/settings/payment-methods/all');
        $response = $this->dispatchRequest($request);
        $data = $response->get_data();
        delete_option('fluent_cart_payment_methods_order');

        $routes = array_values(array_filter(array_column($data['gateways'], 'route'), function ($r) {
            return in_array($r, ['offline_payment', 'stripe', 'paypal'], true);
        }));
        $this->assertSame(['offline_payment', 'stripe', 'paypal'], $routes);
    }

    // =========================================================================
    // Spec 183: POST /settings/payment-methods/reorder persists option
    // =========================================================================

    public function test_reorder_persists_order_option(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/settings/payment-methods/reorder');
        $request->set_body_params([
            'order' => ['offline_payment', 'stripe', 'paypal'],
        ]);

        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $saved = get_option('fluent_cart_payment_methods_order');
        $this->assertSame(['offline_payment', 'stripe', 'paypal'], $saved);
        delete_option('fluent_cart_payment_methods_order');
    }

    // =========================================================================
    // Spec 184: GET connect/info returns connect info
    // =========================================================================

    public function test_connect_info_route_registered(): void
    {
        $this->assertStringContainsString("'payment-methods/connect/info'", $this->routesSrc);
    }

    // =========================================================================
    // Spec 185: POST disconnect
    // =========================================================================

    public function test_disconnect_route_registered(): void
    {
        $this->assertStringContainsString("'payment-methods/disconnect'", $this->routesSrc);
    }

    // =========================================================================
    // Spec 186: POST /settings/payment-methods/design
    // =========================================================================

    public function test_save_design_persists_checkout_settings(): void
    {
        $fluentRequest = new FluentRequest(App::getInstance(), [
            'method'                      => 'offline_payment',
            'checkout_label'              => 'Pay With Cash',
            'checkout_logo'               => 'https://example.com/cod.png',
            'checkout_instructions'       => 'Pay on delivery',
            'thank_you_page_instructions' => 'Thanks',
        ], []);

        $controller = new \FluentCart\App\Http\Controllers\PaymentMethodController();

        // saveDesign() merges checkout_* into gateway settings, then calls
        // gateway->updateSettings(). updateSettings() terminates via wp_send_json
        // even on success; assert persistence post-mortem.
        WpDieCapture::capture(fn() => $controller->saveDesign($fluentRequest));

        $saved = fluent_cart_get_option('fluent_cart_payment_settings_offline_payment');
        $this->assertIsArray($saved);
        $this->assertSame('Pay With Cash', $saved['checkout_label']);
        $this->assertSame('https://example.com/cod.png', $saved['checkout_logo']);
        $this->assertSame('Pay on delivery', $saved['checkout_instructions']);
    }

    // =========================================================================
    // Spec 187-188: install / activate addon
    // =========================================================================

    public function test_install_addon_route_registered(): void
    {
        $this->assertStringContainsString("'payment-methods/install-addon'", $this->routesSrc);
    }

    public function test_activate_addon_route_registered(): void
    {
        $this->assertStringContainsString("'payment-methods/activate-addon'", $this->routesSrc);
    }

    // =========================================================================
    // Spec 189-190: Route prefix + policy + permissions meta
    // =========================================================================

    public function test_routes_live_under_settings_prefix_with_policy(): void
    {
        $this->assertMatchesRegularExpression(
            "/\\\$router->prefix\\('settings\\/'\\)\\s*->withPolicy\\('StoreSettingsPolicy'\\)/",
            $this->routesSrc
        );
    }

    public function test_each_payment_method_route_declares_is_super_admin_permission(): void
    {
        // Bound per route via the runtime registry — a whole-file grep stays
        // green as long as the string exists ANYWHERE in api.php, so a single
        // route quietly widened to a weaker permission (e.g. store/settings)
        // would pass it. A widened route would expose stored gateway
        // credentials to Pro store managers; the runtime guest/subscriber
        // tests cannot see that regression because core-only test runs give
        // non-admins an empty permission set either way. The three PayPal
        // routes carry their own contract (AdminPolicy + super_admin):
        // seller-auth-token persists merchant credentials and activates
        // PayPal, so losing that binding redirects store payments.
        $expected = [
            ['GET', 'settings/payment-methods', 'is_super_admin', 'StoreSettingsPolicy'],
            ['POST', 'settings/payment-methods', 'is_super_admin', 'StoreSettingsPolicy'],
            ['GET', 'settings/payment-methods/all', 'is_super_admin', 'StoreSettingsPolicy'],
            ['POST', 'settings/payment-methods/reorder', 'is_super_admin', 'StoreSettingsPolicy'],
            ['GET', 'settings/payment-methods/connect/info', 'is_super_admin', 'StoreSettingsPolicy'],
            ['POST', 'settings/payment-methods/disconnect', 'is_super_admin', 'StoreSettingsPolicy'],
            ['POST', 'settings/payment-methods/design', 'is_super_admin', 'StoreSettingsPolicy'],
            ['POST', 'settings/payment-methods/install-addon', 'is_super_admin', 'StoreSettingsPolicy'],
            ['POST', 'settings/payment-methods/activate-addon', 'is_super_admin', 'StoreSettingsPolicy'],
            ['POST', 'settings/payment-methods/paypal/seller-auth-token', 'super_admin', 'AdminPolicy'],
            ['POST', 'settings/payment-methods/paypal/webhook/setup', 'super_admin', 'AdminPolicy'],
            ['GET', 'settings/payment-methods/paypal/webhook/check', 'super_admin', 'AdminPolicy'],
        ];

        $uriRef = new \ReflectionProperty(Route::class, 'uri');
        $uriRef->setAccessible(true);
        $methodRef = new \ReflectionProperty(Route::class, 'method');
        $methodRef->setAccessible(true);
        $policyRef = new \ReflectionProperty(Route::class, 'policyHandler');
        $policyRef->setAccessible(true);

        // Collect ALL routes per (method, uri) — keying with a single slot
        // would let a later route shadow an earlier duplicate, and WordPress
        // dispatches the FIRST matching registration, so the oracle must see
        // every one and there must be exactly one.
        $registered = [];
        foreach (App::getInstance()->router->getRoutes() as $route) {
            if (!$route instanceof Route) {
                continue;
            }
            $key = strtoupper((string) $methodRef->getValue($route))
                . ' ' . trim((string) $uriRef->getValue($route), '/');
            $registered[$key][] = $route;
        }

        foreach ($expected as [$method, $uri, $permission, $policy]) {
            $key = $method . ' ' . $uri;
            $this->assertArrayHasKey($key, $registered,
                "Route {$key} must be registered");
            $this->assertCount(1, $registered[$key],
                "Route {$key} must be registered exactly once — a duplicate registration could dispatch with different guards");
            foreach ($registered[$key] as $route) {
                $meta = $route->getMeta();
                $this->assertSame($permission, $meta['permissions'] ?? null,
                    "Route {$key} must require {$permission} — any weaker permission widens gateway-settings access");
                $this->assertSame($policy, $policyRef->getValue($route),
                    "Route {$key} must be guarded by {$policy}");
            }
        }
    }

    // =========================================================================
    // Spec 191-193: PayPal routes
    // =========================================================================

    public function test_paypal_seller_auth_token_route_registered(): void
    {
        $this->assertStringContainsString("'settings/payment-methods/paypal/seller-auth-token'", $this->routesSrc);
    }

    public function test_paypal_webhook_setup_route_registered(): void
    {
        $this->assertStringContainsString("'settings/payment-methods/paypal/webhook/setup'", $this->routesSrc);
    }

    public function test_paypal_webhook_check_route_registered(): void
    {
        $this->assertStringContainsString("'settings/payment-methods/paypal/webhook/check'", $this->routesSrc);
    }

    // =========================================================================
    // Spec 194-196: Permissions
    // =========================================================================

    public function test_unauthenticated_request_returns_4xx(): void
    {
        // The sensitive oracle is getSettings() — it returns the gateway's
        // stored settings (API/webhook credentials), not just catalog meta.
        $settingsRead = new \WP_REST_Request('GET', '/fluent-cart/v2/settings/payment-methods');
        $settingsRead->set_query_params(['method' => 'offline_payment']);
        $settingsResponse = $this->dispatchRequestAsGuest($settingsRead);
        $this->assertSame(401, $settingsResponse->get_status(),
            'An anonymous request to gateway settings is rejected as unauthenticated');
        $settingsBody = $settingsResponse->get_data();
        $this->assertIsArray($settingsBody);
        $this->assertArrayHasKey('code', $settingsBody,
            'The rejection body is a REST error envelope');
        $this->assertArrayNotHasKey('settings', $settingsBody,
            'The rejection body leaks no gateway settings');
        $this->assertArrayNotHasKey('fields', $settingsBody,
            'The rejection body leaks no gateway field definitions');

        $read = $this->dispatchRequestAsGuest(
            new \WP_REST_Request('GET', '/fluent-cart/v2/settings/payment-methods/all')
        );
        $this->assertSame(401, $read->get_status(),
            'An anonymous request to payment-method settings is rejected as unauthenticated');

        // PayPal credential exchange: seller-auth-token persists merchant
        // credentials and activates the gateway — the rejection must land
        // before the controller, leaving PayPal settings untouched.
        $paypalBefore = fluent_cart_get_option('fluent_cart_payment_settings_paypal');
        // If the policy ever regressed, the controller would call PayPal —
        // short-circuit all external HTTP so even the failure path stays local.
        $blockHttp = function () {
            return new \WP_Error('http_blocked', 'External HTTP is blocked in tests');
        };
        add_filter('pre_http_request', $blockHttp);
        $paypalWrite = new \WP_REST_Request('POST', '/fluent-cart/v2/settings/payment-methods/paypal/seller-auth-token');
        $paypalWrite->set_body_params(['authCode' => 'attacker-code', 'sharedId' => 'attacker-id']);
        $paypalResponse = $this->dispatchRequestAsGuest($paypalWrite);
        remove_filter('pre_http_request', $blockHttp);
        $this->assertSame(401, $paypalResponse->get_status(),
            'An anonymous PayPal seller-auth-token exchange is rejected as unauthenticated');
        $this->assertSame($paypalBefore, fluent_cart_get_option('fluent_cart_payment_settings_paypal'),
            'The rejected anonymous PayPal credential exchange persisted nothing');

        // Writes need their own oracle: a verb-scoped policy regression could
        // reject reads yet accept mutations.
        $write = new \WP_REST_Request('POST', '/fluent-cart/v2/settings/payment-methods/reorder');
        $write->set_body_params(['order' => ['offline_payment']]);
        $writeResponse = $this->dispatchRequestAsGuest($write);
        $this->assertSame(401, $writeResponse->get_status(),
            'An anonymous payment-method reorder is rejected as unauthenticated');
        $writeBody = $writeResponse->get_data();
        $this->assertIsArray($writeBody);
        $this->assertArrayHasKey('code', $writeBody,
            'The rejection body is a REST error envelope, not gateway data');
        $this->assertArrayNotHasKey('gateways', $writeBody,
            'The rejection body leaks no gateway data');
        $this->assertFalse(get_option('fluent_cart_payment_methods_order'),
            'The rejected anonymous reorder persisted nothing');
    }

    public function test_subscriber_without_super_admin_returns_403(): void
    {
        $subscriberId = self::factory()->user->create(['role' => 'subscriber']);

        // Same sensitive oracle as the guest test: getSettings() returns
        // stored gateway credentials, so the subscriber denial must cover it.
        $settingsRead = new \WP_REST_Request('GET', '/fluent-cart/v2/settings/payment-methods');
        $settingsRead->set_query_params(['method' => 'offline_payment']);
        $settingsResponse = $this->dispatchRequestAs($subscriberId, $settingsRead);
        $this->assertSame(403, $settingsResponse->get_status(),
            'A subscriber without super-admin permission cannot read gateway settings');
        $settingsBody = $settingsResponse->get_data();
        $this->assertIsArray($settingsBody);
        $this->assertArrayHasKey('code', $settingsBody,
            'The rejection body is a REST error envelope');
        $this->assertArrayNotHasKey('settings', $settingsBody,
            'The rejection body leaks no gateway settings');
        $this->assertArrayNotHasKey('fields', $settingsBody,
            'The rejection body leaks no gateway field definitions');

        $read = $this->dispatchRequestAs(
            $subscriberId,
            new \WP_REST_Request('GET', '/fluent-cart/v2/settings/payment-methods/all')
        );
        $this->assertSame(403, $read->get_status(),
            'A subscriber without super-admin permission cannot read payment-method settings');

        // Same PayPal oracle as the guest test — subscriber must not reach
        // the credential exchange either.
        $paypalBefore = fluent_cart_get_option('fluent_cart_payment_settings_paypal');
        // If the policy ever regressed, the controller would call PayPal —
        // short-circuit all external HTTP so even the failure path stays local.
        $blockHttp = function () {
            return new \WP_Error('http_blocked', 'External HTTP is blocked in tests');
        };
        add_filter('pre_http_request', $blockHttp);
        $paypalWrite = new \WP_REST_Request('POST', '/fluent-cart/v2/settings/payment-methods/paypal/seller-auth-token');
        $paypalWrite->set_body_params(['authCode' => 'attacker-code', 'sharedId' => 'attacker-id']);
        $paypalResponse = $this->dispatchRequestAs($subscriberId, $paypalWrite);
        remove_filter('pre_http_request', $blockHttp);
        $this->assertSame(403, $paypalResponse->get_status(),
            'A subscriber cannot exchange PayPal seller-auth-token credentials');
        $this->assertSame($paypalBefore, fluent_cart_get_option('fluent_cart_payment_settings_paypal'),
            'The rejected subscriber PayPal credential exchange persisted nothing');

        $write = new \WP_REST_Request('POST', '/fluent-cart/v2/settings/payment-methods/reorder');
        $write->set_body_params(['order' => ['offline_payment']]);
        $writeResponse = $this->dispatchRequestAs($subscriberId, $write);
        $this->assertSame(403, $writeResponse->get_status(),
            'A subscriber without super-admin permission cannot reorder payment methods');
        $writeBody = $writeResponse->get_data();
        $this->assertIsArray($writeBody);
        $this->assertArrayHasKey('code', $writeBody,
            'The rejection body is a REST error envelope, not gateway data');
        $this->assertArrayNotHasKey('gateways', $writeBody,
            'The rejection body leaks no gateway data');
        $this->assertFalse(get_option('fluent_cart_payment_methods_order'),
            'The rejected subscriber reorder persisted nothing');
    }

}
