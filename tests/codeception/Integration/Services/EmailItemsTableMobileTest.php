<?php

namespace Tests\Integration\Services;

use FluentCart\App\App;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;

/**
 * The order email's items table on a phone (ticket 5655).
 *
 * A long variation title and plan label wrapped word by word inside 999px-radius
 * pills and turned into tall ovals, because a fixed Qty column took the item's width.
 */
class EmailItemsTableMobileTest extends WPTestCase
{
    private function renderItem(array $item): string
    {
        $order = (new OrderFactory())->withAttributes([
            'customer_id' => (new CustomerFactory())->create()->id,
        ])->create();

        (new OrderItemFactory())->create($order->id, $item);

        return (string) App::make('view')->make('emails.parts.items_table', ['order' => $order->fresh()]);
    }

    private function renderInstallmentItem(): string
    {
        return $this->renderItem([
            'post_title'   => 'TRIP — Founding Client Enrollment',
            'title'        => 'Two payments (2nd and final payment charged 1 month later)',
            'quantity'     => 3,
            'payment_type' => 'subscription',
            'other_info'   => [
                'payment_type'    => 'subscription',
                'times'           => '2',
                'repeat_interval' => 'monthly',
                'installment'     => 'yes',
            ],
        ]);
    }

    private function renderShell(string $body): string
    {
        return (string) App::make('view')->make('emails.general_template', [
            'emailBody'   => $body,
            'preheader'   => '',
            'header'      => '',
            'emailFooter' => '',
        ]);
    }

    public function test_wrapped_labels_keep_a_small_radius_instead_of_an_oval()
    {
        $html = $this->renderInstallmentItem();

        $this->assertStringContainsString('Two payments (2nd and final payment charged 1 month later)', $html, 'precondition: the variation label renders');
        $this->assertStringContainsString('for 2', $html, 'precondition: the plan label renders');
        $this->assertStringNotContainsString('border-radius:999px', $html, 'A wrapped label must not turn into an oval');
    }

    public function test_phones_put_the_quantity_in_front_of_the_variation_as_plain_text()
    {
        $html = $this->renderInstallmentItem();

        $this->assertSame(2, substr_count($html, 'class="fct_items_qty"'), 'The Qty header and cell can be hidden on phones');
        $this->assertSame(1, preg_match('/class="fct_items_qty_prefix" style="([^"]*)"[^>]*>(.*?)<\/span>/s', $html, $prefix), 'The variation line carries a quantity prefix');
        $this->assertStringContainsString('display:none', $prefix[1], 'The prefix stays hidden where <style> is not read');
        $this->assertStringContainsString('Qty: 3', strip_tags($prefix[2]), 'The prefix shows the quantity');
        $this->assertSame(1, substr_count($html, 'fct_items_label'), 'Only the variation label turns into plain text on phones');
        $this->assertMatchesRegularExpression('/<span style="display:inline-block;background-color:rgb\(238,242,255\)[^"]*">[^<]*for 2/', $html, 'The plan label keeps its background everywhere');
        $this->assertStringNotContainsString('fct_items_qty_inline', $html, 'No second quantity line when the variation line has it');

        $shell = $this->renderShell($html);
        $this->assertMatchesRegularExpression('/max-width: 480px\).*\.fct_items_qty\s*\{\s*display: none !important/s', $shell, 'Phones hide the Qty column');
        $this->assertMatchesRegularExpression('/\.fct_items_qty_prefix\s*\{\s*display: inline !important/', $shell, 'Phones show the quantity prefix');
        $this->assertMatchesRegularExpression('/\.fct_items_label\s*\{[^}]*background: none !important/', $shell, 'Phones show the labels as plain text');
    }

    public function test_an_item_without_a_variation_gets_its_own_quantity_line()
    {
        $html = $this->renderItem(['post_title' => 'TRIP — Founding Client Enrollment', 'title' => 'TRIP — Founding Client Enrollment', 'quantity' => 2]);

        $this->assertSame(1, preg_match('/class="fct_items_qty_inline" style="([^"]*)"[^>]*>(.*?)<\/p>/s', $html, $line), 'The item gets a quantity line');
        $this->assertStringContainsString('display:none', $line[1], 'The line stays hidden where <style> is not read');
        $this->assertStringContainsString('Qty: 2', strip_tags($line[2]), 'The line shows the quantity');
        $this->assertStringNotContainsString('fct_items_qty_prefix', $html, 'No variation line, so no prefix');

        $this->assertMatchesRegularExpression('/\.fct_items_qty_inline\s*\{\s*display: block !important/', $this->renderShell($html), 'Phones show the quantity line');
    }
}
