<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\StoreManagedRenewal\Services\RenewalService;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * Every order and subscription carries a currency snapshot; transactions,
 * renewals and refunds copy the stored rate instead of looking one up.
 * dev-docs/multi-currency/plan.md §9. The test store's base currency is USD.
 */
class CurrencySnapshotTest extends DatabaseTestCase
{

    public function test_base_currency_order_is_stamped_with_rate_one(): void
    {
        $order = $this->makeOrder(['currency' => 'USD']);

        $fx = Order::query()->find($order->id)->config['fx'];

        $this->assertSame('USD', $fx['currency']);
        $this->assertSame('USD', $fx['base_currency']);
        $this->assertSame(CurrencySnapshot::SOURCE_BASE, $fx['source']);
        $this->assertSame('1.000000000000', $fx['rate']);
        $this->assertSame(2, $fx['decimals']);
        $this->assertSame('1.000000000000', (string) Order::query()->find($order->id)->rate);
    }

    public function test_foreign_currency_order_without_a_rate_is_marked_unknown_not_guessed(): void
    {
        $order = $this->makeOrder(['currency' => 'EUR', 'rate' => 1]);

        $fx = Order::query()->find($order->id)->config['fx'];

        $this->assertSame('EUR', $fx['currency']);
        $this->assertSame(CurrencySnapshot::SOURCE_UNKNOWN, $fx['source']);
        $this->assertFalse(CurrencySnapshot::hasTrustedRate($fx));
    }

    public function test_foreign_currency_order_freezes_the_rate_it_was_created_with(): void
    {
        $order = $this->makeOrder(['currency' => 'GBP', 'rate' => '0.79']);

        $fresh = Order::query()->find($order->id);

        $this->assertSame('0.790000000000', (string) $fresh->rate);
        $this->assertSame('0.790000000000', $fresh->config['fx']['rate']);
        $this->assertSame(CurrencySnapshot::SOURCE_EXTERNAL, $fresh->config['fx']['source']);
        $this->assertTrue(CurrencySnapshot::hasTrustedRate($fresh->config['fx']));
    }

    public function test_rate_column_survives_a_real_exchange_rate_at_full_precision(): void
    {
        // IDR -> USD style rate: DECIMAL(12,4) stored this as 0.0001 (a 64% error)
        $order = $this->makeOrder(['currency' => 'JPY', 'rate' => '0.000061234567']);

        $this->assertSame('0.000061234567', (string) Order::query()->find($order->id)->rate);
    }

    public function test_explicit_snapshot_on_a_new_order_wins(): void
    {
        $snapshot = CurrencySnapshot::make('GBP', '0.81', ['source' => CurrencySnapshot::SOURCE_MANUAL, 'rate_id' => 7]);

        $order = $this->makeOrder(['currency' => 'GBP', 'config' => ['fx' => $snapshot]]);

        $fresh = Order::query()->find($order->id);
        $this->assertSame('0.810000000000', (string) $fresh->rate);
        $this->assertSame(CurrencySnapshot::SOURCE_MANUAL, $fresh->config['fx']['source']);
        $this->assertSame(7, $fresh->config['fx']['rate_id']);
    }

    public function test_transaction_copies_the_order_rate_and_currency(): void
    {
        $order = $this->makeOrder(['currency' => 'GBP', 'rate' => '0.79']);

        $transaction = OrderTransaction::query()->create([
            'order_id'         => $order->id,
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status'           => Status::TRANSACTION_PENDING,
            'payment_method'   => 'offline_payment',
            'total'            => 3995,
            'rate'             => 1,
        ]);

        $fresh = OrderTransaction::query()->find($transaction->id);
        $this->assertSame('GBP', $fresh->currency);
        $this->assertSame('0.790000000000', (string) $fresh->rate);
    }

