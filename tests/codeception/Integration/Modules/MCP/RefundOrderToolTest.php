<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\MCP\Tools\OrderTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\FailingQueries;
use Tests\Support\Doubles\FakeRefundGateway;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Traits\BuildsRefundFixtures;

class RefundOrderToolTest extends DatabaseTestCase
{
    use BuildsRefundFixtures;

    public function setUp(): void
    {
        parent::setUp();
        FakeRefundGateway::register();
    }

    public function tearDown(): void
    {
        FakeRefundGateway::remove();
        parent::tearDown();
    }

    public function test_a_new_preview_is_refused_after_a_refund_that_was_not_recorded(): void
    {
        $charge = $this->paidCharge(['payment_mode' => 'test']);
        $this->refundWithoutRecording($charge);

        $preview = OrderTools::refundOrder(['order_id' => $charge->order_id, 'amount' => 30, 'dry_run' => true]);

        $this->assertInstanceOf(\WP_Error::class, $preview);
        $this->assertSame('unrecorded_refund_pending', $preview->get_error_code());
        $this->assertSame(1, FakeRefundGateway::$refundCalls);
    }

    public function test_recording_the_gateway_refund_allows_new_refunds(): void
    {
        $charge = $this->paidCharge(['payment_mode' => 'test']);
        $this->refundWithoutRecording($charge);
        $this->recordRefundRow($charge, 're_fake_1', '2000-01-01 00:00:00');

        $preview = OrderTools::refundOrder(['order_id' => $charge->order_id, 'amount' => 30, 'dry_run' => true]);

        $this->assertIsArray($preview);
        $this->assertNotEmpty($preview['data']['confirm_token']);
    }

    public function test_a_refund_recorded_after_the_failure_allows_new_refunds_when_the_gateway_returned_no_refund_id(): void
    {
        $charge = $this->paidCharge(['payment_mode' => 'test']);
        $this->refundWithoutRecording($charge);
        $this->forgetVendorRefundId($charge);
        $this->recordRefundRow($charge, '', gmdate('Y-m-d H:i:s', time() + 5));

        $preview = OrderTools::refundOrder(['order_id' => $charge->order_id, 'amount' => 30, 'dry_run' => true]);

        $this->assertIsArray($preview);
    }

    public function test_an_unrelated_refund_does_not_clear_a_known_gateway_refund(): void
    {
        $charge = $this->paidCharge(['payment_mode' => 'test']);
        $this->refundWithoutRecording($charge);
        $this->recordRefundRow($charge, 're_other', gmdate('Y-m-d H:i:s', time() + 5));

        $preview = OrderTools::refundOrder(['order_id' => $charge->order_id, 'amount' => 30, 'dry_run' => true]);

        $this->assertInstanceOf(\WP_Error::class, $preview);
        $this->assertSame('unrecorded_refund_pending', $preview->get_error_code());
    }

    public function test_a_refund_of_another_amount_does_not_clear_an_unknown_gateway_refund(): void
    {
        $charge = $this->paidCharge(['payment_mode' => 'test']);
        $this->refundWithoutRecording($charge);
        $this->forgetVendorRefundId($charge);
        $this->recordRefundRow($charge, '', gmdate('Y-m-d H:i:s', time() + 5), 2000);

        $preview = OrderTools::refundOrder(['order_id' => $charge->order_id, 'amount' => 30, 'dry_run' => true]);

        $this->assertInstanceOf(\WP_Error::class, $preview);
        $this->assertSame('unrecorded_refund_pending', $preview->get_error_code());
    }

    public function test_a_refund_recorded_in_the_same_second_does_not_clear_an_unknown_gateway_refund(): void
    {
        $charge = $this->paidCharge(['payment_mode' => 'test']);
        $this->refundWithoutRecording($charge);
        $failedAt = gmdate('Y-m-d H:i:s', time() - 60);
        $this->forgetVendorRefundId($charge, $failedAt);
        $this->recordRefundRow($charge, '', $failedAt);

        $preview = OrderTools::refundOrder(['order_id' => $charge->order_id, 'amount' => 30, 'dry_run' => true]);

        $this->assertInstanceOf(\WP_Error::class, $preview);
        $this->assertSame('unrecorded_refund_pending', $preview->get_error_code());
    }

