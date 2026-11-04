<?php

namespace Tests\Support\Helper;

use Codeception\Module;
use Codeception\TestInterface;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\Framework\Http\Request\Request as FluentRequest;
use Tests\Support\Isolation\DbStateSnapshot;
use Tests\Support\Isolation\ObjectCacheSnapshot;
use Tests\Support\Isolation\WpRuntimeReset;
use Tests\Support\WpDieCapture;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Codeception module exposing $I->... methods to Cest classes.
 *
 * Wraps rest_do_request() with the same auth + nonce + cleanup logic that
 * Tests\Support\RestTestCase already uses for the Integration suite.
 */
class Functional extends Module
{
    /** @var WP_REST_Response|null */
    private $lastResponse;

    /** @var array|null */
    private $lastBody;

    /** @var DbStateSnapshot|null */
    private $dbSnapshot;

    /** @var ObjectCacheSnapshot|null */
    private $cacheSnapshot;

    // ---------------------------------------------------------------------
    // Per-test state isolation (TC-048)
    // ---------------------------------------------------------------------

    /**
     * Runs before EVERY test method, ahead of the Cest's own `_before`.
     *
     * First call in the process captures the post-bootstrap database as the
     * baseline (WPLoader install + DBMigrator::migrate() have both run by
     * the time any test starts). Every subsequent call restores whatever
     * tables the previous test dirtied — WordPress core tables included —
     * and resets the in-process runtime (object cache, roles, current user,
     * superglobals), so each test starts from the same committed state
     * regardless of execution order.
     */
    public function _before(TestInterface $test)
    {
        global $wpdb;

        if ($this->dbSnapshot === null) {
            $this->dbSnapshot    = new DbStateSnapshot();
            $this->cacheSnapshot = new ObjectCacheSnapshot();
        }

        if (!$this->dbSnapshot->isCaptured()) {
            $this->seedStandardUsers();
            $this->dbSnapshot->capture($wpdb);
            $this->cacheSnapshot->capture();
        } else {
            $this->dbSnapshot->restore($wpdb);
            $this->cacheSnapshot->restore();
        }

        WpRuntimeReset::reset();
    }

    /**
     * Provision the five standard actors into the pre-capture baseline.
     * wp_insert_user() bcrypt-hashes the password (~280ms); without seeding,
     * every test's login would re-create its user against the restored
     * users table and pay that hash. Seeded once, logins become pure reads
     * and every test sees identical, deterministic user IDs. Side-effect
     * rows the user_register hooks create in fct_* are wiped right after by
     * capture()'s baseline normalization.
     */
    private function seedStandardUsers(): void
    {
        foreach (['super_admin', 'customer', 'manager', 'worker', 'accountant'] as $role) {
            $this->provisionUser($role);
        }
    }

    // ---------------------------------------------------------------------
    // Auth
    // ---------------------------------------------------------------------

    public function loginAsSuperAdmin(): int
    {
        return $this->loginAs('super_admin');
    }

    public function loginAsManager(): int
    {
        return $this->loginAs('manager');
    }

    public function loginAsWorker(): int
    {
        return $this->loginAs('worker');
    }

    public function loginAsAccountant(): int
    {
        return $this->loginAs('accountant');
    }

    /**
     * Create or reuse a WP user, attach the requested FluentCart shop role,
     * set them as the current user, and refresh the per-request user cache.
     * Returns the WP user id.
     */
    public function loginAs(string $fcRole): int
    {
        $userId = $this->provisionUser($fcRole);

        wp_set_current_user($userId);
        $this->refreshFluentCartCurrentUserCache();

        return $userId;
    }

