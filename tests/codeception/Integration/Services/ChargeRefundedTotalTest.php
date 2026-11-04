<?php

namespace Tests\Integration\Services;

use FluentCart\App\Events\Order\OrderRefund;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\Refund;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\FakeRefundGateway;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Traits\BuildsRefundFixtures;
use Tests\Support\Traits\InvokesPrivateMethods;

class ChargeRefundedTotalTest extends DatabaseTestCase
{
    use BuildsRefundFixtures, InvokesPrivateMethods;

    private OrderTransactionFactory $txnFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->txnFactory = new OrderTransactionFactory();
        FakeRefundGateway::register();
    }

    public function tearDown(): void
    {
        FakeRefundGateway::remove();
        parent::tearDown();
    }

    public function test_stripe_webhook_first_refund_adds_to_charge_refunded_total(): void
    {
        $charge = $this->paidCharge(['payment_method' => 'stripe', 'vendor_charge_id' => 'pi_first']);

        $this->stripeChargeRefunded($charge, 're_first', 3000);

        $rows = $this->refundRows($charge->order_id);
        $this->assertCount(1, $rows);
        $this->assertSame($charge->id, (int) $rows[0]->meta['parent_id']);
        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
    }

    public function test_stripe_webhook_redelivery_keeps_charge_refunded_total(): void
    {
        $charge = $this->paidCharge(['payment_method' => 'stripe', 'vendor_charge_id' => 'pi_redeliver']);

        $this->stripeChargeRefunded($charge, 're_redeliver', 3000);
        $this->stripeChargeRefunded($charge, 're_redeliver', 3000);

        $this->assertCount(1, $this->refundRows($charge->order_id));
        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
    }

    public function test_admin_refund_adopting_webhook_row_counts_refund_once(): void
    {
        $charge = $this->paidCharge(['payment_method' => FakeRefundGateway::SLUG, 'vendor_charge_id' => 'pi_race']);
        FakeRefundGateway::$beforeReturn = function ($transaction, $amount) {
            $this->stripeChargeRefunded($transaction, 're_fake_1', $amount);
        };

        $result = (new Refund())->processRefund(OrderTransaction::query()->findOrFail($charge->id), 3000);

        $this->assertIsArray($result);
        $this->assertCount(1, $this->refundRows($charge->order_id));
        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
    }

    public function test_older_stripe_row_linked_by_transaction_id_is_counted(): void
    {
        $charge = $this->paidCharge(['payment_method' => 'stripe', 'vendor_charge_id' => 'pi_legacy']);
        $this->txnFactory->create($charge->order_id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => 2000,
            'payment_method'   => 'stripe',
            'vendor_charge_id' => 're_legacy_old',
            'meta'             => ['reason' => 'requested_by_customer', 'transaction_id' => $charge->id],
        ]);

        $this->stripeChargeRefunded($charge, 're_legacy_new', 3000);

        $this->assertSame(5000, $this->chargeRefundedTotal($charge->id));
    }

    public function test_recount_keeps_higher_stored_total(): void
    {
        $charge = $this->paidCharge(['payment_method' => 'stripe', 'vendor_charge_id' => 'pi_stored', 'meta' => ['refunded_total' => 5000]]);

        $this->stripeChargeRefunded($charge, 're_stored', 3000);

        $this->assertSame(5000, $this->chargeRefundedTotal($charge->id));
    }

    public function test_order_refund_event_counts_webhook_row_written_without_total(): void
    {
        $charge = $this->paidCharge(['payment_method' => 'authorize_dot_net', 'vendor_charge_id' => 'an_charge_shape']);
        $refund = $this->txnFactory->create($charge->order_id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => 3000,
            'payment_method'   => 'authorize_dot_net',
            'vendor_charge_id' => 'an_refund_shape',
            'meta'             => ['parent_id' => $charge->id, 'refund_source' => 'webhook'],
        ]);

        (new OrderRefund(Order::query()->findOrFail($charge->order_id), $refund))->dispatch();

        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
    }

    public function test_webhook_after_admin_refund_counts_refund_once(): void
    {
        $charge = $this->paidCharge(['payment_method' => FakeRefundGateway::SLUG, 'vendor_charge_id' => 'pi_admin_first']);

        $result = (new Refund())->processRefund($charge, 3000);
        $this->stripeChargeRefunded($charge, 're_fake_1', 3000);

        $this->assertIsArray($result);
        $this->assertCount(1, $this->refundRows($charge->order_id));
        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
    }

    public function test_refund_of_one_charge_leaves_other_charge_unchanged(): void
    {
        $chargeA = $this->paidCharge(['payment_method' => 'stripe', 'vendor_charge_id' => 'pi_charge_a']);
        $chargeB = $this->txnFactory->create($chargeA->order_id, [
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 5000,
            'payment_method'   => 'stripe',
            'vendor_charge_id' => 'pi_charge_b',
        ]);

        $this->stripeChargeRefunded($chargeB, 're_charge_b', 2000);

        $this->assertSame(0, $this->chargeRefundedTotal($chargeA->id));
        $this->assertSame(2000, $this->chargeRefundedTotal($chargeB->id));
    }

    public function test_failed_refund_row_is_not_counted(): void
    {
        $charge = $this->paidCharge(['payment_method' => 'stripe', 'vendor_charge_id' => 'pi_failed_row']);
        $this->txnFactory->create($charge->order_id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_FAILED,
            'total'            => 4000,
            'payment_method'   => 'stripe',
            'meta'             => ['parent_id' => $charge->id],
        ]);

        $this->stripeChargeRefunded($charge, 're_after_failed', 3000);

        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
    }

    public function test_recount_with_stale_charge_does_not_lower_persisted_total(): void
    {
        $charge = $this->paidCharge(['payment_method' => 'stripe', 'vendor_charge_id' => 'pi_stale']);
        $this->txnFactory->create($charge->order_id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => 3000,
            'payment_method'   => 'stripe',
            'vendor_charge_id' => 're_stale_a',
            'meta'             => ['parent_id' => $charge->id],
        ]);
        $staleCharge = OrderTransaction::query()->findOrFail($charge->id);

        $freshCharge = OrderTransaction::query()->findOrFail($charge->id);
        $freshCharge->meta = array_merge($freshCharge->meta, ['refunded_total' => 8000]);
        $freshCharge->save();

        PaymentHelper::recountTransactionRefundedTotal($staleCharge);

        $this->assertSame(8000, $this->chargeRefundedTotal($charge->id));
    }

    public function test_recount_keeps_refund_total_and_meta_written_just_before_its_update(): void
    {
        global $wpdb;

        $charge = $this->paidCharge(['payment_method' => 'stripe', 'vendor_charge_id' => 'pi_concurrent_meta']);
        $this->txnFactory->create($charge->order_id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => 3000,
            'payment_method'   => 'stripe',
            'vendor_charge_id' => 're_concurrent_meta',
            'meta'             => ['parent_id' => $charge->id],
        ]);

        $table = $wpdb->prefix . 'fct_order_transactions';
        $concurrentWrite = function ($query) use ($wpdb, $table, $charge, &$concurrentWrite) {
            if (stripos($query, 'UPDATE') === 0 && strpos($query, $table) !== false) {
                remove_filter('query', $concurrentWrite);
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET meta = %s WHERE id = %d",
                    wp_json_encode(['refunded_total' => 8000, 'dispute_id' => 'dp_1']),
                    $charge->id
                ));
            }
            return $query;
        };
        add_filter('query', $concurrentWrite);

        PaymentHelper::recountTransactionRefundedTotal($charge);
        remove_filter('query', $concurrentWrite);

        $meta = OrderTransaction::query()->findOrFail($charge->id)->meta;
        $this->assertSame(8000, (int) $meta['refunded_total']);
        $this->assertSame('dp_1', $meta['dispute_id']);
    }

    public function test_recount_sets_total_on_charge_with_empty_meta(): void
    {
        global $wpdb;

        $charge = $this->paidCharge(['payment_method' => 'stripe', 'vendor_charge_id' => 'pi_empty_meta']);
        $wpdb->update($wpdb->prefix . 'fct_order_transactions', ['meta' => '[]'], ['id' => $charge->id]);
        $this->txnFactory->create($charge->order_id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => 3000,
            'payment_method'   => 'stripe',
            'vendor_charge_id' => 're_empty_meta',
            'meta'             => ['parent_id' => $charge->id],
        ]);

        $wpdb->update($wpdb->prefix . 'fct_order_transactions', ['updated_at' => '2020-01-01 00:00:00'], ['id' => $charge->id]);

        PaymentHelper::recountTransactionRefundedTotal($charge);

        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
        $this->assertNotSame('2020-01-01 00:00:00', (string) OrderTransaction::query()->findOrFail($charge->id)->updated_at);
    }

    public function test_stripe_webhook_adopting_local_row_keeps_its_meta(): void
    {
        $charge = $this->paidCharge(['payment_method' => 'stripe', 'vendor_charge_id' => 'pi_adopt_local']);
        $localRow = $this->txnFactory->create($charge->order_id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => 3000,
            'payment_method'   => 'stripe',
            'vendor_charge_id' => '',
            'uuid'             => 'local-row-uuid',
            'created_at'       => '2026-01-01 10:00:00',
            'meta'             => [
                'parent_id'    => $charge->id,
                'refund_route' => Refund::ROUTE_FORCED_LOCAL,
                'created_by'   => 7,
                'local_sync'   => 'pending',
            ],
        ]);

        $this->stripeChargeRefunded($charge, 're_adopt_local', 3000);

        $rows = $this->refundRows($charge->order_id);
        $this->assertCount(1, $rows);
        $adopted = OrderTransaction::query()->findOrFail($localRow->id);
        $this->assertSame('re_adopt_local', $adopted->vendor_charge_id);
        $this->assertSame('local-row-uuid', $adopted->uuid);
        $this->assertSame('2026-01-01 10:00:00', (string) $adopted->getRawOriginal('created_at'));
        $this->assertSame(Refund::ROUTE_FORCED_LOCAL, $adopted->meta['refund_route']);
        $this->assertSame(7, (int) $adopted->meta['created_by']);
        $this->assertSame('pending', $adopted->meta['local_sync']);
        $this->assertSame($charge->id, (int) $adopted->meta['parent_id']);
        $this->assertSame('requested_by_customer', $adopted->meta['reason']);
    }

    public function test_stripe_webhook_adopting_forced_local_refund_counts_it_once(): void
    {
        $charge = $this->paidCharge(['payment_method' => 'stripe', 'vendor_charge_id' => 'pi_forced_local']);
        (new Refund())->processRefund($charge, 3000, ['force_local' => 'true']);

        $this->stripeChargeRefunded($charge, 're_forced_local', 3000);

        $rows = $this->refundRows($charge->order_id);
        $this->assertCount(1, $rows);
        $this->assertSame('re_forced_local', $rows[0]->vendor_charge_id);
        $this->assertSame(Refund::ROUTE_FORCED_LOCAL, $rows[0]->meta['refund_route']);
        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
        $this->assertSame(3000, (int) Order::query()->findOrFail($charge->order_id)->total_refund);
    }

    public function test_stripe_webhook_does_not_adopt_failed_local_row(): void
    {
        $charge = $this->paidCharge(['payment_method' => 'stripe', 'vendor_charge_id' => 'pi_failed_local']);
        $failedRow = $this->txnFactory->create($charge->order_id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_FAILED,
            'total'            => 3000,
            'payment_method'   => 'stripe',
            'vendor_charge_id' => '',
            'meta'             => ['parent_id' => $charge->id],
        ]);

        $this->stripeChargeRefunded($charge, 're_failed_local', 3000);

        $this->assertCount(2, $this->refundRows($charge->order_id));
        $this->assertSame('', (string) OrderTransaction::query()->findOrFail($failedRow->id)->vendor_charge_id);
        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
    }

    public function test_authorize_net_webhook_refund_adds_to_charge_refunded_total(): void
    {
        $ipnClass = \FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway\Webhook\IPN::class;
        if (!class_exists($ipnClass)) {
            $this->markTestSkipped('FluentCart Pro is not loaded.');
        }

        $charge = $this->paidCharge(['payment_method' => 'authorize_dot_net', 'vendor_charge_id' => 'an_charge_1']);

        $this->invokePrivateMethod($ipnClass, 'handleRefundCreated', [[
            'payload' => ['id' => 'an_refund_1', 'refTransId' => 'an_charge_1', 'authAmount' => 30.00],
        ]], new $ipnClass());

        $this->assertCount(1, $this->refundRows($charge->order_id));
        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
    }
}
