<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencyAdmin;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\RateFixture;

/**
 * Multi-currency is a Pro feature. This suite runs without Pro, so the saved
 * settings and stored rates below must not reach the storefront: shoppers
 * see, and new orders are placed in, the base currency only.
 * Base USD, GBP at 0.79.
 */
class MultiCurrencyWithoutProTest extends DatabaseTestCase
{
    const AVAILABLE = 'fluent_cart/currency/multi_currency_available';

    public function setUp(): void
    {
        parent::setUp();
        $this->reset();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP', 'rounding' => 'none']], 'rate_lock_minutes' => 30]);
        RateFixture::set('GBP', '0.79');
        $this->reset();
    }

    public function tearDown(): void
    {
        unset($_COOKIE[CurrencyContext::COOKIE], $_GET[CurrencyContext::QUERY_PARAM], $_REQUEST['fct_cart_hash']);
        remove_all_filters(self::AVAILABLE);
        $this->reset();
        parent::tearDown();
    }

    public function test_saved_settings_do_not_turn_multi_currency_on_without_pro(): void
    {
        $this->assertFalse(MultiCurrencySettings::isEnabled(), 'enabled=yes with a GBP rate, but no Pro');
        $this->assertSame([], MultiCurrencySettings::currencyCodes());
        $this->assertSame(['USD'], CurrencyContext::selectableCurrencies());
        $this->assertSame('', do_shortcode('[fluent_cart_currency_switcher]'), 'no switcher to offer');
    }

    public function test_the_currency_cookie_and_query_parameter_are_ignored(): void
    {
        $_COOKIE[CurrencyContext::COOKIE] = 'GBP';
        $_GET[CurrencyContext::QUERY_PARAM] = 'GBP';
        $this->reset();

        $this->assertNull(CurrencyContext::chosen());
        $this->assertSame('USD', CurrencyContext::current());
        $this->assertFalse(CurrencyContext::choose('GBP'), 'the switch cannot select GBP');
    }

    public function test_a_currency_added_by_filter_is_not_offered_without_pro(): void
    {
        add_filter('fluent_cart/currency/enabled_currencies', function () {
            return ['GBP'];
        });

        $this->assertFalse(MultiCurrencySettings::isOffered('GBP'));
        $this->assertFalse(CurrencyContext::isSelectable('GBP'));
    }

    public function test_a_cart_priced_in_gbp_while_pro_was_active_checks_out_in_the_base_currency(): void
    {
        $cart = $this->gbpCart(4980);

        // Pro is deactivated: the GBP cart is re-priced once, then checks out in USD
        $this->deactivatePro();
        $result = CartCurrency::checkoutSnapshot(Cart::query()->find($cart->cart_hash), 'GBP');
        $this->assertInstanceOf(\WP_Error::class, $result, 'the page showed GBP; the shopper reviews the USD total first');

        $this->reset();
        $repriced = Cart::query()->find($cart->cart_hash);
        $this->assertSame('USD', $repriced->checkout_data['fx']['currency']);
        $this->assertSame(4980, (int) $repriced->cart_data[0]['unit_price']);

        $snapshot = CartCurrency::checkoutSnapshot($repriced, 'USD');
        $this->assertIsArray($snapshot);
        $this->assertSame('USD', $snapshot['currency']);
    }

    public function test_a_page_view_reprices_a_gbp_cart_to_the_base_currency(): void
    {
        $cart = $this->gbpCart(4980);
        $this->deactivatePro();
        $_COOKIE['fct_cart_hash'] = $cart->cart_hash;
        $this->reset();

        try {
            $this->assertTrue(CartCurrency::syncRequestCart(), 'the cart drawer must not show GBP amounts as dollars');
        } finally {
            unset($_COOKIE['fct_cart_hash']);
        }

        $this->assertSame(4980, (int) Cart::query()->find($cart->cart_hash)->cart_data[0]['unit_price']);
    }

    public function test_a_cart_held_to_gbp_for_a_new_order_is_refused(): void
    {
        $this->makeAvailable();
        $cart = $this->buildCart(4980, function (Cart $cart) {
            $cart->checkout_data = ['upgrade_data' => ['upgrade_from_order_id' => 1]];
            CartCurrency::force($cart, 'GBP');
        });
        $this->assertSame('GBP', $cart->checkout_data['fx']['currency'], 'precondition: an upgrade cart held to GBP');

        $this->deactivatePro();
        $_REQUEST['fct_cart_hash'] = $cart->cart_hash;
        $this->reset();

        $result = CartCurrency::checkoutSnapshot(Cart::query()->find($cart->cart_hash), 'GBP');

        $this->assertInstanceOf(\WP_Error::class, $result, 'a new order is never placed in GBP without Pro');
        $this->assertSame('fx_currency_unavailable', $result->get_error_code());
    }

