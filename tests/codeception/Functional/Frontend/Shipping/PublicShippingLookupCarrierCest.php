<?php

namespace Tests\Functional\Frontend\Shipping;

use FluentCart\Api\Cookie\Cookie;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Shipping\StubShippingCarrier;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * The public lookup GET /checkout/get-available-shipping-methods asks live
 * carriers only for the visitor's cart that ships; zone methods always show.
 */
class PublicShippingLookupCarrierCest
{
    use PlacesCheckoutOrders;

    private const RATE = 'carrier:stub:ground';

    private const ROUTE = '/fluent-cart/v2/checkout/get-available-shipping-methods';

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
        unset($_COOKIE[Cookie::getCartHashKey()]);
        StubShippingCarrier::uninstall();
        AddressHelper::resetRateCache();
    }

    public function withoutACartOnlyZoneMethodsAreListed(FunctionalTester $I): void
    {
        $zone = $this->buildShippingMethod(['title' => 'Zone Flat']);

        $this->assertZoneOnly($I->get(self::ROUTE, ['country_code' => 'US']), $zone->id);
    }

    public function aDigitalCartOnlyListsZoneMethods(FunctionalTester $I): void
    {
        $zone = $this->buildShippingMethod(['title' => 'Zone Flat']);
        $this->useCart((new ProductFactory())->withVariantAttributes(['item_price' => 5000])->create()->variants->first());

        $this->assertZoneOnly($I->get(self::ROUTE, ['country_code' => 'US']), $zone->id);
    }

    public function aPhysicalCartIsQuotedByTheCarrier(FunctionalTester $I): void
    {
        $zone = $this->buildShippingMethod(['title' => 'Zone Flat']);
        $this->useCart($this->buildPhysicalVariation(5000));

        [$status, $body] = $I->get(self::ROUTE, ['country_code' => 'US']);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty(StubShippingCarrier::$calls);
        Assert::assertSame(
            [(string) $zone->id, self::RATE],
            array_map('strval', array_column($body['available_shipping_methods'], 'id'))
        );
    }

    private function useCart(ProductVariation $variation): void
    {
        $cart = (new CartFactory())
            ->withCartItems([CartHelper::generateCartItemFromVariation($variation, 1)])
            ->create(['cart_group' => 'global']);
        $_COOKIE[Cookie::getCartHashKey()] = $cart->cart_hash;
    }

    private function assertZoneOnly(array $response, int $zoneId): void
    {
        [$status, $body] = $response;

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame([], StubShippingCarrier::$calls, 'no carrier quote. Body: ' . wp_json_encode($body));
        Assert::assertSame('US', $body['country_code']);
        Assert::assertSame([(string) $zoneId], array_map('strval', array_column($body['available_shipping_methods'], 'id')));
    }
}
