<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\Framework\Support\Arr;
use PHPUnit\Framework\Assert;
use Tests\Support\Doubles\CheckoutGateway;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Cart items changed while place-order recalculates must reach the order.
 *
 * Dynamic pricing withdraws a discount saved by an earlier, declined attempt
 * on that recalculation. An order built from a copy read before it charged
 * the discount twice on the retry.
 */
class PlaceOrderCartDataCest
{
    /** @var array<int, array{0: string, 1: callable, 2: int}> This Cest's own hooks, unhooked one by one in _after. */
    private $ownHooks = [];

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        GatewayManager::getInstance()->register('wp_browser_stub', new CheckoutGateway());
    }

    public function _after(FunctionalTester $I): void
    {
        // Only this Cest's callbacks, so TaxModule/ShippingModule stay hooked for later Cests.
        foreach ($this->ownHooks as $hook) {
            remove_action($hook[0], $hook[1], $hook[2]);
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

    public function itemsChangedDuringTheRecalculationReachTheOrder(FunctionalTester $I): void
    {
        $I->logout();

        $product = (new ProductFactory())->withVariantAttributes(['item_price' => 5000])->create();
        $variation = $product->variants->first();

        // Saved by the declined first attempt: a 500 discount on the item.
        $item = CartHelper::generateCartItemFromVariation($variation, 1);
        $item['manual_discount'] = 500;
        $item['discount_total'] = 500;
        $item['line_total'] = 4500;

        $cart = (new CartFactory())->withCartItems([$item])->create(['cart_group' => 'instant']);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        // Withdraws it, the way the pricing module does on this recalculation.
        $withdraw = function ($data) {
            $recalculated = Arr::get($data, 'cart');
            if (!$recalculated instanceof Cart || Arr::get($data, 'scope') !== 'payment_method_fee_recalculate') {
                return;
            }

            $items = $recalculated->cart_data;
            $items[0]['manual_discount'] = 0;
            $items[0]['discount_total'] = 0;
            $items[0]['line_total'] = 5000;
            $recalculated->cart_data = $items;
            $recalculated->save();
        };
        add_action('fluent_cart/cart/cart_data_items_updated', $withdraw, 20);
        $this->ownHooks[] = ['fluent_cart/cart/cart_data_items_updated', $withdraw, 20];

        $seen = null;
        $capture = function ($data) use (&$seen) {
            $seen = (int)Arr::get(Arr::get($data, 'cart')->cart_data, '0.manual_discount', -1);
        };
        add_action('fluent_cart/checkout/before_order_processing', $capture);
        $this->ownHooks[] = ['fluent_cart/checkout/before_order_processing', $capture, 10];

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', [
            'fct_cart_hash'      => $cart->cart_hash,
            '_fct_pay_method'    => 'wp_browser_stub',
            'billing_full_name'  => 'Retry Buyer',
            'billing_first_name' => 'Retry',
            'billing_last_name'  => 'Buyer',
            'billing_email'      => 'retry@example.test',
            'billing_address_1'  => '10 Checkout Way',
            'billing_city'       => 'New York',
            'billing_state'      => 'NY',
            'billing_postcode'   => '10001',
            'billing_country'    => 'US',
            'ship_to_different'  => 'no',
            'agree_terms'        => 'yes',
        ]);

        Assert::assertSame(200, $status, 'place-order must succeed. Body: ' . wp_json_encode($body));
        Assert::assertSame(0, $seen, 'The order hook sees the recalculated items, not a copy read before');

        $order = Order::query()->orderBy('id', 'DESC')->first();
        Assert::assertSame(0, (int)$order->manual_discount_total, 'The order is built from the recalculated items');
    }
}