    public function test_a_refund_recorded_while_waiting_for_the_lock_rejects_the_confirm(): void
    {
        global $wpdb;
        $charge = $this->paidCharge(['payment_mode' => 'test']);
        $preview = OrderTools::refundOrder(['order_id' => $charge->order_id, 'amount' => 30, 'dry_run' => true]);

        $refundByAdmin = function ($query) use (&$refundByAdmin, $wpdb, $charge) {
            if (stripos($query, 'GET_LOCK') !== false) {
                remove_filter('query', $refundByAdmin);
                $wpdb->update($wpdb->prefix . 'fct_orders', ['total_refund' => 3000], ['id' => $charge->order_id]);
            }
            return $query;
        };
        add_filter('query', $refundByAdmin);

        try {
            $result = OrderTools::refundOrder([
                'order_id'        => $charge->order_id,
                'amount'          => 30,
                'confirm_token'   => $preview['data']['confirm_token'],
                'idempotency_key' => 'lock-order',
            ]);
        } finally {
            remove_filter('query', $refundByAdmin);
        }

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('state_changed', $result->get_error_code());
        $this->assertSame(0, FakeRefundGateway::$refundCalls);
    }

    // SCAN-10: a second refund on the same order while one is running records nothing
    // and never reaches the gateway; the confirmation then still works exactly once.
    public function test_a_confirm_while_another_refund_holds_the_order_lock_records_nothing(): void
    {
        $charge = $this->paidCharge(['payment_mode' => 'test']);
        $preview = OrderTools::refundOrder(['order_id' => $charge->order_id, 'amount' => 30, 'dry_run' => true]);
        $confirm = [
            'order_id'        => $charge->order_id,
            'amount'          => 30,
            'confirm_token'   => $preview['data']['confirm_token'],
            'idempotency_key' => 'concurrent-refund',
        ];

        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $other->get_var($other->prepare('SELECT GET_LOCK(%s, %d)', 'fct_refund_' . $charge->order_id, 0));

        try {
            $whileBusy = OrderTools::refundOrder($confirm);
        } finally {
            $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', 'fct_refund_' . $charge->order_id));
            $other->close();
        }

        $this->assertInstanceOf(\WP_Error::class, $whileBusy);
        $this->assertSame('refund_in_progress', $whileBusy->get_error_code());
        $this->assertSame(0, FakeRefundGateway::$refundCalls, 'a refund refused for the busy lock must not reach the gateway');
        $this->assertSame(0, $this->refundRowCount($charge), 'a refund refused for the busy lock must record nothing');

        $first = OrderTools::refundOrder($confirm);
        $this->assertNotInstanceOf(\WP_Error::class, $first, 'the confirmation must still work once the other refund finished');
        $this->assertSame(1, FakeRefundGateway::$refundCalls);

        $replay = OrderTools::refundOrder(array_merge($confirm, ['idempotency_key' => 'concurrent-refund-2']));
        $this->assertInstanceOf(\WP_Error::class, $replay, 'one confirmation must refund only once');
        $this->assertSame(1, FakeRefundGateway::$refundCalls);
        $this->assertSame(1, $this->refundRowCount($charge));
    }

