<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\Framework\Support\Arr;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Shipping\SourcedStubCarrier;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * Progressive checkout rates through the checkout AJAX route: zone rates render
 * at once with the carrier's sources listed as pending, each source is fetched
 * on its own, and a rate that arrived that way prices and places the order.
 */
class ProgressiveCarrierRatesCest
{
    use PlacesCheckoutOrders;

    private const METHODS_FRAGMENT = '[data-fluent-cart-checkout-page-shipping-methods-wrapper]';

    private const UPS = 'carrier:stub_sourced:ups:ground';

    private const FEDEX = 'carrier:stub_sourced:fedex:home';

    public function _before(FunctionalTester $I): void
    {
        $I->logout();
        $this->registerStubCheckoutGateway();
        $this->clearLiveRateTransients();
        AddressHelper::resetRateCache();
        SourcedStubCarrier::install(['ups' => 'UPS', 'fedex' => 'FedEx'], [
            'ups'   => [SourcedStubCarrier::rate(self::UPS, 1500)],
            'fedex' => [SourcedStubCarrier::rate(self::FEDEX, 1200)],
        ]);
    }

    public function _after(FunctionalTester $I): void
    {
        SourcedStubCarrier::uninstall();
        AddressHelper::resetRateCache();
        $this->clearLiveRateTransients();
        $this->removeStubCheckoutGateway();
    }

