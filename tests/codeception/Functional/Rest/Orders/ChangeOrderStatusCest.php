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
 * Spec: Cases/Functional/orders.md → "Updating order statuses" section, items 44-48 and 118.
 */
class ChangeOrderStatusCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/orders.md [44]
     *   "Admin can change an order's overall status or shipping status through the dedicated statuses action; the payment-status action is clearly rejected because payment transitions flow through their dedicated endpoints."
     *
     * Contract decision 2026-08-06 (audit item #40, issue #2447): the statuses
     * endpoint is order/shipping-only. Payment-status transitions have their
     * own safer surfaces — mark-as-paid, transaction status updates, refunds,
     * and gateway webhooks — where money state stays consistent with
     * transactions. change_payment_status is therefore rejected explicitly
     * instead of falling into the generic trailing failure.
     */
    public function adminChangesOrderAndShippingStatusWhilePaymentStatusActionIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'change-status-suite@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_ON_HOLD,
            'payment_status'   => Status::PAYMENT_PENDING,
            'shipping_status'  => Status::SHIPPING_UNSHIPPED,
            'fulfillment_type' => 'physical',
            'invoice_no'       => 'INV-CHANGE-STATUSES',
        ]);

        // -- Change overall order status: on-hold → processing --
        [, $orderBody] = $I->put('/fluent-cart/v2/orders/' . (int) $order->id . '/statuses', [
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_PROCESSING],
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $afterOrder = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT status FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id)
        );
        Assert::assertSame(
            Status::ORDER_PROCESSING,
            $afterOrder,
            'order status must persist as processing after change_order_status. Body: ' . wp_json_encode($orderBody)
        );

        // -- Change shipping status: unshipped → shipped --
        [, $shipBody] = $I->put('/fluent-cart/v2/orders/' . (int) $order->id . '/statuses', [
            'action'   => 'change_shipping_status',
            'statuses' => ['shipping_status' => Status::SHIPPING_SHIPPED],
        ]);

        $I->seeResponseCodeIs(200);

        $afterShipping = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT shipping_status FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id)
        );
        Assert::assertSame(
            Status::SHIPPING_SHIPPED,
            $afterShipping,
            'shipping_status must persist as shipped after change_shipping_status. Body: ' . wp_json_encode($shipBody)
        );

        $afterOrderUnchanged = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT status FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id)
        );
        Assert::assertSame(
            Status::ORDER_PROCESSING,
            $afterOrderUnchanged,
            'order status must remain processing after a shipping-only status change'
        );

        // -- Payment status is NOT this endpoint's surface: clear rejection, no write --
        [$payStatus, $payBody] = $I->put('/fluent-cart/v2/orders/' . (int) $order->id . '/statuses', [
            'action'   => 'change_payment_status',
            'statuses' => ['payment_status' => Status::PAYMENT_PAID],
        ]);

        Assert::assertSame(
            400,
            $payStatus,
            'change_payment_status must be rejected with 400 — payment transitions flow through their dedicated endpoints. Body: ' . wp_json_encode($payBody)
        );
        Assert::assertStringContainsString(
            'order or shipping status only',
            strtolower((string) ($payBody['message'] ?? '')),
            'rejection must name the supported surface instead of the generic trailing failure. Body: ' . wp_json_encode($payBody)
        );

        $afterPayment = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT payment_status FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id)
        );
        Assert::assertSame(
            Status::PAYMENT_PENDING,
            $afterPayment,
            'payment_status must stay pending — the rejected action must not write payment state'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [45]
     *   "Admin sees an \"order status updated\" notification triggered when the order status changes."
     */
    public function adminSeesOrderStatusUpdatedNotificationTriggeredWhenOrderStatusChanges(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'order-status-event@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_ON_HOLD,
            'fulfillment_type' => 'physical',
            'invoice_no'       => 'INV-STATUS-EVENT',
        ]);

        // OrderStatusUpdated::autoFireHook is false, so the public-facing notification is the
        // type-specific `fluent_cart/order_status_changed` action dispatched in afterDispatch().
        $captured = [];
        $listener = function ($payload) use (&$captured) {
            $captured[] = $payload;
        };
        add_action('fluent_cart/order_status_changed', $listener, 10, 1);

        try {
            [, $body] = $I->put('/fluent-cart/v2/orders/' . (int) $order->id . '/statuses', [
                'action'   => 'change_order_status',
                'statuses' => ['order_status' => Status::ORDER_PROCESSING],
            ]);
        } finally {
            remove_action('fluent_cart/order_status_changed', $listener, 10);
        }

        $I->seeResponseCodeIs(200);

        Assert::assertGreaterThanOrEqual(
            1,
            count($captured),
            'fluent_cart/order_status_changed action must fire at least once when the order status changes. Captured: ' . count($captured)
        );

        $firstPayload = $captured[0];
        Assert::assertIsArray($firstPayload, 'order_status_changed event payload must be an array');
        Assert::assertArrayHasKey('order', $firstPayload, 'order_status_changed event payload must include the order key');
        Assert::assertArrayHasKey('new_status', $firstPayload, 'order_status_changed event payload must include the new_status key');
        Assert::assertArrayHasKey('old_status', $firstPayload, 'order_status_changed event payload must include the old_status key');
        Assert::assertSame(
            (int) $order->id,
            (int) ($firstPayload['order']->id ?? 0),
            'order_status_changed event order id must match the target order. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            Status::ORDER_ON_HOLD,
            (string) $firstPayload['old_status'],
            'order_status_changed event old_status must reflect the on-hold starting state'
        );
        Assert::assertSame(
            Status::ORDER_PROCESSING,
            (string) $firstPayload['new_status'],
            'order_status_changed event new_status must reflect the requested processing state'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [46]
     *   "Admin sees an order's completion timestamp recorded automatically when the order moves to \"completed\"."
     */
    public function adminSeesOrderCompletionTimestampRecordedAutomaticallyWhenMovedToCompleted(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'completed-at@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_PROCESSING,
            'fulfillment_type' => 'physical',
            'invoice_no'       => 'INV-COMPLETED-AT',
        ]);

        global $wpdb;
        $beforeCompletedAt = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT completed_at FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id)
        );
        Assert::assertEmpty(
            $beforeCompletedAt,
            'precondition: completed_at must be empty before the order moves to completed (got: ' . $beforeCompletedAt . ')'
        );

        $beforeUtc = gmdate('Y-m-d H:i:s');

        [, $body] = $I->put('/fluent-cart/v2/orders/' . (int) $order->id . '/statuses', [
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_COMPLETED],
        ]);

        $afterUtc = gmdate('Y-m-d H:i:s', time() + 5);

        $I->seeResponseCodeIs(200);

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT status, completed_at FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                (int) $order->id
            ),
            ARRAY_A
        );
        Assert::assertNotEmpty($row, 'order row must still exist after change_order_status. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            Status::ORDER_COMPLETED,
            (string) $row['status'],
            'order status must persist as completed after change_order_status'
        );
        Assert::assertNotEmpty(
            (string) $row['completed_at'],
            'completed_at must be auto-recorded when the order moves to completed (got empty)'
        );
        Assert::assertGreaterThanOrEqual(
            $beforeUtc,
            (string) $row['completed_at'],
            'completed_at (' . (string) $row['completed_at'] . ') must be at or after the GMT timestamp captured immediately before the request (' . $beforeUtc . '), proving GMT not local-time'
        );
        Assert::assertLessThanOrEqual(
            $afterUtc,
            (string) $row['completed_at'],
            'completed_at (' . (string) $row['completed_at'] . ') must be at or before the GMT timestamp captured +5s after the request (' . $afterUtc . ')'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [47]
     *   "Admin sees an order's refund timestamp recorded automatically when the order is
     *    fully refunded through the refund action."
     *
     * Refunds go through POST /orders/{order_id}/refund; the transaction-status action
     * refuses to change a succeeded charge.
     */
    public function adminSeesOrderRefundTimestampRecordedAutomaticallyWhenFullyRefundedThroughTheRefundAction(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'refunded-at@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => Helper::toCent(40),
            'total_amount'   => Helper::toCent(40),
            'total_paid'     => Helper::toCent(40),
            'invoice_no'     => 'INV-REFUNDED-AT',
        ]);
        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => Helper::toCent(40),
        ]);

        global $wpdb;
        $beforeRefundedAt = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT refunded_at FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id)
        );
        Assert::assertEmpty(
            $beforeRefundedAt,
            'precondition: refunded_at must be empty before the order is refunded (got: ' . $beforeRefundedAt . ')'
        );

        $beforeUtc = gmdate('Y-m-d H:i:s');

        // Full refund (cents) through the refund action; Order::syncOrderAfterRefund('full')
        // moves the order to refunded and stamps refunded_at = gmtNow().
        [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund', [
            'refund_info' => [
                'transaction_id' => (int) $transaction->id,
                'amount'         => Helper::toCent(40),
                'note'           => 'full refund for refunded_at spec',
            ],
        ]);

        $afterUtc = gmdate('Y-m-d H:i:s', time() + 5);

        $I->seeResponseCodeIs(200);

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT payment_status, refunded_at FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                (int) $order->id
            ),
            ARRAY_A
        );
        Assert::assertNotEmpty($row, 'order row must still exist after the refund. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            Status::PAYMENT_REFUNDED,
            (string) $row['payment_status'],
            'payment_status must persist as refunded after a full refund through the refund action. Body: ' . wp_json_encode($body)
        );
        Assert::assertNotEmpty(
            (string) $row['refunded_at'],
            'refunded_at must be auto-recorded when the order moves to refunded (got empty)'
        );
        Assert::assertGreaterThanOrEqual(
            $beforeUtc,
            (string) $row['refunded_at'],
            'refunded_at (' . (string) $row['refunded_at'] . ') must be at or after the GMT timestamp captured immediately before the request (' . $beforeUtc . '), proving GMT not local-time'
        );
        Assert::assertLessThanOrEqual(
            $afterUtc,
            (string) $row['refunded_at'],
            'refunded_at (' . (string) $row['refunded_at'] . ') must be at or before the GMT timestamp captured +5s after the request (' . $afterUtc . ')'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [48]
     *   "Admin can resync an order's status from its latest payment when the two are out of step."
     */
    public function adminCanResyncOrderStatusFromLatestPaymentWhenOutOfStep(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'sync-statuses@example.test']);

        // Order is on_hold + pending, but a succeeded transaction exists for the full amount.
        // sync-statuses must reconcile: payment_status → paid; status → processing.
        $order = (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_ON_HOLD,
            'payment_status'   => Status::PAYMENT_PENDING,
            'fulfillment_type' => 'physical',
            'subtotal'         => Helper::toCent(80),
            'total_amount'     => Helper::toCent(80),
            'total_paid'       => 0,
            'invoice_no'       => 'INV-SYNC-STATUSES',
        ]);
        (new OrderTransactionFactory())->create((int) $order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => Helper::toCent(80),
        ]);

        [, $body] = $I->put('/fluent-cart/v2/orders/' . (int) $order->id . '/sync-statuses', []);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(
            Status::PAYMENT_PAID,
            (string) ($body['payment_status'] ?? ''),
            'sync-statuses response must report payment_status=paid for an order with a fully-succeeded transaction. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            Status::ORDER_PROCESSING,
            (string) ($body['status'] ?? ''),
            'sync-statuses response must report status=processing for a previously-on-hold order with a fully-paid transaction. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT status, payment_status, total_paid FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                (int) $order->id
            ),
            ARRAY_A
        );
        Assert::assertSame(
            Status::PAYMENT_PAID,
            (string) $row['payment_status'],
            'persisted payment_status must reflect the resynced paid state'
        );
        Assert::assertSame(
            Status::ORDER_PROCESSING,
            (string) $row['status'],
            'persisted status must reflect the resynced processing state'
        );
        Assert::assertSame(
            Helper::toCent(80),
            (int) $row['total_paid'],
            'persisted total_paid must reflect the sum of succeeded transactions in cents (8000)'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [118]
     *   "Admin trying to change the status of an already-canceled order is rejected with a client error, not a server error."
     */
    public function changingStatusOfCanceledOrderIsRejectedWith400(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'canceled-status@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_CANCELED,
            'invoice_no'  => 'INV-CANCELED-STATUS',
        ]);

        [$status, $body] = $I->put('/fluent-cart/v2/orders/' . (int) $order->id . '/statuses', [
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_PROCESSING],
        ]);

        Assert::assertSame(400, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(
            'You cannot change the order status once it has been canceled.',
            (string) ($body['message'] ?? '')
        );
    }
}
