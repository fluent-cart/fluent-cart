<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Order;
use FluentCart\App\Services\Email\EmailNotificationMailer;
use FluentCart\App\Services\Email\EmailNotifications;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;

/**
 * In an RTL email the bidi algorithm reorders unisolated amounts and phone
 * numbers: "+ $0.50" reads "$0.50 +", "+61 2 9000 0000" reads "0000 9000 2 61+",
 * and "৳ 3,701.40" flips its symbol while the isolated negatives do not.
 */
class EmailAmountBidiTest extends WPTestCase
{
    private $originalDirection;

    public function setUp(): void
    {
        parent::setUp();
        $this->originalDirection = $GLOBALS['wp_locale']->text_direction;
        $GLOBALS['wp_locale']->text_direction = 'rtl';
    }

    public function tearDown(): void
    {
        $GLOBALS['wp_locale']->text_direction = $this->originalDirection;
        parent::tearDown();
    }

    private function renderReceipt(): string
    {
        // Itemized mode renders the per-item "+ tax" line
        update_option('fluent_cart_tax_configuration_settings', ['checkout_tax_breakdown_display' => 'itemized']);

        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'           => $customer->id,
            'payment_status'        => 'paid',
            'subtotal'              => 5000,
            'coupon_discount_total' => 1000,
            'tax_total'             => 400,
            'total_amount'          => 4400,
            'total_paid'            => 4400,
        ]);
        (new OrderItemFactory())->create($order->id, [
            'unit_price' => 2500, 'quantity' => 2, 'subtotal' => 5000, 'line_total' => 4000, 'tax_amount' => 400,
        ]);
        (new OrderAddressFactory())->create($order->id, [
            'type' => 'billing',
            'meta' => ['other_data' => ['phone' => '+61 2 9000 0000']],
        ]);

        $order = Order::query()->with(['order_items', 'customer', 'orderTaxRates', 'billing_address', 'shipping_address'])->find($order->id);
        $data = ['order' => $order, 'customer' => $order->customer];
        $notification = EmailNotifications::formatNotification(EmailNotifications::getNotification('order_paid_customer'), $data);

        return (new EmailNotificationMailer())->parseEmailContent($notification, $data)[0];
    }

    private function squash(string $html): string
    {
        return preg_replace('/\s+/', ' ', $html);
    }

    public function test_item_tax_line_keeps_its_plus_sign_with_the_amount(): void
    {
        $html = $this->squash($this->renderReceipt());

        $this->assertMatchesRegularExpression('/<bdi>\+ [^<]*4\.00<\/bdi>/', $html);
    }

    public function test_phone_number_stays_left_to_right(): void
    {
        $html = $this->squash($this->renderReceipt());

        $this->assertStringContainsString('<bdi dir="ltr">+61 2 9000 0000</bdi>', $html);
    }

    public function test_positive_and_negative_totals_are_isolated_alike(): void
    {
        $html = $this->squash($this->renderReceipt());

        // Subtotal and discount: both isolated, so a spaced or Arabic-script
        // symbol keeps the same side on every line.
        $this->assertMatchesRegularExpression('/<bdi>[^<]*50\.00<\/bdi>/', $html);
        $this->assertMatchesRegularExpression('/<bdi>- [^<]*10\.00<\/bdi>/', $html);
        $this->assertMatchesRegularExpression('/<bdi>[^<]*44\.00<\/bdi>/', $html, 'the grand total too');
        $this->assertStringNotContainsString('<p dir="ltr"', $html);
    }
}
