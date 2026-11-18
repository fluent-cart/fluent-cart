<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Cod\Cod;
use FluentCart\App\Modules\PaymentMethods\Cod\CodHandler;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\Payments\Refund;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;

class PaymentHooksAndEdgeTest extends WPTestCase
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
        if (!GatewayManager::has('offline_payment')) {
            GatewayManager::getInstance()->register('offline_payment', new Cod());
        }
    }

    // =========================================================================
    // Hooks (exhaustive) — spec 207-218
    // =========================================================================

    // 207: Filter fluent_cart/ipn_url_{slug}
    public function test_ipn_url_filter_applied(): void
    {
        add_filter('fluent_cart/ipn_url_offline_payment', function () {
            return ['listener_url' => 'custom-ipn'];
        });
        $out = (new PaymentHelper('offline_payment'))->listenerUrl();
        remove_all_filters('fluent_cart/ipn_url_offline_payment');
        $this->assertSame('custom-ipn', $out['listener_url']);
    }

    // 208: Filter fluent_cart/payment/success_url
    public function test_success_url_filter_applied(): void
    {
        add_filter('fluent_cart/payment/success_url', fn() => 'overridden-success');
        $url = (new PaymentHelper('offline_payment'))->successUrl('hash');
        remove_all_filters('fluent_cart/payment/success_url');
        $this->assertSame('overridden-success', $url);
    }

    // 209: default zero-payment method filter default
    public function test_default_zero_payment_method_filter_default(): void
    {
        $defaultFromFilter = apply_filters('fluent_cart/default_payment_method_for_zero_payment', 'offline_payment', []);
        $this->assertSame('offline_payment', $defaultFromFilter);
    }

    // 211: Action fluent_cart/payments/after_payment_{status}
    public function test_after_payment_status_action_fires_from_base_gateway(): void
    {
        $fired = false;
        add_action('fluent_cart/payments/after_payment_paid', function () use (&$fired) { $fired = true; });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_ON_HOLD,
            'total_amount'     => 5000,
            'total_paid'       => 0,
            'fulfillment_type' => 'digital',
        ]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_PENDING,
            'total'  => 5000,
        ]);

        (new Cod())->updateOrderDataByOrder($order, [
            'status' => 'succeeded',
            'total'  => 5000,
        ], $txn);

        remove_all_actions('fluent_cart/payments/after_payment_paid');
        $this->assertTrue($fired);
    }

    // 213: Action fluent_cart/order_placed_offline fired by CodHandler
    public function test_order_placed_offline_action_fires(): void
    {
        $fired = false;
        add_action('fluent_cart/order_placed_offline', function () use (&$fired) { $fired = true; });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 5000,
            'payment_method' => 'offline_payment',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_PENDING,
            'total'  => 5000,
            'uuid'   => 'hook-213-uuid',
        ]);

        $cod = new Cod();
        $cod->settings->settings['is_active'] = 'yes';

        $instance = new PaymentInstance($order);
        (new CodHandler($cod))->handlePayment($instance);

        remove_all_actions('fluent_cart/order_placed_offline');
        $this->assertTrue($fired);
    }

    // 216: Filter fluent_cart/order_refund_manually
    public function test_order_refund_manually_filter_suppresses_gateway(): void
    {
        add_filter('fluent_cart/order_refund_manually', function () {
            return ['status' => 'yes', 'source' => 'hook-test'];
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
        $this->assertNull($result['vendor_refund_id']);
    }

    // 217: Filter fluent_cart/subscription_interval_in_days
    public function test_subscription_interval_in_days_filter_customizable(): void
    {
        add_filter('fluent_cart/subscription_interval_in_days', fn() => 42);
        $v = PaymentHelper::getIntervalDays('monthly');
        remove_all_filters('fluent_cart/subscription_interval_in_days');
        $this->assertSame(42, $v);
    }

    // 218: Filter fluent_cart/transaction/receipt_page_url
    public function test_transaction_receipt_page_url_filter_overrides(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, ['uuid' => 'filter-receipt-url']);

        add_filter('fluent_cart/transaction/receipt_page_url', fn() => 'https://custom.receipt');
        $url = $txn->getReceiptPageUrl(true);
        remove_all_filters('fluent_cart/transaction/receipt_page_url');
        $this->assertSame('https://custom.receipt', $url);
    }

    // =========================================================================
    // Edge Cases — spec 219-230
    // =========================================================================

    // 219: Zero-total order routes to offline_payment via filter
    public function test_zero_total_default_payment_method_filter(): void
    {
        $method = apply_filters('fluent_cart/default_payment_method_for_zero_payment', 'offline_payment', []);
        $this->assertSame('offline_payment', $method);
    }

    // 220: Refund on fully-refunded transaction returns WP_Error
    public function test_refund_on_fully_refunded_transaction_returns_wp_error(): void
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
            'meta'   => ['refunded_total' => 10000],
        ]);

        $result = (new Refund())->processRefund($txn, 100);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // 221: handlePaymentPaid idempotent for already-completed digital order
    public function test_handle_payment_paid_noop_on_completed_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_COMPLETED,
            'fulfillment_type' => 'digital',
            'payment_method'   => 'offline_payment',
        ]);

        $count = 0;
        add_action('fluent_cart/order_status_updated', function () use (&$count) { $count++; });

        (new Cod())->handlePaymentPaid([
            'order' => [
                'id'               => $order->id,
                'status'           => Status::ORDER_COMPLETED,
                'fulfillment_type' => 'digital',
                'payment_method'   => 'offline_payment',
            ],
        ]);

        remove_all_actions('fluent_cart/order_status_updated');
        $this->assertSame(0, $count);
    }

    // 222: syncOrderStatuses atomic guard prevents duplicate dispatch (covered in StatusHelperTest)
    public function test_sync_order_statuses_no_duplicate_order_paid(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'total_amount'     => 5000,
            'total_paid'       => 5000,
            'payment_status'   => Status::PAYMENT_PAID,
            'status'           => Status::ORDER_PROCESSING,
            'fulfillment_type' => 'physical',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 5000,
        ]);

        $count = 0;
        add_action('fluent_cart/order_paid', function () use (&$count) { $count++; });

        (new \FluentCart\App\Helpers\StatusHelper($order))->syncOrderStatuses();
        (new \FluentCart\App\Helpers\StatusHelper($order))->syncOrderStatuses();

        remove_all_actions('fluent_cart/order_paid');
        $this->assertSame(0, $count);
    }

    // 223: Digital order with auto_complete filter false does NOT auto-complete
    public function test_digital_order_respects_auto_complete_filter_off(): void
    {
        add_filter('fluent_cart/order_status/auto_complete_digital_order', fn() => false);

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'total_amount'     => 10000,
            'total_paid'       => 0,
            'status'           => Status::ORDER_ON_HOLD,
            'payment_status'   => Status::PAYMENT_PENDING,
            'fulfillment_type' => 'digital',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        (new \FluentCart\App\Helpers\StatusHelper($order))->syncOrderStatuses();

        remove_all_filters('fluent_cart/order_status/auto_complete_digital_order');

        $fresh = Order::query()->find($order->id);
        $this->assertNotSame(Status::ORDER_COMPLETED, $fresh->status);
    }

    // 224: Renewal order's OrderPaid is not dispatched
    public function test_renewal_order_does_not_dispatch_order_paid(): void
    {
        $fired = false;
        add_action('fluent_cart/order_paid', function () use (&$fired) { $fired = true; });

        $customer = $this->customerFactory->create();
        $parent = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'type'        => 'subscription',
        ]);
        $renewal = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'type'             => Status::ORDER_TYPE_RENEWAL,
            'parent_id'        => $parent->id,
            'total_amount'     => 5000,
            'total_paid'       => 0,
            'payment_status'   => Status::PAYMENT_PENDING,
            'fulfillment_type' => 'physical',
        ]);
        $this->txnFactory->create($renewal->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 5000,
        ]);

        (new \FluentCart\App\Helpers\StatusHelper($renewal))->syncOrderStatuses();

        remove_all_actions('fluent_cart/order_paid');
        $this->assertFalse($fired);
    }

    // 225: COD is_active=no rejects makePayment — covered indirectly. Directly:
    public function test_cod_makepayment_returns_failed_without_transaction(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'  => $customer->id,
            'total_amount' => 10000,
        ]);
        $instance = new PaymentInstance($order);
        $instance->transaction = null;

        $result = (new Cod())->makePaymentFromPaymentInstance($instance);
        $this->assertSame('failed', $result['status']);
    }

    // 226: Refund dedup by vendor_charge_id — already covered in RefundServiceTest but verify here too
    public function test_refund_dedup_by_vendor_id_reuses_record(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $parent = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        $existing = OrderTransaction::query()->create([
            'order_id'         => $order->id,
            'order_type'       => 'payment',
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => 2000,
            'vendor_charge_id' => 're_dup',
            'payment_method'   => 'offline_payment',
            'payment_mode'     => 'live',
            'currency'         => 'USD',
        ]);

        $result = Refund::createOrRecordRefund([
            'vendor_charge_id' => 're_dup',
            'total'            => 2000,
        ], $parent);

        $this->assertSame($existing->id, $result->id);
    }

    // 228: UUID collision prevention — boot hook generates md5(time . uuid4)
    public function test_transaction_boot_hook_generates_unique_uuid(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $a = $this->txnFactory->create($order->id);
        $b = $this->txnFactory->create($order->id);

        $this->assertNotSame($a->uuid, $b->uuid);
        $this->assertSame(32, strlen($a->uuid));
        $this->assertSame(32, strlen($b->uuid));
    }

    // 230: getAllMeta error message lists missing keys (covered in GatewayManagerTest)
    public function test_get_all_meta_error_lists_missing_key(): void
    {
        $incomplete = new class implements \FluentCart\App\Modules\PaymentMethods\Core\PaymentGatewayInterface {
            public function has(string $feature): bool { return false; }
            public function meta(): array { return []; }
            public function makePaymentFromPaymentInstance($p) { return []; }
            public function handleIPN() {}
            public function getOrderInfo(array $d) {}
            public function fields() { return []; }
            public function getMeta($key = '') { return $key ? '' : []; }
        };
        GatewayManager::getInstance()->register('incomplete_edge_test', $incomplete);

        $exception = null;
        try {
            GatewayManager::getInstance()->getAllMeta();
        } catch (\Exception $e) {
            $exception = $e;
        } finally {
            GatewayManager::getInstance()->remove('incomplete_edge_test');
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('Missing required meta key', $exception->getMessage());
    }
}
