<?php

namespace Tests\Functional\Permissions;

use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Coupon;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\AppliedCouponFactory;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\AdjustsRolePermissions;

/**
 * Spec: Cases/Functional/coupons.md → "Permissions" section (items 54-97)
 * + "Listing active coupon codes" appendix (items 117-124).
 *
 * Coupon routes (under /coupons, CouponPolicy) and their meta permissions:
 *   GET    /coupons                                coupons/view
 *   GET    /coupons/listCoupons                    orders/create OR orders/manage OR coupons/view
 *   GET    /coupons/getSettings                    coupons/view
 *   GET    /coupons/{id}                           coupons/view
 *   POST   /coupons                                coupons/manage
 *   PUT    /coupons/{id}                           coupons/manage
 *   DELETE /coupons/{id}                           coupons/delete
 *   POST   /coupons/apply                          orders/create OR orders/manage
 *   POST   /coupons/cancel                         orders/create OR orders/manage
 *   POST   /coupons/re-apply                       orders/create OR orders/manage
 *   POST   /coupons/checkProductEligibility        orders/create OR orders/manage
 *   POST   /coupons/storeCouponSettings            coupons/manage
 *
 * PermissionManager role grants (per FluentCart\App\Services\Permission\PermissionManager::getAllRoles):
 *   super_admin — all permissions
 *   manager     — coupons/view, coupons/manage, coupons/delete, orders/view, orders/manage,
 *                 orders/manage_statuses, orders/can_refund, orders/export, orders/delete (no orders/create)
 *   worker      — coupons/view, coupons/manage, orders/view, orders/manage_statuses
 *                 (no coupons/delete, no orders/manage, no orders/create)
 *   accountant  — coupons/view only (no coupons/manage, no coupons/delete,
 *                 no orders/manage, no orders/create)
 *   customer    — none (subscriber WP role, no FC admin role)
 *   guest       — none (logged-out)
 *
 * "with" tests rely on each role's default permission grant (no strip).
 * "without" tests cover permissions the role lacks by default (no strip).
 * Cases 122-124 narrate roles with non-default permission shapes — the
 * AdjustsRolePermissions trait shapes the role for the duration of the dispatch.
 */
