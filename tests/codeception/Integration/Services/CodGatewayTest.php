<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Cod\Cod;
use FluentCart\App\Modules\PaymentMethods\Cod\CodHandler;
use FluentCart\App\Modules\PaymentMethods\Cod\CodSettingsBase;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Services\Payments\PaymentInstance;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;

class CodGatewayTest extends WPTestCase
{
    private Cod $cod;
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderTransactionFactory $txnFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->cod = new Cod();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->txnFactory = new OrderTransactionFactory();
    }

    // =========================================================================
    // Spec 75-77: meta()
    // =========================================================================

    public function test_meta_route_is_offline_payment(): void
    {
        $this->assertSame('offline_payment', $this->cod->meta()['route']);
    }

    public function test_meta_slug_is_offline_payment(): void
    {
        $this->assertSame('offline_payment', $this->cod->meta()['slug']);
    }

    public function test_meta_upcoming_is_false(): void
    {
        $this->assertFalse($this->cod->meta()['upcoming']);
    }

    // =========================================================================
    // Spec 78: supportedFeatures
    // =========================================================================

    public function test_supported_features_match_expected(): void
    {
        // Since the renewal engine, COD subscriptions are store-billed: no gateway
        // subscription capability is declared at all — determineCollectionMethod()
        // resolves non-auto gateways to manual, and the retired
        // `manual_subscription` feature key was removed with it.
        // dd714f052 added the `offline` capability, declared by COD only: it is
        // what keeps COD on a zero-payable subscription cart (nothing to charge
        // or authorize now) without admitting online payment-only gateways.
        // `local_refund_only` keeps COD refunds off the remote-first route: there is
        // no gateway to refund through, so a refund is recorded locally.
        $features = array_values(array_unique($this->cod->supportedFeatures));
        $this->assertSame(['payment', 'refund', 'offline', 'local_refund_only', 'multi_currency'], $features);
    }

    // =========================================================================
    // Spec 79-80: Constructor instantiates modules
    // =========================================================================

    public function test_constructor_has_no_vendor_subscriptions_module(): void
    {
        // CodSubscriptions was removed with the invoice engine — COD subscriptions
        // are store-billed (manual), so no vendor subscription module is attached.
        $this->assertNull($this->cod->subscriptions);
        $this->assertFalse($this->cod->has('subscriptions'));
    }

    // =========================================================================
    // Spec 81-82: boot() registrations
    // =========================================================================

    public function test_boot_registers_payment_paid_action(): void
    {
        $this->cod->boot();
        $this->assertNotFalse(has_action('fluent_cart/payment_paid', [$this->cod, 'handlePaymentPaid']));
    }

    public function test_boot_registers_payment_method_list_class_filter(): void
    {
        $this->cod->boot();
        $this->assertNotFalse(has_filter('fluent_cart/payment_method_list_class', [$this->cod, 'getPaymentMethodClass']));
    }

    // =========================================================================
    // Spec 83-84: makePaymentFromPaymentInstance
    // =========================================================================

    public function test_make_payment_returns_success_with_redirect(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'total_amount' => 10000,
        ]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_PENDING,
            'total'  => 10000,
            'uuid'   => 'cod-success-uuid',
        ]);

        $this->cod->settings->settings['is_active'] = 'yes';

        $instance = new PaymentInstance($order);

        $result = $this->cod->makePaymentFromPaymentInstance($instance);
        $this->assertSame('success', $result['status']);
        $this->assertStringContainsString('trx_hash=cod-success-uuid', $result['redirect_to']);
    }

    public function test_make_payment_returns_failed_on_exception(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'  => $customer->id,
            'total_amount' => 10000,
        ]);

        $instance = new PaymentInstance($order);
        $instance->transaction = null;

        $result = $this->cod->makePaymentFromPaymentInstance($instance);
        $this->assertSame('failed', $result['status']);
        $this->assertArrayHasKey('message', $result);
    }

    // =========================================================================
    // Spec 85-88: handlePaymentPaid behavior
    // =========================================================================

    public function test_handle_payment_paid_completes_digital_processing_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_PROCESSING,
            'fulfillment_type' => 'digital',
            'payment_method'   => 'offline_payment',
        ]);

        $this->cod->handlePaymentPaid([
            'order' => [
                'id'               => $order->id,
                'status'           => Status::ORDER_PROCESSING,
                'fulfillment_type' => 'digital',
                'payment_method'   => 'offline_payment',
            ],
        ]);

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::ORDER_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_handle_payment_paid_leaves_physical_processing_order_unchanged(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_PROCESSING,
            'fulfillment_type' => 'physical',
            'payment_method'   => 'offline_payment',
        ]);

        $this->cod->handlePaymentPaid([
            'order' => [
                'id'               => $order->id,
                'status'           => Status::ORDER_PROCESSING,
                'fulfillment_type' => 'physical',
                'payment_method'   => 'offline_payment',
            ],
        ]);

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::ORDER_PROCESSING, $fresh->status);
    }

    public function test_handle_payment_paid_activates_subscriptions_for_digital_orders(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_PROCESSING,
            'fulfillment_type' => 'digital',
            'payment_method'   => 'offline_payment',
        ]);
        $sub = Subscription::query()->create([
            'customer_id'      => $customer->id,
            'parent_order_id'  => $order->id,
            'status'           => Status::SUBSCRIPTION_PENDING,
            'item_name'        => 'Sub Test',
            'recurring_amount' => 1000,
            'billing_interval' => 'monthly',
            'payment_method'   => 'offline_payment',
        ]);

        $this->cod->handlePaymentPaid([
            'order' => [
                'id'               => $order->id,
                'status'           => Status::ORDER_PROCESSING,
                'fulfillment_type' => 'digital',
                'payment_method'   => 'offline_payment',
            ],
        ]);

        $freshSub = Subscription::query()->find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, $freshSub->status);
    }

    // =========================================================================
    // Spec 89-90: maybeUpdateSubscription
    // =========================================================================

    public function test_maybe_update_subscription_activates_non_active_subscription(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_COMPLETED,
        ]);
        $sub = Subscription::query()->create([
            'customer_id'      => $customer->id,
            'parent_order_id'  => $order->id,
            'status'           => Status::SUBSCRIPTION_PENDING,
            'item_name'        => 'Sub',
            'recurring_amount' => 1000,
            'billing_interval' => 'monthly',
            'payment_method'   => 'offline_payment',
        ]);

        $this->cod->maybeUpdateSubscription([
            'order' => $order,
        ]);

        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, $fresh->status);
    }

    public function test_maybe_update_subscription_is_noop_for_on_hold_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_ON_HOLD,
        ]);
        $sub = Subscription::query()->create([
            'customer_id'      => $customer->id,
            'parent_order_id'  => $order->id,
            'status'           => Status::SUBSCRIPTION_PENDING,
            'item_name'        => 'Sub',
            'recurring_amount' => 1000,
            'billing_interval' => 'monthly',
            'payment_method'   => 'offline_payment',
        ]);

        $this->cod->maybeUpdateSubscription([
            'order' => $order,
        ]);

        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_PENDING, $fresh->status);
    }

    // =========================================================================
    // Spec 91-94: validateSettings + refund + webhook + handleIPN
    // =========================================================================

    public function test_validate_settings_returns_success(): void
    {
        $result = Cod::validateSettings([]);
        $this->assertSame('success', $result['status']);
    }

    public function test_refund_is_a_noop(): void
    {
        $this->assertNull($this->cod->refund([], [], null));
    }

    public function test_webhook_payment_method_name_is_offline_payment(): void
    {
        $this->assertSame('offline_payment', $this->cod->webHookPaymentMethodName());
    }

    public function test_handle_ipn_is_a_noop(): void
    {
        $this->assertNull($this->cod->handleIPN());
    }

    // =========================================================================
    // Spec 95: fields()
    // =========================================================================

    public function test_fields_returns_cod_description_html_attr(): void
    {
        $fields = $this->cod->fields();
        $this->assertArrayHasKey('cod_description', $fields);
        $this->assertSame('html_attr', $fields['cod_description']['type']);
    }

    // =========================================================================
    // Spec 96-98: CodSettingsBase
    // =========================================================================

    public function test_cod_settings_base_method_handler(): void
    {
        $settings = new CodSettingsBase();
        $this->assertSame('fluent_cart_payment_settings_offline_payment', $settings->methodHandler);
    }

    public function test_cod_settings_base_defaults(): void
    {
        $defaults = CodSettingsBase::getDefaults();
        $this->assertSame('no', $defaults['is_active']);
        $this->assertSame('live', $defaults['payment_mode']);
    }

    public function test_cod_settings_base_mode_returns_store_mode(): void
    {
        $storeMode = (new \FluentCart\Api\StoreSettings())->get('order_mode');
        $this->assertSame($storeMode, (new CodSettingsBase())->getMode());
    }

    // =========================================================================
    // Spec 99-100: CodSubscriptions — REMOVED with the invoice engine. COD
    // subscriptions are store-billed; there is no vendor subscription module.
    // =========================================================================

    // =========================================================================
    // Spec 101-108: CodHandler
    // =========================================================================

    public function test_handle_payment_delegates_to_zero_total_when_amount_is_zero(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'  => $customer->id,
            'total_amount' => 0,
            'payment_method' => 'offline_payment',
        ]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_PENDING,
            'total'  => 0,
            'uuid'   => 'zero-total-uuid',
        ]);

        $instance = new PaymentInstance($order);
        $url = (new CodHandler($this->cod))->handlePayment($instance);

        $freshTxn = OrderTransaction::query()->find($txn->id);
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $freshTxn->status);
        $this->assertStringContainsString('zero-total-uuid', $url);
    }

    public function test_handle_payment_rejects_when_settings_not_active(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'payment_method' => 'offline_payment',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_PENDING,
            'total'  => 10000,
            'uuid'   => 'cod-disabled-uuid',
        ]);
        Cart::query()->create([
            'order_id'  => $order->id,
            'stage'     => 'pending',
            'cart_hash' => 'cod-disabled-cart',
        ]);

        $this->cod->settings->settings['is_active'] = 'no';

        $offlineFired = false;
        add_action('fluent_cart/order_placed_offline', function () use (&$offlineFired) {
            $offlineFired = true;
        });

        $instance = new PaymentInstance($order);
        $result = $this->cod->makePaymentFromPaymentInstance($instance);

        remove_all_actions('fluent_cart/order_placed_offline');

        $this->assertSame('failed', $result['status']);
        $this->assertArrayNotHasKey('redirect_to', $result);
        $this->assertFalse($offlineFired, 'disabled COD must not fire order_placed_offline');

        $cart = Cart::query()->where('order_id', $order->id)->first();
        $this->assertNotSame('completed', $cart->stage, 'disabled COD must not complete cart');
    }

    public function test_handle_payment_sets_payment_method_title(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'  => $customer->id,
            'total_amount' => 10000,
            'payment_method' => 'offline_payment',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_PENDING,
            'total'  => 10000,
            'uuid'   => 'title-test-uuid',
        ]);

        $this->cod->settings->settings['is_active'] = 'yes';

        $instance = new PaymentInstance($order);
        (new CodHandler($this->cod))->handlePayment($instance);

        $fresh = Order::query()->find($order->id);
        $this->assertSame($this->cod->getMeta('title'), $fresh->payment_method_title);
    }

    public function test_handle_payment_dispatches_order_placed_offline_action(): void
    {
        $fired = false;
        add_action('fluent_cart/order_placed_offline', function ($data) use (&$fired) {
            $fired = isset($data['order']);
        });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'  => $customer->id,
            'total_amount' => 10000,
            'payment_method' => 'offline_payment',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_PENDING,
            'total'  => 10000,
            'uuid'   => 'offline-fire-uuid',
        ]);

        $this->cod->settings->settings['is_active'] = 'yes';

        $instance = new PaymentInstance($order);
        (new CodHandler($this->cod))->handlePayment($instance);

        remove_all_actions('fluent_cart/order_placed_offline');
        $this->assertTrue($fired);
    }

    public function test_handle_payment_marks_related_cart_completed(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'  => $customer->id,
            'total_amount' => 10000,
            'payment_method' => 'offline_payment',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_PENDING,
            'total'  => 10000,
            'uuid'   => 'cart-done-uuid',
        ]);

        Cart::query()->create([
            'order_id'  => $order->id,
            'stage'     => 'pending',
            'cart_hash' => 'cart-hash-xyz',
        ]);

        $this->cod->settings->settings['is_active'] = 'yes';

        $instance = new PaymentInstance($order);
        (new CodHandler($this->cod))->handlePayment($instance);

        $freshCart = Cart::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($freshCart);
        $this->assertSame('completed', $freshCart->stage);
        $this->assertNotNull($freshCart->completed_at);
    }

    public function test_handle_zero_total_payment_sets_transaction_succeeded(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'  => $customer->id,
            'total_amount' => 0,
            'payment_method' => 'offline_payment',
        ]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_PENDING,
            'total'  => 0,
            'uuid'   => 'zero-status-uuid',
        ]);

        $instance = new PaymentInstance($order);
        (new CodHandler($this->cod))->handleZeroTotalPayment($instance);

        $freshTxn = OrderTransaction::query()->find($txn->id);
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $freshTxn->status);
    }

    public function test_handle_zero_total_payment_syncs_order_statuses(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'total_amount'     => 0,
            'payment_method'   => 'offline_payment',
            'fulfillment_type' => 'digital',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_PENDING,
            'total'  => 0,
            'uuid'   => 'zero-sync-uuid',
        ]);

        $instance = new PaymentInstance($order);
        (new CodHandler($this->cod))->handleZeroTotalPayment($instance);

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::PAYMENT_PAID, $fresh->payment_status);
    }

    public function test_handle_zero_total_payment_activates_zero_recurring_subscription(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 0,
            'payment_method' => 'offline_payment',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_PENDING,
            'total'  => 0,
            'uuid'   => 'zero-sub-uuid',
        ]);
        $sub = Subscription::query()->create([
            'customer_id'      => $customer->id,
            'parent_order_id'  => $order->id,
            'status'           => Status::SUBSCRIPTION_PENDING,
            'item_name'        => 'Free Sub',
            'recurring_amount' => 0,
            'recurring_total'  => 0,
            'billing_interval' => 'monthly',
            'payment_method'   => 'offline_payment',
        ]);

        $instance = new PaymentInstance($order);
        (new CodHandler($this->cod))->handleZeroTotalPayment($instance);

        $freshSub = Subscription::query()->find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, $freshSub->status);
    }
}
