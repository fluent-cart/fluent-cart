<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\MultiCurrency\Services\PriceConverter;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\RateFixture;
use Tests\Support\Shipping\StubShippingCarrier;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * Live carrier rates at checkout. Every carrier's rates go through one list,
 * converted once into the checkout currency, so the rate shown, the rate
 * validated and the rate charged are the same number.
 * Base USD, GBP at 0.79, JPY at 151.37.
 */
class CarrierRatesCheckoutCest
{
    use PlacesCheckoutOrders;

    public function _before(FunctionalTester $I): void
    {
        $I->logout();
        $this->registerStubCheckoutGateway();
        // A GBP checkout only offers gateways that take foreign currencies
        GatewayManager::getInstance($this->stubGatewaySlug)->supportedFeatures[] = 'multi_currency';
        MultiCurrencySettings::save([
            'enabled'           => 'yes',
            'currencies'        => [['code' => 'GBP', 'rounding' => 'none'], ['code' => 'JPY', 'rounding' => 'none']],
            'rate_lock_minutes' => 30,
        ]);
        RateFixture::set('GBP', '0.79');
        RateFixture::set('JPY', '151.37');
        $_COOKIE[CurrencyContext::COOKIE] = 'GBP';
        $this->resetCurrency();
        StubShippingCarrier::install([
            $this->stubRate('stub:ground', 'Stub Ground', 1000, 'USD'),
        ]);
    }

    public function _after(FunctionalTester $I): void
    {
        $this->resetRates();
        StubShippingCarrier::uninstall();
        unset($_COOKIE[CurrencyContext::COOKIE]);
        MultiCurrencySettings::save(['enabled' => 'no', 'currencies' => []]);
        RateFixture::clear();
        $this->resetCurrency();
        $this->removeStubCheckoutGateway();
    }

