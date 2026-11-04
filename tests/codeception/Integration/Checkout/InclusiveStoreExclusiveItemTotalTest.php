<?php

namespace Tests\Integration\Checkout;

use FluentCart\Api\Checkout\CheckoutApi;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\App;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\Tax\TaxCalculator;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\Framework\Http\Request\Request as FluentRequest;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\CheckoutGateway;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\Factory\TaxRateFactory;
use Tests\Support\Traits\BuildsTaxFixtures;
use Tests\Support\WpDieCapture;

/**
 * A store whose prices include tax, with a product set to exclude it.
 *
 * Shipping and fees follow the store setting, so their tax is inside their
 * price. The order must not add it again: it charges what the checkout showed.
 */
class InclusiveStoreExclusiveItemTotalTest extends DatabaseTestCase
{
    use BuildsTaxFixtures;
    use \Tests\Support\Traits\MocksPayPalHttp;

    private $module;

    public function setUp(): void
    {
        parent::setUp();

        $stub = new CheckoutGateway();
        $stub->supportedFeatures[] = 'subscriptions';
        GatewayManager::getInstance()->register('wp_browser_stub', $stub);
        add_filter('fluent_cart/checkout_active_payment_methods', [$this, 'allowStub'], 999);

        $this->enableTax('included');
        $this->resetTaxRuntimeState();
        TaxCalculator::resetCache();
        $this->module = new TaxModule();
        $this->module->register();

        (new TaxRateFactory())->create([
            'class_id' => (int) $this->ensureStandardTaxClass()->id,
            // A country no other test taxes, so a leftover rate cannot add to this one.
            'country'  => 'IS',
            'rate'     => '4.75',
            'name'     => 'VAT',
        ]);
    }

    public function tearDown(): void
    {
        $this->removeModuleHooks();
        remove_filter('fluent_cart/checkout_active_payment_methods', [$this, 'allowStub'], 999);
        GatewayManager::getInstance()->remove('wp_browser_stub');
        CartResource::resetCartCache();
        CartCheckoutHelper::resetInstance();
        delete_option('fluent_cart_tax_configuration_settings');

        parent::tearDown();
    }

    public function allowStub($methods)
    {
        $methods = (array) $methods;
        if (!in_array('wp_browser_stub', $methods, true)) {
            $methods[] = 'wp_browser_stub';
        }

        return $methods;
    }

    public function test_the_order_charges_what_the_checkout_showed(): void
    {
        list($order, $cart) = $this->placeOrder(['payment_type' => 'onetime'], 1699, [
            ['key' => 'handling', 'label' => 'Handling', 'amount' => 3000, 'taxable' => true, 'source' => 'custom'],
        ]);

        // 6900 + 328 item tax on top; shipping 1699 and the fee 3000 keep their tax inside.
        $this->assertSame(11927, (int) $cart->getEstimatedTotal(), 'Fixture: the checkout total');
        $this->assertSame(11927, (int) $order->total_amount);
    }

    public function test_a_subscription_renews_at_what_the_checkout_showed(): void
    {
        list($order, $cart) = $this->placeOrder([
            'payment_type'    => 'subscription',
            'repeat_interval' => 'monthly',
            'times'           => 0,
            'trial_days'      => 0,
        ], 1699);

        $subscription = Subscription::query()->where('parent_order_id', $order->id)->first();

        $this->assertSame(8927, (int) $cart->getEstimatedTotal(), 'Fixture: the checkout total');
        $this->assertSame(8927, (int) $order->total_amount);
        $this->assertSame(8927, (int) $subscription->recurring_total);
    }

    public function test_stripe_adds_only_the_item_tax(): void
    {
        list($order) = $this->placeOrder(['payment_type' => 'onetime'], 1699, [
            ['key' => 'handling', 'label' => 'Handling', 'amount' => 3000, 'taxable' => true, 'source' => 'custom'],
        ]);

        $method = new \ReflectionMethod(\FluentCart\App\Modules\PaymentMethods\StripeGateway\Processor::class, 'additiveTaxTotal');
        $method->setAccessible(true);

        $this->assertSame(328, (int) $method->invoke(new \FluentCart\App\Modules\PaymentMethods\StripeGateway\Processor(), $order));
    }

    public function test_paypal_sends_only_the_item_tax(): void
    {
        list($order) = $this->placeOrder(['payment_type' => 'onetime'], 1699, [
            ['key' => 'handling', 'label' => 'Handling', 'amount' => 3000, 'taxable' => true, 'source' => 'custom'],
        ]);

        $this->installPayPalHttpMock();
        $this->scriptPayPal('checkout/orders', 201, ['id' => 'PPORDER1', 'status' => 'CREATED', 'links' => []]);
        $sent = null;
        $capture = function ($preempt, $args, $url) use (&$sent) {
            if (strpos($url, 'checkout/orders') !== false) {
                $sent = json_decode((string) $args['body'], true);
            }
            return $preempt;
        };
        add_filter('pre_http_request', $capture, 5, 3);
        (new \FluentCart\App\Modules\PaymentMethods\PayPalGateway\Processor())
            ->handleSinglePayment(new \FluentCart\App\Services\Payments\PaymentInstance(Order::query()->find($order->id)));
        remove_filter('pre_http_request', $capture, 5);
        $this->removePayPalHttpMock();

        $breakdown = $sent['purchase_units'][0]['amount']['breakdown'];
        $this->assertSame('119.27', $sent['purchase_units'][0]['amount']['value'], 'Fixture: PayPal charges the order total');
        $this->assertSame('3.28', $breakdown['tax_total']['value']);
        $this->assertArrayNotHasKey('discount', $breakdown, 'No made-up discount to balance a doubled tax');
    }