    private function refundRowCount(OrderTransaction $charge): int
    {
        return OrderTransaction::query()
            ->where('order_id', $charge->order_id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->count();
    }

    public function test_a_confirm_previewed_by_another_operator_is_refused_after_an_unrecorded_refund(): void
    {
        $charge = $this->paidCharge(['payment_mode' => 'test']);
        $operatorA = self::factory()->user->create(['role' => 'administrator']);
        $operatorB = self::factory()->user->create(['role' => 'administrator']);

        wp_set_current_user($operatorB);
        $previewB = OrderTools::refundOrder(['order_id' => $charge->order_id, 'amount' => 30, 'dry_run' => true]);

        wp_set_current_user($operatorA);
        $this->refundWithoutRecording($charge);

        wp_set_current_user($operatorB);
        $result = OrderTools::refundOrder([
            'order_id'      => $charge->order_id,
            'amount'        => 30,
            'confirm_token' => $previewB['data']['confirm_token'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unrecorded_refund_pending', $result->get_error_code());
        $this->assertSame(1, FakeRefundGateway::$refundCalls);
    }

    public function test_a_replayed_unrecorded_refund_does_not_block_the_order_again(): void
    {
        $charge = $this->paidCharge(['payment_mode' => 'test']);
        $this->refundWithoutRecording($charge, 'replay-key');

        $this->forgetVendorRefundId($charge, gmdate('Y-m-d H:i:s', time() - 60));
        $this->recordRefundRow($charge, '', gmdate('Y-m-d H:i:s', time() - 30));

        $preview = OrderTools::refundOrder(['order_id' => $charge->order_id, 'amount' => 30, 'dry_run' => true]);
        $replay = OrderTools::refundOrder([
            'order_id'        => $charge->order_id,
            'amount'          => 30,
            'confirm_token'   => $preview['data']['confirm_token'],
            'idempotency_key' => 'replay-key',
        ]);
        $this->assertSame('refund_not_recorded', $replay->get_error_code());

        $next = OrderTools::refundOrder(['order_id' => $charge->order_id, 'amount' => 30, 'dry_run' => true]);

        $this->assertIsArray($next);
        $this->assertSame(1, FakeRefundGateway::$refundCalls);
    }

    public function test_the_unrecorded_refund_error_reaches_the_caller_when_the_marker_cannot_be_saved(): void
    {
        $charge = $this->paidCharge(['payment_mode' => 'test']);
        $failMetaWrites = FailingQueries::matching('/^(insert into|update) `[^`]*fct_order_meta`/i');

        try {
            $this->refundWithoutRecording($charge);
        } finally {
            $failMetaWrites->remove();
        }

        $this->assertFalse(Order::query()->findOrFail($charge->order_id)->getMeta(OrderTools::UNRECORDED_REFUND_META));
    }

    public function test_a_preview_rejects_a_fractional_amount_on_a_zero_decimal_currency(): void
    {
        $charge = $this->paidCharge(['payment_mode' => 'test', 'currency' => 'JPY'], ['currency' => 'JPY']);

        $preview = OrderTools::refundOrder(['order_id' => $charge->order_id, 'amount' => 30.5, 'dry_run' => true]);

        $this->assertInstanceOf(\WP_Error::class, $preview);
        $this->assertSame('invalid_refund_precision', $preview->get_error_code());
    }

    public function test_a_preview_rejects_more_than_the_charge_can_refund(): void
    {
        $charge = $this->paidCharge(['payment_mode' => 'test'], ['total_amount' => 15000, 'total_paid' => 15000]);
        $second = (new OrderTransactionFactory())->create((int) $charge->order_id, [
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 5000,
            'payment_method'   => FakeRefundGateway::SLUG,
            'payment_mode'     => 'test',
            'vendor_charge_id' => 'ch_fake_2',
        ]);

        $preview = OrderTools::refundOrder(['order_id' => $charge->order_id, 'transaction_id' => $second->id, 'amount' => 80, 'dry_run' => true]);

        $this->assertInstanceOf(\WP_Error::class, $preview);
        $this->assertSame('invalid_refund_amount', $preview->get_error_code());
    }

    public function test_a_preview_without_an_amount_offers_what_the_selected_charge_can_refund(): void
    {
        $charge = $this->paidCharge(['payment_mode' => 'test'], ['total_amount' => 15000, 'total_paid' => 15000]);
        (new OrderTransactionFactory())->create((int) $charge->order_id, [
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 5000,
            'payment_method'   => FakeRefundGateway::SLUG,
            'payment_mode'     => 'test',
            'vendor_charge_id' => 'ch_fake_2',
        ]);

        $preview = OrderTools::refundOrder(['order_id' => $charge->order_id, 'dry_run' => true]);

        $this->assertIsArray($preview);
        $this->assertSame(5000, $preview['data']['preview']['amount']['amount_cents']);
    }

    public function test_a_preview_without_an_amount_names_the_charge_limit_when_the_latest_charge_is_spent(): void
    {
        $charge = $this->paidCharge(['payment_mode' => 'test'], ['total_amount' => 15000, 'total_paid' => 15000]);
        (new OrderTransactionFactory())->create((int) $charge->order_id, [
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 5000,
            'payment_method'   => FakeRefundGateway::SLUG,
            'payment_mode'     => 'test',
            'vendor_charge_id' => 'ch_fake_2',
            'meta'             => ['refunded_total' => 5000],
        ]);

        $preview = OrderTools::refundOrder(['order_id' => $charge->order_id, 'dry_run' => true]);

        $this->assertInstanceOf(\WP_Error::class, $preview);
        $this->assertSame('invalid_refund_amount', $preview->get_error_code());
    }

    public function test_a_preview_offers_the_ledger_balance_when_total_paid_is_already_net(): void
    {
        $charge = $this->paidCharge(
            ['payment_mode' => 'test', 'meta' => ['refunded_total' => 3000]],
            ['total_paid' => 7000, 'total_refund' => 3000, 'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED]
        );
        $this->recordRefundRow($charge, 're_fake_1', '2000-01-01 00:00:00');

        $preview = OrderTools::refundOrder(['order_id' => $charge->order_id, 'dry_run' => true]);

        $this->assertIsArray($preview);
        $this->assertSame(7000, $preview['data']['preview']['refundable']['amount_cents']);
        $this->assertSame(7000, $preview['data']['preview']['amount']['amount_cents']);
    }

    public function test_the_refund_amount_is_described_in_the_order_currency(): void
    {
        $definition = OrderTools::definitions()['fluent-cart/refund-order'];

        $this->assertStringContainsString("order's currency", $definition['description']);
        $this->assertStringNotContainsString('store currency', $definition['description']);
        $this->assertStringContainsString("order's currency", $definition['input_schema']['properties']['amount']['description']);
        $this->assertStringNotContainsString('store currency', $definition['input_schema']['properties']['amount']['description']);
    }

    private function refundWithoutRecording(OrderTransaction $charge, string $idempotencyKey = ''): void
    {
        $preview = OrderTools::refundOrder(['order_id' => $charge->order_id, 'amount' => 30, 'dry_run' => true]);
        $failInserts = FailingQueries::matching('/^insert into `[^`]*fct_order_transactions`/i');

        try {
            $result = OrderTools::refundOrder([
                'order_id'        => $charge->order_id,
                'amount'          => 30,
                'confirm_token'   => $preview['data']['confirm_token'],
                'idempotency_key' => $idempotencyKey,
            ]);
        } finally {
            $failInserts->remove();
        }

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('refund_not_recorded', $result->get_error_code());
        $this->assertSame(1, FakeRefundGateway::$refundCalls);
    }

    private function forgetVendorRefundId(OrderTransaction $charge, string $at = ''): void
    {
        $order = Order::query()->findOrFail($charge->order_id);
        $marker = $order->getMeta(OrderTools::UNRECORDED_REFUND_META);
        $marker['vendor_refund_id'] = null;
        if ($at) {
            $marker['at'] = $at;
        }
        $order->updateMeta(OrderTools::UNRECORDED_REFUND_META, $marker);
    }

    private function recordRefundRow(OrderTransaction $charge, string $vendorRefundId, string $createdAt, int $total = 3000): void
    {
        $refund = (new OrderTransactionFactory())->create((int) $charge->order_id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => $total,
            'payment_method'   => FakeRefundGateway::SLUG,
            'payment_mode'     => 'test',
            'vendor_charge_id' => $vendorRefundId,
        ]);
        OrderTransaction::query()->where('id', $refund->id)->update(['created_at' => $createdAt]);
    }
}
