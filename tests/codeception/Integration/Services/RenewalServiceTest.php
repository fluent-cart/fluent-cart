<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\StoreManagedRenewal\Services\RenewalService;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\Traits\ConfiguresStoreOrderMode;

class RenewalServiceTest extends DatabaseTestCase
{
    use ConfiguresStoreOrderMode;

    private CustomerFactory $customerFactory;
    private ProductFactory $productFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private SubscriptionFactory $subscriptionFactory;
    private array $postponedBillingDates = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->customerFactory = new CustomerFactory();
        $this->productFactory = new ProductFactory();
        $this->orderFactory = new OrderFactory();
        $this->itemFactory = new OrderItemFactory();
        $this->subscriptionFactory = new SubscriptionFactory();

        // OrderFactory creates live-mode orders; renewals only run when the
        // store mode matches.
        $this->setStoreOrderMode('live');

        // Other classes commit subscriptions past the per-test rollback; a due
        // one would be picked ahead of this test's fixtures. RenewalService opens
        // its own transaction, which commits this update, so tearDown restores it.
        $this->postponedBillingDates = Subscription::query()->pluck('next_billing_date', 'id')->all();
        Subscription::query()->update(['next_billing_date' => gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS)]);
    }

    public function tearDown(): void
    {
        foreach ($this->postponedBillingDates as $id => $date) {
            Subscription::query()->where('id', $id)->update(['next_billing_date' => $date]);
        }
        parent::tearDown();
    }

    public function test_create_renewal_invoice_copies_parent_address_and_tax_snapshot(): void
    {
        ['subscription' => $subscription, 'order' => $order] = $this->makeSubscriptionSetup([
            'collection_method' => 'manual',
            'billing_interval'  => 'daily',
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);

        $order->updateMeta('tax_id', 'VAT-ABC-123');
        $order->updateMeta('vat_tax_id', 'VAT-LEGACY-456');
        $order->updateMeta('business_info', [
            'company_name' => 'Acme Inc.',
            'tax_number'   => 'VAT-ABC-123',
        ]);
        $order->updateMeta('store_business_info', [
            'business_name' => 'FluentCart Store',
            'seller_tax_id' => 'SELLER-123',
        ]);

        $created = RenewalService::createRenewalOrders($subscription);

        $this->assertFalse(is_wp_error($created));
        $this->assertCount(1, $created);

        $invoice = Order::query()->find($created[0]->id);

        $this->assertSame('123 Main St', $invoice->billing_address->address_1);
        $this->assertSame('456 Oak Ave', $invoice->shipping_address->address_1);
        $this->assertSame('VAT-ABC-123', $invoice->getMeta('tax_id'));
        $this->assertSame('VAT-LEGACY-456', $invoice->getMeta('vat_tax_id'));
        $this->assertSame('Acme Inc.', $invoice->getMeta('business_info')['company_name']);
        $this->assertSame('SELLER-123', $invoice->getMeta('store_business_info')['seller_tax_id']);
    }

    public function test_process_due_subscriptions_ignores_non_ready_and_automatic_subscriptions(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->makeSubscriptionSetup([
                'collection_method' => 'manual',
                'billing_interval'  => 'monthly',
                'next_billing_date' => gmdate('Y-m-d H:i:s', time() + (14 * DAY_IN_SECONDS)),
            ]);
        }

        ['order' => $automaticOrder] = $this->makeSubscriptionSetup([
            'collection_method' => 'automatic',
            'billing_interval'  => 'daily',
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);

        ['order' => $dueOrder] = $this->makeSubscriptionSetup([
            'collection_method' => 'manual',
            'billing_interval'  => 'daily',
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);

        $result = RenewalService::processDueSubscriptions(1);

        $this->assertSame(1, $result['processed']);
        $this->assertFalse($this->hasRenewalInvoice($automaticOrder->id));
        $this->assertTrue($this->hasRenewalInvoice($dueOrder->id));
    }

    public function test_process_due_subscriptions_skips_live_subscriptions_when_store_is_in_test_mode(): void
    {
        ['order' => $liveOrder] = $this->makeSubscriptionSetup([
            'collection_method' => 'manual',
            'billing_interval'  => 'daily',
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);

        $this->setStoreOrderMode('test');

        $result = RenewalService::processDueSubscriptions(5);

        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['failed']);
        $this->assertFalse($this->hasRenewalInvoice($liveOrder->id));
    }

    public function test_create_renewal_orders_rejects_store_mode_mismatch(): void
    {
        ['subscription' => $subscription] = $this->makeSubscriptionSetup([
            'collection_method' => 'manual',
            'billing_interval'  => 'daily',
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);

        $this->setStoreOrderMode('test');

        $created = RenewalService::createRenewalOrders($subscription);

        $this->assertTrue(is_wp_error($created));
        $this->assertSame('store_mode_mismatch', $created->get_error_code());
    }

    public function test_malformed_mode_guard_value_keeps_guard_enabled(): void
    {
        ['subscription' => $subscription] = $this->makeSubscriptionSetup([
            'collection_method' => 'manual',
            'billing_interval'  => 'daily',
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);

        $this->setStoreOrderMode('test');
        // A value written past the request sanitizer (import, plugin,
        // direct update_option) must fail closed, not disable the guard.
        $this->setStoreSetting('subscription_mode_guard', 'true');

        $created = RenewalService::createRenewalOrders($subscription);

        $this->assertTrue(is_wp_error($created));
        $this->assertSame('store_mode_mismatch', $created->get_error_code());
    }

    public function test_disabled_mode_guard_lets_live_subscriptions_renew_in_test_mode(): void
    {
        ['order' => $liveOrder, 'subscription' => $subscription] = $this->makeSubscriptionSetup([
            'collection_method' => 'manual',
            'billing_interval'  => 'daily',
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);

        $this->setStoreOrderMode('test');
        $this->setStoreSetting('subscription_mode_guard', 'no');

        $created = RenewalService::createRenewalOrders($subscription);
        $this->assertFalse(is_wp_error($created));
        $this->assertTrue($this->hasRenewalInvoice($liveOrder->id));
    }

    public function test_expire_canceled_subscriptions_expires_store_billed_once_paid_time_ends(): void
    {
        $fired = [];
        add_action('fluent_cart/subscription_expired_validity', function ($data) use (&$fired) {
            $fired[] = (int) $data['subscription']->id;
        });

        $pastDate = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
        ['subscription' => $manual] = $this->makeSubscriptionSetup([
            'collection_method' => 'manual',
            'status'            => Status::SUBSCRIPTION_CANCELED,
            'next_billing_date' => $pastDate,
        ]);
        ['subscription' => $system] = $this->makeSubscriptionSetup([
            'collection_method' => 'system',
            'status'            => Status::SUBSCRIPTION_CANCELED,
            'next_billing_date' => $pastDate,
        ]);

        $result = RenewalService::expireCanceledSubscriptions(20);
        RenewalService::expireCanceledSubscriptions(20);

        $this->assertSame(2, $result['expired']);
        $this->assertSame([(int) $manual->id, (int) $system->id], $fired);

        foreach ([$manual, $system] as $subscription) {
            $fresh = Subscription::query()->find($subscription->id);
            $this->assertSame(Status::SUBSCRIPTION_CANCELED, $fresh->status);
            $this->assertNull($fresh->next_billing_date);
            $this->assertNotEmpty($fresh->getMeta('validity_expired_at'));
        }
    }

    public function test_expire_canceled_subscriptions_skips_rows_it_does_not_own(): void
    {
        $fired = 0;
        add_action('fluent_cart/subscription_expired_validity', function () use (&$fired) {
            $fired++;
        });

        $pastDate = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
        $skipped = [
            // Paid time not over yet.
            $this->makeSubscriptionSetup([
                'collection_method' => 'manual',
                'status'            => Status::SUBSCRIPTION_CANCELED,
                'next_billing_date' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
            ])['subscription'],
            // The upgraded-to subscription owns validity.
            $this->makeSubscriptionSetup([
                'collection_method' => 'manual',
                'status'            => Status::SUBSCRIPTION_CANCELED,
                'next_billing_date' => $pastDate,
                'config'            => ['upgraded_to_sub_id' => 999],
            ])['subscription'],
            // Gateway-billed: the automatic sweep owns it.
            $this->makeSubscriptionSetup([
                'collection_method' => 'automatic',
                'status'            => Status::SUBSCRIPTION_CANCELED,
                'next_billing_date' => $pastDate,
            ])['subscription'],
        ];

        $result = RenewalService::expireCanceledSubscriptions(20);

        $this->assertSame(0, $result['expired']);
        $this->assertSame(0, $fired);
        foreach ($skipped as $subscription) {
            $fresh = Subscription::query()->find($subscription->id);
            $this->assertNotNull($fresh->next_billing_date);
            $this->assertEmpty($fresh->getMeta('validity_expired_at'));
        }
    }

    public function test_expire_canceled_subscriptions_expires_again_after_admin_extends_the_date(): void
    {
        $fired = 0;
        add_action('fluent_cart/subscription_expired_validity', function () use (&$fired) {
            $fired++;
        });

        $subscription = $this->makeSubscriptionSetup([
            'collection_method' => 'manual',
            'status'            => Status::SUBSCRIPTION_CANCELED,
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ])['subscription'];

        RenewalService::expireCanceledSubscriptions(20);

        $updated = SubscriptionService::updateSubscription(
            Subscription::query()->find($subscription->id),
            ['next_billing_date' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS)]
        );
        $this->assertNotWPError($updated);

        Subscription::query()->where('id', $subscription->id)->update([
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);

        $result = RenewalService::expireCanceledSubscriptions(20);

        $this->assertSame(1, $result['expired']);
        $this->assertSame(2, $fired);
        $this->assertNull(Subscription::query()->find($subscription->id)->next_billing_date);
    }

    public function test_expired_cancel_keeps_access_denied_after_the_date_is_cleared(): void
    {
        ['subscription' => $subscription, 'order' => $order] = $this->makeSubscriptionSetup([
            'collection_method' => 'manual',
            'status'            => Status::SUBSCRIPTION_CANCELED,
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);
        // Paid today, so a date rebuilt from payments lands a month ahead.
        Order::query()->where('id', $order->id)->update(['payment_status' => Status::PAYMENT_PAID]);

        $this->assertFalse(Subscription::query()->find($subscription->id)->hasAccessValidity());

        $result = RenewalService::expireCanceledSubscriptions(20);

        $this->assertSame(1, $result['expired']);
        $fresh = Subscription::query()->find($subscription->id);
        $this->assertNull($fresh->next_billing_date);
        $this->assertFalse($fresh->hasAccessValidity());
    }

    public function test_sync_keeps_recorded_expiry_on_a_canceled_subscription(): void
    {
        $subscriptionId = $this->makeExpiredCanceledSubscription();

        SubscriptionService::syncSubscriptionStates(Subscription::query()->find($subscriptionId), []);

        $fresh = Subscription::query()->find($subscriptionId);
        $this->assertNull($fresh->next_billing_date);
        $this->assertNotEmpty($fresh->getMeta('validity_expired_at'));
        $this->assertFalse($fresh->hasAccessValidity());
    }

    public function test_sync_to_active_rebuilds_the_date_and_clears_recorded_expiry(): void
    {
        $subscriptionId = $this->makeExpiredCanceledSubscription();

        SubscriptionService::syncSubscriptionStates(Subscription::query()->find($subscriptionId), [
            'status' => Status::SUBSCRIPTION_ACTIVE,
        ]);

        $fresh = Subscription::query()->find($subscriptionId);
        $this->assertNotNull($fresh->next_billing_date);
        $this->assertEmpty($fresh->getMeta('validity_expired_at'));
    }

    public function test_recorded_expiry_gives_no_reactivation_trial_credit(): void
    {
        $subscriptionId = $this->makeExpiredCanceledSubscription();

        $fresh = Subscription::query()->find($subscriptionId);
        $this->assertFalse($fresh->hasReactivationTrialCredit());
        $this->assertSame(0, $fresh->getReactivationTrialDays());
    }

    public function test_expire_canceled_subscriptions_keeps_expiry_when_delivery_throws(): void
    {
        ['subscription' => $subscription] = $this->makeSubscriptionSetup([
            'collection_method' => 'manual',
            'status'            => Status::SUBSCRIPTION_CANCELED,
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);

        $listener = function () {
            throw new \RuntimeException('listener down');
        };
        add_action('fluent_cart/subscription_expired_validity', $listener);

        try {
            $first = RenewalService::expireCanceledSubscriptions(20);

            $this->assertSame(0, $first['expired']);
            $this->assertCount(1, $first['errors']);
            $fresh = Subscription::query()->find($subscription->id);
            $this->assertNull($fresh->next_billing_date);
            $this->assertNotEmpty($fresh->getMeta('validity_expired_at'));
            $this->assertFalse($fresh->hasAccessValidity());

            $second = RenewalService::expireCanceledSubscriptions(20);

            $this->assertSame(0, $second['expired']);
            $this->assertCount(0, $second['errors']);
        } finally {
            remove_action('fluent_cart/subscription_expired_validity', $listener);
        }
    }

    public function test_expire_canceled_subscriptions_skips_other_store_mode(): void
    {
        ['subscription' => $subscription] = $this->makeSubscriptionSetup([
            'collection_method' => 'manual',
            'status'            => Status::SUBSCRIPTION_CANCELED,
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);

        $this->setStoreOrderMode('test');

        $result = RenewalService::expireCanceledSubscriptions(20);

        $this->assertSame(0, $result['expired']);
        $this->assertNotNull(Subscription::query()->find($subscription->id)->next_billing_date);
    }

    private function makeSubscriptionSetup(array $subscriptionOverrides = []): array
    {
        $customer = $this->customerFactory->create();
        $product = $this->productFactory
            ->withVariantAttributes(['payment_type' => Status::ORDER_TYPE_SUBSCRIPTION])
            ->create();
        $variant = $product->variants->first();
        $order = $this->orderFactory->withAddresses()->create([
            'customer_id' => $customer->id,
            'type'        => Status::ORDER_TYPE_SUBSCRIPTION,
        ]);

        $this->itemFactory->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'payment_type' => Status::ORDER_TYPE_SUBSCRIPTION,
        ]);

        $subscription = $this->subscriptionFactory->create(array_merge([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $variant->id,
        ], $subscriptionOverrides));

        return compact('customer', 'product', 'variant', 'order', 'subscription');
    }

    // Paid today, so a date rebuilt from payments would land a month ahead.
    private function makeExpiredCanceledSubscription(): int
    {
        ['subscription' => $subscription, 'order' => $order] = $this->makeSubscriptionSetup([
            'collection_method' => 'manual',
            'status'            => Status::SUBSCRIPTION_CANCELED,
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);
        Order::query()->where('id', $order->id)->update(['payment_status' => Status::PAYMENT_PAID]);

        $this->assertSame(1, RenewalService::expireCanceledSubscriptions(20)['expired']);

        return (int) $subscription->id;
    }

    private function hasRenewalInvoice(int $parentOrderId): bool
    {
        return Order::query()
            ->where('parent_id', $parentOrderId)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->exists();
    }
}
