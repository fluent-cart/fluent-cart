<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\MultiCurrency\Services\PriceConverter;
use FluentCart\App\Modules\MultiCurrency\Services\RoundingRules;
use FluentCart\App\Modules\MultiCurrency\Services\StorefrontPrice;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;
use Tests\Support\RateFixture;

/**
 * Global per-currency pricing rules (dev-docs/multi-currency/plan.md §15.4):
 * a % adjustment, rounding up to a step, a charm ending — applied to product
 * prices, never to shipping or coupon amounts. Base USD, GBP at 0.79.
 */
class PricingRulesTest extends DatabaseTestCase
{
    use MakesMultiCurrencyAvailable;

    public function setUp(): void
    {
        parent::setUp();
        $this->makeMultiCurrencyAvailable();
        $this->configure(['rounding' => 'none', 'charm' => 'none', 'adjustment' => 0]);
        $_COOKIE[CurrencyContext::COOKIE] = 'GBP';
        CurrencyContext::reset();
        CartCurrency::reset();
    }

    public function tearDown(): void
    {
        unset($_COOKIE[CurrencyContext::COOKIE]);
        CurrencyContext::reset();
        CartCurrency::reset();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_rounding_steps_round_up_and_charm_takes_off_the_ending(): void
    {
        $this->assertSame(3934, RoundingRules::price(3934.2, 'GBP'), 'no rule: smallest unit');
        $this->assertSame(3950, RoundingRules::price(3934.2, 'GBP', 'up_0_50'));
        $this->assertSame(4000, RoundingRules::price(3934.2, 'GBP', 'up_1'));
        $this->assertSame(3999, RoundingRules::price(3934.2, 'GBP', 'up_1', 'minus_0_01'), '£40.00 → £39.99');
        $this->assertSame(3995, RoundingRules::price(3934.2, 'GBP', 'up_1', 'minus_0_05'));
        $this->assertSame(3900, RoundingRules::price(3900, 'GBP', 'up_1'), 'an exact step does not round up');
        $this->assertSame(0, RoundingRules::price(0, 'GBP', 'up_1', 'minus_0_01'), 'zero stays zero');
    }

    public function test_a_zero_decimal_currency_rounds_whole_units_and_skips_charm(): void
    {
        $this->assertSame(740100, RoundingRules::price(740023, 'JPY', 'up_0_50'), 'a step below one yen rounds to whole yen');
        $this->assertSame(741000, RoundingRules::price(740023, 'JPY', 'up_10'));
        $this->assertSame(741000, RoundingRules::price(740023, 'JPY', 'up_10', 'minus_0_01'), 'no .99 for yen');
    }

    public function test_storefront_prices_follow_the_rules_and_shipping_does_not(): void
    {
        $this->configure(['rounding' => 'up_1', 'charm' => 'minus_0_01', 'adjustment' => 5]);
        $variation = $this->variation(4980);

        // 4980 × 0.79 × 1.05 = 4130.9 → up to 4200 → 4199
        $this->assertSame(4199, StorefrontPrice::amount($variation->item_price));
        $this->assertSame('£41.99', html_entity_decode(StorefrontPrice::format($variation->item_price)));
        $this->assertSame(790, StorefrontPrice::fee(1000), '$10 shipping → £7.90, no adjustment, no rounding step');
    }

    public function test_the_cart_charges_the_shown_price_and_the_order_records_the_rules(): void
    {
        $this->configure(['rounding' => 'up_1', 'charm' => 'minus_0_01', 'adjustment' => 5]);
        $variation = $this->variation(4980);
        $cart = $this->cartWith($variation);

        $this->assertSame(StorefrontPrice::amount($variation->item_price), (int) $cart->cart_data[0]['unit_price'], 'shown = charged');
        $this->assertSame('up_1|minus_0_01|5', $cart->cart_data[0]['other_info']['fx']['rules']);

        $snapshot = CartCurrency::checkoutSnapshot($cart);
        $this->assertSame('up_1', $snapshot['rounding']);
        $this->assertSame('minus_0_01', $snapshot['charm']);
        $this->assertSame('5', $snapshot['adjustment']);
        $this->assertSame('0.790000000000', $snapshot['rate'], 'the rate stays the market rate; rules are separate');
    }

    public function test_changed_rules_hold_inside_the_lock_and_apply_after_it(): void
    {
        $variation = $this->variation(4980);
        $cart = $this->cartWith($variation);
        $this->assertSame(3934, (int) $cart->cart_data[0]['unit_price']);

        $this->configure(['rounding' => 'up_1', 'charm' => 'none', 'adjustment' => 0]);
        Cart::query()->find($cart->cart_hash)->save();
        $this->assertSame(3934, (int) Cart::query()->find($cart->cart_hash)->cart_data[0]['unit_price'], 'the price the customer saw holds for the lock');

        $this->expireLock($cart);
        Cart::query()->find($cart->cart_hash)->save();
        $this->assertSame(4000, (int) Cart::query()->find($cart->cart_hash)->cart_data[0]['unit_price'], 'after the lock: the new rule');
    }

    public function test_a_lock_refreshed_under_new_rules_at_the_same_rate_asks_for_review(): void
    {
        // Another tab refreshed the lock after the rules changed: same
        // currency, same rate, new prices. The page priced before it must
        // not pay the new total unseen.
        $variation = $this->variation(4980);
        $cart = $this->cartWith($variation);
        $shownQuote = CartCurrency::quoteRevision($cart);

        $this->configure(['rounding' => 'up_1', 'charm' => 'none', 'adjustment' => 0]);
        $this->expireLock($cart);
        Cart::query()->find($cart->cart_hash)->save();
        $cart = Cart::query()->find($cart->cart_hash);
        $this->assertSame(4000, (int) $cart->cart_data[0]['unit_price'], 'the other tab re-priced under the new rule');
        $this->assertSame('0.790000000000', (string) CartCurrency::lock($cart)['rate'], 'at the same rate');

        $this->assertNotSame($shownQuote, CartCurrency::quoteRevision($cart), 'new rules are a new quote');
        $result = CartCurrency::checkoutSnapshot($cart, 'GBP', $shownQuote);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('fx_prices_updated', $result->get_error_code());
    }

    public function test_a_held_checkout_refuses_a_lock_with_other_rules(): void
    {
        $variation = $this->variation(4980);
        $cart = $this->cartWith($variation);
        CartCurrency::holdForCheckout($cart);

        // Same currency and rate, other rules written to the cart meanwhile
        $checkoutData = $cart->checkout_data;
        $checkoutData['fx']['rounding'] = 'up_1';
        $cart->checkout_data = $checkoutData;

        $refusal = CartCurrency::heldLockChanged($cart);
        CartCurrency::releaseCheckout($cart);

        $this->assertInstanceOf(\WP_Error::class, $refusal);
    }

    public function test_settings_keep_the_adjustment_within_range(): void
    {
        $this->configure(['rounding' => 'up_5', 'charm' => 'nonsense', 'adjustment' => 250]);

        $rules = MultiCurrencySettings::pricingRules('GBP');
        $this->assertSame('up_5', $rules['rounding']);
        $this->assertSame('none', $rules['charm'], 'an unknown charm falls back to none');
        $this->assertSame('100', $rules['adjustment'], 'capped at +100 percent');
    }

    public function test_a_price_filter_bound_converts_back_through_the_adjustment(): void
    {
        $this->configure(['rounding' => 'none', 'charm' => 'none', 'adjustment' => 10]);
        $quote = CartCurrency::currentQuote();

        // £8.69 = $10 × 0.79 × 1.10
        $this->assertSame(1000, PriceConverter::toBase(869, $quote));
    }

    private function configure(array $rules): void
    {
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        MultiCurrencySettings::save([
            'enabled'           => 'yes',
            'currencies'        => [array_merge(['code' => 'GBP'], $rules)],
            'rate_lock_minutes' => 30,
        ]);
        if (!ExchangeRateService::current('GBP')) {
            RateFixture::set('GBP', '0.79');
        }
        CurrencyContext::reset();
        CartCurrency::reset();
    }

    private function variation(int $basePrice): ProductVariation
    {
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => $basePrice])->create();

        return ProductVariation::query()->find($product->variants->first()->id);
    }

    private function cartWith(ProductVariation $variation): Cart
    {
        $cart = (new CartFactory())->create([
            'cart_data' => [CartHelper::generateCartItemFromVariation($variation, 1)],
        ]);

        return Cart::query()->find($cart->cart_hash);
    }

    private function expireLock(Cart $cart): void
    {
        global $wpdb;
        $cart = Cart::query()->find($cart->cart_hash);
        $checkoutData = $cart->checkout_data;
        $checkoutData['fx']['locked_at'] = gmdate('Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS);
        $wpdb->update($wpdb->prefix . 'fct_carts', ['checkout_data' => json_encode($checkoutData)], ['cart_hash' => $cart->cart_hash]);
        CartCurrency::reset();
    }
}
