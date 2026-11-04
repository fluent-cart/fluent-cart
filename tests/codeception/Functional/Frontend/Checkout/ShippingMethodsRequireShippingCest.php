<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Models\Cart;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Shipping\StubShippingCarrier;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * A cart with nothing to ship never asks a carrier for rates, so a guest
 * cannot run live carrier quotes from a digital-only cart.
 */
class ShippingMethodsRequireShippingCest
{
    use PlacesCheckoutOrders;

    private const RATE = 'carrier:stub:ground';

    public function _before(FunctionalTester $I): void
    {
        $I->logout();
        AddressHelper::resetRateCache();
        StubShippingCarrier::install([[
            'id' => self::RATE, 'title' => 'Stub Ground', 'cost' => 1000, 'currency' => 'USD', 'states' => [],
        ]]);
    }

    public function _after(FunctionalTester $I): void
    {
        StubShippingCarrier::uninstall();
        AddressHelper::resetRateCache();
    }

    public function theMethodsListQuotesNoCarrierForADigitalCart(FunctionalTester $I): void
    {
        [$status, $body] = $this->methodsList($I, $this->digitalCart($I));

        Assert::assertSame([], StubShippingCarrier::$calls, 'no carrier quote. Body: ' . wp_json_encode($body));
        Assert::assertArrayNotHasKey('fragments', $body);
        Assert::assertSame(422, $status);
    }

    public function theMethodsListStillQuotesForAPhysicalCart(FunctionalTester $I): void
    {
        $cart = $this->buildInstantCart($I, [[$this->buildPhysicalVariation(5000), 1]]);

        [$status, $body] = $this->methodsList($I, $cart);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty(StubShippingCarrier::$calls);
        Assert::assertStringContainsString(self::RATE, wp_json_encode($body['fragments']));
    }

    public function choosingAShippingMethodQuotesNoCarrierForADigitalCart(FunctionalTester $I): void
    {
        $cart = $this->digitalCart($I);

        [$status, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'save_checkout_data',
            'fct_cart_hash'      => $cart->cart_hash,
            'ship_to_different'  => 'no',
            'billing_country'    => 'US',
            'billing_state'      => 'NY',
            'billing_postcode'   => '10001',
            'billing_city'       => 'New York',
            'data_key'           => 'shipping_method_id',
            'data_value'         => self::RATE,
        ]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame([], StubShippingCarrier::$calls, 'no carrier quote');
    }

    private function digitalCart(FunctionalTester $I): Cart
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 5000])->create()->variants->first();

        return $this->buildInstantCart($I, [[$variation, 1]]);
    }

    private function methodsList(FunctionalTester $I, Cart $cart): array
    {
        return $I->checkoutAjax([
            'fc_checkout_action' => 'get_shipping_methods_list_view',
            'fct_cart_hash'      => $cart->cart_hash,
            'country_code'       => 'US',
            'state'              => 'NY',
        ]);
    }
}
