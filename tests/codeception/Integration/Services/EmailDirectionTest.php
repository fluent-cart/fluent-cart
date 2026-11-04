<?php

namespace Tests\Integration\Services;

use FluentCart\App\Services\Email\EmailDirection;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\Email\EmailNotificationMailer;
use FluentCart\App\Services\Email\EmailNotifications;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;

class EmailDirectionTest extends WPTestCase
{
    private $originalDirection;

    public function setUp(): void
    {
        parent::setUp();
        $this->originalDirection = $GLOBALS['wp_locale']->text_direction;
    }

    public function tearDown(): void
    {
        $GLOBALS['wp_locale']->text_direction = $this->originalDirection;
        parent::tearDown();
    }

    private function setRtl(bool $isRtl): void
    {
        $GLOBALS['wp_locale']->text_direction = $isRtl ? 'rtl' : 'ltr';
    }

    public function test_sides_follow_direction(): void
    {
        $this->setRtl(false);
        $this->assertSame('left', EmailDirection::start());
        $this->assertSame('right', EmailDirection::end());
        $this->assertSame('ltr', EmailDirection::dir());

        $this->setRtl(true);
        $this->assertSame('right', EmailDirection::start());
        $this->assertSame('left', EmailDirection::end());
        $this->assertSame('rtl', EmailDirection::dir());
    }

    public function test_sides_swaps_only_four_value_shorthand_in_rtl(): void
    {
        $this->setRtl(false);
        $this->assertSame('0 0 0 auto', EmailDirection::sides('0 0 0 auto'));

        $this->setRtl(true);
        $this->assertSame('0 auto 0 0', EmailDirection::sides('0 0 0 auto'));
        $this->assertSame('5px 6px 7px', EmailDirection::sides('5px 6px 7px'));
    }

    public function test_default_email_is_marked_rtl(): void
    {
        $this->setRtl(true);
        $notification = [
            'body'      => '<p style="text-align:right">Body</p>',
            'subject'   => 'Subject',
            'to'        => 'shopper@example.com',
            'is_custom' => false,
        ];

        $body = (new EmailNotificationMailer())->parseEmailContent($notification, [])[0];

        $this->assertStringContainsString('<html dir="rtl"', $body);
        $this->assertStringContainsString('class="email_wrap" dir="rtl"', $body);
        // Admin-written body HTML is not rewritten; only our templates pick their sides.
        $this->assertStringContainsString('<p style="text-align:right">Body</p>', $body);
    }

    public function test_forward_arrow_follows_direction(): void
    {
        $this->setRtl(false);
        $this->assertSame('&rarr;', EmailDirection::forwardArrow());

        $this->setRtl(true);
        $this->assertSame('&larr;', EmailDirection::forwardArrow());
    }

    // Both directions: the totals span the row, so they line up with the item column.
    public function test_totals_span_the_row_in_both_directions(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id, 'payment_status' => 'paid',
            'subtotal' => 5000, 'coupon_discount_total' => 1000, 'total_amount' => 4000, 'total_paid' => 4000,
        ]);
        (new OrderItemFactory())->create($order->id, ['unit_price' => 2500, 'quantity' => 2, 'subtotal' => 5000, 'line_total' => 4000]);
        $order = Order::query()->with(['order_items', 'customer', 'orderTaxRates'])->find($order->id);
        $data = ['order' => $order, 'customer' => $order->customer];

        $render = function () use ($data) {
            $notification = EmailNotifications::formatNotification(EmailNotifications::getNotification('order_paid_customer'), $data);
            return (new EmailNotificationMailer())->parseEmailContent($notification, $data)[0];
        };

        foreach ([false, true] as $isRtl) {
            $this->setRtl($isRtl);
            $html = $render();
            $direction = $isRtl ? 'rtl' : 'ltr';
            $this->assertStringNotContainsString('fct-email-totals-spacer', $html, $direction);
            $this->assertStringNotContainsString('width: 400px;', $html, $direction);
            $this->assertMatchesRegularExpression('/class="fct-email-totals"[^>]*width="100%"/', $html, $direction);
        }
    }

    public function test_rtl_keeps_custom_block_email_alignment_as_set(): void
    {
        $this->setRtl(true);
        $parse = function () {
            return '<p style="text-align:right">Block</p>';
        };
        $wrap = function ($html, $templateData) {
            return '<table class="email_wrap"><tr><td>' . $templateData['emailBody'] . '</td></tr></table>';
        };
        add_filter('fluent_cart/parse_email_block_content', $parse);
        add_filter('fluent_cart/render_block_email_template', $wrap, 10, 2);

        $notification = ['body' => '<!-- wp:paragraph -->', 'subject' => 'S', 'to' => 'a@example.com', 'is_custom' => true];
        $body = (new EmailNotificationMailer())->parseEmailContent($notification, [])[0];

        remove_filter('fluent_cart/parse_email_block_content', $parse);
        remove_filter('fluent_cart/render_block_email_template', $wrap, 10);

        // Like WooCommerce and Gutenberg, an alignment set in the block editor stays physical.
        $this->assertStringContainsString('<p style="text-align:right">Block</p>', $body);
    }
}
