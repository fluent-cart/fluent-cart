<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CouponCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\Framework\Support\Collection;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\TaxClassFactory;
use Tests\Support\Factory\TaxRateFactory;
use Tests\Support\Traits\BuildsTaxFixtures;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;
use Tests\Support\RateFixture;

/**
 * What a currency re-price sets off. Tax and shipping are computed from the
 * item prices, so a re-priced cart runs the same items-updated lifecycle as
 * any item change — with or without coupons. And a converted coupon copy is
 * recognised by its own identity, never by an object id PHP can reuse.
 * Base USD, GBP at 0.79.
 */
class CartRepriceLifecycleTest extends DatabaseTestCase
{
    use MakesMultiCurrencyAvailable;

    use BuildsTaxFixtures;

    public function setUp(): void
    {
        parent::setUp();
        $this->makeMultiCurrencyAvailable();
        $this->setTaxSettings([]);
        $this->resetTaxRuntimeState();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP', 'rounding' => 'none']], 'rate_lock_minutes' => 30]);
        RateFixture::set('GBP', '0.79');
        $this->visitIn('USD');
    }

    public function tearDown(): void
    {
        unset($_COOKIE[CurrencyContext::COOKIE]);
        delete_option('fluent_cart_tax_configuration_settings');
        CurrencyContext::reset();
        CartCurrency::reset();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_switching_currency_recalculates_the_stored_exclusive_tax(): void
    {
        $this->enableTax('excluded', 'billing');
        $class = (new TaxClassFactory())->create(['meta' => ['priority' => 10, 'categories' => []]]);
        $product = $this->makeProductWithClass($class->id);
        (new TaxRateFactory())->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);
        (new TaxModule())->register();

        // A coupon-free USD cart with its 10% exclusive tax persisted: $100 -> $10
        $cart = (new CartFactory())->create([
            'cart_data'     => [$this->buildLineItem($product->ID, ['subtotal' => 10000, 'unit_price' => 10000])],
            'checkout_data' => ['form_data' => ['billing_country' => 'US']],
        ]);
        $cart = Cart::query()->find($cart->cart_hash);
        do_action('fluent_cart/cart/cart_data_items_updated', ['cart' => $cart, 'scope' => 'item_added']);
        $cart = Cart::query()->find($cart->cart_hash);
        $this->assertSame(1000, (int) $cart->checkout_data['tax_data']['tax_total']);

        // The shopper switches to GBP: £79.00, so the tax is £7.90
        $this->visitIn('GBP');
        $cart->save();

        $fresh = Cart::query()->find($cart->cart_hash);
        $this->assertSame(7900, (int) $fresh->cart_data[0]['subtotal']);
        $this->assertSame(790, (int) $fresh->checkout_data['tax_data']['tax_total'], 'not the $10.00 computed in USD');
    }

    public function test_switching_currency_converts_a_stored_fee_and_its_tax(): void
    {
        $cart = $this->taxedCartWithFee(1000);
        $this->assertSame(100, (int) $cart->checkout_data['tax_data']['fee_tax'], 'precondition: 10% of the $10.00 fee');

        $this->visitIn('GBP');
        $cart->save();

        $fresh = Cart::query()->find($cart->cart_hash);
        $this->assertSame(790, (int) $fresh->checkout_data['fees'][0]['amount'], 'the $10.00 fee is £7.90, not 1000 pence');
        $this->assertSame(79, (int) $fresh->checkout_data['tax_data']['fee_tax']);
        $this->assertSame(869, (int) $fresh->checkout_data['tax_data']['tax_total'], '£7.90 on the item plus £0.79 on the fee');
    }

    public function test_a_cart_left_in_gbp_without_pro_converts_its_stored_fee_to_the_base_currency(): void
    {
        $this->visitIn('GBP');
        $cart = $this->taxedCartWithFee(790);
        $this->assertSame('GBP', $cart->checkout_data['fx']['currency'], 'precondition: priced in GBP while Pro was active');
        $this->assertSame(79, (int) $cart->checkout_data['tax_data']['fee_tax']);

        // Pro is deactivated; the shopper's next page view re-prices the cart to USD
        remove_filter('fluent_cart/currency/multi_currency_available', '__return_true');
        MultiCurrencySettings::clearCache();
        $this->visitIn('GBP');
        $_COOKIE['fct_cart_hash'] = $cart->cart_hash;
        try {
            $this->assertTrue(CartCurrency::syncRequestCart());
        } finally {
            unset($_COOKIE['fct_cart_hash']);
        }

        $fresh = Cart::query()->find($cart->cart_hash);
        $this->assertSame('USD', $fresh->checkout_data['fx']['currency']);
        $this->assertSame(10000, (int) $fresh->cart_data[0]['subtotal']);
        $this->assertSame(1000, (int) $fresh->checkout_data['fees'][0]['amount'], 'the £7.90 fee is $10.00, not 790 cents');
        $this->assertSame(100, (int) $fresh->checkout_data['tax_data']['fee_tax'], 'not the 79 pence computed in GBP');
        $this->assertSame(1100, (int) $fresh->checkout_data['tax_data']['tax_total']);
    }

