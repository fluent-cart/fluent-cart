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
 * Spec: Cases/Functional/orders.md → "Updating an order's transaction payment status" section, items 49-51.
 */
class ChangeTransactionPaymentStatusCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/orders.md [49]
     *   "Admin can change a specific transaction's payment status, and the order's
     *    payment status and paid total reflect the new state."
     *
     * The request status must come from Status::getEditableTransactionStatuses()
     * (pending / succeeded / authorized / failed / refunded); 'paid' is an ORDER
     * payment status, not a transaction status. A succeeded transaction must leave the
     * order at the canonical `paid` with total_paid recomputed (TC-053 / FC-SEC-09) —
     * never at the transaction word `succeeded`.
     */
    public function adminCanChangeTransactionPaymentStatus(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'tx-status-change@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => Helper::toCent(60),
            'total_amount'   => Helper::toCent(60),
            'invoice_no'     => 'INV-TX-STATUS-CHANGE',
        ]);
        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'status' => Status::TRANSACTION_PENDING,
            'total'  => Helper::toCent(60),
        ]);

        [, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/transactions/' . (int) $transaction->id . '/status',
            [
                'status' => Status::TRANSACTION_SUCCEEDED,
            ]
        );

        $I->seeResponseCodeIs(200);
        Assert::assertSame(
            Status::TRANSACTION_SUCCEEDED,
            (string) ($body['transaction']['status'] ?? ''),
            'the response must echo the transaction at its new status. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $afterTxStatus = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_order_transactions WHERE id = %d",
                (int) $transaction->id
            )
        );
        Assert::assertSame(
            Status::TRANSACTION_SUCCEEDED,
            $afterTxStatus,
            'transaction status must persist as the requested new status. Body: ' . wp_json_encode($body)
        );

        $afterTxTotal = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT total FROM {$wpdb->prefix}fct_order_transactions WHERE id = %d",
                (int) $transaction->id
            )
        );
        Assert::assertSame(
            Helper::toCent(60),
            (int) $afterTxTotal,
            'a status change must leave the transaction amount untouched, in cents'
        );

        $orderRow = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT payment_status, total_paid FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                (int) $order->id
            ),
            ARRAY_A
        );
        Assert::assertSame(
            [Status::PAYMENT_PAID, Helper::toCent(60)],
            [(string) $orderRow['payment_status'], (int) $orderRow['total_paid']],
            'a succeeded transaction covering the total must leave the ORDER at payment status paid with total_paid recomputed in cents — never the transaction word succeeded. Row: ' . wp_json_encode($orderRow)
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [49a]
     *   "Admin is rejected when trying to change a succeeded transaction to another status."
     */
    public function adminIsRejectedWhenChangingASucceededTransactionToAnotherStatus(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'tx-succeeded-locked@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => Helper::toCent(40),
            'total_amount'   => Helper::toCent(40),
            'total_paid'     => Helper::toCent(40),
        ]);
        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => Helper::toCent(40),
        ]);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/transactions/' . (int) $transaction->id . '/status',
            ['status' => Status::TRANSACTION_PENDING]
        );

        Assert::assertSame(422, $status, 'a succeeded transaction must not be changed from the status action. Body: ' . wp_json_encode($body));

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT t.status AS tx_status, o.payment_status, o.total_paid FROM {$wpdb->prefix}fct_order_transactions t JOIN {$wpdb->prefix}fct_orders o ON o.id = t.order_id WHERE t.id = %d",
                (int) $transaction->id
            ),
            ARRAY_A
        );
        Assert::assertSame(
            [Status::TRANSACTION_SUCCEEDED, Status::PAYMENT_PAID, Helper::toCent(40)],
            [(string) $row['tx_status'], (string) $row['payment_status'], (int) $row['total_paid']],
            'the rejected request must leave the transaction, the paid order and its total untouched'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [50]
     *   "Admin is rejected when changing a transaction's status to the same status it already has."
     */
    public function adminIsRejectedWhenChangingTransactionStatusToSameStatus(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'tx-same-status@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => Helper::toCent(40),
            'total_amount'   => Helper::toCent(40),
            'total_paid'     => Helper::toCent(40),
            'invoice_no'     => 'INV-TX-SAME-STATUS',
        ]);
        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => Helper::toCent(40),
        ]);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/transactions/' . (int) $transaction->id . '/status',
            [
                'status' => Status::TRANSACTION_SUCCEEDED,
            ]
        );

        // OrderController::updateTransactionStatus calls $this->sendError([...]) without an
        // explicit code, which defaults to 422 in WPFluent's Controller::sendError.
        Assert::assertSame(
            422,
            $status,
            'changing tx to its current status must be rejected with the framework client-error code 422. Body: ' . wp_json_encode($body)
        );
        $message = (string) ($body['message'] ?? '');
        Assert::assertStringContainsString(
            'same status',
            strtolower($message),
            'rejection message must explain the transaction already has the same status. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $afterTxStatus = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_order_transactions WHERE id = %d",
                (int) $transaction->id
            )
        );
        Assert::assertSame(
            Status::TRANSACTION_SUCCEEDED,
            $afterTxStatus,
            'rejected request must NOT mutate transaction status'
        );

        $afterOrderPaymentStatus = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT payment_status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                (int) $order->id
            )
        );
        Assert::assertSame(
            Status::PAYMENT_PAID,
            $afterOrderPaymentStatus,
            'rejected request must NOT mutate order payment_status'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [51]
     *   "Admin is rejected when the chosen transaction does not belong to the chosen order."
     */
    public function adminIsRejectedWhenChosenTransactionDoesNotBelongToChosenOrder(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'tx-mismatched@example.test']);

        $orderA = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => Helper::toCent(30),
            'total_amount'   => Helper::toCent(30),
            'invoice_no'     => 'INV-MISMATCHED-A',
        ]);
        $orderB = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => Helper::toCent(50),
            'total_amount'   => Helper::toCent(50),
            'invoice_no'     => 'INV-MISMATCHED-B',
        ]);
        // Transaction belongs to orderB; URL targets orderA → mismatch.
        $foreignTransaction = (new OrderTransactionFactory())->create((int) $orderB->id, [
            'status' => Status::TRANSACTION_PENDING,
            'total'  => Helper::toCent(50),
        ]);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $orderA->id . '/transactions/' . (int) $foreignTransaction->id . '/status',
            [
                'status' => Status::TRANSACTION_SUCCEEDED,
            ]
        );

        Assert::assertSame(
            422,
            $status,
            'mismatched-order tx-status change must be rejected with the framework client-error code 422. Body: ' . wp_json_encode($body)
        );
        $message = (string) ($body['message'] ?? '');
        Assert::assertStringContainsString(
            'does not match',
            strtolower($message),
            'rejection message must clearly explain that the chosen transaction does not match the chosen order. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $afterTxStatus = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_order_transactions WHERE id = %d",
                (int) $foreignTransaction->id
            )
        );
        Assert::assertSame(
            Status::TRANSACTION_PENDING,
            $afterTxStatus,
            'rejected mismatched request must NOT mutate the foreign transaction status'
        );

        $afterOrderAStatus = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT payment_status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                (int) $orderA->id
            )
        );
        Assert::assertSame(
            Status::PAYMENT_PENDING,
            $afterOrderAStatus,
            'rejected mismatched request must NOT mutate order A payment_status'
        );

        $afterOrderBStatus = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT payment_status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                (int) $orderB->id
            )
        );
        Assert::assertSame(
            Status::PAYMENT_PENDING,
            $afterOrderBStatus,
            'rejected mismatched request must NOT mutate order B payment_status either'
        );
    }
}
