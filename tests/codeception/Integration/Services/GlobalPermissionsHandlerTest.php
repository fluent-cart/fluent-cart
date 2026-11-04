<?php

namespace Tests\Integration\Services;

use FluentCart\App\Hooks\Handlers\GlobalPermissionsHandler;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Covers the `fluent_cart_register_permission` filter surface exposed by
 * GlobalPermissionsHandler. Addons register module-scoped capabilities through
 * this filter; the handler writes them into the static $customCapabilities map.
 */
class GlobalPermissionsHandlerTest extends WPTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        GlobalPermissionsHandler::$customCapabilities = [];
    }

    public function tearDown(): void
    {
        GlobalPermissionsHandler::$customCapabilities = [];
        remove_all_filters('fluent_cart_register_permission');
        parent::tearDown();
    }

    // =========================================================================
    // Spec 116: register() attaches the filter
    // =========================================================================

    public function test_register_hooks_fluent_cart_register_permission_filter(): void
    {
        global $wp_filter;

        $handler = new GlobalPermissionsHandler();
        $handler->register();

        $this->assertTrue(isset($wp_filter['fluent_cart_register_permission']));
        $hook = $wp_filter['fluent_cart_register_permission'];

        $this->assertArrayHasKey(10, $hook->callbacks, 'Handler must register at priority 10');

        $registered = $hook->callbacks[10];
        $this->assertCount(1, $registered, 'Expected exactly one callback at priority 10');

        $entry = array_values($registered)[0];
        $this->assertSame(
            2,
            (int) $entry['accepted_args'],
            'Handler must accept 2 args ($moduleName, $permissions) — wrong arity silently drops the permissions array'
        );
        $this->assertIsCallable($entry['function']);
    }

    // =========================================================================
    // Spec 117: filter populates custom capability map
    // =========================================================================

    public function test_filter_populates_custom_capabilities(): void
    {
        (new GlobalPermissionsHandler())->register();

        apply_filters('fluent_cart_register_permission', 'billing', ['view', 'manage']);

        $caps = GlobalPermissionsHandler::$customCapabilities;

        $this->assertArrayHasKey('fluent_cart/permissions/billing/view', $caps);
        $this->assertArrayHasKey('fluent_cart/permissions/billing/manage', $caps);
        $this->assertFalse($caps['fluent_cart/permissions/billing/view']);
        $this->assertFalse($caps['fluent_cart/permissions/billing/manage']);
    }

    // =========================================================================
    // Spec 118: Known bug — subsequent apply_filters wipes previous module caps.
    //
    // Current `GlobalPermissionsHandler::register()` uses `=`, not merge, on the
    // static `$customCapabilities` map. Two addons cannot both register module
    // permissions; the second call erases the first. This test PINS the buggy
    // behavior as a regression guard. When core is fixed to merge:
    //   - flip assertion to assertCount(2, $caps)
    //   - assert 'fluent_cart/permissions/billing/view' is STILL present
    //   - delete this note and rename the test
    // =========================================================================

    public function test_filter_overwrites_previous_capabilities_known_bug(): void
    {
        (new GlobalPermissionsHandler())->register();

        apply_filters('fluent_cart_register_permission', 'billing', ['view']);
        $this->assertArrayHasKey('fluent_cart/permissions/billing/view', GlobalPermissionsHandler::$customCapabilities);

        apply_filters('fluent_cart_register_permission', 'shipping', ['manage']);

        $caps = GlobalPermissionsHandler::$customCapabilities;
        $this->assertArrayNotHasKey(
            'fluent_cart/permissions/billing/view',
            $caps,
            'KNOWN BUG guard: second addon registration erases first. If this flips to true, the merge bug is fixed — update the test to assert BOTH billing/view AND shipping/manage survive.'
        );
        $this->assertArrayHasKey('fluent_cart/permissions/shipping/manage', $caps);
        $this->assertCount(
            1,
            $caps,
            'KNOWN BUG: two addons cannot accumulate capabilities. Fix GlobalPermissionsHandler::register() to array_merge then expect count == 2.'
        );
    }
}
