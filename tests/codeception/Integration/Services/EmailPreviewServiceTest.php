<?php

namespace Tests\Integration\Services;

use FluentCart\App\Services\Email\EmailPreviewService;
use lucatume\WPBrowser\TestCase\WPTestCase;

class EmailPreviewServiceTest extends WPTestCase
{
    // =========================================================================
    // Spec 97: getPreviewData returns expected top-level keys
    // =========================================================================

    public function test_get_preview_data_returns_expected_keys(): void
    {
        $data = (new EmailPreviewService())->getPreviewData('order.paid.customer');
        $this->assertArrayHasKey('order', $data);
        $this->assertArrayHasKey('subscription', $data);
        $this->assertArrayHasKey('customer', $data);
        $this->assertArrayHasKey('transaction', $data);
        $this->assertArrayHasKey('reminder', $data);
    }

    // =========================================================================
    // Spec 98: dummy order shape
    // =========================================================================

    public function test_dummy_order_shape(): void
    {
        $data = (new EmailPreviewService())->getPreviewData('order.paid.customer');
        $order = $data['order'];
        $this->assertSame(1001, $order->id);
        $this->assertSame('FC-1001', $order->invoice_no);
        $this->assertSame(4900, $order->total_amount);
        $this->assertSame('digital', $order->fulfillment_type);
    }

    // =========================================================================
    // Spec 99: dummy customer shape
    // =========================================================================

    public function test_dummy_customer_shape(): void
    {
        $data = (new EmailPreviewService())->getPreviewData('order.paid.customer');
        $this->assertSame('John Doe', $data['customer']->full_name);
        $this->assertSame('john.doe@example.com', $data['customer']->email);
    }

    // =========================================================================
    // Spec 100: subscription template returns the subscription's transaction (not the order's)
    // =========================================================================

    public function test_subscription_template_swaps_to_subscription_transaction(): void
    {
        $data = (new EmailPreviewService())->getPreviewData('subscription.renewal.customer');
        $this->assertNotNull($data['transaction']);

        // The subscription's transaction MUST be a different object instance than the order's
        $orderTransaction = $data['order']->getLatestTransaction();
        $subscriptionTransaction = $data['subscription']->getLatestTransaction();
        $this->assertSame(
            $subscriptionTransaction,
            $data['transaction'],
            'subscription template must use the subscription transaction'
        );
        $this->assertNotSame(
            $orderTransaction,
            $data['transaction'],
            'subscription template must NOT reuse the order transaction'
        );
    }

    public function test_order_template_uses_order_transaction(): void
    {
        $data = (new EmailPreviewService())->getPreviewData('order.paid.customer');
        $this->assertSame(
            $data['order']->getLatestTransaction(),
            $data['transaction'],
            'order template must use the order transaction'
        );
    }

    // =========================================================================
    // Spec 101: trial_end template sets reminder.stage='trial_end_3'
    // =========================================================================

    public function test_trial_end_template_sets_stage(): void
    {
        $data = (new EmailPreviewService())->getPreviewData('subscription.trial_end.customer');
        $this->assertSame('trial_end_3', $data['reminder']['stage']);
    }

    // =========================================================================
    // Spec 102: canceled template adds reason
    // =========================================================================

    public function test_canceled_template_adds_reason(): void
    {
        $data = (new EmailPreviewService())->getPreviewData('subscription.canceled.customer');
        $this->assertArrayHasKey('reason', $data);
        $this->assertNotEmpty($data['reason']);
    }

    // =========================================================================
    // Spec 103: reminder dates relative to now
    // =========================================================================

    public function test_reminder_dates_offsets(): void
    {
        $data = (new EmailPreviewService())->getPreviewData('order.paid.customer');
        $now = time();

        $dueAt = strtotime($data['reminder']['due_at']);
        $billing = strtotime($data['reminder']['billing_date']);
        $trialEnd = strtotime($data['reminder']['trial_end_date']);

        // ~2 days, ~30 days, ~3 days; allow 60s drift
        $this->assertEqualsWithDelta(2 * 86400, $dueAt - $now, 60);
        $this->assertEqualsWithDelta(30 * 86400, $billing - $now, 60);
        $this->assertEqualsWithDelta(3 * 86400, $trialEnd - $now, 60);
    }
}
