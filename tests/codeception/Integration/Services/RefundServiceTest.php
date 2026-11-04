<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\Refund;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;

class RefundServiceTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderTransactionFactory $txnFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->txnFactory = new OrderTransactionFactory();
    }

    // =========================================================================
    // Spec 142: processRefund with zero amount
    // =========================================================================

    public function test_process_refund_with_zero_amount_returns_wp_error(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        $result = (new Refund())->processRefund($txn, 0);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_refund_amount', $result->get_error_code());
    }

    // =========================================================================
    // Spec 143: processRefund exceeding net paid
    // =========================================================================

    public function test_process_refund_exceeding_net_paid_returns_wp_error(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        // Refund more than paid
        $result = (new Refund())->processRefund($txn, 20000);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // =========================================================================
    // Spec 144: processRefund exceeding transaction max
    // =========================================================================

    public function test_process_refund_exceeding_transaction_max_returns_wp_error(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 5000,
            'meta'   => ['refunded_total' => 3000],
        ]);

        // Max refundable is 2000, try to refund 3000
        $result = (new Refund())->processRefund($txn, 3000);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // CR-6: the order-level cap read a non-existent `total_refunded` column, so
    // earlier refunds on other transactions were never subtracted.
    public function test_process_refund_counts_prior_order_refunds_against_net_paid(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'total_refund'   => 8000,
            'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED,
        ]);
        // This transaction alone still allows 10000, so only the order cap can refuse.
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);
        // An order-level refund with no parent charge, e.g. one recorded from a gateway.
        $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => 8000,
        ]);

        $result = (new Refund())->processRefund($txn, 5000);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringContainsString('net paid amount', $result->get_error_message());
    }

    // OrderPaid's listener calls recountTotalPaid(), which stores total_paid net
    // of refunds; a cap read from the columns then subtracts the refund twice.
    public function test_process_refund_allows_the_remaining_balance_after_a_paid_recount(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        $this->assertIsArray((new Refund())->processRefund($txn, 3000));

        $order = Order::query()->find($order->id);
        $order->recountTotalPaid();

        $txn = OrderTransaction::query()->find($txn->id);
        $result = (new Refund())->processRefund($txn, 7000);

        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
    }

    public function test_process_refund_allows_a_refund_within_the_remaining_net_paid(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'total_refund'   => 3000,
            'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED,
        ]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);
        $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => 3000,
        ]);

        $result = (new Refund())->processRefund($txn, 7000);

        $this->assertIsArray($result);
        $this->assertSame(7000, (int) $result['refund_transaction']->total, 'the refund row carries the requested cents');
    }

    // =========================================================================
    // Spec 145-147: Successful processRefund
    // =========================================================================

    public function test_process_refund_creates_refund_transaction(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        $result = (new Refund())->processRefund($txn, 3000);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('refund_transaction', $result);

        $refundTxn = $result['refund_transaction'];
        $this->assertSame(Status::TRANSACTION_TYPE_REFUND, $refundTxn->transaction_type);
        $this->assertSame(Status::TRANSACTION_REFUNDED, $refundTxn->status);
        $this->assertEquals(3000, $refundTxn->total);
    }

    public function test_process_refund_updates_parent_transaction_meta(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        (new Refund())->processRefund($txn, 3000);

        $freshTxn = OrderTransaction::query()->find($txn->id);
        $this->assertEquals(3000, $freshTxn->meta['refunded_total'] ?? 0);
    }

    public function test_process_refund_dispatches_order_refund_event(): void
    {
        $eventFired = false;
        add_action('fluent_cart/order_refunded', function () use (&$eventFired) {
            $eventFired = true;
        });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        (new Refund())->processRefund($txn, 3000);

        $this->assertTrue($eventFired, 'fluent_cart/order_refunded should fire');
    }

    // =========================================================================
    // Spec 149-150: createOrRecordRefund
    // =========================================================================

    public function test_create_or_record_refund_returns_existing_when_vendor_id_matches(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $parentTxn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        // Create an existing refund
        $existingRefund = OrderTransaction::query()->create([
            'order_id'         => $order->id,
            'order_type'       => 'payment',
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => 2000,
            'vendor_charge_id' => 're_existing123',
            'payment_method'   => 'offline_payment',
            'payment_mode'     => 'live',
            'currency'         => 'USD',
        ]);

        $result = Refund::createOrRecordRefund([
            'vendor_charge_id' => 're_existing123',
            'total'            => 2000,
        ], $parentTxn);

        $this->assertSame($existingRefund->id, $result->id);
    }

    // =========================================================================
    // Spec 151: recordRefund with zero total
    // =========================================================================

    public function test_record_refund_with_zero_total_returns_wp_error(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'total_amount' => 10000,
        ]);
        $parentTxn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        $result = Refund::recordRefund(['total' => 0], $parentTxn);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // =========================================================================
    // Spec 140: refund transaction meta contains parent_id + reason
    // =========================================================================

    public function test_refund_transaction_meta_includes_parent_id_and_reason(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        $result = (new Refund())->processRefund($txn, 3000, ['reason' => 'Item damaged']);

        $refundTxn = $result['refund_transaction'];
        $this->assertSame($txn->id, $refundTxn->meta['parent_id']);
        $this->assertSame('Item damaged', $refundTxn->meta['reason']);
    }

    // =========================================================================
    // Spec 143: filter fluent_cart/order_refund_manually suppresses gateway call
    // =========================================================================

    public function test_process_refund_respects_manual_filter(): void
    {
        add_filter('fluent_cart/order_refund_manually', function () {
            return ['status' => 'yes', 'source' => 'unit-test'];
        });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        $result = (new Refund())->processRefund($txn, 1000);

        remove_all_filters('fluent_cart/order_refund_manually');

        $this->assertIsArray($result);
        $this->assertNull($result['vendor_refund_id']);
    }

    // =========================================================================
    // Spec 147: createOrRecordRefund dedups local refunds by total + parent_id
    // =========================================================================

    public function test_create_or_record_refund_dedups_local_by_total_and_parent(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $parentTxn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        $existingRefund = OrderTransaction::query()->create([
            'order_id'         => $order->id,
            'order_type'       => 'payment',
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => 2500,
            'payment_method'   => 'offline_payment',
            'payment_mode'     => 'live',
            'currency'         => 'USD',
            'meta'             => ['parent_id' => $parentTxn->id, 'reason' => ''],
        ]);

        $result = Refund::createOrRecordRefund([
            'vendor_charge_id' => 're_vendor_xyz',
            'total'            => 2500,
        ], $parentTxn);

        $this->assertSame($existingRefund->id, $result->id);
        $this->assertSame('re_vendor_xyz', $result->vendor_charge_id);
    }

    // =========================================================================
    // Spec 148: recordRefund dispatches OrderRefund when status=REFUNDED
    // =========================================================================

    public function test_record_refund_dispatches_order_refund_event(): void
    {
        $fired = false;
        add_action('fluent_cart/order_refunded', function () use (&$fired) { $fired = true; });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $parentTxn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        Refund::recordRefund([
            'total' => 1000,
        ], $parentTxn);

        remove_all_actions('fluent_cart/order_refunded');
        $this->assertTrue($fired);
    }

    public function test_record_refund_writes_nothing_while_the_order_refund_lock_is_busy(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $parentTxn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);
        $lockName = 'fct_refund_' . $order->id;

        $rival = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        if (!$rival) {
            $this->markTestSkipped('could not open a second DB connection to hold the order refund lock');
        }

        try {
            $row = mysqli_fetch_row(mysqli_query($rival, "SELECT GET_LOCK('" . mysqli_real_escape_string($rival, $lockName) . "', 5)"));
            $this->assertSame('1', (string) $row[0], 'the rival connection must hold the order refund lock');

            $result = Refund::recordRefund(['total' => 1000], $parentTxn);
        } finally {
            mysqli_query($rival, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($rival, $lockName) . "')");
            mysqli_close($rival);
        }

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('refund_lock_timeout', $result->get_error_code());
        $this->assertSame(0, OrderTransaction::query()
            ->where('order_id', $order->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->count());
        $this->assertSame(0, (int) Order::query()->findOrFail($order->id)->total_refund);
    }
}
