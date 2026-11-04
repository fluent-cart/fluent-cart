<?php

namespace Tests\Integration\Models;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;

class OrderTransactionTest extends DatabaseTestCase
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
    // Spec 94: Columns
    // =========================================================================

    public function test_transactions_table_has_expected_columns(): void
    {
        $expected = [
            'id', 'order_id', 'order_type', 'vendor_charge_id', 'payment_method',
            'payment_mode', 'payment_method_type', 'currency', 'transaction_type',
            'subscription_id', 'card_last_4', 'card_brand', 'status', 'total',
            'rate', 'meta', 'uuid', 'created_at', 'updated_at',
        ];
        $this->assertColumnsExist('fct_order_transactions', $expected);
    }

    // =========================================================================
    // Spec 95: UUID auto-generation
    // =========================================================================

    public function test_creating_transaction_auto_generates_uuid(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id);

        $this->assertNotEmpty($txn->uuid);
        $this->assertSame(32, strlen($txn->uuid));
    }

    // =========================================================================
    // Spec 96: Meta JSON
    // =========================================================================

    public function test_meta_is_json_encoded_and_decoded(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, [
            'meta' => ['refunded_total' => 1000, 'reason' => 'test'],
        ]);

        $fresh = OrderTransaction::query()->find($txn->id);
        $this->assertIsArray($fresh->meta);
        $this->assertSame(1000, $fresh->meta['refunded_total']);
        $this->assertSame('test', $fresh->meta['reason']);
    }

    // =========================================================================
    // Spec 97-98: Relationships
    // =========================================================================

    public function test_transaction_belongs_to_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id);

        $this->assertInstanceOf(Order::class, $txn->order);
        $this->assertSame($order->id, $txn->order->id);
    }

    public function test_transaction_has_one_subscription(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'type'        => Status::ORDER_TYPE_SUBSCRIPTION,
        ]);

        $sub = Subscription::query()->create([
            'customer_id'      => $customer->id,
            'parent_order_id'  => $order->id,
            'status'           => Status::SUBSCRIPTION_ACTIVE,
            'item_name'        => 'Sub Test',
            'recurring_amount' => 1000,
            'billing_interval' => 'monthly',
            'payment_method'   => 'offline_payment',
        ]);

        $txn = $this->txnFactory->create($order->id, ['subscription_id' => $sub->id]);

        $this->assertInstanceOf(Subscription::class, $txn->subscription);
        $this->assertSame($sub->id, $txn->subscription->id);
    }

    // =========================================================================
    // Spec 99-100: updateStatus
    // =========================================================================

    public function test_update_status_changes_and_saves(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, ['status' => Status::TRANSACTION_PENDING]);

        $txn->updateStatus(Status::TRANSACTION_SUCCEEDED);

        $fresh = OrderTransaction::query()->find($txn->id);
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $fresh->status);
    }

    public function test_update_status_with_same_value_is_noop(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, ['status' => Status::TRANSACTION_SUCCEEDED]);

        $result = $txn->updateStatus(Status::TRANSACTION_SUCCEEDED);
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $result->status);
    }

    public function test_update_status_with_other_data_merges_fields(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, ['status' => Status::TRANSACTION_PENDING]);

        $txn->updateStatus(Status::TRANSACTION_SUCCEEDED, [
            'vendor_charge_id' => 'ch_12345',
        ]);

        $fresh = OrderTransaction::query()->find($txn->id);
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $fresh->status);
        $this->assertSame('ch_12345', $fresh->vendor_charge_id);
    }

    // =========================================================================
    // Spec 101-102: getMaxRefundableAmount
    // =========================================================================

    public function test_get_max_refundable_amount_for_succeeded_transaction(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
            'meta'   => ['refunded_total' => 3000],
        ]);

        $this->assertSame(7000, $txn->getMaxRefundableAmount());
    }

    public function test_get_max_refundable_amount_returns_zero_for_non_succeeded(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_PENDING,
            'total'  => 10000,
        ]);

        $this->assertSame(0, $txn->getMaxRefundableAmount());
    }

    // =========================================================================
    // Spec 103-104: getPaymentMethodText
    // =========================================================================

    public function test_get_payment_method_text_with_card_info(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, [
            'card_brand'  => 'Visa',
            'card_last_4' => '4242',
        ]);

        $text = $txn->getPaymentMethodText();
        $this->assertStringContainsString('Visa', $text);
        $this->assertStringContainsString('4242', $text);
    }

    public function test_get_payment_method_text_without_card_info(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, ['payment_method' => 'paypal']);

        $this->assertSame(\FluentCart\App\App::gateway('paypal')->getMeta('title'), $txn->getPaymentMethodText());
        $this->assertNotSame('paypal', $txn->getPaymentMethodText());
    }

    // =========================================================================
    // Spec 105: bulkDeleteByOrderIds
    // =========================================================================

    public function test_bulk_delete_by_order_ids(): void
    {
        $customer = $this->customerFactory->create();
        $order1 = $this->orderFactory->create(['customer_id' => $customer->id]);
        $order2 = $this->orderFactory->create(['customer_id' => $customer->id]);

        $this->txnFactory->create($order1->id);
        $this->txnFactory->create($order1->id);
        $this->txnFactory->create($order2->id);

        OrderTransaction::bulkDeleteByOrderIds([$order1->id, $order2->id]);

        $this->assertSame(0, OrderTransaction::query()->whereIn('order_id', [$order1->id, $order2->id])->count());
    }

    // =========================================================================
    // Spec 106-107: Scopes
    // =========================================================================

    public function test_of_status_scope(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $succeededTxn = $this->txnFactory->create($order->id, ['status' => Status::TRANSACTION_SUCCEEDED]);
        $this->txnFactory->create($order->id, ['status' => Status::TRANSACTION_PENDING]);

        // Scoped to this order: rows committed past rollback by earlier
        // DDL-bearing tests (TC-058) would otherwise inflate the global count.
        $succeeded = OrderTransaction::query()->where('order_id', $order->id)->ofStatus(Status::TRANSACTION_SUCCEEDED)->get();

        // Identity, not cardinality: the fixture holds one succeeded and one
        // pending transaction, so inverting the scope's predicate to != still
        // returns exactly one row and would satisfy a bare assertCount.
        $this->assertSame(
            [(int) $succeededTxn->id],
            array_map('intval', $succeeded->pluck('id')->toArray()),
            'ofStatus must return the succeeded transaction itself, not merely one row'
        );
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $succeeded->first()->status,
            'the returned row must carry the status that was asked for');
    }

    public function test_of_payment_method_scope(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $this->txnFactory->create($order->id, ['payment_method' => 'stripe']);
        $this->txnFactory->create($order->id, ['payment_method' => 'paypal']);

        $stripe = OrderTransaction::query()->ofPaymentMethod('stripe')->get();
        $this->assertCount(1, $stripe);
    }

    // =========================================================================
    // Spec 26: explicit uuid preserved
    // =========================================================================

    public function test_explicit_uuid_is_preserved(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, ['uuid' => 'my-explicit-uuid']);

        $this->assertSame('my-explicit-uuid', $txn->uuid);
    }

    // =========================================================================
    // Spec 28: meta defaults to empty array
    // =========================================================================

    public function test_meta_defaults_to_empty_array_when_null_set(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, ['meta' => []]);

        $fresh = OrderTransaction::query()->find($txn->id);
        $this->assertSame([], $fresh->meta);
    }

    // =========================================================================
    // Spec 37-38: getReceiptPageUrl
    // =========================================================================

    public function test_receipt_page_url_contains_trx_hash(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, ['uuid' => 'test-hash-abc']);

        $url = $txn->getReceiptPageUrl();
        $this->assertStringContainsString('trx_hash=test-hash-abc', $url);
    }

    public function test_receipt_page_url_applies_filter_when_filtered(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, ['uuid' => 'filter-hash']);

        add_filter('fluent_cart/transaction/receipt_page_url', function ($url, $ctx) {
            return 'https://overridden.test/?filtered=1';
        }, 10, 2);

        $url = $txn->getReceiptPageUrl(true);

        remove_all_filters('fluent_cart/transaction/receipt_page_url');

        $this->assertStringContainsString('filtered=1', $url);
    }

    /**
     * Every caller of this method redirects the customer, so every caller wants
     * the filter. `$filtered` defaulted to false, and a caller that forgot to opt
     * in got an unfilterable redirect with no indication anything was skipped —
     * which is how commit 1df38106f reinstated the Stripe confirmation redirect
     * without the `true` that 609fa8bf9 originally shipped, silently removing
     * that gateway from the filter for every integration downstream.
     */
    public function test_receipt_page_url_applies_filter_without_an_explicit_argument(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, ['uuid' => 'default-filter-hash']);

        add_filter('fluent_cart/transaction/receipt_page_url', function ($url) {
            return add_query_arg(['gclid' => 'test-click-id'], $url);
        });

        $url = $txn->getReceiptPageUrl();

        remove_all_filters('fluent_cart/transaction/receipt_page_url');

        $this->assertStringContainsString('gclid=test-click-id', $url, 'the filter must apply when no argument is passed, because that is how every gateway but PayPal calls it');
        $this->assertStringContainsString('trx_hash=default-filter-hash', $url, 'filtering must not discard the query args the method builds');
    }

    // =========================================================================
    // Transaction settlement date (Specs 265-268)
    // =========================================================================

    /**
     * Spec: Cases/Integration/orders.md [265]
     *   "Marking a pending transaction succeeded stamps the settlement time in meta.settled_at (GMT)"
     */
    public function test_marking_a_pending_transaction_succeeded_stamps_settled_at_in_meta(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, ['created_at' => '2026-07-28 11:42:00']);

        $before = gmdate('Y-m-d H:i:s');
        $txn->update(['status' => Status::TRANSACTION_SUCCEEDED]);
        $after = gmdate('Y-m-d H:i:s');

        $fresh = OrderTransaction::query()->find($txn->id);
        $settledAt = $fresh->meta['settled_at'] ?? null;

        $this->assertNotEmpty($settledAt, 'transitioning to succeeded must record the settlement date in meta.settled_at');
        $this->assertGreaterThanOrEqual($before, $settledAt, 'settled_at must be the GMT time of the transition, not the (older) transaction creation time');
        $this->assertLessThanOrEqual($after, $settledAt, 'settled_at must be the GMT time of the transition, not a future date');
    }

    /**
     * Spec: Cases/Integration/orders.md [266]
     *   "A transaction that already has meta.settled_at keeps its original value when saved again"
     */
    public function test_settled_at_is_not_overwritten_on_later_saves(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'meta'   => ['settled_at' => '2026-07-28 11:45:00'],
        ]);

        $txn = OrderTransaction::query()->find($txn->id);
        $txn->update(['card_brand' => 'visa']);

        $fresh = OrderTransaction::query()->find($txn->id);
        $this->assertSame('2026-07-28 11:45:00', $fresh->meta['settled_at'] ?? null, 'an existing settled_at must survive later saves of the same transaction');
    }

    /**
     * Spec: Cases/Integration/orders.md [267]
     *   "A transaction created directly with status succeeded gets meta.settled_at stamped at creation"
     */
    public function test_transaction_created_as_succeeded_gets_settled_at_at_creation(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $before = gmdate('Y-m-d H:i:s');
        $txn = $this->txnFactory->create($order->id, ['status' => Status::TRANSACTION_SUCCEEDED]);
        $after = gmdate('Y-m-d H:i:s');

        $fresh = OrderTransaction::query()->find($txn->id);
        $settledAt = $fresh->meta['settled_at'] ?? null;

        $this->assertNotEmpty($settledAt, 'a transaction born succeeded (e.g. a renewal payment) must carry a settlement date');
        $this->assertGreaterThanOrEqual($before, $settledAt, 'settled_at at creation must be the GMT creation time');
        $this->assertLessThanOrEqual($after, $settledAt, 'settled_at at creation must be the GMT creation time');
    }

    /**
     * Spec: Cases/Integration/orders.md [268]
     *   "A transaction that stays pending gets no meta.settled_at"
     */
    public function test_pending_transaction_has_no_settled_at(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id);

        $txn->update(['vendor_charge_id' => 'pi_still_pending']);

        $fresh = OrderTransaction::query()->find($txn->id);
        $this->assertArrayNotHasKey('settled_at', $fresh->meta, 'a payment that has not succeeded has no settlement date');
    }
}
