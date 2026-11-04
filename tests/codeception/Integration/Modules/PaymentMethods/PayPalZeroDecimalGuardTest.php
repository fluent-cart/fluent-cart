<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPalSubscriptions;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\Processor;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Traits\BuildsSubscriptions;
use Tests\Support\Traits\MocksPayPalHttp;

/**
 * The verification paths, exercised as handlers rather than as helper arithmetic.
 *
 * Storage stays x100 for every currency, so a JPY transaction can hold 100050 —
 * an amount PayPal cannot charge. It goes on the wire as "1001" and is reported
 * back as 100100. Every path that compares a PayPal-reported amount against the
 * local total must compare against what PayPal actually moved, or it reads a
 * correct payment as tampering and strands a paid order.
 *
 * These tests fail if any guard is reverted to comparing the raw stored total —
 * which is what separates them from the helper-level tests in
 * PayPalZeroDecimalCurrencyTest, all of which still pass against the old guards.
 */
class PayPalZeroDecimalGuardTest extends DatabaseTestCase
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

    public function test_sync_confirms_a_jpy_capture_paid_at_the_rounded_wire_amount(): void
    {
        $transaction = $this->jpyChargeTransaction(['vendor_charge_id' => 'CAP-JPY-OK']);

        $this->scriptPayPal('payments/captures/', 200, [
            'id'     => 'CAP-JPY-OK',
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'JPY', 'value' => '1001'],
        ]);

        $result = (new Processor())->syncRemoteTransaction($transaction);

        $this->assertNotWPError($result, 'a JPY capture paid at the rounded wire amount is the correct payment, not a mismatch');
        $this->assertSame(
            Status::TRANSACTION_SUCCEEDED,
            OrderTransaction::query()->find($transaction->id)->status
        );
    }

    public function test_sync_still_rejects_a_jpy_capture_short_by_a_whole_yen(): void
    {
        $transaction = $this->jpyChargeTransaction(['vendor_charge_id' => 'CAP-JPY-SHORT']);

        $this->scriptPayPal('payments/captures/', 200, [
            'id'     => 'CAP-JPY-SHORT',
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'JPY', 'value' => '1000'],
        ]);

        $result = (new Processor())->syncRemoteTransaction($transaction);

        $this->assertWPError($result);
        $this->assertSame('amount_mismatch', $result->get_error_code());
        $this->assertSame(
            Status::TRANSACTION_PENDING,
            OrderTransaction::query()->find($transaction->id)->status,
            'an underpaid capture must never confirm the transaction'
        );
    }

    public function test_activation_confirms_a_jpy_initial_payment_at_the_rounded_wire_amount(): void
    {
        [$transaction, $subscription] = $this->jpySubscriptionFixture();

        (new Processor())->activateSubscription(
            $this->payPalSubscriptionPayload($subscription, '1001'),
            $transaction,
            $subscription
        );

        $this->assertSame(
            Status::TRANSACTION_SUCCEEDED,
            OrderTransaction::query()->find($transaction->id)->status,
            'a JPY initial payment at the rounded wire amount must mark the transaction paid'
        );
    }

    public function test_activation_still_refuses_a_jpy_initial_payment_short_by_a_whole_yen(): void
    {
        [$transaction, $subscription] = $this->jpySubscriptionFixture();

        (new Processor())->activateSubscription(
            $this->payPalSubscriptionPayload($subscription, '1000'),
            $transaction,
            $subscription
        );

        $this->assertNotSame(
            Status::TRANSACTION_SUCCEEDED,
            OrderTransaction::query()->find($transaction->id)->status,
            'an underpaid initial payment must leave the transaction unconfirmed for the webhook to reconcile'
        );
    }

    public function test_resync_binds_the_first_jpy_charge_instead_of_recording_a_renewal(): void
    {
        [$transaction, $subscription] = $this->jpySubscriptionFixture();

        $this->scriptPayPal('billing/subscriptions/' . $subscription->vendor_subscription_id . '/transactions', 200, [
            'transactions' => [
                [
                    'id'                      => 'SALE-JPY-1',
                    'status'                  => 'COMPLETED',
                    'time'                    => '2026-01-05T10:00:00Z',
                    'amount_with_breakdown'   => ['gross_amount' => ['currency_code' => 'JPY', 'value' => '1001']],
                ],
            ],
        ]);
        $this->scriptPayPal('billing/subscriptions/' . $subscription->vendor_subscription_id, 200, [
            'id'           => $subscription->vendor_subscription_id,
            'status'       => 'ACTIVE',
            'start_time'   => '2026-01-05T10:00:00Z',
            'subscriber'   => ['payer_id' => 'PAYER-JPY'],
            'billing_info' => ['next_billing_time' => '2026-02-05T10:00:00Z'],
        ]);

        $before = OrderTransaction::query()->where('subscription_id', $subscription->id)->count();

        (new PayPalSubscriptions())->reSyncSubscriptionFromRemote($subscription);

        $bound = OrderTransaction::query()->find($transaction->id);

        $this->assertSame('SALE-JPY-1', $bound->vendor_charge_id, 'the reported sale must bind to the existing first-cycle charge, not create a renewal');
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $bound->status);
        $this->assertSame(
            $before,
            OrderTransaction::query()->where('subscription_id', $subscription->id)->count(),
            'binding the first cycle must not add a transaction row'
        );
    }

    // =========================================================================
    // Confirmation-time reconciliation.
    //
    // The order's line items sum to the stored total, which for a zero-decimal
    // currency can sit off the payable grid. PayPal reports back the rounded
    // figure it actually moved. Overwriting the stored total with it desyncs the
    // order from its own items in one of two directions, so the stored number is
    // kept and the wire figure is recorded alongside it.
    // =========================================================================

    public function test_confirmation_rounding_up_does_not_fake_an_overpayment(): void
    {
        // JPY 109890 goes on the wire as 1099 and returns as 109900.
        [$order, $transaction] = $this->jpyPendingOrder(109890);

        (new Processor())->confirmPaymentSuccessByCharge($transaction, [
            'vendor_charge_id' => 'CAP-JPY-UP',
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 109900,
        ]);

        $confirmed = OrderTransaction::query()->find($transaction->id);
        $paidOrder = Order::query()->find($order->id);

        $this->assertSame(109890, (int)$confirmed->total, 'the stored total must survive confirmation');
        $this->assertSame(109900, (int)$confirmed->meta['wire_total'], 'what PayPal actually moved must be recorded');
        $this->assertSame(Status::PAYMENT_PAID, $paidOrder->payment_status);
        $this->assertSame(
            (int)$paidOrder->total_amount,
            (int)$paidOrder->total_paid,
            'total_paid must not exceed the order total on a rounded-up capture'
        );
    }

    public function test_confirmation_rounding_down_does_not_strand_the_order_partially_paid(): void
    {
        // JPY 109940 goes on the wire as 1099 and returns as 109900 — below the
        // stored total, so adopting it leaves total_amount > total_paid forever.
        [$order, $transaction] = $this->jpyPendingOrder(109940);

        (new Processor())->confirmPaymentSuccessByCharge($transaction, [
            'vendor_charge_id' => 'CAP-JPY-DOWN',
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 109900,
        ]);

        $confirmed = OrderTransaction::query()->find($transaction->id);
        $paidOrder = Order::query()->find($order->id);

        $this->assertSame(109940, (int)$confirmed->total);
        $this->assertSame(109900, (int)$confirmed->meta['wire_total']);
        $this->assertSame(
            Status::PAYMENT_PAID,
            $paidOrder->payment_status,
            'a customer who paid the rounded-down wire amount has paid in full'
        );
    }

    public function test_confirmation_reconciles_the_half_unit_boundary(): void
    {
        // 109950 is the worst case: exactly half a yen, rounding away from the stored total.
        [$order, $transaction] = $this->jpyPendingOrder(109950);

        (new Processor())->confirmPaymentSuccessByCharge($transaction, [
            'vendor_charge_id' => 'CAP-JPY-HALF',
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 110000,
        ]);

        $confirmed = OrderTransaction::query()->find($transaction->id);

        $this->assertSame(109950, (int)$confirmed->total);
        $this->assertSame(110000, (int)$confirmed->meta['wire_total']);
        $this->assertSame(Status::PAYMENT_PAID, Order::query()->find($order->id)->payment_status);
    }

    public function test_confirmation_records_a_genuinely_different_amount_unchanged(): void
    {
        // 110000 is not what 109890 rounds to. The reconciliation is an exact
        // wire-amount match, not a tolerance — anything else is recorded as it came.
        [, $transaction] = $this->jpyPendingOrder(109890);

        (new Processor())->confirmPaymentSuccessByCharge($transaction, [
            'vendor_charge_id' => 'CAP-JPY-WRONG',
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 110000,
        ]);

        $confirmed = OrderTransaction::query()->find($transaction->id);

        $this->assertSame(110000, (int)$confirmed->total);
        $this->assertArrayNotHasKey('wire_total', (array)$confirmed->meta);
    }

    public function test_confirmation_leaves_a_two_decimal_currency_alone(): void
    {
        // USD is on the grid already; the reported amount stays authoritative there.
        [, $transaction] = $this->jpyPendingOrder(10990, 'USD');

        (new Processor())->confirmPaymentSuccessByCharge($transaction, [
            'vendor_charge_id' => 'CAP-USD',
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 11000,
        ]);

        $confirmed = OrderTransaction::query()->find($transaction->id);

        $this->assertSame(11000, (int)$confirmed->total, 'a two-decimal currency must keep the existing overwrite behaviour');
        $this->assertArrayNotHasKey('wire_total', (array)$confirmed->meta);
    }

    public function test_a_redelivered_confirmation_does_not_re_reconcile(): void
    {
        [, $transaction] = $this->jpyPendingOrder(109890);

        $args = [
            'vendor_charge_id' => 'CAP-JPY-DUP',
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 109900,
        ];

        (new Processor())->confirmPaymentSuccessByCharge($transaction, $args);
        (new Processor())->confirmPaymentSuccessByCharge(
            OrderTransaction::query()->find($transaction->id),
            $args
        );

        $confirmed = OrderTransaction::query()->find($transaction->id);

        $this->assertSame(109890, (int)$confirmed->total);
        $this->assertSame(109900, (int)$confirmed->meta['wire_total']);
        $this->assertSame(
            1,
            OrderTransaction::query()->where('order_id', $confirmed->order_id)->count(),
            'a redelivery must not add a transaction row'
        );
    }

    /**
     * A pending one-time charge whose order total is the stored (possibly off-grid) amount.
     *
     * @return array{0: Order, 1: OrderTransaction}
     */
    private function jpyPendingOrder(int $total, string $currency = 'JPY'): array
    {
        $customer = (new CustomerFactory())->create();

        $order = (new OrderFactory())->create([
            'customer_id'  => $customer->id,
            'currency'     => $currency,
            'subtotal'     => $total,
            'total_amount' => $total,
        ]);

        $transaction = (new OrderTransactionFactory())->create($order->id, [
            'currency'            => $currency,
            'total'               => $total,
            'payment_method'      => 'paypal',
            'payment_method_type' => 'PayPal',
            'status'              => Status::TRANSACTION_PENDING,
            'vendor_charge_id'    => '',
        ]);

        return [$order, $transaction];
    }

    /**
     * A JPY charge storing 100050 — the residue PayPal cannot charge.
     */
    private function jpyChargeTransaction(array $overrides = []): OrderTransaction
    {
        [, , $order] = $this->buildSubscriptionChain([], [], ['currency' => 'JPY']);

        return (new OrderTransactionFactory())->create($order->id, array_merge([
            'currency'            => 'JPY',
            'total'               => 100050,
            'payment_method'      => 'paypal',
            'payment_method_type' => 'PayPal',
            'status'              => Status::TRANSACTION_PENDING,
        ], $overrides));
    }

    /**
     * @return array{0: OrderTransaction, 1: Subscription}
     */
    private function jpySubscriptionFixture(): array
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain(
            [
                'status'                 => Status::SUBSCRIPTION_PENDING,
                'vendor_subscription_id' => 'I-JPY-TEST',
                'current_payment_method' => 'paypal',
                'billing_interval'       => 'month',
            ],
            [],
            ['currency' => 'JPY']
        );

        $transaction = (new OrderTransactionFactory())->create($order->id, [
            'currency'            => 'JPY',
            'total'               => 100050,
            'subscription_id'     => $subscription->id,
            'payment_method'      => 'paypal',
            'payment_method_type' => 'PayPal',
            'vendor_charge_id'    => '',
            'status'              => Status::TRANSACTION_PENDING,
        ]);

        return [$transaction, $subscription];
    }

    private function payPalSubscriptionPayload(Subscription $subscription, string $lastPaymentValue): array
    {
        return [
            'id'           => $subscription->vendor_subscription_id,
            'status'       => 'ACTIVE',
            'subscriber'   => ['payer_id' => 'PAYER-JPY'],
            'billing_info' => [
                'next_billing_time' => '2026-02-05T10:00:00Z',
                'last_payment'      => [
                    'amount' => ['currency_code' => 'JPY', 'value' => $lastPaymentValue],
                ],
            ],
        ];
    }
}
