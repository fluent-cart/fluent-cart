<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Cart;
use FluentCart\Framework\Support\Arr;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Shipping\StubShippingCarrier;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * Live carriers price by postcode and city, so a checkout patch that changes
 * either on the shipping destination must re-quote, like a country or state
 * change does. The destination is billing_* unless "ship to a different
 * address" is on.
 */
class CarrierRatesAddressRecalcCest
{
    use PlacesCheckoutOrders;

    private const METHODS_FRAGMENT = '[data-fluent-cart-checkout-page-shipping-methods-wrapper]';

    public function _before(FunctionalTester $I): void
    {
        $I->logout();
        StubShippingCarrier::install([$this->stubRate('stub:ground', 'Stub Ground', 1000)]);
    }

    public function _after(FunctionalTester $I): void
    {
        StubShippingCarrier::uninstall();
    }

    public function aBillingPostcodeOrCityChangeRequotesTheCarrier(FunctionalTester $I): void
    {
        $cart = $this->physicalCart($I);
        $address = $this->billing('10001', 'New York');

        $this->patch($I, $cart, $address, 'billing_state', 'NY');
        Assert::assertSame(['stub:ground', 1000], $this->shippingOn($cart), 'precondition: the state patch quotes the carrier');

        $this->quoteNext([$this->stubRate('stub:ground', 'Stub Ground', 2500)]);
        $address['billing_postcode'] = '90210';
        $body = $this->patch($I, $cart, $address, 'billing_postcode', '90210');

        Assert::assertSame('90210', $this->lastQuoted('postcode'), 'the carrier is asked for the new postcode');
        Assert::assertSame(['stub:ground', 2500], $this->shippingOn($cart), 'the cart carries the rate for the new postcode');
        Assert::assertStringContainsString(esc_html(Helper::toDecimal(2500, true, 'USD')), $this->methodsView($body), 'the re-rendered methods list shows the new rate');

        $this->quoteNext([$this->stubRate('stub:ground', 'Stub Ground', 3000)]);
        $address['billing_city'] = 'Beverly Hills';
        $this->patch($I, $cart, $address, 'billing_city', 'Beverly Hills');

        Assert::assertSame('Beverly Hills', $this->lastQuoted('city'), 'the carrier is asked for the new city');
        Assert::assertSame(['stub:ground', 3000], $this->shippingOn($cart));
    }

    public function aShippingPostcodeChangeRequotesWhenShippingToADifferentAddress(FunctionalTester $I): void
    {
        $cart = $this->physicalCart($I);
        $address = $this->billing('10001', 'New York') + $this->shipping('02108', 'Boston', 'MA') + ['ship_to_different' => 'yes'];

        $this->patch($I, $cart, $address, 'shipping_state', 'MA');
        Assert::assertSame('02108', $this->lastQuoted('postcode'), 'precondition: the shipping address is the destination');

        $this->quoteNext([$this->stubRate('stub:ground', 'Stub Ground', 1800)]);
        $address['shipping_postcode'] = '02139';
        $body = $this->patch($I, $cart, $address, 'shipping_postcode', '02139');

        Assert::assertSame('02139', $this->lastQuoted('postcode'));
        Assert::assertSame(['stub:ground', 1800], $this->shippingOn($cart));
        Assert::assertStringContainsString(esc_html(Helper::toDecimal(1800, true, 'USD')), $this->methodsView($body));
    }

    public function shippingToADifferentAddressRequotesForThatAddress(FunctionalTester $I): void
    {
        $cart = $this->physicalCart($I);
        $address = $this->billing('10001', 'New York') + $this->shipping('10001', 'New York', 'NY') + ['ship_to_different' => 'no'];

        $this->patch($I, $cart, $address, 'billing_state', 'NY');
        Assert::assertSame(['stub:ground', 1000], $this->shippingOn($cart), 'precondition');

        // Same country and state; only the postcode and city differ.
        $this->quoteNext([$this->stubRate('stub:ground', 'Stub Ground', 2100)]);
        $address = array_merge($address, $this->shipping('11201', 'Brooklyn', 'NY'), ['ship_to_different' => 'yes']);
        $this->patch($I, $cart, $address, 'ship_to_different', 'yes');

        Assert::assertSame('11201', $this->lastQuoted('postcode'), 'the carrier is asked for the shipping address');
        Assert::assertSame(['stub:ground', 2100], $this->shippingOn($cart));
    }

