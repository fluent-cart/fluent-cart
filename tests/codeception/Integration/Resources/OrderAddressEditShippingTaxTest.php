<?php

namespace Tests\Integration\Resources;

use FluentCart\Api\Checkout\CheckoutApi;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\App;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\TaxRate;
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
use Tests\Support\Factory\TaxClassFactory;
use Tests\Support\Factory\TaxRateFactory;
use Tests\Support\Traits\BuildsTaxFixtures;
use Tests\Support\WpDieCapture;

/**
 * Editing an order address that keeps the same tax rate must keep the
 * shipping tax and total the checkout charged.
 */
class OrderAddressEditShippingTaxTest extends DatabaseTestCase
{
    use BuildsTaxFixtures;

    private $module;

    public function setUp(): void
    {
        parent::setUp();

        GatewayManager::getInstance()->register('wp_browser_stub', new CheckoutGateway());
        add_filter('fluent_cart/checkout_active_payment_methods', [$this, 'allowStub'], 999);

        $this->enableTax('excluded');
        $this->resetTaxRuntimeState();
        TaxCalculator::resetCache();
        $this->module = new TaxModule();
        $this->module->register();

        // Place-order commits, so an earlier test's rate can survive into this one
        $classId = (int) $this->ensureStandardTaxClass()->id;
        if (!TaxRate::query()->where('class_id', $classId)->where('country', 'LI')->exists()) {
            (new TaxRateFactory())->create([
                'class_id' => $classId,
                'country'  => 'LI',
                'rate'     => '10',
                'name'     => 'VAT',
            ]);
        }
    }

    public function tearDown(): void
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

    public function test_an_address_edit_keeps_the_shipping_tax(): void
    {
        $order = $this->placeOrder(1500);

        $this->assertSame(150, (int) $order->shipping_tax, 'Fixture: checkout taxes the shipping');
        $placedTotal = (int) $order->total_amount;

        $address = OrderAddress::query()->where('order_id', $order->id)->where('type', 'billing')->first();
        OrderResource::updateOrderAddress([
            'order_id'  => $order->id,
            'id'        => $address->id,
            'address_1' => '2 Other Way',
        ]);

        $edited = Order::query()->find($order->id);
        $this->assertSame(150, (int) $edited->shipping_tax);
        $this->assertSame($placedTotal, (int) $edited->total_amount);
    }

    public function test_a_free_shipping_line_gets_no_share_of_a_zone_rate(): void
    {
        // No LI rate on this class, so a share landing here is untaxed
        $untaxed = (new TaxClassFactory())->create();
        $order = $this->placeOrder(1500, [
            [3000, []],
            [3000, ['tax_class' => $untaxed->slug, 'free_shipping' => 'yes']],
        ]);

        $this->assertSame(150, (int) $order->shipping_tax, 'Fixture: the whole rate sits on the taxed line');
        $placedTotal = (int) $order->total_amount;

        $address = OrderAddress::query()->where('order_id', $order->id)->where('type', 'billing')->first();
        OrderResource::updateOrderAddress([
            'order_id'  => $order->id,
            'id'        => $address->id,
            'address_1' => '2 Other Way',
        ]);

        $edited = Order::query()->find($order->id);
        $this->assertSame(150, (int) $edited->shipping_tax);
        $this->assertSame($placedTotal, (int) $edited->total_amount);
    }

    private function placeOrder(int $shipping, array $products = [[4000, []], [2900, []]]): Order
    {
        $lines = [];
        foreach ($products as list($price, $otherInfo)) {
            $variation = (new ProductFactory())
                ->withDetailAttributes(['fulfillment_type' => 'physical'])
                ->withVariantAttributes([
                    'item_price'       => $price,
                    'fulfillment_type' => 'physical',
                    'other_info'       => array_merge(['payment_type' => 'onetime', 'tax_class' => 'standard'], $otherInfo),
                ])
                ->create()->variants->first();
            $lines[] = CartHelper::generateCartItemFromVariation($variation, 1);
        }

        $zone = (new ShippingZoneFactory())->create(['region' => 'LI']);
        $method = (new ShippingMethodFactory())->create([
            'zone_id'  => (int) $zone->id,
            'type'     => 'flat_rate',
            'amount'   => $shipping / 100,
            'settings' => ['configure_rate' => 'per_order'],
        ]);

        $cart = (new CartFactory())->withCartItems($lines)->create(['cart_group' => 'instant']);

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_carts', ['checkout_data' => wp_json_encode([
            'form_data'     => ['billing_country' => 'LI', 'ship_to_different' => 'no'],
            'shipping_data' => ['shipping_method_id' => (int) $method->id, 'shipping_charge' => $shipping],
        ])], ['cart_hash' => $cart->cart_hash]);

        $data = [
            'fct_cart_hash'               => $cart->cart_hash,
            '_fct_pay_method'             => 'wp_browser_stub',
            'billing_full_name'           => 'Tax Buyer',
            'billing_first_name'          => 'Tax',
            'billing_last_name'           => 'Buyer',
            'billing_email'               => 'address-edit-buyer@example.test',
            'billing_address_1'           => '1 Way',
            'billing_city'                => 'Vaduz',
            'billing_postcode'            => '9490',
            'billing_country'             => 'LI',
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

        return $order;
    }
}
