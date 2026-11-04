<?php

namespace Tests\Functional\Rest\Customers;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/customers.md → "Running bulk actions on customers" section, items 30-36.
 */
class BulkCustomerActionsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/customers.md [30]
     *   "Admin can delete multiple customers in a single bulk action."
     */
    public function adminCanDeleteMultipleCustomersInSingleBulkAction(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new CustomerFactory();
        $a = $factory->create(['email' => 'bulk-del-a-' . uniqid('', true) . '@example.test']);
        $b = $factory->create(['email' => 'bulk-del-b-' . uniqid('', true) . '@example.test']);
        $survivor = $factory->create(['email' => 'bulk-del-survivor-' . uniqid('', true) . '@example.test']);

        [, $body] = $I->post('/fluent-cart/v2/customers/do-bulk-action', [
            'action'        => 'delete_customers',
            'customer_ids'  => [(int) $a->id, (int) $b->id],
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $remainingIds = array_map('intval', (array) $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}fct_customers"
        ));
        Assert::assertNotContains((int) $a->id, $remainingIds, 'customer A must be removed by the bulk delete');
        Assert::assertNotContains((int) $b->id, $remainingIds, 'customer B must be removed by the bulk delete');
        Assert::assertContains((int) $survivor->id, $remainingIds, 'unrelated customer must remain untouched by the bulk delete');
    }

    /**
     * Spec: Cases/Functional/customers.md [31]
     *   "Admin sees orders belonging to deleted customers removed alongside the customers themselves."
     */
    public function ordersOfDeletedCustomersAreRemovedAlongsideTheCustomers(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory      = new CustomerFactory();
        $orderFactory = new OrderFactory();
        $deleted     = $factory->create(['email' => 'bulk-cascade-deleted-' . uniqid('', true) . '@example.test']);
        $survivor    = $factory->create(['email' => 'bulk-cascade-survivor-' . uniqid('', true) . '@example.test']);
        $deletedOrder  = $orderFactory->create(['customer_id' => $deleted->id, 'invoice_no' => 'DEL-1']);
        $survivorOrder = $orderFactory->create(['customer_id' => $survivor->id, 'invoice_no' => 'SUR-1']);

        [, $body] = $I->post('/fluent-cart/v2/customers/do-bulk-action', [
            'action'        => 'delete_customers',
            'customer_ids'  => [(int) $deleted->id],
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $remainingOrders = array_map('intval', (array) $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}fct_orders"
        ));
        Assert::assertNotContains((int) $deletedOrder->id, $remainingOrders, 'order belonging to the deleted customer must be removed');
        Assert::assertContains((int) $survivorOrder->id, $remainingOrders, 'order belonging to a non-deleted customer must remain');
    }

    /**
     * Spec: Cases/Functional/customers.md [32]
     *   "Admin can change the status of multiple customers in a single bulk action."
     */
    public function adminCanChangeStatusOfMultipleCustomersInSingleBulkAction(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new CustomerFactory();
        $a = $factory->create(['email' => 'bulk-status-a-' . uniqid('', true) . '@example.test', 'status' => 'active']);
        $b = $factory->create(['email' => 'bulk-status-b-' . uniqid('', true) . '@example.test', 'status' => 'active']);
        $skip = $factory->create(['email' => 'bulk-status-skip-' . uniqid('', true) . '@example.test', 'status' => 'active']);

        [, $body] = $I->post('/fluent-cart/v2/customers/do-bulk-action', [
            'action'        => 'change_customer_status',
            'customer_ids'  => [(int) $a->id, (int) $b->id],
            'new_status'    => 'inactive',
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $statusA = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}fct_customers WHERE id = %d", $a->id));
        $statusB = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}fct_customers WHERE id = %d", $b->id));
        $statusSkip = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}fct_customers WHERE id = %d", $skip->id));
        Assert::assertSame('inactive', (string) $statusA, 'customer A status must change to the new bulk-action status');
        Assert::assertSame('inactive', (string) $statusB, 'customer B status must change to the new bulk-action status');
        Assert::assertSame('active', (string) $statusSkip, 'customer not selected by the bulk action must keep its original status');
    }

    /**
     * Spec: Cases/Functional/customers.md [33]
     *   "Admin sees a \"customer status updated\" notification triggered when a customer's status changes via bulk action."
     */
    public function customerStatusUpdatedNotificationFiresOnBulkStatusChange(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory  = new CustomerFactory();
        $target   = $factory->create(['email' => 'bulk-notify-' . uniqid('', true) . '@example.test', 'status' => 'active']);

        $captured = [];
        $listener = static function ($payload) use (&$captured) {
            $captured[] = [
                'customer_id' => (int) ($payload['customer']->id ?? 0),
                'old_status'  => (string) ($payload['old_status'] ?? ''),
                'new_status'  => (string) ($payload['new_status'] ?? ''),
            ];
        };
        add_action('fluent_cart/customer_status_updated', $listener, 99);

        try {
            [, $body] = $I->post('/fluent-cart/v2/customers/do-bulk-action', [
                'action'        => 'change_customer_status',
                'customer_ids'  => [(int) $target->id],
                'new_status'    => 'inactive',
            ]);

            $I->seeResponseCodeIs(200);

            $matched = array_values(array_filter($captured, static function ($entry) use ($target) {
                return $entry['customer_id'] === (int) $target->id;
            }));
            Assert::assertCount(1, $matched, 'fluent_cart/customer_status_updated must dispatch exactly once for the target customer');
            Assert::assertSame('active', $matched[0]['old_status'], 'dispatched payload must carry the prior customer status');
            Assert::assertSame('inactive', $matched[0]['new_status'], 'dispatched payload must carry the new customer status');
        } finally {
            remove_action('fluent_cart/customer_status_updated', $listener, 99);
        }
    }

    /**
     * Spec: Cases/Functional/customers.md [34]
     *   "Admin is rejected when running a bulk action with no customers selected."
     */
    public function bulkActionWithNoCustomersSelectedIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $survivor = (new CustomerFactory())->create(['email' => 'bulk-no-sel-' . uniqid('', true) . '@example.test', 'status' => 'active']);

        [, $body] = $I->post('/fluent-cart/v2/customers/do-bulk-action', [
            'action'        => 'change_customer_status',
            'customer_ids'  => [],
            'new_status'    => 'inactive',
        ]);

        $status = $I->grabResponseStatus();
        Assert::assertGreaterThanOrEqual(400, $status, 'empty selection must not return success');
        Assert::assertNotSame(200, $status, 'empty selection must not return 200');
        Assert::assertNotEmpty($body['message'] ?? '', 'WP_Error response must include a non-empty top-level message field. Body: ' . wp_json_encode($body));
        Assert::assertSame(403, (int) ($body['code'] ?? 0), 'CustomerResource emits the missing-selection rejection with WP_Error code 403; assert it stays the contract clients see in body.code. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsStringIgnoringCase('select', (string) $body['message'], 'rejection message must mention the missing selection');

        global $wpdb;
        $statusVal = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}fct_customers WHERE id = %d", $survivor->id));
        Assert::assertSame('active', (string) $statusVal, 'no customer status may change when the bulk action has empty selection');
    }

    /**
     * Spec: Cases/Functional/customers.md [35]
     *   "Admin is rejected when running a bulk action with an unknown action name."
     */
    public function bulkActionWithUnknownActionNameIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $survivor = (new CustomerFactory())->create(['email' => 'bulk-bad-action-' . uniqid('', true) . '@example.test', 'status' => 'active']);

        [, $body] = $I->post('/fluent-cart/v2/customers/do-bulk-action', [
            'action'        => 'totally_unknown_action_xyz',
            'customer_ids'  => [(int) $survivor->id],
        ]);

        $status = $I->grabResponseStatus();
        Assert::assertGreaterThanOrEqual(400, $status, 'unknown action name must not return success');
        Assert::assertNotSame(200, $status, 'unknown action name must not return 200');
        Assert::assertNotEmpty($body['message'] ?? '', 'WP_Error response must include a non-empty top-level message field. Body: ' . wp_json_encode($body));
        Assert::assertSame(400, (int) ($body['code'] ?? 0), 'CustomerResource emits the unknown-action rejection with WP_Error code 400; assert it stays the contract clients see in body.code. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsStringIgnoringCase('invalid', (string) $body['message'], 'rejection message must indicate the action is invalid');

        global $wpdb;
        $statusVal = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}fct_customers WHERE id = %d", $survivor->id));
        Assert::assertSame('active', (string) $statusVal, 'no side effects may persist when the bulk action name is unknown');
        $row = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fct_customers WHERE id = %d", $survivor->id));
        Assert::assertSame(1, (int) $row, 'unknown action must not delete the targeted customer either');
    }

    /**
     * Spec: Cases/Functional/customers.md [36]
     *   "Admin is rejected when changing customers to a status that does not exist."
     */
    public function bulkActionChangingToNonexistentStatusIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $target = (new CustomerFactory())->create(['email' => 'bulk-bad-status-' . uniqid('', true) . '@example.test', 'status' => 'active']);

        [, $body] = $I->post('/fluent-cart/v2/customers/do-bulk-action', [
            'action'        => 'change_customer_status',
            'customer_ids'  => [(int) $target->id],
            'new_status'    => 'totally_unknown_status_xyz',
        ]);

        $status = $I->grabResponseStatus();
        Assert::assertGreaterThanOrEqual(400, $status, 'invalid new_status must not return success');
        Assert::assertNotSame(200, $status, 'invalid new_status must not return 200');
        Assert::assertNotEmpty($body['message'] ?? '', 'WP_Error response must include a non-empty top-level message field. Body: ' . wp_json_encode($body));
        Assert::assertSame(403, (int) ($body['code'] ?? 0), 'CustomerResource emits the invalid-status rejection with WP_Error code 403; assert it stays the contract clients see in body.code. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsStringIgnoringCase('valid', (string) $body['message'], 'rejection message must indicate the status is not valid');

        global $wpdb;
        $statusVal = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}fct_customers WHERE id = %d", $target->id));
        Assert::assertSame('active', (string) $statusVal, 'targeted customer status must remain unchanged when new_status is invalid');
    }
}
