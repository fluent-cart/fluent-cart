<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\IPN;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\Processor;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Traits\BuildsSubscriptions;
use Tests\Support\Traits\MocksPayPalHttp;

class PayPalBillCountTest extends DatabaseTestCase
{
    use BuildsSubscriptions;
    use MocksPayPalHttp;

    public function setUp(): void
    {
        parent::setUp();
        $this->installPayPalHttpMock();
    }

    public function test_activation_with_a_paid_initial_charge_persists_bill_count_one(): void
    {
        [$transaction, $subscription] = $this->pendingFirstCycleFixture();

        (new Processor())->activateSubscription(
            $this->payPalSubscriptionPayload($subscription, '10.00'),
            $transaction,
            $subscription
        );

        $this->assertSame(Status::TRANSACTION_SUCCEEDED, OrderTransaction::query()->find($transaction->id)->status);
        $this->assertSame(1, (int) Subscription::query()->find($subscription->id)->bill_count);
    }

    public function test_first_sale_webhook_after_activation_persists_bill_count_one(): void
    {
        [$transaction, $subscription] = $this->pendingFirstCycleFixture();

        (new Processor())->activateSubscription(
            $this->payPalSubscriptionPayload($subscription, ''),
            $transaction,
            $subscription
        );

        $this->assertSame(Status::TRANSACTION_PENDING, OrderTransaction::query()->find($transaction->id)->status);
        $this->assertSame(0, (int) Subscription::query()->find($subscription->id)->bill_count);

        $this->scriptPayPal('I-BILLCOUNT/transactions', 200, [
            'transactions' => [[
                'id'                    => 'SALE-FIRST-1',
                'status'                => 'COMPLETED',
                'time'                  => '2026-01-05T10:00:00Z',
                'amount_with_breakdown' => ['gross_amount' => ['value' => '10.00', 'currency_code' => 'USD']],
            ]],
        ]);
        $this->scriptPayPal('billing/subscriptions/I-BILLCOUNT', 200, $this->payPalSubscriptionPayload($subscription, ''));

        $this->assertTrue((new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => 'I-BILLCOUNT',
            'charge'                 => [
                'id'     => 'SALE-FIRST-1',
                'custom' => $subscription->uuid,
                'amount' => ['total' => '10.00'],
            ],
        ]));

        $this->assertSame(Status::TRANSACTION_SUCCEEDED, OrderTransaction::query()->find($transaction->id)->status);
        $this->assertSame(1, (int) Subscription::query()->find($subscription->id)->bill_count);
    }

    private function pendingFirstCycleFixture(): array
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain(
            [
                'status'                 => Status::SUBSCRIPTION_PENDING,
                'vendor_subscription_id' => 'I-BILLCOUNT',
                'current_payment_method' => 'paypal',
                'billing_interval'       => 'month',
                'bill_count'             => 0,
            ],
            [],
            ['currency' => 'USD']
        );

        $transaction = (new OrderTransactionFactory())->create($order->id, [
            'currency'            => 'USD',
            'total'               => 1000,
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
        $billingInfo = ['next_billing_time' => '2026-02-05T10:00:00Z'];

        if ($lastPaymentValue !== '') {
            $billingInfo['last_payment'] = ['amount' => ['currency_code' => 'USD', 'value' => $lastPaymentValue]];
        }

        return [
            'id'           => $subscription->vendor_subscription_id,
            'status'       => 'ACTIVE',
            'subscriber'   => ['payer_id' => 'PAYER-1'],
            'billing_info' => $billingInfo,
        ];
    }
}