    public function test_foreign_currency_transaction_is_recorded_and_flagged_never_dropped(): void
    {
        $order = $this->makeOrder(['currency' => 'USD']);
        $fired = [];
        add_action('fluent_cart/currency/transaction_mismatch', function ($payload) use (&$fired) {
            $fired[] = $payload;
        });

        $transaction = OrderTransaction::query()->create([
            'order_id'         => $order->id,
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'payment_method'   => 'offline_payment',
            'currency'         => 'eur',
            'total'            => 1000,
        ]);

        $this->assertNotNull(OrderTransaction::query()->find($transaction->id), 'a real payment must never be dropped');
        $this->assertCount(1, $fired);
        $this->assertTrue(
            Activity::query()
                ->where('module_name', 'order')
                ->where('module_id', $order->id)
                ->where('title', 'Transaction currency differs from the order currency')
                ->exists()
        );
    }

    public function test_lowercase_gateway_currency_is_not_a_mismatch(): void
    {
        $order = $this->makeOrder(['currency' => 'USD']);
        $fired = 0;
        add_action('fluent_cart/currency/transaction_mismatch', function () use (&$fired) {
            $fired++;
        });

        OrderTransaction::query()->create([
            'order_id'         => $order->id,
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'payment_method'   => 'stripe',
            'currency'         => 'usd',
            'total'            => 500,
        ]);

        $this->assertSame(0, $fired);
    }

    public function test_subscription_gets_a_currency_column_and_its_parent_snapshot(): void
    {
        ['subscription' => $subscription, 'order' => $order] = $this->makeSubscriptionSetup('GBP', '0.79');

        $fresh = Subscription::query()->find($subscription->id);

        $this->assertSame('GBP', $fresh->getAttributes()['currency']);
        $this->assertSame('GBP', $fresh->currency);
        $this->assertSame('0.790000000000', $fresh->config['fx']['rate']);
        $this->assertSame($order->config['fx']['captured_at'], $fresh->config['fx']['captured_at']);
    }

    public function test_subscription_in_another_currency_than_its_parent_never_takes_the_parent_rate(): void
    {
        // A USD parent order at rate 1 must not lend a trusted USD rate to EUR amounts
        ['subscription' => $subscription] = $this->makeSubscriptionSetup('USD', '1', ['currency' => 'EUR']);

        $fresh = Subscription::query()->find($subscription->id);

        $this->assertSame('EUR', $fresh->getAttributes()['currency']);
        $this->assertSame('EUR', $fresh->config['fx']['currency']);
        $this->assertSame(CurrencySnapshot::SOURCE_UNKNOWN, $fresh->config['fx']['source']);
        $this->assertFalse(CurrencySnapshot::hasTrustedRate($fresh->config['fx']));
    }

    public function test_subscription_without_a_snapshot_never_reads_a_parent_in_another_currency(): void
    {
        ['subscription' => $subscription] = $this->makeSubscriptionSetup('USD', '1', ['currency' => 'EUR']);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_subscriptions', ['config' => json_encode(new \stdClass())], ['id' => $subscription->id]);

        $snapshot = CurrencySnapshot::fromSubscription(Subscription::query()->find($subscription->id));

        $this->assertSame('EUR', $snapshot['currency']);
        $this->assertFalse(CurrencySnapshot::hasTrustedRate($snapshot));
    }

