<?php

namespace Tests\Integration\Routes;

use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Guards the REST registration invariant behind suspected-bug #5: the same
 * path + HTTP method registered from two route groups (admin api.php and
 * frontend_routes.php) produces two handler sets on one WP REST route entry.
 * WordPress dispatches only the FIRST handler whose method matches, so the
 * later registration is silently unreachable — its policy and controller
 * never run. Every duplicate is therefore dead code at best and an
 * authorization surprise at worst.
 */
class RouteRegistrationTest extends WPTestCase
{
    public function test_no_fluent_cart_route_method_is_registered_twice()
    {
        // Build a FRESH server: the invariant is about the route DECLARATIONS,
        // not suite history. A server inherited from earlier tests accumulates
        // one full handler set per extra `rest_api_init` firing (WordPress
        // re-appends handlers to existing route entries), which reports every
        // route as a duplicate without any double declaration existing.
        $GLOBALS['wp_rest_server'] = null;
        $server = rest_get_server();
        $routes = $server->get_routes('fluent-cart/v2');

        $this->assertNotEmpty($routes, 'the fluent-cart/v2 namespace must expose registered routes');

        $offences = [];
        foreach ($routes as $regex => $handlers) {
            // Key by method + the identity of the declaring Route object. A framework
            // defect (Route::setOptions, vendor/wpfluent .../Http/Route.php:795,
            // parked as TC-059) re-appends one handler-set entry per rest_api_init
            // firing, so a long-lived test process shows N copies that all point at
            // the SAME Route instance — an artifact, not a double declaration. A
            // genuine duplicate declaration comes from two DISTINCT Route objects
            // and still trips this counter.
            $declarations = [];
            foreach ($handlers as $handler) {
                $callback = $handler['callback'] ?? null;
                if (is_array($callback) && is_object($callback[0] ?? null)) {
                    $routeId = spl_object_id($callback[0]);
                } elseif (is_object($callback)) {
                    // Closures cannot be serialized — identity-key them directly.
                    $routeId = spl_object_id($callback);
                } else {
                    $routeId = md5(serialize($callback));
                }
                foreach (array_keys((array) ($handler['methods'] ?? [])) as $method) {
                    $declarations[$method][$routeId] = true;
                }
            }
            foreach ($declarations as $method => $declaringRoutes) {
                if (count($declaringRoutes) > 1) {
                    $offences[] = $method . ' ' . $regex . ' declared by ' . count($declaringRoutes) . ' distinct routes';
                }
            }
        }

        $this->assertSame(
            [],
            $offences,
            'each fluent-cart route path+method must be registered exactly once — WordPress dispatches only the first handler, so later duplicates are unreachable dead registrations: '
            . implode('; ', $offences)
        );
    }
}
