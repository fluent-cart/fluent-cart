<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\CheckoutProcessor;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CouponCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\MultiCurrency\Services\StorefrontPrice;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;
use Tests\Support\RateFixture;

/**
 * A cart is priced in one currency at one locked rate; checkout stamps the
 * order from that lock. dev-docs/multi-currency/plan.md §9.1, §11.6.
 * Base USD, GBP at 0.79 — the plan's worked example: $49.80 -> £39.34.
 */
class CartCurrencyTest extends DatabaseTestCase
{
    use MakesMultiCurrencyAvailable;

    public function setUp(): void
    {
        parent::setUp();
        $this->makeMultiCurrencyAvailable();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP', 'rounding' => 'none']], 'rate_lock_minutes' => 30]);
        RateFixture::set('GBP', '0.79');
        $this->visitIn('GBP');
    }

    public function tearDown(): void
    {
        unset($_COOKIE[CurrencyContext::COOKIE], $_COOKIE['fct_cart_hash']);
        CurrencyContext::reset();
        CartCurrency::reset();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_a_cart_is_priced_in_the_visitor_currency_at_the_active_rate(): void
    {
        $cart = $this->cartWith(4980, 2);

        $item = $cart->cart_data[0];
        $this->assertSame(3934, (int) $item['unit_price'], '4980 x 0.79 = 3934.2 -> 3934');
        $this->assertSame(7868, (int) $item['subtotal']);
        $this->assertSame(4980, (int) $item['other_info']['fx_base']['unit_price'], 'the base price is kept for re-pricing');
        $this->assertSame('GBP', $cart->checkout_data['fx']['currency']);
        $this->assertSame('0.790000000000', $cart->checkout_data['fx']['rate']);
    }

    public function test_the_locked_rate_holds_inside_the_window_even_when_the_rate_moves(): void
    {
        $cart = $this->cartWith(4980);
        RateFixture::set('GBP', '0.81');
        CartCurrency::reset();

        $cart->save();

        $this->assertSame(3934, (int) Cart::query()->find($cart->cart_hash)->cart_data[0]['unit_price'], 'the price the customer saw is the price they pay');

        $snapshot = CartCurrency::checkoutSnapshot(Cart::query()->find($cart->cart_hash));
        $this->assertSame('0.790000000000', $snapshot['rate']);
        $this->assertSame(CurrencySnapshot::SOURCE_MANUAL, $snapshot['source']);
    }

    public function test_an_expired_lock_reprices_and_checkout_refuses_once(): void
    {
        $cart = $this->cartWith(4980);
        RateFixture::set('GBP', '0.81');
        $this->expireLock($cart);

        $result = CartCurrency::checkoutSnapshot(Cart::query()->find($cart->cart_hash));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('fx_prices_updated', $result->get_error_code());

        $repriced = Cart::query()->find($cart->cart_hash);
        $this->assertSame(4034, (int) $repriced->cart_data[0]['unit_price'], '4980 x 0.81 = 4033.8 -> 4034');

        $snapshot = CartCurrency::checkoutSnapshot($repriced);
        $this->assertSame('0.810000000000', $snapshot['rate'], 'the second attempt goes through at the new rate');
    }

    public function test_no_hold_is_the_default_and_is_kept_on_save(): void
    {
        $this->assertSame(0, MultiCurrencySettings::DEFAULT_RATE_LOCK_MINUTES);

        $saved = $this->holdRateFor(0);

        $this->assertSame(0, $saved['rate_lock_minutes']);
        $this->assertSame(0, MultiCurrencySettings::rateLockSeconds());
        $this->assertSame(0, $this->holdRateFor(-5)['rate_lock_minutes']);
    }

    public function test_with_no_hold_the_shop_shows_a_new_rate_at_once(): void
    {
        $this->holdRateFor(0);
        $cart = $this->cartWith(4980);
        $_COOKIE['fct_cart_hash'] = $cart->cart_hash;
        RateFixture::set('GBP', '0.81');
        ExchangeRateService::flushCache();
        CartCurrency::reset();

        $this->assertSame(4034, StorefrontPrice::amount(4980), 'catalogue prices are not held at the cart\'s old rate');
    }

    public function test_turning_the_hold_off_releases_carts_already_holding_the_previous_rate(): void
    {
        $cart = $this->cartWith(4980);
        $_COOKIE['fct_cart_hash'] = $cart->cart_hash;
        RateFixture::set('GBP', '0.81');
        ExchangeRateService::flushCache();
        CartCurrency::reset();
        $this->assertSame(3934, StorefrontPrice::amount(4980), 'held at 0.79 inside the 30-minute window');

        $this->holdRateFor(0);
        CartCurrency::reset();

        $this->assertSame(4034, StorefrontPrice::amount(4980), 'no purge needed: the next request uses 0.81');
        $this->assertTrue(CartCurrency::syncRequestCart());
        $this->assertSame(4034, (int) Cart::query()->find($cart->cart_hash)->cart_data[0]['unit_price']);
    }

    public function test_with_no_hold_a_new_rate_reprices_and_checkout_refuses_once(): void
    {
        $this->holdRateFor(0);
        $cart = $this->cartWith(4980);
        RateFixture::set('GBP', '0.81');
        ExchangeRateService::flushCache();
        CartCurrency::reset();

        $result = CartCurrency::checkoutSnapshot(Cart::query()->find($cart->cart_hash));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('fx_prices_updated', $result->get_error_code());

        $repriced = Cart::query()->find($cart->cart_hash);
        $this->assertSame(4034, (int) $repriced->cart_data[0]['unit_price']);
        $this->assertSame('0.810000000000', CartCurrency::checkoutSnapshot($repriced)['rate'], 'the retry goes through at the new rate');
    }

    public function test_with_no_hold_an_unchanged_rate_never_blocks_checkout(): void
    {
        $this->holdRateFor(0);
        $cart = $this->cartWith(4980);
        $this->expireLock($cart);

        $snapshot = CartCurrency::checkoutSnapshot(Cart::query()->find($cart->cart_hash));

        $this->assertIsArray($snapshot, 'an old lock at today\'s rate is still today\'s price');
        $this->assertSame('0.790000000000', $snapshot['rate']);
    }

    public function test_with_no_hold_a_pricing_rule_change_reaches_the_cart(): void
    {
        $this->holdRateFor(0);
        $cart = $this->cartWith(4980);
        $_COOKIE['fct_cart_hash'] = $cart->cart_hash;
        $this->holdRateFor(0, ['adjustment' => 10]);
        CartCurrency::reset();

        $this->assertTrue(CartCurrency::syncRequestCart());
        $this->assertSame(4328, (int) Cart::query()->find($cart->cart_hash)->cart_data[0]['unit_price'], '4980 x 0.79 x 1.10 = 4327.62 -> 4328');
    }

    public function test_switching_currency_reprices_every_item_from_its_base_price(): void
    {
        $cart = $this->cartWith(4980);

        $this->visitIn('USD');
        $cart->save();
        $item = Cart::query()->find($cart->cart_hash)->cart_data[0];
        $this->assertSame(4980, (int) $item['unit_price']);

        $this->visitIn('GBP');
        $cart->save();
        $this->assertSame(3934, (int) Cart::query()->find($cart->cart_hash)->cart_data[0]['unit_price'], 'no compounding through the old currency');
    }

    public function test_a_page_view_reprices_a_cart_left_in_another_currency(): void
    {
        // Priced in USD, then the visitor's currency became GBP without a
        // cart save (a cookie, or a rate set for a currency they had chosen)
        $this->visitIn('USD');
        $cart = $this->cartWith(4980);
        $this->visitIn('GBP');
        $_COOKIE['fct_cart_hash'] = $cart->cart_hash;

        $this->assertTrue(CartCurrency::syncRequestCart());

        $synced = Cart::query()->find($cart->cart_hash);
        $this->assertSame(3934, (int) $synced->cart_data[0]['unit_price'], 'the cart drawer shows the page\'s currency');
        $this->assertSame('GBP', $synced->checkout_data['fx']['currency']);

        CartCurrency::reset();
        $this->assertFalse(CartCurrency::syncRequestCart(), 'an up-to-date cart is not written again');
    }

    public function test_a_page_view_refreshes_an_expired_lock(): void
    {
        $cart = $this->cartWith(4980);
        RateFixture::set('GBP', '0.81');
        $this->expireLock($cart);
        $_COOKIE['fct_cart_hash'] = $cart->cart_hash;

        $this->assertTrue(CartCurrency::syncRequestCart());

        $synced = Cart::query()->find($cart->cart_hash);
        $this->assertSame(4034, (int) $synced->cart_data[0]['unit_price'], 'the page shows today\'s price before checkout');
        $this->assertNotInstanceOf(\WP_Error::class, CartCurrency::checkoutSnapshot($synced), 'the customer already saw the new price');
    }

    public function test_checkout_refuses_an_order_in_a_currency_the_page_no_longer_shows(): void
    {
        // The checkout page is open in GBP; the currency is switched to USD
        // in another tab, which re-prices the cart
        $cart = $this->cartWith(4980);
        $this->visitIn('USD');
        $cart->save();

        $result = CartCurrency::checkoutSnapshot(Cart::query()->find($cart->cart_hash), 'GBP');

        $this->assertInstanceOf(\WP_Error::class, $result, 'the page showed £, the order would charge $');
        $this->assertSame('fx_currency_changed', $result->get_error_code());

        $snapshot = CartCurrency::checkoutSnapshot(Cart::query()->find($cart->cart_hash), 'USD');
        $this->assertNotInstanceOf(\WP_Error::class, $snapshot, 'placed from a page showing $, it goes through');
        $this->assertSame('USD', $snapshot['currency']);
    }

    public function test_checkout_accepts_the_currency_the_page_shows(): void
    {
        $cart = $this->cartWith(4980);

        $snapshot = CartCurrency::checkoutSnapshot($cart, 'gbp');

        $this->assertNotInstanceOf(\WP_Error::class, $snapshot);
        $this->assertSame('GBP', $snapshot['currency']);
    }

    public function test_a_logged_in_shoppers_cart_is_found_without_the_cart_cookie(): void
    {
        // Core finds a logged-in shopper's cart by user when there is no cookie
        $userId = self::factory()->user->create();
        $this->visitIn('USD');
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => 4980])->create();
        $variation = ProductVariation::query()->find($product->variants->first()->id);
        $cart = (new CartFactory())->create([
            'user_id'   => $userId,
            'cart_data' => [CartHelper::generateCartItemFromVariation($variation, 1)],
        ]);
        $this->visitIn('GBP');
        unset($_COOKIE['fct_cart_hash']);
        wp_set_current_user($userId);

