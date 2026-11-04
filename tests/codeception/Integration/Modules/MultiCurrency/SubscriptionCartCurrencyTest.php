<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\MultiCurrency\Services\PriceConverter;
use FluentCart\App\Modules\StoreManagedRenewal\Services\RenewalService;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;
use Tests\Support\RateFixture;

/**
 * Every payment toward an existing subscription — renewal, early installment,
 * reactivation — is in the subscription's currency, else its parent order's
 * (a subscription that predates the currency column). Base USD, GBP 0.79.
 */
class SubscriptionCartCurrencyTest extends DatabaseTestCase
{
    use MakesMultiCurrencyAvailable;

    public function setUp(): void
    {
        parent::setUp();
        $this->makeMultiCurrencyAvailable();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']]]);
        RateFixture::set('GBP', '0.79');
        $this->reset();
    }

    public function tearDown(): void
    {
        unset($_COOKIE[CurrencyContext::COOKIE], $_REQUEST['fct_cart_hash']);
        $this->reset();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_legacy_subscription_currency_falls_back_to_the_parent_order_not_the_visitor(): void
    {
        $subscription = $this->makeLegacySubscription('GBP', '0.79');

        $presentEur = function ($settings) {
            $settings['currency'] = 'EUR';
            return $settings;
        };
        add_filter('fluent_cart/global_currency_setting', $presentEur, 99);

        try {
            $this->assertSame('GBP', Subscription::query()->find($subscription->id)->currency);
        } finally {
            remove_filter('fluent_cart/global_currency_setting', $presentEur, 99);
        }
    }

    public function test_early_payment_cart_stays_in_a_legacy_subscriptions_parent_order_currency(): void
    {
        $_COOKIE[CurrencyContext::COOKIE] = 'USD';
        $subscription = $this->makeLegacySubscription('GBP', '0.79');

        $cart = $this->subscriptionCart($subscription, 3995);

        $this->assertSame(3995, (int) $cart->cart_data[0]['unit_price'], 'already in GBP, never converted again');
        $this->assertSame('GBP', $cart->checkout_data['fx']['currency']);
        $this->assertSame('0.790000000000', $cart->checkout_data['fx']['rate']);

        $snapshot = CartCurrency::checkoutSnapshot($cart, 'GBP');
        $this->assertFalse(is_wp_error($snapshot));
        $this->assertSame('GBP', $snapshot['currency']);
        $this->assertSame('0.790000000000', $snapshot['rate']);
    }

    public function test_catalogue_priced_reactivation_is_converted_into_the_subscriptions_currency(): void
    {
        $_COOKIE[CurrencyContext::COOKIE] = 'USD';
        $subscription = $this->makeLegacySubscription('GBP', '0.79');

        $cart = $this->subscriptionCart($subscription, 4980, true);

        $this->assertSame(3934, (int) $cart->cart_data[0]['unit_price'], 'base catalogue price at today\'s GBP rate');
        $this->assertSame('GBP', $cart->checkout_data['fx']['currency']);
    }

    public function test_store_managed_renewal_of_a_legacy_subscription_uses_the_parent_order_rate(): void
    {
        $subscription = $this->makeLegacySubscription('GBP', '0.79', [
            'collection_method' => 'manual',
            'billing_interval'  => 'daily',
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);

        $created = RenewalService::createRenewalOrders($subscription);

        $this->assertFalse(is_wp_error($created));
        $invoice = Order::query()->find($created[0]->id);
        $this->assertSame('GBP', $invoice->currency);
        $this->assertSame('0.790000000000', (string) $invoice->rate);
    }

    public function test_gateway_renewal_of_a_legacy_subscription_uses_the_parent_order_rate(): void
    {
        $subscription = $this->makeLegacySubscription('GBP', '0.79');

        $transaction = SubscriptionService::recordRenewalPayment([
            'subscription_id'  => $subscription->id,
            'vendor_charge_id' => 'ch_mc_' . wp_generate_password(8, false),
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'payment_method'   => 'offline_payment',
            'payment_mode'     => 'test',
            'total'            => 3995,
        ], $subscription);

        $this->assertFalse(is_wp_error($transaction));
        $renewal = Order::query()->find($transaction->order_id);
        $this->assertSame('GBP', $renewal->currency);
        $this->assertSame('0.790000000000', (string) $renewal->rate);
    }

    public function test_a_catalogue_priced_hold_returns_the_quote_its_line_is_priced_at(): void
    {
        $_COOKIE[CurrencyContext::COOKIE] = 'USD';
        $subscription = $this->makeLegacySubscription('GBP', '0.79');

        [$cart, $quote] = $this->heldCart($subscription, 4980, true);

        $this->assertIsArray($quote, 'the caller converts the renewal amount at this quote');
        $this->assertSame('GBP', $quote['currency']);
        $this->assertSame(3934, PriceConverter::toPrice(4980, $quote));
        $this->assertSame(3934, (int) $cart->cart_data[0]['unit_price'], 'the line is priced at the same quote');
    }

    public function test_a_zero_decimal_catalogue_priced_hold_is_whole_yen(): void
    {
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP'], ['code' => 'JPY']]]);
        MultiCurrencySettings::clearCache();
        RateFixture::set('JPY', '149.5');
        ExchangeRateService::flushCache();
        $this->reset();
        $_COOKIE[CurrencyContext::COOKIE] = 'USD';
        $subscription = $this->makeLegacySubscription('JPY', '149.5');

        [$cart, $quote] = $this->heldCart($subscription, 4980, true);

        $this->assertIsArray($quote);
        $this->assertSame('JPY', $quote['currency']);
        // $49.80 at 149.5 = ¥7,445.10 -> ¥7,445
        $this->assertSame(744500, PriceConverter::toPrice(4980, $quote));
        $this->assertSame(744500, (int) $cart->cart_data[0]['unit_price']);
    }

    public function test_a_pinned_hold_returns_the_subscriptions_own_rate(): void
    {
        $subscription = $this->makeLegacySubscription('GBP', '0.79');

        [, $quote] = $this->heldCart($subscription, 3995);

        $this->assertIsArray($quote);
        $this->assertSame('GBP', $quote['currency']);
        $this->assertSame('0.790000000000', $quote['rate']);
        $this->assertTrue((bool) $quote['pinned']);
    }

    public function test_a_catalogue_priced_hold_without_a_trusted_rate_is_refused_and_leaves_the_cart_alone(): void
    {
        $_COOKIE[CurrencyContext::COOKIE] = 'USD';
        // Parent order at rate 1: a GBP label on unknown money, no trusted rate
        $subscription = $this->makeLegacySubscription('GBP', '1');
        $this->assertFalse(CurrencySnapshot::hasTrustedRate(CurrencySnapshot::fromSubscription($subscription)), 'fixture has no trusted rate');

        $noGbpRate = function ($rate, $args) {
            return $args['quote'] === 'GBP' ? null : $rate;
        };
        add_filter('fluent_cart/currency/exchange_rate', $noGbpRate, 99, 2);
        ExchangeRateService::flushCache();

        try {
            $cart = $this->unsavedCart($subscription, 4980);
            $quote = CartCurrency::holdToSubscription($cart, $subscription, true);
        } finally {
            remove_filter('fluent_cart/currency/exchange_rate', $noGbpRate, 99);
            ExchangeRateService::flushCache();
        }

        $this->assertNull($quote, 'never a base-currency cart beside a GBP subscription');
        $this->assertArrayNotHasKey('fx', (array) $cart->checkout_data, 'the cart is not held to any currency');
    }

    /**
     * @return array{0: Cart, 1: array|null} the saved cart and the quote holdToSubscription() returned
     */
    private function heldCart(Subscription $subscription, int $itemPrice, bool $basePriced = false): array
    {
        $cart = $this->unsavedCart($subscription, $itemPrice);
        $quote = CartCurrency::holdToSubscription($cart, $subscription, $basePriced);
        $cart->save();
        $this->reset();

        return [Cart::query()->find($cart->cart_hash), $quote];
    }

    private function unsavedCart(Subscription $subscription, int $itemPrice): Cart
    {
        $cart = CartHelper::generateCartFromCustomVariation([
            'id'           => $subscription->variation_id,
            'post_id'      => $subscription->product_id,
            'object_id'    => $subscription->variation_id,
            'item_price'   => $itemPrice,
            'payment_type' => 'onetime',
            'post_title'   => 'Reactivation',
            'other_info'   => ['payment_type' => 'onetime'],
        ]);
        $cart->cart_group = 'instant';
        $cart->checkout_data = ['is_locked' => 'yes'];

        return $cart;
    }

    private function subscriptionCart(Subscription $subscription, int $itemPrice, bool $basePriced = false): Cart
    {
        $cart = CartHelper::generateCartFromCustomVariation([
            'id'           => $subscription->variation_id,
            'post_id'      => $subscription->product_id,
            'object_id'    => $subscription->variation_id,
            'item_price'   => $itemPrice,
            'payment_type' => 'onetime',
            'post_title'   => 'Early payment',
            'other_info'   => ['payment_type' => 'onetime'],
        ]);
        $cart->cart_group = 'instant';
        $cart->checkout_data = ['is_locked' => 'yes'];
        CartCurrency::holdToSubscription($cart, $subscription, $basePriced);
        $cart->save();
        $this->reset();

        return Cart::query()->find($cart->cart_hash);
    }

    /**
     * A subscription as it is before the subscription_currency backfill:
     * no currency column value, no config.fx.
     */
    private function makeLegacySubscription(string $currency, string $rate, array $overrides = []): Subscription
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())
            ->withVariantAttributes(['payment_type' => Status::ORDER_TYPE_SUBSCRIPTION])
            ->create();
        $variant = $product->variants->first();
        $order = (new OrderFactory())->withAddresses()->create([
            'customer_id' => $customer->id,
            'type'        => Status::ORDER_TYPE_SUBSCRIPTION,
            'currency'    => $currency,
            'rate'        => $rate,
            'mode'        => 'test',
        ]);

        (new OrderItemFactory())->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'payment_type' => Status::ORDER_TYPE_SUBSCRIPTION,
        ]);

        $subscription = (new SubscriptionFactory())->create(array_merge([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $variant->id,
        ], $overrides));

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_subscriptions', ['currency' => '', 'config' => '[]'], ['id' => $subscription->id]);

        return Subscription::query()->find($subscription->id);
    }

    private function reset(): void
    {
        CurrencyContext::reset();
        CartCurrency::reset();
    }
}
