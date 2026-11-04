<?php

namespace Tests\Integration\Events;

use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Events\Subscription\SubscriptionCanceled;
use FluentCart\App\Events\Subscription\SubscriptionEOT;
use FluentCart\App\Events\Subscription\SubscriptionRenewalFailed;
use FluentCart\App\Events\Subscription\SubscriptionRenewed;
use FluentCart\App\Events\Subscription\SubscriptionValidityExpired;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Listeners;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use lucatume\WPBrowser\TestCase\WPTestCase;
use ReflectionClass;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

class SubscriptionEventsTest extends WPTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private ProductFactory $productFactory;
    private SubscriptionFactory $subscriptionFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->productFactory = new ProductFactory();
        $this->subscriptionFactory = new SubscriptionFactory();
    }

    private function reflectListeners(object $event): array
    {
        $ref = new ReflectionClass($event);
        $prop = $ref->getProperty('listeners');
        $prop->setAccessible(true);
        return $prop->getValue($event);
    }

    private function makeSubscription(): array
    {
        $customer = $this->customerFactory->create();
        $product = $this->productFactory->create();
        $variant = $product->variants->first();
        $order = $this->orderFactory->create(['customer_id' => $customer->id, 'type' => 'subscription']);
        $subscription = $this->subscriptionFactory->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $variant->id,
        ]);
        return compact('customer', 'product', 'order', 'subscription');
    }

    // =========================================================================
    // SubscriptionActivated (specs 89-91)
    // =========================================================================

    public function test_subscription_activated_hook_and_no_listeners(): void
    {
        ['subscription' => $subscription, 'order' => $order, 'customer' => $customer] = $this->makeSubscription();

        $event = new SubscriptionActivated($subscription, $order, $customer);
        $this->assertSame('fluent_cart/subscription_activated', $event->hook);
        $this->assertTrue($event->autoFireHook);
        $this->assertSame([], $this->reflectListeners($event));
    }

    public function test_subscription_activated_to_array_keys(): void
    {
        ['subscription' => $subscription, 'order' => $order, 'customer' => $customer] = $this->makeSubscription();
        $data = (new SubscriptionActivated($subscription, $order, $customer, ['k' => 'v']))->toArray();

        $this->assertArrayHasKey('subscription', $data);
        $this->assertArrayHasKey('order', $data);
        $this->assertArrayHasKey('customer', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertSame(['k' => 'v'], $data['meta']);
    }

    public function test_subscription_activated_activity_module_type_subscription(): void
    {
        ['subscription' => $subscription, 'order' => $order, 'customer' => $customer] = $this->makeSubscription();

        (new SubscriptionActivated($subscription, $order, $customer))->dispatch();

        $log = Activity::query()
            ->where('module_type', Subscription::class)
            ->where('module_id', $subscription->id)
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Subscription Activated', $log->title);
    }

    // =========================================================================
    // SubscriptionRenewed (specs 92-95)
    // =========================================================================

    public function test_subscription_renewed_hook_and_listener(): void
    {
        ['subscription' => $subscription, 'order' => $order, 'customer' => $customer] = $this->makeSubscription();
        $newOrder = $this->orderFactory->create(['customer_id' => $customer->id, 'type' => 'renewal']);

        $event = new SubscriptionRenewed($subscription, $newOrder, $order, $customer);
        $this->assertSame('fluent_cart/subscription_renewed', $event->hook);
        $this->assertTrue($event->autoFireHook);
        $this->assertSame([Listeners\Subscription\SubscriptionRenewed::class], $this->reflectListeners($event));
    }

    public function test_subscription_renewed_before_dispatch_generates_receipt(): void
    {
        ['subscription' => $subscription, 'order' => $order, 'customer' => $customer] = $this->makeSubscription();
        $newOrder = $this->orderFactory->create(['customer_id' => $customer->id, 'type' => 'renewal']);
        $this->assertEmpty($newOrder->receipt_number);

        // Invoke only beforeDispatch directly to avoid triggering email-notification side effects
        // whose template rendering raises PHP warnings in the test env (no configured templates).
        $event = new SubscriptionRenewed($subscription, $newOrder, $order, $customer);
        $event->beforeDispatch();

        $fresh = Order::query()->find($newOrder->id);
        $this->assertNotEmpty($fresh->receipt_number);
    }

    public function test_subscription_renewed_to_array_keys(): void
    {
        ['subscription' => $subscription, 'order' => $order, 'customer' => $customer] = $this->makeSubscription();
        $newOrder = $this->orderFactory->create(['customer_id' => $customer->id, 'type' => 'renewal']);

        $data = (new SubscriptionRenewed($subscription, $newOrder, $order, $customer))->toArray();

        $this->assertArrayHasKey('subscription', $data);
        $this->assertArrayHasKey('order', $data);
        $this->assertArrayHasKey('main_order', $data);
        $this->assertArrayHasKey('customer', $data);
    }

    public function test_subscription_renewed_listener_recounts_customer_stats(): void
    {
        ['subscription' => $subscription, 'order' => $order, 'customer' => $customer] = $this->makeSubscription();
        $newOrder = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'type'           => 'renewal',
            'payment_status' => Status::PAYMENT_PAID,
            'total_paid'     => Helper::toCent(15),
        ]);
        $customer->purchase_count = 0;
        $customer->save();

        Listeners\Subscription\SubscriptionRenewed::handle(
            new SubscriptionRenewed($subscription, $newOrder, $order, $customer)
        );

        $fresh = Customer::query()->find($customer->id);
        $this->assertSame(1, (int) $fresh->purchase_count);
    }

    // =========================================================================
    // SubscriptionCanceled (specs 96-98)
    // =========================================================================

    public function test_subscription_canceled_hook_and_no_listeners(): void
    {
        ['subscription' => $subscription, 'order' => $order, 'customer' => $customer] = $this->makeSubscription();

        $event = new SubscriptionCanceled($subscription, $order, $customer);
        $this->assertSame('fluent_cart/subscription_canceled', $event->hook);
        $this->assertTrue($event->autoFireHook);
        $this->assertSame([], $this->reflectListeners($event));
    }

    public function test_subscription_canceled_reason_defaults_to_translated(): void
    {
        ['subscription' => $subscription, 'order' => $order, 'customer' => $customer] = $this->makeSubscription();
        $data = (new SubscriptionCanceled($subscription, $order, $customer, ''))->toArray();

        $this->assertSame(__('canceled on customer request', 'fluent-cart'), $data['reason']);
    }

    public function test_subscription_canceled_should_create_activity_true(): void
    {
        ['subscription' => $subscription, 'order' => $order, 'customer' => $customer] = $this->makeSubscription();
        $this->assertTrue((new SubscriptionCanceled($subscription, $order, $customer))->shouldCreateActivity());
    }

    // =========================================================================
    // SubscriptionEOT (specs 99-102)
    // =========================================================================

    public function test_subscription_eot_hook_and_no_listeners(): void
    {
        ['subscription' => $subscription, 'order' => $order] = $this->makeSubscription();

        $event = new SubscriptionEOT($subscription, $order);
        $this->assertSame('fluent_cart/subscription_eot', $event->hook);
        $this->assertTrue($event->autoFireHook);
        $this->assertSame([], $this->reflectListeners($event));
    }

    public function test_subscription_eot_loads_customer_on_order(): void
    {
        ['subscription' => $subscription, 'order' => $order] = $this->makeSubscription();
        $event = new SubscriptionEOT($subscription, $order);

        $this->assertTrue($event->order->relationLoaded('customer'));
    }

    public function test_subscription_eot_before_dispatch_logs_error_on_wp_error(): void
    {
        ['subscription' => $subscription, 'order' => $order] = $this->makeSubscription();
        // Pre-cancel subscription so cancelRemoteSubscription returns WP_Error('subscription_already_cancelled')
        $subscription->status = Status::SUBSCRIPTION_CANCELED;
        $subscription->save();

        $before = Activity::query()->where('status', 'error')->count();
        (new SubscriptionEOT($subscription, $order))->dispatch();
        $after = Activity::query()->where('status', 'error')->count();

        $this->assertGreaterThan($before, $after);
    }

    public function test_subscription_eot_remote_cancel_error_is_logged_on_subscription(): void
    {
        ['subscription' => $subscription, 'order' => $order] = $this->makeSubscription();
        $subscription->status = Status::SUBSCRIPTION_CANCELED;
        $subscription->save();

        (new SubscriptionEOT($subscription, $order))->beforeDispatch();

        $log = Activity::query()
            ->where('title', 'Remote subscription cancellation failed on EOT')
            ->orderBy('id', 'DESC')
            ->first();
        $this->assertSame(Subscription::class, $log->module_type);
        $this->assertSame($subscription->id, (int) $log->module_id);
    }

    // =========================================================================
    // SubscriptionValidityExpired (specs 103-104)
    // =========================================================================

    public function test_subscription_validity_expired_hook_and_no_listeners(): void
    {
        ['subscription' => $subscription, 'order' => $order, 'customer' => $customer] = $this->makeSubscription();

        $event = new SubscriptionValidityExpired($subscription, $order, $customer);
        $this->assertSame('fluent_cart/subscription_expired_validity', $event->hook);
        $this->assertTrue($event->autoFireHook);
        $this->assertSame([], $this->reflectListeners($event));
    }

    public function test_subscription_validity_expired_reason_default(): void
    {
        ['subscription' => $subscription, 'order' => $order, 'customer' => $customer] = $this->makeSubscription();
        $data = (new SubscriptionValidityExpired($subscription, $order, $customer, ''))->toArray();

        $this->assertSame(__('Subscription validity expired', 'fluent-cart'), $data['reason']);
    }

    // =========================================================================
    // Activity status and wording
    // =========================================================================

    private function subscriptionActivities(Subscription $subscription)
    {
        return Activity::query()
            ->where('module_type', Subscription::class)
            ->where('module_id', $subscription->id)
            ->orderBy('id', 'DESC')
            ->get();
    }

    public function test_subscription_renewal_failed_activity_is_error_with_reason(): void
    {
        ['subscription' => $subscription, 'order' => $order, 'customer' => $customer] = $this->makeSubscription();

        (new SubscriptionRenewalFailed($subscription, $order, $customer, 'Your card was declined.'))->dispatch();

        $logs = $this->subscriptionActivities($subscription);
        $this->assertCount(1, $logs);
        $this->assertSame('Subscription Renewal Failed', $logs[0]->title);
        $this->assertSame('error', $logs[0]->status);
        $this->assertStringContainsString('Your card was declined.', $logs[0]->content);
        $this->assertStringNotContainsString('successfully', $logs[0]->content);
    }

    public function test_subscription_renewed_activity_stays_success(): void
    {
        ['subscription' => $subscription, 'order' => $order, 'customer' => $customer] = $this->makeSubscription();
        $newOrder = $this->orderFactory->create(['customer_id' => $customer->id, 'type' => 'renewal']);

        Listeners\Activity::handle(new SubscriptionRenewed($subscription, $newOrder, $order, $customer));

        $log = $this->subscriptionActivities($subscription)->first();
        $this->assertSame('success', $log->status);
        $this->assertSame('Subscription Renewed successfully!', $log->content);
    }

    public function test_subscription_validity_expired_activity_is_warning(): void
    {
        ['subscription' => $subscription, 'order' => $order, 'customer' => $customer] = $this->makeSubscription();

        Listeners\Activity::handle(new SubscriptionValidityExpired($subscription, $order, $customer));

        $log = $this->subscriptionActivities($subscription)->first();
        $this->assertSame('warning', $log->status);
        $this->assertSame(__('Subscription validity expired', 'fluent-cart'), $log->content);
    }

    public function test_subscription_eot_activity_is_info(): void
    {
        ['subscription' => $subscription, 'order' => $order] = $this->makeSubscription();

        Listeners\Activity::handle(new SubscriptionEOT($subscription, $order));

        $log = $this->subscriptionActivities($subscription)->first();
        $this->assertSame('Subscription Ended', $log->title);
        $this->assertSame('info', $log->status);
        $this->assertStringNotContainsString('successfully', $log->content);
    }

    public function test_subscription_canceled_activity_is_info_with_reason(): void
    {
        ['subscription' => $subscription, 'order' => $order, 'customer' => $customer] = $this->makeSubscription();

        Listeners\Activity::handle(new SubscriptionCanceled($subscription, $order, $customer, 'Too expensive'));

        $log = $this->subscriptionActivities($subscription)->first();
        $this->assertSame('info', $log->status);
        $this->assertStringContainsString('Too expensive', $log->content);
        $this->assertStringNotContainsString('successfully', $log->content);
    }
}
