<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\IPN;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPalSubscriptions;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Traits\BuildsSubscriptions;
use Tests\Support\Traits\MocksPayPalHttp;

class PayPalRenewalLockTest extends DatabaseTestCase
{
    use BuildsSubscriptions;
    use MocksPayPalHttp;

    public function setUp(): void
    {
        parent::setUp();
        $this->installPayPalHttpMock();
    }

    public function tearDown(): void
    {
        $this->removePayPalHttpMock();
        parent::tearDown();
    }

    public function entryPoints(): array
    {
        return [['webhook'], ['resync']];
    }

    /**
     * `refunded` expects false on purpose: a charge carrying that status never
     * settled, so it is not a recorded sale.
     *
     * Refunding a settled payment does NOT produce such a row — every refund
     * creates a separate `refund`-typed transaction (Refund::refund(),
     * Refund::createOrRecordRefund(), Refund::recordRefund()) and only
     * updates the charge's refunded_total metadata, leaving its status
     * `succeeded`. The state is still reachable by another route:
     * OrderController::updateTransactionStatus() accepts `refunded` from the
     * admin status action for any transaction that is not already `succeeded`
     * (app/Http/Controllers/OrderController.php:1075-1101), so a pending,
     * authorized or failed charge can be marked refunded by hand. Money never
     * moved in that case, which is exactly why the lookup must keep ignoring it.
     *
     * The row fails the moment someone widens the status filter to include
     * refunded.
     */
    public function recordedStatuses(): array
    {
        return [['succeeded', true], ['refunded', false], ['pending', false], ['failed', false]];
    }

    /** @dataProvider recordedStatuses */
    public function test_recorded_sale_lookup_requires_a_settled_charge_for_the_matching_subscription(string $status, bool $expected): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain();
        $saleId = 'RECORDED-' . uniqid();
        (new OrderTransactionFactory())->create($order->id, [
            'subscription_id' => $subscription->id,
            'payment_method' => 'paypal',
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'vendor_charge_id' => $saleId,
            'status' => $status,
        ]);
        $paypal = new PayPalSubscriptions();
        $this->assertSame($expected, $paypal->hasRecordedSale($subscription, $saleId), 'Only settled charges count as recorded');
        $this->assertFalse($paypal->hasRecordedSale($subscription, $saleId . '-OTHER'), 'A different sale must not match');
        $otherSubscription = new Subscription();
        $otherSubscription->id = 0;
        $this->assertFalse($paypal->hasRecordedSale($otherSubscription, $saleId), 'A different subscription must not match');
    }

    /**
     * The lookup asks for a settled CHARGE, and the type half of that is not
     * pinned by the status datasets above: every row they build is already
     * charge-typed, so deleting the transaction_type filter survives all four.
     * A refund row carrying a settled status is the case that separates them.
     */
    public function test_recorded_sale_lookup_ignores_a_non_charge_transaction(): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain();
        $saleId = 'REFUND-ROW-' . uniqid();

        (new OrderTransactionFactory())->create($order->id, [
            'subscription_id'  => $subscription->id,
            'payment_method'   => 'paypal',
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'vendor_charge_id' => $saleId,
            'status'           => Status::TRANSACTION_SUCCEEDED,
        ]);

        $paypal = new PayPalSubscriptions();

        $this->assertFalse($paypal->hasRecordedSale($subscription, $saleId),
            'a refund transaction is not a recorded sale, whatever status it carries');
    }

    /** @dataProvider entryPoints */
    public function test_unrecorded_sale_remains_retryable_when_another_connection_holds_its_lock(string $entryPoint): void
    {
        $saleId = 'LOCK-' . uniqid();
        $vendorId = 'I-' . uniqid();
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'current_payment_method' => 'paypal',
            'vendor_subscription_id' => $vendorId,
            'status' => Status::SUBSCRIPTION_ACTIVE,
            'recurring_total' => 7740,
        ], [], ['payment_method' => 'paypal']);
        (new OrderTransactionFactory())->create($order->id, [
            'subscription_id' => $subscription->id,
            'payment_method' => 'paypal',
            'vendor_charge_id' => $saleId . '-FIRST',
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total' => 7740,
        ]);
        $remote = ['id' => $vendorId, 'status' => 'ACTIVE'];
        $this->scriptPayPal('billing/subscriptions/' . $vendorId, 200, $remote);
        $sales = [
            ['id' => $saleId . '-FIRST', 'status' => 'COMPLETED', 'time' => '2025-08-11T18:26:00Z',
                'amount_with_breakdown' => ['gross_amount' => ['value' => '77.40']]],
            ['id' => $saleId, 'status' => 'COMPLETED', 'time' => '2026-08-11T18:26:00Z',
                'amount_with_breakdown' => ['gross_amount' => ['value' => '154.80']]],
        ];
        $rival = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $lockName = 'fc_webhook_' . $saleId;
        try {
            $this->assertSame('1', (string) $rival->get_var($rival->prepare('SELECT GET_LOCK(%s, 0)', $lockName)), 'Rival must hold the sale lock');
            if ($entryPoint === 'webhook') {
                $result = (new IPN())->processRecurringPaymentReceived([
                    'vendor_subscription_id' => $vendorId,
                    'charge' => ['id' => $saleId, 'amount' => ['total' => '154.80']],
                ]);
                $this->assertFalse($result, 'An unrecorded sale must not be acknowledged');
                $getError = new \ReflectionMethod(IPN::class, 'getRecurringPaymentError');
                $getError->setAccessible(true);
                $error = $getError->invoke(null);
                $this->assertNotFalse(as_next_scheduled_action('fluent_cart/paypal/subscription_resync_retry', [$subscription->id, 1], 'fluent-cart'), 'The webhook must schedule recovery');
                $this->assertSame([$saleId], (array) $subscription->getMeta('paypal_pending_sales', []), 'Recovery must track the unresolved sale');
            } else {
                $error = (new PayPalSubscriptions())->reSyncSubscriptionFromRemote($subscription, $remote, $sales);
            }
            $this->assertTrue(is_wp_error($error), 'Lock contention must remain an error');
            $this->assertSame('lock_failed', $error->get_error_code(), 'The original lock error must survive');
            $this->assertFalse(OrderTransaction::query()->where('vendor_charge_id', $saleId)->exists(), 'The sale has no backing transaction');
            $this->assertCount(1, (array) Subscription::query()->find($subscription->id)->getMeta('early_payment_history', []), 'Credit must remain available to the concurrent recorder');
        } finally {
            $rival->get_var($rival->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
            $rival->close();
            as_unschedule_all_actions('fluent_cart/paypal/subscription_resync_retry', [$subscription->id, 1], 'fluent-cart');
        }
    }
}
