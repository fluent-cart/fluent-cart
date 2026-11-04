<?php

namespace Tests\Integration\Http;

use FluentCart\App\App;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\UserRoleFactory;
use Tests\Support\RestTestCase;
use Tests\Support\Traits\AssertsRouteRegistration;

/**
 * Route-meta + end-to-end permission verification.
 *
 * Uses Router::getRoutes() + Reflection (via AssertsRouteRegistration) to
 * assert that specific (method, uri) tuples are bound to the expected policy
 * + permission meta. String greps against api.php are avoided — they pass as
 * long as a fragment exists anywhere in the file, not on the specific route
 * being tested.
 */
class PermissionRouteTest extends RestTestCase
{
    use AssertsRouteRegistration;

    // =========================================================================
    // Route-meta binding (per-endpoint, not file-wide grep)
    // =========================================================================

    public function test_products_index_requires_products_view_with_product_policy(): void
    {
        $this->assertRouteHasPolicyAndPermissions('GET', '/products', 'ProductPolicy', 'products/view');
    }

    public function test_products_create_requires_products_create(): void
    {
        $this->assertRouteHasPolicyAndPermissions('POST', '/products', 'ProductPolicy', 'products/create');
    }

    public function test_products_delete_requires_products_delete(): void
    {
        $this->assertRouteHasPolicyAndPermissions('DELETE', '/products/{product}', 'ProductPolicy', 'products/delete');
    }

    public function test_orders_index_requires_orders_view_with_order_policy(): void
    {
        $this->assertRouteHasPolicyAndPermissions('GET', '/orders', 'OrderPolicy', 'orders/view');
    }

    public function test_orders_create_requires_orders_create(): void
    {
        $this->assertRouteHasPolicyAndPermissions('POST', '/orders', 'OrderPolicy', 'orders/create');
    }

    public function test_orders_refund_requires_can_refund(): void
    {
        $this->assertRouteHasPolicyAndPermissions('POST', '/orders/{order_id}/refund', 'OrderPolicy', 'orders/can_refund');
    }

    public function test_orders_status_update_requires_manage_statuses(): void
    {
        $this->assertRouteHasPolicyAndPermissions('PUT', '/orders/{order}/statuses', 'OrderPolicy', 'orders/manage_statuses');
    }

    /**
     * Regression: this route used to sit under AdminPolicy, which gates on
     * userCan('is_super_admin') — a check only a WP `manage_options` user can
     * ever satisfy. A user holding FluentCart's own "Super Admin" shop role
     * (assigned via PermissionManager::attachRole(), no `manage_options`)
     * was denied. OrderPolicy + orders/manage is granted to that role because
     * getAllRoles()['super_admin']['permissions'] includes every key from
     * PermissionManager::getAllPermissions(), orders/manage among them.
     */
    public function test_notes_attach_requires_orders_manage_with_order_policy(): void
    {
        $this->assertRouteHasPolicyAndPermissions('POST', '/notes/attach', 'OrderPolicy', 'orders/manage');
    }

    public function test_customers_create_requires_customers_manage(): void
    {
        $this->assertRouteHasPolicyAndPermissions('POST', '/customers', 'CustomerPolicy', 'customers/manage');
    }

    public function test_coupons_delete_requires_coupons_delete(): void
    {
        $this->assertRouteHasPolicyAndPermissions('DELETE', '/coupons/{id}', 'CouponPolicy', 'coupons/delete');
    }

    public function test_dashboard_stats_requires_dashboard_stats_view(): void
    {
        $this->assertRouteHasPolicyAndPermissions('GET', '/dashboard/stats', 'DashboardPolicy', 'dashboard_stats/view');
    }

    public function test_widgets_uses_order_policy_with_any_permissions_type(): void
    {
        $this->assertRouteHasPolicyAndPermissions(
            'GET',
            '/widgets',
            'OrderPolicy',
            ['customers/view', 'orders/view'],
            'any'
        );
    }

    // The shipment form reads this list, so whoever may create a shipment must be able to read it.
    public function test_carrier_options_requires_the_shipment_create_permission(): void
    {
        $this->assertRouteHasPolicyAndPermissions('POST', '/orders/{order_id}/shipments', 'OrderPolicy', 'orders/manage_statuses');
        $this->assertRouteHasPolicyAndPermissions('GET', '/shipping/carrier-options', 'OrderPolicy', 'orders/manage_statuses');
    }

    public function test_orders_calculate_shipping_declares_array_meta(): void
    {
        $this->assertRouteHasPolicyAndPermissions(
            'POST',
            '/orders/calculate-shipping',
            'OrderPolicy',
            ['orders/create', 'orders/manage']
        );
    }

