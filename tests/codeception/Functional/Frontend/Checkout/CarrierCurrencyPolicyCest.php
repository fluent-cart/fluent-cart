<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\MultiCurrency\Services\PriceConverter;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\RateFixture;
use Tests\Support\Shipping\StubShippingCarrier;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * A carrier that declares canQuoteInCurrency() is shown only in a currency it
 * quotes in. Converting its base-currency rate is opt-in through
 * fluent_cart/shipping/carrier_rates_allow_conversion; a carrier that cannot
 * quote the base currency either is never shown.
 * Base USD, GBP at 0.79.
 */
class CarrierCurrencyPolicyCest
{
    use PlacesCheckoutOrders;

    const FILTER = 'fluent_cart/shipping/carrier_rates_allow_conversion';

    /** @var array[] context of every filter call */
    private array $filterCalls = [];

    public function _before(FunctionalTester $I): void
    {
        $I->logout();
        $this->registerStubCheckoutGateway();
        GatewayManager::getInstance($this->stubGatewaySlug)->supportedFeatures[] = 'multi_currency';
        MultiCurrencySettings::save([
            'enabled'           => 'yes',
            'currencies'        => [['code' => 'GBP', 'rounding' => 'none']],
            'rate_lock_minutes' => 30,
        ]);
        RateFixture::set('GBP', '0.79');
        $_COOKIE[CurrencyContext::COOKIE] = 'GBP';
        $this->resetCurrency();
        StubShippingCarrier::install();
        $this->filterCalls = [];
    }

    public function _after(FunctionalTester $I): void
    {
        remove_all_filters(self::FILTER);
        StubShippingCarrier::uninstall();
        unset($_COOKIE[CurrencyContext::COOKIE]);
        MultiCurrencySettings::save(['enabled' => 'no', 'currencies' => []]);
        RateFixture::clear();
        $this->resetCurrency();
        $this->removeStubCheckoutGateway();
    }

    public function aSupportedCheckoutCurrencyShowsTheCarriersOwnAmount(FunctionalTester $I): void
    {
        StubShippingCarrier::$quotableCurrencies = ['USD', 'GBP'];
        StubShippingCarrier::$ratesByCurrency = [
            'GBP' => [$this->stubRate('stub:ground', 612, 'GBP')],
            'USD' => [$this->stubRate('stub:ground', 1000, 'USD')],
        ];

        $rates = $this->stubRates($I);

        Assert::assertSame(['stub:ground'], array_keys($rates));
        Assert::assertSame(612, $rates['stub:ground']['cost'], 'the carrier\'s pound quote, not $10.00 at 0.79');
        Assert::assertSame('GBP', $rates['stub:ground']['currency']);
        Assert::assertSame('GBP', StubShippingCarrier::$calls[0]['currency'] ?? null, 'the carrier is asked in the checkout currency');
        Assert::assertSame('USD', StubShippingCarrier::$calls[0]['base_currency'] ?? null);
    }

    public function anUnsupportedCheckoutCurrencyHidesTheCarrier(FunctionalTester $I): void
    {
        StubShippingCarrier::$quotableCurrencies = ['USD'];
        StubShippingCarrier::$rates = [$this->stubRate('stub:ground', 1000, 'USD')];
        $this->recordFilter();

        Assert::assertSame([], $this->stubRates($I), 'no converted rate by default');
        Assert::assertSame([], StubShippingCarrier::$calls, 'a carrier with nothing to show is not called');
        Assert::assertSame([[
            'carrier'           => 'stub',
            'checkout_currency' => 'GBP',
            'base_currency'     => 'USD',
        ]], $this->filterCalls, 'the filter is asked once, with its context');
    }

    public function aCarrierThatAnswersInBaseInsteadIsHiddenByDefault(FunctionalTester $I): void
    {
        StubShippingCarrier::$quotableCurrencies = ['USD', 'GBP'];
        StubShippingCarrier::$rates = [
            $this->stubRate('stub:ground', 1000, 'USD'),
            $this->stubRate('stub:euro', 1000, 'EUR'),
        ];

        Assert::assertSame([], $this->stubRates($I), 'a base rate is not the pound quote that was asked for');
    }