    /**
     * Create-or-reuse the WP user backing an FC role and make sure the role
     * meta + admin cap are attached. Idempotent: after the baseline seeding
     * in `_before`, every call is a pure read (update_user_meta short-
     * circuits on identical values, add_cap is guarded by has_cap).
     */
    private function provisionUser(string $fcRole): int
    {
        if ($fcRole === 'super_admin') {
            return $this->ensureUser('fc_func_super_admin', 'administrator');
        }

        if ($fcRole === 'customer') {
            return $this->ensureUser('fc_func_customer', 'subscriber');
        }

        $login  = 'fc_func_' . $fcRole;
        $userId = $this->ensureUser($login, 'subscriber');

        update_user_meta($userId, '_fluent_cart_admin_role', $fcRole);

        $wpUser = get_user_by('ID', $userId);
        if ($wpUser && !$wpUser->has_cap(PermissionManager::ADMIN_CAP)) {
            $wpUser->add_cap(PermissionManager::ADMIN_CAP);
        }

        return $userId;
    }

    public function logout(): void
    {
        wp_set_current_user(0);
        $this->refreshFluentCartCurrentUserCache();
    }

    private function ensureUser(string $login, string $wpRole): int
    {
        $user = get_user_by('login', $login);
        if ($user) {
            return (int) $user->ID;
        }

        $userId = wp_insert_user([
            'user_login' => $login,
            'user_email' => $login . '@functional.test',
            'user_pass'  => wp_generate_password(12, false),
            'role'       => $wpRole,
        ]);

        if (is_wp_error($userId)) {
            throw new \RuntimeException('Failed to create test user: ' . $userId->get_error_message());
        }

        return (int) $userId;
    }

    // ---------------------------------------------------------------------
    // REST dispatch
    // ---------------------------------------------------------------------

    public function get(string $route, array $query = []): array
    {
        return $this->dispatch('GET', $route, $query);
    }

    public function post(string $route, array $body = []): array
    {
        return $this->dispatch('POST', $route, $body);
    }

    /**
     * Create an admin order the way the Create Order screen does: price it,
     * then save with the previewed revision and total.
     */
    public function createOrder(array $body): array
    {
        [$status, $pricing] = $this->post('/fluent-cart/v2/orders/price', $body);
        if ($status !== 200) {
            return [$status, $pricing];
        }

        return $this->post('/fluent-cart/v2/orders', array_merge($body, [
            'quote_revision' => $pricing['quote_revision'],
            'total_amount'   => $pricing['total_amount'],
        ]));
    }

    public function put(string $route, array $body = []): array
    {
        return $this->dispatch('PUT', $route, $body);
    }

    public function delete(string $route, array $body = []): array
    {
        return $this->dispatch('DELETE', $route, $body);
    }

    /**
     * Dispatch a POST route whose controller intentionally terminates through
     * wp_send_json(), returning the captured HTTP status and decoded JSON.
     */
    public function postCapturingJsonDie(string $route, array $body = [], bool $includeGuestNonce = false): array
    {
        $this->resetFluentRequest();
        $this->clearNonceGlobals();
        $this->resetRouterParameterCache();
        $this->resetRequestRuntimeCaches();

        $request = new WP_REST_Request('POST', $route);
        $request->set_body_params($body);
        $request->set_header('Content-Type', 'application/json');

        $userId = get_current_user_id();
        if ($userId || $includeGuestNonce) {
            $nonce = wp_create_nonce('wp_rest');
            $request->set_header('X-WP-Nonce', $nonce);
            $_REQUEST['_wpnonce']       = $nonce;
            $_SERVER['HTTP_X_WP_NONCE'] = $nonce;
        }

        $captured = WpDieCapture::dispatchRestWithDie($request);
        $this->lastResponse = $captured['response'];
        $this->lastBody = $captured['json'] ?? [];

        return [(int) $captured['status'], $this->lastBody, $captured];
    }