    public function test_a_coupon_free_reprice_notifies_the_items_lifecycle_once(): void
    {
        $cart = (new CartFactory())->create([
            'cart_data' => [$this->buildLineItem((new \Tests\Support\Factory\ProductFactory())->create()->ID, ['subtotal' => 10000, 'unit_price' => 10000])],
        ]);
        $cart = Cart::query()->find($cart->cart_hash);

        $scopes = [];
        $listener = function ($data) use (&$scopes) {
            $scopes[] = $data['scope'];
            // Listeners save the cart, as the tax and shipping modules do
            $data['cart']->save();
        };
        add_action('fluent_cart/cart/cart_data_items_updated', $listener);

        $this->visitIn('GBP');
        $cart->save();

        $this->assertSame(['currency_repriced'], $scopes, 'once, and the listener\'s own save does not loop');
    }

    public function test_a_released_converted_coupon_never_marks_a_fresh_one(): void
    {
        $this->visitIn('GBP');
        $cart = (new CartFactory())->create(['cart_data' => []]);
        $cart = Cart::query()->find($cart->cart_hash);
        CartCurrency::reconcile($cart);
        $code = 'TEN-' . wp_generate_password(6, false);
        $saved = (new CouponFactory())->create(['code' => $code, 'amount' => 1000]);

        $copies = CouponCurrency::convert(new Collection([Coupon::query()->find($saved->id)]), [$code], ['cart' => $cart]);
        $copy = $copies->first();
        $this->assertSame(790, (int) $copy->amount);

        // Free the copy last: PHP hands its object id to the next object made
        unset($copies);
        unset($copy);
        $fresh = new Coupon();
        $fresh->forceFill(Coupon::query()->find($saved->id)->getAttributes());
        $fresh->exists = true;
        $fresh->syncOriginal();

        $this->assertFalse(CouponCurrency::isConvertedCopy($fresh), 'a fresh coupon is not a converted copy');

        $converted = CouponCurrency::convert(new Collection([$fresh]), [$code], ['cart' => $cart]);
        $this->assertSame(790, (int) $converted->first()->amount, 'a fresh coupon is still converted');

        $fresh->title = 'Saved again';
        $this->assertTrue((bool) $fresh->save(), 'a fresh coupon still saves');
    }

    /**
     * A cart for one $100 base item, 10% exclusive tax and a stored taxable
     * fee of $feeAmount in the cart's currency, with its tax persisted.
     */
    private function taxedCartWithFee(int $feeAmount): Cart
    {
        $this->enableTax('excluded', 'billing');
        // A class of its own: a US rate another class committed on the shared
        // 'standard' class would double the tax here.
        $class = (new TaxClassFactory())->create(['meta' => ['priority' => 10, 'categories' => []]]);
        $product = $this->makeProductWithClass($class->id);
        (new TaxRateFactory())->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);
        (new TaxModule())->register();

        $cart = (new CartFactory())->create([
            'cart_data'     => [$this->buildLineItem($product->ID, ['subtotal' => 10000, 'unit_price' => 10000])],
            'checkout_data' => ['form_data' => ['billing_country' => 'US']],
        ]);
        $cart = Cart::query()->find($cart->cart_hash);
        $cart->save();
        $cart->addFee(['key' => 'handling', 'label' => 'Handling', 'amount' => $feeAmount, 'taxable' => true]);
        do_action('fluent_cart/cart/cart_data_items_updated', ['cart' => $cart, 'scope' => 'item_added']);

        return Cart::query()->find($cart->cart_hash);
    }

    private function visitIn(string $currency): void
    {
        $_COOKIE[CurrencyContext::COOKIE] = $currency;
        CurrencyContext::reset();
        CartCurrency::reset();
    }
}
