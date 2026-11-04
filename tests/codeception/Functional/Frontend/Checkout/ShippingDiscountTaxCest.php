<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\Framework\Support\Arr;
use PHPUnit\Framework\Assert;
use Tests\Support\Doubles\CheckoutGateway;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\FunctionalTester;

/**
 * Tax must be calculated on the shipping charge the order actually stores.
 *
 * A shipping pricing rule reduces the order's postage through
 * `fluent_cart/checkout/order_shipping_charge`. If that runs after the tax
 * recalculation, the order keeps tax derived from the full charge while
 * charging the discounted one.
 */
class ShippingDiscountTaxCest
{
    /** @var int|null */
    private $taxTimeShippingCharge = null;

    /** @var callable|null Held so it can be unhooked without disturbing TaxModule's own. */
    private $netShippingFilter = null;

    /** @var array<int, array{0: string, 1: callable}> This Cest's own hooks, unhooked one by one in _after. */
    private $ownHooks = [];

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        GatewayManager::getInstance()->register('wp_browser_stub', new CheckoutGateway());
    }

    public function _after(FunctionalTester $I): void
    {
        // Only this Cest's callbacks: removing every listener on these hooks would also
        // drop ShippingModule/TaxModule (and Pro's pricing rule) for every later Cest.
        foreach ($this->ownHooks as $hook) {
            remove_filter($hook[0], $hook[1], 10);
        }
        $this->ownHooks = [];

        if ($this->netShippingFilter) {
            remove_filter('fluent_cart/cart/shipping_total', $this->netShippingFilter, 10);
            $this->netShippingFilter = null;
        }

        $gateway = GatewayManager::getInstance('wp_browser_stub');
        if ($gateway) {
            remove_filter('fluent_cart/transaction/url_wp_browser_stub', [$gateway, 'getTransactionUrl'], 10);
            remove_filter('fluent_cart/subscription/url_wp_browser_stub', [$gateway, 'getSubscriptionUrl'], 10);
        }
        GatewayManager::getInstance()->remove('wp_browser_stub');
        $I->disarmStockListeners();
    }

    public function taxIsRecalculatedOnTheDiscountedShippingCharge(FunctionalTester $I): void
    {
        $I->logout();

        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);
        $method = (new ShippingMethodFactory())->create([
            'zone_id' => $zone->id,
            'type'    => 'flat_rate',
            'amount'  => 10,
        ]);

        $product = (new ProductFactory())
            ->withAttributes(['fulfillment_type' => 'physical'])
            ->withVariantAttributes(['item_price' => 5000, 'fulfillment_type' => 'physical'])
            ->create();
        $variation = $product->variants->first();

        // Shipping already chosen on the checkout page, which is what puts the
        // gross postage on the cart for the tax pipeline to read.
        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 1),
        ])->create([
            'cart_group'    => 'instant',
            'checkout_data' => [
                'shipping_data' => [
                    'shipping_method_id' => $method->id,
                    'shipping_charge'    => 1000,
                ],
            ],
        ]);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        // Stands in for a 10%-off shipping pricing rule.
        $this->hook('fluent_cart/checkout/order_shipping_charge', function ($charge) {
            return (int) round((int) $charge * 0.9);
        });

        // Record the postage the tax pipeline is handed, at the moment
        // placeOrder() recalculates tax.
        $this->hook('fluent_cart/cart/cart_data_items_updated', function ($data) {
            if (Arr::get($data, 'scope') !== 'payment_method_fee_recalculate') {
                return;
            }

            $cart = Arr::get($data, 'cart');
            $this->taxTimeShippingCharge = (int) Arr::get($cart->checkout_data, 'shipping_data.shipping_charge', 0);
        });

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', [
            'fct_cart_hash'      => $cart->cart_hash,
            '_fct_pay_method'    => 'wp_browser_stub',
            'fc_shipping_method'          => (string) $method->id,
            'fc_selected_shipping_method' => (string) $method->id,
            'billing_full_name'  => 'Ship Buyer',
            'billing_first_name' => 'Ship',
            'billing_last_name'  => 'Buyer',
            'billing_email'      => 'shipping-discount@example.test',
            'billing_address_1'  => '10 Checkout Way',
            'billing_city'       => 'New York',
            'billing_state'      => 'NY',
            'billing_postcode'   => '10001',
            'billing_country'    => 'US',
            'ship_to_different'  => 'no',
            'agree_terms'        => 'yes',
        ]);

        Assert::assertSame(200, $status, 'place-order must succeed. Body: ' . wp_json_encode($body));

        $order = Order::query()->orderBy('id', 'DESC')->first();
        Assert::assertNotNull($order, 'an order must be created. Body: ' . wp_json_encode($body));

        $storedShipping = (int) $order->shipping_total;
        Assert::assertSame(900, $storedShipping, 'the order stores the discounted postage');

        Assert::assertSame(
            $storedShipping,
            $this->taxTimeShippingCharge,
            'tax must be calculated on the same postage the order charges, not the pre-discount amount'
        );
    }

    /**
     * The stored discount has to be measured in the same money as the stored
     * charge. Dynamic reverse charge with inclusive shipping tax converts the
     * charge to net through `fluent_cart/cart/shipping_total`, and that
     * conversion clamps at zero — so a discount taken before it can describe an
     * original price the order never had.
     */
    public function theStoredShippingDiscountUsesTheSameBasisAsTheStoredCharge(FunctionalTester $I): void
    {
        $I->logout();

        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);
        $method = (new ShippingMethodFactory())->create([
            'zone_id' => $zone->id,
            'type'    => 'flat_rate',
            'amount'  => 10,
        ]);

        $product = (new ProductFactory())
            ->withAttributes(['fulfillment_type' => 'physical'])
            ->withVariantAttributes(['item_price' => 5000, 'fulfillment_type' => 'physical'])
            ->create();
        $variation = $product->variants->first();

        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 1),
        ])->create([
            'cart_group'    => 'instant',
            'checkout_data' => [
                'shipping_data' => [
                    'shipping_method_id' => $method->id,
                    'shipping_charge'    => 1000,
                ],
            ],
        ]);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        // A rule generous enough that the net conversion below clamps at zero.
        $this->hook('fluent_cart/checkout/order_shipping_charge', function ($charge) {
            return (int) round((int) $charge * 0.1);
        });

        // Stands in for dynamic reverse charge with inclusive shipping tax:
        // TaxModule subtracts the embedded shipping VAT, clamped at zero.
        $this->netShippingFilter = function ($shippingTotal) {
            return max(0, (int) $shippingTotal - 150);
        };
        add_filter('fluent_cart/cart/shipping_total', $this->netShippingFilter, 10, 2);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', [
            'fct_cart_hash'               => $cart->cart_hash,
            '_fct_pay_method'             => 'wp_browser_stub',
            'fc_shipping_method'          => (string) $method->id,
            'fc_selected_shipping_method' => (string) $method->id,
            'billing_full_name'           => 'Basis Buyer',
            'billing_first_name'          => 'Basis',
            'billing_last_name'           => 'Buyer',
            'billing_email'               => 'shipping-basis@example.test',
            'billing_address_1'           => '10 Checkout Way',
            'billing_city'                => 'New York',
            'billing_state'               => 'NY',
            'billing_postcode'            => '10001',
            'billing_country'             => 'US',
            'ship_to_different'           => 'no',
            'agree_terms'                 => 'yes',
        ]);

        Assert::assertSame(200, $status, 'place-order must succeed. Body: ' . wp_json_encode($body));

        $order = Order::query()->orderBy('id', 'DESC')->first();
        Assert::assertNotNull($order, 'an order must be created. Body: ' . wp_json_encode($body));

        $storedShipping = (int) $order->shipping_total;
        $storedDiscount = (int) Arr::get($order->config, 'shipping_discount', 0);

        // The undiscounted postage, put through the same net conversion: 1000 - 150.
        Assert::assertSame(
            850,
            $storedShipping + $storedDiscount,
            'the stored charge plus the stored discount must rebuild the original postage in the same money'
        );
    }

    private function hook(string $name, callable $callback): void
    {
        add_filter($name, $callback);
        $this->ownHooks[] = [$name, $callback];
    }
}
