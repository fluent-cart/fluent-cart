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
use Tests\Support\Factory\CouponFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;
use Tests\Support\RateFixture;

/**
 * place-order and the cart's rate lock. The shopper submits a page priced at
 * the lock; when that lock expired, checkout must refuse with the reload
 * response — even though coupon revalidation and the tax recalculation save
 * the cart (and so re-price it) before the order is built. A lock that was
 * valid when checkout accepted it is used for the whole order.
 * Base USD, GBP at 0.79, moved to 0.81.
 */
class PlaceOrderCurrencyLockCest
{
    use PlacesCheckoutOrders;

    public function _before(FunctionalTester $I): void
    {
        $I->logout();
        $this->registerStubCheckoutGateway();
        // GBP carts are only offered to gateways that declare multi_currency
        GatewayManager::getInstance($this->stubGatewaySlug)->supportedFeatures[] = 'multi_currency';
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP', 'rounding' => 'none']], 'rate_lock_minutes' => 30]);
        RateFixture::set('GBP', '0.79');
        $_COOKIE[CurrencyContext::COOKIE] = 'GBP';
        $this->resetCurrency();
    }

    public function _after(FunctionalTester $I): void
    {
        unset($_COOKIE[CurrencyContext::COOKIE]);
        MultiCurrencySettings::save(['enabled' => 'no', 'currencies' => []]);
        RateFixture::clear();
        $this->resetCurrency();
        $this->removeStubCheckoutGateway();
        $I->disarmTaxModule();
    }

    public function anExpiredLockIsRefusedWhenTheCartHasACoupon(FunctionalTester $I): void
    {
        $cart = $this->gbpCart($I);
        $code = 'LOCK-' . wp_generate_password(6, false);
        (new CouponFactory())->create(['code' => $code, 'amount' => 500]);
        Cart::query()->find($cart->cart_hash)->applyCoupon([$code]);

        $this->expireLockAndMoveRate($cart);

        $this->assertRefusedWithReload($I, $cart);
    }

    public function anExpiredLockIsRefusedWithTaxEnabled(FunctionalTester $I): void
    {
        $I->armTaxModule(['tax_inclusion' => 'excluded']);
        $this->buildStandardTaxRate('US', '10');
        $cart = $this->gbpCart($I);

        $this->expireLockAndMoveRate($cart);

        $this->assertRefusedWithReload($I, $cart);
    }

    public function anAcceptedLockPricesTheWholeOrderEvenIfItExpiresMidCheckout(FunctionalTester $I): void
    {
        $I->armTaxModule(['tax_inclusion' => 'excluded']);
        $this->buildStandardTaxRate('US', '10');
        $cart = $this->gbpCart($I);
        $code = 'HOLD-' . wp_generate_password(6, false);
        (new CouponFactory())->create(['code' => $code, 'amount' => 500]);
        Cart::query()->find($cart->cart_hash)->applyCoupon([$code]);
        RateFixture::set('GBP', '0.81');
        $this->resetCurrency();

        // The lock runs out after checkout accepted it: during the tax
        // recalculation, whose save would re-price an expired lock
        add_action('fluent_cart/cart/cart_data_items_updated', function ($data) {
            if (($data['scope'] ?? '') !== 'payment_method_fee_recalculate') {
                return;
            }
            $checkoutData = $data['cart']->checkout_data;
            $checkoutData['fx']['locked_at'] = gmdate('Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS);
            $data['cart']->checkout_data = $checkoutData;
        }, 1);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, ['fct_shown_currency' => 'GBP']));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'the accepted lock is honoured. Body: ' . wp_json_encode($body));
        $order = Order::query()->orderBy('id', 'DESC')->first();
        Assert::assertSame('GBP', $order->currency);
        Assert::assertSame('0.790000000000', (string) $order->rate, 'the rate the shopper was shown, not 0.81');
        Assert::assertSame(3950, (int) $order->subtotal, '$50.00 at 0.79');
    }

    public function aStaleCheckoutPageIsRefusedAfterAnotherTabRefreshedTheRate(FunctionalTester $I): void
    {
        $cart = $this->gbpCart($I);
        // This tab's checkout page was rendered at 0.79
        $shownQuote = CartCurrency::quoteRevision(Cart::query()->find($cart->cart_hash));

        // Another tab, after the lock ran out, re-priced the same-currency cart at 0.81
        $this->expireLockAndMoveRate($cart);
        Cart::query()->find($cart->cart_hash)->save();
        $this->resetCurrency();
        Assert::assertSame('0.810000000000', Cart::query()->find($cart->cart_hash)->checkout_data['fx']['rate'], 'precondition: a fresh lock at the new rate');

        global $wpdb;
        $ordersBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'fct_shown_currency' => 'GBP',
            'fct_shown_quote'    => $shownQuote,
        ]));

        Assert::assertSame(423, $status, 'the page showed 0.79 prices; the cart is now at 0.81. Body: ' . wp_json_encode($body));
        Assert::assertTrue((bool) ($body['reload'] ?? false));
        Assert::assertSame($ordersBefore, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders"), 'no order is created');
    }

    public function aCheckoutPageShowingTheCurrentQuoteIsAccepted(FunctionalTester $I): void
    {
        $cart = $this->gbpCart($I);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'fct_shown_currency' => 'GBP',
            'fct_shown_quote'    => CartCurrency::quoteRevision(Cart::query()->find($cart->cart_hash)),
        ]));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'Body: ' . wp_json_encode($body));
    }

    public function aSubmissionWithoutAQuoteRevisionStillWorks(FunctionalTester $I): void
    {
        $cart = $this->gbpCart($I);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, ['fct_shown_currency' => 'GBP']));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'an older cached checkout script. Body: ' . wp_json_encode($body));
    }

    private function gbpCart(FunctionalTester $I): Cart
    {
        $variation = $this->buildStockedVariation(5000, 10);
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $cart = Cart::query()->find($cart->cart_hash);
        Assert::assertSame(3950, (int) $cart->cart_data[0]['unit_price'], 'precondition: priced in GBP at 0.79');
        Assert::assertSame('GBP', $cart->checkout_data['fx']['currency']);

        return $cart;
    }

    private function expireLockAndMoveRate(Cart $cart): void
    {
        global $wpdb;
        $cart = Cart::query()->find($cart->cart_hash);
        $checkoutData = $cart->checkout_data;
        $checkoutData['fx']['locked_at'] = gmdate('Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS);
        $wpdb->update($wpdb->prefix . 'fct_carts', ['checkout_data' => json_encode($checkoutData)], ['cart_hash' => $cart->cart_hash]);
        RateFixture::set('GBP', '0.81');
        $this->resetCurrency();
    }

    private function assertRefusedWithReload(FunctionalTester $I, Cart $cart): void
    {
        global $wpdb;
        $ordersBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, ['fct_shown_currency' => 'GBP']));

        Assert::assertSame(423, $status, 'the page was priced at the expired lock: review and retry. Body: ' . wp_json_encode($body));
        Assert::assertTrue((bool) ($body['reload'] ?? false));
        Assert::assertSame($ordersBefore, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders"), 'no order is created');

        $repriced = Cart::query()->find($cart->cart_hash);
        Assert::assertSame('0.810000000000', $repriced->checkout_data['fx']['rate'], 'the cart is re-priced for the reload');
    }

    private function resetCurrency(): void
    {
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        CurrencyContext::reset();
        CartCurrency::reset();
    }
}
