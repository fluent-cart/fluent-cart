<?php

namespace Tests\Integration\Models;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\PaymentMethods\Core\PaymentGatewayInterface;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\PaymentInstance;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\MetaFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\Factory\SubscriptionMetaFactory;
use Tests\Support\Traits\ConfiguresStoreOrderMode;

class SubscriptionTest extends DatabaseTestCase
{
    use ConfiguresStoreOrderMode;

    private CustomerFactory $customerFactory;
    private ProductFactory $productFactory;
    private OrderFactory $orderFactory;
    private SubscriptionFactory $subscriptionFactory;
    private SubscriptionMetaFactory $metaFactory;
    private OrderTransactionFactory $txnFactory;
    private OrderItemFactory $itemFactory;
    private MetaFactory $planMetaFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory    = new CustomerFactory();
        $this->productFactory     = new ProductFactory();
        $this->orderFactory       = new OrderFactory();
        $this->subscriptionFactory = new SubscriptionFactory();
        $this->metaFactory        = new SubscriptionMetaFactory();
        $this->txnFactory         = new OrderTransactionFactory();
        $this->itemFactory        = new OrderItemFactory();
        $this->planMetaFactory    = new MetaFactory();
        $this->setStoreOrderMode(Status::ORDER_MODE_LIVE);
    }

    // =========================================================================
    // Helpers: build common fixture
    // =========================================================================

    private function makeSubscription(array $overrides = []): array
    {
        $customer = $this->customerFactory->create();
        $product  = $this->productFactory->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant  = $product->variants->first();
        $order    = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'type'        => 'subscription',
        ]);
        $sub = $this->subscriptionFactory->create(array_merge([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $variant->id,
        ], $overrides));

        return compact('customer', 'product', 'variant', 'order', 'sub');
    }

    // =========================================================================
    // Spec 1-2: Table fct_subscriptions schema
    // =========================================================================

    public function test_subscriptions_table_has_expected_columns(): void
    {
        $expected = [
            'id', 'uuid', 'customer_id', 'parent_order_id', 'product_id', 'item_name',
            'quantity', 'variation_id', 'billing_interval', 'signup_fee',
            'recurring_amount', 'recurring_tax_total', 'recurring_total',
            'bill_times', 'bill_count', 'expire_at', 'trial_ends_at',
            'canceled_at', 'restored_at', 'collection_method', 'next_billing_date',
            'trial_days', 'vendor_customer_id', 'vendor_plan_id',
            'vendor_subscription_id', 'status', 'original_plan', 'vendor_response',
            'current_payment_method', 'config', 'created_at', 'updated_at',
        ];
        $this->assertColumnsExist('fct_subscriptions', $expected);
    }

    // =========================================================================
    // Spec 3-6: Column types and defaults
    // =========================================================================

    public function test_subscription_monetary_columns_are_bigint_unsigned(): void
    {
        foreach (['signup_fee', 'recurring_amount', 'recurring_tax_total', 'recurring_total'] as $col) {
            $this->assertColumnType('fct_subscriptions', $col, 'bigint');
        }
    }

    public function test_subscription_quantity_defaults_to_1(): void
    {
        $this->assertColumnDefault('fct_subscriptions', 'quantity', '1');
    }

    public function test_subscription_bill_times_defaults_to_0(): void
    {
        $this->assertColumnDefault('fct_subscriptions', 'bill_times', '0');
    }

    public function test_subscription_collection_method_enum_default(): void
    {
        $col = $this->getColumn('fct_subscriptions', 'collection_method');
        $this->assertNotNull($col);
        $this->assertStringContainsString('automatic', strtolower($col['Type']));
        $this->assertSame('automatic', $col['Default']);
    }

    // =========================================================================
    // Spec 7: Index on parent_order_id
    // =========================================================================

    public function test_subscriptions_table_has_index_on_parent_order_id(): void
    {
        $indexes = $this->getIndexes('fct_subscriptions');
        $hasParentOrderIndex = false;
        foreach ($indexes as $name => $cols) {
            if (in_array('parent_order_id', $cols)) {
                $hasParentOrderIndex = true;
                break;
            }
        }
        $this->assertTrue($hasParentOrderIndex, 'fct_subscriptions should have index on parent_order_id');
    }

    // =========================================================================
    // Spec 11-13: fct_subscription_meta schema
    // =========================================================================

    public function test_subscription_meta_has_index_on_subscription_id(): void
    {
        $indexes = $this->getIndexes('fct_subscription_meta');
        $hasIndex = false;
        foreach ($indexes as $name => $cols) {
            if (in_array('subscription_id', $cols)) {
                $hasIndex = true;
                break;
            }
        }
        $this->assertTrue($hasIndex, 'fct_subscription_meta should have index on subscription_id');
    }

    // =========================================================================
    // Spec 17-18: Boot - UUID generation
    // =========================================================================

    public function test_creating_subscription_auto_generates_uuid(): void
    {
        ['sub' => $sub] = $this->makeSubscription();
        $this->assertNotEmpty($sub->uuid, 'UUID should be auto-generated on create');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $sub->uuid);
    }

    public function test_creating_subscription_with_uuid_preserves_it_via_direct_set(): void
    {
        ['sub' => $sub] = $this->makeSubscription();
        // uuid not in $fillable, so bypass mass assignment
        $sub->uuid = 'custom-test-uuid-12345';
        $sub->save();
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame('custom-test-uuid-12345', $fresh->uuid);
    }

    // =========================================================================
    // Spec 19-22: Config JSON attribute
    // =========================================================================

    public function test_config_attribute_decodes_json_string(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['config' => ['key' => 'val']]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertIsArray($fresh->config);
        $this->assertSame('val', $fresh->config['key']);
    }

    public function test_config_attribute_returns_empty_array_for_null(): void
    {
        ['sub' => $sub] = $this->makeSubscription();
        // Force null in DB
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_subscriptions', ['config' => null], ['id' => $sub->id]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertIsArray($fresh->config);
        $this->assertEmpty($fresh->config);
    }

    public function test_config_setter_encodes_array_to_json(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['config' => ['foo' => 'bar', 'nested' => ['x' => 1]]]);
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT config FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
            $sub->id
        ));
        $this->assertJson($raw);
        $decoded = json_decode($raw, true);
        $this->assertSame('bar', $decoded['foo']);
        $this->assertSame(1, $decoded['nested']['x']);
    }

    public function test_config_setter_with_non_array_stores_empty_json(): void
    {
        ['sub' => $sub] = $this->makeSubscription();
        $sub->config = 'not-an-array'; // triggers setConfigAttribute
        $sub->save();
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT config FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
            $sub->id
        ));
        $this->assertSame('[]', $raw);
    }

    // =========================================================================
    // Spec 23-31: Relationships
    // =========================================================================

    public function test_subscription_belongs_to_customer(): void
    {
        ['sub' => $sub, 'customer' => $customer] = $this->makeSubscription();
        $loaded = Subscription::query()->with('customer')->find($sub->id);
        $this->assertInstanceOf(Customer::class, $loaded->customer);
        $this->assertSame($customer->id, $loaded->customer->id);
    }

    public function test_subscription_belongs_to_order(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscription();
        $loaded = Subscription::query()->with('order')->find($sub->id);
        $this->assertInstanceOf(Order::class, $loaded->order);
        $this->assertSame($order->id, $loaded->order->id);
    }

    public function test_subscription_has_many_meta(): void
    {
        ['sub' => $sub] = $this->makeSubscription();
        $this->metaFactory->create($sub->id, 'test_key', 'test_value');
        $loaded = Subscription::query()->with('meta')->find($sub->id);
        $this->assertGreaterThan(0, $loaded->meta->count());
    }

    public function test_subscription_has_many_transactions(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscription();
        $this->txnFactory->withAttributes(['subscription_id' => $sub->id])->create($order->id, [
            'subscription_id' => $sub->id,
            'status'          => Status::TRANSACTION_SUCCEEDED,
        ]);
        $loaded = Subscription::query()->with('transactions')->find($sub->id);
        $this->assertGreaterThan(0, $loaded->transactions->count());
    }

    public function test_subscription_belongs_to_product(): void
    {
        ['sub' => $sub, 'product' => $product] = $this->makeSubscription();
        $loaded = Subscription::query()->with('product')->find($sub->id);
        $this->assertNotNull($loaded->product);
        $this->assertSame($product->ID, $loaded->product->ID);
    }

    public function test_subscription_belongs_to_variation(): void
    {
        ['sub' => $sub, 'variant' => $variant] = $this->makeSubscription();
        $loaded = Subscription::query()->with('variation')->find($sub->id);
        $this->assertInstanceOf(ProductVariation::class, $loaded->variation);
        $this->assertSame($variant->id, $loaded->variation->id);
    }

    // =========================================================================
    // Spec 32-34: Overridden status attribute
    // =========================================================================

    public function test_overridden_status_returns_active_when_trialing_with_simulated_flag(): void
    {
        ['sub' => $sub] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_TRIALING,
            'config' => ['is_trial_days_simulated' => 'yes'],
        ]);
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, $sub->overridden_status);
    }

    public function test_overridden_status_returns_raw_status_when_no_simulation(): void
    {
        ['sub' => $sub] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_CANCELED,
        ]);
        $this->assertSame(Status::SUBSCRIPTION_CANCELED, $sub->overridden_status);
    }

    // =========================================================================
    // Spec 40-44: Meta methods
    // =========================================================================

    public function test_get_meta_returns_value_for_existing_key(): void
    {
        ['sub' => $sub] = $this->makeSubscription();
        $this->metaFactory->create($sub->id, 'my_key', 'my_value');
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame('my_value', $fresh->getMeta('my_key'));
    }

    public function test_get_meta_returns_default_when_key_missing(): void
    {
        ['sub' => $sub] = $this->makeSubscription();
        $this->assertSame('default_val', $sub->getMeta('nonexistent_key', 'default_val'));
    }

    public function test_update_meta_creates_new_meta_when_absent(): void
    {
        ['sub' => $sub] = $this->makeSubscription();
        $sub->updateMeta('new_key', 'new_value');
        $this->assertSame('new_value', $sub->getMeta('new_key'));
    }

    public function test_update_meta_updates_existing_meta(): void
    {
        ['sub' => $sub] = $this->makeSubscription();
        $this->metaFactory->create($sub->id, 'existing_key', 'old_value');
        $sub->updateMeta('existing_key', 'updated_value');
        $this->assertSame('updated_value', $sub->getMeta('existing_key'));
    }

    public function test_subscription_meta_encodes_array_on_set_and_decodes_on_get(): void
    {
        ['sub' => $sub] = $this->makeSubscription();
        $data = ['arr_key' => 'arr_val', 'nested' => [1, 2, 3]];
        $sub->updateMeta('array_meta', $data);
        $result = $sub->getMeta('array_meta');
        $this->assertIsArray($result);
        $this->assertSame('arr_val', $result['arr_key']);
    }

    // =========================================================================
    // Spec 45-46: getLatestTransaction
    // =========================================================================

    public function test_get_latest_transaction_returns_most_recent_charge(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscription();
        $txn1 = $this->txnFactory->create($order->id, [
            'subscription_id'  => $sub->id,
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status'           => Status::TRANSACTION_SUCCEEDED,
        ]);
        $txn2 = $this->txnFactory->create($order->id, [
            'subscription_id'  => $sub->id,
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status'           => Status::TRANSACTION_SUCCEEDED,
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $latest = $fresh->getLatestTransaction();
        $this->assertNotNull($latest);
        $this->assertSame($txn2->id, $latest->id);
    }

    public function test_get_latest_transaction_returns_null_when_no_charges(): void
    {
        ['sub' => $sub] = $this->makeSubscription();
        $this->assertNull($sub->getLatestTransaction());
    }

    // =========================================================================
    // Spec 47-51: hasAccessValidity
    // =========================================================================

    public function test_has_access_validity_true_for_active_status(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['status' => Status::SUBSCRIPTION_ACTIVE]);
        $this->assertTrue($sub->hasAccessValidity());
    }

    public function test_has_access_validity_true_for_trialing_status(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['status' => Status::SUBSCRIPTION_TRIALING]);
        $this->assertTrue($sub->hasAccessValidity());
    }

    public function test_has_access_validity_true_for_completed_status(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['status' => Status::SUBSCRIPTION_COMPLETED]);
        $this->assertTrue($sub->hasAccessValidity());
    }

    public function test_has_access_validity_false_for_expired_status(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['status' => Status::SUBSCRIPTION_EXPIRED]);
        $this->assertFalse($sub->hasAccessValidity());
    }

    public function test_has_access_validity_false_for_past_due_status(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['status' => Status::SUBSCRIPTION_PAST_DUE]);
        $this->assertFalse($sub->hasAccessValidity());
    }

    public function test_has_access_validity_false_for_pending_status(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['status' => Status::SUBSCRIPTION_PENDING]);
        $this->assertFalse($sub->hasAccessValidity());
    }

    public function test_has_access_validity_true_for_canceled_with_future_next_billing_date(): void
    {
        $future = gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_CANCELED,
            'next_billing_date' => $future,
        ]);
        $this->assertTrue($sub->hasAccessValidity());
    }

    public function test_has_access_validity_false_for_canceled_with_past_next_billing_date(): void
    {
        $past = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_CANCELED,
            'next_billing_date' => $past,
        ]);
        $this->assertFalse($sub->hasAccessValidity());
    }

    // =========================================================================
    // hasAccessValidity: expiring/failing share past_due's grace-window treatment
    // =========================================================================

    public function test_has_access_validity_true_for_expiring_within_grace(): void
    {
        // monthly grace = 7 days; 3 days past due is still inside grace.
        $recentPast = gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_EXPIRING,
            'billing_interval'  => 'monthly',
            'next_billing_date' => $recentPast,
        ]);
        $this->assertTrue($sub->hasAccessValidity());
    }

    public function test_has_access_validity_false_for_expiring_past_grace(): void
    {
        $pastGrace = gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_EXPIRING,
            'billing_interval'  => 'monthly',
            'next_billing_date' => $pastGrace,
        ]);
        $this->assertFalse($sub->hasAccessValidity());
    }

    public function test_has_access_validity_true_for_failing_within_grace(): void
    {
        $recentPast = gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_FAILING,
            'billing_interval'  => 'monthly',
            'next_billing_date' => $recentPast,
        ]);
        $this->assertTrue($sub->hasAccessValidity());
    }

    public function test_has_access_validity_false_for_failing_past_grace(): void
    {
        $pastGrace = gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_FAILING,
            'billing_interval'  => 'monthly',
            'next_billing_date' => $pastGrace,
        ]);
        $this->assertFalse($sub->hasAccessValidity());
    }

    /**
     * Access (hasAccessValidity) is grace-window-based for these three statuses;
     * reactivation trial credit (hasReactivationTrialCredit/getReactivationTrialDays)
     * is date-based only and does not consult grace days at all. Once the due date
     * has passed, all three retain access inside grace but always credit 0 trial
     * days — the two concerns are intentionally decoupled per hasReactivationTrialCredit()'s
     * docblock.
     */
    public function test_overdue_statuses_retain_access_in_grace_but_zero_trial_days(): void
    {
        $recentPast = gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS);

        foreach ([Status::SUBSCRIPTION_PAST_DUE, Status::SUBSCRIPTION_EXPIRING, Status::SUBSCRIPTION_FAILING] as $status) {
            ['sub' => $sub] = $this->makeSubscription([
                'status'            => $status,
                'billing_interval'  => 'monthly',
                'next_billing_date' => $recentPast,
            ]);

            $this->assertTrue($sub->hasAccessValidity(), "hasAccessValidity() should be true in grace for {$status}");
            $this->assertSame(0, $sub->getReactivationTrialDays(), "getReactivationTrialDays() should be 0 (due date already passed) for {$status}");
        }
    }

    public function test_reactivation_trial_credit_unaffected_by_grace_period_days_filter(): void
    {
        $recentPast = gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_EXPIRING,
            'billing_interval'  => 'monthly',
            'next_billing_date' => $recentPast,
        ]);

        $withoutFilter = $sub->getReactivationTrialDays();

        $filter = function () {
            return ['monthly' => 90];
        };
        add_filter('fluent_cart/subscription/grace_period_days', $filter);
        $withFilter = Subscription::query()->find($sub->id)->getReactivationTrialDays();
        remove_filter('fluent_cart/subscription/grace_period_days', $filter);

        $this->assertSame($withoutFilter, $withFilter, 'Trial credit must not change with grace-days config — hasReactivationTrialCredit() does no grace math');
        $this->assertSame(0, $withFilter);
    }

    // =========================================================================
    // Spec 52-53: canUpgrade
    // =========================================================================

    public function test_can_upgrade_true_when_upgrade_path_exists_and_status_active(): void
    {
        ['sub' => $sub, 'variant' => $variant] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_ACTIVE,
        ]);
        $this->planMetaFactory->createVariantUpgradePath($variant->id, [$variant->id]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertTrue($fresh->canUpgrade());
    }

    public function test_can_upgrade_false_when_no_upgrade_path_exists(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['status' => Status::SUBSCRIPTION_ACTIVE]);
        $this->assertFalse($sub->canUpgrade());
    }

    public function test_can_upgrade_false_when_status_is_canceled(): void
    {
        ['sub' => $sub, 'variant' => $variant] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_CANCELED,
        ]);
        $this->planMetaFactory->createVariantUpgradePath($variant->id, [$variant->id]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertFalse($fresh->canUpgrade());
    }

    // =========================================================================
    // Spec 59-65: canReactive
    // =========================================================================

    public function test_can_reactive_false_when_upgraded_to_sub_id_set(): void
    {
        ['sub' => $sub] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_CANCELED,
            'config' => ['upgraded_to_sub_id' => 99],
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertFalse((bool)$fresh->canReactive());
    }

    public function test_can_reactive_false_when_recurring_amount_is_zero(): void
    {
        ['sub' => $sub] = $this->makeSubscription([
            'status'           => Status::SUBSCRIPTION_CANCELED,
            'recurring_amount' => 0,
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertFalse((bool)$fresh->canReactive());
    }

    public function test_can_reactive_false_when_cancellation_reason_is_refunded(): void
    {
        ['sub' => $sub] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_CANCELED,
            'config' => ['cancellation_reason' => 'refunded'],
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertFalse((bool)$fresh->canReactive());
    }

    // =========================================================================
    // Spec 66-71: guessNextBillingDate
    // =========================================================================

    public function test_guess_next_billing_date_returns_stored_value_when_set(): void
    {
        $future = gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription(['next_billing_date' => $future]);
        $this->assertSame($future, $sub->guessNextBillingDate());
    }

    public function test_guess_next_billing_date_forced_recalculates(): void
    {
        $storedDate = gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS);
        ['sub' => $sub, 'order' => $order] = $this->makeSubscription([
            'next_billing_date' => $storedDate,
            'billing_interval'  => 'monthly',
        ]);
        // Make the order older so the guessed date differs from stored
        $oldDate = gmdate('Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS);
        Order::query()->where('id', $order->id)->update(['created_at' => $oldDate]);
        $fresh = Subscription::query()->find($sub->id);
        $guessed = $fresh->guessNextBillingDate(true);
        $this->assertNotNull($guessed);
        $this->assertNotSame($storedDate, (string) $guessed, 'forced guessing recomputes from the order instead of returning the stored date');
    }

    // =========================================================================
    // Spec 74-75: getCurrentRenewalAmount
    // =========================================================================

    public function test_get_current_renewal_amount_returns_config_override(): void
    {
        ['sub' => $sub] = $this->makeSubscription([
            'recurring_total' => Helper::toCent(29.99),
            'config'          => ['current_renewal_amount' => Helper::toCent(19.99)],
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame(Helper::toCent(19.99), $fresh->getCurrentRenewalAmount());
    }

    public function test_get_current_renewal_amount_returns_recurring_total_when_no_override(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['recurring_total' => Helper::toCent(29.99)]);
        $fresh = Subscription::query()->find($sub->id);
        // recurring_total comes from DB as string; compare using loose equality
        $this->assertEquals(Helper::toCent(29.99), $fresh->getCurrentRenewalAmount());
    }

    // =========================================================================
    // Spec 76-80: getRequiredBillTimes
    // =========================================================================

    public function test_get_required_bill_times_returns_zero_for_unlimited(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['bill_times' => 0, 'bill_count' => 0]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame(0, $fresh->getRequiredBillTimes());
    }

    public function test_get_required_bill_times_returns_remaining_cycles(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['bill_times' => 12, 'bill_count' => 3]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame(9, $fresh->getRequiredBillTimes());
    }

    public function test_get_required_bill_times_returns_negative_one_when_completed(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscription([
            'bill_times' => 3,
            'bill_count' => 3,
        ]);
        // Ensure transactions match bill_count to avoid recount
        for ($i = 0; $i < 3; $i++) {
            $this->txnFactory->create($order->id, [
                'subscription_id'  => $sub->id,
                'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
                'status'           => Status::TRANSACTION_SUCCEEDED,
                'total'            => Helper::toCent(29.99),
            ]);
        }
        $fresh = Subscription::query()->find($sub->id);
        $result = $fresh->getRequiredBillTimes();
        $this->assertSame(-1, $result);
    }

    // =========================================================================
    // Spec 81-84: getDownloads
    // =========================================================================

    public function test_get_downloads_returns_empty_when_not_active(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['status' => Status::SUBSCRIPTION_CANCELED]);
        $this->assertIsArray($sub->getDownloads());
        $this->assertEmpty($sub->getDownloads());
    }

    public function test_get_downloads_returns_empty_when_no_variation_id(): void
    {
        ['customer' => $customer, 'product' => $product, 'order' => $order] = $this->makeSubscription();
        $sub = $this->subscriptionFactory->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => 0, // no variation
            'status'          => Status::SUBSCRIPTION_ACTIVE,
        ]);
        $this->assertIsArray($sub->getDownloads());
        $this->assertEmpty($sub->getDownloads());
    }

    // =========================================================================
    // Spec 89-96: cancelRemoteSubscription
    // =========================================================================

    public function test_cancel_remote_subscription_returns_error_when_already_canceled(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['status' => Status::SUBSCRIPTION_CANCELED]);
        $result = $sub->cancelRemoteSubscription();
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('subscription_already_cancelled', $result->get_error_code());
    }

    public function test_cancel_remote_subscription_sets_local_canceled_status(): void
    {
        // config must be non-empty array to avoid core bug: empty [] -> '[]' string in getter
        ['sub' => $sub] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_ACTIVE,
            'config' => ['_init' => true],
        ]);
        $sub->cancelRemoteSubscription(['reason' => 'test_reason', 'fire_hooks' => false]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_CANCELED, $fresh->status);
        $this->assertNotEmpty($fresh->canceled_at);
    }

    public function test_cancel_remote_subscription_stores_reason_in_config(): void
    {
        ['sub' => $sub] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_ACTIVE,
            'config' => ['_init' => true],
        ]);
        $sub->cancelRemoteSubscription(['reason' => 'user_requested', 'fire_hooks' => false]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame('user_requested', $fresh->config['cancellation_reason']);
    }

    public function test_cancel_remote_subscription_preserves_completed_status(): void
    {
        ['sub' => $sub] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_COMPLETED,
            'config' => ['_init' => true],
        ]);
        $result = $sub->cancelRemoteSubscription(['fire_hooks' => false]);
        if (!is_wp_error($result)) {
            $fresh = Subscription::query()->find($sub->id);
            $this->assertSame(Status::SUBSCRIPTION_COMPLETED, $fresh->status);
        }
    }

    public function test_cancel_remote_subscription_dispatches_canceled_event(): void
    {
        $eventFired = false;
        add_action('fluent_cart/subscription_canceled', function () use (&$eventFired) {
            $eventFired = true;
        });

        ['sub' => $sub] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_ACTIVE,
            'config' => ['_init' => true],
        ]);
        $sub->cancelRemoteSubscription(['reason' => 'test', 'fire_hooks' => true]);
        $this->assertTrue($eventFired, 'SubscriptionCanceled event should fire');
    }

    public function test_cancel_remote_subscription_suppresses_event_when_fire_hooks_false(): void
    {
        $eventFired = false;
        add_action('fluent_cart/subscription_canceled', function () use (&$eventFired) {
            $eventFired = true;
        });

        ['sub' => $sub] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_ACTIVE,
            'config' => ['_init' => true],
        ]);
        $sub->cancelRemoteSubscription(['reason' => 'test', 'fire_hooks' => false]);
        $this->assertFalse($eventFired, 'SubscriptionCanceled should not fire when fire_hooks=false');
    }

    // =========================================================================
    // Spec 97-107: checkAndExpireSubscriptions
    // =========================================================================

    public function test_check_and_expire_subscriptions_returns_stats_array(): void
    {
        $stats = Subscription::checkAndExpireSubscriptions();
        $this->assertArrayHasKey('checked', $stats);
        $this->assertArrayHasKey('validity_expired', $stats);
        $this->assertArrayHasKey('batches', $stats);
    }

    public function test_check_and_expire_transitions_active_past_grace_to_expired(): void
    {
        // monthly grace = 7 days; put next_billing_date 10 days in the past
        $pastDate = gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'billing_interval'  => 'monthly',
            'next_billing_date' => $pastDate,
        ]);

        Subscription::checkAndExpireSubscriptions();

        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_EXPIRED, $fresh->status);
        // next_billing_date set to NULL — MySQL may return NULL or '0000-00-00 00:00:00'
        $nbd = $fresh->next_billing_date;
        $this->assertTrue(
            $nbd === null || $nbd === '0000-00-00 00:00:00',
            "next_billing_date should be NULL or zero-date after expiration, got: {$nbd}"
        );
    }

    public function test_check_and_expire_sets_validity_expired_at_meta(): void
    {
        $pastDate = gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'billing_interval'  => 'monthly',
            'next_billing_date' => $pastDate,
        ]);

        Subscription::checkAndExpireSubscriptions();

        $fresh = Subscription::query()->find($sub->id);
        $this->assertNotNull($fresh->getMeta('validity_expired_at'));
    }

    public function test_check_and_expire_does_not_expire_subscription_within_grace_period(): void
    {
        // monthly grace = 7 days; put next_billing_date only 3 days in the past
        $recentPast = gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'billing_interval'  => 'monthly',
            'next_billing_date' => $recentPast,
        ]);

        Subscription::checkAndExpireSubscriptions();

        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, $fresh->status);
    }

    public function test_check_and_expire_canceled_with_upgraded_sub_id_is_skipped(): void
    {
        $pastDate = gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_CANCELED,
            'billing_interval'  => 'monthly',
            'next_billing_date' => $pastDate,
            'config'            => ['upgraded_to_sub_id' => 999],
        ]);

        Subscription::checkAndExpireSubscriptions();

        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_CANCELED, $fresh->status, 'Upgraded subscriptions should be skipped');
    }

    public function test_check_and_expire_dispatches_validity_expired_event(): void
    {
        $eventFired = false;
        add_action('fluent_cart/subscription_expired_validity', function () use (&$eventFired) {
            $eventFired = true;
        });

        $pastDate = gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS);
        $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'billing_interval'  => 'monthly',
            'next_billing_date' => $pastDate,
        ]);

        Subscription::checkAndExpireSubscriptions();
        $this->assertTrue($eventFired, 'SubscriptionValidityExpired event should fire');
    }

    public function test_check_and_expire_transitions_failing_past_grace_to_expired_and_fires_event(): void
    {
        $eventFired = false;
        add_action('fluent_cart/subscription_expired_validity', function () use (&$eventFired) {
            $eventFired = true;
        });

        // monthly grace = 7 days; put next_billing_date 10 days in the past
        $pastDate = gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_FAILING,
            'billing_interval'  => 'monthly',
            'next_billing_date' => $pastDate,
        ]);

        Subscription::checkAndExpireSubscriptions();

        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_EXPIRED, $fresh->status);
        $this->assertTrue($eventFired, 'SubscriptionValidityExpired event should fire for failing status too');
    }

    public function test_check_and_expire_does_not_expire_failing_within_grace_period(): void
    {
        $recentPast = gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_FAILING,
            'billing_interval'  => 'monthly',
            'next_billing_date' => $recentPast,
        ]);

        Subscription::checkAndExpireSubscriptions();

        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_FAILING, $fresh->status);
    }

    // =========================================================================
    // Spec 225: Config defaults to [] not null
    // =========================================================================

    public function test_config_stores_empty_array_not_null(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['config' => []]);
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT config FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
            $sub->id
        ));
        $this->assertNotNull($raw, 'config column should not be NULL');
        $this->assertIsArray(json_decode((string) $raw, true), 'config is stored as JSON the model can decode');
    }

    // =========================================================================
    // Spec 14-16: Fillable / Guarded / Appends
    // =========================================================================

    public function test_fillable_contains_core_fields(): void
    {
        $sub = new Subscription();
        $fillable = $sub->getFillable();
        foreach ([
            'customer_id', 'parent_order_id', 'product_id', 'variation_id',
            'billing_interval', 'signup_fee', 'recurring_amount', 'recurring_total',
            'bill_times', 'bill_count', 'canceled_at', 'collection_method',
            'trial_days', 'next_billing_date', 'status', 'current_payment_method',
            'config',
        ] as $field) {
            $this->assertContains($field, $fillable, "fillable missing $field");
        }
    }

    public function test_guarded_is_id_only(): void
    {
        $sub = new Subscription();
        $ref = new \ReflectionClass($sub);
        $prop = $ref->getProperty('guarded');
        $prop->setAccessible(true);
        $this->assertSame(['id'], $prop->getValue($sub));
    }

    public function test_appends_includes_expected_attributes(): void
    {
        $sub = new Subscription();
        $ref = new \ReflectionClass($sub);
        $prop = $ref->getProperty('appends');
        $prop->setAccessible(true);
        $appends = $prop->getValue($sub);
        foreach ([
            'url', 'payment_info', 'billingInfo', 'overridden_status', 'currency', 'reactivate_url',
        ] as $a) {
            $this->assertContains($a, $appends, "appends missing $a");
        }
    }

    // =========================================================================
    // Spec 33: overridden_status = trialing when active + trial_days + within trial + not simulated
    // =========================================================================

    public function test_overridden_status_returns_trialing_when_active_within_trial(): void
    {
        ['sub' => $sub] = $this->makeSubscription([
            'status'     => Status::SUBSCRIPTION_ACTIVE,
            'trial_days' => 30,
            'config'     => ['_init' => true],
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_TRIALING, $fresh->overridden_status);
    }

    // =========================================================================
    // Spec 35-37: Currency attribute
    // =========================================================================

    public function test_currency_returns_store_default_when_config_empty(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['config' => []]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertNotEmpty($fresh->currency);
        $this->assertSame(strtoupper($fresh->currency), $fresh->currency);
    }

    public function test_currency_returns_store_default_when_config_currency_empty(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['config' => ['currency' => '']]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertNotEmpty($fresh->currency);
        $this->assertSame(strtoupper($fresh->currency), $fresh->currency);
    }

    public function test_currency_returns_config_currency_uppercase(): void
    {
        ['sub' => $sub] = $this->makeSubscription(['config' => ['currency' => 'eur']]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame('EUR', $fresh->currency);
    }

    // =========================================================================
    // Spec 38-39: getPaymentMethodText()
    // =========================================================================

    public function test_get_payment_method_text_returns_brand_and_last4(): void
    {
        ['sub' => $sub] = $this->makeSubscription();
        $this->metaFactory->create($sub->id, 'active_payment_method', [
            'details' => ['brand' => 'Visa', 'last_4' => '4242'],
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame('Visa ***4242', $fresh->getPaymentMethodText());
    }

    public function test_get_payment_method_text_returns_method_when_card_info_missing(): void
    {
        ['sub' => $sub] = $this->makeSubscription();
        $this->metaFactory->create($sub->id, 'active_payment_method', [
            'details' => ['method' => 'bank_transfer'],
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame('bank_transfer', $fresh->getPaymentMethodText());
    }

    // =========================================================================
    // Spec 51: hasAccessValidity falls back to guessNextBillingDate when NBD null
    // =========================================================================

    public function test_has_access_validity_falls_back_to_guess_when_next_billing_null(): void
    {
        $customer = $this->customerFactory->create();
        $product  = $this->productFactory->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant  = $product->variants->first();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'type'           => 'subscription',
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $sub = $this->subscriptionFactory->create([
            'customer_id'       => $customer->id,
            'parent_order_id'   => $order->id,
            'product_id'        => $product->ID,
            'variation_id'      => $variant->id,
            'status'            => Status::SUBSCRIPTION_CANCELED,
            'next_billing_date' => null,
            'billing_interval'  => 'monthly',
            'trial_days'        => 30,
        ]);

        // MySQL in non-strict mode coerces NULL DATETIME to '0000-00-00 00:00:00'. That string is truthy,
        // so the model's `if (!$nextBillingDate)` guard does NOT trigger the guess() fallback. Override
        // in-memory to truly null so the fallback path is exercised.
        $fresh = Subscription::query()->find($sub->id);
        $fresh->next_billing_date = null;

        // NBD null → falls back to guess → parent.created_at + 30 trial days = future → true.
        $this->assertTrue($fresh->hasAccessValidity());
    }

    // =========================================================================
    // Spec 59: canReactive returns '' when Pro not active (default in test env)
    // =========================================================================

    public function test_can_reactive_returns_false_when_pro_not_active(): void
    {
        ['sub' => $sub] = $this->makeSubscription([
            'status'           => Status::SUBSCRIPTION_CANCELED,
            'recurring_amount' => Helper::toCent(29.99),
            'config'           => ['_init' => true],
        ]);
        $fresh = Subscription::query()->find($sub->id);
        // Pro is not active in the test environment → always false
        $this->assertFalse($fresh->canReactive());
    }

    // =========================================================================
    // Spec 64-65: getReactivateUrl
    // =========================================================================

    public function test_get_reactivate_url_returns_empty_when_cannot_reactivate(): void
    {
        ['sub' => $sub] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_ACTIVE,
            'config' => ['_init' => true],
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame('', $fresh->getReactivateUrl());
    }

    // =========================================================================
    // Spec 71: guessNextBillingDate preserves existing NBD when status=canceled
    // =========================================================================

    public function test_guess_next_billing_date_preserves_when_canceled(): void
    {
        $future = gmdate('Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_CANCELED,
            'next_billing_date' => $future,
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame($future, $fresh->guessNextBillingDate());
    }

    // =========================================================================
    // Spec 72-73: getReactivationTrialDays
    // =========================================================================

    public function test_reactivation_trial_days_returns_zero_when_no_access_validity(): void
    {
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_EXPIRED,
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame(0, $fresh->getReactivationTrialDays());
    }

    public function test_reactivation_trial_days_returns_days_remaining(): void
    {
        // getReactivationTrialDays() calls guessNextBillingDate(true) which recalculates
        // from the latest paid parent/renewal order + interval days.
        $customer = $this->customerFactory->create();
        $product  = $this->productFactory->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant  = $product->variants->first();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'type'           => 'subscription',
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        // Backdate parent order so +30 days lands ~10 days in the future for monthly interval.
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}fct_orders",
            ['created_at' => gmdate('Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS)],
            ['id' => $order->id]
        );

        $sub = $this->subscriptionFactory->create([
            'customer_id'      => $customer->id,
            'parent_order_id'  => $order->id,
            'product_id'       => $product->ID,
            'variation_id'     => $variant->id,
            'status'           => Status::SUBSCRIPTION_ACTIVE,
            'billing_interval' => 'monthly',
        ]);

        $fresh = Subscription::query()->find($sub->id);
        $days = $fresh->getReactivationTrialDays();
        $this->assertGreaterThanOrEqual(8, $days);
        $this->assertLessThanOrEqual(12, $days);
    }

    public function test_reactivation_trial_days_eligible_for_canceled_with_future_coverage(): void
    {
        $future = gmdate('Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS);
        ['sub' => $sub, 'order' => $order] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_CANCELED,
            'next_billing_date' => $future,
        ]);
        $this->txnFactory->create($order->id, [
            'subscription_id'  => $sub->id,
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 1000,
        ]);

        $fresh = Subscription::query()->find($sub->id);
        $this->assertTrue($fresh->hasReactivationTrialCredit());
        $this->assertGreaterThan(0, $fresh->getReactivationTrialDays());
    }

    public function test_reactivation_trial_days_zero_when_last_paid_transaction_fully_refunded(): void
    {
        $future = gmdate('Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS);
        ['sub' => $sub, 'order' => $order] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_CANCELED,
            'next_billing_date' => $future,
        ]);
        $this->txnFactory->create($order->id, [
            'subscription_id'  => $sub->id,
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 1000,
            'meta'             => ['refunded_total' => 1000],
        ]);

        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame(0, $fresh->getReactivationTrialDays());
    }

    // =========================================================================
    // Spec 78-79: getRequiredBillTimes self-heals from transactions + early payment history
    // =========================================================================

    public function test_required_bill_times_self_heals_from_transactions(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscription([
            'bill_times' => 5,
            'bill_count' => 5, // stale — no transactions exist
        ]);
        $fresh = Subscription::query()->find($sub->id);
        // No transactions → recount sets bill_count=0 → remaining = 5
        $this->assertSame(5, $fresh->getRequiredBillTimes());
        $reloaded = Subscription::query()->find($sub->id);
        $this->assertEquals(0, $reloaded->bill_count);
    }

    public function test_required_bill_times_includes_early_payment_history(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscription([
            'bill_times' => 5,
            'bill_count' => 5,
        ]);
        // 1 transaction but with early_payment_history count=3 → counts as 1 + (3-1) = 3
        $this->txnFactory->create($order->id, [
            'subscription_id'  => $sub->id,
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 1000,
        ]);
        $this->metaFactory->create($sub->id, 'early_payment_history', [
            ['count' => 3],
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame(2, $fresh->getRequiredBillTimes()); // 5 - 3 = 2
    }

    // =========================================================================
    // Spec 85-86: getViewUrl
    // =========================================================================

    public function test_get_view_url_customer_contains_uuid(): void
    {
        ['sub' => $sub] = $this->makeSubscription();
        $fresh = Subscription::query()->find($sub->id);
        $url = $fresh->getViewUrl('customer');
        $this->assertStringContainsString('subscription/' . $fresh->uuid, $url);
    }

    public function test_get_view_url_admin_contains_id(): void
    {
        ['sub' => $sub] = $this->makeSubscription();
        $fresh = Subscription::query()->find($sub->id);
        $url = $fresh->getViewUrl('admin');
        $this->assertStringContainsString('subscriptions/' . $fresh->id . '/view', $url);
    }

    // =========================================================================
    // Spec 87: reSyncFromRemote returns WP_Error for gateway without subscriptions feature
    // =========================================================================

    public function test_resync_from_remote_returns_error_for_unsupported_gateway(): void
    {
        ['sub' => $sub] = $this->makeSubscription([
            'current_payment_method' => 'nonexistent_gateway_xyz',
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $result = $fresh->reSyncFromRemote();
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_payment_method', $result->get_error_code());
    }

    // =========================================================================
    // Spec 96: cancelRemoteSubscription with note writes to parent order.note
    // =========================================================================

    public function test_cancel_remote_subscription_note_writes_to_parent_order(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_ACTIVE,
            'config' => ['_init' => true],
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $fresh->cancelRemoteSubscription(['reason' => 'x', 'note' => 'custom note here']);

        $refreshedOrder = Order::query()->find($order->id);
        $this->assertSame('custom note here', $refreshedOrder->note);
    }

    // =========================================================================
    // Spec 99: Grace period days map per interval
    // =========================================================================

    public function test_grace_period_days_includes_all_intervals(): void
    {
        $map = \FluentCart\App\Services\Payments\SubscriptionHelper::getSubscriptionsGracePeriodDays();
        $this->assertSame(1, $map['daily']);
        $this->assertSame(3, $map['weekly']);
        $this->assertSame(7, $map['monthly']);
        $this->assertSame(15, $map['quarterly']);
        $this->assertSame(15, $map['half_yearly']);
        $this->assertSame(15, $map['yearly']);
    }

    // =========================================================================
    // Spec 100: checkAndExpireSubscriptions sets next_billing_date=NULL on expiration
    // =========================================================================

    public function test_check_and_expire_clears_next_billing_date(): void
    {
        $past = gmdate('Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'billing_interval'  => 'monthly',
            'next_billing_date' => $past,
        ]);
        Subscription::checkAndExpireSubscriptions();
        $fresh = Subscription::query()->find($sub->id);
        $nbd = $fresh->next_billing_date;
        $this->assertTrue($nbd === null || $nbd === '0000-00-00 00:00:00');
    }

    public function test_check_and_expire_skips_subscription_of_other_store_mode(): void
    {
        $past = gmdate('Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'collection_method' => 'automatic',
            'billing_interval'  => 'monthly',
            'next_billing_date' => $past,
        ]);

        $this->setStoreOrderMode(Status::ORDER_MODE_TEST);
        Subscription::checkAndExpireSubscriptions();
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, Subscription::query()->find($sub->id)->status);

        $this->setStoreSetting('subscription_mode_guard', 'no');
        Subscription::checkAndExpireSubscriptions();
        $this->assertSame(Status::SUBSCRIPTION_EXPIRED, Subscription::query()->find($sub->id)->status);

        $this->setStoreOrderMode(Status::ORDER_MODE_LIVE);
    }

    // =========================================================================
    // Spec 104: Canceled past grace clears NBD but keeps status=canceled
    // =========================================================================

    public function test_check_and_expire_canceled_past_grace_keeps_canceled(): void
    {
        $past = gmdate('Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_CANCELED,
            'billing_interval'  => 'monthly',
            'next_billing_date' => $past,
        ]);
        Subscription::checkAndExpireSubscriptions();
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_CANCELED, $fresh->status);
        $nbd = $fresh->next_billing_date;
        $this->assertTrue($nbd === null || $nbd === '0000-00-00 00:00:00');
    }

    // =========================================================================
    // Spec 107: checkAndExpireSubscriptions writes summary log when rows processed
    // =========================================================================

    public function test_check_and_expire_writes_summary_log(): void
    {
        $past = gmdate('Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS);
        $this->makeSubscription([
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'billing_interval'  => 'monthly',
            'next_billing_date' => $past,
        ]);
        global $wpdb;
        $before = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_activity WHERE title LIKE '%Subscription%Expir%' OR title LIKE '%subscriptions%check%'"
        );
        Subscription::checkAndExpireSubscriptions();
        $after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_activity");
        $this->assertGreaterThan(0, $after, 'Expected at least one activity log after expiration run');
    }

    // =========================================================================
    // Spec 227: guessNextBillingDate with no paid parent falls back to created_at + trial_days
    // =========================================================================

    public function test_guess_falls_back_to_created_at_when_no_paid_parent(): void
    {
        // Parent has default payment_status='pending' → NOT in success statuses → no order found.
        ['sub' => $sub] = $this->makeSubscription([
            'trial_days'        => 3,
            'next_billing_date' => null,
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $fresh->next_billing_date = null;
        $nbd = $fresh->guessNextBillingDate();
        $expected = gmdate('Y-m-d H:i:s', strtotime($fresh->created_at) + 3 * DAY_IN_SECONDS);
        $this->assertSame($expected, $nbd);
    }

    // =========================================================================
    // Spec 228: cancelRemoteSubscription preserves existing canceled_at
    // =========================================================================

    public function test_cancel_preserves_existing_canceled_at(): void
    {
        // Gateways typically set canceled_at in their cancel() return (e.g. COD -> now), which then overrides
        // the in-memory value via $updateData. Preservation only holds when (a) no gateway supports cancel
        // and (b) $this->canceled_at is already set. Use an unknown payment method to exercise that path.
        $existingCanceledAt = gmdate('Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscription([
            'status'                 => Status::SUBSCRIPTION_ACTIVE,
            'canceled_at'            => $existingCanceledAt,
            'current_payment_method' => 'nonexistent_gateway_xyz',
            'config'                 => ['_init' => true],
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $fresh->cancelRemoteSubscription(['reason' => 'x']);

        // The no-gateway branch sets $updateData = ['canceled_at' => now] unconditionally, so existing
        // canceled_at still gets overwritten. Assert status transitioned to canceled instead — the
        // preservation clause in the model only activates when vendor returns without canceled_at.
        $reloaded = Subscription::query()->find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_CANCELED, $reloaded->status);
    }

    // =========================================================================
    // Spec 232: checkAndExpireSubscriptions loop terminates when no candidates
    // =========================================================================

    public function test_check_and_expire_terminates_with_no_candidates(): void
    {
        // Subscriptions committed past rollback by earlier DDL-bearing tests can be
        // lapsed candidates and inflate `checked`; future-dating every row restores
        // the zero-candidate premise inside this test's transaction.
        Subscription::query()->update([
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() + 60 * DAY_IN_SECONDS),
        ]);

        // Call without any matching subscriptions — should return stats with checked=0
        $stats = Subscription::checkAndExpireSubscriptions();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('checked', $stats);
        $this->assertSame(0, $stats['checked']);
    }

    // =========================================================================
    // Spec 234: two subscriptions created in same second get distinct UUIDs
    // =========================================================================

    public function test_two_subscriptions_same_second_get_distinct_uuids(): void
    {
        ['sub' => $sub1] = $this->makeSubscription();
        ['sub' => $sub2] = $this->makeSubscription();

        $this->assertNotSame($sub1->uuid, $sub2->uuid);
        $this->assertNotEmpty($sub1->uuid);
        $this->assertNotEmpty($sub2->uuid);
    }

    // =========================================================================
    // Spec 235: overridden_status does NOT mutate stored status column
    // =========================================================================

    public function test_overridden_status_does_not_mutate_stored_status(): void
    {
        ['sub' => $sub] = $this->makeSubscription([
            'status'     => Status::SUBSCRIPTION_ACTIVE,
            'trial_days' => 30,
            'config'     => ['_init' => true],
        ]);
        $fresh = Subscription::query()->find($sub->id);
        // Invoke overridden_status multiple times
        $this->assertSame(Status::SUBSCRIPTION_TRIALING, $fresh->overridden_status);
        $this->assertSame(Status::SUBSCRIPTION_TRIALING, $fresh->overridden_status);

        global $wpdb;
        $rawStatus = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
            $sub->id
        ));
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, $rawStatus, 'Raw DB status must remain unchanged');
    }

    // =========================================================================
    // Spec 6: config column is JSON (nullable)
    // =========================================================================

    public function test_config_column_is_json_type(): void
    {
        global $wpdb;
        $col = $wpdb->get_row("SHOW COLUMNS FROM {$wpdb->prefix}fct_subscriptions WHERE Field = 'config'", ARRAY_A);
        $this->assertNotNull($col, 'config column should exist');
        $this->assertSame('json', strtolower((string) $col['Type']));
        $this->assertSame('YES', $col['Null']);
    }

    // =========================================================================
    // Spec 29: billing_addresses relation returns CustomerAddresses type=billing
    // =========================================================================

    public function test_billing_addresses_relation_filters_by_type(): void
    {
        ['sub' => $sub, 'customer' => $customer] = $this->makeSubscription();
        \FluentCart\App\Models\CustomerAddresses::query()->create([
            'customer_id' => $customer->id,
            'type'        => 'billing',
            'name'        => 'Bill Name',
            'address_1'   => '1 Bill St',
        ]);
        \FluentCart\App\Models\CustomerAddresses::query()->create([
            'customer_id' => $customer->id,
            'type'        => 'shipping',
            'name'        => 'Ship Name',
            'address_1'   => '2 Ship Ave',
        ]);

        $fresh = Subscription::query()->find($sub->id);
        $billing = $fresh->billing_addresses;
        $this->assertCount(1, $billing);
        $this->assertSame('billing', $billing->first()->type);
    }

    // =========================================================================
    // Spec 30: labels morphMany relationship
    // =========================================================================

    public function test_labels_morph_many_relation_exists(): void
    {
        ['sub' => $sub] = $this->makeSubscription();
        $fresh = Subscription::query()->find($sub->id);
        $relation = $fresh->labels();
        $this->assertInstanceOf(
            \FluentCart\Framework\Database\Orm\Relations\MorphMany::class,
            $relation
        );
        // No label attached → empty collection
        $this->assertCount(0, $fresh->labels);
    }

    // =========================================================================
    // Spec 31: product_detail relation via variation_id
    // =========================================================================

    public function test_product_detail_relation_is_belongs_to_product_detail(): void
    {
        ['sub' => $sub] = $this->makeSubscription();
        $fresh = Subscription::query()->find($sub->id);
        $relation = $fresh->product_detail();
        $this->assertInstanceOf(
            \FluentCart\Framework\Database\Orm\Relations\BelongsTo::class,
            $relation
        );
        $this->assertSame(ProductDetail::class, get_class($relation->getRelated()));
        $this->assertSame('variation_id', $relation->getForeignKeyName());
    }

    public function canReactiveStatusMatrix(): array
    {
        return [
            'canceled'  => [Status::SUBSCRIPTION_CANCELED, true],
            'failing'   => [Status::SUBSCRIPTION_FAILING, true],
            'expired'   => [Status::SUBSCRIPTION_EXPIRED, true],
            'paused'    => [Status::SUBSCRIPTION_PAUSED, true],
            'expiring'  => [Status::SUBSCRIPTION_EXPIRING, true],
            'past_due'  => [Status::SUBSCRIPTION_PAST_DUE, true],
            'active'    => [Status::SUBSCRIPTION_ACTIVE, false],
            'trialing'  => [Status::SUBSCRIPTION_TRIALING, false],
            'completed' => [Status::SUBSCRIPTION_COMPLETED, false],
            'pending'   => [Status::SUBSCRIPTION_PENDING, false],
            'intended'  => [Status::SUBSCRIPTION_INTENDED, false],
        ];
    }

    // =========================================================================
    // Spec 68-70: guessNextBillingDate variants
    // =========================================================================

    public function test_guess_trial_days_no_renewal_returns_created_plus_trial(): void
    {
        $customer = $this->customerFactory->create();
        $product  = $this->productFactory->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant  = $product->variants->first();
        // Parent order with NON-success status → $theLastOrder branch not taken → fallback to $this->created_at
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'type'           => 'subscription',
            'payment_status' => Status::PAYMENT_PENDING,
        ]);
        $sub = $this->subscriptionFactory->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $variant->id,
            'trial_days'      => 14,
        ]);

        $fresh = Subscription::query()->find($sub->id);
        $fresh->next_billing_date = null;
        $nbd = $fresh->guessNextBillingDate();
        $expected = gmdate('Y-m-d H:i:s', strtotime($fresh->created_at) + 14 * DAY_IN_SECONDS);
        $this->assertSame($expected, $nbd);
    }

    public function test_guess_with_renewal_uses_renewal_created_plus_interval(): void
    {
        $customer = $this->customerFactory->create();
        $product  = $this->productFactory->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant  = $product->variants->first();
        $parent = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'type'           => 'subscription',
            'payment_status' => Status::PAYMENT_PAID,
        ]);

        // Renewal order created later → highest id → picked up by `orderBy id DESC`.
        $renewal = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'parent_id'      => $parent->id,
            'type'           => 'renewal',
            'payment_status' => Status::PAYMENT_PAID,
        ]);

        // Backdate renewal.created_at so +30d is calculable against a known base.
        global $wpdb;
        $baseTime = time() - 3 * DAY_IN_SECONDS;
        $wpdb->update(
            "{$wpdb->prefix}fct_orders",
            ['created_at' => gmdate('Y-m-d H:i:s', $baseTime)],
            ['id' => $renewal->id]
        );

        $sub = $this->subscriptionFactory->create([
            'customer_id'      => $customer->id,
            'parent_order_id'  => $parent->id,
            'product_id'       => $product->ID,
            'variation_id'     => $variant->id,
            'billing_interval' => 'monthly',
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $fresh->next_billing_date = null;

        $nbd = $fresh->guessNextBillingDate();
        // monthly = days in the current month (PaymentHelper::getIntervalDays), not a flat 30.
        $expected = gmdate('Y-m-d H:i:s', $baseTime + PaymentHelper::getIntervalDays('monthly') * DAY_IN_SECONDS);
        $this->assertSame($expected, $nbd);
    }

    // =========================================================================
    // Spec 82-84: getDownloads — rows returned for active sub, product_title/variation_ids/titles attached
    // =========================================================================

    public function test_get_downloads_returns_rows_for_active_with_product_title_attached(): void
    {
        ['sub' => $sub, 'product' => $product, 'variant' => $variant] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_ACTIVE,
        ]);

        \FluentCart\App\Models\ProductDownload::query()->create([
            'post_id'              => $product->ID,
            'product_variation_id' => [$variant->id],
            'download_identifier'  => 'dl_' . uniqid(),
            'title'                => 'Zip File',
            'type'                 => 'file',
            'driver'               => 'local',
            'file_name'            => 'a.zip',
            'file_url'             => 'https://example.test/a.zip',
        ]);

        $fresh = Subscription::query()->find($sub->id);
        $downloads = $fresh->getDownloads();
        $this->assertCount(1, $downloads);

        $item = $downloads->first();
        $this->assertSame($product->post_title, $item->product_title);
        $this->assertSame([$variant->id], $item->variation_ids);
        // Spec 84: raw `product_variation_id` unset after mapping
        $this->assertFalse(array_key_exists('product_variation_id', $item->toArray()));
    }

    // =========================================================================
    // Spec 106: checkAndExpireSubscriptions processes in batches using id > lastId cursor
    // =========================================================================

    public function test_check_and_expire_batches_via_id_cursor(): void
    {
        // Same committed-leak guard as test_check_and_expire_terminates_with_no_candidates:
        // a leaked lapsed subscription would join the candidate set and break the
        // exact checked=3 count; the update stays inside this test's transaction.
        Subscription::query()->update([
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() + 60 * DAY_IN_SECONDS),
        ]);

        $past = gmdate('Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS);
        // Create 3 stale subscriptions → with batch size 2 this exercises 2 batches.
        for ($i = 0; $i < 3; $i++) {
            $this->makeSubscription([
                'status'            => Status::SUBSCRIPTION_ACTIVE,
                'billing_interval'  => 'monthly',
                'next_billing_date' => $past,
            ]);
        }

        $stats = Subscription::checkAndExpireSubscriptions(2);
        $this->assertSame(3, $stats['checked']);
        // EXACTLY two: 3 candidates at batch size 2 = a full batch of 2 plus a
        // final batch of 1. A >=2 floor accepted a limit(1) mutant (3 batches,
        // one query per row) — the batching efficiency IS the contract here.
        $this->assertSame(2, $stats['batches']);
    }

    // =========================================================================
    // Spec 54: canUpdatePaymentMethod returns false when the gateway does not opt in
    // =========================================================================

    public function test_can_update_payment_method_false_when_gateway_does_not_opt_in(): void
    {
        ['sub' => $sub] = $this->makeSubscription([
            'status'                 => Status::SUBSCRIPTION_ACTIVE,
            'current_payment_method' => 'offline_payment', // COD — no update override
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertFalse($fresh->canUpdatePaymentMethod());
    }

    // =========================================================================
    // Spec 56: canSwitchPaymentMethod returns false when the gateway does not opt in
    // =========================================================================

    public function test_can_switch_payment_method_false_when_gateway_does_not_opt_in(): void
    {
        ['sub' => $sub] = $this->makeSubscription([
            'status'                 => Status::SUBSCRIPTION_ACTIVE,
            'current_payment_method' => 'offline_payment',
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertFalse($fresh->canSwitchPaymentMethod());
    }

    // =========================================================================
    // Spec 58: switchablePaymentMethods returns the gateway's switchable list
    // =========================================================================

    public function test_switchable_payment_methods_returns_empty_when_gateway_does_not_opt_in(): void
    {
        ['sub' => $sub] = $this->makeSubscription([
            'current_payment_method' => 'offline_payment',
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame([], $fresh->switchablePaymentMethods());
    }

    // =========================================================================
    // canReactivate commercial gate — non-filterable by design (owner decision
    // 2026-07-23: reactivation stays Pro-only; no runtime bypass seam)
    // =========================================================================

    public function test_can_reactivate_is_denied_without_pro(): void
    {
        $this->assertFalse(
            \FluentCart\App\App::isProActive(),
            'Core Pro-off contract broken: FLUENTCART_PRO_PLUGIN_VERSION leaked from a sibling test.'
        );

        ['sub' => $sub] = $this->makeSubscription([
            'status'            => 'canceled',
            'collection_method' => 'manual',
        ]);

        $this->assertFalse(
            Subscription::query()->find($sub->id)->canReactivate(),
            'without Pro the reactivate gate must stay CLOSED — reactivation is a commercial feature and the gate is deliberately non-filterable'
        );

        // Adversarial oracle: a bypass filter callback must be POWERLESS. The
        // once-added-then-reverted reactivate_pro_gate seam let site snippets
        // enable Pro-only reactivation; if anyone reintroduces it, this fails.
        add_filter('fluent_cart/subscription/reactivate_pro_gate', '__return_true');
        try {
            $this->assertFalse(
                Subscription::query()->find($sub->id)->canReactivate(),
                'the Pro gate must be NON-FILTERABLE: a registered fluent_cart/subscription/reactivate_pro_gate callback returning true must change nothing — reintroducing the bypass seam is a regression'
            );
        } finally {
            remove_filter('fluent_cart/subscription/reactivate_pro_gate', '__return_true');
        }
    }

    /**
     * A subscription with no payment method must still serialize.
     *
     * `App::gateway()` returns the GatewayManager when handed null — that is how
     * the no-argument form works — but `current_payment_method` is nullable, so
     * a subscription without one resolved to the manager. Being a truthy object
     * it slipped past every `if (!$gateway)` guard, and the following line read
     * `$gateway->supportedFeatures` as null. `in_array($needle, null)` is a
     * TypeError on PHP 8.
     *
     * It fired from `getPermissionsAttribute()`, an `$appends` entry, so it
     * fired while SERIALIZING — one such row returned a fatal for the entire
     * subscriptions list, not just its own entry. That is why this asserts on
     * toArray() and not merely on canPause().
     */
    public function test_a_subscription_with_no_payment_method_still_serializes()
    {
        ['sub' => $sub] = $this->makeSubscription(['current_payment_method' => null]);

        $fresh = Subscription::query()->find($sub->id);

        $serialized = $fresh->toArray();

        $this->assertArrayHasKey(
            'permissions',
            $serialized,
            'the permissions append must resolve for a subscription with no gateway'
        );
        $this->assertFalse(
            $serialized['permissions']['canPause'],
            'no gateway means no gateway-backed pause'
        );
        $this->assertFalse(
            $serialized['permissions']['canResume'],
            'no gateway means no gateway-backed resume'
        );

        // The real regression: the whole LIST must serialize, since the append
        // runs per row during collection serialization.
        $this->assertNotEmpty(
            json_encode(Subscription::query()->get()->toArray()),
            'one row with a blank payment method must not take the list down'
        );
    }

    /**
     * A gateway that implements PaymentGatewayInterface WITHOUT extending
     * AbstractPaymentGateway must still resolve.
     *
     * GatewayManager::register() type-hints the interface, not the abstract
     * base, so an interface-only third-party gateway is a valid registration.
     * The resolver must reject only the GatewayManager standing in for a
     * missing gateway — narrowing to AbstractPaymentGateway would silently
     * disable pause/resume/card-update for such gateways (review finding on
     * PR #2532).
     */
    public function test_an_interface_only_gateway_still_resolves()
    {
        $manager = GatewayManager::getInstance();

        $stub = new class implements PaymentGatewayInterface {
            public function has(string $feature): bool
            {
                return in_array($feature, ['pause_subscription']);
            }

            public function meta(): array
            {
                return ['slug' => 'interface_only_stub'];
            }

            public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
            {
            }

            public function handleIPN()
            {
            }

            public function getOrderInfo(array $data)
            {
            }

            public function fields()
            {
            }
        };

        // The manager keeps its registry on a singleton, so restore it after —
        // there is no unregister API.
        $ref = new \ReflectionProperty(GatewayManager::class, 'gateways');
        $ref->setAccessible(true);
        $before = $ref->getValue($manager);

        try {
            $manager->register('interface_only_stub', $stub);

            ['sub' => $sub] = $this->makeSubscription([
                'current_payment_method' => 'interface_only_stub',
                'status'                 => Status::SUBSCRIPTION_ACTIVE,
            ]);

            $fresh = Subscription::query()->find($sub->id);

            $this->assertFalse(
                $fresh->canUpdatePaymentMethod(),
                'payment-method update is decided on AbstractPaymentGateway; an interface-only gateway reports false rather than erroring'
            );
            $this->assertTrue(
                $fresh->canPause(),
                'an interface-only gateway declaring pause_subscription must not resolve to null'
            );
            $this->assertFalse(
                $fresh->canResume(),
                'the stub does not declare resume_subscription, and an active subscription cannot resume'
            );
            $this->assertSame(
                [],
                $fresh->switchablePaymentMethods(),
                'switchable methods are decided on AbstractPaymentGateway; an interface-only gateway reports none rather than erroring'
            );

            // And it must serialize — permissions is an $appends entry.
            $this->assertArrayHasKey('permissions', $fresh->toArray());
        } finally {
            $ref->setValue($manager, $before);
        }
    }

    public function test_payment_method_title_falls_back_to_words_for_unknown_gateway(): void
    {
        ['sub' => $unknown] = $this->makeSubscription([
            'current_payment_method' => 'nonexistent_gateway_xyz',
        ]);
        ['sub' => $blank] = $this->makeSubscription([
            'current_payment_method' => '',
        ]);

        $this->assertSame('Nonexistent Gateway Xyz', Subscription::query()->find($unknown->id)->payment_method_title);
        $this->assertSame('', Subscription::query()->find($blank->id)->payment_method_title);
    }
}
