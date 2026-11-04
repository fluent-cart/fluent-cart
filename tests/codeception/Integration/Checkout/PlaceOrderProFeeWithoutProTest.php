<?php

namespace Tests\Integration\Checkout;

use FluentCart\Api\Checkout\CheckoutApi;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\App;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\Framework\Http\Request\Request as FluentRequest;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\CheckoutGateway;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\WpDieCapture;

/**
 * A dynamic pricing fee saved on the cart while Pro was active is not charged
 * at place-order once Pro is gone. Lives here, not in Functional: that suite
 * defines the Pro constant for its permission checks.
 */
class PlaceOrderProFeeWithoutProTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->assertFalse(App::isProActive(), 'This suite must run without Pro');
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

    public function test_the_order_is_placed_without_the_pro_fee(): void
    {
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => 5000])->create();
        $item = CartHelper::generateCartItemFromVariation($product->variants->first(), 1);

        $cart = (new CartFactory())->withCartItems([$item])->create(['cart_group' => 'instant']);

        // Written past the model, the way the row looks after a save with Pro active.
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_carts', ['checkout_data' => wp_json_encode([
            'fees' => [
                ['key' => 'rule_7', 'label' => 'Zia Fee', 'amount' => 700, 'source' => 'dynamic-pricing'],
                ['key' => 'handling', 'label' => 'Handling', 'amount' => 300, 'source' => 'custom'],
            ],
        ])], ['cart_hash' => $cart->cart_hash]);

        $data = [
            'fct_cart_hash'      => $cart->cart_hash,
            '_fct_pay_method'    => 'wp_browser_stub',
            'billing_full_name'  => 'Fee Buyer',
            'billing_first_name' => 'Fee',
            'billing_last_name'  => 'Buyer',
            'billing_email'      => 'fee-buyer@example.test',
            'billing_address_1'  => '10 Checkout Way',
            'billing_city'       => 'New York',
            'billing_state'      => 'NY',
            'billing_postcode'   => '10001',
            'billing_country'    => 'US',
            'ship_to_different'  => 'no',
            'agree_terms'        => 'yes',
        ];
        $this->bindRequest($data);

        $result = WpDieCapture::capture(function () use ($data) {
            CheckoutApi::placeOrder($data, true);
        });

        $order = Order::query()->orderBy('id', 'DESC')->first();
        $this->assertNotNull($order, 'place-order must create the order. Body: ' . $result['body']);

        $feeTitles = OrderItem::query()
            ->where('order_id', $order->id)
            ->where('payment_type', 'fee')
            ->pluck('title')
            ->toArray();

        $this->assertSame(['Handling'], $feeTitles, 'Only the core fee becomes an order line');
        $this->assertSame(5300, (int) $order->total_amount, 'The Pro fee is not charged');
    }

    private function bindRequest(array $params): void
    {
        $app = App::getInstance();
        $request = new FluentRequest($app, $params, []);
        $app->instance(FluentRequest::class, $request);
        $app->instance('request', $request);
    }
}
