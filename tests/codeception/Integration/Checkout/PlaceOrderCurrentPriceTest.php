<?php

namespace Tests\Integration\Checkout;

use FluentCart\Api\Checkout\CheckoutApi;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\App;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\Framework\Http\Request\Request as FluentRequest;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\CheckoutGateway;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\WpDieCapture;

/**
 * SCAN-05: a cart line keeps the price from when it was added. The order
 * must be priced from the catalog at checkout, not from that old snapshot.
 */
class PlaceOrderCurrentPriceTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        GatewayManager::getInstance()->register('wp_browser_stub', new CheckoutGateway());
        CartResource::resetCartCache();
        CartCheckoutHelper::resetInstance();
    }

    public function tearDown(): void
    {
        $gateway = GatewayManager::getInstance('wp_browser_stub');
        if ($gateway) {
            remove_filter('fluent_cart/transaction/url_wp_browser_stub', [$gateway, 'getTransactionUrl'], 10);
            remove_filter('fluent_cart/subscription/url_wp_browser_stub', [$gateway, 'getSubscriptionUrl'], 10);
        }
        GatewayManager::getInstance()->remove('wp_browser_stub');
        CartResource::resetCartCache();
        CartCheckoutHelper::resetInstance();
        $this->bindRequest([]);
        parent::tearDown();
    }

    private function placeOrder(string $cartHash): array
    {
        $data = [
            'fct_cart_hash'      => $cartHash,
            '_fct_pay_method'    => 'wp_browser_stub',
            'billing_full_name'  => 'Price Buyer',
            'billing_first_name' => 'Price',
            'billing_last_name'  => 'Buyer',
            'billing_email'      => 'price-buyer@example.test',
            'billing_address_1'  => '10 Checkout Way',
            'billing_city'       => 'New York',
            'billing_state'      => 'NY',
            'billing_postcode'   => '10001',
            'billing_country'    => 'US',
            'ship_to_different'  => 'no',
            'agree_terms'        => 'yes',
        ];
        $this->bindRequest($data);

        return WpDieCapture::capture(function () use ($data) {
            CheckoutApi::placeOrder($data, true);
        });
    }

    public function test_a_price_increase_is_shown_before_the_order_is_charged(): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1000, 'manage_stock' => 0])->create()->variants->first();
        $cart = (new CartFactory())->withCartItems([CartHelper::generateCartItemFromVariation($variation, 2)])->create(['cart_group' => 'instant']);

        // The store raises the price after the shopper added the item.
        ProductVariation::query()->where('id', $variation->id)->update(['item_price' => 2000]);
        $ordersBefore = Order::query()->count();

        $first = $this->placeOrder($cart->cart_hash);

        $this->assertSame(423, $first['status'], 'a changed price must stop this attempt so the shopper can see it. Body: ' . $first['body']);
        $this->assertTrue((bool) ($first['json']['reload'] ?? false), 'the checkout page must reload to show the new price');
        $this->assertSame($ordersBefore, Order::query()->count(), 'no order may be created at the old price');
        $this->assertSame(2000, (int) Cart::query()->find($cart->cart_hash)->cart_data[0]['unit_price'], 'the cart must now hold the current price');
        $this->assertSame(
            [$first['json']['message'] ?? ''],
            Cart::query()->find($cart->cart_hash)->checkout_data['__checkout_error_notices'] ?? [],
            'the reloaded checkout page must show why the price changed'
        );

        CartResource::resetCartCache();
        CartCheckoutHelper::resetInstance();
        $second = $this->placeOrder($cart->cart_hash);

        $order = Order::query()->orderBy('id', 'DESC')->first();
        $this->assertSame($ordersBefore + 1, Order::query()->count(), 'the second attempt must place the order. Body: ' . $second['body']);
        $this->assertSame(4000, (int) $order->total_amount, 'the order charges the current price: 2 x $20.00');
    }

    public function test_a_raised_signup_fee_is_re_priced_even_when_the_price_is_the_same(): void
    {
        $variation = (new ProductFactory())->withVariantAttributes([
            'item_price'   => 1000,
            'manage_stock' => 0,
            'payment_type' => 'subscription',
            'other_info'   => ['payment_type' => 'subscription', 'repeat_interval' => 'monthly', 'manage_setup_fee' => 'yes', 'signup_fee' => 500],
        ])->create()->variants->first();
        $cart = (new CartFactory())->withCartItems([CartHelper::generateCartItemFromVariation($variation, 1)])->create(['cart_group' => 'instant']);

        // The store raises only the signup fee; the recurring price stays $10.00.
        $variation = ProductVariation::query()->find($variation->id);
        $otherInfo = $variation->other_info;
        $otherInfo['signup_fee'] = 1500;
        $variation->other_info = $otherInfo;
        $variation->save();

        $cart = Cart::query()->find($cart->cart_hash);
        $this->assertTrue($cart->refreshStalePrices(), 'a changed signup fee must re-price the line');
        $this->assertSame(1500, (int) Cart::query()->find($cart->cart_hash)->cart_data[0]['other_info']['signup_fee'], 'the cart must now hold the current signup fee');
        $this->assertFalse(Cart::query()->find($cart->cart_hash)->refreshStalePrices(), 'a re-priced line must then compare as current');
    }

    public function test_only_the_stale_line_of_two_lines_for_the_same_variation_is_replaced(): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1000, 'manage_stock' => 0])->create()->variants->first();
        $first = CartHelper::generateCartItemFromVariation($variation, 1);
        $first['other_info']['engraving'] = 'A';
        $cart = (new CartFactory())->withCartItems([$first])->create(['cart_group' => 'instant']);

        ProductVariation::query()->where('id', $variation->id)->update(['item_price' => 2000]);

        // The first line was re-added at the new price; the second, for the same variation, still holds $10.00.
        $current = CartHelper::generateCartItemFromVariation(ProductVariation::query()->find($variation->id), 1);
        $current['other_info']['engraving'] = 'A';
        $stale = $first;
        $stale['other_info']['engraving'] = 'B';
        $cart = Cart::query()->find($cart->cart_hash);
        $cart->cart_data = [$current, $stale];
        $cart->save();

        $cart = Cart::query()->find($cart->cart_hash);
        $this->assertTrue($cart->refreshStalePrices(), 'the stale second line must be re-priced');

        $lines = Cart::query()->find($cart->cart_hash)->cart_data;
        $this->assertSame([2000, 2000], array_map('intval', array_column($lines, 'unit_price')), 'both lines must now hold the current price');
        $this->assertSame(['A', 'B'], [$lines[0]['other_info']['engraving'], $lines[1]['other_info']['engraving']], 'each line must keep its own identity');
        $this->assertFalse(Cart::query()->find($cart->cart_hash)->refreshStalePrices(), 'nothing is stale on the next check, so checkout cannot loop on 423');
    }

    public function test_a_checkout_locked_cart_without_an_order_keeps_its_agreed_price(): void
    {
        // An early-installment cart: locked, no order yet, a real variation at the agreed amount.
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1000, 'manage_stock' => 0])->create()->variants->first();
        $item = CartHelper::generateCartItemFromVariation($variation, 1);
        $item['price'] = $item['unit_price'] = $item['subtotal'] = $item['line_total'] = 750;
        $cart = (new CartFactory())->withCartItems([$item])->create([
            'cart_group'    => 'instant',
            'checkout_data' => ['is_locked' => 'yes', 'early_installment_data' => ['parent_order_id' => 1]],
        ]);

        ProductVariation::query()->where('id', $variation->id)->update(['item_price' => 2000]);

        $cart = Cart::query()->find($cart->cart_hash);
        $this->assertFalse($cart->isLocked(), 'precondition: without an order, isLocked() is false by design');
        $this->assertFalse($cart->refreshStalePrices(), 'a checkout-locked cart must never be re-priced');
        $this->assertSame(750, (int) Cart::query()->find($cart->cart_hash)->cart_data[0]['unit_price'], 'the agreed amount must stay');
    }

    public function test_a_subscription_turned_one_time_is_re_priced_once_without_old_billing_fields(): void
    {
        $variation = (new ProductFactory())->withVariantAttributes([
            'item_price'   => 1000,
            'manage_stock' => 0,
            'payment_type' => 'subscription',
            'other_info'   => ['payment_type' => 'subscription', 'repeat_interval' => 'monthly', 'times' => 12, 'trial_days' => 7],
        ])->create()->variants->first();
        $cart = (new CartFactory())->withCartItems([CartHelper::generateCartItemFromVariation($variation, 1)])->create(['cart_group' => 'instant']);

        // The store turns the product into a one-time purchase at the same price.
        $variation = ProductVariation::query()->find($variation->id);
        $variation->payment_type = 'onetime';
        $variation->other_info = ['payment_type' => 'onetime'];
        $variation->save();

        $this->assertTrue(Cart::query()->find($cart->cart_hash)->refreshStalePrices(), 'the subscription terms changed, so the line is re-priced');

        $otherInfo = Cart::query()->find($cart->cart_hash)->cart_data[0]['other_info'];
        $this->assertSame('onetime', $otherInfo['payment_type'] ?? null, 'the line must now be a one-time purchase');
        $this->assertArrayNotHasKey('repeat_interval', $otherInfo, 'removed subscription terms must not survive');
        $this->assertArrayNotHasKey('times', $otherInfo, 'removed subscription terms must not survive');
        $this->assertFalse(Cart::query()->find($cart->cart_hash)->refreshStalePrices(), 'the second check finds nothing stale, so checkout cannot loop on 423');
    }

    private function bindRequest(array $params): void
    {
        $app = App::getInstance();
        $request = new FluentRequest($app, $params, []);
        $app->instance(FluentRequest::class, $request);
        $app->instance('request', $request);
    }
}