    public function test_one_time_items_beside_a_subscription_add_no_shipping_tax(): void
    {
        list($order) = $this->placeOrder([
            'payment_type'    => 'subscription',
            'repeat_interval' => 'monthly',
            'times'           => 0,
            'trial_days'      => 0,
        ], 1699, [], [[2000, 'physical', ['payment_type' => 'onetime']]], 'digital');

        // A digital subscription leaves the shipping to the one-time item; its tax stays inside.
        $instance = new \FluentCart\App\Services\Payments\PaymentInstance(Order::query()->find($order->id));
        $this->assertSame(3794, (int) $instance->getExtraAddonAmount(), '2000 + 95 item tax + 1699 shipping');
    }

    public function test_an_admin_resave_keeps_the_checkout_total(): void
    {
        list($order) = $this->placeOrder(['payment_type' => 'onetime'], 1699, [
            ['key' => 'handling', 'label' => 'Handling', 'amount' => 3000, 'taxable' => true, 'source' => 'custom'],
        ]);

        $method = new \ReflectionMethod(\FluentCart\Api\Resource\OrderResource::class, 'reapplyTaxAfterUpdate');
        $method->setAccessible(true);
        $method->invoke(null, $order->id, Order::with(['order_items'])->find($order->id));

        $resaved = Order::query()->find($order->id);
        $this->assertSame(11927, (int) $resaved->total_amount);
        $this->assertSame(3000, (int) $resaved->fee_total, 'The fee keeps its tax inside its price');
    }

    private function placeOrder(array $otherInfo, int $shipping, array $fees = [], array $extraProducts = [], string $fulfillment = 'physical'): array
    {
        $lines = [];
        foreach (array_merge([[6900, $fulfillment, $otherInfo]], $extraProducts) as list($price, $lineFulfillment, $info)) {
            $variation = (new ProductFactory())
                ->withDetailAttributes(['fulfillment_type' => $lineFulfillment])
                ->withVariantAttributes([
                    'item_price'       => $price,
                    'fulfillment_type' => $lineFulfillment,
                    'other_info'       => $info + ['tax_class' => 'standard', 'tax_inclusion' => 'excluded'],
                ])
                ->create()->variants->first();
            $lines[] = CartHelper::generateCartItemFromVariation($variation, 1);
        }

        $zone = (new ShippingZoneFactory())->create(['region' => 'IS']);
        $method = (new ShippingMethodFactory())->create([
            'zone_id'  => (int) $zone->id,
            'type'     => 'flat_rate',
            'amount'   => $shipping / 100,
            'settings' => ['configure_rate' => 'per_order'],
        ]);

        $cart = (new CartFactory())->withCartItems($lines)->create(['cart_group' => 'instant']);

        // What the checkout's address and shipping step saves on the cart.
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_carts', ['checkout_data' => wp_json_encode([
            'fees'          => $fees,
            'form_data'     => ['billing_country' => 'IS', 'ship_to_different' => 'no'],
            'shipping_data' => ['shipping_method_id' => (int) $method->id, 'shipping_charge' => $shipping],
        ])], ['cart_hash' => $cart->cart_hash]);

        $data = [
            'fct_cart_hash'               => $cart->cart_hash,
            '_fct_pay_method'             => 'wp_browser_stub',
            'billing_full_name'           => 'Tax Buyer',
            'billing_first_name'          => 'Tax',
            'billing_last_name'           => 'Buyer',
            'billing_email'               => 'tax-buyer@example.test',
            'billing_address_1'           => '1 Way',
            'billing_city'                => 'Reykjavik',
            'billing_postcode'            => '101',
            'billing_country'             => 'IS',
            'ship_to_different'           => 'no',
            'agree_terms'                 => 'yes',
            'fc_selected_shipping_method' => (int) $method->id,
            'fc_shipping_method'          => (int) $method->id,
        ];
        $app = App::getInstance();
        $request = new FluentRequest($app, $data, []);
        $app->instance(FluentRequest::class, $request);
        $app->instance('request', $request);

        $result = WpDieCapture::capture(function () use ($data) {
            CheckoutApi::placeOrder($data, true);
        });

        $order = Order::query()->orderBy('id', 'DESC')->first();
        $this->assertNotNull($order, 'place-order must create the order. Body: ' . $result['body']);

        return [$order, Cart::query()->where('cart_hash', $cart->cart_hash)->first()];
    }

    private function removeModuleHooks(): void
    {
        global $wp_filter;
        foreach ($wp_filter as $hookName => $hook) {
            foreach ($hook->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $callback) {
                    $fn = $callback['function'];
                    $owned = is_array($fn) && isset($fn[0]) && $fn[0] === $this->module;
                    if (!$owned && $fn instanceof \Closure) {
                        $owned = (new \ReflectionFunction($fn))->getClosureThis() === $this->module;
                    }
                    if ($owned) {
                        remove_filter($hookName, $fn, $priority);
                    }
                }
            }
        }
    }
}
