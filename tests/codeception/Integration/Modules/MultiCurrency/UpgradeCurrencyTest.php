<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\MultiCurrency\Services\PriceConverter;
use FluentCart\App\Services\PlanUpgradeService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\MetaFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;
use Tests\Support\RateFixture;

/**
 * A plan upgrade is a new order and a new subscription, so it is priced in
 * the shopper's chosen currency — else the subscription's — at today's rate
 * (dev-docs/multi-currency/plan.md §15.6). Base USD, GBP 0.79, EUR 0.92.
 */
class UpgradeCurrencyTest extends DatabaseTestCase
{
    use MakesMultiCurrencyAvailable;

    /** @var array<int, bool> upgrade paths created, by from-variation */
    private $paths = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->makeMultiCurrencyAvailable();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP'], ['code' => 'EUR']]]);
        RateFixture::set('GBP', '0.79');
        RateFixture::set('EUR', '0.92');
        $this->choose(null);
    }

    public function tearDown(): void
    {
        $this->choose(null);
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_without_a_choice_the_upgrade_stays_in_the_subscriptions_currency(): void
    {
        $path = $this->upgradeFrom($this->order('GBP', '0.79'));

        $this->assertSame('GBP', $path['currency']);
        $this->assertSame(7900.0, $path['price'], '$100 at 0.79');
    }

    public function test_a_shopper_who_chose_another_currency_upgrades_in_it(): void
    {
        $order = $this->order('GBP', '0.79');

        $this->choose('EUR');
        $path = $this->upgradeFrom($order);
        $this->assertSame('EUR', $path['currency']);
        $this->assertSame(9200.0, $path['price'], '$100 at today\'s EUR rate');

        $this->choose('USD');
        $path = $this->upgradeFrom($order);
        $this->assertSame('USD', $path['currency'], 'the base currency can be chosen too');
        $this->assertSame(10000.0, $path['price']);
    }

    public function test_an_order_with_an_unknown_rate_stays_in_its_own_currency(): void
    {
        $order = $this->order('GBP', '1', CurrencySnapshot::legacy('GBP'));

        $this->choose('EUR');
        $this->assertSame('GBP', CartCurrency::upgradeQuote($order)['currency'], 'its credit could not be converted');
    }

    public function test_an_order_whose_currency_cannot_be_quoted_has_no_upgrade_quote(): void
    {
        // A pre-snapshot CHF order: no CHF rate today and none it can trust
        $order = $this->order('CHF', '1', CurrencySnapshot::legacy('CHF'));

        $this->assertNull(CartCurrency::upgradeQuote($order), 'no base-currency fallback for a CHF credit');

        $this->choose('EUR');
        $this->assertNull(CartCurrency::upgradeQuote($order), 'a chosen currency cannot convert a credit of unknown value');
    }

    public function test_a_chosen_currency_never_prices_an_upgrade_the_order_cannot_quote(): void
    {
        $order = $this->order('CHF', '1', CurrencySnapshot::legacy('CHF'));

        $this->choose('EUR');
        $path = $this->upgradeFrom($order);

        $this->assertFalse($path['is_available']);
        $this->assertSame('CHF', $path['currency']);
        $this->assertNull($path['price']);
        $this->assertNull($path['prorate_credit']);
        $this->assertSame('', $path['upgrade_url']);
    }

    public function test_a_credit_moves_between_currencies_through_the_paid_rate(): void
    {
        $paid = CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL]);

        // £20.00 paid at 0.79 is $25.32 of base value; at EUR 0.92 that is €23.29
        $this->assertSame(2329, PriceConverter::between(2000, $paid, CartCurrency::quoteFor('EUR')));
        $this->assertSame(2532, PriceConverter::between(2000, $paid, PriceConverter::baseQuote()));
        $this->assertSame(2000, PriceConverter::between(2000, $paid, CartCurrency::quoteFor('GBP')), 'same currency: unchanged');
    }

    public function test_a_credit_at_an_untrusted_rate_is_never_converted_one_to_one(): void
    {
        // A pre-snapshot GBP order: rate 1 is the store currency label, not a rate
        $legacy = CurrencySnapshot::legacy('GBP');
        $this->assertSame(CurrencySnapshot::SOURCE_LEGACY_UNKNOWN, $legacy['source']);

        $this->assertNull(PriceConverter::between(2000, $legacy, CartCurrency::quoteFor('EUR')), 'not €20.00 from £20.00 at 1:1');
        $this->assertNull(PriceConverter::between(2000, $legacy, PriceConverter::baseQuote()), 'not $20.00 from £20.00 at 1:1');
        $this->assertNull(PriceConverter::between(2000, CurrencySnapshot::make('GBP', '1'), CartCurrency::quoteFor('EUR')), 'unknown source');
        $this->assertNull(
            PriceConverter::between(2000, ['currency' => 'GBP', 'rate' => '0', 'source' => CurrencySnapshot::SOURCE_MANUAL], CartCurrency::quoteFor('EUR')),
            'a foreign amount without a usable rate is not base money'
        );

        $this->assertSame(2000, PriceConverter::between(2000, $legacy, CartCurrency::quoteFor('GBP')), 'kept in its own currency it needs no rate');
    }

    public function test_a_credit_in_a_zero_decimal_currency_is_whole_units(): void
    {
        $this->enableYen();
        $yen = CartCurrency::quoteFor('JPY');

        // £20.00 at 0.79 = $25.3165 = ¥3,784.81 at 149.5 -> ¥3,785
        $paid = CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL]);
        $this->assertSame(378500, PriceConverter::between(2000, $paid, $yen));

        // A prorated yen credit (¥5,000 x 17/30 = ¥2,833.33) stays in yen, whole
        $paidInYen = CurrencySnapshot::make('JPY', '149.5', ['source' => CurrencySnapshot::SOURCE_MANUAL]);
        $this->assertSame(283300, PriceConverter::between(283333, $paidInYen, $yen));
    }

    private function upgradeFrom(Order $order): array
    {
        $from = $this->variationOf($order);
        if (!isset($this->paths[$from])) {
            $target = ProductVariation::query()->find((new ProductFactory())->withVariantAttributes(['item_price' => 10000])->create()->variants->first()->id);
            (new MetaFactory())->createVariantUpgradePath($from, [$target->id]);
            $this->paths[$from] = true;
        }
        CurrencyContext::reset();
        CartCurrency::reset();

        $paths = PlanUpgradeService::getUpgardePathsFromVariation($from, $order->uuid);
        $this->assertCount(1, $paths);

        return $paths[0];
    }

    private function order(string $currency, string $rate, ?array $snapshot = null): Order
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'currency'    => $currency,
            'rate'        => $rate,
            'config'      => ['fx' => $snapshot ?: CurrencySnapshot::make($currency, $rate, ['source' => CurrencySnapshot::SOURCE_MANUAL])],
        ]);
        $variation = ProductVariation::query()->find((new ProductFactory())->withVariantAttributes(['item_price' => 5000])->create()->variants->first()->id);
        (new OrderItemFactory())->create($order->id, ['post_id' => $variation->post_id, 'object_id' => $variation->id, 'unit_price' => 3950]);

        return Order::query()->find($order->id);
    }

    private function variationOf(Order $order): int
    {
        return (int) $order->order_items()->first()->object_id;
    }

    private function enableYen(): void
    {
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP'], ['code' => 'EUR'], ['code' => 'JPY']]]);
        MultiCurrencySettings::clearCache();
        RateFixture::set('JPY', '149.5');
        ExchangeRateService::flushCache();
        $this->choose(null);
    }

    private function choose(?string $currency): void
    {
        if ($currency) {
            $_COOKIE[CurrencyContext::COOKIE] = $currency;
        } else {
            unset($_COOKIE[CurrencyContext::COOKIE]);
        }
        CurrencyContext::reset();
        CartCurrency::reset();
    }
}
