<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;
use Tests\Support\RateFixture;

/**
 * place-order without Pro: multi-currency is off for the storefront, so a
 * new order is only ever placed in the base currency, whatever the cart was
 * priced in while Pro was active. This suite fakes Pro being active, so
 * deactivating it is simulated through the availability filter.
 * Base USD, GBP at 0.79.
 */
class PlaceOrderWithoutProCest
{
    use PlacesCheckoutOrders;

    const AVAILABLE = 'fluent_cart/currency/multi_currency_available';

    public function _before(FunctionalTester $I): void
    {
        $I->logout();
        $this->registerStubCheckoutGateway();
        // Pro-on carts here are GBP; only a multi_currency gateway may take them
        GatewayManager::getInstance($this->stubGatewaySlug)->supportedFeatures[] = 'multi_currency';
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP', 'rounding' => 'none']], 'rate_lock_minutes' => 30]);
        RateFixture::set('GBP', '0.79');
        $this->resetCurrency();
    }

    public function _after(FunctionalTester $I): void
    {
        unset($_COOKIE[CurrencyContext::COOKIE], $_REQUEST['fct_cart_hash']);
        remove_all_filters(self::AVAILABLE);
        MultiCurrencySettings::save(['enabled' => 'no', 'currencies' => []]);
        RateFixture::clear();
        $this->resetCurrency();
        $this->removeStubCheckoutGateway();
    }

    public function aGbpCartFromBeforeProWasDeactivatedIsPlacedInUsd(FunctionalTester $I): void
    {
        $_COOKIE[CurrencyContext::COOKIE] = 'GBP';
        $this->resetCurrency();
        $cart = $this->buildInstantCart($I, [[$this->buildStockedVariation(5000, 10), 1]]);
        Assert::assertSame('GBP', Cart::query()->find($cart->cart_hash)->checkout_data['fx']['currency'], 'precondition: priced in GBP while Pro was active');

        $this->deactivatePro($cart);

        global $wpdb;
        $ordersBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, ['fct_shown_currency' => 'GBP']));

        Assert::assertSame(423, $status, 'the page showed GBP; the shopper reviews the USD total first. Body: ' . wp_json_encode($body));
        Assert::assertSame($ordersBefore, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders"), 'no GBP order is created');

        $this->resetCurrency();
        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, ['fct_shown_currency' => 'USD']));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'Body: ' . wp_json_encode($body));
        $order = Order::query()->orderBy('id', 'DESC')->first();
        Assert::assertSame('USD', $order->currency);
        Assert::assertSame('1.000000000000', (string) $order->rate);
        Assert::assertSame(5000, (int) $order->subtotal, 'the base price, not £39.50 relabelled');
    }

    public function aCartHeldToGbpIsRefusedWithoutPro(FunctionalTester $I): void
    {
        $cart = $this->buildInstantCart($I, [[$this->buildStockedVariation(5000, 10), 1]]);
        $cart = Cart::query()->find($cart->cart_hash);
        $cart->checkout_data = ['upgrade_data' => ['upgrade_from_order_id' => 1]];
        CartCurrency::force($cart, 'GBP');
        $cart->save();
        Assert::assertSame('GBP', Cart::query()->find($cart->cart_hash)->checkout_data['fx']['currency'], 'precondition: held to GBP while Pro was active');

        $this->deactivatePro($cart);

        global $wpdb;
        $ordersBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, ['fct_shown_currency' => 'GBP']));

        Assert::assertNotSame('success', (string) ($body['status'] ?? ''), 'Body: ' . wp_json_encode($body));
        Assert::assertSame(423, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame($ordersBefore, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders"), 'no order is created');
    }

    /**
     * The shopper's GBP cookie outlives Pro. The checkout form posts the
     * cart hash, which a real request carries in $_REQUEST.
     */
    private function deactivatePro(Cart $cart): void
    {
        $_REQUEST['fct_cart_hash'] = $cart->cart_hash;
        add_filter(self::AVAILABLE, '__return_false');
        $this->resetCurrency();
    }

    private function resetCurrency(): void
    {
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        CurrencyContext::reset();
        CartCurrency::reset();
    }
}
