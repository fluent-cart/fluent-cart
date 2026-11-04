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
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;
use Tests\Support\RateFixture;

/**
 * Which carts the currency follows. A completed cart is history and is never
 * re-priced (a payment webhook has no visitor currency). The visitor's cart
 * is found the way the cart itself is: by cookie, else a logged-in user's
 * open cart. Re-pricing drops amounts kept in the old currency. A cart pinned
 * to an order keeps its currency even if multi-currency is switched off.
 * Base USD, GBP at 0.79.
 */
class CartCurrencyScopeTest extends DatabaseTestCase
{
    use MakesMultiCurrencyAvailable;

    public function setUp(): void
    {
        parent::setUp();
        $this->makeMultiCurrencyAvailable();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP', 'rounding' => 'none']], 'rate_lock_minutes' => 30]);
        if (!ExchangeRateService::current('GBP')) {
            RateFixture::set('GBP', '0.79');
        }
        $this->visitIn('GBP');
    }

    public function tearDown(): void
    {
        unset($_COOKIE[CurrencyContext::COOKIE], $_COOKIE['fct_cart_hash']);
        wp_set_current_user(0);
        $this->reset();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_a_completed_cart_is_never_repriced(): void
    {
        $cart = $this->cartWith(4980);
        $this->assertSame(3934, (int) $cart->cart_data[0]['unit_price']);

        // A payment webhook: no visitor currency, the base context
        unset($_COOKIE[CurrencyContext::COOKIE]);
        $this->reset();
        $cart->stage = 'completed';
        $cart->save();

        $fresh = Cart::query()->find($cart->cart_hash);
        $this->assertSame(3934, (int) $fresh->cart_data[0]['unit_price'], 'the paid GBP cart stays GBP');
        $this->assertSame('GBP', $fresh->checkout_data['fx']['currency']);
    }

    public function test_a_logged_in_shoppers_open_cart_is_found_without_a_matching_cookie(): void
    {
        $userId = self::factory()->user->create();
        wp_set_current_user($userId);
        $cart = $this->cartWith(4980, ['user_id' => $userId, 'cart_group' => 'global']);
        $_COOKIE['fct_cart_hash'] = md5('another-device');
        $this->reset();

        $found = CartCurrency::requestCart();

        $this->assertNotNull($found);
        $this->assertSame($cart->cart_hash, $found->cart_hash);
    }

    public function test_a_completed_cart_behind_the_cookie_is_not_the_visitors_cart(): void
    {
        $cart = $this->cartWith(4980);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_carts', ['stage' => 'completed'], ['cart_hash' => $cart->cart_hash]);
        $_COOKIE['fct_cart_hash'] = $cart->cart_hash;
        $this->reset();

        $this->assertNull(CartCurrency::requestCart());
    }

    public function test_a_base_priced_cart_is_refused_once_the_visitor_is_in_another_currency(): void
    {
        $this->visitIn('USD');
        $cart = $this->cartWith(4980);
        $this->assertSame('USD', $cart->checkout_data['fx']['currency']);

        // Another tab switched to GBP; this tab still shows USD
        $this->visitIn('GBP');
        $result = CartCurrency::checkoutSnapshot(Cart::query()->find($cart->cart_hash), 'USD');

        $this->assertInstanceOf(\WP_Error::class, $result, 'never an order with USD items and GBP tax or shipping');
    }

    public function test_repricing_drops_amounts_kept_in_the_old_currency(): void
    {
        $item = CartCurrency::stampBase([
            'unit_price' => 9600,
            'subtotal'   => 9600,
            'line_total' => 9600,
            'quantity'   => 1,
            'line_meta'  => ['original_unit_price' => 9600, 'reverse_charge_adjustment' => 1600],
            'other_info' => ['original_signup_fee' => 2000, 'signup_fee' => 1667],
        ]);

        $repriced = CartCurrency::priceItem($item, CartCurrency::freshQuote('GBP'));

        $this->assertArrayNotHasKey('original_unit_price', $repriced['line_meta'], 'a reverse-charge gross in the old currency would be restored over the new price');
        $this->assertArrayNotHasKey('reverse_charge_adjustment', $repriced['line_meta']);
        $this->assertArrayNotHasKey('original_signup_fee', $repriced['other_info']);
    }

    public function test_a_pinned_cart_keeps_its_currency_when_multi_currency_is_off(): void
    {
        $cart = $this->cartWith(3934);
        CartCurrency::pin($cart, CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL]));
        $cart->save();
        $_COOKIE['fct_cart_hash'] = $cart->cart_hash;
        MultiCurrencySettings::save(['enabled' => 'no']);
        $this->reset();

        $this->assertSame('GBP', CurrencyContext::current(), 'an EUR/GBP invoice is still paid in its own currency');
    }

    private function cartWith(int $basePrice, array $overrides = []): Cart
    {
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => $basePrice])->create();
        $variation = ProductVariation::query()->find($product->variants->first()->id);
        $cart = (new CartFactory())->create(array_merge([
            'cart_data' => [CartHelper::generateCartItemFromVariation($variation, 1)],
        ], $overrides));

        return Cart::query()->find($cart->cart_hash);
    }

    private function visitIn(string $currency): void
    {
        $_COOKIE[CurrencyContext::COOKIE] = $currency;
        $this->reset();
    }

    private function reset(): void
    {
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        CurrencyContext::reset();
        CartCurrency::reset();
    }
}
