<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Report\ProductReportService;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\App\Services\ShipmentService;
use PHPUnit\Framework\Assert;
use Tests\Support\Doubles\FakeRefundGateway;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsRefundFixtures;

class RemoteFirstRefundCest
{
    use BuildsRefundFixtures;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        FakeRefundGateway::register();
        FakeRefundGateway::$nextResult = new \WP_Error('charge_disputed', 'Charge is disputed.');
    }

    public function _after(FunctionalTester $I): void
    {
        FakeRefundGateway::remove();
    }

    public function gatewayFailureReturnsForceLocalPromptAndRecordsNothing(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $transaction = $this->paidCharge([], ['status' => Status::ORDER_PROCESSING]);
        $order = $transaction->order;

        [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund', [
            'refund_info' => [
                'transaction_id'     => (int) $transaction->id,
                'amount'             => Helper::toCent(40),
                'cancelSubscription' => 'false',
            ],
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertSame('remote_refund_failed', $body['code'] ?? null, wp_json_encode($body));
        Assert::assertTrue((bool) ($body['can_force_local'] ?? false));
        Assert::assertSame('Charge is disputed.', $body['gateway_message'] ?? null);
        Assert::assertSame(Helper::toCent(40), (int) $body['impact']['amount']);
        Assert::assertSame(Status::PAYMENT_PARTIALLY_REFUNDED, $body['impact']['payment_status_after']);
        Assert::assertCount(0, $this->refundRows((int) $order->id));
    }

    public function forceLocalResendRecordsRefundWithoutGateway(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $transaction = $this->paidCharge([], ['status' => Status::ORDER_PROCESSING]);
        $order = $transaction->order;

        [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund', [
            'refund_info' => [
                'transaction_id'     => (int) $transaction->id,
                'amount'             => Helper::toCent(100),
                'cancelSubscription' => 'false',
                'force_local'        => 'true',
            ],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame('warning', $body['gateway_refund']['status'] ?? null, wp_json_encode($body));
        Assert::assertSame(0, FakeRefundGateway::$refundCalls);

        $rows = $this->refundRows((int) $order->id);
        Assert::assertCount(1, $rows);
        Assert::assertSame('forced_local', $rows[0]->meta['refund_route']);

        global $wpdb;
        $paymentStatus = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT payment_status FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id)
        );
        Assert::assertSame(Status::PAYMENT_REFUNDED, $paymentStatus);
    }

    public function forceLocalRefundLeavesTheSubscriptionUntouched(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $charge = $this->paidCharge([], ['status' => Status::ORDER_PROCESSING]);
        $order = $charge->order;

        $subscription = Subscription::query()->create([
            'customer_id'      => (int) $order->customer_id,
            'parent_order_id'  => (int) $order->id,
            'status'           => Status::SUBSCRIPTION_ACTIVE,
            'billing_interval' => 'month',
            'recurring_amount' => 10000,
            'current_payment_method' => FakeRefundGateway::SLUG,
        ]);
        $charge->subscription_id = (int) $subscription->id;
        $charge->save();

        [, $body] = $this->refund($I, (int) $order->id, [
            'transaction_id'     => (int) $charge->id,
            'amount'             => Helper::toCent(100),
            'cancelSubscription' => 'true',
            'force_local'        => 'true',
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayNotHasKey('subscription_cancel', (array) $body, wp_json_encode($body));
        Assert::assertSame(
            Status::SUBSCRIPTION_ACTIVE,
            Subscription::query()->findOrFail((int) $subscription->id)->status
        );
    }

    private function refund(FunctionalTester $I, int $orderId, array $refundInfo, array $refundedItems = []): array
    {
        $payload = ['refund_info' => array_merge(['cancelSubscription' => 'false'], $refundInfo)];
        if ($refundedItems) {
            $payload['refunded_items'] = $refundedItems;
        }

        return $I->post('/fluent-cart/v2/orders/' . $orderId . '/refund', $payload);
    }

    private function orderWithItem(int $quantity): array
    {
        $total = $quantity * Helper::toCent(100);
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'payment_method' => FakeRefundGateway::SLUG,
            'subtotal'       => $total,
            'total_amount'   => $total,
            'total_paid'     => $total,
        ]);
        $item = (new OrderItemFactory())->create((int) $order->id, [
            'quantity'   => $quantity,
            'unit_price' => Helper::toCent(100),
            'subtotal'   => $total,
            'line_total' => $total,
        ]);
        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => $total,
            'payment_method'   => FakeRefundGateway::SLUG,
            'vendor_charge_id' => 'pi_fake_1',
        ]);

        return [$order, $item, $transaction];
    }

    private function column(string $table, string $column, int $id): string
    {
        global $wpdb;
        return (string) $wpdb->get_var(
            $wpdb->prepare("SELECT {$column} FROM {$wpdb->prefix}{$table} WHERE id = %d", $id)
        );
    }

    private function refundedQuantity(int $itemId): int
    {
        $lineMeta = json_decode($this->column('fct_order_items', 'line_meta', $itemId), true);
        return (int) ($lineMeta['refunded_quantity'] ?? 0);
    }

    public function partialForceLocalConfirmRecordsOnlyThePartialAmount(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $transaction = $this->paidCharge([], ['status' => Status::ORDER_PROCESSING]);
        $order = $transaction->order;
        $refundInfo = ['transaction_id' => (int) $transaction->id, 'amount' => Helper::toCent(40)];

        [, $body] = $this->refund($I, (int) $order->id, $refundInfo);
        $I->seeResponseCodeIs(422);
        Assert::assertSame('remote_refund_failed', $body['code'] ?? null, wp_json_encode($body));

        [, $body] = $this->refund($I, (int) $order->id, $refundInfo + ['force_local' => 'true']);

        $I->seeResponseCodeIs(200);
        Assert::assertSame('warning', $body['gateway_refund']['status'] ?? null, wp_json_encode($body));
        Assert::assertSame(1, FakeRefundGateway::$refundCalls);

        $rows = $this->refundRows((int) $order->id);
        Assert::assertCount(1, $rows);
        Assert::assertSame(Helper::toCent(40), (int) $rows[0]->total);
        Assert::assertSame(Helper::toCent(40), $this->chargeRefundedTotal((int) $transaction->id));
        Assert::assertSame(Helper::toCent(40), (int) $this->column('fct_orders', 'total_refund', (int) $order->id));
        Assert::assertSame(Status::PAYMENT_PARTIALLY_REFUNDED, $this->column('fct_orders', 'payment_status', (int) $order->id));
    }

    public function forceLocalResendIsValidatedAgainstTheCurrentItems(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item, $transaction] = $this->orderWithItem(1);
        (new OrderItemFactory())->create((int) $order->id, [
            'quantity'   => 1,
            'unit_price' => Helper::toCent(100),
            'subtotal'   => Helper::toCent(100),
            'line_total' => Helper::toCent(100),
        ]);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_orders', ['total_amount' => Helper::toCent(200), 'total_paid' => Helper::toCent(200)], ['id' => (int) $order->id]);
        $wpdb->update($wpdb->prefix . 'fct_order_transactions', ['total' => Helper::toCent(200)], ['id' => (int) $transaction->id]);

        $refundInfo = ['transaction_id' => (int) $transaction->id, 'amount' => 0, 'manageStock' => 'false'];
        $itemA = [['id' => (int) $item->id, 'refund_qty' => 1]];

        [, $body] = $this->refund($I, (int) $order->id, $refundInfo, $itemA);
        $I->seeResponseCodeIs(422);
        Assert::assertSame('remote_refund_failed', $body['code'] ?? null, wp_json_encode($body));

        $this->refund($I, (int) $order->id, $refundInfo + ['force_local' => 'true'], $itemA);
        $I->seeResponseCodeIs(200);
        Assert::assertSame(1, $this->refundedQuantity((int) $item->id));

        [$status, $body] = $this->refund($I, (int) $order->id, $refundInfo + ['force_local' => 'true'], $itemA);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('Invalid refund amount.', $body['message'] ?? null);
        Assert::assertCount(1, $this->refundRows((int) $order->id));
        Assert::assertSame(Helper::toCent(100), $this->chargeRefundedTotal((int) $transaction->id));
        Assert::assertSame(Helper::toCent(100), (int) $this->column('fct_orders', 'total_refund', (int) $order->id));
    }

    public function itemRefundAdoptsTheRowAStripeWebhookRecordedFirst(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item, $transaction] = $this->orderWithItem(3);
        FakeRefundGateway::$nextResult = 're_fake_1';
        FakeRefundGateway::$beforeReturn = function ($charge, $amount) {
            $this->stripeChargeRefunded($charge, 're_fake_1', $amount);
        };
        $events = $this->countOrderRefundedEvents();

        try {
            [$status, $body] = $this->refund($I, (int) $order->id, [
                'transaction_id' => (int) $transaction->id,
                'amount'         => 0,
                'manageStock'    => 'false',
            ], [['id' => (int) $item->id, 'refund_qty' => 1]]);
        } finally {
            remove_action('fluent_cart/order_refunded', $events->listener);
        }

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        $rows = $this->refundRows((int) $order->id);
        Assert::assertCount(1, $rows);
        Assert::assertSame('re_fake_1', $rows[0]->vendor_charge_id);
        Assert::assertSame(3, (int) $this->column('fct_order_items', 'quantity', (int) $item->id));
        Assert::assertSame(Helper::toCent(300), (int) $this->column('fct_order_items', 'line_total', (int) $item->id));
        Assert::assertSame(1, $this->refundedQuantity((int) $item->id));
        Assert::assertSame(Helper::toCent(100), (int) $this->column('fct_order_items', 'refund_total', (int) $item->id));
        Assert::assertSame(Helper::toCent(100), $this->chargeRefundedTotal((int) $transaction->id));
        Assert::assertSame(Helper::toCent(100), (int) $this->column('fct_orders', 'total_refund', (int) $order->id));
        Assert::assertSame(1, $events->fired, 'the webhook and the admin request must announce the refund once');
    }

    public function discountedYenItemRefundChargesWholeYenPerUnit(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item, $transaction] = $this->orderWithItem(3);
        FakeRefundGateway::$nextResult = 're_fake_1';
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_orders', ['currency' => 'JPY', 'subtotal' => 60000, 'total_amount' => 50000, 'total_paid' => 50000], ['id' => (int) $order->id]);
        $wpdb->update($wpdb->prefix . 'fct_order_items', ['unit_price' => 20000, 'subtotal' => 60000, 'discount_total' => 10000, 'line_total' => 50000], ['id' => (int) $item->id]);
        $wpdb->update($wpdb->prefix . 'fct_order_transactions', ['currency' => 'JPY', 'total' => 50000], ['id' => (int) $transaction->id]);

        $refundInfo = ['transaction_id' => (int) $transaction->id, 'amount' => 0, 'manageStock' => 'false'];
        $refundOneUnit = [['id' => (int) $item->id, 'refund_qty' => 1]];

        [$status, $body] = $this->refund($I, (int) $order->id, $refundInfo, $refundOneUnit);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(16700, (int) $this->refundRows((int) $order->id)[0]->total);
        Assert::assertSame(50000, (int) $this->column('fct_order_items', 'line_total', (int) $item->id));
        Assert::assertSame(16700, (int) $this->column('fct_order_items', 'refund_total', (int) $item->id));

        FakeRefundGateway::$nextResult = 're_fake_2';
        $this->refund($I, (int) $order->id, $refundInfo, $refundOneUnit);
        FakeRefundGateway::$nextResult = 're_fake_3';
        [$status, $body] = $this->refund($I, (int) $order->id, $refundInfo, $refundOneUnit);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame([16700, 16700, 16600], array_map('intval', $this->refundRows((int) $order->id)->pluck('total')->all()));
        Assert::assertSame(50000, (int) $this->column('fct_orders', 'total_refund', (int) $order->id));
        Assert::assertSame(50000, (int) $this->column('fct_order_items', 'refund_total', (int) $item->id));
    }

    public function taxExclusiveYenItemRefundsAddUpToTheLineTotal(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item, $transaction] = $this->orderWithItem(3);
        FakeRefundGateway::$nextResult = 're_fake_1';
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_orders', ['currency' => 'JPY', 'subtotal' => 50000, 'tax_total' => 5000, 'total_amount' => 55000, 'total_paid' => 55000], ['id' => (int) $order->id]);
        $wpdb->update($wpdb->prefix . 'fct_order_items', [
            'unit_price' => 16667,
            'subtotal'   => 50000,
            'line_total' => 50000,
            'tax_amount' => 5000,
            'line_meta'  => wp_json_encode(['tax_config' => ['inclusive' => false]]),
        ], ['id' => (int) $item->id]);
        $wpdb->update($wpdb->prefix . 'fct_order_transactions', ['currency' => 'JPY', 'total' => 55000], ['id' => (int) $transaction->id]);

        $refundInfo = ['transaction_id' => (int) $transaction->id, 'amount' => 0, 'manageStock' => 'false'];
        $refundOneUnit = [['id' => (int) $item->id, 'refund_qty' => 1]];

        [$status, $body] = $this->refund($I, (int) $order->id, $refundInfo, $refundOneUnit);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(50000, (int) $this->column('fct_order_items', 'line_total', (int) $item->id));
        Assert::assertSame(5000, (int) $this->column('fct_order_items', 'tax_amount', (int) $item->id));
        Assert::assertSame(18300, (int) $this->column('fct_order_items', 'refund_total', (int) $item->id));

        FakeRefundGateway::$nextResult = 're_fake_2';
        [$status, $body] = $this->refund($I, (int) $order->id, $refundInfo, $refundOneUnit);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        FakeRefundGateway::$nextResult = 're_fake_3';
        [$status, $body] = $this->refund($I, (int) $order->id, $refundInfo, $refundOneUnit);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame([18300, 18400, 18300], array_map('intval', $this->refundRows((int) $order->id)->pluck('total')->all()));
        Assert::assertSame(55000, (int) $this->column('fct_orders', 'total_refund', (int) $order->id));
        Assert::assertSame(55000, (int) $this->column('fct_order_items', 'refund_total', (int) $item->id));
    }

    public function resyncRestocksARefundTheStripeWebhookAnnouncedFirst(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item, $transaction] = $this->orderWithItem(3);
        $product = (new ProductFactory())
            ->withVariantAttributes(['manage_stock' => 1, 'total_stock' => 10, 'available' => 7, 'item_price' => Helper::toCent(100)])
            ->create();
        $variant = $product->variants->first();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_order_items', ['post_id' => (int) $product->ID, 'object_id' => (int) $variant->id], ['id' => (int) $item->id]);

        FakeRefundGateway::$nextResult = 're_fake_1';
        $failItemUpdates = function ($query) {
            return preg_match('/^update `[^`]*fct_order_items`/i', $query) ? 'SELECT * FROM fct_missing_table_for_test' : $query;
        };
        FakeRefundGateway::$beforeReturn = function ($charge, $amount) use ($failItemUpdates) {
            $this->stripeChargeRefunded($charge, 're_fake_1', $amount);
            add_filter('query', $failItemUpdates);
        };
        $savedModules = get_option(ModuleSettings::MODULE_SETTINGS_OPTION);
        update_option(ModuleSettings::MODULE_SETTINGS_OPTION, ['stock_management' => ['active' => 'yes']]);
        ModuleSettings::getAllSettings(false);
        $events = $this->countOrderRefundedEvents();

        try {
            [$status, $body] = $this->refund($I, (int) $order->id, [
                'transaction_id' => (int) $transaction->id,
                'amount'         => 0,
                'manageStock'    => 'true',
            ], [['id' => (int) $item->id, 'refund_qty' => 1]]);
            remove_filter('query', $failItemUpdates);

            Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
            $refundRow = $this->refundRows((int) $order->id)->first();
            Assert::assertSame('pending', $refundRow->meta['local_sync'] ?? null);

            [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund/' . (int) $refundRow->id . '/resync');
        } finally {
            remove_filter('query', $failItemUpdates);
            remove_action('fluent_cart/order_refunded', $events->listener);
            update_option(ModuleSettings::MODULE_SETTINGS_OPTION, $savedModules);
            ModuleSettings::getAllSettings(false);
        }

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertCount(1, $this->refundRows((int) $order->id));
        Assert::assertSame('done', $this->refundRows((int) $order->id)->first()->meta['local_sync'] ?? null);
        Assert::assertSame(3, (int) $this->column('fct_order_items', 'quantity', (int) $item->id));
        Assert::assertSame(1, $this->refundedQuantity((int) $item->id));
        Assert::assertSame(Helper::toCent(100), (int) $this->column('fct_order_items', 'refund_total', (int) $item->id));
        Assert::assertSame(8, (int) $this->column('fct_product_variations', 'available', (int) $variant->id));
        Assert::assertSame(1, $events->fired, 'the webhook and the resync must announce the refund once');
    }

    public function itemRefundsAfterAnAmountRefundKeepTheLineAndFillItsRefundTotal(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item, $transaction] = $this->orderWithItem(2);
        $refundInfo = ['transaction_id' => (int) $transaction->id, 'amount' => 0, 'manageStock' => 'false'];
        $refundOneUnit = [['id' => (int) $item->id, 'refund_qty' => 1]];

        FakeRefundGateway::$nextResult = 're_fake_1';
        [$status, $body] = $this->refund($I, (int) $order->id, ['transaction_id' => (int) $transaction->id, 'amount' => Helper::toCent(40)]);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        FakeRefundGateway::$nextResult = 're_fake_2';
        [$status, $body] = $this->refund($I, (int) $order->id, $refundInfo, $refundOneUnit);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        FakeRefundGateway::$nextResult = 're_fake_3';
        [$status, $body] = $this->refund($I, (int) $order->id, $refundInfo, $refundOneUnit);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));

        Assert::assertSame([4000, 8000, 8000], array_map('intval', $this->refundRows((int) $order->id)->pluck('total')->all()));
        Assert::assertSame(Helper::toCent(200), (int) $this->column('fct_order_items', 'line_total', (int) $item->id));
        Assert::assertSame(2, (int) $this->column('fct_order_items', 'quantity', (int) $item->id));
        Assert::assertSame(2, $this->refundedQuantity((int) $item->id));
        Assert::assertSame(Helper::toCent(200), (int) $this->column('fct_order_items', 'refund_total', (int) $item->id));
        Assert::assertSame(Helper::toCent(200), (int) $this->column('fct_orders', 'total_refund', (int) $order->id));
    }

    public function shippedUnitsCountOnlyWhatIsLeftAfterAnItemRefund(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item, $transaction] = $this->orderWithItem(2);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_order_items', ['fulfillment_type' => 'physical'], ['id' => (int) $item->id]);
        $refundInfo = ['transaction_id' => (int) $transaction->id, 'amount' => 0, 'manageStock' => 'false'];
        $refundOneUnit = [['id' => (int) $item->id, 'refund_qty' => 1]];
        $markShipping = function (string $status) use ($I, $order) {
            [$code, $body] = $I->put('/fluent-cart/v2/orders/' . (int) $order->id . '/statuses', [
                'action'   => 'change_shipping_status',
                'statuses' => ['shipping_status' => $status],
            ]);
            Assert::assertSame(200, $code, 'Body: ' . wp_json_encode($body));
        };

        $markShipping(Status::SHIPPING_SHIPPED);
        FakeRefundGateway::$nextResult = 're_fake_1';
        [$status, $body] = $this->refund($I, (int) $order->id, $refundInfo, $refundOneUnit);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(2, (int) $this->column('fct_order_items', 'fulfilled_quantity', (int) $item->id), 'a refund does not unship units');

        $markShipping(Status::SHIPPING_DELIVERED);
        Assert::assertSame(2, (int) $this->column('fct_order_items', 'fulfilled_quantity', (int) $item->id));
        Assert::assertSame(2, (int) $this->column('fct_order_items', 'quantity', (int) $item->id));
    }

    public function shippingAfterAnItemRefundShipsOnlyTheUnitsLeft(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item, $transaction] = $this->orderWithItem(2);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_order_items', ['fulfillment_type' => 'physical'], ['id' => (int) $item->id]);

        FakeRefundGateway::$nextResult = 're_fake_1';
        [$status, $body] = $this->refund(
            $I,
            (int) $order->id,
            ['transaction_id' => (int) $transaction->id, 'amount' => 0, 'manageStock' => 'false'],
            [['id' => (int) $item->id, 'refund_qty' => 1]]
        );
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));

        [$code, $body] = $I->put('/fluent-cart/v2/orders/' . (int) $order->id . '/statuses', [
            'action'   => 'change_shipping_status',
            'statuses' => ['shipping_status' => Status::SHIPPING_SHIPPED],
        ]);
        Assert::assertSame(200, $code, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(1, (int) $this->column('fct_order_items', 'fulfilled_quantity', (int) $item->id));
    }

    public function refundingTheUnshippedUnitShipsTheOrder(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item, $transaction] = $this->orderWithItem(2);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_order_items', ['fulfillment_type' => 'physical'], ['id' => (int) $item->id]);
        $shipment = ShipmentService::createShipment(Order::find($order->id), [
            'tracking_number' => 'TRK-1',
            'items'           => [['order_item_id' => (int) $item->id, 'qty' => 1]],
        ]);
        Assert::assertInstanceOf(Shipment::class, $shipment);
        Assert::assertSame(Status::SHIPPING_PARTIALLY_SHIPPED, $this->column('fct_orders', 'shipping_status', (int) $order->id));

        FakeRefundGateway::$nextResult = 're_fake_1';
        [$status, $body] = $this->refund(
            $I,
            (int) $order->id,
            ['transaction_id' => (int) $transaction->id, 'amount' => 0, 'manageStock' => 'false'],
            [['id' => (int) $item->id, 'refund_qty' => 1]]
        );
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(Status::SHIPPING_SHIPPED, $this->column('fct_orders', 'shipping_status', (int) $order->id), 'the one unit left has shipped');
    }

    public function partialItemRefundNeverChargesMoreThanTheLineHasLeft(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item, $transaction] = $this->orderWithItem(10);
        // Earlier amount refunds left 25 cents on this line; the order still has room, so its cap stays out.
        OrderItem::query()->where('id', $item->id)->update(['refund_total' => Helper::toCent(1000) - 25]);

        FakeRefundGateway::$nextResult = 're_fake_1';
        [$status, $body] = $this->refund(
            $I,
            (int) $order->id,
            ['transaction_id' => (int) $transaction->id, 'amount' => 0, 'manageStock' => 'false'],
            [['id' => (int) $item->id, 'refund_qty' => 9]]
        );
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));

        Assert::assertSame(25, (int) $this->refundRows((int) $order->id)[0]->total);
        Assert::assertSame(Helper::toCent(1000), (int) $this->column('fct_order_items', 'refund_total', (int) $item->id));
    }

    public function fullItemRefundShowsInTheProductReport(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item, $transaction] = $this->orderWithItem(2);
        FakeRefundGateway::$nextResult = 're_fake_1';

        [$status, $body] = $this->refund($I, (int) $order->id, [
            'transaction_id' => (int) $transaction->id,
            'amount'         => 0,
            'manageStock'    => 'false',
        ], [['id' => (int) $item->id, 'refund_qty' => 2]]);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));

        // getProductSales() amounts are in cents
        $summary = ProductReportService::make()->getProductSales(ReportHelper::processParams([
            'startDate' => gmdate('Y-m-d', strtotime('-1 day')),
            'endDate'   => gmdate('Y-m-d'),
            'currency'  => $order->currency,
        ], [], true))['summary'];

        Assert::assertSame(2, (int) $summary['units'], wp_json_encode($summary));
        Assert::assertEqualsWithDelta(200.0, (float) $summary['gross'] / 100, 0.001);
        Assert::assertEqualsWithDelta(200.0, (float) $summary['refunds'] / 100, 0.001);
        Assert::assertEqualsWithDelta(0.0, (float) $summary['net'] / 100, 0.001);
    }

    public function cappedItemRefundRecordsOnlyTheRefundedAmountOnTheLine(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item, $transaction] = $this->orderWithItem(2);
        FakeRefundGateway::$nextResult = 're_fake_1';
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_orders', ['total_amount' => Helper::toCent(50), 'total_paid' => Helper::toCent(50)], ['id' => (int) $order->id]);
        $wpdb->update($wpdb->prefix . 'fct_order_transactions', ['total' => Helper::toCent(50)], ['id' => (int) $transaction->id]);

        [$status, $body] = $this->refund($I, (int) $order->id, [
            'transaction_id' => (int) $transaction->id,
            'amount'         => 0,
            'manageStock'    => 'false',
        ], [['id' => (int) $item->id, 'refund_qty' => 2]]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(Helper::toCent(50), (int) $this->refundRows((int) $order->id)[0]->total);
        Assert::assertSame(Helper::toCent(50), (int) $this->column('fct_order_items', 'refund_total', (int) $item->id));
        Assert::assertSame(2, $this->refundedQuantity((int) $item->id));
    }

    private function orderWithShippingTax(int $taxBehavior, bool $shippingInclusive): array
    {
        [$order, $item, $transaction] = $this->orderWithItem(1);
        $paid = $shippingInclusive ? 12000 : 15200;
        $shippingTax = $shippingInclusive ? 1048 : 2200;
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_orders', [
            'tax_behavior'   => $taxBehavior,
            'tax_total'      => 1000,
            'shipping_total' => 2000,
            'shipping_tax'   => $shippingTax,
            'total_amount'   => $paid,
            'total_paid'     => $paid,
        ], ['id' => (int) $order->id]);
        $wpdb->update($wpdb->prefix . 'fct_order_items', [
            'tax_amount' => 1000,
            'line_meta'  => wp_json_encode(['tax_config' => ['inclusive' => $shippingInclusive]]),
        ], ['id' => (int) $item->id]);
        $wpdb->insert($wpdb->prefix . 'fct_order_tax_rate', [
            'order_id'     => (int) $order->id,
            'tax_rate_id'  => 1,
            'order_tax'    => 1000,
            'shipping_tax' => $shippingTax,
            'total_tax'    => 1000 + $shippingTax,
            'meta'         => wp_json_encode(['shipping_inclusive' => $shippingInclusive]),
        ]);
        $wpdb->update($wpdb->prefix . 'fct_order_transactions', ['total' => $paid], ['id' => (int) $transaction->id]);

        return [$order, $item, $transaction];
    }

    private function orderMeta(int $orderId, string $key)
    {
        return \FluentCart\App\Models\Order::query()->findOrFail($orderId)->getMeta($key);
    }

    public function fullRefundIncludesShippingTaxAddedOnTop(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item, $transaction] = $this->orderWithShippingTax(1, false);
        FakeRefundGateway::$nextResult = 're_fake_1';

        [$status, $body] = $this->refund($I, (int) $order->id, [
            'transaction_id'  => (int) $transaction->id,
            'amount'          => 0,
            'manageStock'     => 'false',
            'shipping_amount' => 4200,
        ], [['id' => (int) $item->id, 'refund_qty' => 1]]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(15200, (int) $this->refundRows((int) $order->id)[0]->total);
        Assert::assertSame(15200, (int) $this->column('fct_orders', 'total_refund', (int) $order->id));
        Assert::assertSame(Status::PAYMENT_REFUNDED, $this->column('fct_orders', 'payment_status', (int) $order->id));
        Assert::assertSame(4200, (int) $this->orderMeta((int) $order->id, 'shipping_refunded_amount'));
        Assert::assertSame('1', (string) $this->orderMeta((int) $order->id, 'shipping_refunded'));
    }

    public function shippingRefundIsCappedAtShippingPlusItsTax(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, , $transaction] = $this->orderWithShippingTax(1, false);
        $refundInfo = ['transaction_id' => (int) $transaction->id, 'amount' => 0, 'manageStock' => 'false'];

        FakeRefundGateway::$nextResult = 're_fake_1';
        [$status, $body] = $this->refund($I, (int) $order->id, $refundInfo + ['shipping_amount' => 3000]);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(3000, (int) $this->orderMeta((int) $order->id, 'shipping_refunded_amount'));
        Assert::assertEmpty($this->orderMeta((int) $order->id, 'shipping_refunded'));

        FakeRefundGateway::$nextResult = 're_fake_2';
        [$status, $body] = $this->refund($I, (int) $order->id, $refundInfo + ['shipping_amount' => 9999]);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame([3000, 1200], array_map('intval', $this->refundRows((int) $order->id)->pluck('total')->all()));
        Assert::assertSame('1', (string) $this->orderMeta((int) $order->id, 'shipping_refunded'));
    }

    public function inclusiveShippingRefundAddsNoExtraTax(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, , $transaction] = $this->orderWithShippingTax(2, true);
        FakeRefundGateway::$nextResult = 're_fake_1';

        [$status, $body] = $this->refund($I, (int) $order->id, [
            'transaction_id'  => (int) $transaction->id,
            'amount'          => 0,
            'shipping_amount' => 9999,
        ]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(2000, (int) $this->refundRows((int) $order->id)[0]->total);
    }

    public function shippingRateRowDecidesWhetherShippingTaxWasAddedOnTop(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, , $transaction] = $this->orderWithShippingTax(2, false);
        FakeRefundGateway::$nextResult = 're_fake_1';

        [$status, $body] = $this->refund($I, (int) $order->id, [
            'transaction_id'  => (int) $transaction->id,
            'amount'          => 0,
            'shipping_amount' => 9999,
        ]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(4200, (int) $this->refundRows((int) $order->id)[0]->total);
    }

    public function orderDetailsSendTheRefundableShippingWithItsTax(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order] = $this->orderWithShippingTax(1, false);
        \FluentCart\App\Models\Order::query()->findOrFail((int) $order->id)->updateMeta('shipping_refunded_amount', 3000);

        [$status, $body] = $I->get('/fluent-cart/v2/orders/' . (int) $order->id);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(1200, (int) ($body['order']['shipping_refundable'] ?? -1), wp_json_encode($body['order'] ?? $body));
    }

    public function resyncMarksShippingRefundedOnlyOnceItsTaxIsRefunded(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item, $transaction] = $this->orderWithShippingTax(1, false);

        FakeRefundGateway::$nextResult = 're_fake_1';
        $failItemUpdates = function ($query) {
            return preg_match('/^update `[^`]*fct_order_items`/i', $query) ? 'SELECT * FROM fct_missing_table_for_test' : $query;
        };
        FakeRefundGateway::$beforeReturn = function () use ($failItemUpdates) {
            add_filter('query', $failItemUpdates);
        };

        try {
            [$status, $body] = $this->refund($I, (int) $order->id, [
                'transaction_id'  => (int) $transaction->id,
                'amount'          => 0,
                'manageStock'     => 'false',
                'shipping_amount' => 3000,
            ], [['id' => (int) $item->id, 'refund_qty' => 1]]);
            remove_filter('query', $failItemUpdates);

            Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
            $refundRow = $this->refundRows((int) $order->id)->first();
            Assert::assertSame('pending', $refundRow->meta['local_sync'] ?? null);
            Assert::assertEmpty($this->orderMeta((int) $order->id, 'shipping_refunded_amount'));

            [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund/' . (int) $refundRow->id . '/resync');
        } finally {
            remove_filter('query', $failItemUpdates);
        }

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(3000, (int) $this->orderMeta((int) $order->id, 'shipping_refunded_amount'));
        Assert::assertEmpty($this->orderMeta((int) $order->id, 'shipping_refunded'));
    }

    public function cappedShippingRefundRecordsOnlyTheShippingActuallyRefunded(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, , $transaction] = $this->orderWithShippingTax(1, false);

        FakeRefundGateway::$nextResult = 're_fake_1';
        [$status, $body] = $this->refund($I, (int) $order->id, ['transaction_id' => (int) $transaction->id, 'amount' => Helper::toCent(147)]);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));

        FakeRefundGateway::$nextResult = 're_fake_2';
        [$status, $body] = $this->refund($I, (int) $order->id, [
            'transaction_id'  => (int) $transaction->id,
            'amount'          => 0,
            'manageStock'     => 'false',
            'shipping_amount' => 4200,
        ]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(15200, (int) $this->column('fct_orders', 'total_refund', (int) $order->id));
        Assert::assertSame(500, (int) $this->refundRows((int) $order->id)[1]->total);
        Assert::assertSame(4200, (int) $this->orderMeta((int) $order->id, 'shipping_refunded_amount'));
        Assert::assertSame('1', (string) $this->orderMeta((int) $order->id, 'shipping_refunded'));
    }

    public function amountRefundBeyondTheItemsCountsTheRestAgainstShipping(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, , $transaction] = $this->orderWithShippingTax(1, false);

        FakeRefundGateway::$nextResult = 're_fake_1';
        [$status, $body] = $this->refund($I, (int) $order->id, ['transaction_id' => (int) $transaction->id, 'amount' => 15000]);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));

        Assert::assertSame(4000, (int) $this->orderMeta((int) $order->id, 'shipping_refunded_amount'));
        Assert::assertEmpty($this->orderMeta((int) $order->id, 'shipping_refunded'));

        [$status, $body] = $I->get('/fluent-cart/v2/orders/' . (int) $order->id);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(200, (int) ($body['order']['shipping_refundable'] ?? -1), wp_json_encode($body['order'] ?? $body));
    }

    public function amountRefundOfTheWholeOrderMarksShippingRefunded(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, , $transaction] = $this->orderWithShippingTax(1, false);

        FakeRefundGateway::$nextResult = 're_fake_1';
        [$status, $body] = $this->refund($I, (int) $order->id, ['transaction_id' => (int) $transaction->id, 'amount' => 15200]);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));

        Assert::assertSame(4200, (int) $this->orderMeta((int) $order->id, 'shipping_refunded_amount'));
        Assert::assertSame('1', (string) $this->orderMeta((int) $order->id, 'shipping_refunded'));
    }

    public function refundableShippingNeverExceedsWhatIsLeftOnTheOrder(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order] = $this->orderWithShippingTax(1, false);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_orders', ['total_refund' => 14400], ['id' => (int) $order->id]);

        [$status, $body] = $I->get('/fluent-cart/v2/orders/' . (int) $order->id);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(800, (int) ($body['order']['shipping_refundable'] ?? -1), wp_json_encode($body['order'] ?? $body));
    }

    public function shippingRefundIsCutToWhatIsLeftWhenAnOrderChargeIsNeitherLineNorShipping(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, , $transaction] = $this->orderWithShippingTax(1, false);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_orders', ['total_amount' => 15500, 'total_paid' => 15500], ['id' => (int) $order->id]);
        $wpdb->update($wpdb->prefix . 'fct_order_transactions', ['total' => 15500], ['id' => (int) $transaction->id]);

        FakeRefundGateway::$nextResult = 're_fake_1';
        [$status, $body] = $this->refund($I, (int) $order->id, ['transaction_id' => (int) $transaction->id, 'amount' => 15000]);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertEmpty($this->orderMeta((int) $order->id, 'shipping_refunded_amount'));

        FakeRefundGateway::$nextResult = 're_fake_2';
        [$status, $body] = $this->refund($I, (int) $order->id, [
            'transaction_id'  => (int) $transaction->id,
            'amount'          => 0,
            'manageStock'     => 'false',
            'shipping_amount' => 4200,
        ]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(500, (int) $this->refundRows((int) $order->id)[1]->total);
        Assert::assertSame(500, (int) $this->orderMeta((int) $order->id, 'shipping_refunded_amount'));
        Assert::assertEmpty($this->orderMeta((int) $order->id, 'shipping_refunded'));
    }

    private function orderWithTwoItems(): array
    {
        [$order, $first, $transaction] = $this->orderWithItem(1);
        $second = (new OrderItemFactory())->create((int) $order->id, [
            'quantity'   => 1,
            'unit_price' => Helper::toCent(100),
            'subtotal'   => Helper::toCent(100),
            'line_total' => Helper::toCent(100),
        ]);
        global $wpdb;
        $total = Helper::toCent(200);
        $wpdb->update($wpdb->prefix . 'fct_orders', ['subtotal' => $total, 'total_amount' => $total, 'total_paid' => $total], ['id' => (int) $order->id]);
        $wpdb->update($wpdb->prefix . 'fct_order_transactions', ['total' => $total], ['id' => (int) $transaction->id]);

        return [$order, $first, $second, $transaction];
    }

    private function failQueries(string $pattern): callable
    {
        $filter = function ($query) use ($pattern) {
            return preg_match($pattern, $query) ? 'SELECT * FROM fct_missing_table_for_test' : $query;
        };
        add_filter('query', $filter);

        return $filter;
    }

    private function assertFailedLineWriteResyncsOntoTheSelectedLine(FunctionalTester $I, bool $forceLocal): void
    {
        $I->loginAsSuperAdmin();
        [$order, $selected, $other, $transaction] = $this->orderWithTwoItems();
        FakeRefundGateway::$nextResult = 're_fake_1';
        $events = $this->countOrderRefundedEvents();
        $failLineWrites = $this->failQueries('/^update `[^`]*fct_order_items` set `refund_total`/i');

        try {
            [$status, $body] = $this->refund($I, (int) $order->id, [
                'transaction_id' => (int) $transaction->id,
                'amount'         => 0,
                'manageStock'    => 'false',
                'force_local'    => $forceLocal ? 'true' : 'false',
            ], [['id' => (int) $selected->id, 'refund_qty' => 1]]);
            remove_filter('query', $failLineWrites);

            Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
            Assert::assertSame('warning', $body['local_sync']['status'] ?? null, wp_json_encode($body));
            $refundRow = $this->refundRows((int) $order->id)->first();
            Assert::assertSame('pending', $refundRow->meta['local_sync'] ?? null);
            Assert::assertSame(0, (int) $this->column('fct_orders', 'total_refund', (int) $order->id), 'the order total rolls back with the line write');
            Assert::assertSame(0, (int) $this->column('fct_order_items', 'refund_total', (int) $selected->id));
            Assert::assertSame(1, $this->refundedQuantity((int) $selected->id));

            [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund/' . (int) $refundRow->id . '/resync');
        } finally {
            remove_filter('query', $failLineWrites);
            remove_action('fluent_cart/order_refunded', $events->listener);
        }

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('done', $this->refundRows((int) $order->id)->first()->meta['local_sync'] ?? null);
        Assert::assertSame(Helper::toCent(100), (int) $this->column('fct_orders', 'total_refund', (int) $order->id));
        Assert::assertSame(Helper::toCent(100), (int) $this->column('fct_order_items', 'refund_total', (int) $selected->id));
        Assert::assertSame(0, (int) $this->column('fct_order_items', 'refund_total', (int) $other->id));
        Assert::assertSame(1, $this->refundedQuantity((int) $selected->id), 'resync must not apply the units twice');
        Assert::assertSame(1, $events->fired);
    }

    public function failedLineWriteOnARemoteItemRefundResyncsOntoTheSelectedLine(FunctionalTester $I): void
    {
        $this->assertFailedLineWriteResyncsOntoTheSelectedLine($I, false);
    }

    public function failedLineWriteOnAForcedLocalItemRefundResyncsOntoTheSelectedLine(FunctionalTester $I): void
    {
        $this->assertFailedLineWriteResyncsOntoTheSelectedLine($I, true);
    }

    public function failedOrderWriteOnAShippingRefundResyncsTheShippingOnly(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item, $transaction] = $this->orderWithShippingTax(1, false);
        FakeRefundGateway::$nextResult = 're_fake_1';
        $failOrderWrites = $this->failQueries('/^update `[^`]*fct_orders` set/i');

        try {
            [$status, $body] = $this->refund($I, (int) $order->id, [
                'transaction_id'  => (int) $transaction->id,
                'amount'          => 0,
                'manageStock'     => 'false',
                'shipping_amount' => 3000,
            ]);
            remove_filter('query', $failOrderWrites);

            Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
            $refundRow = $this->refundRows((int) $order->id)->first();
            Assert::assertSame('pending', $refundRow->meta['local_sync'] ?? null);
            Assert::assertSame(0, (int) $this->column('fct_orders', 'total_refund', (int) $order->id));
            Assert::assertSame(3000, (int) $this->orderMeta((int) $order->id, 'shipping_refunded_amount'));

            [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund/' . (int) $refundRow->id . '/resync');
        } finally {
            remove_filter('query', $failOrderWrites);
        }

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(3000, (int) $this->column('fct_orders', 'total_refund', (int) $order->id));
        Assert::assertSame(3000, (int) $this->orderMeta((int) $order->id, 'shipping_refunded_amount'), 'resync must not add the shipping twice');
        Assert::assertSame(0, (int) $this->column('fct_order_items', 'refund_total', (int) $item->id));
    }

    private function pendingShippingRefund(FunctionalTester $I, int $orderId, int $transactionId, int $shippingAmount)
    {
        FakeRefundGateway::$nextResult = 're_fake_1';
        $failOrderMetaWrites = $this->failQueries('/^insert into `[^`]*fct_order_meta`/i');

        try {
            [$status, $body] = $this->refund($I, $orderId, [
                'transaction_id'  => $transactionId,
                'amount'          => 0,
                'manageStock'     => 'false',
                'shipping_amount' => $shippingAmount,
            ]);
        } finally {
            remove_filter('query', $failOrderMetaWrites);
        }

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        $refundRow = $this->refundRows($orderId)->first();
        Assert::assertSame('pending', $refundRow->meta['local_sync'] ?? null);
        Assert::assertEmpty($this->orderMeta($orderId, 'shipping_refunded_amount'));

        return $refundRow;
    }

    public function shippingRefundIsBlockedWhileAnotherRefundAwaitsResync(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, , $transaction] = $this->orderWithShippingTax(1, false);
        $this->pendingShippingRefund($I, (int) $order->id, (int) $transaction->id, 2000);

        FakeRefundGateway::$nextResult = 're_fake_2';
        [$status, $body] = $this->refund($I, (int) $order->id, [
            'transaction_id'  => (int) $transaction->id,
            'amount'          => 0,
            'manageStock'     => 'false',
            'shipping_amount' => 2000,
        ]);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('Re-sync the pending refund first.', $body['message'] ?? null, wp_json_encode($body));
        Assert::assertCount(1, $this->refundRows((int) $order->id));
    }

    public function resyncRefusesShippingAlreadyRefundedSinceTheRefund(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, , $transaction] = $this->orderWithShippingTax(1, false);
        $refundRow = $this->pendingShippingRefund($I, (int) $order->id, (int) $transaction->id, 2000);
        Order::query()->findOrFail((int) $order->id)->updateMeta('shipping_refunded_amount', 4200);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund/' . (int) $refundRow->id . '/resync');

        Assert::assertSame(409, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(4200, (int) $this->orderMeta((int) $order->id, 'shipping_refunded_amount'));
        Assert::assertSame('pending', $this->refundRows((int) $order->id)->first()->meta['local_sync'] ?? null);
    }

    public function failedLineWriteOnAnAmountRefundResyncsAsASpread(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $first, $second, $transaction] = $this->orderWithTwoItems();
        FakeRefundGateway::$nextResult = 're_fake_1';
        $failLineWrites = $this->failQueries('/^update `[^`]*fct_order_items` set `refund_total`/i');

        try {
            [$status, $body] = $this->refund($I, (int) $order->id, ['transaction_id' => (int) $transaction->id, 'amount' => Helper::toCent(40)]);
            remove_filter('query', $failLineWrites);

            Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
            $refundRow = $this->refundRows((int) $order->id)->first();
            Assert::assertSame('pending', $refundRow->meta['local_sync'] ?? null);
            Assert::assertSame(0, (int) $this->column('fct_orders', 'total_refund', (int) $order->id));

            [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund/' . (int) $refundRow->id . '/resync');
        } finally {
            remove_filter('query', $failLineWrites);
        }

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(Helper::toCent(40), (int) $this->column('fct_orders', 'total_refund', (int) $order->id));
        Assert::assertSame(Helper::toCent(20), (int) $this->column('fct_order_items', 'refund_total', (int) $first->id));
        Assert::assertSame(Helper::toCent(20), (int) $this->column('fct_order_items', 'refund_total', (int) $second->id));
    }

    public function webhookCountingAnUncountedItemRefundCreditsTheSelectedLine(FunctionalTester $I): void
    {
        [$order, $selected, $other, $transaction] = $this->orderWithTwoItems();
        \FluentCart\App\Models\OrderTransaction::query()->create([
            'order_id'         => (int) $order->id,
            'order_type'       => $order->type,
            'payment_method'   => FakeRefundGateway::SLUG,
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'currency'         => $transaction->currency,
            'total'            => Helper::toCent(100),
            'vendor_charge_id' => 're_fake_1',
            'meta'             => [
                'parent_id'      => (int) $transaction->id,
                'refund_route'   => 'remote',
                'is_item_refund' => true,
                'refunded_items' => [['id' => (int) $selected->id, 'refund_qty' => 1, 'refund_amount' => Helper::toCent(100)]],
                'manage_stock'   => false,
            ],
            'uuid'             => md5('uncounted-refund'),
        ]);

        $this->stripeChargeRefunded($transaction, 're_fake_1', Helper::toCent(100));

        Assert::assertCount(1, $this->refundRows((int) $order->id));
        Assert::assertSame(Helper::toCent(100), (int) $this->column('fct_orders', 'total_refund', (int) $order->id));
        Assert::assertSame(Helper::toCent(100), (int) $this->column('fct_order_items', 'refund_total', (int) $selected->id));
        Assert::assertSame(0, (int) $this->column('fct_order_items', 'refund_total', (int) $other->id));
    }
}
