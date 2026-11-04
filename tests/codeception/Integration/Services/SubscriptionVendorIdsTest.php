<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

class SubscriptionVendorIdsTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private ProductFactory $productFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private SubscriptionFactory $subscriptionFactory;

    public function setUp(): void
    {
        parent::setUp();
        // The feature ships off; every case below except the default-off ones runs
        // with it enabled the way a site would enable it.
        add_filter('fluent_cart/subscription/vendor_id_editing_enabled', '__return_true');
        $this->customerFactory     = new CustomerFactory();
        $this->productFactory      = new ProductFactory();
        $this->orderFactory        = new OrderFactory();
        $this->itemFactory         = new OrderItemFactory();
        $this->subscriptionFactory = new SubscriptionFactory();
    }

    public function tearDown(): void
    {
        remove_filter('fluent_cart/subscription/vendor_id_editing_enabled', '__return_true');
        parent::tearDown();
    }

    private function makeSubscription(array $overrides = []): Subscription
    {
        $customer = $this->customerFactory->create();
        $product  = $this->productFactory->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant  = $product->variants->first();
        $order    = $this->orderFactory->withAddresses()->create([
            'customer_id' => $customer->id,
            'type'        => 'subscription',
        ]);
        $this->itemFactory->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'payment_type' => Status::ORDER_TYPE_SUBSCRIPTION,
        ]);

        return $this->subscriptionFactory->create(array_merge([
            'customer_id'            => $customer->id,
            'parent_order_id'        => $order->id,
            'product_id'             => $product->ID,
            'variation_id'           => $variant->id,
            'collection_method'      => Status::SUBSCRIPTION_METHOD_AUTOMATIC,
            'current_payment_method' => 'stripe',
            'vendor_subscription_id' => 'sub_original',
            'vendor_customer_id'     => 'cus_original',
        ], $overrides));
    }

    // =========================================================================
    // canEditVendorIds() gate
    // =========================================================================

    /**
     * The feature is opt-in: with the filter off, an otherwise-eligible subscription
     * exposes neither the edit nor the verify capability, and the service refuses the
     * write even if the endpoint is reached directly.
     */
    public function test_vendor_id_editing_is_off_by_default(): void
    {
        remove_filter('fluent_cart/subscription/vendor_id_editing_enabled', '__return_true');

        $sub = $this->makeSubscription();

        $this->assertFalse($sub->canEditVendorIds());
        $this->assertFalse($sub->canVerifyVendorIds());
        $this->assertFalse($sub->permissions['canEditVendorIds']);

        $result = SubscriptionService::updateVendorIds($sub, ['vendor_subscription_id' => 'sub_corrected']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('cannot_edit_vendor_ids', $result->get_error_code());
        $this->assertSame('sub_original', $sub->fresh()->vendor_subscription_id);
    }

    public function test_can_edit_vendor_ids_true_for_active_automatic(): void
    {
        $sub = $this->makeSubscription();

        $this->assertTrue($sub->canEditVendorIds());
    }

    public function test_can_edit_vendor_ids_false_for_store_billed(): void
    {
        foreach ([Status::SUBSCRIPTION_METHOD_MANUAL, Status::SUBSCRIPTION_METHOD_SYSTEM] as $method) {
            $sub = $this->makeSubscription(['collection_method' => $method]);

            $this->assertFalse($sub->canEditVendorIds(), $method . ' must not expose vendor id editing');
        }
    }

    public function test_can_edit_vendor_ids_false_for_completed(): void
    {
        $sub = $this->makeSubscription(['status' => Status::SUBSCRIPTION_COMPLETED]);

        $this->assertFalse($sub->canEditVendorIds(), 'completed must not expose vendor id editing');
    }

    /**
     * A wrong id is a common *cause* of expiry and of a gateway-side cancellation —
     * webhooks resolve to nothing and the subscription lapses — so both stay repairable.
     */
    public function test_can_edit_vendor_ids_true_for_expired_and_canceled(): void
    {
        foreach ([Status::SUBSCRIPTION_EXPIRED, Status::SUBSCRIPTION_CANCELED] as $status) {
            $sub = $this->makeSubscription(['status' => $status]);

            $this->assertTrue($sub->canEditVendorIds(), $status . ' must stay repairable');
        }
    }

    public function test_can_edit_vendor_ids_false_without_payment_method(): void
    {
        $sub = $this->makeSubscription(['current_payment_method' => '']);

        $this->assertFalse($sub->canEditVendorIds());
    }

    public function test_can_update_details_unchanged_by_vendor_id_gate(): void
    {
        $manual = $this->makeSubscription(['collection_method' => Status::SUBSCRIPTION_METHOD_MANUAL]);
        $auto   = $this->makeSubscription();

        $this->assertTrue($manual->canUpdateDetails());
        $this->assertFalse($auto->canUpdateDetails());
    }

    public function test_permissions_attribute_exposes_can_edit_vendor_ids(): void
    {
        $sub = $this->makeSubscription();

        $this->assertArrayHasKey('canEditVendorIds', $sub->permissions);
        $this->assertTrue($sub->permissions['canEditVendorIds']);
        $this->assertFalse($sub->permissions['canEdit']);
    }

    /**
     * Verification is a gateway capability, editing is not: offline_payment does not
     * declare verify_vendor_ids, so the ids stay editable with no Verify button.
     */
    public function test_verify_flag_is_false_for_gateway_without_the_feature(): void
    {
        $sub = $this->makeSubscription(['current_payment_method' => 'offline_payment']);

        $this->assertTrue($sub->canEditVendorIds());
        $this->assertFalse($sub->canVerifyVendorIds());
        $this->assertFalse($sub->permissions['canVerifyVendorIds']);
    }

    public function test_verify_flag_is_false_when_editing_is_blocked(): void
    {
        $sub = $this->makeSubscription(['status' => Status::SUBSCRIPTION_COMPLETED]);

        $this->assertFalse($sub->canVerifyVendorIds());
    }

    // =========================================================================
    // updateVendorIds()
    // =========================================================================

    public function test_update_vendor_ids_writes_both_columns(): void
    {
        $sub = $this->makeSubscription();

        $result = SubscriptionService::updateVendorIds($sub, [
            'vendor_subscription_id' => 'sub_corrected',
            'vendor_customer_id'     => 'cus_corrected',
        ]);

        $this->assertTrue($result);

        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame('sub_corrected', $fresh->vendor_subscription_id);
        $this->assertSame('cus_corrected', $fresh->vendor_customer_id);
    }

    public function test_update_vendor_ids_rejects_store_billed(): void
    {
        $sub = $this->makeSubscription(['collection_method' => Status::SUBSCRIPTION_METHOD_MANUAL]);

        $result = SubscriptionService::updateVendorIds($sub, ['vendor_subscription_id' => 'sub_corrected']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('cannot_edit_vendor_ids', $result->get_error_code());
        $this->assertSame('sub_original', Subscription::query()->find($sub->id)->vendor_subscription_id);
    }

    public function test_update_vendor_ids_rejects_terminal_status(): void
    {
        $sub = $this->makeSubscription(['status' => Status::SUBSCRIPTION_COMPLETED]);

        $result = SubscriptionService::updateVendorIds($sub, ['vendor_subscription_id' => 'sub_corrected']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('cannot_edit_vendor_ids', $result->get_error_code());
    }

    public function test_update_vendor_ids_allows_expired_and_canceled(): void
    {
        foreach ([Status::SUBSCRIPTION_EXPIRED, Status::SUBSCRIPTION_CANCELED] as $i => $status) {
            $sub = $this->makeSubscription([
                'status'                 => $status,
                'vendor_subscription_id' => 'sub_original_' . $i,
            ]);

            $result = SubscriptionService::updateVendorIds($sub, ['vendor_subscription_id' => 'sub_corrected_' . $i]);

            $this->assertNotWPError($result);
            $this->assertSame('sub_corrected_' . $i, $sub->fresh()->vendor_subscription_id);
        }
    }

    public function test_update_vendor_ids_rejects_duplicate_vendor_subscription_id(): void
    {
        $other = $this->makeSubscription(['vendor_subscription_id' => 'sub_taken']);
        $sub   = $this->makeSubscription();

        $result = SubscriptionService::updateVendorIds($sub, ['vendor_subscription_id' => 'sub_taken']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('vendor_subscription_id_taken', $result->get_error_code());
        $this->assertSame('sub_original', Subscription::query()->find($sub->id)->vendor_subscription_id);
        $this->assertSame('sub_taken', Subscription::query()->find($other->id)->vendor_subscription_id);
    }

    public function test_update_vendor_ids_allows_same_id_on_a_different_payment_method(): void
    {
        $this->makeSubscription([
            'vendor_subscription_id' => 'sub_shared',
            'current_payment_method' => 'paypal',
        ]);
        $sub = $this->makeSubscription(['current_payment_method' => 'stripe']);

        $result = SubscriptionService::updateVendorIds($sub, ['vendor_subscription_id' => 'sub_shared']);

        $this->assertTrue($result);
        $this->assertSame('sub_shared', Subscription::query()->find($sub->id)->vendor_subscription_id);
    }

    public function test_update_vendor_ids_allows_keeping_own_id_while_changing_customer_id(): void
    {
        $sub = $this->makeSubscription();

        $result = SubscriptionService::updateVendorIds($sub, [
            'vendor_subscription_id' => 'sub_original',
            'vendor_customer_id'     => 'cus_corrected',
        ]);

        $this->assertTrue($result);
        $this->assertSame('cus_corrected', Subscription::query()->find($sub->id)->vendor_customer_id);
    }

    public function test_update_vendor_ids_returns_no_changes_when_identical(): void
    {
        $sub = $this->makeSubscription();

        $result = SubscriptionService::updateVendorIds($sub, [
            'vendor_subscription_id' => 'sub_original',
            'vendor_customer_id'     => 'cus_original',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('no_changes', $result->get_error_code());
    }

    public function test_update_vendor_ids_ignores_absent_key(): void
    {
        $sub = $this->makeSubscription();

        SubscriptionService::updateVendorIds($sub, ['vendor_subscription_id' => 'sub_corrected']);

        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame('sub_corrected', $fresh->vendor_subscription_id);
        $this->assertSame('cus_original', $fresh->vendor_customer_id);
    }

    public function test_update_vendor_ids_logs_old_and_new_values(): void
    {
        $sub = $this->makeSubscription(['vendor_customer_id' => '']);

        SubscriptionService::updateVendorIds($sub, [
            'vendor_subscription_id' => 'sub_corrected',
            'vendor_customer_id'     => 'cus_corrected',
        ]);

        $log = Activity::query()
            ->where('module_type', Subscription::class)
            ->where('module_id', $sub->id)
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Vendor IDs updated', $log->title);
        $this->assertStringContainsString('vendor_subscription_id: sub_original → sub_corrected', $log->content);
        $this->assertStringContainsString('vendor_customer_id: (none) → cus_corrected', $log->content);
    }

    public function test_update_vendor_ids_does_not_touch_billing_state(): void
    {
        $sub = $this->makeSubscription();
        $renewalCountBefore = Order::query()->where('parent_id', $sub->parent_order_id)->count();

        SubscriptionService::updateVendorIds($sub, ['vendor_subscription_id' => 'sub_corrected']);

        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame($sub->status, $fresh->status);
        $this->assertEquals($sub->recurring_total, $fresh->recurring_total);
        $this->assertEquals($sub->next_billing_date, $fresh->next_billing_date);
        $this->assertSame(
            $renewalCountBefore,
            Order::query()->where('parent_id', $sub->parent_order_id)->count()
        );
    }
}
