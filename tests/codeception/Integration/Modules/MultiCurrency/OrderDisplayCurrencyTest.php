<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Models\Order;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Services\ShortCodeParser\SmartCodeParser;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTaxRateFactory;

/**
 * An order's emails, receipt and invoice show its own currency — a GBP
 * order's email sent from a background job (store currency USD) must not
 * print "$" (dev-docs/multi-currency/plan.md §1.5 B1). Base USD.
 */
class OrderDisplayCurrencyTest extends DatabaseTestCase
{
    public function test_an_email_items_table_renders_in_the_orders_currency(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'  => $customer->id,
            'currency'     => 'GBP',
            'subtotal'     => 3995,
            'total_amount' => 3995,
            'config'       => ['fx' => CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL])],
        ]);
        (new OrderItemFactory())->create($order->id, ['unit_price' => 3995, 'subtotal' => 3995, 'line_total' => 3995]);
        $order = Order::query()->with(['order_items'])->find($order->id);

        $html = SmartCodeParser::make()->parseTemplate('{{order.items_table}}', ['order' => $order]);

        $this->assertStringContainsString('£39.95', html_entity_decode($html));
        $this->assertStringNotContainsString('$39.95', html_entity_decode($html));
        $this->assertSame('USD', CurrencyContext::current(), 'the order currency applies only while rendering it');
    }

    public function test_the_receipt_page_renders_in_the_orders_currency(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'  => $customer->id,
            'currency'     => 'GBP',
            'subtotal'     => 3995,
            'total_amount' => 3995,
            'config'       => ['fx' => CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL])],
        ]);
        (new OrderItemFactory())->create($order->id, ['unit_price' => 3995, 'subtotal' => 3995, 'line_total' => 3995]);
        $order = Order::query()->with(['order_items'])->find($order->id);

        ob_start();
        (new \FluentCart\App\Services\Renderer\Receipt\ReceiptRenderer(['order' => $order]))->render();
        $html = html_entity_decode((string) ob_get_clean());

        $this->assertStringContainsString('£39.95', $html);
        $this->assertStringNotContainsString('$39.95', $html);
        $this->assertSame('USD', CurrencyContext::current());
    }

    /**
     * The tax line label ("VAT (20%) on £39.34") names the taxable amount in
     * the order's currency wherever it is read — admin (base) or the
     * customer portal (visitor's currency) — not only inside withCurrency().
     */
    public function test_a_tax_line_label_names_the_taxable_amount_in_the_orders_currency(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'  => $customer->id,
            'currency'     => 'GBP',
            'tax_behavior' => 1,
            'subtotal'     => 3934,
            'tax_total'    => 787,
            'total_amount' => 4721,
            'config'       => ['fx' => CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL])],
        ]);
        (new OrderTaxRateFactory())->create([
            'order_id'    => $order->id,
            'tax_rate_id' => 1,
            'order_tax'   => 787,
            'total_tax'   => 787,
            'meta'        => ['label' => 'VAT', 'rate_percent' => 20, 'taxable_amount' => 3934, 'inclusive' => false],
        ]);
        $order = Order::query()->with(['orderTaxRates'])->find($order->id);

        $this->assertSame('USD', CurrencyContext::current(), 'read outside any order rendering scope');
        $lines = $order->getDisplayTaxLines();

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('on £39.34', $lines[0]['label']);
        $this->assertStringNotContainsString('$', $lines[0]['label']);
    }

    public function test_the_rendering_currency_nests_and_unwinds(): void
    {
        $seen = CurrencyContext::withCurrency('GBP', function () {
            $inner = CurrencyContext::withCurrency('JPY', function () {
                return CurrencyContext::current();
            });

            return [CurrencyContext::current(), $inner];
        });

        $this->assertSame(['GBP', 'JPY'], $seen);
        $this->assertSame('USD', CurrencyContext::current());
    }
}
