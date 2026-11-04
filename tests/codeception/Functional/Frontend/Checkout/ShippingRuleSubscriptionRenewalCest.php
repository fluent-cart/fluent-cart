<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use PHPUnit\Framework\Assert;
use Tests\Support\Doubles\CheckoutGateway;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\FunctionalTester;

/**
 * A shipping pricing rule discounts the first order's postage only. The
 * subscription created at checkout must renew on the full shipping charge.
 */
class ShippingRuleSubscriptionRenewalCest
{
    /** @var array<int, array{0: string, 1: callable}> This Cest's own hooks, unhooked one by one in _after. */
    private $ownHooks = [];

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $gateway = new CheckoutGateway();
        $gateway->supportedFeatures[] = 'subscriptions';
        GatewayManager::getInstance()->register('wp_browser_stub', $gateway);
    }

    public function _after(FunctionalTester $I): void
    {
        foreach ($this->ownHooks as $hook) {
            remove_filter($hook[0], $hook[1], 10);
        }
        $this->ownHooks = [];

        $gateway = GatewayManager::getInstance('wp_browser_stub');
        if ($gateway) {
            remove_filter('fluent_cart/transaction/url_wp_browser_stub', [$gateway, 'getTransactionUrl'], 10);
            remove_filter('fluent_cart/subscription/url_wp_browser_stub', [$gateway, 'getSubscriptionUrl'], 10);
        }
        GatewayManager::getInstance()->remove('wp_browser_stub');
        $I->disarmStockListeners();
    }

    public function renewalsBillFullShippingAfterAShippingRule(FunctionalTester $I): void
    {
        // Stands in for a 50%-off "every purchase" shipping pricing rule.
        $this->hook('fluent_cart/checkout/order_shipping_charge', function ($charge) {
            return (int) round((int) $charge / 2);
        });

        [$order, $subscription] = $this->placeSubscriptionOrder($I, 'rule');

        Assert::assertSame(500, (int) $order->shipping_total, 'the first order keeps the discounted postage');
        Assert::assertSame(1500, (int) $order->total_amount);
        Assert::assertSame(1500, $this->firstPayment($subscription), 'the first payment must stay what the order charges');

        Assert::assertSame(2000, (int) $subscription->recurring_total, 'renewals bill the item plus the full postage');
        Assert::assertSame(2000, (int) $subscription->recurring_amount);
    }

    public function renewalsWithoutAShippingRuleAreUnchanged(FunctionalTester $I): void
    {
        [$order, $subscription] = $this->placeSubscriptionOrder($I, 'norule');

        Assert::assertSame(1000, (int) $order->shipping_total);
        Assert::assertSame(2000, (int) $order->total_amount);
        Assert::assertSame(2000, (int) $subscription->recurring_total);
        Assert::assertSame(0, (int) $subscription->trial_days);
        Assert::assertSame(0, (int) $subscription->signup_fee);
    }

    /**
     * $10/year physical subscription with a $10 flat-rate shipping method.
     *
     * @return array{0: Order, 1: Subscription}
     */
    private function placeSubscriptionOrder(FunctionalTester $I, string $emailTag): array
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
            ->withVariantAttributes([
                'item_price'       => 1000,
                'fulfillment_type' => 'physical',
                'payment_type'     => 'subscription',
                'other_info'       => [
                    'payment_type'    => 'subscription',
                    'repeat_interval' => 'yearly',
                    'times'           => 0,
                    'trial_days'      => 0,
                    'signup_fee'      => 0,
                ],
            ])
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

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', [
            'fct_cart_hash'               => $cart->cart_hash,
            '_fct_pay_method'             => 'wp_browser_stub',
            'fc_shipping_method'          => (string) $method->id,
            'fc_selected_shipping_method' => (string) $method->id,
            'billing_full_name'           => 'Renewal Buyer',
            'billing_first_name'          => 'Renewal',
            'billing_last_name'           => 'Buyer',
            'billing_email'               => 'shipping-renewal-' . $emailTag . '@example.test',
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

        $subscription = Subscription::query()->where('parent_order_id', $order->id)->first();
        Assert::assertNotNull($subscription, 'the order must create a subscription. Body: ' . wp_json_encode($body));

        return [$order, $subscription];
    }

    /**
     * What a gateway takes up front: the signup fee alone during a (real or
     * simulated) trial, otherwise one cycle plus the signup fee.
     */
    private function firstPayment(Subscription $subscription): int
    {
        if ((int) $subscription->trial_days > 0) {
            return (int) $subscription->signup_fee;
        }

        return (int) $subscription->recurring_total + (int) $subscription->signup_fee;
    }

    private function hook(string $name, callable $callback): void
    {
        add_filter($name, $callback);
        $this->ownHooks[] = [$name, $callback];
    }
}