    public function test_store_managed_renewal_carries_the_subscription_rate(): void
    {
        ['subscription' => $subscription] = $this->makeSubscriptionSetup('GBP', '0.79', [
            'collection_method' => 'manual',
            'billing_interval'  => 'daily',
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);

        $created = RenewalService::createRenewalOrders($subscription);

        $this->assertFalse(is_wp_error($created));
        $invoice = Order::query()->find($created[0]->id);

        $this->assertSame('GBP', $invoice->currency);
        $this->assertSame('0.790000000000', (string) $invoice->rate);
        $this->assertSame($subscription->config['fx']['captured_at'], $invoice->config['fx']['captured_at'], 'carried, not re-looked-up');

        $transaction = OrderTransaction::query()->where('order_id', $invoice->id)->first();
        $this->assertSame('0.790000000000', (string) $transaction->rate);
    }

    public function test_gateway_renewal_carries_the_subscription_rate(): void
    {
        ['subscription' => $subscription] = $this->makeSubscriptionSetup('GBP', '0.79');

        $transaction = SubscriptionService::recordRenewalPayment([
            'subscription_id'  => $subscription->id,
            'vendor_charge_id' => 'ch_mc_' . wp_generate_password(8, false),
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'payment_method'   => 'offline_payment',
            'payment_mode'     => 'test',
            'total'            => 3995,
        ], $subscription);

        $this->assertFalse(is_wp_error($transaction));
        $renewal = Order::query()->find($transaction->order_id);

        $this->assertSame('GBP', $renewal->currency);
        $this->assertSame('0.790000000000', (string) $renewal->rate);
        $this->assertSame('0.790000000000', (string) OrderTransaction::query()->find($transaction->id)->rate);
    }

    public function test_store_managed_renewal_bills_in_the_subscriptions_currency_not_the_parents(): void
    {
        ['subscription' => $subscription] = $this->makeSubscriptionSetup('USD', '1', [
            'currency'          => 'EUR',
            'collection_method' => 'manual',
            'billing_interval'  => 'daily',
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);

        $created = RenewalService::createRenewalOrders($subscription);

        $this->assertFalse(is_wp_error($created));
        $invoice = Order::query()->find($created[0]->id);
        $this->assertSame('EUR', $invoice->currency, 'the recurring amount is EUR');
        $this->assertSame('EUR', $invoice->config['fx']['currency']);
        $this->assertFalse(CurrencySnapshot::hasTrustedRate($invoice->config['fx']), 'the untrusted EUR snapshot is kept, not replaced by a base one');
        $this->assertSame('EUR', OrderTransaction::query()->where('order_id', $invoice->id)->first()->currency);
    }

    public function test_gateway_renewal_defaults_to_the_subscriptions_currency_not_the_parents(): void
    {
        ['subscription' => $subscription] = $this->makeSubscriptionSetup('USD', '1', ['currency' => 'EUR']);

        $transaction = SubscriptionService::recordRenewalPayment([
            'subscription_id'  => $subscription->id,
            'vendor_charge_id' => 'ch_mc_' . wp_generate_password(8, false),
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'payment_method'   => 'offline_payment',
            'payment_mode'     => 'test',
            'total'            => 3995,
        ], $subscription);

        $this->assertFalse(is_wp_error($transaction));
        $renewal = Order::query()->find($transaction->order_id);
        $this->assertSame('EUR', $renewal->currency);
        $this->assertSame('EUR', $renewal->config['fx']['currency']);
        $this->assertSame('EUR', OrderTransaction::query()->find($transaction->id)->currency);
    }

    private function makeOrder(array $overrides = []): Order
    {
        $customer = (new CustomerFactory())->create();

        return (new OrderFactory())->create(array_merge(['customer_id' => $customer->id], $overrides));
    }

    private function makeSubscriptionSetup(string $currency, string $rate, array $subscriptionOverrides = []): array
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())
            ->withVariantAttributes(['payment_type' => Status::ORDER_TYPE_SUBSCRIPTION])
            ->create();
        $variant = $product->variants->first();
        // Test mode, like a fresh test store: renewals only run when the
        // order mode matches the store's, and switching the store to live
        // would leak into later test classes (PayPal webhook credentials)
        $order = (new OrderFactory())->withAddresses()->create([
            'customer_id' => $customer->id,
            'type'        => Status::ORDER_TYPE_SUBSCRIPTION,
            'currency'    => $currency,
            'rate'        => $rate,
            'mode'        => 'test',
        ]);

        (new OrderItemFactory())->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'payment_type' => Status::ORDER_TYPE_SUBSCRIPTION,
        ]);

        $subscription = (new SubscriptionFactory())->create(array_merge([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $variant->id,
        ], $subscriptionOverrides));

        return [
            'subscription' => Subscription::query()->find($subscription->id),
            'order'        => Order::query()->find($order->id),
        ];
    }
}