    public function test_reports_overview_uses_report_policy(): void
    {
        // ReportPolicy ignores route meta (hard-codes reports/view). Only verify
        // the policy binding here.
        $route = $this->findFrameworkRoute('GET', '/reports/overview');
        $this->assertNotNull($route, 'Route GET /reports/overview must exist');
        $this->assertSame('ReportPolicy', $this->getRoutePolicyHandler($route));
    }

    public function test_public_products_uses_public_policy(): void
    {
        $route = $this->findFrameworkRoute('GET', '/public/products');
        $this->assertNotNull($route);
        $this->assertSame('PublicPolicy', $this->getRoutePolicyHandler($route));
    }

    public function test_customer_profile_uses_customer_frontend_policy(): void
    {
        $route = $this->findFrameworkRoute('GET', '/customer-profile');
        $this->assertNotNull($route);
        $this->assertSame('CustomerFrontendPolicy', $this->getRoutePolicyHandler($route));
    }

    public function test_address_info_countries_uses_user_policy(): void
    {
        $route = $this->findFrameworkRoute('GET', '/address-info/countries');
        $this->assertNotNull($route);
        $this->assertSame('UserPolicy', $this->getRoutePolicyHandler($route));
    }

    public function test_tax_classes_read_uses_store_settings_policy_with_settings_permission(): void
    {
        $this->assertRouteHasPolicyAndPermissions('GET', '/tax/classes', 'StoreSettingsPolicy', 'store/settings');
    }

    public function test_tax_classes_write_requires_store_sensitive_permission(): void
    {
        $this->assertRouteHasPolicyAndPermissions('POST', '/tax/classes', 'StoreSettingsPolicy', 'store/sensitive');
        $this->assertRouteHasPolicyAndPermissions('DELETE', '/tax/classes/{id}', 'StoreSettingsPolicy', 'store/sensitive');
    }

    public function test_public_products_endpoint_allows_guest(): void
    {
        $response = $this->dispatchRequestAsGuest(new \WP_REST_Request('GET', '/fluent-cart/v2/public/products'));
        $this->assertSame(200, $response->get_status());
    }

