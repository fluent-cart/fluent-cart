<?php

namespace Tests\Integration\Services\Renderer;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\Renderer\Receipt\ThankYouRender;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Traits\BuildsCustomerOrders;

/**
 * The thank-you/receipt page header used to branch on a binary
 * `payment_status !== 'paid'`, so every non-paid status — including a fully
 * refunded or failed order — rendered as "Payment Pending!" with a live
 * "you can pay from here" link. The header must instead reflect the actual
 * payment status: refunded orders say so and drop the pay link, failed
 * payments explain the failure and offer a retry, and only genuinely
 * unsettled statuses keep the pending treatment.
 */
class ThankYouRenderHeaderTest extends WPTestCase
{
    use BuildsCustomerOrders;

    private function renderHeaderFor(string $paymentStatus): string
    {
        $order = $this->makeOrderWithCustomer(['payment_status' => $paymentStatus]);

        ob_start();
        (new ThankYouRender(['order' => $order]))->renderHeader();

        return ob_get_clean();
    }

    private function renderOrderHeaderFor(string $paymentStatus): string
    {
        $order = $this->makeOrderWithCustomer(['payment_status' => $paymentStatus]);

        ob_start();
        (new ThankYouRender(['order' => $order]))->renderOrderHeader();

        return ob_get_clean();
    }

    public function test_paid_order_renders_success_header(): void
    {
        $html = $this->renderHeaderFor(Status::PAYMENT_PAID);

        $this->assertStringContainsString('Purchase Successful!', $html);
        $this->assertStringNotContainsString('Payment Pending!', $html);
    }

    public function test_pending_order_renders_pending_header_with_pay_link(): void
    {
        $header = $this->renderHeaderFor(Status::PAYMENT_PENDING);
        $body = $this->renderOrderHeaderFor(Status::PAYMENT_PENDING);

        $this->assertStringContainsString('Payment Pending!', $header);
        $this->assertStringContainsString('has payment due', $body);
    }

    public function test_refunded_order_does_not_render_as_pending(): void
    {
        $html = $this->renderHeaderFor(Status::PAYMENT_REFUNDED);

        $this->assertStringNotContainsString(
            'Payment Pending!',
            $html,
            'a fully refunded order must not present itself as awaiting payment'
        );
        $this->assertStringContainsString('Refunded', $html);
    }

    public function test_refunded_order_body_has_no_pay_link(): void
    {
        $html = $this->renderOrderHeaderFor(Status::PAYMENT_REFUNDED);

        $this->assertStringNotContainsString(
            'has payment due',
            $html,
            'a refunded order must not invite the customer to pay again'
        );
        $this->assertStringContainsString('refunded', strtolower($html));
    }

    public function test_failed_payment_renders_failed_header(): void
    {
        $html = $this->renderHeaderFor(Status::PAYMENT_FAILED);

        $this->assertStringNotContainsString('Payment Pending!', $html);
        $this->assertStringContainsString('Payment Failed', $html);
    }

    public function test_failed_payment_body_explains_and_offers_retry(): void
    {
        $html = $this->renderOrderHeaderFor(Status::PAYMENT_FAILED);

        $this->assertStringNotContainsString('has payment due', $html);
        $this->assertStringContainsString('could not be completed', $html);
        $this->assertStringContainsString('fluent-cart=custom_checkout', $html);
    }

    public function test_partially_refunded_order_renders_success_header(): void
    {
        $html = $this->renderHeaderFor(Status::PAYMENT_PARTIALLY_REFUNDED);

        $this->assertStringContainsString(
            'Purchase Successful!',
            $html,
            'a partial refund does not undo the purchase; the refund row carries the detail'
        );
    }

    public function test_partially_paid_order_keeps_pending_header(): void
    {
        $html = $this->renderHeaderFor(Status::PAYMENT_PARTIALLY_PAID);

        $this->assertStringContainsString('Payment Pending!', $html);
    }
}
