<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Order;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\Renderer\Receipt\TaxSummaryHelper;
use FluentCart\App\Services\Renderer\TaxRenderer;
use FluentCart\Framework\Support\Arr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\TaxRateFactory;
use Tests\Support\Traits\BuildsTaxFixtures;

/**
 * The tax breakdown's taxable base when shipping is taxed.
 *
 * Item $69.00 and shipping $6.90 at 4.75%: the base is $75.90. Working the
 * shipping part back from its rounded tax (33 / 4.75%) gives $75.95 instead.
 */
class TaxBreakdownShippingBaseTest extends DatabaseTestCase
{
    use BuildsTaxFixtures;

    public function setUp(): void
    {
        parent::setUp();

        $this->enableTax();
        $this->resetTaxRuntimeState();
        $this->ensureStandardTaxClass();
    }

    public function tearDown(): void
    {
        delete_option('fluent_cart_tax_configuration_settings');

        parent::tearDown();
    }

    public function test_checkout_breakdown_shows_the_exact_base_with_taxed_shipping(): void
    {
        $this->makeRate();
        list($cart, $module) = $this->taxedCart();

        $this->assertSame(33, (int) Arr::get($cart->checkout_data, 'tax_data.shipping_tax'), 'Fixture: shipping is taxed');

        ob_start();
        (new TaxRenderer($module))->renderTaxSummaryBox($cart);
        $html = ob_get_clean();

        $this->assertStringContainsString('75.90', $html);
        $this->assertStringNotContainsString('75.95', $html);
    }

    public function test_order_breakdown_shows_the_exact_base_with_taxed_shipping(): void
    {
        $this->makeRate();

        $this->assertSame(7590, $this->placedOrderBase());
    }

    public function test_order_breakdown_uses_the_shipping_charge_with_a_shipping_rate_override(): void
    {
        // Shipping is taxed at 10%, not the item rate, so its 69 cents of tax
        // divided by 4.75% would add $14.53 instead of $6.90.
        $this->makeRate(['for_shipping' => '10']);

        $this->assertSame(7590, $this->placedOrderBase());
    }

    public function test_order_breakdown_uses_the_net_shipping_charge_when_prices_include_tax(): void
    {
        $this->enableTax('included');
        $this->resetTaxRuntimeState();
        $this->makeRate();

        // Net item 6587 (6900 - 313) + net shipping 659 (690 - 31). From the
        // rounded tax, the shipping part would be 31 / 4.75% = 653.
        $this->assertSame(7246, $this->placedOrderBase());
    }

    public function test_order_breakdown_includes_earlier_tax_in_a_compound_shipping_base(): void
    {
        $this->makeRate();
        $this->makeRate(['name' => 'County Tax', 'rate' => '2', 'priority' => 2, 'is_compound' => 1]);

        $rows = $this->placedOrderRows();
        $bases = array_column($rows, 'base');

        $this->assertSame(7590, (int) $bases[0]);
        // Compound base: item 6900 + 328 state tax, shipping 690 + 33 state tax.
        // From the rounded tax, the shipping part would be 14 / 2% = 700.
        $this->assertSame(7951, (int) $bases[1]);
    }

    private function makeRate(array $overrides = [])
    {
        return (new TaxRateFactory())->create(array_merge([
            'class_id' => (int) $this->ensureStandardTaxClass()->id,
            // A country no other test taxes, so a leftover rate cannot add to this one.
            'country'  => 'IS',
            'rate'     => '4.75',
            'name'     => 'State Tax',
        ], $overrides));
    }

    private function taxedCart(): array
    {
        $product = $this->makeProductWithClass((int) $this->ensureStandardTaxClass()->id);
        $line = $this->buildCartLineForProduct((int) $product->ID, ['unit_price' => 6900]);
        $line['shipping_charge'] = 690;

        $cart = (new CartFactory())->withCartItems([$line])->create([
            'checkout_data' => ['form_data' => ['billing_country' => 'IS']],
        ]);

        $module = $this->makeTaxModule();
        $fill = $module->calculateCartTax(['cart_data' => $cart->cart_data, 'checkout_data' => $cart->checkout_data], $cart);
        $cart->cart_data = $fill['cart_data'];
        $cart->checkout_data = $fill['checkout_data'];

        return [$cart, $module];
    }

    private function placedOrderBase(): int
    {
        return (int) Arr::get($this->placedOrderRows(), '0.base');
    }

    private function placedOrderRows(): array
    {
        list($cart) = $this->taxedCart();
        $taxData = (array) Arr::get($cart->checkout_data, 'tax_data', []);
        $taxLines = (array) Arr::get($taxData, 'tax_lines', []);
        $cartLine = (array) Arr::get($cart->cart_data, '0', []);

        $order = (new OrderFactory())->create([
            'customer_id'  => (new CustomerFactory())->create()->id,
            'tax_behavior' => 1,
            'shipping_tax' => (int) $taxData['shipping_tax'],
            'tax_total'    => (int) $taxData['tax_total'],
        ]);
        (new OrderItemFactory())->create((int) $order->id, [
            'subtotal'   => 6900,
            'line_total' => 6900,
            'tax_amount' => (int) Arr::get($cartLine, 'tax_amount', 0),
            'line_meta'  => ['tax_config' => (array) Arr::get($cartLine, 'line_meta.tax_config', [])],
        ]);

        // Same call the order placement makes with the checkout's tax data.
        TaxModule::persistTaxRates(
            $order->id,
            $taxLines,
            [],
            (int) $taxData['shipping_tax'],
            (array) Arr::get($taxData, 'shipping_tax_lines', [])
        );

        $summary = TaxSummaryHelper::computeTaxSummary(Order::query()->find($order->id));

        return (array) Arr::get($summary, 'foldedRateLines', []);
    }
}
