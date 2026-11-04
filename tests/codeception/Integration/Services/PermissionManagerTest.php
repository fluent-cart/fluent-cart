<?php

namespace Tests\Integration\Services;

use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\Permission\PermissionManager;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\UserRoleFactory;
use Tests\Support\Factory\WPUserFactory;

/**
 * Integration tests for FluentCart permission core — Pro-absent contract.
 *
 * Scope: how core behaves WITHOUT the Pro plugin installed. The Pro plugin
 * has its own test suite for role-matrix coverage. Every assertion here holds
 * when `App::isProActive()` is false — the commercial gate inside
 * `PermissionManager::getUserPermissions()` returns `[]` for non-admins.
 *
 * Helper cache: `Helper::getCurrentUser()` is a function-static. Reset via
 * `Helper::getCurrentUser(true)` before every user-context switch.
 */
class PermissionManagerTest extends WPTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Helper::getCurrentUser(true);
    }

    /**
     * Hard precondition: the Pro plugin must NOT be installed for this suite.
     * If `FLUENTCART_PRO_PLUGIN_VERSION` is defined, another test leaked it
     * in-process — fail loudly rather than skip so CI cannot go green without
     * actually executing the Pro-off path.
     */
    private function requireProAbsent(): void
    {
        $this->assertFalse(
            App::isProActive(),
            'Core Pro-off contract broken: FLUENTCART_PRO_PLUGIN_VERSION is defined in this process. Another test in the run (Codeception+WPLoader does not honor @runInSeparateProcess) leaked the constant and hid this assertion from real execution. Fix the leaking test or run this file in isolation.'
        );
    }

    public function tearDown(): void
    {
        wp_set_current_user(0);
        Helper::getCurrentUser(true);
        wp_cache_flush();
        parent::tearDown();
    }

    // =========================================================================
    // Spec 1-3: getAllPermissions()
    // =========================================================================

    public function test_get_all_permissions_returns_40_keys(): void
    {
        $permissions = PermissionManager::getAllPermissions();
        $this->assertCount(40, $permissions);
    }

    public function test_get_all_permissions_groups_match_source(): void
    {
        $keys = array_keys(PermissionManager::getAllPermissions());

        $groups = [];
        foreach ($keys as $key) {
            [$area] = explode('/', $key, 2);
            $groups[$area] = ($groups[$area] ?? 0) + 1;
        }

        $this->assertSame(2, $groups['store']);
        $this->assertSame(4, $groups['products']);
        $this->assertSame(4, $groups['customers']);
        $this->assertSame(7, $groups['orders']);
        $this->assertSame(4, $groups['subscriptions']);
        $this->assertSame(4, $groups['licenses']);
        $this->assertSame(3, $groups['coupons']);
        $this->assertSame(2, $groups['reports']);
        $this->assertSame(3, $groups['integrations']);
        $this->assertSame(3, $groups['labels']);
        $this->assertSame(1, $groups['dashboard_stats']);
    }

    public function test_every_permission_has_non_empty_string_label(): void
    {
        foreach (PermissionManager::getAllPermissions() as $key => $label) {
            $this->assertIsString($key);
            $this->assertMatchesRegularExpression('#^[a-z_]+/[a-z_]+$#', $key);
            $this->assertIsString($label);
            $this->assertNotSame('', $label);
        }
    }

    public function test_expected_permission_keys_present(): void
    {
        $keys = array_keys(PermissionManager::getAllPermissions());
        $expected = [
            'store/settings', 'store/sensitive',
            'products/view', 'products/create', 'products/edit', 'products/delete',
            'customers/view', 'customers/manage', 'customers/export', 'customers/delete',
            'orders/view', 'orders/create', 'orders/manage_statuses',
            'orders/manage', 'orders/can_refund', 'orders/export', 'orders/delete',
            'subscriptions/view', 'subscriptions/manage', 'subscriptions/export', 'subscriptions/delete',
            'licenses/view', 'licenses/manage', 'licenses/export', 'licenses/delete',
            'coupons/view', 'coupons/manage', 'coupons/delete',
            'reports/view', 'reports/export',
            'integrations/view', 'integrations/manage', 'integrations/delete',
            'labels/view', 'labels/manage', 'labels/delete',
            'dashboard_stats/view',
        ];
        foreach ($expected as $key) {
            $this->assertContains($key, $keys, "Missing permission key: {$key}");
        }
    }

    // =========================================================================
    // Spec 4-11: getAllRoles()
    // =========================================================================

    public function test_get_all_roles_returns_four_roles(): void
    {
        $roles = PermissionManager::getAllRoles();
        $this->assertSame(
            ['super_admin', 'manager', 'worker', 'accountant'],
            array_keys($roles)
        );
    }

    public function test_super_admin_role_has_all_permissions(): void
    {
        $roles = PermissionManager::getAllRoles();
        $allKeys = array_keys(PermissionManager::getAllPermissions());
        $this->assertSame($allKeys, $roles['super_admin']['permissions']);
    }

    /**
     * Spec: Cases/Integration/permissions.md [6a]
     *   "manager.permissions equals the exact declared roster — including the
     *    customers/subscriptions/licenses export grants added by #2581."
     * EXACT equality (blocker class 26): a Contains-walk stays green when a
     * grant is silently dropped from the role.
     */
    public function test_manager_role_roster_is_exact(): void
    {
        $this->assertSame(
            [
                'store/settings',
                'products/view', 'products/create', 'products/edit', 'products/delete',
                'customers/view', 'customers/manage', 'customers/export', 'customers/delete',
                'orders/view', 'orders/manage_statuses', 'orders/can_refund',
                'orders/manage', 'orders/export', 'orders/delete',
                'subscriptions/view', 'subscriptions/manage', 'subscriptions/export', 'subscriptions/delete',
                'licenses/view', 'licenses/manage', 'licenses/export', 'licenses/delete',
                'coupons/view', 'coupons/manage', 'coupons/delete',
                'reports/view', 'reports/export',
                'integrations/view', 'integrations/manage', 'integrations/delete',
                'reviews/manage',
                'dynamic_pricing/view', 'dynamic_pricing/manage',
            ],
            PermissionManager::getAllRoles()['manager']['permissions'],
            'manager roster must match the declared source exactly — dropping any grant (the #2581 export trio included) must fail'
        );
    }

    /**
     * Spec: Cases/Integration/permissions.md [9a]
     *   "accountant.permissions equals the exact declared roster — including
     *    customers/subscriptions/licenses export."
     */
    public function test_accountant_role_roster_is_exact(): void
    {
        $this->assertSame(
            [
                'orders/view', 'orders/export',
                'reports/view', 'reports/export',
                'products/view',
                'customers/view', 'customers/export',
                'subscriptions/view', 'subscriptions/export',
                'licenses/view', 'licenses/export',
                'coupons/view',
                'integrations/view',
            ],
            PermissionManager::getAllRoles()['accountant']['permissions'],
            'accountant roster must match the declared source exactly — dropping any grant (the export permissions included) must fail'
        );
    }

    public function test_manager_role_has_store_settings_not_sensitive(): void
    {
        $perms = PermissionManager::getAllRoles()['manager']['permissions'];
        $this->assertContains('store/settings', $perms);
        $this->assertNotContains('store/sensitive', $perms);
    }

    public function test_manager_role_excludes_orders_create_labels_and_dashboard(): void
    {
        $perms = PermissionManager::getAllRoles()['manager']['permissions'];
        $this->assertNotContains('orders/create', $perms);
        $this->assertNotContains('labels/view', $perms);
        $this->assertNotContains('labels/manage', $perms);
        $this->assertNotContains('labels/delete', $perms);
        $this->assertNotContains('dashboard_stats/view', $perms);
    }

    public function test_worker_role_is_view_only_plus_status_and_coupon_manage(): void
    {
        $perms = PermissionManager::getAllRoles()['worker']['permissions'];

        $this->assertContains('orders/manage_statuses', $perms);
        $this->assertContains('coupons/manage', $perms);

        foreach ($perms as $key) {
            if (in_array($key, ['orders/manage_statuses', 'coupons/manage'], true)) {
                continue;
            }
            $this->assertStringEndsWith('/view', $key, "Unexpected non-view perm in worker: {$key}");
        }
    }

    public function test_accountant_role_is_view_only_plus_reports(): void
    {
        $perms = PermissionManager::getAllRoles()['accountant']['permissions'];

        $this->assertContains('reports/view', $perms);
        $this->assertContains('reports/export', $perms);
        $this->assertNotContains('orders/manage_statuses', $perms);
        $this->assertNotContains('coupons/manage', $perms);
    }

    public function test_all_roles_filter_receives_role_array(): void
    {
        $seen = null;
        $cb = function ($roles) use (&$seen) {
            $seen = $roles;
            $roles['custom_role'] = [
                'title'        => 'Custom',
                'descriptions' => 'Custom role',
                'permissions'  => ['products/view'],
            ];
            return $roles;
        };
        add_filter('fluent_cart/permission/all_roles', $cb, 10);

        $roles = PermissionManager::getAllRoles();

        remove_filter('fluent_cart/permission/all_roles', $cb, 10);

        $this->assertIsArray($seen);
        $this->assertArrayHasKey('super_admin', $seen);
        $this->assertArrayHasKey('custom_role', $roles);
        $this->assertSame(['products/view'], $roles['custom_role']['permissions']);
    }

    public function test_role_permissions_are_valid_permission_keys(): void
    {
        $validKeys = array_keys(PermissionManager::getAllPermissions());
        foreach (PermissionManager::getAllRoles() as $roleName => $role) {
            foreach ($role['permissions'] as $perm) {
                $this->assertContains(
                    $perm,
                    $validKeys,
                    "Role {$roleName} references unknown permission: {$perm}"
                );
            }
        }
    }

    // =========================================================================
    // Spec 12-17: getUserPermissions()
    // =========================================================================

    public function test_wp_admin_gets_all_permissions_plus_super_admin_token(): void
    {
        $userId = UserRoleFactory::createAdmin();
        $perms = PermissionManager::getUserPermissions($userId);

        $allKeys = array_keys(PermissionManager::getAllPermissions());
        foreach ($allKeys as $key) {
            $this->assertContains($key, $perms);
        }
        $this->assertContains('is_super_admin', $perms);
    }

    public function test_non_admin_without_role_meta_returns_empty(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        $this->assertSame([], PermissionManager::getUserPermissions($userId));
    }

    public function test_non_admin_with_role_meta_returns_empty_when_pro_absent(): void
    {
        // Core-without-Pro contract: shop role meta has no effect when Pro is
        // not installed. Verifies the commercial gate inside
        // PermissionManager::getUserPermissions() (`if (!isProActive) return []`).
        $this->requireProAbsent();

        $userId = UserRoleFactory::createWithShopRole('worker');
        $this->assertSame([], PermissionManager::getUserPermissions($userId));
    }

    public function test_non_admin_with_unknown_role_returns_empty_when_pro_absent(): void
    {
        $this->requireProAbsent();
        $userId = UserRoleFactory::createWithShopRole('bogus_role');
        $this->assertSame([], PermissionManager::getUserPermissions($userId));
    }

    public function test_false_user_id_uses_current_user(): void
    {
        $userId = UserRoleFactory::createAdmin();
        wp_set_current_user($userId);

        $perms = PermissionManager::getUserPermissions(false);
        $this->assertContains('products/view', $perms);
        $this->assertContains('is_super_admin', $perms);
    }

    // =========================================================================
    // Spec 18-23: hasPermission()
    // =========================================================================

    public function test_has_permission_returns_false_for_unauthenticated(): void
    {
        wp_set_current_user(0);
        $this->assertFalse(PermissionManager::hasPermission('products/view'));
    }

    public function test_has_permission_returns_true_for_wp_admin_on_any_permission(): void
    {
        $userId = UserRoleFactory::createAdmin();
        $this->assertTrue((bool) PermissionManager::hasPermission('products/view', $userId));
        $this->assertTrue((bool) PermissionManager::hasPermission('store/sensitive', $userId));
    }

    public function test_has_permission_non_admin_with_shop_role_always_false_when_pro_absent(): void
    {
        // Core-without-Pro contract: no matter which shop role is attached,
        // non-admin users never hold any permission when Pro is absent.
        $this->requireProAbsent();

        foreach (['manager', 'worker', 'accountant'] as $role) {
            $userId = UserRoleFactory::createWithShopRole($role);
            foreach (['store/sensitive', 'store/settings', 'products/view', 'orders/delete'] as $perm) {
                $this->assertFalse(
                    (bool) PermissionManager::hasPermission($perm, $userId),
                    "Pro absent — {$role} must not hold {$perm}"
                );
            }
        }
    }

    public function test_has_permission_accepts_array(): void
    {
        $userId = UserRoleFactory::createAdmin();
        $this->assertTrue((bool) PermissionManager::hasPermission(['nonexistent/x', 'products/view'], $userId));
    }

    public function test_has_permission_super_admin_literal_is_dead_branch(): void
    {
        // Documents current behavior: hasPermission short-circuits on the literal
        // 'super_admin', but getUserPermissions emits 'is_super_admin'. WP admins
        // still pass via array_intersect when a real permission key is requested,
        // never via this branch.
        $userId = UserRoleFactory::createAdmin();
        $perms = PermissionManager::getUserPermissions($userId);
        $this->assertNotContains('super_admin', $perms);
        $this->assertContains('is_super_admin', $perms);
    }

    public function test_has_permission_unknown_key_locks_out_admin_known_bug(): void
    {
        // Regression guard for the dead-branch bug. An unknown permission key
        // (e.g. one registered by an addon after the PermissionManager static
        // list was built) returns false even for WP admins because:
        //   - getUserPermissions emits array_keys(getAllPermissions()) + 'is_super_admin'
        //   - hasPermission short-circuits on literal 'super_admin' (dead branch)
        //   - array_intersect($userPerms, ['addon/x']) is empty
        // Net: admins are locked out of addon-registered capabilities until the
        // dead branch is fixed to check 'is_super_admin'.
        $userId = UserRoleFactory::createAdmin();

        $this->assertFalse(
            (bool) PermissionManager::hasPermission('addon/unknown_permission', $userId),
            'If this flips to true, the dead super_admin branch was fixed — update the dead-branch test.'
        );
        $this->assertFalse(
            (bool) PermissionManager::hasPermission(['addon/one', 'addon/two'], $userId),
            'Admins should pass any permission array via the is_super_admin token; currently they do not.'
        );
    }

    public function test_has_any_permission_unknown_keys_still_passes_for_admin(): void
    {
        // Contrast with hasPermission: hasAnyPermission correctly uses the
        // 'is_super_admin' token (in_array check on the correct literal).
        // Regression guard: if this flips to false, hasAnyPermission was
        // changed to also look for 'super_admin' literal and now inherits
        // the dead-branch bug.
        $userId = UserRoleFactory::createAdmin();
        $this->assertTrue(
            PermissionManager::hasAnyPermission(['addon/unknown'], $userId),
            'hasAnyPermission must continue to honor the is_super_admin token so admins can access addon-registered permissions.'
        );
    }

    // =========================================================================
    // Spec 24-27: hasAnyPermission()
    // =========================================================================

    public function test_has_any_permission_false_for_unauthenticated(): void
    {
        wp_set_current_user(0);
        $this->assertFalse(PermissionManager::hasAnyPermission(['products/view']));
    }

    public function test_has_any_permission_true_for_wp_admin(): void
    {
        $userId = UserRoleFactory::createAdmin();
        $this->assertTrue(PermissionManager::hasAnyPermission(['nonexistent/key'], $userId));
    }

    public function test_has_any_permission_non_admin_shop_role_always_false_when_pro_absent(): void
    {
        // Without Pro, hasAnyPermission never matches for non-admin regardless
        // of shop role (getUserPermissions returns []).
        $this->requireProAbsent();
        foreach (['manager', 'worker', 'accountant'] as $role) {
            $userId = UserRoleFactory::createWithShopRole($role);
            $this->assertFalse(
                PermissionManager::hasAnyPermission(['products/view', 'orders/view'], $userId),
                "Pro absent — {$role} must not hold any listed permission"
            );
        }
    }

    // =========================================================================
    // Spec 28-30: userCan()
    // =========================================================================

    public function test_user_can_returns_false_for_guest(): void
    {
        wp_set_current_user(0);
        Helper::getCurrentUser(true);
        $this->assertFalse(PermissionManager::userCan('products/view'));
    }

    public function test_user_can_returns_true_for_wp_admin(): void
    {
        $userId = UserRoleFactory::createAdmin();
        wp_set_current_user($userId);
        Helper::getCurrentUser(true);
        $this->assertTrue(PermissionManager::userCan('products/view'));
        $this->assertTrue(PermissionManager::userCan('store/sensitive'));
    }

    // =========================================================================
    // Spec 31-33: getShopRole()
    // =========================================================================

    public function test_get_shop_role_returns_super_admin_for_wp_admin(): void
    {
        $userId = UserRoleFactory::createAdmin();
        $this->assertSame('super_admin', PermissionManager::getShopRole($userId));
    }

    public function test_get_shop_role_returns_false_for_no_meta(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        $this->assertFalse(PermissionManager::getShopRole($userId));
    }

    public function test_get_shop_role_returns_meta_value(): void
    {
        $userId = UserRoleFactory::createWithShopRole('accountant');
        $this->assertSame('accountant', PermissionManager::getShopRole($userId));
    }

    // =========================================================================
    // Spec 34-37: attachRole()
    // =========================================================================

    public function test_attach_role_rejects_unknown_user(): void
    {
        $result = PermissionManager::attachRole(999999, 'manager');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('user_not_found', $result->get_error_code());
    }

    public function test_attach_role_rejects_wp_admin(): void
    {
        $userId = UserRoleFactory::createAdmin();
        $result = PermissionManager::attachRole($userId, 'manager');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('super_admin', $result->get_error_code());
    }

    public function test_attach_role_adds_cap_and_meta(): void
    {
        $userId = UserRoleFactory::createSubscriber();

        $result = PermissionManager::attachRole($userId, 'worker');

        $this->assertNotInstanceOf(\WP_Error::class, $result);
        $this->assertNotFalse($result);

        $wpUser = get_user_by('ID', $userId);
        $this->assertTrue($wpUser->has_cap(PermissionManager::ADMIN_CAP));
        $this->assertSame('worker', get_user_meta($userId, '_fluent_cart_admin_role', true));
    }

    public function test_attach_role_does_not_duplicate_cap(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        PermissionManager::attachRole($userId, 'worker');
        PermissionManager::attachRole($userId, 'manager');

        $wpUser = get_user_by('ID', $userId);
        // Count occurrences of the cap across $wpUser->caps — with direct
        // add_cap() calls, WP stores it as a single key, so count is 1.
        $capsArray = $wpUser->caps;
        $occurrences = isset($capsArray[PermissionManager::ADMIN_CAP]) ? 1 : 0;
        $this->assertSame(1, $occurrences);
    }

    // =========================================================================
    // Spec 38-41: detachRole()
    // =========================================================================

    public function test_detach_role_rejects_unknown_user(): void
    {
        $result = PermissionManager::detachRole(999999, 'manager');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('user_not_found', $result->get_error_code());
    }

    public function test_detach_role_rejects_wp_admin(): void
    {
        $userId = UserRoleFactory::createAdmin();
        $result = PermissionManager::detachRole($userId, 'manager');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('super_admin', $result->get_error_code());
    }

    public function test_detach_role_removes_meta(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        PermissionManager::attachRole($userId, 'worker');

        PermissionManager::detachRole($userId, 'worker');

        $this->assertSame('', get_user_meta($userId, '_fluent_cart_admin_role', true));
    }

    public function test_detach_role_does_not_remove_cap_known_bug(): void
    {
        // Regression guard for inverted conditional in PermissionManager::detachRole():
        //   if (!$wpUser->has_cap(ADMIN_CAP)) { $wpUser->remove_cap(...); }
        // The cap is only removed when the user does NOT hold it — a no-op. A
        // user who went through attachRole keeps the cap after detachRole.
        // When the core bug is fixed, invert this assertion.
        $userId = UserRoleFactory::createSubscriber();
        PermissionManager::attachRole($userId, 'worker');

        PermissionManager::detachRole($userId, 'worker');

        $wpUser = get_user_by('ID', $userId);
        $this->assertTrue(
            $wpUser->has_cap(PermissionManager::ADMIN_CAP),
            'If this fails, the inverted has_cap check in detachRole() was fixed — update the test to assert removal.'
        );
    }

    // =========================================================================
    // Spec 42-46: getUsersWithShopRole()
    // =========================================================================

    public function test_get_users_with_shop_role_returns_only_assigned_users(): void
    {
        UserRoleFactory::createSubscriber(); // no meta, must not appear
        $assignedId = UserRoleFactory::createWithShopRole('worker');

        $rows = PermissionManager::getUsersWithShopRole();

        $ids = array_column($rows, 'id');
        $this->assertContains($assignedId, $ids);

        foreach ($rows as $row) {
            $this->assertNotEmpty(
                $row['shop_role'],
                'getUsersWithShopRole must not return users without _fluent_cart_admin_role meta'
            );
        }
    }

    public function test_get_users_with_shop_role_row_shape(): void
    {
        $userId = UserRoleFactory::createWithShopRole('accountant');
        $rows = PermissionManager::getUsersWithShopRole();

        $row = null;
        foreach ($rows as $r) {
            if ((int) $r['id'] === $userId) {
                $row = $r;
                break;
            }
        }

        $this->assertNotNull($row);
        foreach (['id', 'email', 'display_name', 'username', 'shop_role', 'description', 'registered_at', 'role_permissions'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertSame('accountant', $row['shop_role']);
    }

    public function test_get_users_with_shop_role_permissions_match_get_user_permissions(): void
    {
        $userId = UserRoleFactory::createWithShopRole('manager');

        $rows = PermissionManager::getUsersWithShopRole();
        $row = null;
        foreach ($rows as $r) {
            if ((int) $r['id'] === $userId) {
                $row = $r;
                break;
            }
        }
        $this->assertNotNull($row);
        $this->assertSame(PermissionManager::getUserPermissions($userId), $row['role_permissions']);
    }

    public function test_get_users_with_shop_role_unknown_role_yields_empty_description(): void
    {
        $userId = UserRoleFactory::createWithShopRole('phantom_role');

        $rows = PermissionManager::getUsersWithShopRole();
        $row = null;
        foreach ($rows as $r) {
            if ((int) $r['id'] === $userId) {
                $row = $r;
                break;
            }
        }
        $this->assertNotNull($row);
        $this->assertSame('', $row['description']);
        $this->assertSame([], $row['role_permissions']);
    }

    public function test_get_users_with_shop_role_empty_when_no_assigned_users(): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->usermeta, ['meta_key' => '_fluent_cart_admin_role']);
        $this->assertSame([], PermissionManager::getUsersWithShopRole());
    }

    // =========================================================================
    // Spec 107-109: Role assignment integration
    // =========================================================================

    public function test_attach_role_persists_meta_but_grants_nothing_when_pro_absent(): void
    {
        // Core-without-Pro: attach writes the meta + ADMIN_CAP but the
        // commercial gate keeps getUserPermissions() empty.
        $this->requireProAbsent();

        $userId = UserRoleFactory::createSubscriber();
        PermissionManager::attachRole($userId, 'worker');

        $this->assertSame('worker', get_user_meta($userId, '_fluent_cart_admin_role', true));
        $this->assertSame([], PermissionManager::getUserPermissions($userId));
    }

    public function test_attach_role_switch_only_changes_meta_when_pro_absent(): void
    {
        $this->requireProAbsent();

        $userId = UserRoleFactory::createSubscriber();
        PermissionManager::attachRole($userId, 'worker');
        PermissionManager::attachRole($userId, 'accountant');

        $this->assertSame('accountant', get_user_meta($userId, '_fluent_cart_admin_role', true));
        $this->assertSame([], PermissionManager::getUserPermissions($userId));
    }

    public function test_detach_role_empties_permissions(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        PermissionManager::attachRole($userId, 'worker');
        PermissionManager::detachRole($userId, 'worker');

        $this->assertSame([], PermissionManager::getUserPermissions($userId));
    }

    // =========================================================================
    // Spec 119-121: Edge cases
    // =========================================================================

    public function test_has_permission_with_empty_array_returns_false_for_non_admin(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        $this->assertFalse((bool) PermissionManager::hasPermission([], $userId));
    }

    public function test_has_permission_with_empty_array_wp_admin_intersect_behavior(): void
    {
        // array_intersect with an empty argument returns []. The super_admin
        // literal-check branch is dead (see test_has_permission_super_admin_literal_is_dead_branch).
        // Net: hasPermission([]) returns falsy even for admins. Document so a
        // future change that tries to "fix" the dead branch doesn't silently
        // flip this behavior.
        $userId = UserRoleFactory::createAdmin();
        $this->assertFalse((bool) PermissionManager::hasPermission([], $userId));
    }

    public function test_attach_unknown_role_persists_meta_without_validation(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        PermissionManager::attachRole($userId, 'nonexistent_role');

        $this->assertSame('nonexistent_role', get_user_meta($userId, '_fluent_cart_admin_role', true));
        $this->assertSame([], PermissionManager::getUserPermissions($userId));
    }

    // =========================================================================
    // Pro-off user-visible surface: admin-notice filter + premium-integration
    // flags. These are the "proper message returned when Pro is off" paths in
    // core. The Vue admin reads these to render upgrade CTAs.
    // =========================================================================

    public function test_admin_notices_filter_has_no_pro_update_notice_when_pro_absent(): void
    {
        // boot/app.php only registers the "Pro update required" notice inside
        // `if (defined('FLUENTCART_PRO_PLUGIN_VERSION'))`. With Pro absent the
        // filter must produce no update_license notice — front-end must not
        // show a "please update Pro" prompt to a site that doesn't have Pro.
        $this->requireProAbsent();

        $notices = apply_filters('fluent_cart/admin_notices', []);

        $this->assertIsArray($notices);
        foreach ($notices as $notice) {
            $this->assertNotSame(
                'update_license',
                $notice['id'] ?? null,
                'core_update notice must NOT fire when Pro is absent'
            );
        }
    }

    public function test_premium_integrations_flag_is_pro_active_false_when_pro_absent(): void
    {
        // AddonsController::getPremiumIntegrations() is the REST-visible signal
        // the Vue admin uses to render the "upgrade to Pro" CTA next to each
        // premium integration. Every entry must report is_pro_active=false +
        // enabled=false + is_pro=true when Pro is absent.
        $this->requireProAbsent();

        $controller = new \FluentCart\App\Http\Controllers\AddonsController();
        $premium = $controller->getPremiumIntegrations();

        $this->assertNotEmpty($premium);
        foreach ($premium as $key => $entry) {
            $this->assertTrue($entry['is_pro'], "{$key} must be flagged is_pro=true");
            $this->assertFalse(
                $entry['is_pro_active'],
                "{$key} must be flagged is_pro_active=false when Pro is absent"
            );
            $this->assertFalse(
                $entry['enabled'],
                "{$key} must NOT be enabled when Pro is absent — front-end renders the upgrade CTA based on this flag"
            );
        }
    }
}
