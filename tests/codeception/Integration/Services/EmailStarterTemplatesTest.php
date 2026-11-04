<?php

namespace Tests\Integration\Services;

use FluentCart\App\Services\Email\StarterTemplates;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Core's own email starter templates and how they reach Pro's template picker.
 */
class EmailStarterTemplatesTest extends WPTestCase
{
    private function blank(): array
    {
        return ['id' => 'blank', 'name' => 'Start Blank', 'description' => '', 'thumbnail' => '', 'content' => ''];
    }

    private function ids(array $templates): array
    {
        return array_column($templates, 'id');
    }

    public function test_shipment_email_gets_the_core_shipment_template(): void
    {
        $templates = StarterTemplates::getForNotification('order_shipment_created_customer');

        $this->assertSame(['shipment_update/basic'], $this->ids($templates));

        $template = $templates[0];
        $this->assertNotSame('', $template['name']);
        $this->assertNotSame('', $template['thumbnail'], 'a thumbnail keeps the picker card consistent with the others');
        $this->assertStringContainsString('{{shipment.carrier_name}}', $template['content']);
        $this->assertStringContainsString('{{shipment.tracking_link}}', $template['content']);
        $this->assertStringContainsString('{{shipment.items}}', $template['content']);
        $this->assertStringNotContainsString('email-order-items', $template['content'], 'the whole-order items block would list items this shipment does not hold');
    }

    public function test_order_level_shipping_emails_get_no_shipment_template(): void
    {
        // Shipped/delivered fire with the order only, so {{shipment.*}} would render empty.
        $this->assertSame([], StarterTemplates::getForNotification('order_shipped_customer'));
        $this->assertSame([], StarterTemplates::getForNotification('order_delivered_customer'));
        $this->assertSame([], StarterTemplates::getForNotification('order_paid_customer'));
    }

    public function test_the_template_is_valid_block_markup(): void
    {
        $content = StarterTemplates::getForNotification('order_shipment_created_customer')[0]['content'];

        $blocks = array_values(array_filter(parse_blocks($content), function ($block) {
            return !empty($block['blockName']);
        }));

        $this->assertCount(1, $blocks, 'one root group, like the Pro starters');
        $this->assertSame('core/group', $blocks[0]['blockName']);
        $this->assertStringNotContainsString('"align":"right"', $content, 'physical alignment would not flip for RTL');
        $this->assertStringNotContainsString('"align":"left"', $content, 'physical alignment would not flip for RTL');
    }

    public function test_the_picker_filter_puts_the_core_template_first(): void
    {
        $templates = apply_filters(
            'fluent_cart_pro/email/starter_templates',
            [$this->blank()],
            ['template' => 'order_shipment_created_customer']
        );

        // Pro auto-applies the first non-blank starter on an empty editor.
        $this->assertSame(['shipment_update/basic', 'blank'], $this->ids($templates));
    }

    public function test_the_picker_filter_leaves_other_notifications_alone(): void
    {
        $templates = apply_filters(
            'fluent_cart_pro/email/starter_templates',
            [$this->blank()],
            ['template' => 'order_shipped_customer']
        );

        $this->assertSame(['blank'], $this->ids($templates));
    }

    public function test_the_picker_filter_does_not_add_a_template_twice(): void
    {
        $once = apply_filters(
            'fluent_cart_pro/email/starter_templates',
            [$this->blank()],
            ['template' => 'order_shipment_created_customer']
        );
        $twice = apply_filters(
            'fluent_cart_pro/email/starter_templates',
            $once,
            ['template' => 'order_shipment_created_customer']
        );

        $this->assertSame(['shipment_update/basic', 'blank'], $this->ids($twice));
    }
}
