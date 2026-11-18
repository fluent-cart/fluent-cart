<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Webhook\IPN;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\ConfiguresStripeTestMode;

/**
 * Spec: Cases/Functional/orders.md → "Marking an order as paid" section, items 39-43, 43c-43r.
 */
class MarkOrderPaidCest
{
    use ConfiguresStripeTestMode;

    /** @var callable|null */
    private $stripeMock = null;

    /** @var mixed */
    private $storeSettings = null;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    public function _after(FunctionalTester $I): void
    {
        if ($this->stripeMock) {
            remove_filter('pre_http_request', $this->stripeMock, 10);
            $this->stripeMock = null;
        }

        if ($this->storeSettings !== null) {
            update_option('fluent_cart_store_settings', $this->storeSettings);
            $this->storeSettings = null;
        }
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

    /**
     * Spec: Cases/Functional/orders.md [43c]
     */
    public function confirmReportsThatStripeAlreadyReceivedTheOpenCharge(FunctionalTester $I): void
    {
        [$order, $charge] = $this->stripeOrderWithOpenCharge($I, 'INV-MARK-PAID-STRIPE-PAID');
        $this->stripeIntentIs('succeeded', 'pi_mark_paid_settled', 10000, 'usd');

        [$status, $body] = $this->markPaid($I, $order, ['mark_paid_as' => 'confirm']);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('gateway_paid', $body['outcome'] ?? null);
        Assert::assertStringContainsString('Stripe already received this payment', (string) ($body['message'] ?? ''));

        $rows = $this->charges((int) $order->id);
        Assert::assertCount(1, $rows, 'no second charge may be created');
        Assert::assertSame((int) $charge->id, $rows[0]['id']);
        Assert::assertSame(Status::TRANSACTION_SUCCEEDED, $rows[0]['status']);
        Assert::assertSame('stripe', $rows[0]['payment_method']);
        Assert::assertSame(Helper::toCent(100), $this->totalPaid((int) $order->id));
        Assert::assertSame(Status::PAYMENT_PAID, $this->paymentStatus((int) $order->id));
    }

    /**
     * Spec: Cases/Functional/orders.md [43d]
     */
    public function confirmSettlesAProcessingPayPayChargeAndALateWebhookAddsNothing(FunctionalTester $I): void
    {
        [$order, $charge] = $this->stripeOrderWithOpenCharge($I, 'INV-MARK-PAID-PAYPAY', 12370, 'JPY');
        $this->stripeIntentIs('processing', 'pi_mark_paid_settled', 12370, 'jpy');

        [$status, $body] = $this->markPaid($I, $order, [
            'mark_paid_as'   => 'confirm',
            'transaction_id' => (int) $charge->id,
            'mark_paid_note' => 'PayPay screenshot checked',
        ]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('confirmed', $body['outcome'] ?? null);
        $rows = $this->charges((int) $order->id);
        Assert::assertCount(1, $rows, 'the open charge is settled, not duplicated');
        Assert::assertSame((int) $charge->id, $rows[0]['id']);
        Assert::assertSame(Status::TRANSACTION_SUCCEEDED, $rows[0]['status']);
        Assert::assertSame('pi_mark_paid_settled', $rows[0]['vendor_charge_id'], 'the gateway ID is kept');
        Assert::assertSame('stripe', $rows[0]['payment_method'], 'refunds must still route to Stripe');
        Assert::assertSame('paypay', $rows[0]['payment_method_type']);
        Assert::assertSame(Helper::toCent(12370), $this->totalPaid((int) $order->id));

        $meta = OrderTransaction::query()->find($charge->id)->meta;
        Assert::assertSame('processing', $meta['mark_paid']['verification'] ?? null);
        Assert::assertSame('PayPay screenshot checked', $meta['mark_paid']['note'] ?? null);
        Assert::assertSame(Status::TRANSACTION_PENDING, $meta['mark_paid']['previous_status'] ?? null);

        $this->deliverStripeChargeSucceeded((int) $order->id, 'pi_mark_paid_settled', 12370, 'jpy');
        $this->deliverStripeChargeSucceeded((int) $order->id, 'pi_mark_paid_settled', 12370, 'jpy');

        $rows = $this->charges((int) $order->id);
        Assert::assertCount(1, $rows);
        Assert::assertSame(Status::TRANSACTION_SUCCEEDED, $rows[0]['status']);
        Assert::assertSame(Helper::toCent(12370), $this->totalPaid((int) $order->id), 'a late success must not raise total_paid');
    }

    /**
     * Spec: Cases/Functional/orders.md [43e]
     */
    public function openChargeRequiresTheAdminToChooseConfirmOrSeparate(FunctionalTester $I): void
    {
        [$order] = $this->stripeOrderWithOpenCharge($I, 'INV-MARK-PAID-NO-CHOICE');

        [$status, $body] = $this->markPaid($I, $order, []);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        $rows = $this->charges((int) $order->id);
        Assert::assertCount(1, $rows);
        Assert::assertSame(Status::TRANSACTION_PENDING, $rows[0]['status']);
        Assert::assertSame(0, $this->totalPaid((int) $order->id));
    }

    /**
     * Spec: Cases/Functional/orders.md [43f]
     */
    public function confirmRejectsAMethodOrReferenceThatDiffersFromTheGatewayRow(FunctionalTester $I): void
    {
        [$order] = $this->stripeOrderWithOpenCharge($I, 'INV-MARK-PAID-CONFLICT');

        [$status, $body] = $this->markPaid($I, $order, [
            'mark_paid_as'     => 'confirm',
            'payment_method'   => 'stripe',
            'vendor_charge_id' => 'pi_some_other_intent',
        ]);
        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));

        [$status, $body] = $this->markPaid($I, $order, [
            'mark_paid_as'   => 'confirm',
            'payment_method' => 'offline_payment',
        ]);
        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));

        Assert::assertSame(Status::TRANSACTION_PENDING, $this->charges((int) $order->id)[0]['status']);
    }

    /**
     * Spec: Cases/Functional/orders.md [43g]
     */
    public function confirmSettlesWhenStripeCannotBeReached(FunctionalTester $I): void
    {
        [$order, $charge] = $this->stripeOrderWithOpenCharge($I, 'INV-MARK-PAID-UNREACHABLE');
        $this->stripeIntentIs('succeeded', 'pi_not_this_one', 10000, 'usd');

        [$status, $body] = $this->markPaid($I, $order, [
            'mark_paid_as'     => 'confirm',
            'payment_method'   => 'stripe',
            'vendor_charge_id' => '',
        ]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('confirmed', $body['outcome'] ?? null);
        $rows = $this->charges((int) $order->id);
        Assert::assertCount(1, $rows);
        Assert::assertSame(Status::TRANSACTION_SUCCEEDED, $rows[0]['status']);
        Assert::assertSame('pi_mark_paid_settled', $rows[0]['vendor_charge_id'], 'an empty reference keeps the gateway ID');
        Assert::assertSame('stripe', $rows[0]['payment_method']);
        Assert::assertSame('unreachable', OrderTransaction::query()->find($charge->id)->meta['mark_paid']['verification'] ?? null);
    }

    /**
     * Spec: Cases/Functional/orders.md [43h]
     */
    public function confirmSettlesAFailedGatewayChargeOnTheSameRow(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order = $this->pendingOrder('INV-MARK-PAID-STRIPE-FAILED', 'stripe');
        $failed = (new OrderTransactionFactory())->create((int) $order->id, [
            'payment_method'      => 'stripe',
            'payment_method_type' => 'paypay',
            'vendor_charge_id'    => 'pi_mark_paid_declined',
            'status'              => Status::TRANSACTION_FAILED,
            'total'               => Helper::toCent(100),
        ]);

        [$status, $body] = $this->markPaid($I, $order, ['mark_paid_as' => 'confirm']);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        $rows = $this->charges((int) $order->id);
        Assert::assertCount(1, $rows);
        Assert::assertSame((int) $failed->id, $rows[0]['id']);
        Assert::assertSame(Status::TRANSACTION_SUCCEEDED, $rows[0]['status']);
        Assert::assertSame('stripe', $rows[0]['payment_method']);
        $meta = OrderTransaction::query()->find($failed->id)->meta;
        Assert::assertSame(Status::TRANSACTION_FAILED, $meta['mark_paid']['previous_status'] ?? null);
        Assert::assertSame('not_checked', $meta['mark_paid']['verification'] ?? null);
    }

    /**
     * Spec: Cases/Functional/orders.md [43i]
     */
    public function separatePaymentLeavesTheGatewayRowAndALateSuccessShowsOverpaid(FunctionalTester $I): void
    {
        [$order, $charge] = $this->stripeOrderWithOpenCharge($I, 'INV-MARK-PAID-SEPARATE');

        [$status, $body] = $this->markPaid($I, $order, [
            'mark_paid_as'     => 'separate',
            'payment_method'   => 'offline_payment',
            'vendor_charge_id' => 'bank-ref-77',
        ]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('separate', $body['outcome'] ?? null);
        $rows = $this->charges((int) $order->id);
        Assert::assertCount(2, $rows);
        Assert::assertSame((int) $charge->id, $rows[0]['id']);
        Assert::assertSame(Status::TRANSACTION_PENDING, $rows[0]['status'], 'the Stripe payment stays open');
        Assert::assertSame('stripe', $rows[0]['payment_method']);
        Assert::assertSame(Status::TRANSACTION_SUCCEEDED, $rows[1]['status']);
        Assert::assertSame('offline_payment', $rows[1]['payment_method']);
        Assert::assertSame('bank-ref-77', $rows[1]['vendor_charge_id']);
        Assert::assertSame(Helper::toCent(100), $this->totalPaid((int) $order->id));

        $this->deliverStripeChargeSucceeded((int) $order->id, 'pi_mark_paid_settled', 10000, 'usd');

        Assert::assertSame(Helper::toCent(200), $this->totalPaid((int) $order->id));
        Assert::assertSame(1, $this->overpaidLogs((int) $order->id), 'one overpaid warning');

        $this->deliverStripeChargeSucceeded((int) $order->id, 'pi_mark_paid_settled', 10000, 'usd');
        Assert::assertSame(1, $this->overpaidLogs((int) $order->id), 'the warning is not repeated');
    }

    /**
     * Spec: Cases/Functional/orders.md [43j]
     */
    public function confirmWithSeveralOpenChargesNeedsTheChosenOne(FunctionalTester $I): void
    {
        [$order, $first] = $this->stripeOrderWithOpenCharge($I, 'INV-MARK-PAID-TWO-OPEN');
        $second = (new OrderTransactionFactory())->create((int) $order->id, [
            'payment_method'   => 'stripe',
            'vendor_charge_id' => 'pi_mark_paid_second',
            'status'           => Status::TRANSACTION_FAILED,
            'total'            => Helper::toCent(100),
        ]);

        [$status, $body] = $this->markPaid($I, $order, ['mark_paid_as' => 'confirm']);
        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));

        [$status, $body] = $this->markPaid($I, $order, ['mark_paid_as' => 'confirm', 'transaction_id' => (int) $second->id]);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        $rows = $this->charges((int) $order->id);
        Assert::assertCount(2, $rows);
        Assert::assertSame((int) $first->id, $rows[0]['id']);
        Assert::assertSame(Status::TRANSACTION_PENDING, $rows[0]['status']);
        Assert::assertSame(Status::TRANSACTION_SUCCEEDED, $rows[1]['status']);
    }

    /**
     * Spec: Cases/Functional/orders.md [43k]
     */
    public function markAsPaidSyncsAnOrderWhoseChargeAlreadySettled(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order = $this->pendingOrder('INV-MARK-PAID-UNSYNCED', 'stripe');
        $settled = (new OrderTransactionFactory())->create((int) $order->id, [
            'payment_method'   => 'stripe',
            'vendor_charge_id' => 'pi_mark_paid_unsynced',
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => Helper::toCent(100),
        ]);

        $paidEvents = 0;
        $listener = function () use (&$paidEvents) {
            $paidEvents++;
        };
        add_action('fluent_cart/order_paid', $listener, 10, 1);

        try {
            [$status, $body] = $this->markPaid($I, $order, []);
        } finally {
            remove_action('fluent_cart/order_paid', $listener, 10);
        }

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        $rows = $this->charges((int) $order->id);
        Assert::assertCount(1, $rows, 'a charge already settled must not be paid twice');
        Assert::assertSame((int) $settled->id, $rows[0]['id']);
        Assert::assertSame(Helper::toCent(100), $this->totalPaid((int) $order->id));
        Assert::assertSame(Status::PAYMENT_PAID, $this->paymentStatus((int) $order->id));
        Assert::assertSame(1, $paidEvents, 'the order-paid hooks run once');
    }

    /**
     * Spec: Cases/Functional/orders.md [43l]
     */
    public function confirmReportsTheBalanceWhenStripeReceivedPart(FunctionalTester $I): void
    {
        [$order] = $this->stripeOrderWithOpenCharge($I, 'INV-MARK-PAID-PART');
        $this->stripeIntentIs('succeeded', 'pi_mark_paid_settled', 6000, 'usd');

        [$status, $body] = $this->markPaid($I, $order, ['mark_paid_as' => 'confirm']);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('gateway_partial', $body['outcome'] ?? null);
        Assert::assertStringContainsString('still due', (string) ($body['message'] ?? ''));
        $rows = $this->charges((int) $order->id);
        Assert::assertCount(1, $rows);
        Assert::assertSame(6000, $rows[0]['total']);
        Assert::assertSame(6000, $this->totalPaid((int) $order->id));
        Assert::assertSame(Status::PAYMENT_PARTIALLY_PAID, $this->paymentStatus((int) $order->id));
    }

    /**
     * Spec: Cases/Functional/orders.md [43m]
     */
    public function confirmRejectsAGatewayRowWhoseAmountIsNotTheDue(FunctionalTester $I): void
    {
        [$order, $charge] = $this->stripeOrderWithOpenCharge($I, 'INV-MARK-PAID-AMOUNT');
        OrderTransaction::query()->where('id', $charge->id)->update(['total' => 6000]);
        $this->stripeIntentIs('processing', 'pi_mark_paid_settled', 6000, 'usd');

        [$status, $body] = $this->markPaid($I, $order, ['mark_paid_as' => 'confirm']);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(Status::TRANSACTION_PENDING, $this->charges((int) $order->id)[0]['status']);
        Assert::assertSame(0, $this->totalPaid((int) $order->id));
    }

    /**
     * Spec: Cases/Functional/orders.md [43n]
     */
    public function markAsPaidReusesAPlaceholderChargeWithoutGatewayId(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order = $this->pendingOrder('INV-MARK-PAID-PLACEHOLDER', 'offline_payment');
        $placeholder = (new OrderTransactionFactory())->create((int) $order->id, [
            'vendor_charge_id' => '',
            'total'            => Helper::toCent(100),
        ]);

        [$status, $body] = $this->markPaid($I, $order, [
            'payment_method'   => 'offline_payment',
            'vendor_charge_id' => 'cash-1',
        ]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        $rows = $this->charges((int) $order->id);
        Assert::assertCount(1, $rows);
        Assert::assertSame((int) $placeholder->id, $rows[0]['id']);
        Assert::assertSame(Status::TRANSACTION_SUCCEEDED, $rows[0]['status']);
        Assert::assertSame('cash-1', $rows[0]['vendor_charge_id']);
    }

    /**
     * Spec: Cases/Functional/orders.md [43o]
     */
    public function markAsPaidAddsAChargeForTheRaisedPartOfAPaidOrder(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'mark-paid-raised@example.test']);
        $order = (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_PROCESSING,
            'payment_status'   => Status::PAYMENT_PARTIALLY_PAID,
            'payment_method'   => 'offline_payment',
            'fulfillment_type' => 'physical',
            'subtotal'         => Helper::toCent(150),
            'total_amount'     => Helper::toCent(150),
            'total_paid'       => Helper::toCent(100),
            'invoice_no'       => 'INV-MARK-PAID-RAISED',
        ]);
        (new OrderTransactionFactory())->create((int) $order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => Helper::toCent(100),
        ]);

        [$status, $body] = $this->markPaid($I, $order, ['payment_method' => 'offline_payment']);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        $rows = $this->charges((int) $order->id);
        Assert::assertCount(2, $rows);
        Assert::assertSame(Helper::toCent(50), $rows[1]['total']);
        Assert::assertSame(Helper::toCent(150), $this->totalPaid((int) $order->id));
    }

    /**
     * Spec: Cases/Functional/orders.md [43p]
     */
    public function markAsPaidRejectsACanceledOrderWhoseChargeAlreadySettled(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order = $this->pendingOrder('INV-MARK-PAID-CANCELED-SETTLED', 'stripe');
        $order->update(['status' => Status::ORDER_CANCELED]);
        (new OrderTransactionFactory())->create((int) $order->id, [
            'payment_method'   => 'stripe',
            'vendor_charge_id' => 'pi_mark_paid_canceled',
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => Helper::toCent(100),
        ]);

        [$status, $body] = $this->markPaid($I, $order, []);

        Assert::assertSame(423, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(Status::PAYMENT_PENDING, $this->paymentStatus((int) $order->id));
        Assert::assertSame(0, $this->totalPaid((int) $order->id));
    }

    /**
     * Spec: Cases/Functional/orders.md [43q]
     */
    public function confirmRejectsWhenStripeReportsAnotherCurrency(FunctionalTester $I): void
    {
        [$order] = $this->stripeOrderWithOpenCharge($I, 'INV-MARK-PAID-CURRENCY');
        $this->stripeIntentIs('succeeded', 'pi_mark_paid_settled', 10000, 'eur');

        [$status, $body] = $this->markPaid($I, $order, ['mark_paid_as' => 'confirm']);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(Status::TRANSACTION_PENDING, $this->charges((int) $order->id)[0]['status']);
        Assert::assertSame(0, $this->totalPaid((int) $order->id));
    }

    /**
     * Spec: Cases/Functional/orders.md [43r]
     */
    public function confirmRejectsASubscriptionCharge(FunctionalTester $I): void
    {
        [$order, $charge] = $this->stripeOrderWithOpenCharge($I, 'INV-MARK-PAID-SUBSCRIPTION');
        OrderTransaction::query()->where('id', $charge->id)->update(['subscription_id' => 987654]);

        [$status, $body] = $this->markPaid($I, $order, ['mark_paid_as' => 'confirm']);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(Status::TRANSACTION_PENDING, $this->charges((int) $order->id)[0]['status']);
        Assert::assertSame(0, $this->totalPaid((int) $order->id));
    }

    /**
     * Spec: Cases/Functional/orders.md [43s]
     */
    public function confirmRejectsACanceledOrderBeforeAskingTheGateway(FunctionalTester $I): void
    {
        [$order] = $this->stripeOrderWithOpenCharge($I, 'INV-MARK-PAID-CANCELED-OPEN');
        $order->update(['status' => Status::ORDER_CANCELED]);
        $this->stripeIntentIs('succeeded', 'pi_mark_paid_settled', 10000, 'usd');

        [$status, $body] = $this->markPaid($I, $order, ['mark_paid_as' => 'confirm']);

        Assert::assertSame(423, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(Status::TRANSACTION_PENDING, $this->charges((int) $order->id)[0]['status']);
        Assert::assertSame(0, $this->totalPaid((int) $order->id));
        Assert::assertSame(Status::ORDER_CANCELED, Order::query()->find($order->id)->status);
    }

    private function markPaid(FunctionalTester $I, $order, array $data): array
    {
        return $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/mark-as-paid', $data);
    }

    private function stripeOrderWithOpenCharge(FunctionalTester $I, string $invoiceNo, int $amount = 100, string $currency = 'USD'): array
    {
        $this->storeSettings = get_option('fluent_cart_store_settings', []);
        $this->enableStripeTestMode();
        $I->loginAsSuperAdmin();

        $order = $this->pendingOrder($invoiceNo, 'stripe', $amount, $currency);
        $charge = (new OrderTransactionFactory())->create((int) $order->id, [
            'payment_method'      => 'stripe',
            'payment_method_type' => 'paypay',
            'payment_mode'        => Status::ORDER_MODE_TEST,
            'vendor_charge_id'    => 'pi_mark_paid_settled',
            'currency'            => $currency,
            'total'               => Helper::toCent($amount),
        ]);

        return [$order, $charge];
    }

    private function pendingOrder(string $invoiceNo, string $paymentMethod, int $amount = 100, string $currency = 'USD')
    {
        $customer = (new CustomerFactory())->create(['email' => strtolower($invoiceNo) . '@example.test']);

        return (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_ON_HOLD,
            'payment_status'   => Status::PAYMENT_PENDING,
            'payment_method'   => $paymentMethod,
            'mode'             => Status::ORDER_MODE_TEST,
            'currency'         => $currency,
            'fulfillment_type' => 'physical',
            'subtotal'         => Helper::toCent($amount),
            'total_amount'     => Helper::toCent($amount),
            'total_paid'       => 0,
            'invoice_no'       => $invoiceNo,
        ]);
    }

    /**
     * $amount is in Stripe's unit for $currency (yen for jpy, cents for usd).
     */
    private function stripeIntentIs(string $status, string $intentId, int $amount, string $currency): void
    {
        $this->stripeMock = function ($response, $args, $url) use ($status, $intentId, $amount, $currency) {
            if (strpos($url, 'api.stripe.com') === false) {
                return $response;
            }

            if (strpos($url, 'payment_intents/' . $intentId) === false) {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(['error' => ['message' => 'not mocked']]),
                    'response' => ['code' => 404, 'message' => 'Not Found'],
                    'cookies'  => [],
                ];
            }

            return [
                'headers'  => [],
                'body'     => wp_json_encode([
                    'id'            => $intentId,
                    'object'        => 'payment_intent',
                    'status'        => $status,
                    'latest_charge' => [
                        'id'                     => 'ch_mark_paid',
                        'status'                 => $status === 'succeeded' ? 'succeeded' : 'pending',
                        'amount'                 => $amount,
                        'currency'               => $currency,
                        'payment_intent'         => $intentId,
                        'livemode'               => false,
                        'payment_method_details' => ['type' => 'paypay'],
                    ],
                ]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
            ];
        };
        add_filter('pre_http_request', $this->stripeMock, 10, 3);
    }

    private function deliverStripeChargeSucceeded(int $orderId, string $intentId, int $amount, string $currency): void
    {
        (new IPN())->handleChargeSucceeded([
            'event' => ['data' => ['object' => [
                'id'                     => 'ch_mark_paid_late',
                'object'                 => 'charge',
                'status'                 => 'succeeded',
                'amount'                 => $amount,
                'currency'               => $currency,
                'payment_intent'         => $intentId,
                'livemode'               => false,
                'payment_method_details' => ['type' => 'paypay'],
            ]]],
            'order' => Order::query()->find($orderId),
        ]);
    }

    private function charges(int $orderId): array
    {
        return OrderTransaction::query()
            ->where('order_id', $orderId)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->orderBy('id', 'ASC')
            ->get()
            ->map(function ($row) {
                return [
                    'id'                  => (int) $row->id,
                    'status'              => (string) $row->status,
                    'total'               => (int) $row->total,
                    'payment_method'      => (string) $row->payment_method,
                    'payment_method_type' => (string) $row->payment_method_type,
                    'vendor_charge_id'    => (string) $row->vendor_charge_id,
                ];
            })
            ->toArray();
    }

    private function paymentStatus(int $orderId): string
    {
        return (string) Order::query()->find($orderId)->payment_status;
    }

    private function totalPaid(int $orderId): int
    {
        return (int) Order::query()->find($orderId)->total_paid;
    }

    private function overpaidLogs(int $orderId): int
    {
        return Activity::query()
            ->where('module_id', $orderId)
            ->where('module_name', 'Order')
            ->where('status', 'warning')
            ->where('title', 'Order overpaid')
            ->count();
    }
}
