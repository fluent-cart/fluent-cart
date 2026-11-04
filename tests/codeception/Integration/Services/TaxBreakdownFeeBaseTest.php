<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\Renderer\Receipt\TaxSummaryHelper;
use FluentCart\App\Services\Renderer\TaxRenderer;
use FluentCart\Framework\Support\Arr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTaxRateFactory;
use Tests\Support\Factory\TaxRateFactory;
use Tests\Support\Traits\BuildsTaxFixtures;

/**
 * The tax breakdown's taxable base when fees are taxed.
 *
 * Items $215 and fees $30 + $21.50 at 15%: the base is $266.50. Working it
 * back from the rounded tax ($39.98 / 15%) gives $266.53 instead.
 */
class TaxBreakdownFeeBaseTest extends DatabaseTestCase
{
    use BuildsTaxFixtures;

    private $rate;

    public function setUp(): void
    {
        parent::setUp();

        $this->enableTax();
        $this->resetTaxRuntimeState();
        $standard = $this->ensureStandardTaxClass();
        $this->rate = (new TaxRateFactory())->create([
            'class_id' => (int) $standard->id,
            // A country no other test taxes, so a leftover rate cannot add to this one.
            'country'  => 'IS',
            'rate'     => '15',
            'name'     => 'VAT',
        ]);
    }

    public function tearDown(): void
    {
        delete_option('fluent_cart_tax_configuration_settings');

        parent::tearDown();
    }

    public function test_checkout_breakdown_shows_the_exact_base_with_taxed_fees(): void
    {
        $product = $this->makeProductWithClass((int) $this->ensureStandardTaxClass()->id);
        $cart = (new CartFactory())->withCartItems([
            $this->buildCartLineForProduct((int) $product->ID, ['unit_price' => 21500]),
        ])->create([
            'checkout_data' => [
                'form_data' => ['billing_country' => 'IS'],
                'fees'      => [
                    ['key' => 'general', 'label' => 'General Fee', 'amount' => 3000, 'taxable' => true, 'source' => 'custom'],
                    ['key' => 'ten', 'label' => 'Ten percent', 'amount' => 2150, 'taxable' => true, 'source' => 'custom'],
                ],
            ],
        ]);

        $module = $this->makeTaxModule();
        $fill = $module->calculateCartTax(['cart_data' => $cart->cart_data, 'checkout_data' => $cart->checkout_data], $cart);
        $this->assertSame(3998, (int) Arr::get($fill, 'checkout_data.tax_data.tax_total'), 'Fixture: tax includes both fees');

        $cart->cart_data = $fill['cart_data'];
        $cart->checkout_data = $fill['checkout_data'];

        ob_start();
        (new TaxRenderer($module))->renderTaxSummaryBox($cart);
        $html = ob_get_clean();

        $this->assertStringContainsString('266.50', $html);
        $this->assertStringNotContainsString('266.53', $html);
    }

    public function test_order_breakdown_shows_the_exact_base_with_taxed_fees(): void
    {
        $rateId = (int) $this->rate->id;
        $rates = function ($tax, $taxable) use ($rateId) {
            return [['rate_id' => $rateId, 'label' => 'VAT', 'rate_percent' => '15', 'tax_amount' => $tax, 'taxable_amount' => $taxable]];
        };

        $order = (new OrderFactory())->create([
            'customer_id'  => (new CustomerFactory())->create()->id,
            'tax_behavior' => 1,
            'tax_total'    => 3998,
        ]);
        (new OrderItemFactory())->create((int) $order->id, [
            'subtotal'   => 21500,
            'line_total' => 21500,
            'tax_amount' => 3225,
            'line_meta'  => ['tax_config' => ['inclusive' => false, 'rates' => $rates(3225, 21500)]],
        ]);
        (new OrderTaxRateFactory())->create([
            'order_id'    => $order->id,
            'tax_rate_id' => $rateId,
            'order_tax'   => 3998,
            'total_tax'   => 3998,
            'meta'        => ['label' => 'VAT', 'rate_percent' => '15', 'taxable_amount' => 26650, 'inclusive' => false],
        ]);
        $order->updateMeta('fee_tax', 773);
        $order->updateMeta('fee_tax_lines', [
            ['label' => 'General Fee', 'tax_amount' => 450, 'inclusive' => false, 'rates' => $rates(450, 3000)],
            ['label' => 'Ten percent', 'tax_amount' => 323, 'inclusive' => false, 'rates' => $rates(323, 2150)],
        ]);

        $summary = TaxSummaryHelper::computeTaxSummary(Order::query()->find($order->id));

        $this->assertSame(26650, (int) Arr::get($summary, 'foldedRateLines.0.base'));
    }
}
