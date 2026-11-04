<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Confirmations;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;

/**
 * A payment can settle long after the pending transaction row was created —
 * e.g. an unpaid order collected weeks later via a payment link, or a delayed
 * webhook. The admin "Transaction Details" table shows the real settlement
 * moment, which for Stripe is the charge's own `created` timestamp, not the
 * moment our confirmation code happened to run.
 */
class StripeSettledAtTest extends WPTestCase
{
    /**
     * Spec: Cases/Integration/orders.md [269]
     *   "Stripe confirmPaymentSuccessByCharge records the Stripe charge's own
     *    created timestamp as meta.settled_at, not the confirmation time"
     */
    public function test_confirm_payment_success_by_charge_stamps_settled_at_from_charge_created(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $transaction = (new OrderTransactionFactory())->create($order->id, [
            'payment_method' => 'stripe',
            'created_at'     => '2026-07-28 11:42:00',
        ]);

        // A fixed past moment, distinct from both the row's created_at and the
        // wall clock, so the assertion cannot pass off the fallback now() stamp.
        $chargeCreatedEpoch = strtotime('2026-08-01 10:26:00 UTC');

        (new Confirmations())->confirmPaymentSuccessByCharge($transaction, [
            'charge' => [
                'id'                     => 'ch_settled_at_test',
                'status'                 => 'succeeded',
                'amount'                 => 23000,
                'currency'               => 'usd',
                'created'                => $chargeCreatedEpoch,
                'livemode'               => false,
                'payment_intent'         => 'pi_settled_at_test',
                'payment_method_details' => [
                    'type' => 'card',
                    'card' => ['brand' => 'visa', 'last4' => '4242'],
                ],
            ],
        ]);

        $fresh = OrderTransaction::query()->find($transaction->id);

        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $fresh->status, 'the charge confirmation must mark the transaction succeeded');
        $this->assertSame('2026-08-01 10:26:00', $fresh->meta['settled_at'] ?? null, 'settled_at must be the Stripe charge created time (GMT), so delayed webhooks and manual syncs record when the money actually moved');
    }
}
