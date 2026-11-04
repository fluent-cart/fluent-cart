<?php

namespace Tests\Integration\Events;

use FluentCart\App\Events\Order\OrderBulkAction;
use FluentCart\App\Events\Order\OrderCanceled;
use FluentCart\App\Events\Order\OrderCreated;
use FluentCart\App\Events\Order\OrderDeleted;
use FluentCart\App\Events\Order\OrderDeleting;
use FluentCart\App\Events\Order\OrderPaid;
use FluentCart\App\Events\Order\OrderPaymentFailed;
use FluentCart\App\Events\Order\OrderRefund;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Events\Order\OrderUpdated;
use FluentCart\App\Events\Order\RenewalOrderDeleted;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Listeners;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\Payments\Refund;
use lucatume\WPBrowser\TestCase\WPTestCase;
use ReflectionClass;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\RequiresEmailVerification;

class OrderEventsTest extends WPTestCase
{
    use RequiresEmailVerification;

    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private OrderTransactionFactory $txnFactory;
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->requireEmailVerification();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->itemFactory = new OrderItemFactory();
        $this->txnFactory = new OrderTransactionFactory();
        $this->productFactory = new ProductFactory();
    }

    protected function tearDown(): void
    {
        $this->restoreEmailVerificationDefault();
        parent::tearDown();
    }

    private function reflectListeners(object $event): array
    {
        $ref = new ReflectionClass($event);
        $prop = $ref->getProperty('listeners');
        $prop->setAccessible(true);
        return $prop->getValue($event);
    }

    private function makeOrder(array $overrides = []): Order
    {
        $customer = $this->customerFactory->create();
        return $this->orderFactory->create(array_merge(['customer_id' => $customer->id], $overrides));
    }

    // =========================================================================
    // OrderCreated (specs 14-20)
    // =========================================================================

    public function test_order_created_hook_autofire_and_listeners(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $event = new OrderCreated($order, null, $customer);

        $this->assertSame('fluent_cart/order_created', $event->hook);
        $this->assertTrue($event->autoFireHook);
        $this->assertSame([Listeners\Order\OrderCreated::class], $this->reflectListeners($event));
    }

    public function test_order_created_constructor_loads_relations(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->withAddresses()->create(['customer_id' => $customer->id]);

        $event = new OrderCreated($order, null, $customer);

        $this->assertTrue($event->order->relationLoaded('customer'));
        $this->assertTrue($event->order->relationLoaded('shipping_address'));
        $this->assertTrue($event->order->relationLoaded('billing_address'));
    }

    public function test_order_created_to_array_keys(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $event = new OrderCreated($order, null, $customer);
        $data = $event->toArray();

        $this->assertArrayHasKey('order', $data);
        $this->assertArrayHasKey('prev_order', $data);
        $this->assertArrayHasKey('customer', $data);
        $this->assertArrayHasKey('transaction', $data);
    }

    public function test_order_created_fires_do_action_with_payload(): void
    {
        $fired = null;
        add_action('fluent_cart/order_created', function ($data) use (&$fired) {
            $fired = $data;
        });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        (new OrderCreated($order, null, $customer))->dispatch();

        $this->assertIsArray($fired);
        $this->assertArrayHasKey('order', $fired);
        $this->assertArrayHasKey('customer', $fired);
        $this->assertArrayHasKey('transaction', $fired);
    }

    public function test_order_created_listener_recounts_customer_stats(): void
    {
        $customer = $this->customerFactory->create(['purchase_count' => 0, 'ltv' => 0]);
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
            'total_paid'     => Helper::toCent(50),
        ]);

        Listeners\Order\OrderCreated::handle(new OrderCreated($order, null, $customer));

        $fresh = Customer::query()->find($customer->id);
        $this->assertSame(1, (int) $fresh->purchase_count);
        $this->assertSame(Helper::toCent(50), (int) $fresh->ltv);
    }

    public function test_order_created_listener_noop_without_customer_id(): void
    {
        // Pre-existing customer should NOT have its stats touched when the event order has no customer_id.
        $other = $this->customerFactory->create([
            'purchase_count' => 7,
            'ltv'            => Helper::toCent(123),
        ]);

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $order->customer_id = 0;

        Listeners\Order\OrderCreated::handle(new OrderCreated($order, null, $customer));

        $freshOther = Customer::query()->find($other->id);
        $this->assertSame(7, (int) $freshOther->purchase_count);
        $this->assertSame(Helper::toCent(123), (int) $freshOther->ltv);
    }

    public function test_order_created_activity_logged(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        (new OrderCreated($order, null, $customer))->dispatch();

        $log = Activity::query()
            ->where('module_type', Order::class)
            ->where('module_id', $order->id)
            ->orderBy('id', 'DESC')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame('Order Created', $log->title);
    }

    // =========================================================================
    // OrderPaid (specs 21-35)
    // =========================================================================

    public function test_order_paid_hook_autofire_and_listeners(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $event = new OrderPaid($order);

        $this->assertSame('fluent_cart/order_paid', $event->hook);
        $this->assertTrue($event->autoFireHook);
        $this->assertSame([Listeners\Order\OrderPaid::class], $this->reflectListeners($event));
    }

    public function test_order_paid_loads_relations(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->withItems([[
            'post_title'       => 'Test',
            'title'            => 'Default',
            'quantity'         => 1,
            'unit_price'       => Helper::toCent(29),
            'line_total'       => Helper::toCent(29),
            'payment_type'     => 'onetime',
            'fulfillment_type' => 'digital',
        ]])->withAddresses()->create(['customer_id' => $customer->id]);

        $event = new OrderPaid($order);

        $this->assertTrue($event->order->relationLoaded('customer'));
        $this->assertTrue($event->order->relationLoaded('order_items'));
        $this->assertTrue($event->order->relationLoaded('shipping_address'));
        $this->assertTrue($event->order->relationLoaded('billing_address'));
    }

    public function test_order_paid_before_dispatch_generates_receipt_number(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $this->assertEmpty($order->receipt_number);

        (new OrderPaid($order))->dispatch();

        $fresh = Order::query()->find($order->id);
        $this->assertNotEmpty($fresh->receipt_number);
    }

    public function test_order_paid_before_dispatch_keeps_existing_receipt(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'receipt_number' => 999999,
            'invoice_no'     => 'INV-999999',
        ]);

        (new OrderPaid($order))->dispatch();

        $fresh = Order::query()->find($order->id);
        $this->assertSame('999999', (string) $fresh->receipt_number);
    }

    public function test_order_paid_after_dispatch_enqueues_async_action(): void
    {

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        (new OrderPaid($order))->dispatch();

        $metaRow = OrderMeta::query()->where('order_id', $order->id)->where('meta_key', 'action_scheduler_id')->first();
        $this->assertNotNull($metaRow);
        $this->assertNotEmpty($metaRow->meta_value);
    }

    public function test_order_paid_after_dispatch_writes_action_scheduler_id_meta(): void
    {

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        (new OrderPaid($order))->dispatch();

        $meta = OrderMeta::query()->where('order_id', $order->id)->where('meta_key', 'action_scheduler_id')->first();
        $this->assertNotNull($meta);
        $this->assertGreaterThan(0, (int) $meta->meta_value);
    }

    public function test_order_paid_listener_recounts_total_paid(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory
            ->withTransaction(['status' => Status::TRANSACTION_SUCCEEDED, 'total' => Helper::toCent(80)])
            ->create(['customer_id' => $customer->id, 'total_paid' => 0]);

        Listeners\Order\OrderPaid::handle(new OrderPaid($order));

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Helper::toCent(80), (int) $fresh->total_paid);
    }

    public function test_order_paid_listener_recounts_customer_stats(): void
    {
        $customer = $this->customerFactory->create(['ltv' => 0, 'purchase_count' => 0]);
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
            'total_paid'     => Helper::toCent(50),
        ]);

        Listeners\Order\OrderPaid::handle(new OrderPaid($order));

        $fresh = Customer::query()->find($customer->id);
        $this->assertSame(1, (int) $fresh->purchase_count);
    }

    public function test_order_paid_maybe_create_user_noop_when_customer_has_user(): void
    {
        $customer = $this->customerFactory->createWithUser();
        $existingUserId = $customer->user_id;
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        Listeners\Order\OrderPaid::maybeCreateUser($order);

        $fresh = Customer::query()->find($customer->id);
        $this->assertSame($existingUserId, (int) $fresh->user_id);
    }

    public function test_order_paid_maybe_create_user_creates_when_config_flag_yes(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'config'      => ['create_account_after_paid' => 'yes'],
        ]);

        Listeners\Order\OrderPaid::maybeCreateUser($order);

        $fresh = Customer::query()->find($customer->id);
        $this->assertGreaterThan(0, (int) email_exists($fresh->email), 'Account creation must still occur.');
        $this->assertSame((int) email_exists($fresh->email), (int) $fresh->user_id, 'A first purchase links its own customer.');
    }

    public function test_order_paid_maybe_create_user_creates_for_subscription_type(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'type'        => 'subscription',
        ]);

        Listeners\Order\OrderPaid::maybeCreateUser($order);

        $fresh = Customer::query()->find($customer->id);
        $this->assertGreaterThan(0, (int) email_exists($fresh->email), 'Account creation must still occur.');
        $this->assertSame((int) email_exists($fresh->email), (int) $fresh->user_id, 'A first purchase links its own customer.');
    }

    public function test_order_paid_maybe_create_user_respects_store_setting(): void
    {
        update_option('fluent_cart_store_settings', array_merge(
            (array) get_option('fluent_cart_store_settings', []),
            ['user_account_creation_mode' => 'all']
        ));

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        Listeners\Order\OrderPaid::maybeCreateUser($order);

        $fresh = Customer::query()->find($customer->id);
        $this->assertGreaterThan(0, (int) email_exists($fresh->email), 'Account creation must still occur.');
        $this->assertSame((int) email_exists($fresh->email), (int) $fresh->user_id, 'A first purchase links its own customer.');
    }

    public function test_order_paid_maybe_create_user_links_the_first_purchase_customer(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'type'        => 'subscription',
        ]);

        Listeners\Order\OrderPaid::maybeCreateUser($order);

        $fresh = Customer::query()->find($customer->id);
        $wpUser = get_user_by('email', $fresh->email);
        $this->assertNotFalse($wpUser, 'The account must be created for the checkout address.');
        $this->assertSame((int) $wpUser->ID, (int) $fresh->user_id, 'The new account owns its first-purchase customer.');
        $this->assertSame($customer->email, $wpUser->user_email, 'The created account must use the checkout email.');
    }

    public function test_order_paid_maybe_create_user_does_not_claim_an_existing_account_by_email(): void
    {
        // An existing account must neither be recreated nor claim a guest record.
        $email = 'dup-' . wp_generate_password(6, false) . '@example.com';
        wp_insert_user([
            'user_login' => 'dup-user-' . wp_generate_password(6, false),
            'user_email' => $email,
            'user_pass'  => 'x',
        ]);

        $customer = $this->customerFactory->create(['email' => $email]);
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'type'        => 'subscription',
        ]);

        Listeners\Order\OrderPaid::maybeCreateUser($order);

        $fresh = Customer::query()->find($customer->id);
        $this->assertEmpty($fresh->user_id, 'An email match must not grant ownership.');
    }

    // =========================================================================
    // OrderUpdated (specs 36-41)
    // =========================================================================

    public function test_order_updated_hook_autofire_and_listeners(): void
    {
        $order = $this->makeOrder();
        $event = new OrderUpdated($order, $order);

        $this->assertSame('fluent_cart/order_updated', $event->hook);
        $this->assertTrue($event->autoFireHook);
        $this->assertSame([Listeners\Order\OrderUpdated::class], $this->reflectListeners($event));
    }

    public function test_order_updated_loads_relations_on_both_orders(): void
    {
        $customer = $this->customerFactory->create();
        $old = $this->orderFactory->withAddresses()->create(['customer_id' => $customer->id]);
        $new = $this->orderFactory->withAddresses()->create(['customer_id' => $customer->id]);

        $event = new OrderUpdated($new, $old);

        $this->assertTrue($event->order->relationLoaded('customer'));
        $this->assertTrue($event->oldOrder->relationLoaded('customer'));
    }

    public function test_order_updated_to_array_keys(): void
    {
        $order = $this->makeOrder();
        $data = (new OrderUpdated($order, $order))->toArray();

        $this->assertArrayHasKey('order', $data);
        $this->assertArrayHasKey('old_order', $data);
    }

    public function test_order_updated_listener_noop_when_totals_equal(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory
            ->withTransaction(['status' => Status::TRANSACTION_PENDING, 'total' => Helper::toCent(100)])
            ->create(['customer_id' => $customer->id, 'total_amount' => Helper::toCent(100)]);

        Listeners\Order\OrderUpdated::updateTransaction($order, $order);

        $txn = OrderTransaction::query()->where('order_id', $order->id)->first();
        $this->assertSame(Helper::toCent(100), (int) $txn->total);
    }

    public function test_order_updated_listener_updates_pending_charge_total(): void
    {
        $customer = $this->customerFactory->create();
        $oldOrder = $this->orderFactory
            ->withTransaction(['status' => Status::TRANSACTION_PENDING, 'total' => Helper::toCent(100)])
            ->create(['customer_id' => $customer->id, 'total_amount' => Helper::toCent(100)]);

        $newOrder = Order::query()->find($oldOrder->id);
        $newOrder->total_amount = Helper::toCent(150);
        $newOrder->save();

        Listeners\Order\OrderUpdated::updateTransaction($oldOrder, $newOrder);

        $txn = OrderTransaction::query()->where('order_id', $oldOrder->id)->first();
        $this->assertSame(Helper::toCent(150), (int) $txn->total);
    }

    public function test_order_updated_listener_noop_without_pending_charge(): void
    {
        $customer = $this->customerFactory->create();
        $oldOrder = $this->orderFactory->create(['customer_id' => $customer->id, 'total_amount' => Helper::toCent(100)]);

        $newOrder = Order::query()->find($oldOrder->id);
        $newOrder->total_amount = Helper::toCent(200);

        Listeners\Order\OrderUpdated::updateTransaction($oldOrder, $newOrder);

        $this->assertSame(0, OrderTransaction::query()->where('order_id', $oldOrder->id)->count());
    }

    // =========================================================================
    // OrderStatusUpdated (specs 42-49)
    // =========================================================================

    public function test_order_status_updated_autofire_false(): void
    {
        $order = $this->makeOrder();
        $event = new OrderStatusUpdated($order, 'on-hold', 'processing');

        $this->assertSame('fluent_cart/order_status_updated', $event->hook);
        $this->assertFalse($event->autoFireHook);
    }

    public function test_order_status_updated_same_status_is_noop(): void
    {
        $order = $this->makeOrder();
        $event = new OrderStatusUpdated($order, 'processing', 'processing');

        $ref = new ReflectionClass($event);
        $willDispatch = $ref->getProperty('willDispatch');
        $willDispatch->setAccessible(true);

        $this->assertFalse($willDispatch->getValue($event));
        $this->assertSame([], $this->reflectListeners($event));
    }

    public function test_order_status_updated_fires_payment_status_hooks(): void
    {
        $specific = 0;
        $generic = 0;
        add_action('fluent_cart/payment_status_changed_to_paid', function () use (&$specific) {
            $specific++;
        });
        add_action('fluent_cart/payment_status_changed', function () use (&$generic) {
            $generic++;
        });

        $order = $this->makeOrder();
        (new OrderStatusUpdated($order, 'pending', 'paid', true, [], 'payment_status'))->dispatch();

        $this->assertSame(1, $specific);
        $this->assertSame(1, $generic);
    }

    public function test_order_status_updated_fires_shipping_status_hooks(): void
    {
        $specific = 0;
        $generic = 0;
        add_action('fluent_cart/shipping_status_changed_to_shipped', function () use (&$specific) {
            $specific++;
        });
        add_action('fluent_cart/shipping_status_changed', function () use (&$generic) {
            $generic++;
        });

        $order = $this->makeOrder();
        (new OrderStatusUpdated($order, 'unshipped', 'shipped', true, [], 'shipping_status'))->dispatch();

        $this->assertSame(1, $specific);
        $this->assertSame(1, $generic);
    }

    public function test_order_status_updated_fires_order_status_hooks(): void
    {
        $specific = 0;
        $generic = 0;
        add_action('fluent_cart/order_status_changed_to_completed', function () use (&$specific) {
            $specific++;
        });
        add_action('fluent_cart/order_status_changed', function () use (&$generic) {
            $generic++;
        });

        $order = $this->makeOrder();
        (new OrderStatusUpdated($order, 'processing', 'completed', true, [], 'order_status'))->dispatch();

        $this->assertSame(1, $specific);
        $this->assertSame(1, $generic);
    }

    public function test_order_status_updated_no_hook_fire_when_same_status(): void
    {
        $specific = 0;
        add_action('fluent_cart/payment_status_changed', function () use (&$specific) {
            $specific++;
        });

        $order = $this->makeOrder();
        (new OrderStatusUpdated($order, 'paid', 'paid', true, [], 'payment_status'))->dispatch();

        $this->assertSame(0, $specific);
    }

    public function test_order_status_updated_activity_info_from_activity_array(): void
    {
        $order = $this->makeOrder();
        $event = new OrderStatusUpdated(
            $order,
            'on-hold',
            'processing',
            true,
            ['title' => 'Status Bump', 'content' => 'Moved to processing'],
            'order_status'
        );

        $info = $event->getEventInfoForActivity();
        $this->assertSame('Status Bump', $info['title']);
        $this->assertSame('Moved to processing', $info['content']);
    }

    public function test_order_status_updated_to_array_keys(): void
    {
        $order = $this->makeOrder();
        $data = (new OrderStatusUpdated($order, 'on-hold', 'processing', true, ['foo' => 'bar'], 'payment_status'))->toArray();

        $this->assertArrayHasKey('order', $data);
        $this->assertArrayHasKey('old_status', $data);
        $this->assertArrayHasKey('new_status', $data);
        $this->assertArrayHasKey('manageStock', $data);
        $this->assertArrayHasKey('activity', $data);
    }

    // =========================================================================
    // OrderRefund (specs 50-59)
    // =========================================================================

    public function test_order_refund_hook_and_autofire_false(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id, 'total_paid' => Helper::toCent(100), 'total_refund' => 0]);

        $refundTxn = $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => Helper::toCent(40),
        ]);

        $event = new OrderRefund($order, $refundTxn, [], false);
        $this->assertSame('fluent_cart/order_refunded', $event->hook);
        $this->assertFalse($event->autoFireHook);
    }

    public function test_order_refund_listener_is_order_refunded(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id, 'total_paid' => Helper::toCent(100)]);

        $refundTxn = $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => Helper::toCent(40),
        ]);

        $event = new OrderRefund($order, $refundTxn, [], false);
        $this->assertSame([Listeners\Order\OrderRefunded::class], $this->reflectListeners($event));
    }

    public function test_order_refund_zero_new_amount_clears_listeners(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'  => $customer->id,
            'total_paid'   => Helper::toCent(100),
            'total_refund' => Helper::toCent(40),
        ]);

        // existing refund txn sums to 40 equal to total_refund → newRefundAmount=0
        $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => Helper::toCent(40),
        ]);

        $event = new OrderRefund($order, null, [], false);
        $this->assertFalse($event->willTrigger);
        $this->assertSame([], $this->reflectListeners($event));
    }

    public function test_order_refund_type_full_when_sum_ge_total_paid(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'total_paid'  => Helper::toCent(100),
        ]);

        $refundTxn = $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => Helper::toCent(100),
        ]);

        $event = new OrderRefund($order, $refundTxn, [], false);
        $this->assertSame('full', $event->type);
    }

    public function test_order_refund_type_partial_when_sum_lt_total_paid(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'total_paid'  => Helper::toCent(100),
        ]);

        $refundTxn = $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => Helper::toCent(40),
        ]);

        $event = new OrderRefund($order, $refundTxn, [], false);
        $this->assertSame('partial', $event->type);
    }

    public function test_order_refund_after_dispatch_fires_refunded_hook(): void
    {
        $fired = 0;
        add_action('fluent_cart/order_refunded', function () use (&$fired) {
            $fired++;
        });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id, 'total_paid' => Helper::toCent(100)]);
        $refundTxn = $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => Helper::toCent(40),
        ]);

        (new OrderRefund($order, $refundTxn, [], false))->dispatch();

        $this->assertSame(1, $fired);
    }

    public function test_order_refund_fires_fully_refunded_hook(): void
    {
        $fired = 0;
        add_action('fluent_cart/order_fully_refunded', function () use (&$fired) {
            $fired++;
        });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id, 'total_paid' => Helper::toCent(100)]);
        $refundTxn = $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => Helper::toCent(100),
        ]);

        (new OrderRefund($order, $refundTxn, [], false))->dispatch();

        $this->assertSame(1, $fired);
    }

    public function test_order_refund_fires_partially_refunded_hook(): void
    {
        $fired = 0;
        add_action('fluent_cart/order_partially_refunded', function () use (&$fired) {
            $fired++;
        });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id, 'total_paid' => Helper::toCent(100)]);
        $refundTxn = $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => Helper::toCent(40),
        ]);

        (new OrderRefund($order, $refundTxn, [], false))->dispatch();

        $this->assertSame(1, $fired);
    }

    public function test_order_refund_after_dispatch_noop_when_will_trigger_false(): void
    {
        $fired = 0;
        add_action('fluent_cart/order_refunded', function () use (&$fired) {
            $fired++;
        });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'  => $customer->id,
            'total_paid'   => Helper::toCent(100),
            'total_refund' => Helper::toCent(50),
        ]);
        $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => Helper::toCent(50),
        ]);

        (new OrderRefund($order, null, [], false))->dispatch();

        $this->assertSame(0, $fired);
    }

    public function test_order_refund_already_counted_logs_no_second_activity(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id, 'total_paid' => Helper::toCent(100), 'total_refund' => 0]);
        $refundTxn = $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => Helper::toCent(40),
        ]);

        // Webhook counts the refund first, then the admin request dispatches for the same row.
        (new OrderRefund($order, $refundTxn, [], false))->dispatch();
        $late = new OrderRefund(Order::query()->findOrFail($order->id), $refundTxn, [], false);
        $this->assertFalse($late->willTrigger);
        $late->dispatch();

        $count = Activity::query()
            ->where('module_type', Order::class)
            ->where('module_id', $order->id)
            ->where('title', 'Order Refund')
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_order_refund_after_dispatch_resolves_refunded_items(): void
    {
        $received = null;
        add_action('fluent_cart/order_refunded', function ($data) use (&$received) {
            $received = $data;
        });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id, 'total_paid' => Helper::toCent(100)]);
        $item = $this->itemFactory->create($order->id);
        $refundTxn = $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => Helper::toCent(40),
        ]);

        (new OrderRefund($order, $refundTxn, [$item->id], false))->dispatch();

        $this->assertIsArray($received);
        $this->assertArrayHasKey('refunded_items', $received);
        $this->assertCount(1, $received['refunded_items']);
        $this->assertSame($item->id, (int) $received['refunded_items'][0]['id']);
    }

    public function test_order_refund_listener_syncs_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'  => $customer->id,
            'total_paid'   => Helper::toCent(100),
            'total_refund' => 0,
        ]);
        $item = $this->itemFactory->create($order->id, ['subtotal' => Helper::toCent(100), 'line_total' => Helper::toCent(100)]);
        $refundTxn = $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => Helper::toCent(40),
        ]);

        $event = new OrderRefund($order, $refundTxn, [$item->id], false);
        Listeners\Order\OrderRefunded::handle($event);

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Helper::toCent(40), (int) $fresh->total_refund);
        $this->assertSame(Status::PAYMENT_PARTIALLY_REFUNDED, $fresh->payment_status);

        $freshItem = OrderItem::query()->find($item->id);
        $this->assertSame(Helper::toCent(40), (int) $freshItem->refund_total);
    }

    public function test_refund_event_built_before_the_admin_item_refund_was_counted_adds_nothing(): void
    {
        $order = $this->orderFactory->create(['customer_id' => $this->customerFactory->create()->id, 'total_paid' => 10000, 'total_refund' => 0]);
        $selected = $this->itemFactory->create($order->id, ['line_total' => 5000]);
        $other = $this->itemFactory->create($order->id, ['line_total' => 5000]);
        $refund = $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => 5000,
        ]);

        $webhookEvent = new OrderRefund(Order::query()->find($order->id), $refund);
        (new OrderRefund(Order::query()->find($order->id), $refund, [$selected->id], false, [
            ['id' => $selected->id, 'refund_amount' => 5000],
        ], true))->dispatch();
        $webhookEvent->dispatch();

        $this->assertSame(5000, (int) Order::query()->find($order->id)->total_refund);
        $this->assertSame(5000, (int) OrderItem::query()->find($selected->id)->refund_total);
        $this->assertSame(0, (int) OrderItem::query()->find($other->id)->refund_total);
    }

    public function test_overlapping_refund_events_record_each_refund_once(): void
    {
        $order = $this->orderFactory->create(['customer_id' => $this->customerFactory->create()->id, 'total_paid' => 10000, 'total_refund' => 0]);
        $first = $this->itemFactory->create($order->id, ['line_total' => 5000]);
        $second = $this->itemFactory->create($order->id, ['line_total' => 5000]);
        $refundRow = [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
        ];

        $earlierEvent = new OrderRefund(Order::query()->find($order->id), $this->txnFactory->create($order->id, $refundRow + ['total' => 2000]));
        $laterEvent = new OrderRefund(Order::query()->find($order->id), $this->txnFactory->create($order->id, $refundRow + ['total' => 3000]));
        $earlierEvent->dispatch();
        $laterEvent->dispatch();

        $itemRefunds = (int) OrderItem::query()->find($first->id)->refund_total + (int) OrderItem::query()->find($second->id)->refund_total;
        $this->assertSame(5000, (int) Order::query()->find($order->id)->total_refund);
        $this->assertSame(5000, $itemRefunds);
    }

    public function test_webhook_refund_event_is_applied_under_the_order_refund_lock(): void
    {
        global $wpdb;
        $order = $this->orderFactory->create(['customer_id' => $this->customerFactory->create()->id, 'total_paid' => 10000, 'total_refund' => 0]);
        $this->itemFactory->create($order->id, ['line_total' => 10000]);
        $charge = $this->txnFactory->create($order->id, ['total' => 10000]);

        $heldLock = null;
        $checkLock = function () use ($wpdb, $order, &$heldLock) {
            $heldLock = (bool) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', 'fct_refund_' . $order->id));
        };
        add_action('fluent_cart/order_refunded', $checkLock);
        try {
            Refund::createOrRecordRefund(['vendor_charge_id' => 're_lock_test', 'total' => 2000], $charge);
        } finally {
            remove_action('fluent_cart/order_refunded', $checkLock);
        }

        $this->assertTrue($heldLock);
        $this->assertSame(2000, (int) Order::query()->find($order->id)->total_refund);
        $this->assertNull($wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', 'fct_refund_' . $order->id)));
    }

    // =========================================================================
    // OrderPaymentFailed (specs 60-64)
    // =========================================================================

    public function test_order_payment_failed_hook_and_listener(): void
    {
        $order = $this->makeOrder();
        $event = new OrderPaymentFailed($order);

        $this->assertSame('fluent_cart/order_payment_failed', $event->hook);
        $this->assertTrue($event->autoFireHook);
        $this->assertSame([Listeners\Order\OrderPaymentFailed::class], $this->reflectListeners($event));
    }

    public function test_order_payment_failed_loads_relations_and_sets_customer(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->withAddresses()->create(['customer_id' => $customer->id]);

        $event = new OrderPaymentFailed($order);

        $this->assertTrue($event->order->relationLoaded('customer'));
        $this->assertInstanceOf(Customer::class, $event->customer);
    }

    public function test_order_payment_failed_to_array_keys(): void
    {
        $order = $this->makeOrder();
        $data = (new OrderPaymentFailed($order, null, 'pending', 'failed', 'card declined'))->toArray();

        $this->assertArrayHasKey('old_status', $data);
        $this->assertArrayHasKey('new_status', $data);
        $this->assertArrayHasKey('reason', $data);
        $this->assertSame('card declined', $data['reason']);
    }

    public function test_order_payment_failed_listener_adds_error_log(): void
    {
        $order = $this->makeOrder();
        $before = Activity::query()->where('module_id', $order->id)->where('status', 'error')->count();

        Listeners\Order\OrderPaymentFailed::handle(new OrderPaymentFailed($order));

        $after = Activity::query()->where('module_id', $order->id)->where('status', 'error')->count();
        $this->assertGreaterThan($before, $after);
    }

    public function test_order_payment_failed_writes_one_error_log_with_reason(): void
    {
        $order = $this->makeOrder();

        (new OrderPaymentFailed($order, null, 'pending', 'failed', 'card declined'))->dispatch();

        $logs = Activity::query()->where('module_type', Order::class)->where('module_id', $order->id)->get();
        $this->assertCount(1, $logs);
        $this->assertSame('error', $logs[0]->status);
        $this->assertStringContainsString('card declined', $logs[0]->content);
    }

    public function test_order_payment_failed_listener_recounts_customer(): void
    {
        $customer = $this->customerFactory->create(['purchase_count' => 99]);
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        Listeners\Order\OrderPaymentFailed::handle(new OrderPaymentFailed($order));

        $fresh = Customer::query()->find($customer->id);
        $this->assertSame(0, (int) $fresh->purchase_count);
    }

    // =========================================================================
    // OrderCanceled (specs 65-67)
    // =========================================================================

    public function test_order_canceled_hook_and_listener(): void
    {
        $order = $this->makeOrder();
        $event = new OrderCanceled($order);

        $this->assertSame('fluent_cart/order_canceled', $event->hook);
        $this->assertTrue($event->autoFireHook);
        $this->assertSame([Listeners\Order\OrderDeleted::class], $this->reflectListeners($event));
    }

    public function test_order_canceled_activity_is_info(): void
    {
        $order = $this->makeOrder();

        Listeners\Activity::handle(new OrderCanceled($order));

        $log = Activity::query()->where('module_type', Order::class)->where('module_id', $order->id)->orderBy('id', 'DESC')->first();
        $this->assertSame('info', $log->status);
        $this->assertStringNotContainsString('successfully', $log->content);
    }

    // =========================================================================
    // OrderDeleted (specs 68-72)
    // =========================================================================

    public function test_order_deleted_hook_and_listener(): void
    {
        $order = $this->makeOrder();
        $event = new OrderDeleted($order);

        $this->assertSame('fluent_cart/order_deleted', $event->hook);
        $this->assertTrue($event->autoFireHook);
        $this->assertSame([Listeners\Order\OrderDeleted::class], $this->reflectListeners($event));
    }

    public function test_order_deleted_uses_load_missing(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $order->setRelation('customer', $customer);

        $event = new OrderDeleted($order);
        $this->assertTrue($event->order->relationLoaded('customer'));
    }

    public function test_order_deleted_to_array_includes_connected_order_ids(): void
    {
        $order = $this->makeOrder();
        $data = (new OrderDeleted($order, [11, 22]))->toArray();

        $this->assertSame([11, 22], $data['connected_order_ids']);
    }

    public function test_order_deleted_should_create_activity_false_in_test_mode(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'mode'        => Status::ORDER_MODE_TEST,
        ]);

        $event = new OrderDeleted($order);
        $this->assertFalse($event->shouldCreateActivity());
    }

    public function test_order_deleted_listener_recounts_customer_stats(): void
    {
        $customer = $this->customerFactory->create(['purchase_count' => 7]);
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        Listeners\Order\OrderDeleted::handle(new OrderDeleted($order));

        $fresh = Customer::query()->find($customer->id);
        $this->assertSame(0, (int) $fresh->purchase_count);
    }

    // =========================================================================
    // OrderDeleting (specs 73-80)
    // =========================================================================

    public function test_order_deleting_hook_and_autofire(): void
    {
        $order = $this->makeOrder();
        $event = new OrderDeleting($order, []);

        $this->assertSame('fluent_cart/order_before_delete', $event->hook);
        $this->assertTrue($event->autoFireHook);
    }

    public function test_order_deleting_should_create_activity_always_false(): void
    {
        $order = $this->makeOrder();
        $this->assertFalse((new OrderDeleting($order))->shouldCreateActivity());
    }

    public function test_order_deleting_listener_noop_without_stock_module(): void
    {
        // stock_management module is unset by default in the test env, so isActive() returns false
        // on first call and ModuleSettings::getAllSettings() memoizes that result for the rest of
        // the process. The listener must short-circuit before touching variations.
        $product = $this->productFactory
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'manage_stock' => 1,
                'available'    => 5,
                'committed'    => 5,
                'total_stock'  => 10,
            ])
            ->create();
        $variant = $product->variants->first();

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id, 'mode' => Status::ORDER_MODE_TEST]);
        $item = $this->itemFactory->create($order->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
            'quantity'  => 3,
        ]);
        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$item->id => ['on_hold' => 0, 'committed' => 3]],
        ]);

        Listeners\Order\OrderDeleting::handle(new OrderDeleting($order, [$order->id], true));

        $fresh = ProductVariation::query()->find($variant->id);
        $this->assertSame(5, (int) $fresh->available);
        $this->assertSame(5, (int) $fresh->committed);
    }

    // =========================================================================
    // RenewalOrderDeleted (specs 81-84)
    // =========================================================================

    public function test_renewal_order_deleted_hook_and_listener(): void
    {
        $order = $this->makeOrder();
        $event = new RenewalOrderDeleted($order);

        $this->assertSame('fluent_cart/renewal_order_deleted', $event->hook);
        $this->assertTrue($event->autoFireHook);
        $this->assertSame([Listeners\Order\RenewalOrderDeleted::class], $this->reflectListeners($event));
    }

    public function test_renewal_order_deleted_activity_false_in_test_mode(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'mode'        => Status::ORDER_MODE_TEST,
        ]);
        $this->assertFalse((new RenewalOrderDeleted($order))->shouldCreateActivity());
    }

    public function test_renewal_order_deleted_listener_recounts_customer(): void
    {
        $customer = $this->customerFactory->create(['purchase_count' => 8]);
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        Listeners\Order\RenewalOrderDeleted::handle(new RenewalOrderDeleted($order));

        $fresh = Customer::query()->find($customer->id);
        $this->assertSame(0, (int) $fresh->purchase_count);
    }

    // =========================================================================
    // OrderBulkAction (specs 85-88)
    // =========================================================================

    public function test_order_bulk_action_hook_and_listener(): void
    {
        $event = new OrderBulkAction([1, 2]);
        $this->assertSame('fluent_cart/order_bulk_action', $event->hook);
        $this->assertTrue($event->autoFireHook);
        $this->assertSame([Listeners\Order\OrderBulkAction::class], $this->reflectListeners($event));
    }

    public function test_order_bulk_action_should_create_activity_false(): void
    {
        $this->assertFalse((new OrderBulkAction([1]))->shouldCreateActivity());
    }

    public function test_order_bulk_action_listener_calls_calculate_stats(): void
    {
        $customer = $this->customerFactory->create(['purchase_count' => 99]);
        $order = $this->orderFactory->create(['customer_id' => $customer->id, 'payment_status' => Status::PAYMENT_PAID, 'total_paid' => Helper::toCent(10)]);

        Listeners\Order\OrderBulkAction::handle(new OrderBulkAction([$customer->id]));

        $fresh = Customer::query()->find($customer->id);
        $this->assertSame(1, (int) $fresh->purchase_count);
    }

    public function test_order_bulk_action_listener_noop_on_empty_ids(): void
    {
        // Pre-existing customer stats must be untouched when no ids supplied.
        $other = $this->customerFactory->create([
            'purchase_count' => 9,
            'ltv'            => Helper::toCent(50),
        ]);

        Listeners\Order\OrderBulkAction::handle(new OrderBulkAction([]));

        $fresh = Customer::query()->find($other->id);
        $this->assertSame(9, (int) $fresh->purchase_count);
        $this->assertSame(Helper::toCent(50), (int) $fresh->ltv);
    }
}
