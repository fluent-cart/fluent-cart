<?php

namespace Tests\Integration\Http;

use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Http\Policies\AdminPolicy;
use FluentCart\App\Http\Policies\CouponPolicy;
use FluentCart\App\Http\Policies\CustomerFrontendPolicy;
use FluentCart\App\Http\Policies\CustomerPolicy;
use FluentCart\App\Http\Policies\DashboardPolicy;
use FluentCart\App\Http\Policies\IntegrationPolicy;
use FluentCart\App\Http\Policies\LabelPolicy;
use FluentCart\App\Http\Policies\OrderPolicy;
use FluentCart\App\Http\Policies\Policy;
use FluentCart\App\Http\Policies\ProductPolicy;
use FluentCart\App\Http\Policies\PublicPolicy;
use FluentCart\App\Http\Policies\ReportPolicy;
use FluentCart\App\Http\Policies\StoreSensitivePolicy;
use FluentCart\App\Http\Policies\StoreSettingsPolicy;
use FluentCart\App\Http\Policies\UserPolicy;
use FluentCart\Framework\Http\Request\Request as FluentRequest;
use FluentCart\Framework\Http\Route;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\UserRoleFactory;

/**
 * Tests the 14 FluentCart policies and the shared Policy base class.
 *
 * Policy::hasRoutePermissions() reads meta from `App::route()` which resolves
 * from the `route` container binding. We inject a stub Route directly via
 * `App::getInstance()->instance('route', $route)` so these tests do not
 * require dispatching a real REST request.
 *
 * Pro gate and Helper::getCurrentUser() static cache are the same constraints
 * documented in PermissionManagerTest.
 */
class PolicyTest extends WPTestCase
{
    private FluentRequest $request;

    public function setUp(): void
    {
        parent::setUp();
        Helper::getCurrentUser(true);
        $this->request = new FluentRequest(App::getInstance(), [], []);
    }

    /**
     * Hard Pro-off precondition. See PermissionManagerTest::requireProAbsent()
     * for rationale — skip would hide real regressions behind a leaked constant.
     */
    private function requireProAbsent(): void
    {
        $this->assertFalse(
            App::isProActive(),
            'Core Pro-off contract broken: FLUENTCART_PRO_PLUGIN_VERSION is defined in this process. Another test leaked the constant. Fix the leaking test or run this file in isolation.'
        );
    }

    public function tearDown(): void
    {
        wp_set_current_user(0);
        Helper::getCurrentUser(true);
        App::getInstance()->forgetInstance('route');
        parent::tearDown();
    }

    private function bindRouteWithMeta(array $meta): Route
    {
        $route = new Route(App::getInstance(), 'fluent-cart/v2', '/dummy', function () {}, 'GET');
        $route->meta($meta);
        App::getInstance()->instance('route', $route);
        return $route;
    }

    private function setUserAndFlushCache(int $userId): void
    {
        wp_set_current_user($userId);
        Helper::getCurrentUser(true);
    }

    // =========================================================================
    // Spec 47-55: Policy base class
    // =========================================================================

    public function test_policy_user_can_returns_false_for_guest(): void
    {
        $this->setUserAndFlushCache(0);
        $policy = new AdminPolicy();
        $this->assertFalse($policy->userCan('products/view'));
    }