    /**
     * Dispatch the public cart's WordPress AJAX ingress with its real nonce,
     * request binding, action hook, and device cart cookie.
     */
    public function checkoutAjax(array $params, bool $withNonce = true): array
    {
        $this->resetFluentRequest();
        $this->clearNonceGlobals();
        $this->resetRequestRuntimeCaches();

        $request = array_merge([
            'action' => 'fluent_cart_checkout_routes',
        ], $params);

        $_GET = $request;
        $_POST = [];
        $_REQUEST = $request;
        if ($withNonce) {
            $_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce('wp_rest');
        }

        if (!empty($params['fct_cart_hash'])) {
            $_COOKIE['fct_cart_hash'] = sanitize_text_field($params['fct_cart_hash']);
        }

        $app = App::getInstance();
        $fluentRequest = new FluentRequest($app, $_GET, $_POST);
        $app->instance(FluentRequest::class, $fluentRequest);
        $app->instance('request', $fluentRequest);

        $hook = is_user_logged_in()
            ? 'wp_ajax_fluent_cart_checkout_routes'
            : 'wp_ajax_nopriv_fluent_cart_checkout_routes';

        $captured = WpDieCapture::capture(function () use ($hook) {
            do_action($hook);
        });

        $this->lastResponse = null;
        $this->lastBody = $captured['json'] ?? [];

        return [(int) $captured['status'], $this->lastBody, $captured];
    }

    /**
     * Dispatch a request through the WordPress REST server in-process.
     * Returns [status, body_array, response_object] for convenient destructuring.
     */
    private function dispatch(string $method, string $route, array $params): array
    {
        $this->resetFluentRequest();
        $this->clearNonceGlobals();
        $this->resetRouterParameterCache();

        // Each dispatch simulates a fresh HTTP request; production would get
        // fresh per-request memos (TESTABILITY-DEBT #2/#7/#8 seams).
        $this->resetRequestRuntimeCaches();

        $request = new WP_REST_Request(strtoupper($method), $route);

        if (in_array($method, ['GET', 'DELETE'], true)) {
            $request->set_query_params($params);
        } else {
            $request->set_body_params($params);
            $request->set_header('Content-Type', 'application/json');
        }

        $userId = get_current_user_id();
        if ($userId && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $nonce = wp_create_nonce('wp_rest');
            $request->set_header('X-WP-Nonce', $nonce);
            $_REQUEST['_wpnonce']        = $nonce;
            $_SERVER['HTTP_X_WP_NONCE']  = $nonce;
        }

        $response = rest_do_request($request);

        $this->lastResponse = $response;
        $data               = $response->get_data();
        $encoded            = wp_json_encode($data);
        $this->lastBody     = is_string($encoded)
            ? json_decode($encoded, true)
            : (is_array($data) ? $data : []);

        return [$response->get_status(), $this->lastBody, $response];
    }

    // ---------------------------------------------------------------------
    // Assertions
    // ---------------------------------------------------------------------

    public function seeResponseCodeIs(int $expected): void
    {
        \PHPUnit\Framework\Assert::assertSame(
            $expected,
            $this->lastResponse->get_status(),
            'Unexpected REST status. Body: ' . wp_json_encode($this->lastBody)
        );
    }

    public function seeResponseJsonMatchesSubset(array $subset): void
    {
        $this->assertSubset($subset, $this->lastBody ?? [], '');
    }

    public function grabResponseJson(): array
    {
        return $this->lastBody ?? [];
    }

    public function grabResponseStatus(): int
    {
        return $this->lastResponse ? (int) $this->lastResponse->get_status() : 0;
    }


    /**
     * Recursive subset assertion. PHPUnit removed assertArraySubset() in 9.x;
     * this is the minimal replacement: every key in $expected must exist in
     * $actual at the same path with the same value (or, for array values,
     * recursively satisfy the subset rule).
     */
    private function assertSubset($expected, $actual, string $path): void
    {
        if (!is_array($expected)) {
            \PHPUnit\Framework\Assert::assertEquals(
                $expected,
                $actual,
                "Subset mismatch at '{$path}'. Body: " . wp_json_encode($this->lastBody)
            );
            return;
        }

        \PHPUnit\Framework\Assert::assertIsArray(
            $actual,
            "Expected array at '{$path}', got " . gettype($actual) . '. Body: ' . wp_json_encode($this->lastBody)
        );

        foreach ($expected as $key => $value) {
            $childPath = $path === '' ? (string) $key : ($path . '.' . $key);
            \PHPUnit\Framework\Assert::assertArrayHasKey(
                $key,
                $actual,
                "Missing key '{$childPath}' in response. Body: " . wp_json_encode($this->lastBody)
            );
            $this->assertSubset($value, $actual[$key], $childPath);
        }
    }

