<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\RateFixture;

/**
 * A cart priced in a currency other than the store's is offered, and may be
 * paid with, only gateways that declare the multi_currency feature.
 * Base USD, GBP 0.79.
 */
class GatewayCurrencyGateTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']]]);
        RateFixture::set('GBP', '0.79');
        $this->reset();
    }

    public function tearDown(): void
    {
        unset($_COOKIE[CurrencyContext::COOKIE], $_REQUEST['fct_cart_hash']);
        $this->reset();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_stripe_paypal_and_cod_declare_multi_currency(): void
    {
        foreach (['stripe', 'paypal', 'offline_payment'] as $slug) {
            $this->assertTrue(GatewayManager::gateway($slug)->has('multi_currency'), $slug);
        }
    }

    public function test_a_gbp_cart_drops_gateways_without_the_feature(): void
    {
        $cart = $this->cartIn('GBP');
        $storeCurrencyOnly = $this->storeCurrencyOnlyGateway();

        $methods = $this->filter([
            GatewayManager::gateway('stripe'),
            GatewayManager::gateway('paypal'),
            GatewayManager::gateway('offline_payment'),
            $storeCurrencyOnly,
        ], $cart);

        $this->assertSame([
            GatewayManager::gateway('stripe'),
            GatewayManager::gateway('paypal'),
            GatewayManager::gateway('offline_payment'),
        ], $methods);
    }

    public function test_a_store_currency_cart_keeps_every_gateway(): void
    {
        $cart = $this->cartIn(null);
        $storeCurrencyOnly = $this->storeCurrencyOnlyGateway();

        $this->assertSame([$storeCurrencyOnly], $this->filter([$storeCurrencyOnly], $cart));
    }

    public function test_the_posted_gateway_is_refused_for_a_gbp_cart(): void
    {
        // PaymentHelper::validateAndGetPayMethod() seeds the filter with just the posted gateway
        $cart = $this->cartIn('GBP');

        $this->assertSame([], $this->filter([$this->storeCurrencyOnlyGateway()], $cart));
    }

    public function test_a_held_gbp_cart_is_gated_after_multi_currency_is_switched_off(): void
    {
        $cart = $this->cartIn('GBP');
        MultiCurrencySettings::save(['enabled' => 'no', 'currencies' => [['code' => 'GBP']]]);
        MultiCurrencySettings::clearCache();
        $this->reset();

        $this->assertSame([], $this->filter([$this->storeCurrencyOnlyGateway()], $cart));
    }

    public function test_a_pinned_gbp_invoice_cart_is_gated_while_multi_currency_is_off(): void
    {
        MultiCurrencySettings::save(['enabled' => 'no', 'currencies' => [['code' => 'GBP']]]);
        MultiCurrencySettings::clearCache();
        $this->reset();

        $cart = $this->cartIn(null);
        CartCurrency::pin($cart, ['currency' => 'GBP', 'rate' => '0.79']);
        $cart->save();
        $cart = Cart::query()->find($cart->cart_hash);

        $this->assertSame([], $this->filter([$this->storeCurrencyOnlyGateway()], $cart));
        $this->assertSame([GatewayManager::gateway('stripe')], $this->filter([GatewayManager::gateway('stripe')], $cart));
    }

    public function test_gateway_slugs_are_gated_like_gateway_objects(): void
    {
        $cart = $this->cartIn('GBP');

        $this->assertSame(['stripe'], $this->filter(['stripe', 'not_a_gateway'], $cart));
    }

    private function filter(array $methods, Cart $cart): array
    {
        return apply_filters('fluent_cart/checkout_active_payment_methods', $methods, ['cart' => $cart]);
    }

    private function storeCurrencyOnlyGateway()
    {
        $gateway = clone GatewayManager::gateway('stripe');
        $gateway->supportedFeatures = ['payment', 'refund'];

        return $gateway;
    }

    private function cartIn(?string $currency): Cart
    {
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => 4980])->create();
        $variation = ProductVariation::query()->find($product->variants->first()->id);

        $cart = new Cart();
        $cart->cart_group = 'instant';
        $cart->cart_data = [CartHelper::generateCartItemFromVariation($variation, 1)];
        $cart->checkout_data = [];
        if ($currency) {
            CartCurrency::force($cart, $currency);
        }
        $cart->save();

        return Cart::query()->find($cart->cart_hash);
    }

    private function reset(): void
    {
        CurrencyContext::reset();
        CartCurrency::reset();
    }
}