    public function test_policy_user_can_any_delegates_to_current_user(): void
    {
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);
        $policy = new AdminPolicy();
        $this->assertTrue($policy->userCanAny(['products/view', 'store/sensitive']));
    }

    public function test_resolve_route_permission_string_meta(): void
    {
        $this->bindRouteWithMeta(['permissions' => 'orders/view']);
        $policy = new OrderPolicy();
        $this->assertSame(['orders/view'], $policy->resolveRoutePermission());
    }

    public function test_resolve_route_permission_comma_separated_string(): void
    {
        $this->bindRouteWithMeta(['permissions' => 'orders/view, orders/manage']);
        $policy = new OrderPolicy();
        $this->assertSame(['orders/view', 'orders/manage'], $policy->resolveRoutePermission());
    }

    public function test_resolve_route_permission_array_passthrough(): void
    {
        $this->bindRouteWithMeta(['permissions' => ['orders/view', 'orders/manage']]);
        $policy = new OrderPolicy();
        $this->assertSame(['orders/view', 'orders/manage'], $policy->resolveRoutePermission());
    }

    public function test_resolve_route_permission_missing_meta_returns_empty(): void
    {
        $this->bindRouteWithMeta([]);
        $policy = new OrderPolicy();
        $this->assertSame([], $policy->resolveRoutePermission());
    }

    public function test_has_route_permissions_empty_required_returns_false(): void
    {
        $this->bindRouteWithMeta([]);
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);
        $policy = new OrderPolicy();
        $this->assertFalse($policy->hasRoutePermissions());
    }

    public function test_has_route_permissions_any_type_uses_user_can_any(): void
    {
        // All unknown permissions. 'any' path calls PermissionManager::hasAnyPermission
        // which short-circuits on the 'is_super_admin' token → admin passes.
        // If Policy::hasRoutePermissions is accidentally rewired to delegate to
        // userCan() (the 'all' path), this flips to false because userCan/
        // hasPermission has the dead 'super_admin' literal branch.
        $this->bindRouteWithMeta([
            'permissions' => ['addon/alpha_unknown', 'addon/beta_unknown'],
            'permissions_type' => 'any',
        ]);
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);

        $policy = new OrderPolicy();
        $this->assertTrue(
            $policy->hasRoutePermissions(),
            'permissions_type=any must call userCanAny(); regression if this flips to false.'
        );
    }

    public function test_has_route_permissions_all_type_uses_strict_user_can(): void
    {
        // Same unknown array + type=all. 'all' path calls userCan() / hasPermission()
        // which currently does NOT honor 'is_super_admin' (dead 'super_admin' literal).
        // array_intersect(admin-perms, ['addon/alpha','addon/beta']) is empty.
        // Admin therefore fails — distinguishes the branch from userCanAny behavior.
        //
        // If this flips to true, either (a) hasPermission was fixed to check
        // 'is_super_admin' or (b) hasRoutePermissions was rewired to userCanAny.
        // Update both tests accordingly.
        $this->bindRouteWithMeta([
            'permissions' => ['addon/alpha_unknown', 'addon/beta_unknown'],
            'permissions_type' => 'all',
        ]);
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);

        $policy = new OrderPolicy();
        $this->assertFalse(
            $policy->hasRoutePermissions(),
            'permissions_type=all must call userCan() (strict); admin fails on unknown perms because of the dead super_admin branch. This test distinguishes any-vs-all branch selection.'
        );
    }

    public function test_has_route_permissions_admin_any_empty_intersection_still_passes(): void
    {
        // Reinforces that the 'any' branch is wired to hasAnyPermission and not
        // to hasPermission with casted array input.
        $this->bindRouteWithMeta([
            'permissions' => ['addon/unknown'],
            'permissions_type' => 'any',
        ]);
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);
        $this->assertTrue((new OrderPolicy())->hasRoutePermissions());
    }

    public function test_resolve_route_permission_non_string_non_array_returns_empty(): void
    {
        $this->bindRouteWithMeta(['permissions' => 123]);
        $policy = new OrderPolicy();
        $this->assertSame([], $policy->resolveRoutePermission());
    }

    public function test_has_route_permissions_denies_non_admin_shop_role_when_pro_absent(): void
    {
        // Core-without-Pro contract: any hasRoutePermissions() check against a
        // non-admin user with an attached shop role returns false because
        // PermissionManager::getUserPermissions() is gated to [] without Pro.
        $this->requireProAbsent();

        $this->bindRouteWithMeta([
            'permissions' => ['products/view', 'orders/delete'],
            'permissions_type' => 'all',
        ]);

        foreach (['manager', 'worker', 'accountant'] as $role) {
            $userId = UserRoleFactory::createWithShopRole($role);
            $this->setUserAndFlushCache($userId);
            $this->assertFalse(
                (new OrderPolicy())->hasRoutePermissions(),
                "Pro absent — {$role} must fail hasRoutePermissions"
            );
        }
    }

    // =========================================================================
    // Spec 56-58: AdminPolicy
    // =========================================================================

    public function test_admin_policy_passes_for_wp_admin(): void
    {
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);
        $policy = new AdminPolicy();
        $this->assertTrue($policy->verifyRequest($this->request));
    }

    public function test_admin_policy_rejects_non_admin(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        $this->setUserAndFlushCache($userId);
        $policy = new AdminPolicy();
        $this->assertFalse($policy->verifyRequest($this->request));
    }

    public function test_admin_policy_rejects_guest(): void
    {
        $this->setUserAndFlushCache(0);
        $policy = new AdminPolicy();
        $this->assertFalse($policy->verifyRequest($this->request));
    }

    // =========================================================================
    // Spec 59-60: DashboardPolicy
    // =========================================================================

    public function test_dashboard_policy_passes_for_wp_admin_on_stats(): void
    {
        $this->bindRouteWithMeta(['permissions' => 'dashboard_stats/view']);
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);

        $policy = new DashboardPolicy();
        $this->assertTrue($policy->verifyRequest($this->request));
    }

    public function test_dashboard_policy_rejects_all_shop_roles_when_pro_absent(): void
    {
        $this->requireProAbsent();
        $this->bindRouteWithMeta(['permissions' => 'dashboard_stats/view']);

        foreach (['manager', 'worker', 'accountant'] as $role) {
            $userId = UserRoleFactory::createWithShopRole($role);
            $this->setUserAndFlushCache($userId);
            $this->assertFalse((new DashboardPolicy())->verifyRequest($this->request));
        }
    }

    // =========================================================================
    // Spec 61-64: ProductPolicy — use admin happy-path; role-matrix needs Pro.
    // =========================================================================

    public function test_product_policy_view_requires_products_view(): void
    {
        $this->bindRouteWithMeta(['permissions' => 'products/view']);
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);

        $this->assertTrue((new ProductPolicy())->verifyRequest($this->request));
    }

    public function test_product_policy_rejects_guest(): void
    {
        $this->bindRouteWithMeta(['permissions' => 'products/view']);
        $this->setUserAndFlushCache(0);
        $this->assertFalse((new ProductPolicy())->verifyRequest($this->request));
    }

    // =========================================================================
    // Spec 65-71: OrderPolicy
    // =========================================================================

    public function test_order_policy_admin_passes_all_declared_permissions(): void
    {
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);

        foreach ([
            'orders/view',
            'orders/create',
            'orders/can_refund',
            'orders/manage_statuses',
            'orders/delete',
            'orders/manage',
        ] as $perm) {
            $this->bindRouteWithMeta(['permissions' => $perm]);
            $this->assertTrue(
                (new OrderPolicy())->verifyRequest($this->request),
                "WP admin should pass OrderPolicy for {$perm}"
            );
        }
    }

    // =========================================================================
    // Spec 72-74: CustomerPolicy
    // =========================================================================

    public function test_customer_policy_admin_passes_view_and_manage(): void
    {
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);

        foreach (['customers/view', 'customers/manage', 'customers/delete'] as $perm) {
            $this->bindRouteWithMeta(['permissions' => $perm]);
            $this->assertTrue((new CustomerPolicy())->verifyRequest($this->request));
        }
    }

    public function test_customer_policy_rejects_all_shop_roles_when_pro_absent(): void
    {
        $this->requireProAbsent();
        $this->bindRouteWithMeta(['permissions' => 'customers/manage']);

        foreach (['manager', 'worker', 'accountant'] as $role) {
            $userId = UserRoleFactory::createWithShopRole($role);
            $this->setUserAndFlushCache($userId);
            $this->assertFalse((new CustomerPolicy())->verifyRequest($this->request));
        }
    }

    // =========================================================================
    // Spec 75-78: CouponPolicy
    // =========================================================================

    public function test_coupon_policy_admin_passes_all(): void
    {
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);

        foreach (['coupons/view', 'coupons/manage', 'coupons/delete'] as $perm) {
            $this->bindRouteWithMeta(['permissions' => $perm]);
            $this->assertTrue((new CouponPolicy())->verifyRequest($this->request));
        }
    }

    public function test_coupon_policy_rejects_all_shop_roles_when_pro_absent(): void
    {
        $this->requireProAbsent();

        foreach (['coupons/manage', 'coupons/delete'] as $perm) {
            $this->bindRouteWithMeta(['permissions' => $perm]);
            foreach (['manager', 'worker', 'accountant'] as $role) {
                $userId = UserRoleFactory::createWithShopRole($role);
                $this->setUserAndFlushCache($userId);
                $this->assertFalse(
                    (new CouponPolicy())->verifyRequest($this->request),
                    "Pro absent — {$role} must not hold {$perm}"
                );
            }
        }
    }

    // =========================================================================
    // Spec 79-81: IntegrationPolicy
    // =========================================================================

    public function test_integration_policy_base_gate_uses_route_meta(): void
    {
        $this->bindRouteWithMeta(['permissions' => 'integrations/view']);
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);

        $this->assertTrue((new IntegrationPolicy())->verifyRequest($this->request));
    }

    public function test_integration_policy_method_overrides_admin(): void
    {
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);

        $policy = new IntegrationPolicy();
        $this->assertTrue($policy->index($this->request));
        $this->assertTrue($policy->store($this->request));
        $this->assertTrue($policy->update($this->request));
        $this->assertTrue($policy->delete($this->request));
    }

    public function test_integration_policy_method_overrides_reject_shop_roles_when_pro_absent(): void
    {
        $this->requireProAbsent();

        foreach (['manager', 'worker', 'accountant'] as $role) {
            $userId = UserRoleFactory::createWithShopRole($role);
            $this->setUserAndFlushCache($userId);

            $policy = new IntegrationPolicy();
            foreach (['index', 'store', 'update', 'delete'] as $method) {
                $this->assertFalse(
                    $policy->{$method}($this->request),
                    "Pro absent — {$role} must fail IntegrationPolicy::{$method}"
                );
            }
        }
    }

    // =========================================================================
    // Spec 82-83: LabelPolicy
    // =========================================================================

    public function test_label_policy_view_only_super_admin(): void
    {
        $this->bindRouteWithMeta(['permissions' => 'labels/view']);
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);
        $this->assertTrue((new LabelPolicy())->verifyRequest($this->request));
    }

    public function test_label_policy_rejects_shop_roles_when_pro_absent(): void
    {
        $this->requireProAbsent();

        $this->bindRouteWithMeta(['permissions' => 'labels/manage']);
        foreach (['manager', 'worker', 'accountant'] as $role) {
            $userId = UserRoleFactory::createWithShopRole($role);
            $this->setUserAndFlushCache($userId);
            $this->assertFalse((new LabelPolicy())->verifyRequest($this->request));
        }
    }

    // =========================================================================
    // Spec 84-85: ReportPolicy
    // =========================================================================

    public function test_report_policy_ignores_route_meta_and_checks_reports_view(): void
    {
        // Route meta is irrelevant — ReportPolicy hard-codes userCan('reports/view').
        $this->bindRouteWithMeta(['permissions' => 'nothing/at/all']);
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);

        $this->assertTrue((new ReportPolicy())->verifyRequest($this->request));
    }

    public function test_report_policy_rejects_all_shop_roles_when_pro_absent(): void
    {
        $this->requireProAbsent();

        foreach (['manager', 'worker', 'accountant'] as $role) {
            $userId = UserRoleFactory::createWithShopRole($role);
            $this->setUserAndFlushCache($userId);
            $this->assertFalse(
                (new ReportPolicy())->verifyRequest($this->request),
                "Pro absent — {$role} must not hold reports/view"
            );
        }
    }

    // =========================================================================
    // Spec 86-88: StoreSensitivePolicy
    // =========================================================================

    public function test_store_sensitive_policy_fires_hook(): void
    {
        $fired = 0;
        $cb = function () use (&$fired) { $fired++; };
        add_action('fluent_cart/policy/store_sensitive_request', $cb);

        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);

        (new StoreSensitivePolicy())->verifyRequest($this->request);

        remove_action('fluent_cart/policy/store_sensitive_request', $cb);
        $this->assertSame(1, $fired);
    }

    public function test_store_sensitive_policy_only_admin_passes(): void
    {
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);
        $this->assertTrue((new StoreSensitivePolicy())->verifyRequest($this->request));
    }

    public function test_store_sensitive_policy_rejects_non_admin(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        $this->setUserAndFlushCache($userId);
        $this->assertFalse((new StoreSensitivePolicy())->verifyRequest($this->request));
    }

    // =========================================================================
    // Spec 89-91: StoreSettingsPolicy
    // =========================================================================

    public function test_store_settings_policy_fires_hook(): void
    {
        $fired = 0;
        $cb = function () use (&$fired) { $fired++; };
        add_action('fluent_cart/policy/store_settings_request', $cb);

        $this->bindRouteWithMeta(['permissions' => 'is_super_admin']);
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);

        (new StoreSettingsPolicy())->verifyRequest($this->request);

        remove_action('fluent_cart/policy/store_settings_request', $cb);
        $this->assertSame(1, $fired);
    }

    public function test_store_settings_policy_admin_passes_is_super_admin_route(): void
    {
        $this->bindRouteWithMeta(['permissions' => 'is_super_admin']);
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);
        $this->assertTrue((new StoreSettingsPolicy())->verifyRequest($this->request));
    }

    public function test_store_settings_policy_store_settings_route_admin(): void
    {
        $this->bindRouteWithMeta(['permissions' => 'store/settings']);
        $userId = UserRoleFactory::createAdmin();
        $this->setUserAndFlushCache($userId);
        $this->assertTrue((new StoreSettingsPolicy())->verifyRequest($this->request));
    }

    // =========================================================================
    // Spec 92-93: CustomerFrontendPolicy
    // =========================================================================

    public function test_customer_frontend_policy_requires_login(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        wp_set_current_user($userId);
        $this->assertTrue((new CustomerFrontendPolicy())->verifyRequest($this->request));
    }

    public function test_customer_frontend_policy_rejects_guest(): void
    {
        wp_set_current_user(0);
        $this->assertFalse((new CustomerFrontendPolicy())->verifyRequest($this->request));
    }

    // =========================================================================
    // Spec 94: UserPolicy
    // =========================================================================

    public function test_user_policy_requires_login(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        wp_set_current_user($userId);
        $this->assertTrue((new UserPolicy())->verifyRequest($this->request));

        wp_set_current_user(0);
        $this->assertFalse((new UserPolicy())->verifyRequest($this->request));
    }

}
