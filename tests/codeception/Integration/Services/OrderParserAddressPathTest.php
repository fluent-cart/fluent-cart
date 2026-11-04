<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Order;
use FluentCart\App\Services\ShortCodeParser\Parsers\OrderParser;
use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * {{order.billing.*}} / {{order.shipping.*}} may only read the address itself;
 * the address's order() relation must not lead back into the order graph.
 */
class OrderParserAddressPathTest extends WPTestCase
{
    private function buildOrderForLinkedCustomer(): Order
    {
        $customer = (new CustomerFactory())->createWithUser();
        $order = (new OrderFactory())->withAddresses()->create(['customer_id' => $customer->id]);

        return Order::query()
            ->with(['customer', 'billing_address', 'shipping_address'])
            ->find($order->id);
    }

    public function test_address_code_cannot_walk_the_order_relation_to_the_wp_user(): void
    {
        $order = $this->buildOrderForLinkedCustomer();
        $hash = get_user_by('ID', $order->customer->user_id)->user_pass;
        $this->assertNotEmpty($hash, 'fixture: the linked WP user must have a password hash');

        $data = ['order' => $order];
        foreach (['billing', 'shipping'] as $type) {
            $code = "{{order.{$type}.order.customer.wpUser.user_pass}}";
            $this->assertStringNotContainsString($hash, ShortcodeTemplateBuilder::make($code, $data), $code);
        }
    }

    public function test_address_code_cannot_read_other_order_records(): void
    {
        $order = $this->buildOrderForLinkedCustomer();
        $parser = new OrderParser(['order' => $order]);

        $this->assertSame('', $parser->parse('billing.order.id', 'order.billing.order.id'));
        $this->assertSame('', $parser->parse('billing.order.customer.email', 'order.billing.order.customer.email'));
        $this->assertSame('', $parser->parse('shipping.order.customer.user_id', 'order.shipping.order.customer.user_id'));
    }

    public function test_address_fields_still_resolve(): void
    {
        $order = $this->buildOrderForLinkedCustomer();
        $data = ['order' => $order];
        $billing = $order->billing_address;

        $this->assertSame((string) $billing->address_1, ShortcodeTemplateBuilder::make('{{order.billing.address_1}}', $data));
        $this->assertSame((string) $billing->postcode, ShortcodeTemplateBuilder::make('{{order.billing.postcode}}', $data));
        $this->assertSame((string) $billing->full_name, ShortcodeTemplateBuilder::make('{{order.billing.full_name}}', $data));
        $this->assertNotSame('', ShortcodeTemplateBuilder::make('{{order.billing.city}}', $data));
        $this->assertNotSame('', ShortcodeTemplateBuilder::make('{{order.shipping.city}}', $data));
    }
}