    public function theChosenRateIsKeptWhileTheNewAddressStillOffersIt(FunctionalTester $I): void
    {
        StubShippingCarrier::$rates = [
            $this->stubRate('stub:ground', 'Stub Ground', 1000),
            $this->stubRate('stub:express', 'Stub Express', 2000),
        ];
        $cart = $this->physicalCart($I);
        $address = $this->billing('10001', 'New York');

        $this->patch($I, $cart, $address, 'billing_state', 'NY');
        $this->patch($I, $cart, $address, 'shipping_method_id', 'stub:express');
        Assert::assertSame(['stub:express', 2000], $this->shippingOn($cart), 'precondition: express chosen');

        $this->quoteNext([
            $this->stubRate('stub:ground', 'Stub Ground', 1100),
            $this->stubRate('stub:express', 'Stub Express', 2200),
        ]);
        $address['billing_postcode'] = '90210';
        $this->patch($I, $cart, $address, 'billing_postcode', '90210');
        Assert::assertSame(['stub:express', 2200], $this->shippingOn($cart), 'express is still offered, so it stays chosen at the new price');

        $this->quoteNext([$this->stubRate('stub:ground', 'Stub Ground', 1200)]);
        $address['billing_postcode'] = '99501';
        $this->patch($I, $cart, $address, 'billing_postcode', '99501');
        Assert::assertSame(['stub:ground', 1200], $this->shippingOn($cart), 'express is gone, so the first offered rate is used');
    }

    private function patch(FunctionalTester $I, Cart $cart, array $fields, string $key, string $value): array
    {
        [$status, $body] = $I->checkoutAjax(array_merge([
            'fc_checkout_action' => 'save_checkout_data',
            'fct_cart_hash'      => $cart->cart_hash,
            'ship_to_different'  => 'no',
        ], $fields, [
            'data_key'   => $key,
            'data_value' => $value,
        ]));
        Assert::assertSame(200, $status, 'the patch must succeed. Body: ' . wp_json_encode($body));

        return $body;
    }

    private function billing(string $postcode, string $city): array
    {
        return [
            'billing_full_name' => 'Happy Buyer',
            'billing_email'     => 'happy-buyer@example.test',
            'billing_address_1' => '20 Checkout Way',
            'billing_country'   => 'US',
            'billing_state'     => 'NY',
            'billing_city'      => $city,
            'billing_postcode'  => $postcode,
        ];
    }

    private function shipping(string $postcode, string $city, string $state): array
    {
        return [
            'shipping_full_name' => 'Happy Buyer',
            'shipping_address_1' => '1 Other Street',
            'shipping_country'   => 'US',
            'shipping_state'     => $state,
            'shipping_city'      => $city,
            'shipping_postcode'  => $postcode,
        ];
    }

    private function physicalCart(FunctionalTester $I): Cart
    {
        return $this->buildInstantCart($I, [[$this->buildPhysicalVariation(5000), 1]]);
    }

    private function quoteNext(array $rates): void
    {
        StubShippingCarrier::$rates = $rates;
        StubShippingCarrier::$calls = [];
    }

    private function lastQuoted(string $field): ?string
    {
        Assert::assertNotEmpty(StubShippingCarrier::$calls, 'the carrier was asked for rates');
        $last = end(StubShippingCarrier::$calls);

        return isset($last[$field]) ? (string) $last[$field] : null;
    }

    private function shippingOn(Cart $cart): array
    {
        $saved = Cart::query()->find($cart->cart_hash);

        return [
            (string) Arr::get($saved->checkout_data, 'shipping_data.shipping_method_id', ''),
            (int) Arr::get($saved->checkout_data, 'shipping_data.shipping_charge', 0),
        ];
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

    private function stubRate(string $id, string $title, int $cost): array
    {
        return [
            'id'             => $id,
            'carrier'        => StubShippingCarrier::SLUG,
            'title'          => $title,
            'cost'           => $cost,
            'currency'       => 'USD',
            'states'         => [],
            'description'    => '',
            'estimated_days' => 2,
        ];
    }
}
