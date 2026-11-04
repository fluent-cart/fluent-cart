<?php

namespace Tests\Integration\Models;

use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\App;
use FluentCart\App\Models\Cart;
use FluentCart\Framework\Support\Arr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\TaxRateFactory;
use Tests\Support\Traits\BuildsTaxFixtures;

/**
 * A fee that a Pro feature saved on the cart (dynamic pricing) is dropped once
 * Pro is inactive: no Pro rule is left to take it off again. This suite runs
 * without Pro.
 */
class CartProFeesWithoutProTest extends DatabaseTestCase
{
    use BuildsTaxFixtures;

    private $proFee = [
        'key'     => 'rule_7',
        'label'   => 'Zia Fee',
        'amount'  => 700,
        'taxable' => true,
        'source'  => 'dynamic-pricing',
        'meta'    => ['rule_type' => 'scratch'],
    ];

    private $coreFee = [
        'key'     => 'handling',
        'label'   => 'Handling',
        'amount'  => 300,
        'taxable' => true,
        'source'  => 'custom',
        'meta'    => [],
    ];

    private $taxListener;

    public function setUp(): void
    {
        parent::setUp();
        $this->assertFalse(App::isProActive(), 'This suite must run without Pro');
        CartResource::resetCartCache();
    }

    public function tearDown(): void
    {
        if ($this->taxListener) {
            remove_action('fluent_cart/cart/cart_data_items_updated', $this->taxListener);
            $this->taxListener = null;
        }
        CartResource::resetCartCache();
        delete_option('fluent_cart_tax_configuration_settings');
        parent::tearDown();
    }

    public function test_a_pro_fee_is_not_charged_or_shown_without_pro(): void
    {
        $cart = $this->cartWithFees();

        $this->assertSame(['handling'], array_column($cart->getFees(), 'key'), 'Only the core fee is listed');
        $this->assertSame(300, $cart->getFeeTotal(), 'The Pro fee is not charged');
    }

    public function test_the_pro_fee_is_not_taxed_without_pro(): void
    {
        $cart = $this->taxedCartWithFees();

        $this->makeTaxModule()->recalculateTax(['cart' => $cart]);

        $reloaded = Cart::query()->find($cart->cart_hash);
        $this->assertFeeTaxCoversOnlyTheCoreFee($reloaded);
        $this->assertSame(['handling'], array_column($this->storedFees($cart), 'key'));
    }

    public function test_loading_the_cart_drops_the_pro_fee_and_retaxes_it(): void
    {
        $cart = $this->taxedCartWithFees([
            'tax_data' => ['fee_tax' => 100, 'tax_total' => 600, 'exclusive_tax_total' => 500],
        ]);

        $taxModule = $this->makeTaxModule();
        $this->taxListener = [$taxModule, 'recalculateTax'];
        add_action('fluent_cart/cart/cart_data_items_updated', $this->taxListener);

        $loaded = CartResource::get(['hash' => $cart->cart_hash]);

        $this->assertSame(300, $loaded->getFeeTotal());
        $this->assertSame(['handling'], array_column($this->storedFees($cart), 'key'), 'The drop is saved');

        $reloaded = Cart::query()->find($cart->cart_hash);
        $this->assertFeeTaxCoversOnlyTheCoreFee($reloaded);
    }

    public function test_a_fee_added_through_the_filter_goes_once_the_filter_stops_adding_it(): void
    {
        $addFee = function ($fees) {
            $fees[] = ['key' => 'surcharge', 'label' => 'Surcharge', 'amount' => 200, 'source' => 'acme-addon'];
            return $fees;
        };
        $taxModule = $this->makeTaxModule();
        $cart = $this->taxedCartWithFees(['fees' => [$this->coreFee]]);

        add_filter('fluent_cart/cart/fees', $addFee);
        try {
            $taxModule->recalculateTax(['cart' => $cart]);
        } finally {
            remove_filter('fluent_cart/cart/fees', $addFee);
        }

        $stored = array_column($this->storedFees($cart), 'dynamic', 'key');
        $this->assertSame(['surcharge' => true], $stored, 'The filter fee is saved marked as dynamic, the core fee is not');

        $reloaded = Cart::query()->find($cart->cart_hash);
        $this->assertSame(['handling'], array_column($reloaded->getFees(), 'key'), 'Without the filter the fee is gone');
        $this->assertTrue($reloaded->refreshStaleDynamicFees(), 'The stale fee triggers a recalculation');
    }

    public function test_a_locked_cart_keeps_the_fees_its_order_was_placed_with(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $cart = (new CartFactory())->create(['order_id' => $order->id, 'cart_group' => 'instant']);
        $cart = $this->savedWhileProWasActive($cart, ['is_locked' => 'yes', 'fees' => [$this->proFee, $this->coreFee]]);

        $cart->save();
        $reloaded = Cart::query()->find($cart->cart_hash);

        $this->assertSame(1000, $reloaded->getFeeTotal(), 'A custom payment pays what the order was charged');
        $this->assertCount(2, $this->storedFees($cart));
    }

    private function cartWithFees(array $checkoutData = []): Cart
    {
        $cart = (new CartFactory())->create(['cart_group' => 'instant']);

        return $this->savedWhileProWasActive($cart, array_merge(['fees' => [$this->proFee, $this->coreFee]], $checkoutData));
    }

    private function taxedCartWithFees(array $checkoutData = []): Cart
    {
        $this->enableTax();
        $this->resetTaxRuntimeState();
        $standard = $this->ensureStandardTaxClass();
        (new TaxRateFactory())->create(['class_id' => (int) $standard->id, 'country' => 'US', 'rate' => '10']);
        $product = $this->makeProductWithClass((int) $standard->id);

        $cart = (new CartFactory())->withCartItems([
            $this->buildCartLineForProduct((int) $product->ID, ['unit_price' => 5000]),
        ])->create(['cart_group' => 'instant']);

        return $this->savedWhileProWasActive($cart, array_merge([
            'form_data' => ['billing_country' => 'US'],
            'fees'      => [$this->proFee, $this->coreFee],
        ], $checkoutData));
    }

    // Written past the model, the way the row looks after a save with Pro active.
    private function savedWhileProWasActive(Cart $cart, array $checkoutData): Cart
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'fct_carts',
            ['checkout_data' => wp_json_encode($checkoutData)],
            ['cart_hash' => $cart->cart_hash]
        );

        return Cart::query()->find($cart->cart_hash);
    }

    // Rates other tests leave behind can stack on US, so compare the fee's tax
    // with the 5000 item's rather than pinning a percentage.
    private function assertFeeTaxCoversOnlyTheCoreFee(Cart $cart): void
    {
        $feeTax = (int) Arr::get($cart->checkout_data, 'tax_data.fee_tax');
        $itemTax = (int) Arr::get($cart->checkout_data, 'tax_data.tax_total') - $feeTax;

        $this->assertGreaterThan(0, $itemTax, 'The item is taxed');
        $this->assertSame(intdiv($itemTax * 300, 5000), $feeTax, 'Only the 300 core fee is taxed');
    }

    // Straight from the row, past the model accessor.
    private function storedFees(Cart $cart): array
    {
        return (array) Arr::get($this->storedCheckoutData($cart), 'fees', []);
    }

    private function storedCheckoutData(Cart $cart): array
    {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT checkout_data FROM {$wpdb->prefix}fct_carts WHERE cart_hash = %s",
            $cart->cart_hash
        ));

        return (array) json_decode((string) $raw, true);
    }
}
