<?php

namespace Tests\Integration\Services;

use FluentCart\App\App;
use FluentCart\App\Hooks\Handlers\ShortCodes\ReceiptHandler;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\Email\EmailNotificationMailer;
use FluentCart\App\Services\Email\EmailNotifications;
use FluentCart\App\Services\Renderer\Receipt\ReceiptRenderer;
use FluentCart\App\Services\Renderer\Receipt\ThankYouRender;
use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use FluentCart\Framework\Support\Arr;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\EmailNotificationConfigFactory;
use Tests\Support\Traits\BuildsCustomerOrders;

/**
 * The order number shows the invoice number when the order has one and the
 * order uuid when it does not yet (GitHub issue #55).
 */
class OrderRefFallbackTest extends WPTestCase
{
    use BuildsCustomerOrders;

    private function makeUnpaidOrder(array $overrides = []): Order
    {
        return $this->makeOrderWithCustomer(array_merge([
            'payment_status' => 'pending',
            'status'         => 'on-hold',
            'payment_method' => 'offline_payment',
            'invoice_no'     => '',
            'receipt_number' => null,
        ], $overrides));
    }

    // Order::creating assigns its own invoice number to paid orders, so pin ours afterwards.
    private function makePaidOrder(array $overrides = []): Order
    {
        $order = $this->makeOrderWithCustomer(array_merge(['payment_status' => 'paid'], $overrides));
        Order::query()->where('id', $order->id)->update(['invoice_no' => 'INV-PAID-55']);

        return Order::query()->with('customer')->find($order->id);
    }

    private function offlineConfirmationSubject(Order $order): string
    {
        $subject = Arr::get(EmailNotifications::getNotifications(), 'order_placed_customer.settings.subject');
        $notification = [
            'body' => '<p>Body</p>', 'subject' => $subject, 'to' => 'r@x.test',
            'pre_header' => '', 'is_async' => false, 'is_custom' => false,
        ];

        return (new EmailNotificationMailer())->parseEmailContent($notification, ['order' => $order, 'customer' => $order->customer])[1];
    }

    private function orderHeaderValue(Order $order): string
    {
        $header = (string) App::make('view')->make('emails.parts.order_header', ['order' => $order]);
        preg_match('/Order #\s*<\/p>\s*<p[^>]*>\s*(.*?)\s*<\/p>/s', $header, $match);

        return $match[1] ?? '';
    }

    public function test_invoice_no_smartcode(): void
    {
        $paid = $this->makePaidOrder();
        $unpaid = $this->makeUnpaidOrder();

        $this->assertSame('INV-PAID-55', ShortcodeTemplateBuilder::make('{{order.invoice_no}}', ['order' => $paid]));
        $this->assertSame((string) $unpaid->uuid, ShortcodeTemplateBuilder::make('{{order.invoice_no}}', ['order' => $unpaid]));
    }

    // A template's own fallback ({{order.invoice_no|text}}) wins over the uuid, even beside a plain code.
    public function test_invoice_no_smartcode_keeps_the_template_fallback(): void
    {
        $paid = $this->makePaidOrder();
        $unpaid = $this->makeUnpaidOrder();
        $template = '{{order.invoice_no|Pending}} / {{order.invoice_no}}';

        $this->assertSame('INV-PAID-55 / INV-PAID-55', ShortcodeTemplateBuilder::make($template, ['order' => $paid]));
        $this->assertSame('Pending / ' . $unpaid->uuid, ShortcodeTemplateBuilder::make($template, ['order' => $unpaid]));
        $this->assertSame($unpaid->uuid . ' / Pending', ShortcodeTemplateBuilder::make('{{order.invoice_no}} / {{order.invoice_no|Pending}}', ['order' => $unpaid]));
    }

    // An explicit empty fallback ({{order.invoice_no|}}) means the raw invoice number, blank when unpaid.
    public function test_invoice_no_smartcode_with_empty_fallback_stays_blank(): void
    {
        $this->assertSame('INV-PAID-55', ShortcodeTemplateBuilder::make('{{order.invoice_no|}}', ['order' => $this->makePaidOrder()]));
        $this->assertSame('', ShortcodeTemplateBuilder::make('{{order.invoice_no|}}', ['order' => $this->makeUnpaidOrder()]));
    }

    public function test_offline_confirmation_subject(): void
    {
        $paid = $this->makePaidOrder();
        $unpaid = $this->makeUnpaidOrder();

        $this->assertSame('Order Confirmation #INV-PAID-55 (Offline Payment)', $this->offlineConfirmationSubject($paid));
        $this->assertSame('Order Confirmation #' . $unpaid->uuid . ' (Offline Payment)', $this->offlineConfirmationSubject($unpaid));
    }

    public function test_subject_saved_before_the_fix(): void
    {
        $configFactory = new EmailNotificationConfigFactory();
        $configFactory->withNotificationConfig('order_placed_customer', [
            'subject'         => 'Order Confirmation #{{order.invoice_no}} (Offline Payment)',
            'is_default_body' => 'yes',
        ]);

        try {
            $paid = $this->makePaidOrder();
            $unpaid = $this->makeUnpaidOrder();

            $this->assertSame('Order Confirmation #INV-PAID-55 (Offline Payment)', $this->offlineConfirmationSubject($paid));
            $this->assertSame('Order Confirmation #' . $unpaid->uuid . ' (Offline Payment)', $this->offlineConfirmationSubject($unpaid));
        } finally {
            $configFactory->reset();
        }
    }

