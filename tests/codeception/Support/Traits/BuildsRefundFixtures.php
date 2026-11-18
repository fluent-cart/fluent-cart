<?php

namespace Tests\Support\Traits;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Webhook\IPN as StripeIPN;
use Tests\Support\Doubles\FakeRefundGateway;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;

/**
 * Paid charges, refund-row reads and gateway-side refund simulation shared by the
 * refund Integration tests and Functional Cests.
 */
trait BuildsRefundFixtures
{
    protected function paidCharge(array $txnOverrides = [], array $orderOverrides = []): OrderTransaction
    {
        $paymentMethod = $txnOverrides['payment_method'] ?? FakeRefundGateway::SLUG;
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(array_merge([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'payment_status' => Status::PAYMENT_PAID,
            'payment_method' => $paymentMethod,
        ], $orderOverrides));

        return (new OrderTransactionFactory())->create((int) $order->id, array_merge([
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 10000,
            'payment_method'   => $paymentMethod,
            'vendor_charge_id' => 'ch_fake_1',
        ], $txnOverrides));
    }

    protected function refundRows(int $orderId)
    {
        return OrderTransaction::query()
            ->where('order_id', $orderId)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->get();
    }

    protected function chargeRefundedTotal(int $transactionId): int
    {
        return (int) (OrderTransaction::query()->findOrFail($transactionId)->meta['refunded_total'] ?? 0);
    }

    protected function stripeChargeRefunded(OrderTransaction $charge, string $refundId, int $amount): void
    {
        $event = json_decode(json_encode([
            'livemode' => false,
            'data'     => [
                'object' => [
                    'id'             => 'ch_' . $charge->vendor_charge_id,
                    'payment_intent' => $charge->vendor_charge_id,
                    'currency'       => 'usd',
                    'refunds'        => [
                        'data' => [
                            ['id' => $refundId, 'amount' => $amount, 'reason' => 'requested_by_customer', 'created' => time()],
                        ],
                    ],
                ],
            ],
        ]));

        (new StripeIPN())->handleChargeRefunded([
            'event' => $event,
            'order' => $charge->order,
        ]);
    }

    protected function countOrderRefundedEvents(): \stdClass
    {
        $counter = new \stdClass();
        $counter->fired = 0;
        $counter->listener = function () use ($counter) {
            $counter->fired++;
        };
        add_action('fluent_cart/order_refunded', $counter->listener);

        return $counter;
    }
}
