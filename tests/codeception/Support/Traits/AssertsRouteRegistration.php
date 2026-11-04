<?php

namespace Tests\Support\Traits;

use FluentCart\App\App;
use FluentCart\Framework\Http\Route;
use PHPUnit\Framework\Assert;

/**
 * Structural assertions against a route file's SOURCE (fast regression net
 * complementing executable dispatches).
 *
 * Verb, path, controller, and method are asserted as ONE registration
 * substring — separate "route exists" + "handler mentioned somewhere"
 * checks would still pass if two routes swapped their handlers.
 */
trait AssertsRouteRegistration
{
    protected string $routesSrc = '';

    protected function loadRoutesSource(string $absolutePath): void
    {
        $this->routesSrc = file_get_contents($absolutePath);
    }

    protected function assertRouteBoundTo(string $verb, string $needle, string $controller, string $method): void
    {
        $pattern = "\$router->{$verb}('{$needle}', [{$controller}::class, '{$method}'])";
        Assert::assertNotFalse(
            strpos($this->routesSrc, $pattern),
            "Expected route binding not found: {$verb} {$needle} => {$controller}::{$method}"
        );
    }

    protected function assertRouteNotRegistered(string $verb, string $needle): void
    {
        $pattern = "\$router->{$verb}('{$needle}'";
        Assert::assertFalse(
            strpos($this->routesSrc, $pattern) !== false,
            "Route registration should be absent: {$verb} {$needle}"
        );
    }

    /**
     * Runtime route-registration check — introspects the live WP REST server
     * rather than grepping source. Catches regressions where source looks
     * right but runtime routing breaks (callback signature wrong, policy
     * metadata dropped, endpoint unreachable due to autoload/bootstrap error).
     *
     * $namespacePrefix is the mounted REST prefix (e.g. '/fluent-cart/v2/shipping'),
     * $path the leaf below it, with route params in `{param}` form.
     */
    protected function assertRestRouteRegistered(string $verb, string $namespacePrefix, string $path): void
    {
        $routes = rest_get_server()->get_routes();
        $prefix = rtrim($namespacePrefix, '/') . '/';
        $leaf = ltrim($path, '/');

        // Registered route keys ARE the regex patterns WP dispatches against.
        // Substitute a dummy value for each {param} and require a WHOLE-path
        // match with WP's own semantics — a prefix/substring check would stay
        // green when e.g. 'classes/{id}' disappears but 'classes/{id}/profile'
        // survives.
        $concretePath = $prefix . preg_replace('/\{[^}]+\}/', '123', $leaf);

        $found = false;
        foreach ($routes as $routeKey => $definitions) {
            if (!preg_match('@^' . $routeKey . '$@i', $concretePath)) {
                continue;
            }
            foreach ($definitions as $def) {
                $allowedMethods = isset($def['methods']) ? (array) $def['methods'] : [];
                $methodList = [];
                foreach ($allowedMethods as $k => $v) {
                    if (is_string($k)) { $methodList[] = strtoupper($k); }
                    if (is_string($v)) { $methodList[] = strtoupper($v); }
                }
                if (!in_array(strtoupper($verb), $methodList, true)) {
                    continue;
                }
                Assert::assertTrue(
                    isset($def['callback']) && is_callable($def['callback']),
                    "Route {$verb} {$prefix}{$leaf} is registered but its callback is not callable"
                );
                $found = true;
                break 2;
            }
        }

        Assert::assertTrue(
            $found,
            "Runtime route not registered with WP REST server: {$verb} {$prefix}{$leaf}. "
            . 'Available routes under prefix: ' . implode(', ',
                array_filter(array_keys($routes), fn($r) => strpos($r, rtrim($namespacePrefix, '/')) !== false))
        );
    }

    /**
     * Locate a FluentCart framework route by method + exact URI (prefix-stripped
     * form as registered, e.g. 'tax/classes'). Uses Reflection because Route
     * exposes no public getters for uri/method. Returns null when absent.
     */
    protected function findFrameworkRoute(string $method, string $uri): ?Route
    {
        $routes = App::getInstance()->router->getRoutes();
        $method = strtoupper($method);

        $uriRef = new \ReflectionProperty(Route::class, 'uri');
        $uriRef->setAccessible(true);
        $methodRef = new \ReflectionProperty(Route::class, 'method');
        $methodRef->setAccessible(true);

        $target = trim($uri, '/');
        foreach ($routes as $route) {
            if (!$route instanceof Route) {
                continue;
            }
            if (strtoupper((string) $methodRef->getValue($route)) !== $method) {
                continue;
            }
            if (trim((string) $uriRef->getValue($route), '/') === $target) {
                return $route;
            }
        }
        return null;
    }

    protected function getRoutePolicyHandler(Route $route): ?string
    {
        $ref = new \ReflectionProperty(Route::class, 'policyHandler');
        $ref->setAccessible(true);
        $value = $ref->getValue($route);
        return is_string($value) ? $value : null;
    }

    /**
     * Assert a (method, uri) tuple is registered AND bound to the expected
     * policy + permission meta (and optionally permissions_type). Per-endpoint
     * — never a file-wide grep, which passes as long as a fragment exists
     * anywhere in the routes file.
     */
    protected function assertRouteHasPolicyAndPermissions(
        string $method,
        string $uri,
        string $expectedPolicy,
        $expectedPermissions,
        ?string $expectedType = null
    ): void {
        $route = $this->findFrameworkRoute($method, $uri);
        Assert::assertNotNull($route, "Route {$method} {$uri} should be registered");

        Assert::assertSame(
            $expectedPolicy,
            $this->getRoutePolicyHandler($route),
            "Route {$method} {$uri} must use {$expectedPolicy}"
        );

        $meta = $route->getMeta();
        $encoded = is_array($expectedPermissions) ? json_encode($expectedPermissions) : (string) $expectedPermissions;
        Assert::assertSame(
            $expectedPermissions,
            $meta['permissions'] ?? null,
            "Route {$method} {$uri} must declare permissions={$encoded}"
        );

        if ($expectedType !== null) {
            Assert::assertSame(
                $expectedType,
                $meta['permissions_type'] ?? null,
                "Route {$method} {$uri} must declare permissions_type={$expectedType}"
            );
        }
    }
}
