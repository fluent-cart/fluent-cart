<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/orders.md → "Marking an order as paid" section, items 39-43.
 */
class MarkOrderPaidCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/orders.md [39]
     *   "Admin can mark a pending offline-payment order as paid, and the order moves into processing."
     */
    public function adminCanMarkPendingOfflinePaymentOrderAsPaidAndOrderMovesIntoProcessing(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'mark-paid-physical@example.test']);

        // Physical fulfillment so the controller does NOT auto-advance to completed; the
        // spec for #39 asserts the order moves into processing (not completed).
        $order = (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_ON_HOLD,
            'payment_status'   => Status::PAYMENT_PENDING,
            'payment_method'   => 'offline_payment',
            'fulfillment_type' => 'physical',
            'subtotal'         => Helper::toCent(100),
            'total_amount'     => Helper::toCent(100),
            'total_paid'       => 0,
            'invoice_no'       => 'INV-MARK-PAID-PHYS',
        ]);

        [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/mark-as-paid', [
            'payment_method'  => 'offline_payment',
            'transaction_type' => 'charge',
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT status, payment_status, total_paid FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                (int) $order->id
            ),
            ARRAY_A
        );
        Assert::assertNotEmpty($row, 'order row must still exist after mark-as-paid. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            Status::ORDER_PROCESSING,
            (string) $row['status'],
            'order status must move to processing after marking a pending physical order as paid. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            Status::PAYMENT_PAID,
            (string) $row['payment_status'],
            'order payment_status must be paid after mark-as-paid'
        );
        Assert::assertSame(
            Helper::toCent(100),
            (int) $row['total_paid'],
            'total_paid must equal the order total in cents after mark-as-paid (10000)'
        );

        $txnRow = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT total, status, payment_method FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d AND status = %s ORDER BY id DESC LIMIT 1",
                (int) $order->id,
                Status::TRANSACTION_SUCCEEDED
            ),
            ARRAY_A
        );
        Assert::assertNotEmpty(
            $txnRow,
            'mark-as-paid must persist or update at least one succeeded transaction for the order'
        );
        Assert::assertSame(
            Helper::toCent(100),
            (int) $txnRow['total'],
            'succeeded transaction total must equal the order due amount in cents (10000)'
        );
        Assert::assertSame(
            'offline_payment',
            (string) $txnRow['payment_method'],
            'succeeded transaction must carry the offline_payment payment method submitted in the request'
        );
        Assert::assertSame(
            Status::TRANSACTION_SUCCEEDED,
            (string) $txnRow['status'],
            'succeeded transaction status must be persisted as succeeded'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [40]
     *   "Admin is rejected from marking an order as paid when its due amount is already settled."
     */
    public function adminIsRejectedFromMarkingOrderAsPaidWhenDueAmountAlreadySettled(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'mark-paid-already@example.test']);

        // total_paid == total_amount → due amount is 0; controller must reject with 423.
        $order = (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_PROCESSING,
            'payment_status'   => Status::PAYMENT_PAID,
            'payment_method'   => 'offline_payment',
            'fulfillment_type' => 'physical',
            'subtotal'         => Helper::toCent(50),
            'total_amount'     => Helper::toCent(50),
            'total_paid'       => Helper::toCent(50),
            'invoice_no'       => 'INV-MARK-PAID-ALREADY',
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/mark-as-paid', [
            'payment_method' => 'offline_payment',
        ]);

        Assert::assertSame(
            423,
            $status,
            'mark-as-paid on a fully-settled order must be rejected with 423. Body: ' . wp_json_encode($body)
        );
        $message = (string) ($body['message'] ?? '');
        Assert::assertNotEmpty($message, 'rejection body must carry a human-readable message');
        Assert::assertStringContainsString(
            'already',
            strtolower($message),
            'rejection message must explain the order is already paid. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $persisted = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT status, payment_status, total_paid FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                (int) $order->id
            ),
            ARRAY_A
        );
        Assert::assertSame(
            Status::ORDER_PROCESSING,
            (string) $persisted['status'],
            'rejected mark-as-paid must NOT mutate order status'
        );
        Assert::assertSame(
            Status::PAYMENT_PAID,
            (string) $persisted['payment_status'],
            'rejected mark-as-paid must NOT mutate payment_status'
        );
        Assert::assertSame(
            Helper::toCent(50),
            (int) $persisted['total_paid'],
            'rejected mark-as-paid must NOT mutate total_paid'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [41]
     *   "Admin is rejected when marking a canceled order as paid."
     */
    public function adminIsRejectedWhenMarkingCanceledOrderAsPaid(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'mark-paid-canceled@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_CANCELED,
            'payment_status'   => Status::PAYMENT_PENDING,
            'payment_method'   => 'offline_payment',
            'fulfillment_type' => 'physical',
            'subtotal'         => Helper::toCent(75),
            'total_amount'     => Helper::toCent(75),
            'total_paid'       => 0,
            'invoice_no'       => 'INV-MARK-PAID-CANCELED',
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/mark-as-paid', [
            'payment_method' => 'offline_payment',
        ]);

        Assert::assertSame(
            423,
            $status,
            'mark-as-paid on a canceled order must be rejected with 423. Body: ' . wp_json_encode($body)
        );
        $message = (string) ($body['message'] ?? '');
        Assert::assertNotEmpty($message, 'rejection body must carry a human-readable message');
        Assert::assertStringContainsString(
            'canceled',
            strtolower($message),
            'rejection message must mention the canceled state. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $persisted = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT status, payment_status, total_paid FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                (int) $order->id
            ),
            ARRAY_A
        );
        Assert::assertSame(
            Status::ORDER_CANCELED,
            (string) $persisted['status'],
            'rejected mark-as-paid must keep canceled order status'
        );
        Assert::assertSame(
            Status::PAYMENT_PENDING,
            (string) $persisted['payment_status'],
            'rejected mark-as-paid must NOT mutate payment_status on a canceled order'
        );
        Assert::assertSame(
            0,
            (int) $persisted['total_paid'],
            'rejected mark-as-paid must NOT change total_paid on a canceled order'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [42]
     *   "Admin sees an \"order paid\" notification triggered when an order is marked as paid."
     */
    public function adminSeesOrderPaidNotificationTriggeredWhenOrderMarkedAsPaid(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'mark-paid-event@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_ON_HOLD,
            'payment_status'   => Status::PAYMENT_PENDING,
            'payment_method'   => 'offline_payment',
            'fulfillment_type' => 'physical',
            'subtotal'         => Helper::toCent(45),
            'total_amount'     => Helper::toCent(45),
            'total_paid'       => 0,
            'invoice_no'       => 'INV-MARK-PAID-EVENT',
        ]);

        $captured = [];
        $listener = function ($payload) use (&$captured) {
            $captured[] = $payload;
        };
        add_action('fluent_cart/order_paid', $listener, 10, 1);

        try {
            [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/mark-as-paid', [
                'payment_method' => 'offline_payment',
            ]);
        } finally {
            remove_action('fluent_cart/order_paid', $listener, 10);
        }

        $I->seeResponseCodeIs(200);

        Assert::assertCount(
            1,
            $captured,
            'fluent_cart/order_paid action must fire exactly once when an order is marked as paid. Captured: ' . count($captured)
        );
        $payload = $captured[0];
        Assert::assertIsArray($payload, 'order_paid event payload must be an array');
        Assert::assertArrayHasKey('order', $payload, 'order_paid event payload must include the order key');
        Assert::assertArrayHasKey('transaction', $payload, 'order_paid event payload must include the transaction key');
        Assert::assertSame(
            (int) $order->id,
            (int) ($payload['order']->id ?? 0),
            'order_paid event order id must match the order being marked paid'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [43]
     *   "Admin sees a digital-only order automatically advance to \"completed\" after being marked as paid."
     */
    public function adminSeesDigitalOnlyOrderAutoAdvanceToCompletedAfterMarkedAsPaid(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'mark-paid-digital@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_ON_HOLD,
            'payment_status'   => Status::PAYMENT_PENDING,
            'payment_method'   => 'offline_payment',
            'fulfillment_type' => 'digital',
            'subtotal'         => Helper::toCent(60),
            'total_amount'     => Helper::toCent(60),
            'total_paid'       => 0,
            'invoice_no'       => 'INV-MARK-PAID-DIGITAL',
        ]);

        $beforeUtc = gmdate('Y-m-d H:i:s');

        [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/mark-as-paid', [
            'payment_method' => 'offline_payment',
        ]);

        $afterUtc = gmdate('Y-m-d H:i:s', time() + 5);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT status, payment_status, completed_at FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                (int) $order->id
            ),
            ARRAY_A
        );
        Assert::assertNotEmpty($row, 'order row must still exist after digital mark-as-paid. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            Status::ORDER_COMPLETED,
            (string) $row['status'],
            'digital-only order must auto-advance to completed after mark-as-paid (the controller short-circuits processing → completed when fulfillment_type is digital)'
        );
        Assert::assertSame(
            Status::PAYMENT_PAID,
            (string) $row['payment_status'],
            'digital-only order payment_status must remain paid after the auto-advance'
        );
        Assert::assertNotEmpty(
            (string) $row['completed_at'],
            'digital-only order must record a completed_at timestamp once it advances to completed'
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
}