    public function theListShowsZoneRatesAtOnceAndListsPendingSources(FunctionalTester $I): void
    {
        $zone = $this->buildShippingMethod(['title' => 'Zone Flat']);
        $cart = $this->physicalCart($I);

        $view = $this->methodsView($this->patch($I, $cart, 'billing_state', 'NY'));

        Assert::assertSame([], SourcedStubCarrier::$fetches, 'no carrier is called while the list renders');
        Assert::assertStringContainsString('value="' . $zone->id . '"', $view, 'the zone rate shows at once');
        Assert::assertSame([
            ['carrier' => 'stub_sourced', 'source' => 'ups', 'label' => 'UPS'],
            ['carrier' => 'stub_sourced', 'source' => 'fedex', 'label' => 'FedEx'],
        ], $this->pendingIn($view));
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $view);
        $loader = (new \DOMXPath($dom))->query(
            '//div[contains(@class,"fct_shipping_methods_list")]/*[last()][@role="status"][@aria-live="polite"]'
        );
        Assert::assertSame(1, $loader->length, 'the loader is the last row inside the list');
        Assert::assertSame('Loading shipping methods…', trim($loader->item(0)->textContent));
        Assert::assertSame(
            'fct_shipping_rates_loader fct-collapse is-open',
            $loader->item(0)->getAttribute('class'),
            'it renders open as a collapse, so the script can animate it away'
        );
        Assert::assertTrue($loader->item(0)->hasAttribute('data-fct-rate-sources-status'), 'the script finds it by this hook');
        Assert::assertSame(
            1,
            (new \DOMXPath($dom))->query('./div[@class="fct-collapse__inner"]/div[contains(@class,"fct_shipping_rates_status")]', $loader->item(0))->length,
            'the padded row sits inside __inner, so the closed loader has no height'
        );
    }

    public function aSourceIsFetchedAloneAndAnswersForTheShownList(FunctionalTester $I): void
    {
        $cart = $this->physicalCart($I);
        $view = $this->methodsView($this->patch($I, $cart, 'billing_state', 'NY'));

        [$status, $body] = $this->fetchSource($I, $cart, 'stub_sourced', 'fedex');

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(['fedex'], SourcedStubCarrier::$fetches, 'only the asked source is called');
        Assert::assertSame([self::FEDEX], $body['rate_ids']);
        Assert::assertStringContainsString('value="' . self::FEDEX . '"', $body['view']);
        Assert::assertStringNotContainsString('checked', $body['view'], 'an arriving rate is never selected');
        Assert::assertSame($this->ratesKeyIn($view), $body['rates_key'], 'the answer is for the list on screen');

        [, $again] = $this->fetchSource($I, $cart, 'stub_sourced', 'fedex');
        Assert::assertSame([self::FEDEX], $again['rate_ids']);
        Assert::assertSame(['fedex'], SourcedStubCarrier::$fetches, 'a repeat is answered from the cache');
    }

    public function onlyADeclaredSourceWithTheCheckoutNonceIsFetched(FunctionalTester $I): void
    {
        $cart = $this->physicalCart($I);
        $this->patch($I, $cart, 'billing_state', 'NY');

        [, $body] = $this->fetchSource($I, $cart, 'stub_sourced', 'dhl');
        Assert::assertSame([], $body['rate_ids'] ?? null, 'Body: ' . wp_json_encode($body));

        [$status] = $this->fetchSource($I, $cart, 'stub_sourced', 'ups', false);
        Assert::assertSame(403, $status, 'no nonce, no fetch');

        [$status] = $I->checkoutAjax([
            'fc_checkout_action' => 'get_shipping_rate_source',
            'fct_cart_hash'      => $cart->cart_hash,
            'carrier'            => ['stub_sourced'],
            'source'             => ['ups'],
        ]);
        Assert::assertSame(422, $status, 'array input is refused');

        Assert::assertSame([], SourcedStubCarrier::$fetches);
    }

    public function aRateThatArrivedLaterIsChargedAndAForgedOneIsRefused(FunctionalTester $I): void
    {
        $this->buildShippingMethod(['title' => 'Zone Flat']);
        $cart = $this->physicalCart($I);
        $this->patch($I, $cart, 'billing_state', 'NY');
        $this->fetchSource($I, $cart, 'stub_sourced', 'fedex');

        $this->patch($I, $cart, 'shipping_method_id', self::FEDEX);
        $saved = Cart::query()->find($cart->cart_hash);
        Assert::assertSame(self::FEDEX, (string) Arr::get($saved->checkout_data, 'shipping_data.shipping_method_id'));
        Assert::assertSame(1200, (int) Arr::get($saved->checkout_data, 'shipping_data.shipping_charge'));

        foreach (['carrier:stub_sourced:fedex:overnight', self::UPS] as $forged) {
            AddressHelper::resetRateCache();
            [, $refused] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->orderPayload($cart, $forged));
            Assert::assertSame('failed', (string) ($refused['status'] ?? ''), $forged . ' was never quoted. Body: ' . wp_json_encode($refused));
            Assert::assertSame(['shipping_method' => ['invalid']], array_map('array_keys', (array) ($refused['errors'] ?? [])));
        }

        AddressHelper::resetRateCache();
        SourcedStubCarrier::$fetches = [];
        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->orderPayload($cart, self::FEDEX));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'Body: ' . wp_json_encode($body));
        Assert::assertSame([], SourcedStubCarrier::$fetches, 'Place Order does not call the carrier');
        $order = Order::query()->orderBy('id', 'DESC')->first();
        Assert::assertSame(1200, (int) $order->shipping_total);
        Assert::assertSame(self::FEDEX, (string) ($order->config['shipping_method_id'] ?? ''));
    }

    public function choosingAMethodKeepsTheListKeySoNoSourceIsFetchedAgain(FunctionalTester $I): void
    {
        $five = $this->buildShippingMethod(['title' => 'Zone Five', 'amount' => 5]);
        $ten = $this->buildShippingMethod(['title' => 'Zone Ten', 'amount' => 10], ['region' => 'US', 'name' => 'Second']);
        $cart = $this->physicalCart($I);
        $key = $this->ratesKeyIn($this->methodsView($this->patch($I, $cart, 'billing_state', 'NY')));

        [, $fedex] = $this->fetchSource($I, $cart, 'stub_sourced', 'fedex');
        Assert::assertSame($key, $fedex['rates_key']);

        foreach ([$five->id, $ten->id, self::FEDEX] as $method) {
            $view = $this->methodsView($this->patch($I, $cart, 'shipping_method_id', (string) $method));
            Assert::assertSame($key, $this->ratesKeyIn($view), 'the list key ignores the chosen method (' . $method . ')');
        }

        [, $again] = $this->fetchSource($I, $cart, 'stub_sourced', 'fedex');
        Assert::assertSame($key, $again['rates_key']);
        Assert::assertSame(['fedex'], SourcedStubCarrier::$fetches, 'the carrier is asked once');
    }

    public function theCartsChosenRateIsCheckedWhenItArrives(FunctionalTester $I): void
    {
        $cart = $this->physicalCart($I);
        $this->patch($I, $cart, 'billing_state', 'NY');
        $this->fetchSource($I, $cart, 'stub_sourced', 'fedex');
        $this->patch($I, $cart, 'shipping_method_id', self::FEDEX);

        $this->clearLiveRateTransients();
        [, $fedex] = $this->fetchSource($I, $cart, 'stub_sourced', 'fedex');
        [, $ups] = $this->fetchSource($I, $cart, 'stub_sourced', 'ups');

        Assert::assertMatchesRegularExpression('/checked=\'checked\'[^>]*value="' . preg_quote(self::FEDEX, '/') . '"/', $fedex['view'], 'the chosen rate arrives checked');
        Assert::assertStringNotContainsString('checked', $ups['view'], 'other arrivals stay unchecked');
    }

    private function physicalCart(FunctionalTester $I): Cart
    {
        return $this->buildInstantCart($I, [[$this->buildPhysicalVariation(5000), 1]]);
    }

    private function address(): array
    {
        return [
            'billing_full_name' => 'Happy Buyer',
            'billing_email'     => 'happy-buyer@example.test',
            'billing_address_1' => '20 Checkout Way',
            'billing_country'   => 'US',
            'billing_state'     => 'NY',
            'billing_city'      => 'New York',
            'billing_postcode'  => '10001',
        ];
    }

    private function patch(FunctionalTester $I, Cart $cart, string $key, string $value): array
    {
        AddressHelper::resetRateCache();
        [$status, $body] = $I->checkoutAjax(array_merge([
            'fc_checkout_action' => 'save_checkout_data',
            'fct_cart_hash'      => $cart->cart_hash,
            'ship_to_different'  => 'no',
        ], $this->address(), [
            'data_key'   => $key,
            'data_value' => $value,
        ]));
        Assert::assertSame(200, $status, 'the patch must succeed. Body: ' . wp_json_encode($body));

        return $body;
    }

    private function fetchSource(FunctionalTester $I, Cart $cart, string $carrier, string $source, bool $withNonce = true): array
    {
        AddressHelper::resetRateCache();

        return $I->checkoutAjax([
            'fc_checkout_action' => 'get_shipping_rate_source',
            'fct_cart_hash'      => $cart->cart_hash,
            'carrier'            => $carrier,
            'source'             => $source,
        ], $withNonce);
    }

    private function orderPayload(Cart $cart, string $rateId): array
    {
        return $this->checkoutPayload($cart->cart_hash, [
            'fc_selected_shipping_method' => $rateId,
            'fc_shipping_method'          => $rateId,
        ]);
    }

    private function methodsView(array $body): string
    {
        foreach ((array) ($body['fragments'] ?? []) as $fragment) {
            if (($fragment['selector'] ?? '') === self::METHODS_FRAGMENT) {
                return (string) ($fragment['content'] ?? '');
            }
        }
        Assert::fail('the shipping methods list is re-rendered. Body: ' . wp_json_encode($body));
    }

    private function pendingIn(string $view): array
    {
        Assert::assertMatchesRegularExpression('/data-fct-pending-rate-sources="([^"]*)"/', $view);
        preg_match('/data-fct-pending-rate-sources="([^"]*)"/', $view, $match);

        return json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
    }

    private function ratesKeyIn(string $view): string
    {
        Assert::assertMatchesRegularExpression('/data-fct-rates-key="([a-f0-9]{32})"/', $view);
        preg_match('/data-fct-rates-key="([a-f0-9]{32})"/', $view, $match);

        return $match[1];
    }

    private function clearLiveRateTransients(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%fluent\\_cart\\_live\\_rates\\_%'");
        wp_cache_flush();
    }
}
