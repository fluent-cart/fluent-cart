<?php

namespace Tests\Integration\Models;

use FluentCart\App\Helpers\Status;
use lucatume\WPBrowser\TestCase\WPTestCase;

class PaymentStatusTest extends WPTestCase
{
    public function test_payment_pending_constant(): void
    {
        $this->assertSame('pending', Status::PAYMENT_PENDING);
    }

    public function test_payment_paid_constant(): void
    {
        $this->assertSame('paid', Status::PAYMENT_PAID);
    }

    public function test_payment_partially_paid_constant(): void
    {
        $this->assertSame('partially_paid', Status::PAYMENT_PARTIALLY_PAID);
    }

    public function test_payment_failed_constant(): void
    {
        $this->assertSame('failed', Status::PAYMENT_FAILED);
    }

    public function test_payment_refunded_constant(): void
    {
        $this->assertSame('refunded', Status::PAYMENT_REFUNDED);
    }

    public function test_payment_partially_refunded_constant(): void
    {
        $this->assertSame('partially_refunded', Status::PAYMENT_PARTIALLY_REFUNDED);
    }

    public function test_payment_authorized_constant(): void
    {
        $this->assertSame('authorized', Status::PAYMENT_AUTHORIZED);
    }

    public function test_transaction_succeeded_constant(): void
    {
        $this->assertSame('succeeded', Status::TRANSACTION_SUCCEEDED);
    }

    public function test_transaction_authorized_constant(): void
    {
        $this->assertSame('authorized', Status::TRANSACTION_AUTHORIZED);
    }

    public function test_transaction_pending_constant(): void
    {
        $this->assertSame('pending', Status::TRANSACTION_PENDING);
    }

    public function test_transaction_refunded_constant(): void
    {
        $this->assertSame('refunded', Status::TRANSACTION_REFUNDED);
    }

    public function test_transaction_failed_constant(): void
    {
        $this->assertSame('failed', Status::TRANSACTION_FAILED);
    }

    public function test_transaction_dispute_lost_constant(): void
    {
        $this->assertSame('dispute_lost', Status::TRANSACTION_DISPUTE_LOST);
    }

    public function test_transaction_type_charge_constant(): void
    {
        $this->assertSame('charge', Status::TRANSACTION_TYPE_CHARGE);
    }

    public function test_transaction_type_refund_constant(): void
    {
        $this->assertSame('refund', Status::TRANSACTION_TYPE_REFUND);
    }

    public function test_transaction_type_dispute_constant(): void
    {
        $this->assertSame('dispute', Status::TRANSACTION_TYPE_DISPUTE);
    }

    public function test_sync_payment_status_maps_completed_to_paid(): void
    {
        $this->assertSame(Status::PAYMENT_PAID, Status::syncPaymentStatus('completed'));
    }

    public function test_sync_payment_status_maps_succeeded_to_paid(): void
    {
        $this->assertSame(Status::PAYMENT_PAID, Status::syncPaymentStatus('succeeded'));
    }

    public function test_sync_transaction_status_maps_paid_to_succeeded(): void
    {
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, Status::syncTransactionStatus('paid'));
    }

    public function test_sync_subscription_status_maps_cancelled_to_canceled(): void
    {
        $this->assertSame(Status::SUBSCRIPTION_CANCELED, Status::syncSubscriptionStatus('cancelled'));
    }

    public function test_transaction_success_statuses_includes_succeeded_and_authorized(): void
    {
        $statuses = Status::getTransactionSuccessStatuses();
        $this->assertContains(Status::TRANSACTION_SUCCEEDED, $statuses);
        $this->assertContains(Status::TRANSACTION_AUTHORIZED, $statuses);
    }

    public function test_order_success_statuses_includes_completed_and_processing(): void
    {
        $statuses = Status::getOrderSuccessStatuses();
        $this->assertContains(Status::ORDER_COMPLETED, $statuses);
        $this->assertContains(Status::ORDER_PROCESSING, $statuses);
    }
}
