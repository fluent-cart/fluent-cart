<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Models\Order;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * A snapshot supplied with a new order (renewals, the cart's locked rate,
 * or third-party code through fluent_cart/checkout/order_data) is only kept
 * when its rate fits DECIMAL(24,12) and it agrees with the store's base
 * currency. Otherwise the order gets a snapshot built from its own currency,
 * which never guesses a rate. The test store's base currency is USD.
 */
class ExplicitSnapshotValidationTest extends DatabaseTestCase
{
    /**
     * @dataProvider unstorableRates
     */
    public function test_a_snapshot_whose_rate_cannot_be_stored_is_replaced($rate): void
    {
        $fresh = $this->orderWithSnapshot('GBP', ['rate' => $rate, 'source' => CurrencySnapshot::SOURCE_EXTERNAL]);

        $this->assertSame(CurrencySnapshot::SOURCE_UNKNOWN, $fresh->config['fx']['source']);
        $this->assertSame('1.000000000000', $fresh->config['fx']['rate']);
        $this->assertSame('1.000000000000', (string) $fresh->rate);
    }

    public function unstorableRates(): array
    {
        return [
            'zero'             => ['0'],
            'negative'         => ['-0.81'],
            'non-numeric'      => ['abc'],
            'rounds to zero'   => ['0.0000000000001'],
            'too many digits'  => ['1000000000000'],
        ];
    }

    public function test_a_base_currency_snapshot_with_a_rate_other_than_one_is_replaced(): void
    {
        $fresh = $this->orderWithSnapshot('USD', ['rate' => '0.5', 'source' => CurrencySnapshot::SOURCE_MANUAL]);

        $this->assertSame(CurrencySnapshot::SOURCE_BASE, $fresh->config['fx']['source']);
        $this->assertSame('1.000000000000', (string) $fresh->rate);
    }

    public function test_a_snapshot_for_another_base_currency_is_replaced(): void
    {
        $fresh = $this->orderWithSnapshot('GBP', ['base_currency' => 'EUR', 'rate' => '0.85']);

        $this->assertSame(CurrencySnapshot::SOURCE_UNKNOWN, $fresh->config['fx']['source']);
        $this->assertSame('USD', $fresh->config['fx']['base_currency']);
        $this->assertSame('1.000000000000', (string) $fresh->rate);
    }

    public function test_a_valid_snapshot_is_kept_with_its_rate_normalized(): void
    {
        $fresh = $this->orderWithSnapshot('GBP', ['rate' => '0.81', 'source' => CurrencySnapshot::SOURCE_MANUAL, 'rate_id' => 7]);

        $this->assertSame(CurrencySnapshot::SOURCE_MANUAL, $fresh->config['fx']['source']);
        $this->assertSame(7, $fresh->config['fx']['rate_id']);
        $this->assertSame('0.810000000000', $fresh->config['fx']['rate']);
        $this->assertSame('0.810000000000', (string) $fresh->rate);
    }

    public function test_an_order_rate_that_cannot_be_stored_is_not_trusted(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'currency' => 'GBP', 'rate' => '0.0000000000001']);

        $fresh = Order::query()->find($order->id);

        $this->assertSame(CurrencySnapshot::SOURCE_UNKNOWN, $fresh->config['fx']['source']);
        $this->assertSame('1.000000000000', (string) $fresh->rate);
    }

    private function orderWithSnapshot(string $currency, array $overrides): Order
    {
        $snapshot = array_merge(CurrencySnapshot::make('GBP', '0.81', ['source' => CurrencySnapshot::SOURCE_MANUAL]), ['currency' => $currency], $overrides);
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'currency' => $currency, 'config' => ['fx' => $snapshot]]);

        return Order::query()->find($order->id);
    }
}
