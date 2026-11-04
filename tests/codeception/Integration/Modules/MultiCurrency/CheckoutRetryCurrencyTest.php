<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Helpers\CheckoutProcessor;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;

/**
 * Paying an unpaid order again after a currency switch re-uses its draft
 * order, subscription and transaction. All three follow the new currency and
 * rate — a renewal must bill the subscription in the currency the customer
 * paid in. Base USD; first attempt USD, retry GBP at 0.79.
 */
class CheckoutRetryCurrencyTest extends DatabaseTestCase
{
    public function test_a_retried_order_moves_its_subscription_and_transaction_to_the_new_currency(): void
    {
        $customer = (new CustomerFactory())->create();

        $first = new CheckoutProcessor([$this->subscriptionItem(4980)], [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);
        $order = $first->createDraftOrder();
        $this->assertSame('USD', $order->currency);
        $this->assertSame('USD', Subscription::query()->where('parent_order_id', $order->id)->first()->currency);

        // The shopper switches to GBP and pays the same unpaid order
        $retry = new CheckoutProcessor([$this->subscriptionItem(3934)], [
            'customer_id'       => $customer->id,
            'payment_method'    => 'offline_payment',
            'currency_snapshot' => CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL]),
        ]);
        $retry->createDraftOrder(Order::query()->find($order->id));

        $order = Order::query()->find($order->id);
        $this->assertSame('GBP', $order->currency);

        $subscription = Subscription::query()->where('parent_order_id', $order->id)->first();
        $this->assertSame('GBP', $subscription->getAttributes()['currency'], 'renewals bill in GBP, not the first attempt\'s USD');
        $this->assertSame('GBP', $subscription->config['fx']['currency']);
        $this->assertSame('0.790000000000', $subscription->config['fx']['rate']);
        $this->assertSame('GBP', CurrencySnapshot::fromSubscription($subscription)['currency']);

        $transaction = OrderTransaction::query()->where('order_id', $order->id)->first();
        $this->assertSame('0.790000000000', (string) $transaction->rate, 'a transaction carries its order\'s rate');
    }

    private function subscriptionItem(int $price): array
    {
        return [
            'object_id'        => 1,
            'post_id'          => 1,
            'quantity'         => 1,
            'unit_price'       => $price,
            'price'            => $price,
            'subtotal'         => $price,
            'line_total'       => $price,
            'discount_total'   => 0,
            'coupon_discount'  => 0,
            'manual_discount'  => 0,
            'tax_amount'       => 0,
            'shipping_charge'  => 0,
            'cost'             => 0,
            'post_title'       => 'Plan',
            'product_title'    => 'Plan',
            'title'            => 'Monthly',
            'variation_title'  => 'Monthly',
            'fulfillment_type' => 'digital',
            'other_info'       => [
                'payment_type'    => 'subscription',
                'repeat_interval' => 'monthly',
                'times'           => 0,
                'trial_days'      => 0,
                'signup_fee'      => 0,
                'recurring_tax'   => 0,
            ],
        ];
    }
}