    // =========================================================================
    // Guest denial — run each in its own PHP process so the persistent-admin
    // state from RestTestCase::setUpBeforeClass cannot leak in.
    // =========================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_guest_cannot_list_products(): void
    {
        $response = $this->dispatchRequestAsGuest(new \WP_REST_Request('GET', '/fluent-cart/v2/products'));
        $this->assertContains($response->get_status(), [401, 403]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_guest_cannot_list_orders(): void
    {
        $response = $this->dispatchRequestAsGuest(new \WP_REST_Request('GET', '/fluent-cart/v2/orders'));
        $this->assertContains($response->get_status(), [401, 403]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_guest_cannot_list_customers(): void
    {
        $response = $this->dispatchRequestAsGuest(new \WP_REST_Request('GET', '/fluent-cart/v2/customers'));
        $this->assertContains($response->get_status(), [401, 403]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_guest_cannot_list_coupons(): void
    {
        $response = $this->dispatchRequestAsGuest(new \WP_REST_Request('GET', '/fluent-cart/v2/coupons'));
        $this->assertContains($response->get_status(), [401, 403]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_guest_cannot_read_reports(): void
    {
        $response = $this->dispatchRequestAsGuest(new \WP_REST_Request('GET', '/fluent-cart/v2/reports/overview'));
        $this->assertContains($response->get_status(), [401, 403]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_guest_cannot_access_store_settings(): void
    {
        $response = $this->dispatchRequestAsGuest(new \WP_REST_Request('GET', '/fluent-cart/v2/settings/store'));
        $this->assertContains($response->get_status(), [401, 403]);
    }

    public function test_subscriber_cannot_list_products(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/products');
        $response = $this->dispatchRequestAs($userId, $request);
        $this->assertContains($response->get_status(), [401, 403]);
    }

    // =========================================================================
    // Write-route dispatches — guest + subscriber on POST/DELETE endpoints.
    // Reflection-based meta checks above prove the WIRING; these prove the
    // RUNTIME guard actually rejects unauthorized writes.
    // =========================================================================

    public function test_subscriber_cannot_create_order(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/orders');
        $response = $this->dispatchRequestAs($userId, $request);
        $this->assertContains(
            $response->get_status(),
            [401, 403],
            'Subscriber must be denied POST /orders even when Pro is off'
        );
    }

    public function test_subscriber_cannot_delete_product(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        $request = new \WP_REST_Request('DELETE', '/fluent-cart/v2/products/999999');
        $response = $this->dispatchRequestAs($userId, $request);
        $this->assertContains($response->get_status(), [401, 403]);
    }

    public function test_subscriber_cannot_delete_coupon(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        $request = new \WP_REST_Request('DELETE', '/fluent-cart/v2/coupons/999999');
        $response = $this->dispatchRequestAs($userId, $request);
        $this->assertContains($response->get_status(), [401, 403]);
    }

    public function test_subscriber_cannot_refund_order(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/orders/999999/refund');
        $response = $this->dispatchRequestAs($userId, $request);
        $this->assertContains($response->get_status(), [401, 403]);
    }

    public function test_subscriber_cannot_update_order_status(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/orders/999999/statuses');
        $response = $this->dispatchRequestAs($userId, $request);
        $this->assertContains($response->get_status(), [401, 403]);
    }

    public function test_subscriber_cannot_attach_order_note(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/notes/attach');
        $request->set_body_params([
            'order_id' => 999999,
            'note'     => 'forbidden',
        ]);
        $response = $this->dispatchRequestAs($userId, $request);
        $this->assertContains($response->get_status(), [401, 403]);
    }

    public function test_subscriber_cannot_read_settings_permissions(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/settings/permissions');
        $response = $this->dispatchRequestAs($userId, $request);
        $this->assertContains(
            $response->get_status(),
            [401, 403],
            'Only super-admins (manage_options) must read /settings/permissions — route meta declares permissions=is_super_admin'
        );
    }

    public function test_subscriber_cannot_write_settings_permissions(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/settings/permissions');
        $response = $this->dispatchRequestAs($userId, $request);
        $this->assertContains($response->get_status(), [401, 403]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_guest_cannot_create_order(): void
    {
        $response = $this->dispatchRequestAsGuest(new \WP_REST_Request('POST', '/fluent-cart/v2/orders'));
        $this->assertContains($response->get_status(), [401, 403]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_guest_cannot_delete_product(): void
    {
        $response = $this->dispatchRequestAsGuest(new \WP_REST_Request('DELETE', '/fluent-cart/v2/products/999999'));
        $this->assertContains($response->get_status(), [401, 403]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_guest_cannot_read_settings_permissions(): void
    {
        $response = $this->dispatchRequestAsGuest(new \WP_REST_Request('GET', '/fluent-cart/v2/settings/permissions'));
        $this->assertContains($response->get_status(), [401, 403]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_guest_cannot_write_settings_permissions(): void
    {
        $response = $this->dispatchRequestAsGuest(new \WP_REST_Request('POST', '/fluent-cart/v2/settings/permissions'));
        $this->assertContains($response->get_status(), [401, 403]);
    }

    // =========================================================================
    // Logged-in customer frontend — denial AND success paths.
    // =========================================================================

    public function test_customer_frontend_requires_login(): void
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/customer-profile');
        $response = $this->dispatchRequestAsGuest($request);
        $this->assertContains($response->get_status(), [401, 403]);
    }

    public function test_customer_frontend_accepts_logged_in_subscriber(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/customer-profile');
        $response = $this->dispatchRequestAs($userId, $request);
        $this->assertSame(
            200,
            $response->get_status(),
            'CustomerFrontendPolicy must accept any logged-in WP user'
        );
    }

    public function test_address_info_requires_login(): void
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/address-info/countries');
        $response = $this->dispatchRequestAsGuest($request);
        $this->assertContains($response->get_status(), [401, 403]);
    }

    public function test_address_info_accepts_logged_in_subscriber(): void
    {
        $userId = UserRoleFactory::createSubscriber();
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/address-info/countries');
        $response = $this->dispatchRequestAs($userId, $request);
        $this->assertSame(
            200,
            $response->get_status(),
            'UserPolicy must accept any logged-in WP user'
        );
        $data = $response->get_data();
        $this->assertTrue(
            is_array($data) && !empty($data),
            'address-info/countries must return a non-empty payload'
        );
    }

    // =========================================================================
    // Core-without-Pro: shop role assigned but every protected dispatch denied.
    // =========================================================================

    public function test_shop_roles_denied_on_protected_routes_when_pro_absent(): void
    {
        $this->assertFalse(
            App::isProActive(),
            'Core Pro-off contract broken: FLUENTCART_PRO_PLUGIN_VERSION leaked from a sibling test. Fix the leak or run this file in isolation.'
        );

        $endpoints = [
            ['GET', '/fluent-cart/v2/products'],
            ['GET', '/fluent-cart/v2/orders'],
            ['GET', '/fluent-cart/v2/customers'],
            ['GET', '/fluent-cart/v2/coupons'],
            ['GET', '/fluent-cart/v2/reports/overview'],
        ];

        foreach (['manager', 'worker', 'accountant'] as $role) {
            $userId = UserRoleFactory::createWithShopRole($role);
            foreach ($endpoints as [$method, $uri]) {
                $request = new \WP_REST_Request($method, $uri);
                $response = $this->dispatchRequestAs($userId, $request);
                $this->assertContains(
                    $response->get_status(),
                    [401, 403],
                    "Pro absent — {$role} must be denied {$method} {$uri}"
                );
            }
        }
    }
}