    public function test_paying_an_existing_gbp_order_keeps_its_currency(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'currency'       => 'GBP',
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => 3934,
            'total_amount'   => 3934,
            'config'         => ['fx' => CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL])],
        ]);

        $cart = $this->buildCart(3934, function (Cart $cart) use ($order) {
            $cart->order_id = $order->id;
            $cart->checkout_data = ['is_locked' => 'yes', 'custom_checkout' => 'yes'];
            CartCurrency::pin($cart, CurrencySnapshot::fromOrder(Order::query()->find($order->id)));
        });

        $_REQUEST['fct_cart_hash'] = $cart->cart_hash;
        $this->reset();
        $snapshot = CartCurrency::checkoutSnapshot(Cart::query()->find($cart->cart_hash), 'GBP');

        $this->assertIsArray($snapshot, is_wp_error($snapshot) ? $snapshot->get_error_message() : '');
        $this->assertSame('GBP', $snapshot['currency'], 'the order owes GBP; relabelling it USD would corrupt it');
    }

    public function test_an_admin_order_cannot_be_created_in_gbp_without_pro(): void
    {
        $this->assertSame([], MultiCurrencyAdmin::orderCurrencies(), 'the create screen offers no currency picker');

        $ordersBefore = Order::query()->count();
        $result = OrderResource::updatedPlaceOrder($this->adminPayload('GBP'));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame($ordersBefore, Order::query()->count(), 'no order is created');
    }

    public function test_the_base_currency_stays_locked_while_multi_currency_orders_exist(): void
    {
        (new OrderFactory())->create([
            'customer_id' => (new CustomerFactory())->create()->id,
            'currency'    => 'GBP',
            'config'      => ['fx' => CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL])],
        ]);

        $this->assertInstanceOf(\WP_Error::class, MultiCurrencySettings::guardBaseCurrencyChange('USD', 'EUR'), 'stored GBP orders are measured against USD');
    }

    public function test_the_gate_opens_when_pro_makes_multi_currency_available(): void
    {
        $this->makeAvailable();
        $_COOKIE[CurrencyContext::COOKIE] = 'GBP';
        $this->reset();

        $this->assertTrue(MultiCurrencySettings::isEnabled());
        $this->assertSame('GBP', CurrencyContext::current());
    }

    private function gbpCart(int $basePrice): Cart
    {
        $this->makeAvailable();
        $_COOKIE[CurrencyContext::COOKIE] = 'GBP';
        $this->reset();

        $cart = $this->buildCart($basePrice);
        $this->assertSame('GBP', $cart->checkout_data['fx']['currency'], 'precondition: priced in GBP while Pro was active');
        $this->assertSame((int) round($basePrice * 0.79), (int) $cart->cart_data[0]['unit_price']);

        return $cart;
    }

    private function buildCart(int $basePrice, ?callable $prepare = null): Cart
    {
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => $basePrice])->create();
        $variation = ProductVariation::query()->find($product->variants->first()->id);

        $cart = new Cart();
        $cart->cart_group = 'instant';
        $cart->cart_data = [CartHelper::generateCartItemFromVariation($variation, 1)];
        if ($prepare) {
            $prepare($cart);
        }
        $cart->save();

        return Cart::query()->find($cart->cart_hash);
    }

    private function adminPayload(string $currency): array
    {
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => 4980])->create();
        $variant = $product->variants->first();

        return [
            'customer_id' => (new CustomerFactory())->create()->id,
            'currency'    => $currency,
            'order_items' => [[
                'id'           => $variant->id,
                'post_id'      => $product->ID,
                'object_id'    => $variant->id,
                'title'        => 'Default',
                'post_title'   => 'Product',
                'unit_price'   => 4980,
                'quantity'     => 1,
                'payment_type' => 'onetime',
                'other_info'   => ['payment_type' => 'onetime'],
            ]],
            'discount' => [],
            'shipping' => [],
            'labels'   => [],
            'user_tz'  => 'UTC',
        ];
    }

    private function makeAvailable(): void
    {
        add_filter(self::AVAILABLE, '__return_true');
        $this->reset();
    }

    /**
     * The shopper's GBP cookie outlives Pro.
     */
    private function deactivatePro(): void
    {
        remove_all_filters(self::AVAILABLE);
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