    public function theFilterOptsInToTheConvertedBaseRate(FunctionalTester $I): void
    {
        StubShippingCarrier::$quotableCurrencies = ['USD'];
        StubShippingCarrier::$rates = [
            $this->stubRate('stub:ground', 1234, 'USD'),
            $this->stubRate('stub:euro', 1000, 'EUR'),
        ];
        add_filter(self::FILTER, '__return_true');

        $cart = $this->gbpCart($I);
        $lock = $cart->checkout_data['fx'];
        $expected = PriceConverter::toQuote(1234, $lock);
        Assert::assertSame(975, $expected, 'precondition: $12.34 at 0.79 = £9.75');

        $rates = $this->stubRates($I, $cart);
        Assert::assertSame(['stub:ground'], array_keys($rates), 'only the base rate converts; a third currency never does');
        Assert::assertSame($expected, $rates['stub:ground']['cost']);
        Assert::assertSame('GBP', $rates['stub:ground']['currency']);
        Assert::assertSame(1234, $rates['stub:ground']['source_cost']);
        Assert::assertSame('USD', $rates['stub:ground']['source_currency']);
        Assert::assertSame('USD', StubShippingCarrier::$calls[0]['currency'] ?? null, 'asked in the base currency it can quote');

        $this->resetRates();
        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'fct_shown_currency'          => 'GBP',
            'fc_selected_shipping_method' => 'stub:ground',
            'fc_shipping_method'          => 'stub:ground',
        ]));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'Body: ' . wp_json_encode($body));
        Assert::assertSame($expected, (int) Order::query()->orderBy('id', 'DESC')->first()->shipping_total, 'charged the converted amount shown');
    }

    public function aCarrierThatCannotQuoteTheBaseCurrencyIsNeverShown(FunctionalTester $I): void
    {
        StubShippingCarrier::$quotableCurrencies = ['EUR'];
        StubShippingCarrier::$rates = [$this->stubRate('stub:ground', 1000, 'EUR')];
        add_filter(self::FILTER, '__return_true');

        Assert::assertSame([], $this->stubRates($I), 'the filter cannot rescue a carrier with no base quote');
        Assert::assertSame([], StubShippingCarrier::$calls);
    }

    public function aSingleCurrencyStoreShowsASupportedCarrierAsBefore(FunctionalTester $I): void
    {
        $this->singleCurrencyStore();
        StubShippingCarrier::$quotableCurrencies = ['USD'];
        StubShippingCarrier::$rates = [$this->stubRate('stub:ground', 1000, 'USD')];

        $rates = $this->stubRates($I, $this->usdCart($I));

        Assert::assertSame(1000, $rates['stub:ground']['cost'] ?? null);
        Assert::assertSame('USD', $rates['stub:ground']['currency']);
        Assert::assertSame('USD', StubShippingCarrier::$calls[0]['currency'] ?? null);
    }

    public function aSingleCurrencyStoreHidesACarrierThatCannotQuoteIt(FunctionalTester $I): void
    {
        $this->singleCurrencyStore();
        StubShippingCarrier::$quotableCurrencies = ['EUR'];
        StubShippingCarrier::$rates = [$this->stubRate('stub:ground', 1000, 'EUR')];
        RateFixture::set('EUR', '0.92');
        add_filter(self::FILTER, '__return_true');

        Assert::assertSame([], $this->stubRates($I, $this->usdCart($I)), 'a USD store never shows a carrier that cannot quote USD');
        Assert::assertSame([], StubShippingCarrier::$calls);
    }

    public function manualZoneRatesAreUnaffected(FunctionalTester $I): void
    {
        StubShippingCarrier::$quotableCurrencies = ['USD'];
        StubShippingCarrier::$rates = [$this->stubRate('stub:ground', 1000, 'USD')];
        $method = $this->buildShippingMethod(['title' => 'Zone Ground', 'amount' => 5]);

        $rates = $this->ratesById(AddressHelper::getShippingMethods('US', 'NY', null, $this->gbpCart($I)->cart_data));

        Assert::assertSame([(string) $method->id], array_map('strval', array_keys($rates)), 'the zone method stays; the hidden carrier does not');
        Assert::assertSame(395, $rates[(string) $method->id]['cost'], '$5.00 at 0.79');
        Assert::assertSame('GBP', $rates[(string) $method->id]['currency']);
    }

    private function recordFilter(): void
    {
        add_filter(self::FILTER, function ($allow, $context) {
            $this->filterCalls[] = $context;

            return $allow;
        }, 10, 2);
    }

    private function singleCurrencyStore(): void
    {
        MultiCurrencySettings::save(['enabled' => 'no', 'currencies' => []]);
        unset($_COOKIE[CurrencyContext::COOKIE]);
        $this->resetCurrency();
    }

    private function stubRates(FunctionalTester $I, ?Cart $cart = null): array
    {
        $cart = $cart ?: $this->gbpCart($I);
        $this->resetRates();
        $rates = AddressHelper::getShippingMethods('US', 'NY', null, $cart->cart_data);
        if (is_wp_error($rates)) {
            return [];
        }

        return array_filter($this->ratesById($rates), function ($rate) {
            return ($rate['carrier'] ?? '') === StubShippingCarrier::SLUG;
        });
    }

    private function gbpCart(FunctionalTester $I): Cart
    {
        $cart = Cart::query()->find($this->buildInstantCart($I, [[$this->buildPhysicalVariation(5000), 1]])->cart_hash);
        Assert::assertSame('GBP', $cart->checkout_data['fx']['currency'], 'precondition: a GBP cart');

        return $cart;
    }

    private function usdCart(FunctionalTester $I): Cart
    {
        $cart = Cart::query()->find($this->buildInstantCart($I, [[$this->buildPhysicalVariation(5000), 1]])->cart_hash);
        Assert::assertSame('USD', CartCurrency::currentQuote()['currency'] ?? null, 'precondition: a USD checkout');

        return $cart;
    }

    private function stubRate(string $id, int $cost, string $currency): array
    {
        return [
            'id'             => $id,
            'carrier'        => StubShippingCarrier::SLUG,
            'title'          => 'Stub ' . $id,
            'cost'           => $cost,
            'currency'       => $currency,
            'states'         => [],
            'description'    => '',
            'estimated_days' => 2,
        ];
    }

    private function ratesById($rates): array
    {
        Assert::assertIsArray($rates, 'rates resolve: ' . (is_wp_error($rates) ? $rates->get_error_message() : ''));
        $byId = [];
        foreach ($rates as $rate) {
            $byId[(string) $rate['id']] = $rate;
        }

        return $byId;
    }

    private function resetRates(): void
    {
        StubShippingCarrier::$calls = [];
        AddressHelper::resetRateCache();
    }

    private function resetCurrency(): void
    {
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        CurrencyContext::reset();
        CartCurrency::reset();
    }
}
