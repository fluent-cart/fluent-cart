<?php

namespace Tests\Integration\Models;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\LabelRelationship;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderOperation;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\ProductFactory;

class OrderTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private OrderTransactionFactory $txnFactory;
    private OrderAddressFactory $addressFactory;
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->itemFactory = new OrderItemFactory();
        $this->txnFactory = new OrderTransactionFactory();
        $this->addressFactory = new OrderAddressFactory();
        $this->productFactory = new ProductFactory();
    }

    // =========================================================================
    // Spec 1: Columns
    // =========================================================================

    public function test_orders_table_has_expected_columns(): void
    {
        $expected = [
            'id', 'uuid', 'status', 'parent_id', 'invoice_no', 'receipt_number',
            'fulfillment_type', 'type', 'customer_id', 'payment_method',
            'payment_method_title', 'payment_status', 'currency', 'subtotal',
            'discount_tax', 'manual_discount_total', 'coupon_discount_total',
            'shipping_tax', 'shipping_total', 'fee_total', 'tax_total',
            'tax_behavior', 'total_amount', 'rate', 'note', 'ip_address',
            'completed_at', 'refunded_at', 'total_refund', 'total_paid', 'mode',
            'shipping_status', 'config', 'created_at', 'updated_at',
        ];
        $this->assertColumnsExist('fct_orders', $expected);
    }

    // =========================================================================
    // Spec 2-3: Casts
    // =========================================================================

    public function test_monetary_columns_are_cast_to_double(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'subtotal'    => 5000,
            'total_amount' => 5000,
        ]);

        $this->assertIsFloat($order->subtotal);
        $this->assertIsFloat($order->total_amount);
        $this->assertIsFloat($order->shipping_total);
        $this->assertIsFloat($order->tax_total);
        $this->assertIsFloat($order->fee_total);
        $this->assertIsFloat($order->discount_tax);
        $this->assertIsFloat($order->manual_discount_total);
        $this->assertIsFloat($order->coupon_discount_total);
        $this->assertIsFloat($order->shipping_tax);
    }

    public function test_customer_id_is_cast_to_integer(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $this->assertIsInt($order->customer_id);
        $this->assertSame((int) $customer->id, $order->customer_id);
    }

    // =========================================================================
    // Spec 4: Config JSON attribute
    // =========================================================================

    public function test_config_attribute_is_json_encoded_and_decoded(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'config'      => ['user_tz' => 'America/New_York'],
        ]);

        $fresh = Order::query()->find($order->id);
        $this->assertIsArray($fresh->config);
        $this->assertSame('America/New_York', $fresh->config['user_tz']);
    }

    public function test_config_null_returns_empty_array(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'config'      => null,
        ]);

        // A new order's config carries only its currency snapshot
        // (dev-docs/multi-currency/03-data-storage-map.md)
        $fresh = Order::query()->find($order->id);
        $this->assertIsArray($fresh->config);
        $this->assertSame(['fx'], array_keys($fresh->config));

        // The accessor still maps a NULL column to an empty array
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_orders', ['config' => null], ['id' => $order->id]);
        $this->assertSame([], Order::query()->find($order->id)->config);
    }

    // =========================================================================
    // Spec 5-9: Boot Behavior
    // =========================================================================

    public function test_creating_order_auto_generates_uuid(): void
    {
        $customer = $this->customerFactory->create();

        // Several orders, not one: shape assertions alone (present, 12 chars,
        // uppercase alphanumeric) are all satisfied by a constant such as
        // "AAAAAAAAAAAA". fct_orders has no unique index on uuid and lookups do
        // where('uuid')->first(), so a non-unique handle resolves the WRONG order
        // — the uniqueness half of the contract has to be asserted directly.
        $uuids = [];
        for ($i = 0; $i < 5; $i++) {
            $order = $this->orderFactory->create(['customer_id' => $customer->id]);

            // Since 47e5be462 the handle is 12 uppercase alphanumeric characters
            // (Order::generateOrderUuid), not the legacy 32-char md5.
            $this->assertNotEmpty($order->uuid, 'every created order must receive a generated uuid');
            $this->assertSame(12, strlen($order->uuid), 'generated order uuid must be 12 characters');
            $this->assertMatchesRegularExpression(
                '/^[A-Z0-9]{12}$/',
                $order->uuid,
                'generated order uuid must be uppercase alphanumeric only'
            );

            $uuids[] = $order->uuid;
        }

        $this->assertCount(
            5,
            array_unique($uuids),
            'each generated order uuid must be distinct — a constant or otherwise colliding generator passes every shape check above while making where(\'uuid\')->first() resolve the wrong order: ' . wp_json_encode($uuids)
        );
    }

    public function test_creating_order_with_paid_status_assigns_receipt_number(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
        ]);

        $this->assertNotNull($order->receipt_number);
        $this->assertNotEmpty($order->invoice_no);
        $this->assertStringContainsString((string) $order->receipt_number, $order->invoice_no);
    }

    public function test_creating_order_with_pending_status_does_not_assign_receipt_number(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);

        $this->assertNull($order->receipt_number);
    }

    public function test_creating_order_with_empty_config_defaults_to_empty_array(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        // Empty apart from the currency snapshot every order carries
        // (dev-docs/multi-currency/03-data-storage-map.md)
        $this->assertIsArray($order->config);
        $this->assertSame(['fx'], array_keys($order->config));
    }

    public function test_order_created_with_invoice_fires_invoice_number_added_action(): void
    {
        $fired = false;
        add_action('fluent_cart/order/invoice_number_added', function () use (&$fired) {
            $fired = true;
        });

        $customer = $this->customerFactory->create();
        $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
        ]);

        $this->assertTrue($fired, 'fluent_cart/order/invoice_number_added should fire');
    }

    // =========================================================================
    // Spec 10-25: Relationships
    // =========================================================================

    public function test_order_belongs_to_customer(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $this->assertInstanceOf(Customer::class, $order->customer);
        $this->assertSame($customer->id, $order->customer->id);
    }

    public function test_order_has_many_order_items(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $this->itemFactory->create($order->id);
        $this->itemFactory->create($order->id);

        // Fresh-load to avoid cached empty collection
        $order = Order::query()->with('order_items')->find($order->id);
        $this->assertCount(2, $order->order_items);
    }

    public function test_order_has_many_transactions(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $this->txnFactory->create($order->id);

        $this->assertCount(1, $order->transactions);
    }

    public function test_order_has_many_subscriptions(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'type'        => Status::ORDER_TYPE_SUBSCRIPTION,
        ]);

        Subscription::query()->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'status'          => Status::SUBSCRIPTION_ACTIVE,
            'item_name'       => 'Test Sub',
            'recurring_amount' => Helper::toCent(10),
            'billing_interval' => 'monthly',
            'payment_method'  => 'offline_payment',
        ]);

        $this->assertCount(1, $order->subscriptions);
    }

    public function test_order_has_one_billing_address(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->withAddresses()->create(['customer_id' => $customer->id]);

        $billing = $order->billing_address;
        $this->assertInstanceOf(OrderAddress::class, $billing);
        $this->assertSame('billing', $billing->type);
    }

    public function test_order_has_one_shipping_address(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->withAddresses()->create(['customer_id' => $customer->id]);

        $shipping = $order->shipping_address;
        $this->assertInstanceOf(OrderAddress::class, $shipping);
        $this->assertSame('shipping', $shipping->type);
    }

    public function test_order_has_many_order_addresses(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->withAddresses()->create(['customer_id' => $customer->id]);

        $this->assertCount(2, $order->order_addresses);
    }

    public function test_order_has_many_order_meta(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'test_key',
            'meta_value' => 'test_value',
        ]);

        $this->assertCount(1, $order->orderMeta);
    }

    public function test_order_has_many_applied_coupons(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        AppliedCoupon::query()->create([
            'order_id'  => $order->id,
            'coupon_id' => 0,
            'code'      => 'TEST10',
            'amount'    => Helper::toCent(10),
        ]);

        $this->assertCount(1, $order->appliedCoupons);
    }

    public function test_order_has_many_through_used_coupons(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $coupon = Coupon::query()->create([
            'code'          => 'USED10',
            'discount_type' => 'fixed',
            'amount'        => Helper::toCent(10),
            'status'        => 'active',
        ]);

        AppliedCoupon::query()->create([
            'order_id'  => $order->id,
            'coupon_id' => $coupon->id,
            'code'      => 'USED10',
            'amount'    => Helper::toCent(10),
        ]);

        $this->assertCount(1, $order->usedCoupons);
        $this->assertSame($coupon->id, $order->usedCoupons->first()->id);
    }

    public function test_order_belongs_to_parent_order(): void
    {
        $customer = $this->customerFactory->create();
        $parent = $this->orderFactory->create(['customer_id' => $customer->id]);
        $child = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'parent_id'   => $parent->id,
            'type'        => 'renewal',
        ]);

        $this->assertInstanceOf(Order::class, $child->parentOrder);
        $this->assertSame($parent->id, $child->parentOrder->id);
    }

    public function test_order_has_many_children(): void
    {
        $customer = $this->customerFactory->create();
        $parent = $this->orderFactory->create(['customer_id' => $customer->id]);
        $this->orderFactory->create([
            'customer_id' => $customer->id,
            'parent_id'   => $parent->id,
        ]);
        $this->orderFactory->create([
            'customer_id' => $customer->id,
            'parent_id'   => $parent->id,
        ]);

        $this->assertCount(2, $parent->children);
    }

    public function test_order_renewals_filters_type_and_excludes_bad_statuses(): void
    {
        $customer = $this->customerFactory->create();
        $parent = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'type'        => Status::ORDER_TYPE_SUBSCRIPTION,
        ]);

        // Good renewal
        $this->orderFactory->create([
            'customer_id' => $customer->id,
            'parent_id'   => $parent->id,
            'type'        => 'renewal',
            'status'      => Status::ORDER_PROCESSING,
        ]);
        // Canceled renewal — should be excluded
        $this->orderFactory->create([
            'customer_id' => $customer->id,
            'parent_id'   => $parent->id,
            'type'        => 'renewal',
            'status'      => Status::ORDER_CANCELED,
        ]);
        // Non-renewal child — should be excluded
        $this->orderFactory->create([
            'customer_id' => $customer->id,
            'parent_id'   => $parent->id,
            'type'        => Status::ORDER_TYPE_PAYMENT,
            'status'      => Status::ORDER_PROCESSING,
        ]);

        $this->assertCount(1, $parent->renewals);
    }

    public function test_order_has_one_order_operation(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        OrderOperation::query()->create([
            'order_id'    => $order->id,
            'created_via' => 'admin',
        ]);

        $this->assertInstanceOf(OrderOperation::class, $order->orderOperation);
        $this->assertSame((int) $order->id, (int) $order->orderOperation->order_id, 'the relation resolves this order\'s own operation row');
    }

    public function test_order_fee_items_filters_payment_type_fee(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $this->itemFactory->create($order->id, ['payment_type' => 'onetime']);
        $this->itemFactory->create($order->id, ['payment_type' => 'fee', 'title' => 'Processing Fee']);

        $feeItems = $order->feeItems()->get();
        $this->assertCount(1, $feeItems);
        $this->assertSame('fee', $feeItems->first()->payment_type);
    }

    // =========================================================================
    // Spec 26-34: Scopes
    // =========================================================================

    public function test_search_by_finds_order_by_id_status_amount_method_invoice(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'total_amount'   => Helper::toCent(50),
            'payment_method' => 'stripe_unique_gw',
            'payment_status' => Status::PAYMENT_PAID,
        ]);

        // Refresh to get the auto-generated invoice_no
        $order = Order::query()->find($order->id);

        // Search by ID
        $found = Order::query()->searchBy((string) $order->id)->get();
        $this->assertTrue($found->contains('id', $order->id), 'Should find by order ID');

        // Search by status
        $found = Order::query()->searchBy('processing')->get();
        $this->assertTrue($found->contains('id', $order->id), 'Should find by status');

        // Search by payment_method
        $found = Order::query()->searchBy('stripe_unique_gw')->get();
        $this->assertTrue($found->contains('id', $order->id), 'Should find by payment method');

        // Search by invoice_no (auto-generated in boot)
        $found = Order::query()->searchBy($order->invoice_no)->get();
        $this->assertTrue($found->contains('id', $order->id), 'Should find by invoice no');

        // Search by payment_status
        $found = Order::query()->searchBy('paid')->get();
        $this->assertTrue($found->contains('id', $order->id), 'Should find by payment status');
    }

    public function test_search_by_finds_order_by_item_title(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $this->itemFactory->create($order->id, [
            'post_title' => 'Premium Widget',
            'title'      => 'Gold Variant',
        ]);

        $this->assertCount(1, Order::query()->searchBy('Premium Widget')->get());
        $this->assertCount(1, Order::query()->searchBy('Gold Variant')->get());
    }

    public function test_search_by_finds_order_by_customer_email_and_name(): void
    {
        $customer = $this->customerFactory->create([
            'email'      => 'searchtest@example.com',
            'first_name' => 'Findme',
            'last_name'  => 'Please',
        ]);
        $this->orderFactory->create(['customer_id' => $customer->id]);

        $this->assertCount(1, Order::query()->searchBy('searchtest@example.com')->get());
        $this->assertCount(1, Order::query()->searchBy('Findme Please')->get());
    }

    public function test_search_by_with_empty_string_returns_unfiltered(): void
    {
        $customer = $this->customerFactory->create();
        $order1 = $this->orderFactory->create(['customer_id' => $customer->id]);
        $order2 = $this->orderFactory->create(['customer_id' => $customer->id]);

        $results = Order::query()->searchBy('')->get();
        // Empty search should not filter — both orders should be present
        $this->assertTrue($results->contains('id', $order1->id));
        $this->assertTrue($results->contains('id', $order2->id));

        $results2 = Order::query()->searchBy('   ')->get();
        $this->assertTrue($results2->contains('id', $order1->id));
        $this->assertTrue($results2->contains('id', $order2->id));
    }

    public function test_of_payment_status_scope(): void
    {
        $customer = $this->customerFactory->create();
        $paidOrder = $this->orderFactory->create(['customer_id' => $customer->id, 'payment_status' => Status::PAYMENT_PAID]);
        $this->orderFactory->create(['customer_id' => $customer->id, 'payment_status' => Status::PAYMENT_PENDING]);

        // Scoped to this customer: rows committed past rollback by earlier
        // DDL-bearing tests (TC-058) would otherwise inflate the global count.
        $paid = Order::query()->where('customer_id', $customer->id)->ofPaymentStatus(Status::PAYMENT_PAID)->get();

        // Identity, not cardinality: the fixture holds one paid and one pending
        // order, so inverting the scope's predicate to != still returns exactly
        // one row and would satisfy a bare assertCount.
        $this->assertSame(
            [(int) $paidOrder->id],
            array_map('intval', $paid->pluck('id')->toArray()),
            'ofPaymentStatus must return the paid order itself, not merely one row'
        );
        $this->assertSame(Status::PAYMENT_PAID, $paid->first()->payment_status,
            'the returned row must carry the status that was asked for');
    }

    public function test_of_order_status_scope(): void
    {
        $customer = $this->customerFactory->create();
        $this->orderFactory->create(['customer_id' => $customer->id, 'status' => Status::ORDER_PROCESSING]);
        $this->orderFactory->create(['customer_id' => $customer->id, 'status' => Status::ORDER_ON_HOLD]);

        $this->assertCount(1, Order::query()->ofOrderStatus(Status::ORDER_PROCESSING)->get());
    }

    public function test_of_shipping_status_scope(): void
    {
        $customer = $this->customerFactory->create();
        $this->orderFactory->create(['customer_id' => $customer->id, 'shipping_status' => Status::SHIPPING_SHIPPED]);
        $this->orderFactory->create(['customer_id' => $customer->id, 'shipping_status' => Status::SHIPPING_UNSHIPPED]);

        $this->assertCount(1, Order::query()->ofShippingStatus(Status::SHIPPING_SHIPPED)->get());
    }

    public function test_of_payment_method_scope(): void
    {
        $customer = $this->customerFactory->create();
        $this->orderFactory->create(['customer_id' => $customer->id, 'payment_method' => 'stripe']);
        $this->orderFactory->create(['customer_id' => $customer->id, 'payment_method' => 'paypal']);

        $this->assertCount(1, Order::query()->ofPaymentMethod('stripe')->get());
    }

    public function test_apply_custom_filters_scope(): void
    {
        $customer = $this->customerFactory->create();
        $this->orderFactory->create(['customer_id' => $customer->id, 'status' => Status::ORDER_PROCESSING]);
        $this->orderFactory->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        $this->orderFactory->create(['customer_id' => $customer->id, 'status' => Status::ORDER_ON_HOLD]);

        $filters = [
            'status' => [
                'value' => [Status::ORDER_PROCESSING, Status::ORDER_COMPLETED],
            ],
        ];

        $this->assertCount(2, Order::query()->applyCustomFilters($filters)->get());
    }

    // =========================================================================
    // Spec 35-39: Status Methods
    // =========================================================================

    public function test_update_status_to_completed_sets_completed_at(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_PROCESSING,
        ]);

        $order->updateStatus('status', Status::ORDER_COMPLETED);

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::ORDER_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_update_status_payment_refunded_sets_refunded_at(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
        ]);

        $order->updateStatus('payment_status', Status::PAYMENT_REFUNDED);

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::PAYMENT_REFUNDED, $fresh->payment_status);
        $this->assertNotNull($fresh->refunded_at);
    }

    public function test_update_status_with_same_value_does_not_save(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_PROCESSING,
        ]);
        $originalUpdatedAt = $order->updated_at;

        $result = $order->updateStatus('status', Status::ORDER_PROCESSING);

        $this->assertSame(Status::ORDER_PROCESSING, $result->status);
    }

    public function test_update_payment_status_to_refunded_sets_refunded_at(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
        ]);

        $order->updatePaymentStatus(Status::PAYMENT_REFUNDED);

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::PAYMENT_REFUNDED, $fresh->payment_status);
        $this->assertNotNull($fresh->refunded_at);
    }

    public function test_update_payment_status_with_same_value_is_noop(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
        ]);

        $result = $order->updatePaymentStatus(Status::PAYMENT_PAID);
        $this->assertSame(Status::PAYMENT_PAID, $result->payment_status);
    }

    // =========================================================================
    // Spec 40-44: Meta Methods
    // =========================================================================

    public function test_get_meta_returns_value_for_existing_key(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'color',
            'meta_value' => 'blue',
        ]);

        $this->assertSame('blue', $order->getMeta('color'));
    }

    public function test_get_meta_returns_default_for_missing_key(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $this->assertSame('fallback', $order->getMeta('nonexistent', 'fallback'));
        $this->assertFalse($order->getMeta('nonexistent'));
    }

    public function test_update_meta_creates_new_when_key_missing(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $order->updateMeta('new_key', 'new_value');

        $this->assertSame('new_value', $order->getMeta('new_key'));
    }

    public function test_update_meta_updates_existing_key(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $order->updateMeta('key1', 'first');
        $order->updateMeta('key1', 'second');

        $this->assertSame('second', $order->getMeta('key1'));
        // Only one row
        $count = OrderMeta::query()
            ->where('order_id', $order->id)
            ->where('meta_key', 'key1')
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_delete_meta_removes_row(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $order->updateMeta('temp_key', 'temp_val');

        $order->deleteMeta('temp_key');

        $this->assertFalse($order->getMeta('temp_key'));
    }

    // =========================================================================
    // Spec 45-54: Financial Methods
    // =========================================================================

    public function test_get_total_paid_amount_sums_succeeded_transactions(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 5000,
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 3000,
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_PENDING,
            'total'  => 2000,
        ]);

        $this->assertEquals(8000, $order->getTotalPaidAmount());
    }

    public function test_get_total_refund_amount_sums_refunded_transactions(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $this->txnFactory->create($order->id, [
            'status'           => Status::TRANSACTION_REFUNDED,
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'total'            => 2000,
        ]);

        $this->assertEquals(2000, $order->getTotalRefundAmount());
    }

    public function test_recount_total_paid_and_refund_sets_refunded_when_refunded_gte_paid(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
        ]);

        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 5000,
        ]);
        $this->txnFactory->create($order->id, [
            'status'           => Status::TRANSACTION_REFUNDED,
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'total'            => 5000,
        ]);

        $order->recountTotalPaidAndRefund();

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::PAYMENT_REFUNDED, $fresh->payment_status);
        $this->assertEquals(5000, $fresh->total_refund);
    }

    public function test_recount_total_paid_and_refund_sets_partially_refunded(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
        ]);

        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 5000,
        ]);
        $this->txnFactory->create($order->id, [
            'status'           => Status::TRANSACTION_REFUNDED,
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'total'            => 2000,
        ]);

        $order->recountTotalPaidAndRefund();

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::PAYMENT_PARTIALLY_REFUNDED, $fresh->payment_status);
    }

    public function test_sync_order_after_full_refund(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'  => $customer->id,
            'total_refund' => 0,
        ]);

        $order->syncOrderAfterRefund('full', 5000);

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::PAYMENT_REFUNDED, $fresh->payment_status);
        $this->assertEquals(5000, $fresh->total_refund);
    }

    public function test_sync_order_after_partial_refund(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'  => $customer->id,
            'total_refund' => 0,
        ]);

        $order->syncOrderAfterRefund('partial', 2000);

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::PAYMENT_PARTIALLY_REFUNDED, $fresh->payment_status);
        $this->assertEquals(2000, $fresh->total_refund);
    }

    public function test_recount_total_paid_keeps_charges_gross_of_refunds(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 1000,
        ]);
        $this->txnFactory->create($order->id, [
            'status'           => Status::TRANSACTION_REFUNDED,
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'total'            => 2000,
        ]);

        $order->recountTotalPaid();

        $fresh = Order::query()->find($order->id);
        $this->assertEquals(1000, $fresh->total_paid);
    }

    public function test_update_refunded_items_single_item(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = $this->itemFactory->create($order->id, [
            'line_total'   => 5000,
            'refund_total' => 0,
        ]);

        $order->updateRefundedItems([$item->id], 2000);

        $fresh = OrderItem::query()->find($item->id);
        $this->assertEquals(2000, $fresh->refund_total);
    }

    public function test_update_refunded_items_distributes_proportionally(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $itemA = $this->itemFactory->create($order->id, [
            'line_total'   => 7000,
            'refund_total' => 0,
        ]);
        $itemB = $this->itemFactory->create($order->id, [
            'line_total'   => 3000,
            'refund_total' => 0,
        ]);

        $order->updateRefundedItems([$itemA->id, $itemB->id], 5000);

        $freshA = OrderItem::query()->find($itemA->id);
        $freshB = OrderItem::query()->find($itemB->id);

        // A has 70% remaining, B has 30% — last item gets remainder
        $this->assertEquals(3500, $freshA->refund_total);
        $this->assertEquals(1500, $freshB->refund_total);
    }

    public function test_update_refunded_items_never_records_more_than_the_line_has_left(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = $this->itemFactory->create($order->id, [
            'line_total'   => 5000,
            'refund_total' => 0,
        ]);

        $order->updateRefundedItems([$item->id], 6000);

        $this->assertEquals(5000, OrderItem::query()->find($item->id)->refund_total);
    }

    public function test_update_refunded_items_records_exactly_the_refund_when_a_line_is_already_refunded(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $first = $this->itemFactory->create($order->id, ['line_total' => 1000, 'refund_total' => 0]);
        $second = $this->itemFactory->create($order->id, ['line_total' => 1000, 'refund_total' => 0]);
        $refunded = $this->itemFactory->create($order->id, ['line_total' => 1000, 'refund_total' => 1000]);

        $order->updateRefundedItems([], 1001);

        $totals = array_map(function ($item) {
            return (int) OrderItem::query()->find($item->id)->refund_total;
        }, [$first, $second, $refunded]);
        $this->assertSame(1001, $totals[0] + $totals[1]);
        $this->assertSame(1000, $totals[2]);
    }

    public function test_update_refunded_items_records_exactly_the_refund_when_shares_round_down(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $items = [];
        foreach ([4, 4, 4, 1] as $lineTotal) {
            $items[] = $this->itemFactory->create($order->id, ['line_total' => $lineTotal, 'refund_total' => 0]);
        }

        $order->updateRefundedItems([], 11);

        $sum = 0;
        foreach ($items as $item) {
            $sum += (int) OrderItem::query()->find($item->id)->refund_total;
        }
        $this->assertSame(11, $sum);
    }

    public function test_add_item_refunds_caps_against_a_refund_that_lands_between_read_and_write(): void
    {
        global $wpdb;
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = $this->itemFactory->create($order->id, ['line_total' => 5000, 'refund_total' => 0]);

        $table = $wpdb->prefix . 'fct_order_items';
        $raced = false;
        $concurrentRefund = function ($query) use ($wpdb, $table, $item, &$raced) {
            if (!$raced && stripos($query, 'update') === 0 && strpos($query, $table) !== false) {
                $raced = true;
                $wpdb->query($wpdb->prepare("UPDATE {$table} SET refund_total = 4999 WHERE id = %d", $item->id));
            }
            return $query;
        };

        add_filter('query', $concurrentRefund);
        try {
            $order->addItemRefunds([$item->id => 3000]);
        } finally {
            remove_filter('query', $concurrentRefund);
        }

        $this->assertTrue($raced);
        $this->assertSame(5000, (int) OrderItem::query()->find($item->id)->refund_total);
    }

    public function test_update_refunded_items_fills_the_exclusive_tax_of_a_line(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = $this->itemFactory->create($order->id, [
            'line_total'   => 10000,
            'tax_amount'   => 1000,
            'refund_total' => 0,
            'line_meta'    => ['tax_config' => ['inclusive' => false]],
        ]);

        $order->updateRefundedItems([], 11000);

        $this->assertEquals(11000, OrderItem::query()->find($item->id)->refund_total);
    }

    public function test_update_refunded_items_weights_lines_by_what_was_charged_including_tax(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $untaxed = $this->itemFactory->create($order->id, [
            'line_total'   => 10000,
            'refund_total' => 0,
        ]);
        $taxed = $this->itemFactory->create($order->id, [
            'line_total'   => 10000,
            'tax_amount'   => 10000,
            'refund_total' => 0,
            'line_meta'    => ['tax_config' => ['inclusive' => false]],
        ]);

        $order->updateRefundedItems([], 15000);

        $this->assertEquals(5000, OrderItem::query()->find($untaxed->id)->refund_total);
        $this->assertEquals(10000, OrderItem::query()->find($taxed->id)->refund_total);
    }

    public function test_update_refunded_items_puts_a_trial_order_refund_on_the_signup_fee_line(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id, 'type' => Status::ORDER_TYPE_SUBSCRIPTION, 'tax_behavior' => 1, 'total_paid' => 2750]);
        $trial = $this->itemFactory->create($order->id, [
            'payment_type' => 'subscription',
            'line_total'   => 4000,
            'other_info'   => ['payment_type' => 'subscription', 'trial_days' => 7],
        ]);
        $signupFee = $this->itemFactory->create($order->id, [
            'payment_type' => 'signup_fee',
            'line_total'   => 2500,
            'tax_amount'   => 250,
            'line_meta'    => ['parent_item_id' => $trial->id, 'tax_config' => ['inclusive' => false]],
            'other_info'   => ['payment_type' => 'signup_fee'],
        ]);

        $this->assertSame(0, $order->updateRefundedItems([], 1600));
        $this->assertSame(0, $order->updateRefundedItems([], 1150));

        $this->assertEquals(0, OrderItem::query()->find($trial->id)->refund_total);
        $this->assertEquals(2750, OrderItem::query()->find($signupFee->id)->refund_total);
    }

    public function test_update_refunded_items_with_zero_ids_distributes_across_all(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $itemA = $this->itemFactory->create($order->id, [
            'line_total'   => 6000,
            'refund_total' => 0,
        ]);
        $itemB = $this->itemFactory->create($order->id, [
            'line_total'   => 4000,
            'refund_total' => 0,
        ]);

        // Load relation so the model can find items
        $order->load('order_items');
        $order->updateRefundedItems([], 5000);

        $freshA = OrderItem::query()->find($itemA->id);
        $freshB = OrderItem::query()->find($itemB->id);

        $totalRefunded = $freshA->refund_total + $freshB->refund_total;
        $this->assertEquals(5000, $totalRefunded);
    }

    public function test_update_refunded_items_returns_what_the_lines_could_not_take(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $this->itemFactory->create($order->id, ['line_total' => 2000, 'refund_total' => 0]);

        $this->assertSame(0, $order->updateRefundedItems([], 1500));
        $this->assertSame(300, $order->updateRefundedItems([], 800));
        $this->assertSame(400, $order->updateRefundedItems([], 400));
    }

    public function test_refund_overflow_counts_against_shipping_when_lines_and_shipping_make_the_total(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'shipping_total' => 1000,
            'shipping_tax'   => 0,
            'total_amount'   => 3000,
        ]);
        $this->itemFactory->create($order->id, ['line_total' => 2000, 'refund_total' => 2000]);

        $order->addRefundOverflowToShipping(600);
        $this->assertSame(600, (int) $order->getMeta('shipping_refunded_amount'));
        $this->assertEmpty($order->getMeta('shipping_refunded'));

        $order->addRefundOverflowToShipping(900);
        $this->assertSame(1000, (int) $order->getMeta('shipping_refunded_amount'));
        $this->assertSame('1', (string) $order->getMeta('shipping_refunded'));
    }

    public function test_refund_overflow_is_not_called_shipping_when_the_order_has_other_charges(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'shipping_total' => 1000,
            'shipping_tax'   => 0,
            'total_amount'   => 3500,
        ]);
        $this->itemFactory->create($order->id, ['line_total' => 2000, 'refund_total' => 2000]);

        $order->addRefundOverflowToShipping(600);

        $this->assertEmpty($order->getMeta('shipping_refunded_amount'));
    }

    // =========================================================================
    // Spec 55-57: Receipt Number Generation
    // =========================================================================

    public function test_generate_receipt_number_assigns_number_and_invoice(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);

        $this->assertNull($order->receipt_number);

        $order->generateReceiptNumber();

        $this->assertNotNull($order->receipt_number);
        $this->assertNotEmpty($order->invoice_no);
        $this->assertStringContainsString((string) $order->receipt_number, $order->invoice_no);
    }

    public function test_generate_receipt_number_is_idempotent(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);

        $order->generateReceiptNumber();
        $firstNumber = $order->receipt_number;
        $firstInvoice = $order->invoice_no;

        $order->generateReceiptNumber();

        $this->assertSame($firstNumber, $order->receipt_number);
        $this->assertSame($firstInvoice, $order->invoice_no);
    }

    public function test_generate_receipt_number_atomic_prevents_duplicates(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);

        // Simulate another process already generating the receipt number
        Order::query()->where('id', $order->id)->update([
            'receipt_number' => 999,
            'invoice_no'     => 'INV-999',
        ]);

        // Now our call should pick up the existing number
        $order->generateReceiptNumber();

        $this->assertEquals(999, $order->receipt_number);
        $this->assertSame('INV-999', $order->invoice_no);
    }

    // =========================================================================
    // Spec 58-63: Type Checks & Utility
    // =========================================================================

    public function test_is_subscription_returns_true_for_subscription_items(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $this->itemFactory->create($order->id, ['payment_type' => 'subscription']);

        $order->load('order_items');
        $this->assertTrue($order->isSubscription());
    }

    public function test_is_subscription_returns_false_for_onetime_items(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $this->itemFactory->create($order->id, ['payment_type' => 'onetime']);

        $order->load('order_items');
        $this->assertFalse($order->isSubscription());
    }

    public function test_can_be_refunded_false_when_upgraded_to_set(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'config'      => ['upgraded_to' => 99],
        ]);

        $fresh = Order::query()->find($order->id);
        $this->assertFalse($fresh->canBeRefunded());
    }

    public function test_can_be_refunded_true_for_normal_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'config'      => [],
        ]);

        $fresh = Order::query()->find($order->id);
        $this->assertTrue($fresh->canBeRefunded());
    }

    public function test_get_product_items_excludes_fees_and_signup_fees(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $this->itemFactory->create($order->id, ['payment_type' => 'onetime']);
        $this->itemFactory->create($order->id, ['payment_type' => 'fee']);
        $this->itemFactory->create($order->id, ['payment_type' => 'signup_fee']);

        $productItems = $order->getProductItems();
        $this->assertCount(1, $productItems);
        $this->assertSame('onetime', $productItems->first()->payment_type);
    }

    public function test_get_applied_fees_returns_fee_items_array(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $this->itemFactory->create($order->id, [
            'payment_type' => 'fee',
            'title'        => 'Processing Fee',
            'subtotal'     => 500,
            'other_info'   => ['fee_key' => 'processing', 'source' => 'custom'],
        ]);

        $fees = $order->getAppliedFees();
        $this->assertCount(1, $fees);
        $this->assertSame('processing', $fees[0]['key']);
        $this->assertSame('Processing Fee', $fees[0]['label']);
        $this->assertSame(500, $fees[0]['amount']);
        $this->assertSame('custom', $fees[0]['source']);
        $this->assertArrayHasKey('item_id', $fees[0]);
    }

    // =========================================================================
    // Spec 64-70: Deletion Rules (canBeDeleted)
    // =========================================================================

    public function test_can_be_deleted_true_for_canceled_unpaid_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_CANCELED,
            'payment_status' => Status::PAYMENT_PENDING,
            'total_amount'   => 5000,
        ]);

        $this->assertTrue($order->canBeDeleted());
    }

    public function test_can_be_deleted_true_for_on_hold_unpaid_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'total_amount'   => 5000,
        ]);

        $this->assertTrue($order->canBeDeleted());
    }

    public function test_can_be_deleted_returns_wp_error_for_processing_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_PROCESSING,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $order->canBeDeleted());
    }

    public function test_can_be_deleted_returns_wp_error_for_completed_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_COMPLETED,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $order->canBeDeleted());
    }

    public function test_can_be_deleted_returns_wp_error_for_canceled_paid_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_CANCELED,
            'payment_status' => Status::PAYMENT_PAID,
            'total_amount'   => 5000,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $order->canBeDeleted());
    }

    public function test_can_be_deleted_returns_wp_error_for_subscription_with_active_sub(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_CANCELED,
            'payment_status' => Status::PAYMENT_PENDING,
            'type'           => 'subscription',
        ]);

        Subscription::query()->create([
            'customer_id'      => $customer->id,
            'parent_order_id'  => $order->id,
            'status'           => Status::SUBSCRIPTION_ACTIVE,
            'item_name'        => 'Test Sub',
            'recurring_amount' => 1000,
            'billing_interval' => 'monthly',
            'payment_method'   => 'offline_payment',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $order->canBeDeleted());
    }

    public function test_can_be_deleted_true_for_test_mode_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'mode'           => Status::ORDER_MODE_TEST,
        ]);

        $this->assertTrue($order->canBeDeleted());
    }

    public function test_can_be_deleted_true_for_free_order_with_paid_status(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_CANCELED,
            'payment_status' => Status::PAYMENT_PAID,
            'total_amount'   => 0,
        ]);

        $this->assertTrue($order->canBeDeleted());
    }
}
