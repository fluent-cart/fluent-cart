<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\RateFixture;

/**
 * A plan upgrade is priced in the subscription's currency, whatever the
 * visitor browses in (dev-docs/multi-currency/plan.md decision #10, §12.4).
 * Base USD, GBP 0.79.
 */
class ForcedCartCurrencyTest extends DatabaseTestCase
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

    public function test_a_forced_cart_is_priced_in_its_currency_while_the_visitor_browses_in_another(): void
    {
        $_COOKIE[CurrencyContext::COOKIE] = 'USD';
        $cart = $this->forcedCart('GBP', 4980);

        $this->assertSame(3934, (int) $cart->cart_data[0]['unit_price'], 'converted from base, not pinned');
        $this->assertTrue((bool) $cart->checkout_data['fx']['forced']);

        $_REQUEST['fct_cart_hash'] = $cart->cart_hash;
        $this->reset();
        $this->assertSame('GBP', CurrencyContext::current(), 'the cart wins over the USD cookie');

        $cart->save();
        $this->assertSame(3934, (int) Cart::query()->find($cart->cart_hash)->cart_data[0]['unit_price'], 'saving in a USD visit does not re-price to USD');
    }

    public function test_an_expired_forced_lock_requotes_in_the_same_currency(): void
    {
        $cart = $this->forcedCart('GBP', 4980);
        RateFixture::set('GBP', '0.81');

        global $wpdb;
        $checkoutData = $cart->checkout_data;
        $checkoutData['fx']['locked_at'] = gmdate('Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS);
        $wpdb->update($wpdb->prefix . 'fct_carts', ['checkout_data' => json_encode($checkoutData)], ['cart_hash' => $cart->cart_hash]);
        $this->reset();

        $fresh = Cart::query()->find($cart->cart_hash);
        $fresh->save();
        $fresh = Cart::query()->find($cart->cart_hash);

        $this->assertSame('GBP', $fresh->checkout_data['fx']['currency']);
        $this->assertSame('0.810000000000', $fresh->checkout_data['fx']['rate']);
        $this->assertSame(4034, (int) $fresh->cart_data[0]['unit_price']);
    }

    public function test_quote_for_falls_back_to_the_orders_own_trusted_rate(): void
    {
        // No EUR rate stored: the order's own frozen rate is used, never 1
        $snapshot = CurrencySnapshot::make('EUR', '0.92', ['source' => CurrencySnapshot::SOURCE_MANUAL]);

        $quote = CartCurrency::quoteFor('EUR', $snapshot);
        $this->assertSame('EUR', $quote['currency']);
        $this->assertSame('0.920000000000', $quote['rate']);

        $unknown = CurrencySnapshot::legacy('EUR');
        $this->assertSame('USD', CartCurrency::quoteFor('EUR', $unknown)['currency'], 'an untrusted rate is not reused');
    }

    private function forcedCart(string $currency, int $basePrice): Cart
    {
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => $basePrice])->create();
        $variation = ProductVariation::query()->find($product->variants->first()->id);

        $cart = new Cart();
        $cart->cart_group = 'instant';
        $cart->cart_data = [CartHelper::generateCartItemFromVariation($variation, 1)];
        $cart->checkout_data = ['upgrade_data' => ['upgrade_from_order_id' => 1]];
        CartCurrency::force($cart, $currency);
        $cart->save();

        return Cart::query()->find($cart->cart_hash);
    }

    private function reset(): void
    {
        CurrencyContext::reset();
        CartCurrency::reset();
    }
}
