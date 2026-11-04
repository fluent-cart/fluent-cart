<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;

class QuantityOverflowCest
{
    use PlacesCheckoutOrders;

    public function _before(FunctionalTester $I): void
    {
        $I->logout();
        $this->registerStubCheckoutGateway();
    }

    public function _after(FunctionalTester $I): void
    {
        $this->removeStubCheckoutGateway();
    }

    public function guestCannotAddAnOverflowingQuantity(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 10000, 'manage_stock' => 0])->create()->variants->first();
        $cart = (new CartFactory())->create();

        [$status, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'fluent_cart_cart_update',
            'fct_cart_hash'      => $cart->cart_hash,
            'item_id'            => $variation->id,
            'quantity'           => '9223372036854775',
        ]);

        Assert::assertSame(422, $status, 'An overflowing guest cart update must fail: ' . wp_json_encode($body));
        Assert::assertSame([], $cart->fresh()->cart_data, 'A rejected update must not change the stored cart.');
    }

    public function guestCannotIncrementPastTheQuantityCeiling(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1, 'manage_stock' => 0])->create()->variants->first();
        $cart = (new CartFactory())->withCartItems([CartHelper::generateCartItemFromVariation($variation, CartHelper::MAX_QUANTITY)])->create();

        [$status, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'fluent_cart_cart_update',
            'fct_cart_hash'      => $cart->cart_hash,
            'item_id'            => $variation->id,
            'quantity'           => 1,
            'by_input'           => false,
        ]);

        Assert::assertSame(422, $status, 'An increment past the ceiling must fail: ' . wp_json_encode($body));
        Assert::assertSame(CartHelper::MAX_QUANTITY, $cart->fresh()->cart_data[0]['quantity'], 'A rejected increment must preserve the stored quantity.');
    }

    public function guestCanStillUpdateToASupportedQuantity(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 10000, 'manage_stock' => 0])->create()->variants->first();
        $cart = (new CartFactory())->create();

        [$status, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'fluent_cart_cart_update',
            'fct_cart_hash'      => $cart->cart_hash,
            'item_id'            => $variation->id,
            'quantity'           => 3,
        ]);

        Assert::assertSame(200, $status, 'A normal quantity must still be accepted: ' . wp_json_encode($body));
        Assert::assertSame(3, $cart->fresh()->cart_data[0]['quantity'], 'The accepted quantity must be stored.');
    }

    public function staleOverflowingCartCannotBecomeAPaidOrder(FunctionalTester $I): void
    {
        // Carts written before the ceiling existed are the reporter's live exploit.
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 10000, 'manage_stock' => 0])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 9223372036854775]]);
        $paidEvents = did_action('fluent_cart/order_paid');

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));

        Assert::assertSame(422, $status, 'A stale overflowing cart must be rejected: ' . wp_json_encode($body));
        Assert::assertSame($paidEvents, did_action('fluent_cart/order_paid'), 'A rejected checkout must not emit an order-paid event.');
        Assert::assertSame(0, Order::query()->count(), 'No order may be created from overflowing arithmetic.');
        Assert::assertSame(0, OrderTransaction::query()->count(), 'No transaction may be created.');
        Assert::assertNotSame('completed', $cart->fresh()->stage, 'A rejected checkout must not complete the cart.');
        Assert::assertNull($cart->fresh()->order_id, 'A rejected cart must not be linked to an order.');
    }

    public function genuinelyFreeProductStillCompletesWithOfflinePaymentDisabled(FunctionalTester $I): void
    {
        // The zero-total gateway bypass is intentional for genuinely free products; the
        // overflow fix must not take it away. See paidProductCannotUseDisabledOfflinePayment.
        $gateway = GatewayManager::getInstance('offline_payment');
        $previousSettings = $gateway->settings->settings;
        $gateway->settings->settings['is_active'] = 'no';

        try {
            $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 0])->create()->variants->first();
            $cart = $this->buildInstantCart($I, [[$variation, 1]]);

            [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));

            Assert::assertSame(200, $status, 'A genuine free order must still succeed: ' . wp_json_encode($body));
            $order = Order::query()->first();
            Assert::assertNotNull($order, 'Free checkout must persist an order.');
            Assert::assertSame(0, (int)$order->total_amount, 'Free checkout must retain its zero total.');
            Assert::assertSame('paid', $order->payment_status, 'The legitimate zero-payment path must still mark the order paid.');
        } finally {
            $gateway->settings->settings = $previousSettings;
        }
    }

    public function paidProductCannotUseDisabledOfflinePayment(FunctionalTester $I): void
    {
        $gateway = GatewayManager::getInstance('offline_payment');
        $previousSettings = $gateway->settings->settings;
        $gateway->settings->settings['is_active'] = 'no';

        try {
            $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 10000])->create()->variants->first();
            $cart = $this->buildInstantCart($I, [[$variation, 1]]);
            $payload = $this->checkoutPayload($cart->cart_hash);
            $payload['_fct_pay_method'] = 'offline_payment';

            [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);

            Assert::assertSame(423, $status, 'A paid cart must not bypass a disabled gateway: ' . wp_json_encode($body));
            Assert::assertSame(0, Order::query()->count(), 'A disabled-gateway rejection must not create an order.');
            Assert::assertSame(0, OrderTransaction::query()->count(), 'A disabled-gateway rejection must not create a transaction.');
        } finally {
            $gateway->settings->settings = $previousSettings;
        }
    }
}
