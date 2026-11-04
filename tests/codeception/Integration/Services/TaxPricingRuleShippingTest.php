<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Cart;
use FluentCart\App\Models\TaxRate;
use FluentCart\Framework\Support\Arr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\TaxRateFactory;
use Tests\Support\Traits\BuildsTaxFixtures;

/**
 * Shipping shares taxed after a shipping rule must add up to exactly the
 * discounted charge, however the per-line rounding falls.
 */
class TaxPricingRuleShippingTest extends DatabaseTestCase
{
    use BuildsTaxFixtures;

    /** @var callable|null */
    private $shippingRule = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->enableTax();
        $this->resetTaxRuntimeState();
        $standard = $this->ensureStandardTaxClass();
        // Earlier classes whose code commits its own transaction leave rates
        // on the shared 'standard' class; one more US rate would double the
        // tax. Deleted inside this test's transaction, so it rolls back.
        TaxRate::query()->where('class_id', (int) $standard->id)->delete();
        // 100%, so the shipping tax equals the shipping base it was worked out on.
        (new TaxRateFactory())->create(['class_id' => (int) $standard->id, 'country' => 'US', 'rate' => '100']);
    }

    public function tearDown(): void
    {
        if ($this->shippingRule) {
            remove_filter('fluent_cart/checkout/order_shipping_charge', $this->shippingRule, 10);
            $this->shippingRule = null;
        }
        delete_option('fluent_cart_tax_configuration_settings');

        parent::tearDown();
    }

    public function test_small_shares_never_add_up_to_more_than_the_discounted_charge()
    {
        // Four 1-cent shares, halved: each rounds to 1 on its own, which is 4 cents for a 2-cent charge.
        $this->halveShipping();
        $cart = $this->cartWithShares([1, 1, 1, 1]);

        $fill = $this->makeTaxModule()->calculateCartTax($this->fillData($cart), $cart);

        $this->assertSame(2, (int) Arr::get($fill, 'checkout_data.tax_data.shipping_tax'), 'Taxed on the 2 cents charged');
    }

    public function test_uneven_shares_add_up_to_the_discounted_charge()
    {
        $this->halveShipping();
        $cart = $this->cartWithShares([333, 333, 334]);

        $fill = $this->makeTaxModule()->calculateCartTax($this->fillData($cart), $cart);

        $this->assertSame(500, (int) Arr::get($fill, 'checkout_data.tax_data.shipping_tax'), 'Taxed on the 500 charged');
        $this->assertSame(
            [333, 333, 334],
            array_map('intval', array_column($fill['cart_data'], 'shipping_charge')),
            'The saved lines keep their real shares'
        );
    }

    private function halveShipping(): void
    {
        $this->shippingRule = function ($charge) {
            return intdiv((int) $charge, 2);
        };
        add_filter('fluent_cart/checkout/order_shipping_charge', $this->shippingRule, 10, 2);
    }

    private function cartWithShares(array $shares): Cart
    {
        $product = $this->makeProductWithClass((int) $this->ensureStandardTaxClass()->id);

        $lines = [];
        foreach ($shares as $share) {
            $lines[] = $this->buildCartLineForProduct((int) $product->ID, [
                'unit_price'       => 1000,
                'fulfillment_type' => 'physical',
                'shipping_charge'  => $share,
            ]);
        }

        return (new CartFactory())->withCartItems($lines)->create([
            'checkout_data' => ['form_data' => ['billing_country' => 'US']],
        ]);
    }

    private function fillData(Cart $cart): array
    {
        return [
            'cart_data'     => $cart->cart_data,
            'checkout_data' => $cart->checkout_data,
        ];
    }
}
