<?php

namespace Tests\Functional\Permissions;

use FluentCart\App\Helpers\Status;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\AdjustsRolePermissions;

/**
 * Spec: Cases/Functional/orders.md → "Running bulk actions on orders", items 75a-75b.
 *
 * POST /orders/do-bulk-action declared `orders/manage`, but every action it accepts —
 * delete_orders and delete_test_orders — permanently deletes orders and their related records.
 * A delegated user holding orders/manage without orders/delete could therefore
 * destroy orders that DELETE /orders/{id} (orders/delete) refuses them.
 *
 * The route now declares `orders/delete`, matching the single-order delete route.
 * These two cases pin that: strip orders/delete from the manager role, leave
 * orders/manage in place, and both bulk delete names must be denied with no row
 * leaving fct_orders. Reverting the route meta to orders/manage fails both.
 */
class BulkOrderDeletePermissionCest
{
    use AdjustsRolePermissions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Free + on_hold passes Order::canBeDeleted, so a permitted caller would
     * really delete these. The oracle is therefore permission, not deletability.
     */
    private function makeDeletableOrder(int $customerId, string $invoiceSuffix): int
    {
        return (int) (new OrderFactory())->create([
            'customer_id'    => $customerId,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => 0,
            'total_amount'   => 0,
            'invoice_no'     => 'INV-BULKPERM-' . $invoiceSuffix,
        ])->id;
    }

    private function remainingOrderIds(): array
    {
        global $wpdb;

        return array_map('intval', (array) $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}fct_orders ORDER BY id ASC"
        ));
    }

    /**
     * Spec: Cases/Functional/orders.md [75a]
     *   "A store manager who can manage orders but cannot delete them is rejected
     *    when bulk-deleting orders, and the selected orders survive."
     */
    public function managerWithoutDeleteOrdersPermissionCannotBulkDeleteOrders(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'bulkperm-delete@example.test']);
        $orderA   = $this->makeDeletableOrder((int) $customer->id, 'A');
        $orderB   = $this->makeDeletableOrder((int) $customer->id, 'B');

        // Manager keeps orders/manage; only orders/delete is removed.
        $restore = $this->stripPermissionFromRole('manager', 'orders/delete');

        try {
            $I->loginAsManager();

            [$status, $body] = $I->post('/fluent-cart/v2/orders/do-bulk-action', [
                'action'    => 'delete_orders',
                'order_ids' => [$orderA, $orderB],
            ]);
        } finally {
            $restore();
        }

        Assert::assertSame(
            403,
            $status,
            'manage-only manager must be denied the bulk delete_orders action. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            'rest_forbidden',
            $body['code'] ?? '',
            'the denial must surface the rest_forbidden code. Body: ' . wp_json_encode($body)
        );

        $remainingIds = $this->remainingOrderIds();
        Assert::assertContains($orderA, $remainingIds, 'orderA must survive a denied bulk delete');
        Assert::assertContains($orderB, $remainingIds, 'orderB must survive a denied bulk delete');
    }

    /**
     * Spec: Cases/Functional/orders.md [75b]
     *   "A store manager who can manage orders but cannot delete them is rejected
     *    when bulk-deleting test-mode orders, and those orders survive."
     *
     * delete_test_orders takes no order_ids and sweeps every test-mode order in
     * the store, so the manage-only actor's blast radius here is larger than
     * delete_orders'. It must be gated by the same permission.
     */
    public function managerWithoutDeleteOrdersPermissionCannotBulkDeleteTestOrders(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'bulkperm-test-delete@example.test']);

        $testOrderId = (int) (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'mode'        => Status::ORDER_MODE_TEST,
            'invoice_no'  => 'INV-BULKPERM-TEST',
        ])->id;

        $restore = $this->stripPermissionFromRole('manager', 'orders/delete');

        try {
            $I->loginAsManager();

            [$status, $body] = $I->post('/fluent-cart/v2/orders/do-bulk-action', [
                'action' => 'delete_test_orders',
            ]);
        } finally {
            $restore();
        }

        Assert::assertSame(
            403,
            $status,
            'manage-only manager must be denied the bulk delete_test_orders action. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            'rest_forbidden',
            $body['code'] ?? '',
            'the denial must surface the rest_forbidden code. Body: ' . wp_json_encode($body)
        );

        Assert::assertContains(
            $testOrderId,
            $this->remainingOrderIds(),
            'the test-mode order must survive a denied bulk delete_test_orders'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [75c]
     *   "A store manager with the \"delete orders\" permission can still bulk-delete orders."
     *
     * The control leg: tightening the route must not break the legitimate flow.
     * The manager role grants orders/delete by default, so no shaping is applied.
     */
    public function managerWithDeleteOrdersPermissionCanStillBulkDeleteOrders(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'bulkperm-allowed@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'ALLOWED');

        $I->loginAsManager();

        [$status, $body] = $I->post('/fluent-cart/v2/orders/do-bulk-action', [
            'action'    => 'delete_orders',
            'order_ids' => [$orderId],
        ]);

        Assert::assertSame(
            200,
            $status,
            'manager holding orders/delete must still be allowed to bulk delete. Body: ' . wp_json_encode($body)
        );
        Assert::assertNotContains(
            $orderId,
            $this->remainingOrderIds(),
            'the permitted bulk delete must actually remove the order'
        );
    }
}
