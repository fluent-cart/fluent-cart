<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use PHPUnit\Framework\Assert;
use Tests\Support\Concurrency\ConcurrentMarkAsPaidRunner;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\FunctionalTester;

class ConcurrentMarkOrderPaidCest
{
    /**
     * Spec: Cases/Functional/orders.md [43a]
     *   "Two simultaneous admin mark-as-paid requests record exactly one succeeded
     *    charge; one request is rejected as already paid and the amount paid never
     *    exceeds the order total."
     */
    public function twoSimultaneousAdminMarkAsPaidRequestsRecordExactlyOneSucceededCharge(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create([
            'email' => 'mark-paid-concurrently@example.test',
        ]);
        $order = (new OrderFactory())
            ->withTransaction()
            ->create([
                'customer_id'      => $customer->id,
                'status'           => Status::ORDER_ON_HOLD,
                'payment_status'   => Status::PAYMENT_PENDING,
                'payment_method'   => 'offline_payment',
                'fulfillment_type' => 'physical',
                'subtotal'         => Helper::toCent(100),
                'total_amount'     => Helper::toCent(100),
                'total_paid'       => 0,
            ]);
        $pendingTransaction = OrderTransaction::query()
            ->where('order_id', $order->id)
            ->where('status', Status::TRANSACTION_PENDING)
            ->first();

        Assert::assertNotNull(
            $pendingTransaction,
            'the concurrency fixture must include one pending charge transaction'
        );

        $results = ConcurrentMarkAsPaidRunner::run((int) $order->id);
        $statuses = array_column($results, 'status');
        sort($statuses);

        Assert::assertSame(
            [200, 423],
            $statuses,
            'exactly one simultaneous mark-as-paid call must succeed and the other must be rejected'
        );

        $rejected = current(array_filter($results, static function (array $result): bool {
            return $result['status'] === 423;
        }));
        Assert::assertIsArray($rejected, 'one concurrent response must be the already-paid rejection');
        Assert::assertStringContainsString(
            'already been paid',
            strtolower((string) ($rejected['body']['message'] ?? '')),
            'the losing concurrent request must explain that the order was already paid'
        );

        global $wpdb;
        $succeededCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d AND status = %s",
            (int) $order->id,
            Status::TRANSACTION_SUCCEEDED
        ));
        $persistedOrder = $wpdb->get_row($wpdb->prepare(
            "SELECT total_amount, total_paid, payment_status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
            (int) $order->id
        ), ARRAY_A);

        Assert::assertSame(
            1,
            $succeededCount,
            'simultaneous mark-as-paid calls must leave exactly one succeeded charge transaction'
        );
        Assert::assertNotEmpty($persistedOrder, 'the paid order must remain persisted after both requests finish');
        Assert::assertSame(
            Helper::toCent(100),
            (int) $persistedOrder['total_paid'],
            'the persisted paid amount must equal the order total after the winning request'
        );
        Assert::assertLessThanOrEqual(
            (int) $persistedOrder['total_amount'],
            (int) $persistedOrder['total_paid'],
            'the persisted paid amount must never exceed the order total'
        );
        Assert::assertSame(
            Status::PAYMENT_PAID,
            (string) $persistedOrder['payment_status'],
            'the winning request must leave the order payment status paid'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [43b]
     *   "Order-paid listeners run only after the mark-as-paid database transaction has
     *    committed, so third-party hooks never execute while the order row is locked."
     *
     * The framework connection's transaction level is 1 while markAsPaid() holds its
     * SELECT ... FOR UPDATE transaction and 0 once it has committed; a listener that
     * observes level 1 was invoked under the row lock.
     */
    public function orderPaidListenersRunAfterTheMarkAsPaidLockIsReleased(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create([
            'email' => 'mark-paid-hook-timing@example.test',
        ]);
        $order = (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'status'           => Status::ORDER_ON_HOLD,
            'payment_status'   => Status::PAYMENT_PENDING,
            'payment_method'   => 'offline_payment',
            'fulfillment_type' => 'physical',
            'subtotal'         => Helper::toCent(100),
            'total_amount'     => Helper::toCent(100),
            'total_paid'       => 0,
        ]);

        $observedLevels = [];
        $listener = static function () use (&$observedLevels): void {
            $observedLevels[] = Order::query()->getConnection()->transactionLevel();
        };
        add_action('fluent_cart/order_paid', $listener, 10, 1);

        try {
            $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/mark-as-paid', [
                'payment_method' => 'offline_payment',
            ]);
        } finally {
            remove_action('fluent_cart/order_paid', $listener, 10);
        }

        $I->seeResponseCodeIs(200);
        Assert::assertSame(
            [0],
            $observedLevels,
            'fluent_cart/order_paid must fire exactly once and only after the mark-as-paid transaction has committed (transaction level 0), never while the order row lock is held'
        );
    }
}