    public function aBaseCurrencyRateIsShownAndChargedInTheCheckoutCurrency(FunctionalTester $I): void
    {
        $cart = $this->gbpCart($I, 1);
        $lock = Cart::query()->find($cart->cart_hash)->checkout_data['fx'];
        $expected = PriceConverter::toQuote(1000, $lock);
        Assert::assertSame(790, $expected, 'precondition: $10.00 at 0.79');

        [, $listBody] = $I->checkoutAjax([
            'fc_checkout_action' => 'get_shipping_methods_list_view',
            'fct_cart_hash'      => $cart->cart_hash,
            'country_code'       => 'US',
            'state'              => 'NY',
        ]);
        $view = (string) ($listBody['fragments'][0]['content'] ?? '');
        Assert::assertStringContainsString('value="stub:ground"', $view, 'the carrier rate is offered. Body: ' . wp_json_encode($listBody));
        Assert::assertStringContainsString(esc_html(Helper::toDecimal($expected, true, 'GBP')), $view, 'the rate is shown in pounds at the lock');

        $this->resetRates();
        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'fct_shown_currency'          => 'GBP',
            'fc_selected_shipping_method' => 'stub:ground',
            'fc_shipping_method'          => 'stub:ground',
        ]));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'Body: ' . wp_json_encode($body));
        $order = Order::query()->orderBy('id', 'DESC')->first();
        Assert::assertSame('GBP', $order->currency);
        Assert::assertSame($expected, (int) $order->shipping_total, 'the order is charged the shown rate');
        Assert::assertSame('stub:ground', (string) ($order->config['shipping_method_id'] ?? ''), 'the carrier token survives as the method id');
        Assert::assertSame('Stub Ground', (string) ($order->config['shipping_method_title'] ?? ''));
        Assert::assertSame('stub', (string) ($order->config['shipping_carrier'] ?? ''));
        Assert::assertSame([
            'carrier'         => 'stub',
            'rate_id'         => 'stub:ground',
            'source_cost'     => 1000,
            'source_currency' => 'USD',
        ], $order->config['shipping_quote'] ?? null, 'the carrier\'s own quote is kept beside the converted charge');
    }

    public function ratesWithoutAUsableCurrencyAreDropped(FunctionalTester $I): void
    {
        StubShippingCarrier::$rates = [
            $this->stubRate('stub:usd', 'In base', 1000, 'USD'),
            $this->stubRate('stub:gbp', 'In pounds', 555, 'GBP'),
            $this->stubRate('stub:eur', 'No rate', 1000, 'EUR'),
            $this->stubRate('stub:blank', 'No currency', 1000, ''),
        ];

        $rates = $this->ratesById(AddressHelper::getShippingMethods('US', 'NY', null, $this->gbpCart($I, 1)->cart_data));

        Assert::assertSame(['stub:gbp', 'stub:usd'], array_keys($rates), 'a currency with no rate and a rate with no currency are dropped; the rest list cheapest first');
        Assert::assertSame(790, $rates['stub:usd']['cost'], 'a base rate converts at the GBP quote');
        Assert::assertSame('GBP', $rates['stub:usd']['currency']);
        Assert::assertSame(555, $rates['stub:gbp']['cost'], 'a rate already in pounds is not converted');
        Assert::assertSame(1000, $rates['stub:usd']['source_cost']);
        Assert::assertSame('USD', $rates['stub:usd']['source_currency']);

        $cart = $this->gbpCart($I, 1);
        $this->resetRates();
        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'fct_shown_currency'          => 'GBP',
            'fc_selected_shipping_method' => 'stub:eur',
            'fc_shipping_method'          => 'stub:eur',
        ]));
        Assert::assertSame('failed', (string) ($body['status'] ?? ''), 'a dropped rate cannot be bought. Body: ' . wp_json_encode($body));
        Assert::assertSame(['shipping_method' => ['invalid']], array_map('array_keys', (array) ($body['errors'] ?? [])));
    }

    public function aZeroDecimalCurrencyIsQuotedInWholeYen(FunctionalTester $I): void
    {
        $_COOKIE[CurrencyContext::COOKIE] = 'JPY';
        $this->resetCurrency();
        StubShippingCarrier::$rates = [$this->stubRate('stub:ground', 'Stub Ground', 1234, 'USD')];

        $rates = $this->ratesById(AddressHelper::getShippingMethods('US', 'NY', null, $this->buildInstantCart($I, [[$this->buildPhysicalVariation(5000), 1]])->cart_data));

        $expected = PriceConverter::toQuote(1234, CartCurrency::freshQuote('JPY'));
        Assert::assertSame($expected, $rates['stub:ground']['cost']);
        Assert::assertSame(0, $rates['stub:ground']['cost'] % 100, 'stored cents of a whole-yen amount');
        Assert::assertSame('JPY', $rates['stub:ground']['currency']);
    }

    public function manualZoneRatesAreNotConvertedTwice(FunctionalTester $I): void
    {
        $method = $this->buildShippingMethod(['title' => 'Zone Ground', 'amount' => 5]);
        $cart = $this->gbpCart($I, 1);

        $rates = $this->ratesById(AddressHelper::getShippingMethods('US', 'NY', null, $cart->cart_data));

        Assert::assertArrayHasKey((string) $method->id, $rates, 'the zone method is in the merged list');
        Assert::assertSame(395, $rates[(string) $method->id]['cost'], '$5.00 at 0.79, once');
        Assert::assertSame('GBP', $rates[(string) $method->id]['currency']);
        Assert::assertSame('manual', $rates[(string) $method->id]['carrier']);
        Assert::assertSame('Zone Ground', $rates[(string) $method->id]['title']);
        Assert::assertSame('flat_rate', $rates[(string) $method->id]['type'], 'the zone method\'s own fields stay on the rate');
    }

    public function carrierRatesAreListedCheapestFirstAfterTheZoneMethods(FunctionalTester $I): void
    {
        $express = $this->buildShippingMethod(['title' => 'Zone Express', 'amount' => 20]);
        $ground = $this->buildShippingMethod(['title' => 'Zone Ground', 'amount' => 5]);
        $cart = $this->gbpCart($I, 1);

        StubShippingCarrier::$enabled = false;
        $this->resetRates();
        $zoneOrder = array_keys($this->ratesById(AddressHelper::getShippingMethods('US', 'NY', null, $cart->cart_data)));
        Assert::assertEqualsCanonicalizing([(string) $express->id, (string) $ground->id], $zoneOrder, 'precondition: only the zone methods');

        StubShippingCarrier::$enabled = true;
        StubShippingCarrier::$rates = [
            $this->stubRate('stub:overnight', 'Overnight', 3000, 'USD'),
            $this->stubRate('stub:economy', 'Economy', 1000, 'USD'),
            $this->stubRate('stub:priority', 'Priority', 2000, 'USD'),
            $this->stubRate('stub:saver', 'Saver', 1000, 'USD'),
        ];
        $this->resetRates();

        $ids = array_keys($this->ratesById(AddressHelper::getShippingMethods('US', 'NY', null, $cart->cart_data)));

        Assert::assertSame(
            array_merge($zoneOrder, ['stub:economy', 'stub:saver', 'stub:priority', 'stub:overnight']),
            $ids,
            'zone methods keep the merchant\'s order; carrier rates follow, cheapest first, ties in quoted order'
        );
    }

    public function theCarrierQuotesTheRealCartOnEveryCheckoutPath(FunctionalTester $I): void
    {
        $cart = $this->gbpCart($I, 3);
        $variationId = (int) $cart->cart_data[0]['object_id'];

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'fct_shown_currency'          => 'GBP',
            'fc_selected_shipping_method' => 'stub:ground',
            'fc_shipping_method'          => 'stub:ground',
        ]));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty(StubShippingCarrier::$calls, 'the carrier was asked for rates');
        foreach (StubShippingCarrier::$calls as $args) {
            $lines = array_map(function ($item) {
                return [(int) ($item['object_id'] ?? 0), (int) ($item['quantity'] ?? 0)];
            }, (array) ($args['items'] ?? []));
            Assert::assertSame([[$variationId, 3]], $lines, 'every quote prices the real 3-unit cart, never an empty parcel');
            Assert::assertSame('US', $args['country']);
            Assert::assertSame('NY', $args['state']);
            Assert::assertSame('10001', $args['postcode'] ?? null, 'carriers get the postcode');
            Assert::assertSame('New York', $args['city'] ?? null, 'carriers get the city');
        }
    }

    public function placeOrderAsksLiveCarriersToReuseTheirQuote(FunctionalTester $I): void
    {
        $cart = $this->gbpCart($I, 1);

        $this->resetRates();
        $I->checkoutAjax([
            'fc_checkout_action' => 'get_shipping_methods_list_view',
            'fct_cart_hash'      => $cart->cart_hash,
            'country_code'       => 'US',
            'state'              => 'NY',
        ]);
        Assert::assertNotEmpty(StubShippingCarrier::$calls, 'the list view asks the carrier');
        foreach (StubShippingCarrier::$calls as $args) {
            Assert::assertArrayNotHasKey('reuse_quote', $args, 'showing rates asks for a fresh quote');
        }

        $this->resetRates();
        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'fct_shown_currency'          => 'GBP',
            'fc_selected_shipping_method' => 'stub:ground',
            'fc_shipping_method'          => 'stub:ground',
        ]));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty(StubShippingCarrier::$calls, 'place-order resolves the rate through the carrier');
        foreach (StubShippingCarrier::$calls as $args) {
            Assert::assertTrue($args['reuse_quote'] ?? null, 'place-order lets the carrier answer from its last quote');
        }
    }

    public function onlyAnExactCarrierTokenIsAccepted(FunctionalTester $I): void
    {
        $zone = $this->buildShippingMethod(['title' => 'Zone Ground', 'amount' => 5]);

        $shape = function (array $fields) use ($I) {
            $cart = $this->gbpCart($I, 1);
            $before = Order::query()->count();
            $this->resetRates();
            [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, ['fct_shown_currency' => 'GBP'] + $fields));
            $order = Order::query()->count() > $before ? Order::query()->orderBy('id', 'DESC')->first() : null;

            return [
                'status' => (string) ($body['status'] ?? ''),
                'method' => $order ? (string) ($order->config['shipping_method_title'] ?? '') : '',
                'errors' => array_map('array_keys', (array) ($body['errors'] ?? [])),
            ];
        };

        Assert::assertSame(
            [
                'exact'    => ['status' => 'success', 'method' => 'Stub Ground', 'errors' => []],
                'mismatch' => ['status' => 'success', 'method' => 'Zone Ground', 'errors' => []],
                'case'     => ['status' => 'failed', 'method' => '', 'errors' => ['shipping_method' => ['invalid']]],
                'padded'   => ['status' => 'failed', 'method' => '', 'errors' => ['shipping_method' => ['invalid']]],
                'markup'   => ['status' => 'failed', 'method' => '', 'errors' => ['shipping_method' => ['invalid']]],
            ],
            [
                'exact'    => $shape(['fc_selected_shipping_method' => 'stub:ground', 'fc_shipping_method' => 'stub:ground']),
                // The validated mirror prices the order, whatever the radio says
                'mismatch' => $shape(['fc_selected_shipping_method' => (int) $zone->id, 'fc_shipping_method' => 'stub:ground']),
                'case'     => $shape(['fc_selected_shipping_method' => 'STUB:GROUND']),
                'padded'   => $shape(['fc_selected_shipping_method' => 'stub:ground ']),
                'markup'   => $shape(['fc_selected_shipping_method' => 'stub:<b>ground']),
            ]
        );
    }

    private function gbpCart(FunctionalTester $I, int $quantity): Cart
    {
        $variation = $this->buildPhysicalVariation(5000);
        $cart = $this->buildInstantCart($I, [[$variation, $quantity]]);
        $cart = Cart::query()->find($cart->cart_hash);
        Assert::assertSame('GBP', $cart->checkout_data['fx']['currency'], 'precondition: a GBP cart');

        return $cart;
    }

    private function stubRate(string $id, string $title, int $cost, string $currency): array
    {
        return [
            'id'             => $id,
            'carrier'        => StubShippingCarrier::SLUG,
            'title'          => $title,
            'cost'           => $cost,
            'currency'       => $currency,
            'states'         => [],
            'description'    => '',
            'estimated_days' => 2,
        ];
    }

    private function ratesById($rates): array
    {
        Assert::assertIsArray($rates, 'rates resolve for the address: ' . (is_wp_error($rates) ? $rates->get_error_message() : ''));
        $byId = [];
        foreach ($rates as $rate) {
            $byId[(string) $rate['id']] = $rate;
        }

        return $byId;
    }

    private function resetRates(): void
    {
        StubShippingCarrier::$calls = [];
        if (method_exists(AddressHelper::class, 'resetRateCache')) {
            AddressHelper::resetRateCache();
        }
    }

    private function resetCurrency(): void
    {
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        CurrencyContext::reset();
        CartCurrency::reset();
    }
}