    // ---------------------------------------------------------------------
    // DB helpers
    // ---------------------------------------------------------------------

    /**
     * Hard-delete every fluent-products CPT post (and its post_meta / term
     * relationships) from wp_posts. Cest tests that exercise the products
     * REST surface need a clean wp_posts as well as a clean fct_*; the
     * fct_*-only reset leaves orphan product posts that bleed into list /
     * search assertions across methods within one PHP process.
     */
    public function purgeFluentProductPosts(): void
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
            \FluentCart\App\CPT\FluentProducts::CPT_NAME
        ));
        foreach ($ids as $id) {
            wp_delete_post((int) $id, true);
        }
    }

    public function countFluentProductPosts(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
            \FluentCart\App\CPT\FluentProducts::CPT_NAME
        ));
    }

    public function countProductDetails(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_product_details");
    }

    public function countProductVariants(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_product_variations");
    }

    /**
     * Point CartCheckoutHelper's request singleton at a freshly seeded cart.
     * Its production make() method uses a function-static that cannot be reset
     * between simulated HTTP requests in one Codeception PHP process.
     */
    public function rebindCheckoutHelperToCart(string $cartHash): void
    {
        $cart = \FluentCart\App\Models\Cart::query()
            ->where('cart_hash', $cartHash)
            ->first();

        if (!$cart) {
            throw new \RuntimeException('Checkout helper rebind requires an existing cart');
        }

        $helper = \FluentCart\App\Helpers\CartCheckoutHelper::make();
        $reflection = new \ReflectionClass($helper);

        foreach ([
            'cart'               => $cart,
            'checkoutData'       => (array) $cart->checkout_data,
            'utmData'            => (array) $cart->utm_data,
            'couponDiscountData' => [],
        ] as $property => $value) {
            $prop = $reflection->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue($helper, $value);
        }
    }

    /**
     * Truncate every fct_* table. Leaves WordPress core tables untouched.
     *
     * Since TC-048 the module's `_before` hook already restores EVERY table
     * (WordPress core included) to the clean baseline before each test, so
     * when the snapshot is active this returns immediately — re-truncating
     * 41 tables per test measured ~140ms for zero state change. Kept
     * because ~118 existing Cests call it from their `_before` (all calls
     * are in `_before`, verified — none rely on mid-test truncation), and
     * as a real truncation fallback for any use outside the per-test
     * lifecycle. New Cests do not need to call it.
     */
    public function resetFluentCartTables(): void
    {
        global $wpdb;

        // Every functional request comes from 127.0.0.1, so RateLimiter's
        // per-visitor windows (transients, not fct_ rows) would carry hits
        // from one Cest into the next and refuse later requests with 429.
        $this->clearRateLimiterWindows();

        if ($this->dbSnapshot !== null && $this->dbSnapshot->isCaptured()) {
            return;
        }

        // TC-047: re-check the opt-in against the LIVE connection before
        // truncating — the preflight extension validated the config, this
        // validates the database WordPress actually connected to.
        \Tests\Support\Guard\DestructiveDbGuard::assertDbAllowed(
            defined('DB_HOST') ? DB_HOST : '',
            defined('DB_NAME') ? DB_NAME : '',
            'Functional::resetFluentCartTables()'
        );

        $tables = $wpdb->get_col(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($wpdb->prefix . 'fct_') . '%'
            )
        );

        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $wpdb->query("TRUNCATE TABLE `{$table}`");
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function clearRateLimiterWindows(): void
    {
        global $wpdb;

        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_fct_rate_limit_') . '%'
            )
        );

        // delete_transient() so the in-process options cache forgets them too.
        foreach ($names as $name) {
            delete_transient(substr($name, strlen('_transient_')));
        }
    }

    /**
     * Class-level handle on the StockManagement instance the test-time
     * arming binds the action callbacks to. Held on the class (not a method
     * static) so disarmStockListeners() can identify the same callable and
     * remove it via remove_action.
     *
     * @var \FluentCart\App\Modules\StockManagement\StockManagement|null
     */
    private static $armedStockModule = null;

    /**
     * Hook name → method name pairs that armStockListeners binds and
     * disarmStockListeners reverses. One source of truth for both ends.
     */
    private const STOCK_LISTENER_BINDINGS = [
        'fluent_cart/order_created'           => 'manageStockOnOrderCreated',
        'fluent_cart/shipping_status_changed' => 'manageStockOnShippingStatusChanged',
        'fluent_cart/order_status_changed'    => 'manageStockOnOrderStatusChanged',
        'fluent_cart/order_refunded'          => 'manageStockOnOrderRefunded',
        'fluent_cart/order_paid'              => 'manageStockOnOrderPaid',
        'fluent_cart/order_updated'           => 'manageStockOnOrderUpdated',
    ];

    /**
     * Arm the StockManagement listeners for this PHP process.
     *
     * The plugin calls StockManagement::register() once at boot, which adds the
     * six order/shipping/refund/pay/cancel/update hooks ONLY when
     * ModuleSettings::isActive('stock_management') is true. The setting defaults
     * to 'no' and ModuleSettings::getAllSettings() memoizes the result in a
     * function-static with no public reset seam (see
     * Cases/Integration/stock.md:186 and events.md:22). So once the plugin has
     * booted with stock disabled — which it always has in the functional suite —
     * flipping the option later cannot retroactively wire those hooks.
     *
     * This helper bypasses the boot-time gate. It (a) writes the option ON,
     * (b) refreshes the memoized cache so subsequent isActive() calls report
     * true (validateProducts / canPurchase rely on this), and (c) re-binds the
     * six listeners directly via add_action on a class-level StockManagement
     * instance.
     *
     * Pair with disarmStockListeners() in `_after` so each method leaves the
     * option, the ModuleSettings static cache, and the action handlers in the
     * same state the previous _before found them. Without that pairing the
     * helper would leak module-active state into other Cests that depend on
     * the suite's default `stock_management=off`.
     */
    public function armStockListeners(): void
    {
        $settings = get_option(\FluentCart\Api\ModuleSettings::MODULE_SETTINGS_OPTION, []);
        if (!is_array($settings)) {
            $settings = [];
        }
        $settings['stock_management'] = ['active' => 'yes'];
        update_option(\FluentCart\Api\ModuleSettings::MODULE_SETTINGS_OPTION, $settings);
        \FluentCart\Api\ModuleSettings::getAllSettings(false);

        if (self::$armedStockModule !== null) {
            return;
        }
        self::$armedStockModule = new \FluentCart\App\Modules\StockManagement\StockManagement();

        foreach (self::STOCK_LISTENER_BINDINGS as $hook => $method) {
            add_action($hook, [self::$armedStockModule, $method]);
        }
    }

    /**
     * Reverse of armStockListeners(). Removes the six action callbacks bound
     * by the matching arm() call, clears the stock_management key from the
     * module-settings option, and refreshes the ModuleSettings static cache
     * so the next caller of isActive() sees the suite's default state again.
     * Idempotent — safe to call from `_after` even on tests that did not
     * arm.
     */
    public function disarmStockListeners(): void
    {
        if (self::$armedStockModule !== null) {
            foreach (self::STOCK_LISTENER_BINDINGS as $hook => $method) {
                remove_action($hook, [self::$armedStockModule, $method]);
            }
            self::$armedStockModule = null;
        }

        $settings = get_option(\FluentCart\Api\ModuleSettings::MODULE_SETTINGS_OPTION, []);
        if (is_array($settings) && array_key_exists('stock_management', $settings)) {
            unset($settings['stock_management']);
            update_option(\FluentCart\Api\ModuleSettings::MODULE_SETTINGS_OPTION, $settings);
        }
        \FluentCart\Api\ModuleSettings::getAllSettings(false);
    }

    /**
     * The TaxModule instance armed by armTaxModule(), so disarmTaxModule() can
     * unbind exactly the hooks that instance registered.
     *
     * @var \FluentCart\App\Modules\Tax\TaxModule|null
     */
    private static $armedTaxModule = null;

    /**
     * Switch tax collection ON for this PHP process.
     *
     * TaxModule::register() runs once at plugin boot, memoizes the tax settings
     * in the instance, and only wires the checkout tax pipeline
     * (`fluent_cart/cart/cart_data_items_updated` → recalculateTax,
     * prepare_other_data → prepareOtherData, the tax_behavior / estimated_total /
     * shipping_total filters, ...) when `enable_tax` was 'yes' at that moment.
     * The suite boots with tax off, so flipping the option later changes
     * nothing — the same boot-time gate armStockListeners() works around for
     * stock. This helper writes the option, resets the tax singletons, and
     * registers a FRESH TaxModule so every hook production would have bound
     * is bound here too, by production's own register() and not by a copy.
     *
     * `$settings` overrides the enabled defaults (excluded, billing basis,
     * per-item rounding). Pair with disarmTaxModule() in `_after`.
     */
    public function armTaxModule(array $settings = []): void
    {
        $current = get_option('fluent_cart_tax_configuration_settings', []);
        if (!is_array($current)) {
            $current = [];
        }
        update_option('fluent_cart_tax_configuration_settings', array_merge($current, [
            'enable_tax'            => 'yes',
            'tax_inclusion'         => 'excluded',
            'tax_calculation_basis' => 'billing',
            'tax_rounding'          => 'item',
        ], $settings));

        if (self::$armedTaxModule !== null) {
            $this->removeHooksBoundTo(self::$armedTaxModule);
            self::$armedTaxModule = null;
        }
        $this->resetTaxSingletons();

        self::$armedTaxModule = new \FluentCart\App\Modules\Tax\TaxModule();
        self::$armedTaxModule->register();
    }

    /**
     * Reverse of armTaxModule(): unbinds every hook the armed instance
     * registered, drops the `enable_tax` flag from the option, and resets the
     * tax singletons. Idempotent — safe from `_after` on tests that did not arm.
     */
    public function disarmTaxModule(): void
    {
        if (self::$armedTaxModule !== null) {
            $this->removeHooksBoundTo(self::$armedTaxModule);
            self::$armedTaxModule = null;
        }

        $settings = get_option('fluent_cart_tax_configuration_settings', []);
        if (is_array($settings) && array_key_exists('enable_tax', $settings)) {
            unset($settings['enable_tax']);
            update_option('fluent_cart_tax_configuration_settings', $settings);
        }
        $this->resetTaxSingletons();
    }

    private function resetTaxSingletons(): void
    {
        $ref = new \ReflectionClass(\FluentCart\App\Services\Tax\TaxManager::class);
        if ($ref->hasProperty('instance')) {
            $prop = $ref->getProperty('instance');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }
        \FluentCart\App\Modules\Tax\TaxCalculator::resetCache();
    }

    /**
     * Remove every WordPress hook callback owned by `$owner`: array callbacks
     * `[$owner, 'method']` and closures whose bound `$this` is `$owner`.
     */
    private function removeHooksBoundTo(object $owner): void
    {
        global $wp_filter;
        foreach ($wp_filter as $hookName => $hook) {
            if (!$hook instanceof \WP_Hook) {
                continue;
            }
            foreach ($hook->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $callback) {
                    $function = $callback['function'];
                    $owned = false;
                    if (is_array($function) && isset($function[0]) && $function[0] === $owner) {
                        $owned = true;
                    } elseif ($function instanceof \Closure) {
                        try {
                            $owned = (new \ReflectionFunction($function))->getClosureThis() === $owner;
                        } catch (\ReflectionException $e) {
                            $owned = false;
                        }
                    }
                    if ($owned) {
                        remove_filter($hookName, $function, $priority);
                    }
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function resetFluentRequest(): void
    {
        $app     = App::getInstance();
        $request = new FluentRequest($app, [], []);
        $app->instance(FluentRequest::class, $request);
        $app->instance('request', $request);
        if ($app->bound('wprestrequest')) {
            $app->forgetInstance('wprestrequest');
        }
    }

    /**
     * Clear nonce globals between dispatches so a guest dispatch following an
     * authed dispatch (or vice versa) does not inherit the previous request's
     * X-WP-Nonce / _wpnonce headers from $_REQUEST and $_SERVER.
     */
    private function clearNonceGlobals(): void
    {
        unset($_REQUEST['_wpnonce'], $_SERVER['HTTP_X_WP_NONCE']);
    }

    private function refreshFluentCartCurrentUserCache(): void
    {
        if (class_exists(Helper::class)) {
            Helper::getCurrentUser(true);
        }
    }

    private function resetRequestRuntimeCaches(): void
    {
        if (class_exists(\FluentCart\Api\Resource\CustomerResource::class)) {
            \FluentCart\Api\Resource\CustomerResource::resetCurrentCustomerRuntimeCache();
        }
        if (class_exists(\FluentCart\Api\Resource\FrontendResource\CartResource::class)) {
            \FluentCart\Api\Resource\FrontendResource\CartResource::resetCartCache();
        }
        if (class_exists(\FluentCart\App\Modules\Tax\TaxCalculator::class)) {
            \FluentCart\App\Modules\Tax\TaxCalculator::resetCache();
        }
        if (class_exists(\FluentCart\App\Services\Renderer\CheckoutFieldsSchema::class)) {
            \FluentCart\App\Services\Renderer\CheckoutFieldsSchema::resetFieldsCache();
        }
        if (class_exists(\FluentCart\Api\StoreSettings::class)) {
            // Static settings cache; without this a test that changed store
            // options (currency, order mode) leaks them into the next dispatch
            // even though the snapshot restored the options table.
            \FluentCart\Api\StoreSettings::clearCache();
        }
        if (class_exists(\FluentCart\App\Helpers\CartCheckoutHelper::class)) {
            // Request-scoped singleton in production; without this reset the
            // helper keeps the previous test's cart (TESTABILITY-DEBT #14).
            \FluentCart\App\Helpers\CartCheckoutHelper::resetInstance();
        }
        if (method_exists(\FluentCart\App\Helpers\AddressHelper::class, 'resetRateCache')) {
            // Live carrier rates are held for one request in production.
            \FluentCart\App\Helpers\AddressHelper::resetRateCache();
        }
    }

    /**
     * WPFluent Route caches the URL params it resolved on the first request
     * (`$parameters` and `$substitutedParameters`). The Route instance is
     * reused across requests in the same PHP process, so a second request to
     * the same route gets the first request's cached parameters and the
     * controller receives the wrong product / order / customer id. Production
     * doesn't see this because each REST request lands in a fresh PHP process,
     * but Codeception runs many requests per process. Clear those caches via
     * reflection before each dispatch so route binding resolves fresh.
     */
    private function resetRouterParameterCache(): void
    {
        $app = App::getInstance();
        if (!$app->bound('router')) {
            return;
        }

        $router = $app->make('router');
        if (!method_exists($router, 'getRoutes')) {
            return;
        }

        foreach ((array) $router->getRoutes() as $methodGroup) {
            $routes = is_array($methodGroup) ? $methodGroup : [$methodGroup];
            foreach ($routes as $route) {
                if (!is_object($route)) {
                    continue;
                }
                $this->nullPropertyViaReflection($route, 'parameters');
                $this->resetPropertyViaReflection($route, 'substitutedParameters', []);
            }
        }
    }

    private function nullPropertyViaReflection(object $object, string $property): void
    {
        try {
            $ref = new \ReflectionClass($object);
            if (!$ref->hasProperty($property)) {
                return;
            }
            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue($object, null);
        } catch (\ReflectionException $e) {
            // intentionally swallow — best-effort cache clear for tests only
        }
    }

    private function resetPropertyViaReflection(object $object, string $property, $value): void
    {
        try {
            $ref = new \ReflectionClass($object);
            if (!$ref->hasProperty($property)) {
                return;
            }
            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue($object, $value);
        } catch (\ReflectionException $e) {
            // intentionally swallow — best-effort cache clear for tests only
        }
    }
}
