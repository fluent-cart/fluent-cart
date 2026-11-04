<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\StoreManagedRenewal\Services\RenewalService;
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

    private function hasRenewalInvoice(int $parentOrderId): bool
    {
        return Order::query()
            ->where('parent_id', $parentOrderId)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->exists();
    }
}