        try {
            $found = CartCurrency::requestCart();
            $this->assertSame($cart->cart_hash, $found ? $found->cart_hash : null);

            $this->assertTrue(CartCurrency::syncRequestCart(), 'the page view re-prices the logged-in shopper\'s cart');
            $this->assertSame(3934, (int) Cart::query()->find($cart->cart_hash)->cart_data[0]['unit_price']);
        } finally {
            wp_set_current_user(0);
        }
    }

    public function test_a_page_view_never_reprices_a_pinned_cart(): void
    {
        $cart = new Cart();
        $cart->cart_group = 'instant';
        $cart->cart_data = [[
            'object_id' => 1, 'quantity' => 1, 'unit_price' => 3995, 'price' => 3995, 'subtotal' => 3995, 'line_total' => 3995, 'is_custom' => true,
        ]];
        CartCurrency::pin($cart, CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL]));
        $cart->save();
        $this->visitIn('USD');
        $_COOKIE['fct_cart_hash'] = $cart->cart_hash;

        $this->assertFalse(CartCurrency::syncRequestCart());
        $this->assertSame(3995, (int) Cart::query()->find($cart->cart_hash)->cart_data[0]['unit_price']);
    }

    public function test_a_cart_pinned_to_an_order_is_never_repriced(): void
    {
        // Pinned before its first save, the way CustomCheckout builds it
        $cart = new Cart();
        $cart->cart_group = 'instant';
        $cart->cart_data = [[
            'object_id' => 1, 'quantity' => 1, 'unit_price' => 3995, 'price' => 3995, 'subtotal' => 3995, 'line_total' => 3995, 'is_custom' => true,
        ]];
        CartCurrency::pin($cart, CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL]));
        $cart->save();
        $this->assertSame(3995, (int) Cart::query()->find($cart->cart_hash)->cart_data[0]['unit_price']);

        $this->visitIn('USD');
        $this->assertSame('GBP', CartCurrency::pinnedCurrency(Cart::query()->find($cart->cart_hash)));
        Cart::query()->find($cart->cart_hash)->save();

        $this->assertSame(3995, (int) Cart::query()->find($cart->cart_hash)->cart_data[0]['unit_price']);
    }

    public function test_fixed_coupons_convert_on_a_copy_that_can_never_be_saved(): void
    {
        $coupon = (new CouponFactory())->create(['type' => 'fixed', 'amount' => 1000, 'conditions' => ['min_purchase_amount' => 5000]]);
        $cart = $this->cartWith(4980);

        $converted = CouponCurrency::convert(Coupon::query()->where('id', $coupon->id)->get(), [$coupon->code], ['cart' => $cart])->first();

        $this->assertSame(790, (int) $converted->amount);
        $this->assertSame(3950, (int) $converted->conditions['min_purchase_amount']);
        $this->assertFalse($converted->save(), 'saving a converted copy is refused');
        $this->assertSame(1000, (int) Coupon::query()->find($coupon->id)->amount, 'the real coupon keeps its base amount');
    }

    public function test_storefront_prices_and_currency_settings_follow_the_visitor(): void
    {
        $this->assertSame(3934, StorefrontPrice::amount(4980));
        $this->assertSame('GBP', CurrencySettings::get('currency'), 'keyed reads see the presentment currency');

        $this->visitIn('USD');
        $this->assertSame(4980, StorefrontPrice::amount(4980));
        $this->assertSame('USD', CurrencySettings::get('currency'));
    }

    public function test_a_currency_without_a_rate_is_never_offered(): void
    {
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP'], ['code' => 'EUR']]]);
        $this->visitIn('EUR');

        $this->assertSame('USD', CurrencyContext::current(), 'no EUR rate: the visitor stays in the base currency');
        $this->assertSame(['USD', 'GBP'], CurrencyContext::selectableCurrencies());
    }

    public function test_an_order_placed_from_the_cart_is_stamped_with_the_cart_lock(): void
    {
        $cart = $this->cartWith(4980);
        $snapshot = CartCurrency::checkoutSnapshot($cart);
        $customer = (new CustomerFactory())->create();
        $item = $cart->cart_data[0];
        $item['product_title'] = 'Test';
        $item['variation_title'] = 'Default';
        $item['fulfillment_type'] = 'digital';
        $item['other_info']['payment_type'] = 'onetime';

        $order = (new CheckoutProcessor([$item], [
            'customer_id'       => $customer->id,
            'payment_method'    => 'offline_payment',
            'cart_hash'         => $cart->cart_hash,
            'currency_snapshot' => $snapshot,
        ]))->createDraftOrder();

        $this->assertNotInstanceOf(\WP_Error::class, $order);
        $order = Order::query()->find($order->id);
        $this->assertSame('GBP', $order->currency);
        $this->assertSame('0.790000000000', (string) $order->rate);
        $this->assertSame(3934, (int) $order->total_amount);
        $this->assertSame($cart->checkout_data['fx']['locked_at'], $order->config['fx']['captured_at'], 'frozen at the moment the cart was priced');

        $transaction = OrderTransaction::query()->where('order_id', $order->id)->first();
        $this->assertSame('GBP', strtoupper($transaction->currency));
        $this->assertSame('0.790000000000', (string) $transaction->rate);
    }

    /**
     * @dataProvider lockedItemMarkers
     */
    public function test_a_quantity_update_on_a_locked_item_stays_in_the_cart_currency(array $otherInfo): void
    {
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => 4980])->create();
        $variation = ProductVariation::query()->find($product->variants->first()->id);
        $item = CartHelper::generateCartItemFromVariation($variation, 1);
        $item['other_info'] = array_merge((array) $item['other_info'], $otherInfo);
        $cart = (new CartFactory())->create(['cart_data' => [$item]]);
        $cart = Cart::query()->find($cart->cart_hash);
        $this->assertSame(3934, (int) $cart->cart_data[0]['unit_price']);

        // One more of the same: the line is rebuilt from its base price
        $cart->addByVariation(ProductVariation::query()->find($variation->id), ['quantity' => 1]);

        $line = Cart::query()->find($cart->cart_hash)->cart_data[0];
        $this->assertSame(2, (int) $line['quantity']);
        $this->assertSame(3934, (int) $line['unit_price'], 'GBP, not the base 4980 under a GBP marker');
        $this->assertSame(7868, (int) $line['subtotal']);
        $this->assertSame(4980, (int) $line['other_info']['fx_base']['unit_price'], 'the base snapshot is kept');
    }

    public function lockedItemMarkers(): array
    {
        return [
            'price-locked' => [['is_price_locked' => 'yes']],
            'promotional'  => [['promotion_id' => 7]],
        ];
    }

    private function visitIn(string $currency): void
    {
        $_COOKIE[CurrencyContext::COOKIE] = $currency;
        CurrencyContext::reset();
        CartCurrency::reset();
    }

    private function cartWith(int $basePrice, int $quantity = 1): Cart
    {
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => $basePrice])->create();
        $variation = ProductVariation::query()->find($product->variants->first()->id);

        $cart = (new CartFactory())->create([
            'cart_data' => [CartHelper::generateCartItemFromVariation($variation, $quantity)],
        ]);

        return Cart::query()->find($cart->cart_hash);
    }

    private function holdRateFor(int $minutes, array $rules = [])
    {
        $saved = MultiCurrencySettings::save([
            'enabled'           => 'yes',
            'currencies'        => [array_merge(['code' => 'GBP', 'rounding' => 'none'], $rules)],
            'rate_lock_minutes' => $minutes,
        ]);
        MultiCurrencySettings::clearCache();

        return $saved;
    }

    private function expireLock(Cart $cart): void
    {
        global $wpdb;
        $checkoutData = $cart->checkout_data;
        $checkoutData['fx']['locked_at'] = gmdate('Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS);
        $wpdb->update($wpdb->prefix . 'fct_carts', ['checkout_data' => json_encode($checkoutData)], ['cart_hash' => $cart->cart_hash]);
        CartCurrency::reset();
    }
}