    // The email header's "Order #" is always the order uuid, paid or not.
    public function test_default_email_order_header_always_shows_uuid(): void
    {
        $paid = $this->makePaidOrder();
        $unpaid = $this->makeUnpaidOrder();

        $this->assertSame((string) $paid->uuid, $this->orderHeaderValue($paid));
        $this->assertSame((string) $unpaid->uuid, $this->orderHeaderValue($unpaid));
    }

    // "Receipt #" shows the invoice number, and is left out until there is one.
    public function test_default_email_header_receipt_number(): void
    {
        $paidHeader = (string) App::make('view')->make('emails.parts.order_header', ['order' => $this->makePaidOrder()]);
        $unpaidHeader = (string) App::make('view')->make('emails.parts.order_header', ['order' => $this->makeUnpaidOrder()]);

        $this->assertMatchesRegularExpression('/Receipt #\s*<\/p>\s*<p[^>]*>\s*INV-PAID-55\s*<\/p>/', $paidHeader);
        $this->assertStringNotContainsString('Receipt #', $unpaidHeader);
    }

    public function test_renewal_reminder_email_body(): void
    {
        $paid = $this->makePaidOrder();
        $unpaid = $this->makeUnpaidOrder(['type' => 'renewal']);

        $paidHtml = (string) App::make('view')->make('emails.renewal.reminder.customer', ['order' => $paid]);
        $unpaidHtml = (string) App::make('view')->make('emails.renewal.reminder.customer', ['order' => $unpaid]);

        $this->assertStringContainsString('your renewal order INV-PAID-55 is still awaiting payment', $paidHtml);
        $this->assertStringContainsString('your renewal order ' . $unpaid->uuid . ' is still awaiting payment', $unpaidHtml);
    }

    public function test_thank_you_page_header(): void
    {
        $paid = $this->makePaidOrder();
        $failed = $this->makeUnpaidOrder(['payment_status' => 'failed']);

        $render = function (Order $order) {
            ob_start();
            (new ThankYouRender(['order' => $order]))->renderOrderHeader();
            return (string) ob_get_clean();
        };

        $this->assertStringContainsString('>#INV-PAID-55</a>', $render($paid));
        $this->assertStringContainsString('>#' . $failed->uuid . '</a>', $render($failed));
    }

    // Receipt page header: "Order #" is always the uuid; "Invoice number #" only once there is one.
    public function test_receipt_page_header(): void
    {
        $render = function (Order $order) {
            ob_start();
            (new ReceiptRenderer(['order' => $order]))->renderHeader();
            return (string) ob_get_clean();
        };

        $paid = $this->makePaidOrder();
        $unpaid = $this->makeUnpaidOrder();
        $paidHtml = $render($paid);
        $unpaidHtml = $render($unpaid);

        $this->assertMatchesRegularExpression('/id="fct-order-uuid"[^>]*>\s*' . $paid->uuid . '\s*</', $paidHtml);
        $this->assertMatchesRegularExpression('/id="fct-order-invoice-no"[^>]*>\s*INV-PAID-55\s*</', $paidHtml);

        $this->assertMatchesRegularExpression('/id="fct-order-uuid"[^>]*>\s*' . $unpaid->uuid . '\s*</', $unpaidHtml);
        $this->assertStringNotContainsString('Invoice number #', $unpaidHtml);
    }

    private function visibleText(string $view, Order $order): string
    {
        $html = (string) App::make('view')->make($view, ['order' => $order, 'reminder' => []]);

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
    }

    // Payment and renewal reminders: Order # is the uuid; Receipt # only once there is an invoice number.
    public function test_reminder_emails_use_uuid_not_order_id(): void
    {
        $paid = $this->makePaidOrder();
        $unpaid = $this->makeUnpaidOrder();
        $views = [
            'emails.order.reminder.due.customer',
            'emails.order.reminder.due.admin',
            'emails.order.reminder.overdue.customer',
            'emails.order.reminder.overdue.admin',
            'emails.order.reminder.renewal_overdue.first.customer',
            'emails.order.reminder.renewal_overdue.followup.customer',
            'emails.order.reminder.renewal_overdue.final.customer',
        ];

        foreach ($views as $view) {
            $paidText = $this->visibleText($view, $paid);
            $this->assertStringContainsString('Order # ' . $paid->uuid, $paidText, $view);
            $this->assertStringContainsString('Receipt # INV-PAID-55', $paidText, $view);
            $this->assertDoesNotMatchRegularExpression('/#' . $paid->id . '\b/', $paidText, $view);

            $unpaidText = $this->visibleText($view, $unpaid);
            $this->assertStringContainsString('Order # ' . $unpaid->uuid, $unpaidText, $view);
            $this->assertStringNotContainsString('Receipt #', $unpaidText, $view);
            $this->assertDoesNotMatchRegularExpression('/#' . $unpaid->id . '\b/', $unpaidText, $view);
        }
    }

    // The downloaded receipt PDF is named by invoice number once paid, by uuid before.
    public function test_receipt_download_file_name(): void
    {
        $unpaid = $this->makeUnpaidOrder();

        $this->assertSame('receipt-INV-PAID-55.pdf', ReceiptHandler::getDownloadFileName($this->makePaidOrder()));
        $this->assertSame('receipt-' . $unpaid->uuid . '.pdf', ReceiptHandler::getDownloadFileName($unpaid));
    }

    public function test_overdue_reminder_subject_uses_uuid(): void
    {
        $subject = Arr::get(EmailNotifications::getNotifications(), 'renewal_reminder_overdue_customer.settings.subject');

        $this->assertStringContainsString('{{order.uuid}}', $subject);
        $this->assertStringNotContainsString('{{order.id}}', $subject);
    }
}
