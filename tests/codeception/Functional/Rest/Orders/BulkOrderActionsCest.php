<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/orders.md → "Running bulk actions on orders" section, items 68-75.
 */
class BulkOrderActionsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/orders.md [68]
     *   "Admin can delete multiple orders in a single bulk action."
     */
    public function adminCanDeleteMultipleOrdersInSingleBulkAction(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'bulk-delete@example.test']);

        // Free orders are deletable in any state per Order::canBeDeleted (total_amount=0).
        $orderA = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => 0,
            'total_amount'   => 0,
            'invoice_no'     => 'INV-BULK-DEL-A',
        ]);
        $orderB = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => 0,
            'total_amount'   => 0,
            'invoice_no'     => 'INV-BULK-DEL-B',
        ]);
        $survivor = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => 0,
            'total_amount'   => 0,
            'invoice_no'     => 'INV-BULK-DEL-SURVIVOR',
        ]);

        [, $body] = $I->post('/fluent-cart/v2/orders/do-bulk-action', [
            'action'    => 'delete_orders',
            'order_ids' => [(int) $orderA->id, (int) $orderB->id],
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $remainingIds = array_map('intval', (array) $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}fct_orders ORDER BY id ASC"
        ));
        Assert::assertNotContains((int) $orderA->id, $remainingIds, 'orderA must be deleted by the bulk delete. Body: ' . wp_json_encode($body));
        Assert::assertNotContains((int) $orderB->id, $remainingIds, 'orderB must be deleted by the bulk delete');
        Assert::assertContains((int) $survivor->id, $remainingIds, 'unselected survivor order must remain');
    }

    /**
     * Spec: Cases/Functional/orders.md [69]
     *   "Admin is rejected when running the retired bulk order-status action, and no order status changes."
     *
     * Production commit ce143b202 (2026-07-10) removed the change_order_status /
     * change_shipping_status / change_payment_status branches from
     * OrderController::handleBulkActions together with the orphaned _BulkAction.vue that
     * was their only caller. The live bulk bar offers delete_orders / delete_test_orders /
     * capture_payments only, so the retired names now fall through to the invalid-action
     * guard at OrderController.php:822. This test pins that retirement: the endpoint must
     * reject the old name AND leave every selected order untouched — a silent re-add of an
     * unvalidated status-writing branch would fail here.
     */
    public function adminIsRejectedWhenRunningRetiredBulkChangeOrderStatusAction(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'bulk-status@example.test']);

        $orderA = (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_ON_HOLD,
            'fulfillment_type' => 'physical',
            'invoice_no'       => 'INV-BULK-STATUS-A',
        ]);
        $orderB = (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_ON_HOLD,
            'fulfillment_type' => 'physical',
            'invoice_no'       => 'INV-BULK-STATUS-B',
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/do-bulk-action', [
            'action'     => 'change_order_status',
            'order_ids'  => [(int) $orderA->id, (int) $orderB->id],
            'new_status' => Status::ORDER_PROCESSING,
        ]);

        Assert::assertSame(
            422,
            $status,
            'the retired change_order_status bulk action must be rejected with the framework client-error code 422. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'invalid',
            strtolower((string) ($body['message'] ?? '')),
            'rejection message must indicate the chosen action is invalid. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $orderAStatus = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT status FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $orderA->id)
        );
        $orderBStatus = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT status FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $orderB->id)
        );

        Assert::assertSame(Status::ORDER_ON_HOLD, $orderAStatus, 'orderA status must stay on_hold — the retired action must not write any order status');
        Assert::assertSame(Status::ORDER_ON_HOLD, $orderBStatus, 'orderB status must stay on_hold — the retired action must not write any order status');
    }

    /**
     * Spec: Cases/Functional/orders.md [70]
     *   "Admin is rejected when running the retired bulk payment-status action, and no order payment status changes."
     *
     * Same retirement as [69] (commit ce143b202) for the payment-status branch. Seeded with
     * paid orders plus their succeeded transactions so a re-added branch would have real
     * money state to corrupt.
     */
    public function adminIsRejectedWhenRunningRetiredBulkChangePaymentStatusAction(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'bulk-payment-status@example.test']);

        $orderA = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => Helper::toCent(40),
            'total_amount'   => Helper::toCent(40),
            'total_paid'     => Helper::toCent(40),
            'invoice_no'     => 'INV-BULK-PAY-A',
        ]);
        (new OrderTransactionFactory())->create((int) $orderA->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => Helper::toCent(40),
        ]);

        $orderB = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => Helper::toCent(60),
            'total_amount'   => Helper::toCent(60),
            'total_paid'     => Helper::toCent(60),
            'invoice_no'     => 'INV-BULK-PAY-B',
        ]);
        (new OrderTransactionFactory())->create((int) $orderB->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => Helper::toCent(60),
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/do-bulk-action', [
            'action'     => 'change_payment_status',
            'order_ids'  => [(int) $orderA->id, (int) $orderB->id],
            'new_status' => Status::PAYMENT_REFUNDED,
        ]);

        Assert::assertSame(
            422,
            $status,
            'the retired change_payment_status bulk action must be rejected with the framework client-error code 422. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'invalid',
            strtolower((string) ($body['message'] ?? '')),
            'rejection message must indicate the chosen action is invalid. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $orderAPayment = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT payment_status FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $orderA->id)
        );
        $orderBPayment = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT payment_status FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $orderB->id)
        );
        $orderARefundedAt = $wpdb->get_var(
            $wpdb->prepare("SELECT refunded_at FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $orderA->id)
        );

        Assert::assertSame(Status::PAYMENT_PAID, $orderAPayment, 'orderA payment_status must stay paid — the retired action must not write any payment status');
        Assert::assertSame(Status::PAYMENT_PAID, $orderBPayment, 'orderB payment_status must stay paid — the retired action must not write any payment status');
        Assert::assertNull($orderARefundedAt, 'orderA refunded_at must stay unset — the retired action must not stamp a refund date');
    }

    /**
     * Spec: Cases/Functional/orders.md [71]
     *   "Admin is rejected when running the retired bulk capture-payments action, and no order changes."
     *
     * The capture_payments branch was removed 2026-08-06 (audit item #43,
     * issue #2446): it called $order->capturePayments(), a method that has
     * never existed anywhere in the codebase, so the action fataled on the
     * first order. No UI sends it — the orders bulk bar submits
     * delete_test_orders only. Like [69]/[70], this pins the retirement:
     * the endpoint must reject the name AND leave every selected order and
     * transaction untouched. Bulk payment capture, if built later, is a
     * gateway feature (authorize/capture per gateway) with its own spec.
     */
    public function adminIsRejectedWhenRunningRetiredBulkCapturePaymentsAction(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'bulk-capture@example.test']);

        $orderA = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => Helper::toCent(20),
            'total_amount'   => Helper::toCent(20),
            'invoice_no'     => 'INV-BULK-CAPTURE-A',
        ]);
        // Capture-ELIGIBLE fixture (round-5 gate finding): a Stripe transaction
        // with an authorized vendor charge. Offline-payment defaults could not
        // discriminate "action is unrecognized" from a revived branch that
        // merely rejects gateways it cannot capture.
        (new OrderTransactionFactory())->create((int) $orderA->id, [
            'status'              => Status::TRANSACTION_PENDING,
            'total'               => Helper::toCent(20),
            'payment_method'      => 'stripe',
            'payment_method_type' => 'card',
            'vendor_charge_id'    => 'pi_bulk_capture_a_authorized',
        ]);

        $orderB = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => Helper::toCent(30),
            'total_amount'   => Helper::toCent(30),
            'invoice_no'     => 'INV-BULK-CAPTURE-B',
        ]);
        (new OrderTransactionFactory())->create((int) $orderB->id, [
            'status'              => Status::TRANSACTION_PENDING,
            'total'               => Helper::toCent(30),
            'payment_method'      => 'stripe',
            'payment_method_type' => 'card',
            'vendor_charge_id'    => 'pi_bulk_capture_b_authorized',
        ]);

        // Full pre-call snapshot: the retirement contract is "touches NOTHING",
        // so the oracle must cover the columns a capture implementation would
        // write (payment_status, total_paid, transaction status/total), not
        // just the general order status.
        global $wpdb;
        $snapshotRows = function () use ($wpdb, $orderA, $orderB) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT o.id, o.status, o.payment_status, o.total_paid,
                        t.id AS txn_id, t.status AS txn_status, t.total AS txn_total
                 FROM {$wpdb->prefix}fct_orders o
                 LEFT JOIN {$wpdb->prefix}fct_order_transactions t ON t.order_id = o.id
                 WHERE o.id IN (%d, %d)
                 ORDER BY o.id, t.id",
                (int) $orderA->id,
                (int) $orderB->id
            ), ARRAY_A);
        };
        $before = $snapshotRows();
        Assert::assertCount(2, $before, 'snapshot must cover both seeded orders with one pending transaction each');

        // A capture implementation would have to call the gateway; the retired
        // action must be rejected BEFORE any external HTTP leaves the process.
        $externalCalls = [];
        $httpSpy = function ($preempt, $args, $url) use (&$externalCalls) {
            $externalCalls[] = $url;
            return $preempt;
        };
        add_filter('pre_http_request', $httpSpy, 10, 3);

        try {
            [$status, $body] = $I->post('/fluent-cart/v2/orders/do-bulk-action', [
                'action'    => 'capture_payments',
                'order_ids' => [(int) $orderA->id, (int) $orderB->id],
            ]);
        } finally {
            remove_filter('pre_http_request', $httpSpy, 10);
        }

        Assert::assertSame(
            422,
            $status,
            'the retired capture_payments bulk action must be rejected with the framework client-error code 422, not fatal with a 500. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            'Selected action is invalid',
            (string) ($body['message'] ?? ''),
            'rejection must be the canonical unrecognized-action message (OrderController::handleBulkActions '
            . 'fallthrough) — a gateway-validation rejection like "Invalid payment method" would mean the '
            . 'action was RECOGNIZED, i.e. the retired branch came back. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            [],
            $externalCalls,
            'the rejected action must make zero outbound HTTP requests — no gateway may be contacted'
        );
        Assert::assertSame(
            $before,
            $snapshotRows(),
            'every order column a capture would write (status, payment_status, total_paid) and every '
            . 'transaction row (status, total) must be byte-identical to the pre-call snapshot'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [72]
     *   "Admin can clean up test-mode orders in paginated batches."
     */
    public function adminCanCleanUpTestModeOrdersInPaginatedBatches(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'bulk-test-cleanup@example.test']);

        // Seed three test-mode orders. Test orders are deletable regardless of status because
        // canBeDeleted's success-status guard only applies in non-test mode.
        $testOrderIds = [];
        for ($i = 0; $i < 3; $i++) {
            $testOrder = (new OrderFactory())->create([
                'customer_id' => $customer->id,
                'mode'        => Status::ORDER_MODE_TEST,
                'invoice_no'  => 'INV-BULK-TEST-' . $i,
            ]);
            $testOrderIds[] = (int) $testOrder->id;
        }

        // Force batch size = 2 via the documented filter so the response surfaces has_more=true.
        $batchFilter = function ($size) {
            return 2;
        };
        add_filter('fluent_cart/order/delete_test_orders_batch_size', $batchFilter, 10, 1);

        try {
            [, $body] = $I->post('/fluent-cart/v2/orders/do-bulk-action', [
                'action' => 'delete_test_orders',
            ]);
        } finally {
            remove_filter('fluent_cart/order/delete_test_orders_batch_size', $batchFilter, 10);
        }

        $I->seeResponseCodeIs(200);

        // delete_test_orders flattens its fields onto the response root rather than nesting
        // them under a "data" key.
        Assert::assertSame(2, (int) ($body['batch_size'] ?? 0), 'response batch_size must reflect the filtered batch size of 2. Body: ' . wp_json_encode($body));
        Assert::assertSame(2, (int) ($body['batch_count'] ?? 0), 'response batch_count must reflect the 2 orders attempted in this batch');
        Assert::assertSame(2, (int) ($body['deleted_count'] ?? 0), 'response deleted_count must reflect the 2 orders actually deleted in this batch');
        Assert::assertSame(3, (int) ($body['total_count'] ?? 0), 'response total_count must reflect all test orders remaining at the start of this batch');
        Assert::assertTrue(
            (bool) ($body['has_more'] ?? false),
            'response has_more must be true when more test orders remain beyond the current batch (3 seeded, batch size 2)'
        );

        global $wpdb;
        $remainingTestOrders = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE mode = %s",
                Status::ORDER_MODE_TEST
            )
        );
        Assert::assertSame(
            1,
            $remainingTestOrders,
            'after one batch of 2, exactly 1 test order must remain in fct_orders (3 seeded - 2 deleted)'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [73]
     *   "Admin is rejected when running a bulk action without selecting any orders."
     */
    public function adminIsRejectedWhenRunningBulkActionWithoutSelectingAnyOrders(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/orders/do-bulk-action', [
            'action'    => 'delete_orders',
            'order_ids' => [],
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertSame(
            422,
            $status,
            'bulk action without order_ids must be rejected with the framework client-error code 422. Body: ' . wp_json_encode($body)
        );
        $message = (string) ($body['message'] ?? '');
        Assert::assertStringContainsString(
            'selection is required',
            strtolower($message),
            'rejection message must explain that an order selection is required. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [74]
     *   "Admin is rejected when running a bulk action with an unknown action name."
     */
    public function adminIsRejectedWhenRunningBulkActionWithUnknownActionName(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'bulk-unknown-action@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_ON_HOLD,
            'invoice_no'  => 'INV-BULK-UNKNOWN-ACTION',
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/do-bulk-action', [
            'action'    => 'definitely_not_a_real_bulk_action',
            'order_ids' => [(int) $order->id],
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertSame(
            422,
            $status,
            'bulk action with an unknown action name must be rejected with 422. Body: ' . wp_json_encode($body)
        );
        $message = (string) ($body['message'] ?? '');
        Assert::assertStringContainsString(
            'invalid',
            strtolower($message),
            'rejection message must indicate the chosen action is invalid. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $persistedStatus = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT status FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id)
        );
        Assert::assertSame(
            Status::ORDER_ON_HOLD,
            $persistedStatus,
            'rejected unknown-action bulk request must NOT mutate any order state'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [75]
     *   "Admin bulk-deleting a mix of deletable and undeletable orders deletes the deletable
     *    ones and is told which ones could not be deleted."
     */
    public function adminBulkDeletingMixOfDeletableAndUndeletableOrdersDeletesOnlyDeletableOnes(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'bulk-partial-delete@example.test']);

        // Free + on_hold passes Order::canBeDeleted (app/Models/Order.php:1179-1197).
        $deletable = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => 0,
            'total_amount'   => 0,
            'invoice_no'     => 'INV-BULK-PARTIAL-OK',
        ]);
        // Processing + paid fails the same guard's order-status branch (Order.php:1199-1208).
        $undeletable = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => Helper::toCent(35),
            'total_amount'   => Helper::toCent(35),
            'total_paid'     => Helper::toCent(35),
            'invoice_no'     => 'INV-BULK-PARTIAL-BLOCKED',
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/do-bulk-action', [
            'action'    => 'delete_orders',
            'order_ids' => [(int) $deletable->id, (int) $undeletable->id],
        ]);

        Assert::assertSame(
            200,
            $status,
            'a partially blocked bulk delete still succeeds for the deletable orders. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            [(int) $deletable->id],
            array_map('intval', (array) ($body['data']['deleted_order_ids'] ?? [])),
            'response must report exactly the deletable order as deleted. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            [(int) $undeletable->id],
            array_map('intval', (array) ($body['data']['failed_order_ids'] ?? [])),
            'response must report exactly the undeletable order as failed. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(1, (int) ($body['data']['deleted_count'] ?? 0), 'deleted_count must be 1');
        Assert::assertSame(1, (int) ($body['data']['failed_count'] ?? 0), 'failed_count must be 1');
        Assert::assertStringContainsString(
            (string) $undeletable->id,
            (string) ($body['message'] ?? ''),
            'the message must name the order ID that could not be deleted. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $remainingIds = array_map('intval', (array) $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}fct_orders ORDER BY id ASC"
        ));
        Assert::assertNotContains((int) $deletable->id, $remainingIds, 'the deletable order must be gone from fct_orders');
        Assert::assertContains((int) $undeletable->id, $remainingIds, 'the undeletable paid order must survive the bulk delete');

        $survivorStatus = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT status FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $undeletable->id)
        );
        Assert::assertSame(Status::ORDER_PROCESSING, $survivorStatus, 'the surviving order must keep its processing status');
    }
}
