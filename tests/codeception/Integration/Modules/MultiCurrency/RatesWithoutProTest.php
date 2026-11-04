<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Services\PlanUpgradeService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\MetaFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;

/**
 * Exchange rates are stored by FluentCart Pro. Core alone has no rates table
 * and never queries one: a store that never had Pro does not have it, and a
 * query to a missing table throws. Every rate path must still answer.
 * Base USD.
 */
class RatesWithoutProTest extends DatabaseTestCase
{
    use MakesMultiCurrencyAvailable;

    /** @var string[] queries that reached the rates table */
    private $rateQueries = [];

    public function setUp(): void
    {
        parent::setUp();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']]]);
        $this->reset();
    }

    public function tearDown(): void
    {
        unset($_COOKIE[CurrencyContext::COOKIE]);
        $this->reset();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_a_foreign_currency_has_no_rate_and_the_rates_table_is_never_queried(): void
    {
        $rate = $this->withoutRatesTable(function () {
            return ExchangeRateService::current('GBP');
        });

        $this->assertNull($rate, 'no rate means the currency is refused, never priced at 1');
        $this->assertSame([], $this->rateQueries);
        $this->assertSame('1.000000000000', ExchangeRateService::current('USD')['rate']);
    }

    public function test_the_switcher_offers_only_the_base_currency(): void
    {
        $this->makeMultiCurrencyAvailable();

        $selectable = $this->withoutRatesTable(function () {
            return CurrencyContext::selectableCurrencies();
        });

        $this->assertSame(['USD'], $selectable);
        $this->assertSame([], $this->rateQueries);
    }

    public function test_an_upgrade_is_priced_at_the_orders_trusted_rate(): void
    {
        $snapshot = CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL]);

        $path = $this->withoutRatesTable(function () use ($snapshot) {
            return $this->upgradePaths('GBP', $snapshot)[0];
        });

        $this->assertTrue($path['is_available']);
        $this->assertSame('GBP', $path['currency']);
        $this->assertSame(3934.0, $path['price'], '$49.80 at the order rate 0.79');
        $this->assertSame([], $this->rateQueries);
    }

    public function test_an_upgrade_of_an_order_without_a_trusted_rate_is_refused(): void
    {
        $path = $this->withoutRatesTable(function () {
            return $this->upgradePaths('GBP', CurrencySnapshot::legacy('GBP'))[0];
        });

        $this->assertFalse($path['is_available'], 'never priced in the base currency beside a GBP order');
        $this->assertSame([], $this->rateQueries);
    }

    public function test_a_subscription_hold_is_priced_at_the_subscriptions_rate(): void
    {
        $subscription = $this->makeLegacySubscription('GBP', '0.79');
        $cart = $this->unsavedCart($subscription, 4980);

        $quote = $this->withoutRatesTable(function () use ($cart, $subscription) {
            return CartCurrency::holdToSubscription($cart, $subscription, true);
        });

        $this->assertIsArray($quote);
        $this->assertSame('GBP', $quote['currency']);
        $this->assertSame('0.790000000000', $quote['rate']);
        $this->assertTrue((bool) $quote['forced']);
        $this->assertSame([], $this->rateQueries);
    }

    /**
     * Run $callback as if the rates table did not exist: any query to it fails,
     * the way it does on a store that never had the table.
     */
    private function withoutRatesTable(callable $callback)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fct_currency_rates';
        $this->rateQueries = [];
        $missing = function ($query) use ($table, $wpdb) {
            if (strpos($query, $table) === false) {
                return $query;
            }
            $this->rateQueries[] = $query;

            return 'SELECT * FROM ' . $wpdb->prefix . 'fct_no_such_table';
        };

        $suppressed = $wpdb->suppress_errors(true);
        add_filter('query', $missing);
        try {
            return $callback();
        } finally {
            remove_filter('query', $missing);
            $wpdb->suppress_errors($suppressed);
        }
    }

    private function upgradePaths(string $currency, array $snapshot): array
    {
        $from = (new ProductFactory())->withVariantAttributes(['item_price' => 2000])->create();
        $to = (new ProductFactory())->withVariantAttributes(['item_price' => 4980])->create();
        $fromVariantId = (int) $from->variants->first()->id;
        $toVariantId = (int) $to->variants->first()->id;

        (new MetaFactory())->createVariantUpgradePath($fromVariantId, [$toVariantId], true, 5);

        $order = (new OrderFactory())->create([
            'customer_id'  => (new CustomerFactory())->create()->id,
            'currency'     => $currency,
            'subtotal'     => 1000,
            'total_amount' => 1000,
            'config'       => ['fx' => $snapshot],
        ]);
        (new OrderItemFactory())->create($order->id, [
            'post_id'    => $from->ID,
            'object_id'  => $fromVariantId,
            'unit_price' => 1000,
            'subtotal'   => 1000,
            'line_total' => 1000,
        ]);

        $order = Order::query()->find($order->id);

        return PlanUpgradeService::getUpgardePathsFromVariation($fromVariantId, $order->uuid);
    }

    private function unsavedCart(Subscription $subscription, int $itemPrice)
    {
        $cart = CartHelper::generateCartFromCustomVariation([
            'id'           => $subscription->variation_id,
            'post_id'      => $subscription->product_id,
            'object_id'    => $subscription->variation_id,
            'item_price'   => $itemPrice,
            'payment_type' => 'onetime',
            'post_title'   => 'Reactivation',
            'other_info'   => ['payment_type' => 'onetime'],
        ]);
        $cart->cart_group = 'instant';
        $cart->checkout_data = ['is_locked' => 'yes'];

        return $cart;
    }

    /**
     * A subscription before the subscription_currency backfill: its currency
     * and rate come from the parent order.
     */
    private function makeLegacySubscription(string $currency, string $rate): Subscription
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())
            ->withVariantAttributes(['payment_type' => Status::ORDER_TYPE_SUBSCRIPTION])
            ->create();
        $variant = $product->variants->first();
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

        $subscription = (new SubscriptionFactory())->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $variant->id,
        ]);

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_subscriptions', ['currency' => '', 'config' => '[]'], ['id' => $subscription->id]);

        return Subscription::query()->find($subscription->id);
    }

    private function reset(): void
    {
        CurrencyContext::reset();
        CartCurrency::reset();
    }
}
