<?php

namespace Tests\Integration\Http;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Activity;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Services\ShipmentService;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\RestTestCase;
use Tests\Support\Traits\HealsCommittedRows;

class OrderControllerTest extends RestTestCase
{
    // Shipment and order-save endpoints commit their own transaction.
    use HealsCommittedRows;

    /**
     * Fresh Route objects per dispatch — Route::getParameter() memoizes the
     * first dispatch's URL params per instance (TESTABILITY-DEBT #3).
     */
    protected bool $rebuildRestServerPerDispatch = true;

    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private OrderTransactionFactory $txnFactory;
    private OrderAddressFactory $addressFactory;
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->captureRowWatermarks();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->itemFactory = new OrderItemFactory();
        $this->txnFactory = new OrderTransactionFactory();
        $this->addressFactory = new OrderAddressFactory();
        $this->productFactory = new ProductFactory();
    }

    public function tearDown(): void
    {
        $this->healCommittedRows();
        wp_cache_flush();
        gc_collect_cycles();
        parent::tearDown();
    }

    // =========================================================================
    // Spec 180-182: GET /orders (index)
    // =========================================================================

    public function test_get_orders_returns_paginated_list(): void
    {
        $customer = $this->customerFactory->create();
        $this->orderFactory->create(['customer_id' => $customer->id]);
        $this->orderFactory->create(['customer_id' => $customer->id]);

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/orders');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('orders', $data);
    }

    // =========================================================================
    // Spec 187-189: GET /orders/{order_id} (getDetails)
    // =========================================================================

    public function test_get_order_details_returns_full_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->withAddresses()->create(['customer_id' => $customer->id]);
        $this->itemFactory->create($order->id);
        $this->txnFactory->create($order->id);

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/orders/' . $order->id);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('order', $data);
    }

    public function test_get_nonexistent_order_returns_error(): void
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/orders/999999');
        $response = $this->dispatchRequestSilently($request);

        // Nonexistent order should return an error status
        $this->assertGreaterThanOrEqual(400, $response->get_status(), 'Nonexistent order should return 4xx');
    }

    // =========================================================================
    // Spec 202-208: POST /orders/{order}/mark-as-paid
    // =========================================================================

    public function test_mark_as_paid_creates_transaction_and_updates_status(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'total_amount'   => 10000,
            'total_paid'     => 0,
            'payment_method' => 'offline_payment',
        ]);

        // Create a pending offline transaction
        $this->txnFactory->create($order->id, [
            'status'         => Status::TRANSACTION_PENDING,
            'total'          => 10000,
            'payment_method' => 'offline_payment',
        ]);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/orders/' . $order->id . '/mark-as-paid');
        $request->set_body_params([
            'payment_method'   => 'offline_payment',
            'transaction_type' => 'charge',
        ]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::PAYMENT_PAID, $fresh->payment_status);
        $this->assertContains($fresh->status, [Status::ORDER_PROCESSING, Status::ORDER_COMPLETED]);
    }

    public function test_mark_as_paid_rejects_already_paid_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/orders/' . $order->id . '/mark-as-paid');
        $response = $this->dispatchRequestSilently($request);

        $this->assertGreaterThanOrEqual(400, $response->get_status());
    }

    public function test_mark_as_paid_rejects_canceled_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_CANCELED,
            'total_amount'   => 10000,
            'total_paid'     => 0,
        ]);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/orders/' . $order->id . '/mark-as-paid');
        $response = $this->dispatchRequestSilently($request);

        $this->assertGreaterThanOrEqual(400, $response->get_status());
    }

    // =========================================================================
    // Spec 211-215: DELETE /orders/{order_id}
    // =========================================================================

    public function test_delete_order_succeeds_for_deletable_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_CANCELED,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);

        $orderId = $order->id;
        $customerId = $customer->id;

        // OrderController::deleteOrder() runs its own $DB->beginTransaction()/
        // commit(); MySQL has no nested transactions, so that COMMIT also
        // commits this test's wrapper transaction — the fixture rows become
        // permanent and poison later files' count assertions (CustomerTest)
        // across runs. tearDown healing cannot work: the session runs
        // autocommit=0, so tearDown-time deletes open a fresh implicit
        // transaction that WPTestCase's rollback reverts. Heal here in a
        // finally block — it must run on assertion failure too, or a red run
        // leaves the committed rows behind — with an explicit COMMIT to
        // persist the deletes. If the endpoint never committed, the fixtures
        // are deleted-then-committed all the same, so state ends clean on
        // every path and tearDown's ROLLBACK is a no-op.
        try {
            $request = new \WP_REST_Request('DELETE', '/fluent-cart/v2/orders/' . $orderId);
            $response = $this->dispatchRequest($request);

            $this->assertSame(200, $response->get_status());
            $this->assertNull(Order::query()->find($orderId));
        } finally {
            Order::query()->where('id', $orderId)->delete();
            \FluentCart\App\Models\Customer::query()->where('id', $customerId)->delete();
            \FluentCart\App\Models\Activity::query()
                ->where('module_type', Order::class)
                ->where('module_id', $orderId)
                ->delete();
            global $wpdb;
            $wpdb->query('COMMIT');
        }
    }

    public function test_create_shipment_rejects_a_fractional_quantity(): void
    {
        [$order, $item] = $this->createPhysicalOrderWithItem(5);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/orders/' . $order->id . '/shipments');
        $request->set_body_params([
            'tracking_number' => 'TRK-FRAC',
            'items'           => [['order_item_id' => $item->id, 'qty' => '1.5']],
        ]);
        $response = $this->dispatchRequestSilently($request);

        $this->assertSame(422, $response->get_status());
        $this->assertSame(0, (int) OrderItem::query()->find($item->id)->fulfilled_quantity);
        $this->assertSame(0, Shipment::query()->where('order_id', $order->id)->count());
    }

    public function test_create_shipment_accepts_a_whole_number_string_quantity(): void
    {
        [$order, $item] = $this->createPhysicalOrderWithItem(5);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/orders/' . $order->id . '/shipments');
        $request->set_body_params([
            'tracking_number' => 'TRK-WHOLE',
            'items'           => [['order_item_id' => $item->id, 'qty' => '2']],
        ]);
        $response = $this->dispatchRequestSilently($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(2, (int) OrderItem::query()->find($item->id)->fulfilled_quantity);
    }

    public function test_delete_order_removes_its_shipments(): void
    {
        [$order, $item] = $this->createPhysicalOrderWithItem(2);
        $this->shipItem($order, $item, 1);
        Order::query()->where('id', $order->id)->update([
            'status'         => Status::ORDER_CANCELED,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);

        $orderId = $order->id;
        $postId = OrderItem::query()->find($item->id)->post_id;
        $this->assertSame(1, Shipment::query()->where('order_id', $orderId)->count());

        // deleteOrder() commits the wrapper transaction; heal with an explicit COMMIT (see above).
        try {
            $request = new \WP_REST_Request('DELETE', '/fluent-cart/v2/orders/' . $orderId);
            $response = $this->dispatchRequest($request);

            $this->assertSame(200, $response->get_status());
            $this->assertNull(Order::query()->find($orderId));
            $this->assertSame(0, Shipment::query()->where('order_id', $orderId)->count(),
                'Deleting an order must remove its shipments');
        } finally {
            Shipment::query()->where('order_id', $orderId)->delete();
            OrderItem::query()->where('order_id', $orderId)->delete();
            \FluentCart\App\Models\OrderAddress::query()->where('order_id', $orderId)->delete();
            Order::query()->where('id', $orderId)->delete();
            \FluentCart\App\Models\Customer::query()->where('id', $order->customer_id)->delete();
            Activity::query()->where('module_type', Order::class)->where('module_id', $orderId)->delete();
            \FluentCart\App\Models\ProductVariation::query()->where('post_id', $postId)->delete();
            \FluentCart\App\Models\ProductDetail::query()->where('post_id', $postId)->delete();
            wp_delete_post($postId, true);
            global $wpdb;
            $wpdb->query('COMMIT');
        }
    }

    public function test_delete_order_rejects_non_deletable_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_PROCESSING,
        ]);

        $request = new \WP_REST_Request('DELETE', '/fluent-cart/v2/orders/' . $order->id);
        $response = $this->dispatchRequestSilently($request);

        $this->assertGreaterThanOrEqual(400, $response->get_status());
    }

    // =========================================================================
    // Spec 209-210: PUT /orders/{order}/statuses
    // =========================================================================

    public function test_update_order_status_via_rest(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_ON_HOLD,
        ]);

        $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/orders/' . $order->id . '/statuses');
        $request->set_body_params([
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_PROCESSING],
        ]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::ORDER_PROCESSING, $fresh->status);
    }

    // =========================================================================
    // Spec 219-221: POST /orders/{order_id}/change-customer
    // =========================================================================

    public function test_change_customer_requires_customer_id(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/orders/' . $order->id . '/change-customer');
        $request->set_body_params([]); // Missing customer_id
        $response = $this->dispatchRequestSilently($request);

        $this->assertGreaterThanOrEqual(400, $response->get_status());
    }

    public function test_change_customer_succeeds(): void
    {
        $oldCustomer = $this->customerFactory->create();
        $newCustomer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $oldCustomer->id]);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/orders/' . $order->id . '/change-customer');
        $request->set_body_params(['customer_id' => $newCustomer->id]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status(),
            'Changing the customer on an order succeeds for an admin');
        $fresh = Order::query()->find($order->id);
        $this->assertSame((int)$newCustomer->id, (int)$fresh->customer_id,
            'The order row now belongs to the new customer');
    }

    // =========================================================================
    // Spec 222-226: POST /orders/do-bulk-action
    // =========================================================================

    public function test_bulk_action_requires_order_ids(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/orders/do-bulk-action');
        $request->set_body_params([
            'action'    => 'change_order_status',
            'order_ids' => [],
        ]);
        $response = $this->dispatchRequestSilently($request);

        $this->assertGreaterThanOrEqual(400, $response->get_status(), 'Should return error when no order_ids');
    }

    public function test_unauthenticated_request_returns_401_or_403(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $read = $this->dispatchRequestAsGuest(new \WP_REST_Request('GET', '/fluent-cart/v2/orders'));
        $this->assertSame(401, $read->get_status(),
            'An anonymous request to the orders index is rejected as unauthenticated');

        // Writes need their own oracle: a verb-scoped policy regression could
        // reject reads yet accept mutations.
        $write = new \WP_REST_Request('DELETE', '/fluent-cart/v2/orders/' . $order->id);
        $writeResponse = $this->dispatchRequestAsGuest($write);
        $this->assertSame(401, $writeResponse->get_status(),
            'An anonymous order delete is rejected as unauthenticated');
        $this->assertNotNull(Order::query()->find($order->id),
            'The rejected anonymous delete removed nothing');
    }

    public function test_subscriber_without_permission_returns_403(): void
    {
        $subscriberId = self::factory()->user->create(['role' => 'subscriber']);
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $read = $this->dispatchRequestAs($subscriberId, new \WP_REST_Request('GET', '/fluent-cart/v2/orders'));
        $this->assertSame(403, $read->get_status(),
            'A subscriber without store caps cannot read the orders index');

        $write = new \WP_REST_Request('DELETE', '/fluent-cart/v2/orders/' . $order->id);
        $writeResponse = $this->dispatchRequestAs($subscriberId, $write);
        $this->assertSame(403, $writeResponse->get_status(),
            'A subscriber without store caps cannot delete an order');
        $this->assertNotNull(Order::query()->find($order->id),
            'The rejected subscriber delete removed nothing');
    }

    // =========================================================================
    // Spec 251-260: Edge Cases
    // =========================================================================

    public function test_digital_order_auto_completes_on_mark_as_paid(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_ON_HOLD,
            'payment_status'   => Status::PAYMENT_PENDING,
            'total_amount'     => 5000,
            'total_paid'       => 0,
            'fulfillment_type' => 'digital',
            'payment_method'   => 'offline_payment',
        ]);

        $this->txnFactory->create($order->id, [
            'status'         => Status::TRANSACTION_PENDING,
            'total'          => 5000,
            'payment_method' => 'offline_payment',
        ]);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/orders/' . $order->id . '/mark-as-paid');
        $request->set_body_params([
            'payment_method'   => 'offline_payment',
            'transaction_type' => 'charge',
        ]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status(),
            'Marking a digital order as paid succeeds');

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::ORDER_COMPLETED, $fresh->status,
            'A fully digital order auto-completes when marked as paid');
        $this->assertNotNull($fresh->completed_at,
            'Auto-completion stamps completed_at');
        $this->assertSame(Status::PAYMENT_PAID, $fresh->payment_status,
            'The order is paid after mark-as-paid');
    }

    public function test_free_order_gets_paid_status_on_creation(): void
    {
        $customer = $this->customerFactory->create();

        // Use OrderResource::create which sets paid for $0 orders
        $order = \FluentCart\Api\Resource\OrderResource::create([
            'customer_id'           => $customer->id,
            'order_items'           => [],
            'tax_total'             => 0,
            'shipping_total'        => 0,
            'manual_discount_total' => 0,
        ]);

        $this->assertSame(Status::PAYMENT_PAID, $order->payment_status);
    }

    // =========================================================================
    // Spec 270-274: POST /orders/{order_id} keeps unsent fields; notes keep line breaks
    // =========================================================================

    public function test_admin_save_without_note_keeps_stored_note(): void
    {
        $order = $this->createEditableOrderWithItem(['note' => 'keep me']);

        $payload = $this->adminSavePayload($order->id);
        unset($payload['note']);

        $response = $this->dispatchRequest($this->updateOrderRequest($order->id, $payload));

        $this->assertSame(200, $response->get_status());
        $this->assertSame('keep me', Order::query()->find($order->id)->note,
            'A save that does not send note must not touch the stored note');
    }

    public function test_save_with_note_writes_it_with_line_breaks(): void
    {
        $order = $this->createEditableOrderWithItem(['note' => 'old']);

        $payload = $this->adminSavePayload($order->id);
        $payload['note'] = "first line\nsecond line";

        $response = $this->dispatchRequest($this->updateOrderRequest($order->id, $payload));

        $this->assertSame(200, $response->get_status());
        $this->assertSame("first line\nsecond line", Order::query()->find($order->id)->note);
    }

    public function test_partial_payload_leaves_unsent_columns_unchanged(): void
    {
        $order = $this->createEditableOrderWithItem([
            'note'         => 'keep me',
            'invoice_no'   => 'INV-77',
            'completed_at' => '2026-01-02 03:04:05',
        ]);
        $before = Order::query()->find($order->id);

        $full = $this->adminSavePayload($order->id);
        $payload = [
            'customer_id' => $full['customer_id'],
            'order_items' => $full['order_items'],
        ];

        $response = $this->dispatchRequest($this->updateOrderRequest($order->id, $payload));

        $this->assertSame(200, $response->get_status());
        $after = Order::query()->find($order->id);
        foreach (['uuid', 'invoice_no', 'payment_status', 'ip_address', 'completed_at', 'note'] as $column) {
            $this->assertSame((string) $before->{$column}, (string) $after->{$column},
                "Unsent column {$column} must keep its stored value");
        }
    }

    public function test_save_without_shipping_total_keeps_shipping_and_total(): void
    {
        $order = $this->createEditableOrderWithItem([
            'fulfillment_type' => 'physical',
            'shipping_total'   => 500,
            'total_amount'     => Helper::toCent(29.99) + 500,
        ]);

        $payload = $this->adminSavePayload($order->id);
        unset($payload['shipping_total']);

        $response = $this->dispatchRequest($this->updateOrderRequest($order->id, $payload));

        $this->assertSame(200, $response->get_status());
        $after = Order::query()->find($order->id);
        $this->assertSame(500, (int) $after->shipping_total);
        $this->assertSame(Helper::toCent(29.99) + 500, (int) $after->total_amount,
            'An absent shipping_total must not be read as a change to zero shipping');
    }

    public function test_notes_attach_keeps_line_breaks(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/notes/attach');
        $request->set_body_params([
            'order_id' => $order->id,
            'note'     => "first line\nsecond line",
        ]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame("first line\nsecond line", Order::query()->find($order->id)->note);
    }

    public function test_mark_as_paid_note_is_logged_and_keeps_order_note(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'total_amount'   => 10000,
            'total_paid'     => 0,
            'payment_method' => 'offline_payment',
            'note'           => "Gift wrap\ndeliver after 5pm",
        ]);
        $this->txnFactory->create($order->id, [
            'status'         => Status::TRANSACTION_PENDING,
            'total'          => 10000,
            'payment_method' => 'offline_payment',
        ]);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/orders/' . $order->id . '/mark-as-paid');
        $request->set_body_params([
            'payment_method' => 'offline_payment',
            'mark_paid_note' => "Paid by bank transfer\nref 4471 & co",
        ]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame("Gift wrap\ndeliver after 5pm", Order::query()->find($order->id)->note);

        $log = Activity::query()
            ->where('module_type', Order::class)
            ->where('module_id', $order->id)
            ->where('title', 'Payment note')
            ->first();

        $this->assertNotNull($log, 'The payment note is recorded on the order activity');
        $this->assertSame("Paid by bank transfer<br />\nref 4471 &amp; co", $log->content);
        $this->assertNotSame('', wp_get_current_user()->display_name);
        $this->assertSame(wp_get_current_user()->display_name, $log->created_by,
            'The payment note names the admin who wrote it');
    }

    public function test_mark_as_paid_without_note_logs_no_payment_note(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'total_amount'   => 10000,
            'total_paid'     => 0,
            'payment_method' => 'offline_payment',
            'note'           => 'Gift wrap',
        ]);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/orders/' . $order->id . '/mark-as-paid');
        $request->set_body_params(['payment_method' => 'offline_payment']);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('Gift wrap', Order::query()->find($order->id)->note);
        $this->assertSame(0, Activity::query()
            ->where('module_type', Order::class)
            ->where('module_id', $order->id)
            ->where('title', 'Payment note')
            ->count());
    }

    // =========================================================================
    // POST /orders/{order_id}: item edits keep shipment quantities consistent
    // =========================================================================

    public function test_save_keeps_shipped_fulfilled_quantity(): void
    {
        [$order, $item] = $this->createPhysicalOrderWithItem(3);
        $this->shipItem($order, $item, 2);

        $payload = $this->adminSavePayload($order->id);
        $response = $this->dispatchRequest($this->updateOrderRequest($order->id, $payload));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(2, (int) OrderItem::query()->find($item->id)->fulfilled_quantity,
            'An order edit must not overwrite the quantity shipments claimed');
    }

    public function test_save_loaded_before_a_shipment_keeps_its_fulfilled_quantity(): void
    {
        [$order, $item] = $this->createPhysicalOrderWithItem(3);
        $stalePayload = $this->adminSavePayload($order->id);

        $this->shipItem($order, $item, 2);

        $response = $this->dispatchRequest($this->updateOrderRequest($order->id, $stalePayload));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(2, (int) OrderItem::query()->find($item->id)->fulfilled_quantity);
    }

    public function test_save_rejects_quantity_below_shipped_quantity(): void
    {
        [$order, $item] = $this->createPhysicalOrderWithItem(3);
        $this->shipItem($order, $item, 2);

        $payload = $this->adminSavePayload($order->id);
        $payload['order_items'][0]['quantity'] = 1;
        $response = $this->dispatchRequestSilently($this->updateOrderRequest($order->id, $payload));

        $this->assertSame(422, $response->get_status());
        $fresh = OrderItem::query()->find($item->id);
        $this->assertSame(3, (int) $fresh->quantity);
        $this->assertSame(2, (int) $fresh->fulfilled_quantity);
    }

    public function test_save_rejects_deleting_a_shipped_item(): void
    {
        [$order, $item] = $this->createPhysicalOrderWithItem(3);
        $otherItem = $this->addPhysicalItem($order->id, 1);
        $this->shipItem($order, $item, 1);

        $payload = $this->adminSavePayload($order->id);
        $payload['order_items'] = array_values(array_filter($payload['order_items'], function ($row) use ($item) {
            return (int) $row['id'] !== (int) $item->id;
        }));
        $payload['deletedItems'] = [$item->id];
        $response = $this->dispatchRequestSilently($this->updateOrderRequest($order->id, $payload));

        $this->assertSame(422, $response->get_status());
        $this->assertNotNull(OrderItem::query()->find($item->id), 'A shipped item must not be deleted');
        $this->assertNotNull(OrderItem::query()->find($otherItem->id));
    }

    public function test_save_without_shipments_still_reduces_and_deletes_items(): void
    {
        [$order, $item] = $this->createPhysicalOrderWithItem(3);
        $otherItem = $this->addPhysicalItem($order->id, 1);

        $payload = $this->adminSavePayload($order->id);
        $payload['order_items'] = array_values(array_filter($payload['order_items'], function ($row) use ($otherItem) {
            return (int) $row['id'] !== (int) $otherItem->id;
        }));
        $payload['order_items'][0]['quantity'] = 1;
        $payload['deletedItems'] = [$otherItem->id];
        $response = $this->dispatchRequest($this->updateOrderRequest($order->id, $payload));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(1, (int) OrderItem::query()->find($item->id)->quantity);
        $this->assertNull(OrderItem::query()->find($otherItem->id));
    }

    public function test_save_takes_the_shipping_lock(): void
    {
        [$order] = $this->createPhysicalOrderWithItem(2);
        $payload = $this->adminSavePayload($order->id);

        $locked = false;
        $spy = function ($sql) use (&$locked) {
            $normalized = strtolower(str_replace('`', '', (string) $sql));
            if (preg_match('/^\s*select\b.*fct_orders\b.*\bfor update\s*;?\s*$/s', $normalized)) {
                $locked = true;
            }
            return $sql;
        };
        add_filter('query', $spy, 10, 1);
        try {
            $response = $this->dispatchRequest($this->updateOrderRequest($order->id, $payload));
        } finally {
            remove_filter('query', $spy, 10);
        }

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($locked, 'Item edits must serialize with shipment changes on the order row');
    }

    // =========================================================================
    // POST /orders/{order_id}: item edits re-derive shipment-owned shipping status
    // =========================================================================

    public function test_save_increasing_a_shipped_items_quantity_moves_order_to_partially_shipped(): void
    {
        [$order, $item] = $this->createPhysicalOrderWithItem(2);
        $this->shipItem($order, $item, 2);
        $this->assertSame(Status::SHIPPING_SHIPPED, Order::query()->find($order->id)->shipping_status);

        $payload = $this->adminSavePayload($order->id);
        $payload['order_items'][0]['quantity'] = 3;
        $response = $this->dispatchRequest($this->updateOrderRequest($order->id, $payload));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(Status::SHIPPING_PARTIALLY_SHIPPED, Order::query()->find($order->id)->shipping_status);
    }

    public function test_save_reducing_quantity_to_shipped_quantity_moves_order_to_shipped(): void
    {
        [$order, $item] = $this->createPhysicalOrderWithItem(3);
        $this->shipItem($order, $item, 2);
        $this->assertSame(Status::SHIPPING_PARTIALLY_SHIPPED, Order::query()->find($order->id)->shipping_status);

        $payload = $this->adminSavePayload($order->id);
        $payload['order_items'][0]['quantity'] = 2;
        $response = $this->dispatchRequest($this->updateOrderRequest($order->id, $payload));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(Status::SHIPPING_SHIPPED, Order::query()->find($order->id)->shipping_status);
    }

    public function test_save_adding_a_physical_item_to_a_shipped_order_moves_it_to_partially_shipped(): void
    {
        [$order, $item] = $this->createPhysicalOrderWithItem(1);
        $this->shipItem($order, $item, 1);
        $this->assertSame(Status::SHIPPING_SHIPPED, Order::query()->find($order->id)->shipping_status);

        $payload = $this->adminSavePayload($order->id);
        $payload['order_items'][] = $this->newPhysicalItemRow($payload['order_items'][0]);
        $response = $this->dispatchRequest($this->updateOrderRequest($order->id, $payload));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(2, OrderItem::query()->where('order_id', $order->id)->count(), 'The new item must be saved');
        $this->assertSame(Status::SHIPPING_PARTIALLY_SHIPPED, Order::query()->find($order->id)->shipping_status);
    }

    public function test_save_increasing_quantity_on_a_delivered_order_reopens_it_for_shipping(): void
    {
        [$order, $item] = $this->createPhysicalOrderWithItem(1);
        $this->shipItem($order, $item, 1);
        $shipment = Shipment::query()->where('order_id', $order->id)->first();
        ShipmentService::updateShipmentStatus($shipment, Shipment::STATUS_DELIVERED);
        $this->assertSame(Status::SHIPPING_DELIVERED, Order::query()->find($order->id)->shipping_status);

        $payload = $this->adminSavePayload($order->id);
        $payload['order_items'][0]['quantity'] = 2;
        $response = $this->dispatchRequest($this->updateOrderRequest($order->id, $payload));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(Status::SHIPPING_PARTIALLY_SHIPPED, Order::query()->find($order->id)->shipping_status);

        $this->shipItem($order, $item, 1);
        $this->assertSame(2, (int) OrderItem::query()->find($item->id)->fulfilled_quantity);
        $this->assertSame(Status::SHIPPING_SHIPPED, Order::query()->find($order->id)->shipping_status);
    }

    public function test_save_keeps_delivered_when_everything_is_still_fulfilled(): void
    {
        [$order, $item] = $this->createPhysicalOrderWithItem(2);
        $this->shipItem($order, $item, 2);
        // Delivered by hand while the shipment is still in transit.
        Order::query()->where('id', $order->id)->update(['shipping_status' => Status::SHIPPING_DELIVERED]);

        $payload = $this->adminSavePayload($order->id);
        $response = $this->dispatchRequest($this->updateOrderRequest($order->id, $payload));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(Status::SHIPPING_DELIVERED, Order::query()->find($order->id)->shipping_status);
    }

    public function test_save_never_moves_an_unshippable_order(): void
    {
        [$order, $item] = $this->createPhysicalOrderWithItem(1);
        $this->shipItem($order, $item, 1);
        Order::query()->where('id', $order->id)->update(['shipping_status' => Status::SHIPPING_UNSHIPPABLE]);

        $payload = $this->adminSavePayload($order->id);
        $payload['order_items'][0]['quantity'] = 2;
        $response = $this->dispatchRequest($this->updateOrderRequest($order->id, $payload));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(Status::SHIPPING_UNSHIPPABLE, Order::query()->find($order->id)->shipping_status);
    }

    public function test_save_without_shipments_or_fulfilment_keeps_the_manual_status(): void
    {
        [$order] = $this->createPhysicalOrderWithItem(2);
        Order::query()->where('id', $order->id)->update(['shipping_status' => Status::SHIPPING_SHIPPED]);

        $payload = $this->adminSavePayload($order->id);
        $payload['order_items'][0]['quantity'] = 3;
        $response = $this->dispatchRequest($this->updateOrderRequest($order->id, $payload));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(Status::SHIPPING_SHIPPED, Order::query()->find($order->id)->shipping_status);
    }

    public function test_save_dispatches_the_shipping_transition_once_after_commit(): void
    {
        [$order, $item] = $this->createPhysicalOrderWithItem(2);
        $this->shipItem($order, $item, 2);

        $events = [];
        $recorder = function ($data) use (&$events) {
            $events[] = [
                'old'   => $data['old_status'] ?? null,
                'new'   => $data['new_status'] ?? null,
                'level' => (int) \FluentCart\App\App::db()->transactionLevel(),
            ];
        };

        $payload = $this->adminSavePayload($order->id);
        $payload['order_items'][0]['quantity'] = 3;

        add_action('fluent_cart/shipping_status_changed', $recorder, 10, 1);
        try {
            $response = $this->dispatchRequest($this->updateOrderRequest($order->id, $payload));
        } finally {
            remove_action('fluent_cart/shipping_status_changed', $recorder, 10);
        }

        $this->assertSame(200, $response->get_status());
        $this->assertSame([
            ['old' => Status::SHIPPING_SHIPPED, 'new' => Status::SHIPPING_PARTIALLY_SHIPPED, 'level' => 0],
        ], $events);
    }

    /** A row for a new physical product, shaped like the edit screen's existing rows. */
    private function newPhysicalItemRow(array $template): array
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        $row = $template;
        unset($row['id'], $row['fulfilled_quantity'], $row['line_meta']);
        $row['post_id'] = $product->ID;
        $row['object_id'] = $variant->id;
        $row['fulfillment_type'] = 'physical';
        $row['quantity'] = 1;
        $row['other_info'] = [];

        return $row;
    }

    private function createPhysicalOrderWithItem(int $qty): array
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->withAddresses()->create([
            'customer_id'      => $customer->id,
            'fulfillment_type' => 'physical',
            'shipping_status'  => Status::SHIPPING_UNSHIPPED,
            'subtotal'         => Helper::toCent(29.99) * $qty,
            'total_amount'     => Helper::toCent(29.99) * $qty,
        ]);
        $item = $this->addPhysicalItem($order->id, $qty);

        return [Order::query()->find($order->id), $item];
    }

    private function addPhysicalItem(int $orderId, int $qty): OrderItem
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        return $this->itemFactory->create($orderId, [
            'post_id'            => $product->ID,
            'object_id'          => $variant->id,
            'fulfillment_type'   => 'physical',
            'quantity'           => $qty,
            'fulfilled_quantity' => 0,
            'subtotal'           => Helper::toCent(29.99) * $qty,
            'line_total'         => Helper::toCent(29.99) * $qty,
        ]);
    }

    private function shipItem(Order $order, OrderItem $item, int $qty): void
    {
        $shipment = ShipmentService::createShipment(Order::query()->find($order->id), [
            'tracking_number' => 'TRK-' . wp_generate_password(6, false),
            'items'           => [['order_item_id' => $item->id, 'qty' => $qty]],
        ]);
        $this->assertInstanceOf(Shipment::class, $shipment);
    }

    private function createEditableOrderWithItem(array $overrides = []): Order
    {
        $customer = $this->customerFactory->create();
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        $order = $this->orderFactory->create(array_merge([
            'customer_id'  => $customer->id,
            'subtotal'     => Helper::toCent(29.99),
            'total_amount' => Helper::toCent(29.99),
        ], $overrides));

        $this->itemFactory->create($order->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
        ]);

        return Order::query()->find($order->id);
    }

    /**
     * The order object the admin edit screen posts back: the details response
     * without customer and note (SingleOrder.vue update()).
     */
    private function adminSavePayload(int $orderId): array
    {
        $details = $this->dispatchRequest(
            new \WP_REST_Request('GET', '/fluent-cart/v2/orders/' . $orderId)
        )->get_data();

        $order = json_decode(wp_json_encode($details['order']), true);
        unset($order['customer'], $order['note']);

        return $order;
    }

    private function updateOrderRequest(int $orderId, array $payload): \WP_REST_Request
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/orders/' . $orderId);
        $request->set_body_params($payload);

        return $request;
    }
}
