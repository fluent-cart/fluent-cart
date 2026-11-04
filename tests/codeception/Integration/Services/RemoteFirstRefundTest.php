<?php

namespace Tests\Integration\Services;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\StockManagement\StockManagement;
use FluentCart\App\Services\Payments\Refund;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\FakeRefundGateway;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\BuildsRefundFixtures;

class RemoteFirstRefundTest extends DatabaseTestCase
{
    use BuildsRefundFixtures;

    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderTransactionFactory $txnFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->txnFactory = new OrderTransactionFactory();
        FakeRefundGateway::register();
    }

    public function tearDown(): void
    {
        FakeRefundGateway::remove();
        parent::tearDown();
    }

    private function assertItemRefundLeavesStockAt(int $expectedAvailable, bool $webhookFirst, bool $manageStock = true): void
    {
        $savedModules = get_option(ModuleSettings::MODULE_SETTINGS_OPTION);
        update_option(ModuleSettings::MODULE_SETTINGS_OPTION, ['stock_management' => ['active' => 'yes']]);
        ModuleSettings::getAllSettings(false);
        (new StockManagement())->register(fluentCart());

        try {
            $product = (new ProductFactory())
                ->withVariantAttributes(['manage_stock' => 1, 'total_stock' => 10, 'available' => 7, 'item_price' => 5000])
                ->create();
            $variant = $product->variants->first();
            $txn = $this->paidCharge();
            $item = (new OrderItemFactory())->create((int) $txn->order_id, [
                'post_id'    => (int) $product->ID,
                'object_id'  => (int) $variant->id,
                'quantity'   => 2,
                'unit_price' => 5000,
                'subtotal'   => 10000,
                'line_total' => 10000,
            ]);
            $events = $this->countOrderRefundedEvents();
            if ($webhookFirst) {
                FakeRefundGateway::$beforeReturn = function ($charge, $amount) {
                    $this->stripeChargeRefunded($charge, 're_fake_1', $amount);
                };
            }

            $result = (new Refund())->processRefund($txn, 5000, [
                'manageStock'    => $manageStock,
                'is_item_refund' => true,
                'item_ids'       => [(int) $item->id],
                'refunded_items' => [[
                    'id'               => (int) $item->id,
                    'variation_id'     => (int) $variant->id,
                    'refund_qty'       => 1,
                    'restore_quantity' => 1,
                    'restock_type'     => 'return',
                ]],
            ]);
        } finally {
            update_option(ModuleSettings::MODULE_SETTINGS_OPTION, $savedModules);
            ModuleSettings::getAllSettings(false);
        }

        $this->assertIsArray($result);
        $this->assertCount(1, $this->refundRows($txn->order_id));
        $this->assertSame(1, $events->fired);
        $this->assertSame($expectedAvailable, (int) ProductVariation::query()->findOrFail($variant->id)->available);
    }

    private function orderLogs(int $orderId, string $status)
    {
        return Activity::query()
            ->where('module_type', Order::class)
            ->where('module_id', $orderId)
            ->where('status', $status)
            ->get();
    }

    public function test_remote_success_records_refund_with_vendor_id(): void
    {
        $txn = $this->paidCharge();
        $events = $this->countOrderRefundedEvents();

        $result = (new Refund())->processRefund($txn, 3000);

        $this->assertIsArray($result);
        $this->assertSame(Refund::ROUTE_REMOTE, $result['refund_route']);
        $this->assertSame('re_fake_1', $result['vendor_refund_id']);
        $this->assertSame(1, FakeRefundGateway::$refundCalls);

        $rows = $this->refundRows($txn->order_id);
        $this->assertCount(1, $rows);
        $this->assertSame(Status::TRANSACTION_REFUNDED, $rows[0]->status);
        $this->assertSame('re_fake_1', $rows[0]->vendor_charge_id);
        $this->assertSame(Refund::ROUTE_REMOTE, $rows[0]->meta['refund_route']);

        $this->assertSame(3000, (int) Order::find($txn->order_id)->total_refund);
        $this->assertSame(1, $events->fired);
    }

    public function test_remote_failure_records_nothing_and_logs(): void
    {
        $txn = $this->paidCharge();
        FakeRefundGateway::$nextResult = new \WP_Error('charge_already_refunded', 'Charge already refunded.');
        $events = $this->countOrderRefundedEvents();

        $result = (new Refund())->processRefund($txn, 3000);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('remote_refund_failed', $result->get_error_code());
        $data = $result->get_error_data();
        $this->assertTrue($data['can_force_local']);
        $this->assertSame('Charge already refunded.', $data['gateway_message']);

        $this->assertCount(0, $this->refundRows($txn->order_id));
        $this->assertSame(0, (int) ((OrderTransaction::find($txn->id)->meta['refunded_total']) ?? 0));
        $this->assertSame(0, (int) Order::find($txn->order_id)->total_refund);
        $this->assertSame(0, $events->fired);
        $this->assertCount(1, $this->orderLogs($txn->order_id, 'error'));
    }

    public function test_gateway_exception_is_a_remote_failure(): void
    {
        $txn = $this->paidCharge();
        FakeRefundGateway::$nextResult = new \RuntimeException('cURL error 28: timed out');

        $result = (new Refund())->processRefund($txn, 3000);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('remote_refund_failed', $result->get_error_code());
        $this->assertCount(0, $this->refundRows($txn->order_id));
    }

    public function test_missing_charge_id_is_unavailable_without_calling_gateway(): void
    {
        $txn = $this->paidCharge(['vendor_charge_id' => '']);

        $result = (new Refund())->processRefund($txn, 3000);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('gateway_unavailable', $result->get_error_code());
        $this->assertTrue($result->get_error_data()['can_force_local']);
        $this->assertSame(0, FakeRefundGateway::$refundCalls);
        $this->assertCount(0, $this->refundRows($txn->order_id));
    }

    public function test_unregistered_gateway_is_unavailable(): void
    {
        $txn = $this->paidCharge(['payment_method' => 'removed_gateway']);

        $result = (new Refund())->processRefund($txn, 3000);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('gateway_unavailable', $result->get_error_code());
        $this->assertCount(0, $this->refundRows($txn->order_id));
    }

    public function test_force_local_skips_gateway_records_and_dispatches(): void
    {
        $txn = $this->paidCharge();
        $events = $this->countOrderRefundedEvents();

        $result = (new Refund())->processRefund($txn, 3000, ['force_local' => 'true']);

        $this->assertIsArray($result);
        $this->assertSame(Refund::ROUTE_FORCED_LOCAL, $result['refund_route']);
        $this->assertSame(0, FakeRefundGateway::$refundCalls);

        $rows = $this->refundRows($txn->order_id);
        $this->assertCount(1, $rows);
        $this->assertSame('', (string) $rows[0]->vendor_charge_id);
        $this->assertSame(Refund::ROUTE_FORCED_LOCAL, $rows[0]->meta['refund_route']);
        $this->assertSame(3000, (int) Order::find($txn->order_id)->total_refund);
        $this->assertSame(1, $events->fired);
        $this->assertCount(1, $this->orderLogs($txn->order_id, 'warning'));
    }

    public function test_force_local_does_not_ask_the_manual_refund_filter(): void
    {
        $txn = $this->paidCharge();
        $asked = 0;
        $manual = function () use (&$asked) {
            $asked++;
            return ['status' => 'yes', 'source' => 'external'];
        };
        add_filter('fluent_cart/order_refund_manually', $manual);

        try {
            $result = (new Refund())->processRefund($txn, 3000, ['force_local' => 'true']);
        } finally {
            remove_filter('fluent_cart/order_refund_manually', $manual);
        }

        $this->assertIsArray($result);
        $this->assertSame(0, $asked);
        $this->assertSame(Refund::ROUTE_FORCED_LOCAL, $result['refund_route']);
        $this->assertSame('no', $result['manual_refund']['status']);
    }

    public function test_adopts_refund_row_a_webhook_created_first(): void
    {
        $txn = $this->paidCharge();
        $events = $this->countOrderRefundedEvents();

        // The webhook records the refund while the gateway API call is still in flight.
        FakeRefundGateway::$beforeReturn = function ($transaction, $amount) {
            Refund::createOrRecordRefund([
                'vendor_charge_id' => 're_fake_1',
                'payment_method'   => FakeRefundGateway::SLUG,
                'total'            => $amount,
            ], $transaction);
        };

        $result = (new Refund())->processRefund($txn, 3000);

        $this->assertIsArray($result);
        $rows = $this->refundRows($txn->order_id);
        $this->assertCount(1, $rows);
        $this->assertSame((int) $rows[0]->id, (int) $result['refund_transaction']->id);
        $this->assertSame(3000, (int) OrderTransaction::find($txn->id)->meta['refunded_total']);
        $this->assertSame(3000, (int) Order::find($txn->order_id)->total_refund);
        $this->assertSame(1, $events->fired);
    }

    public function test_item_refund_restocks_once(): void
    {
        $this->assertItemRefundLeavesStockAt(8, false);
    }

    public function test_item_refund_restocks_once_when_the_webhook_announced_it_first(): void
    {
        $this->assertItemRefundLeavesStockAt(8, true);
    }

    public function test_item_refund_without_manage_stock_keeps_stock_when_the_webhook_announced_it_first(): void
    {
        $this->assertItemRefundLeavesStockAt(7, true, false);
    }

    public function test_gateway_without_refund_feature_refunds_locally(): void
    {
        FakeRefundGateway::register(['payment']);
        $txn = $this->paidCharge();
        $events = $this->countOrderRefundedEvents();

        $result = (new Refund())->processRefund($txn, 3000);

        $this->assertIsArray($result);
        $this->assertSame(Refund::ROUTE_LOCAL, $result['refund_route']);
        $this->assertSame(0, FakeRefundGateway::$refundCalls);
        $this->assertCount(1, $this->refundRows($txn->order_id));
        $this->assertSame(3000, (int) Order::find($txn->order_id)->total_refund);
        $this->assertSame(1, $events->fired);
    }

    public function test_deactivated_gateway_placeholder_is_unavailable(): void
    {
        FakeRefundGateway::register([]);
        $txn = $this->paidCharge();

        $result = (new Refund())->processRefund($txn, 3000);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('gateway_unavailable', $result->get_error_code());
        $this->assertTrue($result->get_error_data()['can_force_local']);
        $this->assertStringContainsString('is not available', $result->get_error_message());
        $this->assertSame(0, FakeRefundGateway::$refundCalls);
        $this->assertCount(0, $this->refundRows($txn->order_id));
    }

    public function test_offline_gateway_with_base_refund_refunds_locally(): void
    {
        FakeRefundGateway::register(['payment', 'refund', 'offline']);
        $txn = $this->paidCharge(['vendor_charge_id' => '']);

        $result = (new Refund())->processRefund($txn, 3000);

        $this->assertIsArray($result);
        $this->assertSame(Refund::ROUTE_LOCAL, $result['refund_route']);
        $this->assertSame(0, FakeRefundGateway::$refundCalls);
        $this->assertCount(1, $this->refundRows($txn->order_id));
    }

    public function test_gateway_replaying_an_earlier_refund_id_records_nothing(): void
    {
        $txn = $this->paidCharge();
        $events = $this->countOrderRefundedEvents();
        (new Refund())->processRefund($txn, 3000);

        $result = (new Refund())->processRefund(OrderTransaction::find($txn->id), 3000);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('refund_not_recorded', $result->get_error_code());
        $this->assertStringContainsString('re_fake_1', $result->get_error_message());
        $this->assertSame('re_fake_1', $result->get_error_data()['vendor_refund_id']);
        $this->assertCount(1, $this->refundRows($txn->order_id));
        $this->assertSame(3000, (int) OrderTransaction::find($txn->id)->meta['refunded_total']);
        $this->assertSame(3000, (int) Order::find($txn->order_id)->total_refund);
        $this->assertSame(1, $events->fired);
    }

    public function test_webhook_row_of_another_amount_is_not_adopted(): void
    {
        $txn = $this->paidCharge();

        FakeRefundGateway::$beforeReturn = function ($transaction) {
            Refund::createOrRecordRefund([
                'vendor_charge_id' => 're_fake_1',
                'payment_method'   => FakeRefundGateway::SLUG,
                'total'            => 2000,
            ], $transaction);
        };

        $result = (new Refund())->processRefund($txn, 3000);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('refund_not_recorded', $result->get_error_code());
        $this->assertCount(1, $this->refundRows($txn->order_id));
        $this->assertSame(2000, (int) Order::find($txn->order_id)->total_refund);
    }

    public function test_failed_webhook_row_is_not_adopted(): void
    {
        $txn = $this->paidCharge();

        FakeRefundGateway::$beforeReturn = function ($transaction, $amount) {
            Refund::createOrRecordRefund([
                'vendor_charge_id' => 're_fake_1',
                'payment_method'   => FakeRefundGateway::SLUG,
                'total'            => $amount,
                'status'           => Status::TRANSACTION_FAILED,
            ], $transaction);
        };

        $result = (new Refund())->processRefund($txn, 3000);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('refund_not_recorded', $result->get_error_code());
        $this->assertSame(0, (int) Order::find($txn->order_id)->total_refund);
    }

    public function test_older_local_row_stamped_during_the_call_records_nothing(): void
    {
        $txn = $this->paidCharge();
        (new Refund())->processRefund($txn, 3000, ['force_local' => 'true']);
        $events = $this->countOrderRefundedEvents();

        FakeRefundGateway::$beforeReturn = function ($transaction, $amount) {
            Refund::createOrRecordRefund([
                'vendor_charge_id' => 're_fake_1',
                'payment_method'   => FakeRefundGateway::SLUG,
                'total'            => $amount,
            ], $transaction);
        };

        $result = (new Refund())->processRefund(OrderTransaction::find($txn->id), 3000);

        $this->assertWPError($result);
        $this->assertSame('refund_not_recorded', $result->get_error_code());
        $this->assertSame('re_fake_1', $result->get_error_data()['vendor_refund_id']);
        $this->assertCount(1, $this->refundRows($txn->order_id));
        $this->assertSame(3000, $this->chargeRefundedTotal($txn->id));
        $this->assertSame(3000, (int) Order::find($txn->order_id)->total_refund);
        $this->assertSame(0, $events->fired);
    }

    public function test_cod_stays_local_and_keeps_its_refund_id_filter(): void
    {
        $txn = $this->paidCharge(['payment_method' => 'offline_payment', 'vendor_charge_id' => '']);
        add_filter('fluent_cart/offline_payment_refund_id', function () {
            return 'cod_ref_9';
        });

        $result = (new Refund())->processRefund($txn, 3000);

        $this->assertIsArray($result);
        $this->assertSame(Refund::ROUTE_LOCAL, $result['refund_route']);
        $this->assertSame('cod_ref_9', $result['vendor_refund_id']);
    }

    public function test_retry_after_local_state_failure_counts_refund_once(): void
    {
        $txn = $this->paidCharge();
        list($item, $intent) = $this->oneUnitRefundIntent($txn);
        $calls = 0;
        $filter = $this->failOrderItemUpdates(function () use (&$calls) {
            if (++$calls === 1) {
                return 'SELECT * FROM fct_missing_table_for_test';
            }
            return null;
        });

        try {
            $result = (new Refund())->processRefund($txn, 3000, [], $intent);
        } finally {
            remove_filter('query', $filter);
        }

        $this->assertIsArray($result);
        $this->assertFalse($result['needs_resync']);
        $this->assertGreaterThanOrEqual(2, $calls);
        $this->assertSame(1, (int) ($item->fresh()->line_meta['refunded_quantity'] ?? 0));
        $this->assertCount(1, $this->refundRows($txn->order_id));
        $this->assertSame(3000, $this->chargeRefundedTotal($txn->id));
    }

    public function test_fallback_row_after_repeated_local_state_failure_counts_refund_once(): void
    {
        $txn = $this->paidCharge();
        list(, $intent) = $this->oneUnitRefundIntent($txn);
        $filter = $this->failOrderItemUpdates(function () {
            return 'SELECT * FROM fct_missing_table_for_test';
        });

        try {
            $result = (new Refund())->processRefund($txn, 3000, [], $intent);
        } finally {
            remove_filter('query', $filter);
        }

        $this->assertIsArray($result);
        $this->assertTrue($result['needs_resync']);
        $this->assertCount(1, $this->refundRows($txn->order_id));
        $this->assertTrue($this->refundRows($txn->order_id)->first()->isAwaitingResync());
        $this->assertSame(3000, $this->chargeRefundedTotal($txn->id));
    }

    public function test_php_error_in_local_state_still_records_the_gateway_refund(): void
    {
        $txn = $this->paidCharge();
        list(, $intent) = $this->oneUnitRefundIntent($txn);
        $filter = $this->failOrderItemUpdates(function () {
            throw new \TypeError('Argument must be of type array');
        });

        try {
            $result = (new Refund())->processRefund($txn, 3000, [], $intent);
        } finally {
            remove_filter('query', $filter);
        }

        $this->assertIsArray($result);
        $this->assertTrue($result['needs_resync']);
        $this->assertCount(1, $this->refundRows($txn->order_id));
        $this->assertSame(3000, $this->chargeRefundedTotal($txn->id));
    }

    private function oneUnitRefundIntent(OrderTransaction $txn): array
    {
        $item = (new OrderItemFactory())->create((int) $txn->order_id, [
            'quantity'   => 2,
            'unit_price' => 5000,
            'subtotal'   => 10000,
            'line_total' => 10000,
        ]);

        return [$item, [
            'items'           => [[
                'id'               => (int) $item->id,
                'variation_id'     => (int) $item->object_id,
                'refund_qty'       => 1,
                'restore_quantity' => 1,
                'restock_type'     => 'cancel',
                'pre_refund_qty'   => 2,
                'refund_amount'    => 3000,
            ]],
            'shipping_amount' => 0,
            'manage_stock'    => false,
        ]];
    }

    /**
     * @param callable $replace Returns the query to run instead, or null to run the update.
     */
    private function failOrderItemUpdates(callable $replace): callable
    {
        $filter = function ($query) use ($replace) {
            if (preg_match('/^update `[^`]*fct_order_items`/i', $query)) {
                $replacement = $replace();
                return $replacement === null ? $query : $replacement;
            }
            return $query;
        };
        add_filter('query', $filter);

        return $filter;
    }

    public function test_unrecordable_gateway_refund_is_an_error_not_a_success(): void
    {
        $txn = $this->paidCharge();
        $filter = $this->failRefundInserts();

        try {
            $result = (new Refund())->processRefund($txn, 3000);
        } finally {
            remove_filter('query', $filter);
        }

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('refund_not_recorded', $result->get_error_code());
        $data = $result->get_error_data();
        $this->assertSame('re_fake_1', $data['vendor_refund_id']);
        $this->assertSame(3000, $data['amount']);
        $this->assertStringContainsString('re_fake_1', $result->get_error_message());
        $this->assertStringContainsString('Do not refund again', $result->get_error_message());
        $this->assertArrayNotHasKey('can_force_local', $data);
        $this->assertSame(1, FakeRefundGateway::$refundCalls);
        $this->assertCount(0, $this->refundRows($txn->order_id));
        $this->assertSame(0, $this->chargeRefundedTotal($txn->id));
        $this->assertCount(1, $this->orderLogs($txn->order_id, 'error'));
    }

    public function test_unrecordable_forced_local_refund_logs_no_local_refund(): void
    {
        $txn = $this->paidCharge();
        $filter = $this->failRefundInserts();

        try {
            $result = (new Refund())->processRefund($txn, 3000, ['force_local' => 'true']);
        } finally {
            remove_filter('query', $filter);
        }

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('refund_not_recorded', $result->get_error_code());
        $this->assertSame(0, FakeRefundGateway::$refundCalls);
        $this->assertCount(0, $this->refundRows($txn->order_id));
        $this->assertCount(0, $this->orderLogs($txn->order_id, 'warning'));
        $this->assertCount(1, $this->orderLogs($txn->order_id, 'error'));
    }

    public function test_failed_parent_update_leaves_no_orphan_refund_row(): void
    {
        $txn = $this->paidCharge();
        $filter = function ($query) {
            if (preg_match('/^update `[^`]*fct_order_transactions`/i', $query)) {
                return 'SELECT * FROM fct_missing_table_for_test';
            }
            return $query;
        };
        add_filter('query', $filter);

        try {
            $result = (new Refund())->processRefund($txn, 3000);
        } finally {
            remove_filter('query', $filter);
        }

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('refund_not_recorded', $result->get_error_code());
        $this->assertCount(0, $this->refundRows($txn->order_id));
        $this->assertSame(0, $this->chargeRefundedTotal($txn->id));
    }

    public function test_each_charge_of_an_order_is_refunded_against_its_own_total(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 20000,
            'total_paid'     => 20000,
            'payment_status' => Status::PAYMENT_PAID,
            'payment_method' => FakeRefundGateway::SLUG,
        ]);
        $first = $this->txnFactory->create($order->id, [
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 10000,
            'payment_method'   => FakeRefundGateway::SLUG,
            'vendor_charge_id' => 'ch_fake_1',
        ]);
        $second = $this->txnFactory->create($order->id, [
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 10000,
            'payment_method'   => FakeRefundGateway::SLUG,
            'vendor_charge_id' => 'ch_fake_2',
        ]);

        $this->assertIsArray((new Refund())->processRefund(OrderTransaction::find($first->id), 6000));
        FakeRefundGateway::$nextResult = 're_fake_2';
        $this->assertIsArray((new Refund())->processRefund(OrderTransaction::find($second->id), 4000));

        $this->assertSame(6000, $this->chargeRefundedTotal($first->id));
        $this->assertSame(4000, $this->chargeRefundedTotal($second->id));
        $this->assertSame(10000, (int) Order::find($order->id)->total_refund);

        $result = (new Refund())->processRefund(OrderTransaction::find($first->id), 5000);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_refund_amount', $result->get_error_code());
        $this->assertSame('Refund amount exceeds the maximum refundable amount for this transaction.', $result->get_error_message());
        $this->assertSame(2, FakeRefundGateway::$refundCalls);
        $this->assertCount(2, $this->refundRows($order->id));
    }
}