class CouponsPermissionsCest
{
    use AdjustsRolePermissions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Build a complete create-coupon payload satisfying CouponRequest::rules().
     * Used by every "can create" / "can save" permission test.
     */
    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'title'            => 'Permission Created Coupon',
            'code'             => 'PERM-CREATED',
            'type'             => 'fixed',
            'amount'           => 10,
            'status'           => 'active',
            'stackable'        => 'yes',
            'show_on_checkout' => 'yes',
            'priority'         => 1,
            'notes'            => '',
            'conditions'       => [],
        ], $overrides);
    }

    /**
     * Build a single-line-item array compatible with the FrontendRequest
     * coupon-apply / coupon-cancel validators. Mirrors the shape used by
     * ApplyCouponCest so the service receives identical inputs.
     */
    private function lineItem(int $variantId, int $productId): array
    {
        $unitPrice = 5000;
        $quantity  = 2;
        return [
            'id'             => $variantId,
            'order_id'       => 1,
            'post_id'        => $productId,
            'variation_id'   => $variantId,
            'type'           => 'product',
            'quantity'       => $quantity,
            'title'          => 'Line',
            'price'          => $unitPrice,
            'unit_price'     => $unitPrice,
            'item_cost'      => 0,
            'item_total'     => $unitPrice * $quantity,
            'tax_amount'     => 0,
            'discount_total' => 0,
            'total'          => $unitPrice * $quantity,
            'line_total'     => $unitPrice * $quantity,
            'subtotal'       => $unitPrice * $quantity,
            'rate'           => 0,
            'cart_index'     => 0,
            'other_info'     => [],
        ];
    }

    // -------------------------------------------------------------------------
    // Items 54-62 — Store manager
    // -------------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/coupons.md [54]
     *   "Store manager with the \"view coupons\" permission can list coupons."
     */
    public function storeManagerWithViewCouponsPermissionCanListCoupons(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['title' => 'SM List 54', 'code' => 'SM-LIST-54']);

        $I->loginAsManager();
        [$status, $body] = $I->get('/fluent-cart/v2/coupons');

        Assert::assertSame(200, $status, 'manager-with-view list-coupons must return 200. Body: ' . wp_json_encode($body));
        $rows = (array) ($body['coupons']['data'] ?? []);
        $ids = array_map('intval', array_column($rows, 'id'));
        Assert::assertContains((int) $coupon->id, $ids, 'manager list-coupons response must include the seeded coupon id');
    }

    /**
     * Spec: Cases/Functional/coupons.md [55]
     *   "Store manager with the \"view coupons\" permission can view a coupon's details."
     */
    public function storeManagerWithViewCouponsPermissionCanViewCouponDetails(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['title' => 'SM View 55', 'code' => 'SM-VIEW-55']);

        $I->loginAsManager();
        [$status, $body] = $I->get('/fluent-cart/v2/coupons/' . (int) $coupon->id);

        Assert::assertSame(200, $status, 'manager-with-view view-coupon must return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('coupon', (array) $body, 'view-coupon response must expose the coupon under the coupon key');
        Assert::assertSame((int) $coupon->id, (int) ($body['coupon']['id'] ?? 0), 'view-coupon response must echo the requested coupon id');
    }

    /**
     * Spec: Cases/Functional/coupons.md [56]
     *   "Store manager with the \"manage coupons\" permission can create a coupon."
     */
    public function storeManagerWithManageCouponsPermissionCanCreateCoupon(FunctionalTester $I): void
    {
        $I->loginAsManager();
        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->createPayload([
            'title' => 'Manager Create 56',
            'code'  => 'SM-CREATE-56',
        ]));

        Assert::assertSame(200, $status, 'manager-with-manage create-coupon must return 200. Body: ' . wp_json_encode($body));
        Assert::assertNotNull(
            Coupon::query()->where('code', 'SM-CREATE-56')->first(),
            'manager create must persist the fct_coupons row keyed by the submitted code'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [57]
     *   "Store manager with the \"manage coupons\" permission can update a coupon."
     */
    public function storeManagerWithManageCouponsPermissionCanUpdateCoupon(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['title' => 'SM Old', 'code' => 'SM-UPDATE-57']);

        $I->loginAsManager();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/coupons/' . (int) $coupon->id,
            $this->createPayload(['title' => 'SM New Title', 'code' => 'SM-UPDATE-57'])
        );

        Assert::assertSame(200, $status, 'manager-with-manage update-coupon must return 200. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            'SM New Title',
            (string) Coupon::query()->where('id', (int) $coupon->id)->value('title'),
            'manager update must persist the renamed title to fct_coupons.title'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [58]
     *   "Store manager with the \"delete coupons\" permission can delete a coupon."
     */
    public function storeManagerWithDeleteCouponsPermissionCanDeleteCoupon(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['title' => 'SM Delete 58', 'code' => 'SM-DELETE-58']);
        $couponId = (int) $coupon->id;

        $I->loginAsManager();
        [$status, $body] = $I->delete('/fluent-cart/v2/coupons/' . $couponId);

        Assert::assertSame(200, $status, 'manager-with-delete delete-coupon must return 200. Body: ' . wp_json_encode($body));
        global $wpdb;
        Assert::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_coupons WHERE id = %d",
                $couponId
            )),
            'manager delete must remove the underlying fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [59]
     *   "Store manager with the \"manage orders\" permission can apply a coupon to an order."
     */
    public function storeManagerWithManageOrdersPermissionCanApplyCouponToOrder(FunctionalTester $I): void
    {
        (new CouponFactory())->create([
            'code'   => 'SM-APPLY-59',
            'type'   => 'fixed',
            'amount' => 1000,
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        $I->loginAsManager();
        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'SM-APPLY-59',
            'order_items' => [$this->lineItem((int) $variant->id, (int) $product->ID)],
        ]);

        Assert::assertSame(200, $status, 'manager-with-manage_orders apply-coupon must return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey(
            'SM-APPLY-59',
            (array) ($body['applied_coupons'] ?? []),
            'apply response must include the submitted coupon code under applied_coupons (proves the policy gate let the manager through and the apply handler ran)'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [60]
     *   "Store manager with the \"manage orders\" permission can cancel a coupon on an order."
     */
    public function storeManagerWithManageOrdersPermissionCanCancelCouponOnOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'sm-cancel-60@example.test']);
        $coupon = (new CouponFactory())->create(['code' => 'SM-CANCEL-60', 'type' => 'fixed', 'amount' => 500]);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $applied = (new AppliedCouponFactory())->create([
            'order_id'  => (int) $order->id,
            'coupon_id' => (int) $coupon->id,
            'code'      => 'SM-CANCEL-60',
            'amount'    => 500,
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        $I->loginAsManager();
        [$status, $body] = $I->post('/fluent-cart/v2/coupons/cancel', [
            'id'              => (int) $applied->id,
            'coupon_code'     => 'SM-CANCEL-60',
            'order_uuid'      => (string) $order->uuid,
            'applied_coupons' => [(int) $coupon->id],
            'order_items'     => [$this->lineItem((int) $variant->id, (int) $product->ID)],
        ]);

        Assert::assertSame(200, $status, 'manager-with-manage_orders cancel-coupon must return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayNotHasKey(
            'SM-CANCEL-60',
            (array) ($body['applied_coupons'] ?? []),
            'cancel response must not list the canceled code under applied_coupons (proves the policy gate let the manager through and the cancel handler ran)'
        );
        Assert::assertSame(
            0,
            (int) AppliedCoupon::query()->where('id', (int) $applied->id)->count(),
            'manager cancel must delete the seeded fct_applied_coupons row (proves the cancel side-effect was applied, not just a 200 echo)'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [61]
     *   "Store manager with the \"manage orders\" permission can re-apply coupons to an order."
     */
    public function storeManagerWithManageOrdersPermissionCanReapplyCouponsToOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'sm-reapply-61@example.test']);
        $coupon = (new CouponFactory())->create(['code' => 'SM-REAPPLY-61', 'type' => 'fixed', 'amount' => 500]);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        (new AppliedCouponFactory())->create([
            'order_id'  => (int) $order->id,
            'coupon_id' => (int) $coupon->id,
            'code'      => 'SM-REAPPLY-61',
            'amount'    => 500,
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        $I->loginAsManager();
        [$status, $body] = $I->post('/fluent-cart/v2/coupons/re-apply', [
            'order_uuid'      => (string) $order->uuid,
            'applied_coupons' => [(int) $coupon->id],
            'order_items'     => [$this->lineItem((int) $variant->id, (int) $product->ID)],
        ]);

        Assert::assertSame(200, $status, 'manager-with-manage_orders re-apply-coupon must return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey(
            'applied_coupons',
            (array) $body,
            're-apply response must surface the applied_coupons key (proves the policy gate let the manager through and the re-apply handler ran)'
        );
        Assert::assertArrayHasKey(
            'SM-REAPPLY-61',
            (array) ($body['applied_coupons'] ?? []),
            're-apply response must include the previously saved coupon code under applied_coupons (proves the saved coupon was actually re-applied to the supplied items)'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [62]
     *   "Store manager with the \"manage orders\" permission can check whether a product is eligible for a coupon."
     */
    public function storeManagerWithManageOrdersPermissionCanCheckProductEligibilityForCoupon(FunctionalTester $I): void
    {

        $coupon = (new CouponFactory())->create(['code' => 'SM-ELIG-62']);
        $product = (new ProductFactory())->create();

        $I->loginAsManager();
        // The eligibility contract keys on coupon CODES
        // (CouponResource::checkProductEligibilityForCodes) — a numeric id
        // resolves to "no such coupon" and returns isApplicable=false, which a
        // status-only assert cannot distinguish from a real verdict
        // (review-gate round 6).
        [$status, $body] = $I->post('/fluent-cart/v2/coupons/checkProductEligibility', [
            'productId'      => (int) $product->ID,
            'appliedCoupons' => [$coupon->code],
            'origin'         => 'admin',
        ]);

        Assert::assertSame(200, $status, 'manager-with-manage_orders check-eligibility must return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('isApplicable', (array) $body, 'eligibility response must carry the isApplicable verdict. Body: ' . wp_json_encode($body));
        Assert::assertTrue(
            (bool) $body['isApplicable'],
            'an unrestricted coupon checked against an ordinary product must be applicable — the manager must receive a CORRECT verdict, not just a 200. Body: ' . wp_json_encode($body)
        );
    }

    // -------------------------------------------------------------------------
    // Items 63-71 — Worker
    // -------------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/coupons.md [63]
     *   "Worker with the \"view coupons\" permission can list coupons."
     */
    public function workerWithViewCouponsPermissionCanListCoupons(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['title' => 'W List 63', 'code' => 'W-LIST-63']);

        $I->loginAsWorker();
        [$status, $body] = $I->get('/fluent-cart/v2/coupons');

        Assert::assertSame(200, $status, 'worker-with-view list-coupons must return 200. Body: ' . wp_json_encode($body));
        $rows = (array) ($body['coupons']['data'] ?? []);
        $ids = array_map('intval', array_column($rows, 'id'));
        Assert::assertContains((int) $coupon->id, $ids, 'worker list-coupons response must include the seeded coupon id');
    }

    /**
     * Spec: Cases/Functional/coupons.md [64]
     *   "Worker with the \"view coupons\" permission can view a coupon's details."
     */
    public function workerWithViewCouponsPermissionCanViewCouponDetails(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['title' => 'W View 64', 'code' => 'W-VIEW-64']);

        $I->loginAsWorker();
        [$status, $body] = $I->get('/fluent-cart/v2/coupons/' . (int) $coupon->id);

        Assert::assertSame(200, $status, 'worker-with-view view-coupon must return 200. Body: ' . wp_json_encode($body));
        Assert::assertSame((int) $coupon->id, (int) ($body['coupon']['id'] ?? 0), 'worker view-coupon response must echo the requested coupon id');
    }

    /**
     * Spec: Cases/Functional/coupons.md [65]
     *   "Worker with the \"manage coupons\" permission can create a coupon."
     */
    public function workerWithManageCouponsPermissionCanCreateCoupon(FunctionalTester $I): void
    {
        $I->loginAsWorker();
        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->createPayload([
            'title' => 'Worker Create 65',
            'code'  => 'W-CREATE-65',
        ]));

        Assert::assertSame(200, $status, 'worker-with-manage create-coupon must return 200. Body: ' . wp_json_encode($body));
        Assert::assertNotNull(
            Coupon::query()->where('code', 'W-CREATE-65')->first(),
            'worker create must persist the fct_coupons row keyed by the submitted code'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [66]
     *   "Worker with the \"manage coupons\" permission can update a coupon."
     */
    public function workerWithManageCouponsPermissionCanUpdateCoupon(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['title' => 'W Old', 'code' => 'W-UPDATE-66']);

        $I->loginAsWorker();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/coupons/' . (int) $coupon->id,
            $this->createPayload(['title' => 'W New Title', 'code' => 'W-UPDATE-66'])
        );

        Assert::assertSame(200, $status, 'worker-with-manage update-coupon must return 200. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            'W New Title',
            (string) Coupon::query()->where('id', (int) $coupon->id)->value('title'),
            'worker update must persist the renamed title to fct_coupons.title'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [67]
     *   "Worker without the \"delete coupons\" permission cannot delete a coupon."
     */
    public function workerWithoutDeleteCouponsPermissionCannotDeleteCoupon(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['title' => 'W Cannot Delete 67', 'code' => 'W-DELETE-67']);
        $couponId = (int) $coupon->id;

        $I->loginAsWorker();
        [, $body] = $I->delete('/fluent-cart/v2/coupons/' . $couponId);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-delete delete-coupon denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-delete delete-coupon denial must include a permission-denied message');

        global $wpdb;
        Assert::assertSame(
            1,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_coupons WHERE id = %d",
                $couponId
            )),
            'worker-without-delete denial must NOT remove the underlying fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [68]
     *   "Worker without the \"manage orders\" permission and without the \"create orders\" permission cannot apply a coupon to an order."
     */
    public function workerWithoutManageOrdersAndCreateOrdersPermissionsCannotApplyCouponToOrder(FunctionalTester $I): void
    {
        (new CouponFactory())->create(['code' => 'W-APPLY-68']);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'W-APPLY-68',
            'order_items' => [$this->lineItem((int) $variant->id, (int) $product->ID)],
        ]);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-orders-perms apply-coupon denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-orders-perms apply-coupon denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/coupons.md [69]
     *   "Worker without the \"manage orders\" permission and without the \"create orders\" permission cannot cancel a coupon on an order."
     */
    public function workerWithoutManageOrdersAndCreateOrdersPermissionsCannotCancelCouponOnOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'w-cancel-69@example.test']);
        $coupon = (new CouponFactory())->create(['code' => 'W-CANCEL-69']);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $applied = (new AppliedCouponFactory())->create([
            'order_id'  => (int) $order->id,
            'coupon_id' => (int) $coupon->id,
            'code'      => 'W-CANCEL-69',
        ]);

        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/coupons/cancel', [
            'id'              => (int) $applied->id,
            'coupon_code'     => 'W-CANCEL-69',
            'order_uuid'      => (string) $order->uuid,
            'applied_coupons' => [(int) $coupon->id],
            'order_items'     => [],
        ]);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-orders-perms cancel-coupon denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-orders-perms cancel-coupon denial must include a permission-denied message');
        Assert::assertSame(
            1,
            (int) AppliedCoupon::query()->where('id', (int) $applied->id)->count(),
            'worker-without-orders-perms cancel denial must NOT delete the seeded fct_applied_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [70]
     *   "Worker without the \"manage orders\" permission and without the \"create orders\" permission cannot re-apply coupons to an order."
     */
    public function workerWithoutManageOrdersAndCreateOrdersPermissionsCannotReapplyCouponsToOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'w-reapply-70@example.test']);
        $coupon = (new CouponFactory())->create(['code' => 'W-REAPPLY-70']);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $applied = (new AppliedCouponFactory())->create([
            'order_id'  => (int) $order->id,
            'coupon_id' => (int) $coupon->id,
            'code'      => 'W-REAPPLY-70',
        ]);

        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/coupons/re-apply', [
            'order_uuid'  => (string) $order->uuid,
            'order_items' => [],
        ]);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-orders-perms re-apply-coupon denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-orders-perms re-apply-coupon denial must include a permission-denied message');
        Assert::assertSame(
            1,
            (int) AppliedCoupon::query()->where('id', (int) $applied->id)->count(),
            'worker-without-orders-perms re-apply denial must NOT delete the seeded fct_applied_coupons row (re-apply with empty order_items would otherwise wipe saved coupons — the 403 must short-circuit before that side-effect)'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [71]
     *   "Worker without the \"manage orders\" permission and without the \"create orders\" permission cannot check whether a product is eligible for a coupon."
     */
    public function workerWithoutManageOrdersAndCreateOrdersPermissionsCannotCheckProductEligibilityForCoupon(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['code' => 'W-ELIG-71']);
        $product = (new ProductFactory())->create();

        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/coupons/checkProductEligibility', [
            'productId'      => (int) $product->ID,
            'appliedCoupons' => [(int) $coupon->id],
            'origin'         => 'admin',
        ]);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-orders-perms check-eligibility denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-orders-perms check-eligibility denial must include a permission-denied message');
    }

    // -------------------------------------------------------------------------
    // Items 72-80 — Accountant
    // -------------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/coupons.md [72]
     *   "Accountant with the \"view coupons\" permission can list coupons."
     */
    public function accountantWithViewCouponsPermissionCanListCoupons(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['title' => 'A List 72', 'code' => 'A-LIST-72']);

        $I->loginAsAccountant();
        [$status, $body] = $I->get('/fluent-cart/v2/coupons');

        Assert::assertSame(200, $status, 'accountant-with-view list-coupons must return 200. Body: ' . wp_json_encode($body));
        $rows = (array) ($body['coupons']['data'] ?? []);
        $ids = array_map('intval', array_column($rows, 'id'));
        Assert::assertContains((int) $coupon->id, $ids, 'accountant list-coupons response must include the seeded coupon id');
    }

    /**
     * Spec: Cases/Functional/coupons.md [73]
     *   "Accountant with the \"view coupons\" permission can view a coupon's details."
     */
    public function accountantWithViewCouponsPermissionCanViewCouponDetails(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['title' => 'A View 73', 'code' => 'A-VIEW-73']);

        $I->loginAsAccountant();
        [$status, $body] = $I->get('/fluent-cart/v2/coupons/' . (int) $coupon->id);

        Assert::assertSame(200, $status, 'accountant-with-view view-coupon must return 200. Body: ' . wp_json_encode($body));
        Assert::assertSame((int) $coupon->id, (int) ($body['coupon']['id'] ?? 0), 'accountant view-coupon response must echo the requested coupon id');
    }

    /**
     * Spec: Cases/Functional/coupons.md [74]
     *   "Accountant without the \"manage coupons\" permission cannot create a coupon."
     */
    public function accountantWithoutManageCouponsPermissionCannotCreateCoupon(FunctionalTester $I): void
    {
        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/coupons', $this->createPayload([
            'title' => 'Accountant Create 74',
            'code'  => 'A-CREATE-74',
        ]));

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-manage create-coupon denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-manage create-coupon denial must include a permission-denied message');
        Assert::assertNull(
            Coupon::query()->where('code', 'A-CREATE-74')->first(),
            'accountant-without-manage create-coupon denial must NOT persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [75]
     *   "Accountant without the \"manage coupons\" permission cannot update a coupon."
     */
    public function accountantWithoutManageCouponsPermissionCannotUpdateCoupon(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['title' => 'A Locked Title', 'code' => 'A-UPDATE-75']);

        $I->loginAsAccountant();
        [, $body] = $I->put(
            '/fluent-cart/v2/coupons/' . (int) $coupon->id,
            $this->createPayload(['title' => 'Forbidden Update', 'code' => 'A-UPDATE-75'])
        );

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-manage update-coupon denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-manage update-coupon denial must include a permission-denied message');
        Assert::assertSame(
            'A Locked Title',
            (string) Coupon::query()->where('id', (int) $coupon->id)->value('title'),
            'accountant-without-manage denial must NOT mutate fct_coupons.title'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [76]
     *   "Accountant without the \"delete coupons\" permission cannot delete a coupon."
     */
    public function accountantWithoutDeleteCouponsPermissionCannotDeleteCoupon(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['title' => 'A Cannot Delete 76', 'code' => 'A-DELETE-76']);
        $couponId = (int) $coupon->id;

        $I->loginAsAccountant();
        [, $body] = $I->delete('/fluent-cart/v2/coupons/' . $couponId);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-delete delete-coupon denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-delete delete-coupon denial must include a permission-denied message');

        global $wpdb;
        Assert::assertSame(
            1,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_coupons WHERE id = %d",
                $couponId
            )),
            'accountant-without-delete denial must NOT remove the underlying fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [77]
     *   "Accountant without the \"manage orders\" permission and without the \"create orders\" permission cannot apply a coupon to an order."
     */
    public function accountantWithoutManageOrdersAndCreateOrdersPermissionsCannotApplyCouponToOrder(FunctionalTester $I): void
    {
        (new CouponFactory())->create(['code' => 'A-APPLY-77']);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'A-APPLY-77',
            'order_items' => [$this->lineItem((int) $variant->id, (int) $product->ID)],
        ]);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-orders-perms apply-coupon denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-orders-perms apply-coupon denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/coupons.md [78]
     *   "Accountant without the \"manage orders\" permission and without the \"create orders\" permission cannot cancel a coupon on an order."
     */
    public function accountantWithoutManageOrdersAndCreateOrdersPermissionsCannotCancelCouponOnOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'a-cancel-78@example.test']);
        $coupon = (new CouponFactory())->create(['code' => 'A-CANCEL-78']);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $applied = (new AppliedCouponFactory())->create([
            'order_id'  => (int) $order->id,
            'coupon_id' => (int) $coupon->id,
            'code'      => 'A-CANCEL-78',
        ]);

        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/coupons/cancel', [
            'id'              => (int) $applied->id,
            'coupon_code'     => 'A-CANCEL-78',
            'order_uuid'      => (string) $order->uuid,
            'applied_coupons' => [(int) $coupon->id],
            'order_items'     => [],
        ]);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-orders-perms cancel-coupon denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-orders-perms cancel-coupon denial must include a permission-denied message');
        Assert::assertSame(
            1,
            (int) AppliedCoupon::query()->where('id', (int) $applied->id)->count(),
            'accountant-without-orders-perms cancel denial must NOT delete the seeded fct_applied_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [79]
     *   "Accountant without the \"manage orders\" permission and without the \"create orders\" permission cannot re-apply coupons to an order."
     */
    public function accountantWithoutManageOrdersAndCreateOrdersPermissionsCannotReapplyCouponsToOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'a-reapply-79@example.test']);
        $coupon = (new CouponFactory())->create(['code' => 'A-REAPPLY-79']);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $applied = (new AppliedCouponFactory())->create([
            'order_id'  => (int) $order->id,
            'coupon_id' => (int) $coupon->id,
            'code'      => 'A-REAPPLY-79',
        ]);

        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/coupons/re-apply', [
            'order_uuid'  => (string) $order->uuid,
            'order_items' => [],
        ]);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-orders-perms re-apply-coupon denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-orders-perms re-apply-coupon denial must include a permission-denied message');
        Assert::assertSame(
            1,
            (int) AppliedCoupon::query()->where('id', (int) $applied->id)->count(),
            'accountant-without-orders-perms re-apply denial must NOT delete the seeded fct_applied_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [80]
     *   "Accountant without the \"manage orders\" permission and without the \"create orders\" permission cannot check whether a product is eligible for a coupon."
     */
    public function accountantWithoutManageOrdersAndCreateOrdersPermissionsCannotCheckProductEligibilityForCoupon(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['code' => 'A-ELIG-80']);
        $product = (new ProductFactory())->create();

        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/coupons/checkProductEligibility', [
            'productId'      => (int) $product->ID,
            'appliedCoupons' => [(int) $coupon->id],
            'origin'         => 'admin',
        ]);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-orders-perms check-eligibility denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-orders-perms check-eligibility denial must include a permission-denied message');
    }

    // -------------------------------------------------------------------------
    // Items 81-86 — Coupon checkout settings (per-role)
    // -------------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/coupons.md [81]
     *   "Store manager with the \"view coupons\" permission can read the coupon checkout settings."
     */
    public function storeManagerWithViewCouponsPermissionCanReadCheckoutSettings(FunctionalTester $I): void
    {
        $I->loginAsManager();
        [$status, $body] = $I->get('/fluent-cart/v2/coupons/getSettings');

        Assert::assertSame(200, $status, 'manager-with-view get-settings must return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey(
            'show_on_checkout',
            (array) $body,
            'get-settings success body must surface the show_on_checkout key (proves the policy gate passed and the handler ran)'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [82]
     *   "Worker with the \"view coupons\" permission can read the coupon checkout settings."
     */
    public function workerWithViewCouponsPermissionCanReadCheckoutSettings(FunctionalTester $I): void
    {
        $I->loginAsWorker();
        [$status, $body] = $I->get('/fluent-cart/v2/coupons/getSettings');

        Assert::assertSame(200, $status, 'worker-with-view get-settings must return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey(
            'show_on_checkout',
            (array) $body,
            'get-settings success body must surface the show_on_checkout key for worker-with-view'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [83]
     *   "Accountant with the \"view coupons\" permission can read the coupon checkout settings."
     */
    public function accountantWithViewCouponsPermissionCanReadCheckoutSettings(FunctionalTester $I): void
    {
        $I->loginAsAccountant();
        [$status, $body] = $I->get('/fluent-cart/v2/coupons/getSettings');

        Assert::assertSame(200, $status, 'accountant-with-view get-settings must return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey(
            'show_on_checkout',
            (array) $body,
            'get-settings success body must surface the show_on_checkout key for accountant-with-view'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [84]
     *   "Store manager with the \"manage coupons\" permission can save the coupon checkout settings."
     */
    public function storeManagerWithManageCouponsPermissionCanSaveCheckoutSettings(FunctionalTester $I): void
    {
        $I->loginAsManager();
        [$status, $body] = $I->post('/fluent-cart/v2/coupons/storeCouponSettings', [
            'show_on_checkout' => true,
        ]);

        Assert::assertSame(200, $status, 'manager-with-manage store-settings must return 200. Body: ' . wp_json_encode($body));

        global $wpdb;
        $persisted = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}fct_meta WHERE meta_key = %s",
            'fluent_cart_coupon_settings'
        ));
        Assert::assertSame(
            '1',
            (string) $persisted,
            'manager save must persist the on-state sentinel "1" in fct_meta (proves the policy gate passed and the handler ran)'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [85]
     *   "Worker with the \"manage coupons\" permission can save the coupon checkout settings."
     */
    public function workerWithManageCouponsPermissionCanSaveCheckoutSettings(FunctionalTester $I): void
    {
        $I->loginAsWorker();
        [$status, $body] = $I->post('/fluent-cart/v2/coupons/storeCouponSettings', [
            'show_on_checkout' => true,
        ]);

        Assert::assertSame(200, $status, 'worker-with-manage store-settings must return 200. Body: ' . wp_json_encode($body));

        global $wpdb;
        $persisted = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}fct_meta WHERE meta_key = %s",
            'fluent_cart_coupon_settings'
        ));
        Assert::assertSame(
            '1',
            (string) $persisted,
            'worker save must persist the on-state sentinel "1" in fct_meta'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [86]
     *   "Accountant without the \"manage coupons\" permission cannot save the coupon checkout settings."
     */
    public function accountantWithoutManageCouponsPermissionCannotSaveCheckoutSettings(FunctionalTester $I): void
    {
        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/coupons/storeCouponSettings', [
            'show_on_checkout' => true,
        ]);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-manage store-settings denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-manage store-settings denial must include a permission-denied message');

        global $wpdb;
        $persisted = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_meta WHERE meta_key = %s",
            'fluent_cart_coupon_settings'
        ));
        Assert::assertSame(
            0,
            (int) $persisted,
            'accountant-without-manage denial must NOT persist a fct_meta row for the coupon settings key'
        );
    }

    // -------------------------------------------------------------------------
    // Items 87-97 — Logged-out visitor (guest)
    // -------------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/coupons.md [87]
     *   "A logged-out visitor cannot list coupons."
     */
    public function guestCannotListCoupons(FunctionalTester $I): void
    {
        (new CouponFactory())->create(['code' => 'GUEST-LIST-87']);

        $I->logout();
        [$status, $body] = $I->get('/fluent-cart/v2/coupons');

        Assert::assertSame(401, $status, 'guest list-coupons must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest list-coupons denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'guest list-coupons denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/coupons.md [88]
     *   "A logged-out visitor cannot view a single coupon."
     */
    public function guestCannotViewSingleCoupon(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['code' => 'GUEST-VIEW-88']);

        $I->logout();
        [$status, $body] = $I->get('/fluent-cart/v2/coupons/' . (int) $coupon->id);

        Assert::assertSame(401, $status, 'guest view-coupon must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest view-coupon denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'guest view-coupon denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/coupons.md [89]
     *   "A logged-out visitor cannot create a coupon."
     */
    public function guestCannotCreateCoupon(FunctionalTester $I): void
    {
        $I->logout();
        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->createPayload([
            'title' => 'Guest Create 89',
            'code'  => 'GUEST-CREATE-89',
        ]));

        Assert::assertSame(401, $status, 'guest create-coupon must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest create-coupon denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'guest create-coupon denial must include a permission-denied message');
        Assert::assertNull(
            Coupon::query()->where('code', 'GUEST-CREATE-89')->first(),
            'guest create-coupon denial must NOT persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [90]
     *   "A logged-out visitor cannot update a coupon."
     */
    public function guestCannotUpdateCoupon(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['title' => 'Guest Locked', 'code' => 'GUEST-UPDATE-90']);

        $I->logout();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/coupons/' . (int) $coupon->id,
            $this->createPayload(['title' => 'Forbidden Update', 'code' => 'GUEST-UPDATE-90'])
        );

        Assert::assertSame(401, $status, 'guest update-coupon must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest update-coupon denial must surface rest_forbidden code');
        Assert::assertSame(
            'Guest Locked',
            (string) Coupon::query()->where('id', (int) $coupon->id)->value('title'),
            'guest update denial must NOT mutate fct_coupons.title'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [91]
     *   "A logged-out visitor cannot delete a coupon."
     */
    public function guestCannotDeleteCoupon(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['code' => 'GUEST-DELETE-91']);
        $couponId = (int) $coupon->id;

        $I->logout();
        [$status, $body] = $I->delete('/fluent-cart/v2/coupons/' . $couponId);

        Assert::assertSame(401, $status, 'guest delete-coupon must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest delete-coupon denial must surface rest_forbidden code');

        global $wpdb;
        Assert::assertSame(
            1,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_coupons WHERE id = %d",
                $couponId
            )),
            'guest delete denial must NOT remove the underlying fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [92]
     *   "A logged-out visitor cannot apply a coupon to an order."
     */
    public function guestCannotApplyCouponToOrder(FunctionalTester $I): void
    {
        (new CouponFactory())->create(['code' => 'GUEST-APPLY-92']);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        $I->logout();
        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'GUEST-APPLY-92',
            'order_items' => [$this->lineItem((int) $variant->id, (int) $product->ID)],
        ]);

        Assert::assertSame(401, $status, 'guest apply-coupon must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest apply-coupon denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'guest apply-coupon denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/coupons.md [93]
     *   "A logged-out visitor cannot cancel a coupon on an order."
     */
    public function guestCannotCancelCouponOnOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'guest-cancel-93@example.test']);
        $coupon = (new CouponFactory())->create(['code' => 'GUEST-CANCEL-93']);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $applied = (new AppliedCouponFactory())->create([
            'order_id'  => (int) $order->id,
            'coupon_id' => (int) $coupon->id,
            'code'      => 'GUEST-CANCEL-93',
        ]);

        $I->logout();
        [$status, $body] = $I->post('/fluent-cart/v2/coupons/cancel', [
            'id'              => (int) $applied->id,
            'coupon_code'     => 'GUEST-CANCEL-93',
            'order_uuid'      => (string) $order->uuid,
            'applied_coupons' => [(int) $coupon->id],
            'order_items'     => [],
        ]);

        Assert::assertSame(401, $status, 'guest cancel-coupon must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest cancel-coupon denial must surface rest_forbidden code');
        Assert::assertSame(
            1,
            (int) AppliedCoupon::query()->where('id', (int) $applied->id)->count(),
            'guest cancel denial must NOT delete the seeded fct_applied_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [94]
     *   "A logged-out visitor cannot re-apply coupons to an order."
     */
    public function guestCannotReapplyCouponsToOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'guest-reapply-94@example.test']);
        $coupon = (new CouponFactory())->create(['code' => 'GUEST-REAPPLY-94']);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $applied = (new AppliedCouponFactory())->create([
            'order_id'  => (int) $order->id,
            'coupon_id' => (int) $coupon->id,
            'code'      => 'GUEST-REAPPLY-94',
        ]);

        $I->logout();
        [$status, $body] = $I->post('/fluent-cart/v2/coupons/re-apply', [
            'order_uuid'  => (string) $order->uuid,
            'order_items' => [],
        ]);

        Assert::assertSame(401, $status, 'guest re-apply-coupon must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest re-apply-coupon denial must surface rest_forbidden code');
        Assert::assertSame(
            1,
            (int) AppliedCoupon::query()->where('id', (int) $applied->id)->count(),
            'guest re-apply denial must NOT delete the seeded fct_applied_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [95]
     *   "A logged-out visitor cannot check whether a product is eligible for a coupon."
     */
    public function guestCannotCheckProductEligibilityForCoupon(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['code' => 'GUEST-ELIG-95']);
        $product = (new ProductFactory())->create();

        $I->logout();
        [$status, $body] = $I->post('/fluent-cart/v2/coupons/checkProductEligibility', [
            'productId'      => (int) $product->ID,
            'appliedCoupons' => [(int) $coupon->id],
            'origin'         => 'admin',
        ]);

        Assert::assertSame(401, $status, 'guest check-eligibility must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest check-eligibility denial must surface rest_forbidden code');
    }

    /**
     * Spec: Cases/Functional/coupons.md [96]
     *   "A logged-out visitor cannot read the coupon checkout settings."
     */
    public function guestCannotReadCheckoutSettings(FunctionalTester $I): void
    {
        $I->logout();
        [$status, $body] = $I->get('/fluent-cart/v2/coupons/getSettings');

        Assert::assertSame(401, $status, 'guest get-settings must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest get-settings denial must surface rest_forbidden code');
    }

    /**
     * Spec: Cases/Functional/coupons.md [97]
     *   "A logged-out visitor cannot save the coupon checkout settings."
     */
    public function guestCannotSaveCheckoutSettings(FunctionalTester $I): void
    {
        $I->logout();
        [$status, $body] = $I->post('/fluent-cart/v2/coupons/storeCouponSettings', [
            'show_on_checkout' => true,
        ]);

        Assert::assertSame(401, $status, 'guest store-settings must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest store-settings denial must surface rest_forbidden code');

        global $wpdb;
        Assert::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_meta WHERE meta_key = %s",
                'fluent_cart_coupon_settings'
            )),
            'guest store-settings denial must NOT persist a fct_meta row for the coupon settings key'
        );
    }

    // -------------------------------------------------------------------------
    // Items 117-124 — Listing active coupon codes (/listCoupons)
    // Route gate: orders/create OR orders/manage OR coupons/view
    // -------------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/coupons.md [117]
     *   "Store manager can list active coupon codes for use when creating an order."
     */
    public function storeManagerCanListActiveCouponCodes(FunctionalTester $I): void
    {
        (new CouponFactory())->create(['title' => 'Active SM 117', 'code' => 'SM-LIST-ACT-117', 'status' => 'active']);
        (new CouponFactory())->create(['title' => 'Disabled SM 117', 'code' => 'SM-LIST-DIS-117', 'status' => 'disabled']);

        $I->loginAsManager();
        [$status, $body] = $I->get('/fluent-cart/v2/coupons/listCoupons');

        Assert::assertSame(200, $status, 'manager listCoupons must return 200. Body: ' . wp_json_encode($body));
        $codes = (array) ($body['coupons'] ?? []);
        Assert::assertContains(
            'SM-LIST-ACT-117',
            $codes,
            'manager listCoupons response must include the seeded active coupon code'
        );
        Assert::assertNotContains(
            'SM-LIST-DIS-117',
            $codes,
            'manager listCoupons response must exclude the disabled coupon code (proves the route filters by active status, not just permission)'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [118]
     *   "Worker can list active coupon codes for use when creating an order."
     */
    public function workerCanListActiveCouponCodes(FunctionalTester $I): void
    {
        (new CouponFactory())->create(['title' => 'Active W 118', 'code' => 'W-LIST-ACT-118', 'status' => 'active']);
        (new CouponFactory())->create(['title' => 'Disabled W 118', 'code' => 'W-LIST-DIS-118', 'status' => 'disabled']);

        $I->loginAsWorker();
        [$status, $body] = $I->get('/fluent-cart/v2/coupons/listCoupons');

        Assert::assertSame(200, $status, 'worker listCoupons must return 200. Body: ' . wp_json_encode($body));
        $codes = (array) ($body['coupons'] ?? []);
        Assert::assertContains(
            'W-LIST-ACT-118',
            $codes,
            'worker listCoupons response must include the seeded active coupon code'
        );
        Assert::assertNotContains(
            'W-LIST-DIS-118',
            $codes,
            'worker listCoupons response must exclude the disabled coupon code'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [119]
     *   "Accountant can list active coupon codes for use when creating an order."
     */
    public function accountantCanListActiveCouponCodes(FunctionalTester $I): void
    {
        (new CouponFactory())->create(['title' => 'Active A 119', 'code' => 'A-LIST-ACT-119', 'status' => 'active']);
        (new CouponFactory())->create(['title' => 'Disabled A 119', 'code' => 'A-LIST-DIS-119', 'status' => 'disabled']);

        $I->loginAsAccountant();
        [$status, $body] = $I->get('/fluent-cart/v2/coupons/listCoupons');

        Assert::assertSame(200, $status, 'accountant listCoupons must return 200. Body: ' . wp_json_encode($body));
        $codes = (array) ($body['coupons'] ?? []);
        Assert::assertContains(
            'A-LIST-ACT-119',
            $codes,
            'accountant listCoupons response must include the seeded active coupon code'
        );
        Assert::assertNotContains(
            'A-LIST-DIS-119',
            $codes,
            'accountant listCoupons response must exclude the disabled coupon code'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [120]
     *   "A logged-out visitor cannot list active coupon codes for use when creating an order."
     */
    public function guestCannotListActiveCouponCodes(FunctionalTester $I): void
    {
        (new CouponFactory())->create(['code' => 'GUEST-LIST-ACT-120', 'status' => 'active']);

        $I->logout();
        [$status, $body] = $I->get('/fluent-cart/v2/coupons/listCoupons');

        Assert::assertSame(401, $status, 'guest listCoupons must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest listCoupons denial must surface rest_forbidden code');
    }

    /**
     * Spec: Cases/Functional/coupons.md [121]
     *   "A customer (a WordPress user with only the customer role) cannot list active coupon codes for use when creating an order."
     */
    public function customerCannotListActiveCouponCodes(FunctionalTester $I): void
    {
        (new CouponFactory())->create(['code' => 'CUST-LIST-ACT-121', 'status' => 'active']);

        $I->loginAs('customer');
        [, $body] = $I->get('/fluent-cart/v2/coupons/listCoupons');

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'customer listCoupons denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'customer listCoupons denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/coupons.md [122]
     *   "A worker whose permissions are adjusted to grant only \"manage orders\" (with no \"view coupons\" and no \"create orders\") can still list active coupon codes for use when creating an order."
     */
    public function workerWithOnlyManageOrdersPermissionCanStillListActiveCouponCodes(FunctionalTester $I): void
    {
        (new CouponFactory())->create(['code' => 'W-ONLY-MANAGE-122', 'status' => 'active']);
        (new CouponFactory())->create(['code' => 'W-ONLY-MANAGE-DIS-122', 'status' => 'disabled']);

        $remove = $this->setRolePermissions('worker', ['orders/manage']);
        try {
            $I->loginAsWorker();
            [$status, $body] = $I->get('/fluent-cart/v2/coupons/listCoupons');

            Assert::assertSame(
                200,
                $status,
                'worker with only orders/manage must reach listCoupons via the orders/manage clause of the route gate. Body: ' . wp_json_encode($body)
            );
            $codes = (array) ($body['coupons'] ?? []);
            Assert::assertContains(
                'W-ONLY-MANAGE-122',
                $codes,
                'worker-only-manage_orders listCoupons response must include the seeded active coupon code'
            );
            Assert::assertNotContains(
                'W-ONLY-MANAGE-DIS-122',
                $codes,
                'worker-only-manage_orders listCoupons response must exclude the disabled coupon code'
            );
        } finally {
            $remove();
        }
    }

    /**
     * Spec: Cases/Functional/coupons.md [123]
     *   "A worker whose permissions are adjusted to grant only \"create orders\" (with no \"view coupons\" and no \"manage orders\") can still list active coupon codes for use when creating an order."
     */
    public function workerWithOnlyCreateOrdersPermissionCanStillListActiveCouponCodes(FunctionalTester $I): void
    {
        (new CouponFactory())->create(['code' => 'W-ONLY-CREATE-123', 'status' => 'active']);
        (new CouponFactory())->create(['code' => 'W-ONLY-CREATE-DIS-123', 'status' => 'disabled']);

        $remove = $this->setRolePermissions('worker', ['orders/create']);
        try {
            $I->loginAsWorker();
            [$status, $body] = $I->get('/fluent-cart/v2/coupons/listCoupons');

            Assert::assertSame(
                200,
                $status,
                'worker with only orders/create must reach listCoupons via the orders/create clause of the route gate. Body: ' . wp_json_encode($body)
            );
            $codes = (array) ($body['coupons'] ?? []);
            Assert::assertContains(
                'W-ONLY-CREATE-123',
                $codes,
                'worker-only-create_orders listCoupons response must include the seeded active coupon code'
            );
            Assert::assertNotContains(
                'W-ONLY-CREATE-DIS-123',
                $codes,
                'worker-only-create_orders listCoupons response must exclude the disabled coupon code'
            );
        } finally {
            $remove();
        }
    }

    /**
     * Spec: Cases/Functional/coupons.md [124]
     *   "A worker whose permissions are adjusted to remove \"view coupons\", \"manage orders\", and \"create orders\" cannot list active coupon codes for use when creating an order."
     */
    public function workerWithoutViewCouponsAndOrderPermissionsCannotListActiveCouponCodes(FunctionalTester $I): void
    {
        (new CouponFactory())->create(['code' => 'W-NO-PERMS-124', 'status' => 'active']);

        $remove = $this->stripPermissionsFromRole('worker', ['coupons/view', 'orders/manage', 'orders/create']);
        try {
            $I->loginAsWorker();
            [, $body] = $I->get('/fluent-cart/v2/coupons/listCoupons');

            $I->seeResponseCodeIs(403);
            Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-all-three listCoupons denial must surface rest_forbidden code');
            Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-all-three listCoupons denial must include a permission-denied message');
        } finally {
            $remove();
        }
    }
}
