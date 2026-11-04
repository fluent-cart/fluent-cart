<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Traits\BuildsSubscriptions;

class OverduePauseResumeTest extends WPTestCase
{
    use BuildsSubscriptions;

    /**
     * @dataProvider nonPausableSubscriptions
     */
    public function test_rejected_pause_preserves_the_unpaid_invoice_and_subscription($method, $status, $dueOffset): void
    {
        $dueDate = $dueOffset === null ? null : gmdate('Y-m-d H:i:s', time() + $dueOffset);
        $subscription = $this->buildSubscription([
            'collection_method' => $method,
            'status' => $status,
            'next_billing_date' => $dueDate,
        ], [], [
            'type' => Status::ORDER_TYPE_SUBSCRIPTION,
            'status' => Status::ORDER_COMPLETED,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $invoice = (new OrderFactory())->create([
            'customer_id' => $subscription->customer_id,
            'parent_id' => $subscription->parent_order_id,
            'type' => Status::ORDER_TYPE_RENEWAL,
            'status' => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);
        $hadAccess = $subscription->hasAccessValidity();

        $paused = SubscriptionService::pauseSubscription($subscription, 'portal');
        $resumed = SubscriptionService::resumeSubscription(Subscription::find($subscription->id), 'portal');

        $after = Subscription::find($subscription->id);
        $invoiceAfter = Order::find($invoice->id);
        $this->assertSame($hadAccess, $after->hasAccessValidity(), 'Rejected pause/resume must not restore unpaid access.');
        $this->assertInstanceOf(\WP_Error::class, $paused, 'An ineligible pause must be rejected.');
        $this->assertSame('cannot_pause', $paused->get_error_code(), 'Pause must return the existing eligibility error.');
        $this->assertInstanceOf(\WP_Error::class, $resumed, 'The rejected pause must not enable resume.');
        $this->assertFalse($after->canPause(), 'The portal must hide pause for an ineligible subscription.');
        $this->assertSame($status, $after->status, 'Rejected pause must preserve the subscription status.');
        $this->assertSame($dueDate, $after->next_billing_date, 'Rejected pause must preserve the billing date.');
        $this->assertSame(Status::ORDER_ON_HOLD, $invoiceAfter->status, 'Rejected pause must not cancel the renewal.');
        $this->assertSame(Status::PAYMENT_PENDING, $invoiceAfter->payment_status, 'The unpaid renewal must remain payable.');
    }

    public static function nonPausableSubscriptions(): array
    {
        return [
            'manual past due' => ['manual', 'past_due', -60 * DAY_IN_SECONDS],
            'system past due' => ['system', 'past_due', -60 * DAY_IN_SECONDS],
            'manual expiring' => ['manual', 'expiring', -60 * DAY_IN_SECONDS],
            'system expiring' => ['system', 'expiring', -60 * DAY_IN_SECONDS],
            'manual past due with future date' => ['manual', 'past_due', DAY_IN_SECONDS],
            'system expiring with future date' => ['system', 'expiring', DAY_IN_SECONDS],
            'manual active before status processing' => ['manual', 'active', -HOUR_IN_SECONDS],
            'system active before status processing' => ['system', 'active', -HOUR_IN_SECONDS],
            'manual ended trial' => ['manual', 'trialing', -HOUR_IN_SECONDS],
            'system ended trial' => ['system', 'trialing', -HOUR_IN_SECONDS],
            'manual due now' => ['manual', 'active', 0],
            'system due now' => ['system', 'active', 0],
            'manual missing due date' => ['manual', 'active', null],
            'system missing due date' => ['system', 'active', null],
        ];
    }

    /**
     * @dataProvider resumeBillingDates
     */
    public function test_resume_restores_status_and_access_from_the_existing_billing_date($method, $dueOffset, $expectedStatus, $expectedAccess): void
    {
        $dueDate = $dueOffset === null ? null : gmdate('Y-m-d H:i:s', time() + $dueOffset);
        $subscription = $this->buildSubscription([
            'collection_method' => $method,
            'status' => Status::SUBSCRIPTION_PAUSED,
            'billing_interval' => 'monthly',
            'next_billing_date' => $dueDate,
        ], [], [
            'type' => Status::ORDER_TYPE_SUBSCRIPTION,
            'status' => Status::ORDER_COMPLETED,
            'payment_status' => Status::PAYMENT_PAID,
        ]);

        $result = SubscriptionService::resumeSubscription($subscription, 'portal');

        $after = Subscription::find($subscription->id);
        $this->assertTrue($result, 'A paused store-managed subscription must resume.');
        $this->assertSame($expectedStatus, $after->status, 'Resume must persist the status appropriate to the billing date.');
        $this->assertSame($expectedAccess, $after->hasAccessValidity(), 'Resume must respect the existing grace period.');
        $this->assertSame($dueDate, $after->next_billing_date, 'Resume must not extend or replace the billing date.');
        $this->assertFalse($after->canResume(), 'A resumed subscription must no longer offer resume.');
    }

    public static function resumeBillingDates(): array
    {
        return [
            'manual beyond grace' => ['manual', -60 * DAY_IN_SECONDS, 'past_due', false],
            'system beyond grace' => ['system', -60 * DAY_IN_SECONDS, 'past_due', false],
            'manual within grace' => ['manual', -HOUR_IN_SECONDS, 'past_due', true],
            'system within grace' => ['system', -HOUR_IN_SECONDS, 'past_due', true],
            'manual due now' => ['manual', 0, 'past_due', true],
            'system due now' => ['system', 0, 'past_due', true],
            'manual future date' => ['manual', DAY_IN_SECONDS, 'active', true],
            'system future date' => ['system', DAY_IN_SECONDS, 'active', true],
            'manual missing date' => ['manual', null, 'past_due', false],
            'system missing date' => ['system', null, 'past_due', false],
        ];
    }

    /**
     * @dataProvider pausableSubscriptions
     */
    public function test_current_subscriptions_can_pause_and_resume($method, $status): void
    {
        $dueDate = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
        $subscription = $this->buildSubscription([
            'collection_method' => $method,
            'status' => $status,
            'next_billing_date' => $dueDate,
        ], [], [
            'type' => Status::ORDER_TYPE_SUBSCRIPTION,
            'status' => Status::ORDER_COMPLETED,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $invoice = (new OrderFactory())->create([
            'customer_id' => $subscription->customer_id,
            'parent_id' => $subscription->parent_order_id,
            'type' => Status::ORDER_TYPE_RENEWAL,
            'status' => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);

        $this->assertTrue($subscription->canPause(), 'A current subscription must offer pause.');
        $this->assertTrue(SubscriptionService::pauseSubscription($subscription, 'portal'), 'A current subscription must pause.');
        $paused = Subscription::find($subscription->id);
        $this->assertSame(Status::SUBSCRIPTION_PAUSED, $paused->status, 'Pause must persist the paused state.');
        $this->assertSame(Status::ORDER_CANCELED, Order::find($invoice->id)->status, 'Valid pause must still cancel the advance renewal.');
        $this->assertSame(Status::PAYMENT_FAILED, Order::find($invoice->id)->payment_status, 'Valid pause must still void the advance renewal.');
        $this->assertTrue($paused->canResume(), 'A paused subscription must offer resume.');
        $this->assertTrue(SubscriptionService::resumeSubscription($paused, 'portal'), 'A current paused subscription must resume.');

        $resumed = Subscription::find($subscription->id);
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, $resumed->status, 'An unexpired subscription must return to active.');
        $this->assertSame($dueDate, $resumed->next_billing_date, 'Pause/resume must preserve the billing schedule.');
        $this->assertTrue($resumed->hasAccessValidity(), 'An unexpired subscription must retain access.');
    }

    public static function pausableSubscriptions(): array
    {
        return [
            'manual active' => ['manual', 'active'],
            'system active' => ['system', 'active'],
            'manual trialing' => ['manual', 'trialing'],
            'system trialing' => ['system', 'trialing'],
        ];
    }
}
