<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsRefundFixtures;

/**
 * Spec: Cases/Functional/orders.md → "Refunding an order" section, items 52-58.
 */
class RefundOrderCest
{
    use BuildsRefundFixtures;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/orders.md [53]
     *   "Admin can refund part of a paid order, and the order is marked partially refunded."
     */
    public function adminCanRefundPartOfPaidOrderAndOrderIsMarkedPartiallyRefunded(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'refund-partial@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => Helper::toCent(200),
            'total_amount'   => Helper::toCent(200),
            'total_paid'     => Helper::toCent(200),
            'invoice_no'     => 'INV-REFUND-PARTIAL',
        ]);
        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => Helper::toCent(200),
        ]);

        [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund', [
            'refund_info' => [
                'transaction_id' => (int) $transaction->id,
                'amount'         => Helper::toCent(75),
            ],
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT payment_status, total_refund FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                (int) $order->id
            ),
            ARRAY_A
        );
        Assert::assertSame(
            Status::PAYMENT_PARTIALLY_REFUNDED,
            (string) $row['payment_status'],
            'order payment_status must be partially_refunded after a partial refund. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            Helper::toCent(75),
            (int) $row['total_refund'],
            'order total_refund must reflect the partial refund amount in cents (7500)'
        );

        $refundTxn = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT total, status, transaction_type FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d AND transaction_type = %s",
                (int) $order->id,
                Status::TRANSACTION_TYPE_REFUND
            ),
            ARRAY_A
        );
        Assert::assertNotEmpty($refundTxn, 'a refund transaction row must be persisted for the partial refund');
        Assert::assertSame(Helper::toCent(75), (int) $refundTxn['total'], 'partial refund transaction total must be 7500 cents');
    }

    /**
     * `refund_info.amount` is read as CENTS — the same unit every read response and the
     * stored column already use. Submitting 2500 against a $100 order must refund $25.00
     * and leave $75.00 refundable, not refund $0.25.
     *
     * The literal 2500 here is deliberate: writing Helper::toCent(25) would hide the very
     * thing under test, which is that the endpoint does not scale what it is handed. Under
     * the previous dollars contract this same payload was multiplied to 250000 cents,
     * exceeding total_paid, and the request was rejected outright.
     *
     * See dev-docs/PRICING-AND-TAX.md section 6.
     */
    public function refundAmountIsReadAsCentsAndIsNotScaledAgain(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'refund-cents@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => Helper::toCent(100),
            'total_amount'   => Helper::toCent(100),
            'total_paid'     => Helper::toCent(100),
            'invoice_no'     => 'INV-REFUND-CENTS',
        ]);
        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => Helper::toCent(100),
        ]);

        [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund', [
            'refund_info' => [
                'transaction_id' => (int) $transaction->id,
                'amount'         => 2500,
            ],
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT payment_status, total_refund FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                (int) $order->id
            ),
            ARRAY_A
        );
        Assert::assertSame(
            2500,
            (int) $row['total_refund'],
            'a submitted amount of 2500 must record a $25.00 refund, not $0.25 and not $2,500.00. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            Status::PAYMENT_PARTIALLY_REFUNDED,
            (string) $row['payment_status'],
            'refunding 2500 cents of a $100.00 order leaves $75.00 outstanding, so the order is partially refunded'
        );

        $refundTxn = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT total FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d AND transaction_type = %s",
                (int) $order->id,
                Status::TRANSACTION_TYPE_REFUND
            ),
            ARRAY_A
        );
        Assert::assertNotEmpty($refundTxn, 'a refund transaction row must be persisted');
        Assert::assertSame(2500, (int) $refundTxn['total'], 'the refund transaction total must be the 2500 cents submitted');
    }

    /**
     * MoR (Paddle reverse-charge) orders keep total_paid GROSS while the gateway holds a
     * lower captured amount; the gateway caps getMaxRefundableAmount() via the
     * fluent_cart/transaction/max_refundable_amount filter. An item-selected refund
     * recomputes its amount from gross line totals, so the controller cap must net out
     * business_info.mor_vat_removed (Order::netAmount()) or every full-item refund on
     * such orders is rejected with "exceeds the maximum refundable amount".
     */
    public function itemRefundOnMorReverseChargeOrderIsCappedToNetCapturedAmount(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'refund-mor-item@example.test']);

        $product = (new ProductFactory())
            ->withVariantAttributes(['item_price' => 10000])
            ->create();
        $variant = $product->variants->first();

        // Gross $100.00 order; Paddle removed $15.97 reverse-charge VAT → captured $84.03.
        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => 10000,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'invoice_no'     => 'INV-REFUND-MOR-ITEM',
        ]);
        $order->updateMeta('business_info', ['mor_vat_removed' => 1597]);

        $item = (new OrderItemFactory())->create((int) $order->id, [
            'post_id'    => (int) $product->ID,
            'object_id'  => (int) $variant->id,
            'quantity'   => 1,
            'unit_price' => 10000,
            'subtotal'   => 10000,
            'line_total' => 10000,
        ]);
        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        // Stand-in for PaddleMoRHooks::adjustMoRRefundable — caps the transaction's
        // refundable to what the gateway actually captured (8403), independent of
        // the controller math under test.
        $morCap = function ($amount, $txn) use ($transaction) {
            if ((int) $txn->id === (int) $transaction->id) {
                return min((int) $amount, 8403);
            }
            return $amount;
        };
        add_filter('fluent_cart/transaction/max_refundable_amount', $morCap, 10, 2);

        try {
            [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund', [
                'refund_info' => [
                    'transaction_id' => (int) $transaction->id,
                    'amount'         => 0,
                    'manageStock'    => false,
                ],
                'refunded_items' => [
                    ['id' => (int) $item->id, 'refund_qty' => 1],
                ],
            ]);
        } finally {
            remove_filter('fluent_cart/transaction/max_refundable_amount', $morCap, 10);
        }

        Assert::assertSame(
            200,
            $status,
            'a full-item refund on a MoR reverse-charge order must be capped to the net captured amount, not rejected. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $refundTxn = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT total FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d AND transaction_type = %s",
                (int) $order->id,
                Status::TRANSACTION_TYPE_REFUND
            ),
            ARRAY_A
        );
        Assert::assertNotEmpty($refundTxn, 'a refund transaction row must be persisted. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            8403,
            (int) $refundTxn['total'],
            'the refund must charge the net captured amount (gross 10000 line minus 1597 MoR VAT), not the gross line total'
        );

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT payment_status, total_refund FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id),
            ARRAY_A
        );
        Assert::assertSame(8403, (int) $row['total_refund'], 'order total_refund must record the net refund amount');
        Assert::assertSame(
            Status::PAYMENT_REFUNDED,
            (string) $row['payment_status'],
            'refunding the full net captured amount is a FULL refund for a MoR order (netAmount comparison in syncOrderAfterRefund)'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [54]
     *   "Admin is rejected when refunding more than the amount paid on an order."
     */
    public function adminIsRejectedWhenRefundingMoreThanAmountPaidOnOrder(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'refund-overpaid@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => Helper::toCent(40),
            'total_amount'   => Helper::toCent(40),
            'total_paid'     => Helper::toCent(40),
            'invoice_no'     => 'INV-REFUND-EXCEED',
        ]);
        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => Helper::toCent(40),
        ]);

        // Request 100, but only 40 was paid → rejection.
        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund', [
            'refund_info' => [
                'transaction_id' => (int) $transaction->id,
                'amount'         => Helper::toCent(100),
            ],
        ]);

        Assert::assertSame(
            422,
            $status,
            'over-paid refund must be rejected with the framework client-error code 422. Body: ' . wp_json_encode($body)
        );
        $message = (string) ($body['message'] ?? '');
        Assert::assertStringContainsString(
            'exceeds',
            strtolower($message),
            'rejection message must explain that the refund amount exceeds available paid amount. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $afterPaymentStatus = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT payment_status FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id)
        );
        Assert::assertSame(
            Status::PAYMENT_PAID,
            $afterPaymentStatus,
            'rejected over-refund must NOT mutate order payment_status'
        );

        $refundTxnCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d AND transaction_type = %s",
                (int) $order->id,
                Status::TRANSACTION_TYPE_REFUND
            )
        );
        Assert::assertSame(
            0,
            $refundTxnCount,
            'rejected over-refund must NOT persist any refund transaction row'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [55]
     *   "Admin is rejected when refunding an order that is already fully refunded."
     */
    public function adminIsRejectedWhenRefundingOrderThatIsAlreadyFullyRefunded(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'refund-already@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_REFUNDED,
            'subtotal'       => Helper::toCent(60),
            'total_amount'   => Helper::toCent(60),
            'total_paid'     => Helper::toCent(60),
            'total_refund'   => Helper::toCent(60),
            'invoice_no'     => 'INV-REFUND-ALREADY',
        ]);
        // Parent transaction has already been fully refunded; meta.refunded_total equals total
        // → getMaxRefundableAmount() returns 0 → second refund must be rejected.
        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => Helper::toCent(60),
            'meta'   => ['refunded_total' => Helper::toCent(60)],
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund', [
            'refund_info' => [
                'transaction_id' => (int) $transaction->id,
                'amount'         => Helper::toCent(10),
            ],
        ]);

        Assert::assertSame(
            422,
            $status,
            'refunding an already-fully-refunded order must be rejected with 422. Body: ' . wp_json_encode($body)
        );
        $message = (string) ($body['message'] ?? '');
        Assert::assertStringContainsString(
            'exceeds',
            strtolower($message),
            'rejection message must explain that no refundable amount remains. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $newRefundTxnCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d AND transaction_type = %s",
                (int) $order->id,
                Status::TRANSACTION_TYPE_REFUND
            )
        );
        Assert::assertSame(
            0,
            $newRefundTxnCount,
            'rejected duplicate refund must NOT persist a new refund transaction row'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [56]
     *   "Admin is rejected with a clear validation error when refunding without specifying a transaction or an amount."
     */
    public function adminIsRejectedWithValidationErrorWhenRefundingWithoutTransactionOrAmount(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'refund-missing-fields@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => Helper::toCent(20),
            'total_amount'   => Helper::toCent(20),
            'total_paid'     => Helper::toCent(20),
            'invoice_no'     => 'INV-REFUND-NO-FIELDS',
        ]);
        (new OrderTransactionFactory())->create((int) $order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => Helper::toCent(20),
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund', [
            'refund_info' => [],
        ]);

        Assert::assertNotSame(
            200,
            $status,
            'refund without transaction_id/amount must not return success. Body: ' . wp_json_encode($body)
        );

        // OrderController::validate emits a per-field validation array; "transaction_id" and
        // "amount" are both required.
        Assert::assertArrayHasKey(
            'transaction_id',
            (array) $body,
            'validation payload must key the failed rule by field name (transaction_id). Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey(
            'amount',
            (array) $body,
            'validation payload must key the failed rule by field name (amount). Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey(
            'required',
            (array) ($body['transaction_id'] ?? []),
            'validation entry for transaction_id must surface the "required" rule key'
        );
        Assert::assertArrayHasKey(
            'required',
            (array) ($body['amount'] ?? []),
            'validation entry for amount must surface the "required" rule key'
        );
        Assert::assertNotEmpty(
            (string) ($body['transaction_id']['required'] ?? ''),
            'required-rule message for transaction_id must be non-empty'
        );
        Assert::assertNotEmpty(
            (string) ($body['amount']['required'] ?? ''),
            'required-rule message for amount must be non-empty'
        );

        global $wpdb;
        $refundTxnCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d AND transaction_type = %s",
                (int) $order->id,
                Status::TRANSACTION_TYPE_REFUND
            )
        );
        Assert::assertSame(
            0,
            $refundTxnCount,
            'rejected validation must NOT persist any refund transaction row'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [57]
     *   "Admin sees an \"order refund\" notification triggered when a refund is processed."
     */
    public function adminSeesOrderRefundNotificationTriggeredWhenRefundIsProcessed(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'refund-event@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => Helper::toCent(80),
            'total_amount'   => Helper::toCent(80),
            'total_paid'     => Helper::toCent(80),
            'invoice_no'     => 'INV-REFUND-EVENT',
        ]);
        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => Helper::toCent(80),
        ]);

        $captured = [];
        $listener = function ($payload) use (&$captured) {
            $captured[] = $payload;
        };
        add_action('fluent_cart/order_refunded', $listener, 10, 1);

        try {
            [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund', [
                'refund_info' => [
                    'transaction_id' => (int) $transaction->id,
                    'amount'         => Helper::toCent(80),
                ],
            ]);
        } finally {
            remove_action('fluent_cart/order_refunded', $listener, 10);
        }

        $I->seeResponseCodeIs(200);

        Assert::assertCount(
            1,
            $captured,
            'fluent_cart/order_refunded action must fire exactly once when a refund is processed. Captured: ' . count($captured)
        );
        $payload = $captured[0];
        Assert::assertIsArray($payload, 'order_refunded event payload must be an array');
        Assert::assertArrayHasKey('order', $payload, 'order_refunded event payload must include the order key');
        Assert::assertArrayHasKey('refunded_amount', $payload, 'order_refunded event payload must include the refunded_amount key');
        Assert::assertArrayHasKey('type', $payload, 'order_refunded event payload must include the type key');
        Assert::assertSame(
            (int) $order->id,
            (int) ($payload['order']->id ?? 0),
            'order_refunded event order id must match the order being refunded'
        );
        Assert::assertSame(
            Helper::toCent(80),
            (int) ($payload['refunded_amount'] ?? 0),
            'order_refunded event refunded_amount must equal the refund total in cents (8000)'
        );
        Assert::assertSame(
            'full',
            (string) ($payload['type'] ?? ''),
            'order_refunded event type must be "full" when the refund covers the full paid amount'
        );
    }

    public function itemRefundRetriedAfterDatabaseErrorCountsTheUnitOnce(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'refund-item-retry@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => 30000,
            'total_amount'   => 30000,
            'total_paid'     => 30000,
            'invoice_no'     => 'INV-REFUND-ITEM-RETRY',
        ]);
        $item = (new OrderItemFactory())->create((int) $order->id, [
            'quantity'   => 3,
            'unit_price' => 10000,
            'subtotal'   => 30000,
            'line_total' => 30000,
        ]);
        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 30000,
        ]);

        $failed = false;
        $failFirstItemUpdate = function ($query) use (&$failed) {
            if (!$failed && preg_match('/^update `[^`]*fct_order_items`/i', $query)) {
                $failed = true;
                return 'SELECT * FROM fct_missing_table_for_test';
            }
            return $query;
        };
        add_filter('query', $failFirstItemUpdate);

        try {
            [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund', [
                'refund_info'    => [
                    'transaction_id' => (int) $transaction->id,
                    'amount'         => 0,
                    'manageStock'    => false,
                ],
                'refunded_items' => [
                    ['id' => (int) $item->id, 'refund_qty' => 1],
                ],
            ]);
        } finally {
            remove_filter('query', $failFirstItemUpdate);
        }

        Assert::assertTrue($failed, 'the first item update must have been failed by the test');
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));

        global $wpdb;
        $itemRow = $wpdb->get_row(
            $wpdb->prepare("SELECT quantity, line_total, refund_total, line_meta FROM {$wpdb->prefix}fct_order_items WHERE id = %d", (int) $item->id),
            ARRAY_A
        );
        Assert::assertSame(3, (int) $itemRow['quantity'], 'a refund keeps the sold quantity');
        Assert::assertSame(30000, (int) $itemRow['line_total'], 'a refund keeps the sold line total');
        Assert::assertSame(1, (int) (json_decode($itemRow['line_meta'], true)['refunded_quantity'] ?? 0), 'the retried item update must count the refunded unit once');
        Assert::assertSame(10000, (int) $itemRow['refund_total'], 'the retried refund must add to refund_total once');

        $parentMeta = json_decode((string) $wpdb->get_var(
            $wpdb->prepare("SELECT meta FROM {$wpdb->prefix}fct_order_transactions WHERE id = %d", (int) $transaction->id)
        ), true);
        Assert::assertSame(10000, (int) ($parentMeta['refunded_total'] ?? 0), 'the retry must add the refund to refunded_total once');
    }

    public function refundThatCannotBeRecordedIsNotReportedAsProcessed(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'refund-not-recorded@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => 10000,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'invoice_no'     => 'INV-REFUND-NOT-RECORDED',
        ]);
        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        $failRefundInserts = $this->failRefundInserts();

        try {
            [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund', [
                'refund_info' => [
                    'transaction_id' => (int) $transaction->id,
                    'amount'         => 3000,
                ],
            ]);
        } finally {
            remove_filter('query', $failRefundInserts);
        }

        Assert::assertSame(422, $status, 'an unrecorded refund must not answer 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('FluentCart could not record the refund. Check the order activity before you refund again.', $body['message'] ?? null);
        Assert::assertArrayNotHasKey('fluent_cart_refund', (array) $body);
        Assert::assertEmpty($body['can_force_local'] ?? null, 'an unrecorded refund must not offer the force-local prompt');
    }
}
