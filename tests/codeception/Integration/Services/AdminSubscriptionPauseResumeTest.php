<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Traits\BuildsSubscriptions;

class AdminSubscriptionPauseResumeTest extends WPTestCase
{
    use BuildsSubscriptions;

    /** @dataProvider rejectedPauses */
    public function test_admin_edit_rejects_ineligible_pause_without_changing_the_invoice($method, $status, $offset): void
    {
        $date = gmdate('Y-m-d H:i:s', time() + $offset);
        $subscription = $this->buildSubscription([
            'collection_method' => $method,
            'status' => $status,
            'next_billing_date' => $date,
        ]);
        $invoice = (new OrderFactory())->create([
            'customer_id' => $subscription->customer_id,
            'parent_id' => $subscription->parent_order_id,
            'type' => Status::ORDER_TYPE_RENEWAL,
            'status' => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);

        $result = SubscriptionService::updateSubscription($subscription, [
            'recurring_total' => 49.99,
            'status' => Status::SUBSCRIPTION_PAUSED,
        ]);

        $fresh = Subscription::find($subscription->id);
        $renewal = Order::find($invoice->id);
        $this->assertInstanceOf(\WP_Error::class, $result, 'Ineligible admin pause must fail.');
        $this->assertSame('cannot_pause', $result->get_error_code(), 'Admin edit must use the dedicated pause error.');
        $this->assertSame($status, $fresh->status, 'Rejected edit must preserve status.');
        $this->assertSame($date, $fresh->next_billing_date, 'Rejected edit must preserve billing date.');
        $this->assertEquals(2999, $fresh->recurring_total, 'Rejected edit must not partially save other fields.');
        $this->assertSame(Status::ORDER_ON_HOLD, $renewal->status, 'Rejected edit must not cancel the renewal.');
        $this->assertSame(Status::PAYMENT_PENDING, $renewal->payment_status, 'Rejected edit must leave the renewal payable.');
    }

    public static function rejectedPauses(): array
    {
        return [
            ['manual', 'past_due', -60 * DAY_IN_SECONDS],
            ['system', 'past_due', -60 * DAY_IN_SECONDS],
            ['manual', 'expiring', DAY_IN_SECONDS],
            ['system', 'expiring', DAY_IN_SECONDS],
            ['manual', 'active', -HOUR_IN_SECONDS],
            ['system', 'trialing', -HOUR_IN_SECONDS],
        ];
    }

    /** @dataProvider resumeDates */
    public function test_admin_resume_uses_the_billing_date_and_still_emits_the_resume_event($method, $offset, $newOffset, $expectedStatus, $access): void
    {
        $date = $offset === null ? null : gmdate('Y-m-d H:i:s', time() + $offset);
        $subscription = $this->buildSubscription([
            'collection_method' => $method,
            'status' => Status::SUBSCRIPTION_PAUSED,
            'next_billing_date' => $date,
        ]);
        $data = ['status' => Status::SUBSCRIPTION_ACTIVE];
        if ($newOffset !== null) {
            $date = gmdate('Y-m-d H:i:s', time() + $newOffset);
            $data['next_billing_date'] = $date;
        }
        $events = [];
        $listener = static function ($payload) use (&$events) {
            $events[] = $payload;
        };
        add_action('fluent_cart/subscription_resumed', $listener);
        try {
            $result = SubscriptionService::updateSubscription($subscription, $data);
        } finally {
            remove_action('fluent_cart/subscription_resumed', $listener);
        }

        $fresh = Subscription::find($subscription->id);
        $this->assertTrue($result, 'Admin resume must succeed.');
        $this->assertSame($expectedStatus, $fresh->status, 'Admin resume must honor the effective billing date.');
        $this->assertSame($date, $fresh->next_billing_date, 'Resume must not invent or extend the billing date.');
        $this->assertSame($access, $fresh->hasAccessValidity(), 'Admin resume must respect grace-period access.');
        $this->assertCount(1, $events, 'Both active and overdue resumes must emit one resume event.');
        $this->assertSame(Status::SUBSCRIPTION_PAUSED, $events[0]['old_status'], 'Resume event must retain the old status.');
        $this->assertSame($expectedStatus, $events[0]['subscription']->status, 'Resume event must expose the saved status.');
    }

    public static function resumeDates(): array
    {
        return [
            ['manual', -60 * DAY_IN_SECONDS, null, 'past_due', false],
            ['system', -60 * DAY_IN_SECONDS, null, 'past_due', false],
            ['manual', -HOUR_IN_SECONDS, null, 'past_due', true],
            ['system', -HOUR_IN_SECONDS, null, 'past_due', true],
            ['manual', null, null, 'past_due', false],
            ['system', null, null, 'past_due', false],
            ['manual', DAY_IN_SECONDS, null, 'active', true],
            ['system', DAY_IN_SECONDS, null, 'active', true],
            ['manual', -60 * DAY_IN_SECONDS, DAY_IN_SECONDS, 'active', true],
            ['system', DAY_IN_SECONDS, -60 * DAY_IN_SECONDS, 'past_due', false],
        ];
    }

    /** @dataProvider collectionMethods */
    public function test_admin_can_still_pause_a_current_subscription_and_edit_other_fields($method): void
    {
        $subscription = $this->buildSubscription([
            'collection_method' => $method,
            'status' => Status::SUBSCRIPTION_ACTIVE,
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
        ]);
        $invoice = (new OrderFactory())->create([
            'customer_id' => $subscription->customer_id,
            'parent_id' => $subscription->parent_order_id,
            'type' => Status::ORDER_TYPE_RENEWAL,
            'status' => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);

        $result = SubscriptionService::updateSubscription($subscription, [
            'status' => Status::SUBSCRIPTION_PAUSED,
            'recurring_total' => 49.99,
        ]);

        $fresh = Subscription::find($subscription->id);
        $this->assertTrue($result, 'Eligible admin pause must succeed.');
        $this->assertSame(Status::SUBSCRIPTION_PAUSED, $fresh->status, 'Eligible pause must save paused status.');
        $this->assertEquals(4999, $fresh->recurring_total, 'Other admin edits must still be saved.');
        $this->assertSame(Status::ORDER_CANCELED, Order::find($invoice->id)->status, 'Eligible pause must still cancel the advance invoice.');
        $this->assertSame(Status::PAYMENT_FAILED, Order::find($invoice->id)->payment_status, 'Eligible pause must still void the advance invoice.');
    }

    /** @dataProvider combinedPauseEdits */
    public function test_admin_pause_validates_the_final_billing_date_before_voiding_renewals($method, $statusFirst, $changeInterval): void
    {
        $originalDate = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
        $subscription = $this->buildSubscription([
            'collection_method' => $method,
            'status' => Status::SUBSCRIPTION_ACTIVE,
            'billing_interval' => 'monthly',
            'next_billing_date' => $originalDate,
        ], [], [
            'type' => Status::ORDER_TYPE_SUBSCRIPTION,
            'payment_status' => Status::PAYMENT_PAID,
            'created_at' => gmdate('Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS),
        ]);
        $invoice = (new OrderFactory())->create([
            'customer_id' => $subscription->customer_id,
            'parent_id' => $subscription->parent_order_id,
            'type' => Status::ORDER_TYPE_RENEWAL,
            'status' => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);
        $data = $changeInterval
            ? ['billing_interval' => 'daily']
            : ['next_billing_date' => gmdate('Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS)];
        $data = $statusFirst
            ? ['status' => Status::SUBSCRIPTION_PAUSED] + $data
            : $data + ['status' => Status::SUBSCRIPTION_PAUSED];

        $result = SubscriptionService::updateSubscription($subscription, $data);

        $fresh = Subscription::find($subscription->id);
        $renewal = Order::find($invoice->id);
        $this->assertInstanceOf(\WP_Error::class, $result, 'A combined edit must not pause an overdue billing schedule.');
        $this->assertSame('cannot_pause', $result->get_error_code(), 'Combined edits must use the pause eligibility guard.');
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, $fresh->status, 'Rejected edit must preserve status.');
        $this->assertSame($originalDate, $fresh->next_billing_date, 'Rejected edit must not save the requested or derived date.');
        $this->assertSame('monthly', $fresh->billing_interval, 'Rejected edit must not save an interval change.');
        $this->assertSame(Status::ORDER_ON_HOLD, $renewal->status, 'Rejected edit must preserve the renewal order.');
        $this->assertSame(Status::PAYMENT_PENDING, $renewal->payment_status, 'Rejected edit must leave the invoice payable.');
    }

    public static function combinedPauseEdits(): array
    {
        return [
            ['manual', true, false],
            ['manual', false, false],
            ['system', true, false],
            ['system', false, false],
            ['manual', true, true],
            ['manual', false, true],
            ['system', true, true],
            ['system', false, true],
        ];
    }

    /** @dataProvider resumeIntervalEdits */
    public function test_admin_resume_checks_the_date_recalculated_by_an_interval_edit($method, $statusFirst): void
    {
        $subscription = $this->buildSubscription([
            'collection_method' => $method,
            'status' => Status::SUBSCRIPTION_PAUSED,
            'billing_interval' => 'monthly',
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
        ], [], [
            'type' => Status::ORDER_TYPE_SUBSCRIPTION,
            'payment_status' => Status::PAYMENT_PAID,
            'created_at' => gmdate('Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS),
        ]);
        $data = $statusFirst
            ? ['status' => Status::SUBSCRIPTION_ACTIVE, 'billing_interval' => 'daily']
            : ['billing_interval' => 'daily', 'status' => Status::SUBSCRIPTION_ACTIVE];

        $result = SubscriptionService::updateSubscription($subscription, $data);

        $fresh = Subscription::find($subscription->id);
        $this->assertTrue($result, 'Admin interval edit and resume must succeed.');
        $this->assertSame('daily', $fresh->billing_interval, 'The interval edit must still be saved.');
        $this->assertLessThan(time(), strtotime($fresh->next_billing_date), 'Fixture must produce an overdue recalculated date.');
        $this->assertSame(Status::SUBSCRIPTION_PAST_DUE, $fresh->status, 'Resume must use the recalculated date in either field order.');
        $this->assertFalse($fresh->hasAccessValidity(), 'An overdue recalculated date must not grant active access.');
    }

    public static function resumeIntervalEdits(): array
    {
        return [['manual', true], ['manual', false], ['system', true], ['system', false]];
    }

    public static function collectionMethods(): array
    {
        return [['manual'], ['system']];
    }
}
