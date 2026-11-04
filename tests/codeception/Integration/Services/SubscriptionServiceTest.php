<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\EarlyPaymentFeature;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\SubscriptionHelper;
use FluentCart\App\Services\PlanUpgradeService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\MetaFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

class SubscriptionServiceTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private ProductFactory $productFactory;
    private OrderFactory $orderFactory;
    private SubscriptionFactory $subscriptionFactory;
    private OrderTransactionFactory $txnFactory;
    private OrderItemFactory $itemFactory;
    private OrderAddressFactory $addressFactory;
    private MetaFactory $metaFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory    = new CustomerFactory();
        $this->productFactory     = new ProductFactory();
        $this->orderFactory       = new OrderFactory();
        $this->subscriptionFactory = new SubscriptionFactory();
        $this->txnFactory         = new OrderTransactionFactory();
        $this->itemFactory        = new OrderItemFactory();
        $this->addressFactory     = new OrderAddressFactory();
        $this->metaFactory        = new MetaFactory();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeSubscriptionSetup(array $subOverrides = [], array $itemOverrides = []): array
    {
        $customer = $this->customerFactory->create();
        $product  = $this->productFactory->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant  = $product->variants->first();
        $order    = $this->orderFactory->withAddresses()->create([
            'customer_id' => $customer->id,
            'type'        => 'subscription',
        ]);
        $item = $this->itemFactory->create($order->id, array_merge([
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'payment_type' => Status::ORDER_TYPE_SUBSCRIPTION,
        ], $itemOverrides));
        $sub = $this->subscriptionFactory->create(array_merge([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $variant->id,
        ], $subOverrides));

        return compact('customer', 'product', 'variant', 'order', 'item', 'sub');
    }

    // =========================================================================
    // Spec 108-110: recordRenewalPayment error cases
    // =========================================================================

    public function test_record_renewal_payment_returns_error_when_subscription_not_found(): void
    {
        $result = SubscriptionService::recordRenewalPayment([
            'subscription_id' => 999999,
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('subscription_not_found', $result->get_error_code());
    }

    public function test_record_renewal_payment_returns_error_when_transaction_already_exists(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscriptionSetup();

        // Pre-create a transaction with same vendor_charge_id
        $this->txnFactory->create($order->id, [
            'subscription_id'  => $sub->id,
            'vendor_charge_id' => 'ch_duplicate_123',
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
        ]);

        $result = SubscriptionService::recordRenewalPayment([
            'subscription_id' => $sub->id,
            'vendor_charge_id' => 'ch_duplicate_123',
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('transaction_exists', $result->get_error_code());
    }

    // Stripe reuses the same PaymentIntent across dunning retries on one invoice.
    // A prior failed attempt must not block the retry that eventually succeeds.
    public function test_record_renewal_payment_recovers_when_prior_attempt_on_same_payment_intent_failed(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscriptionSetup();

        $renewal = $this->orderFactory->withAddresses()->create([
            'customer_id'    => $order->customer_id,
            'parent_id'      => $order->id,
            'type'           => Status::ORDER_TYPE_RENEWAL,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);
        $failedTxn = $this->txnFactory->create($renewal->id, [
            'subscription_id'  => $sub->id,
            'vendor_charge_id' => 'pi_retry_123',
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status'           => Status::TRANSACTION_FAILED,
        ]);

        $result = SubscriptionService::recordRenewalPayment([
            'subscription_id'  => $sub->id,
            'vendor_charge_id' => 'pi_retry_123',
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => $sub->recurring_total,
        ], $sub);

        $this->assertNotInstanceOf(\WP_Error::class, $result, 'Should succeed: ' . (is_wp_error($result) ? $result->get_error_message() : ''));
        $this->assertSame($failedTxn->id, $result->id, 'Should update the existing failed transaction in place, not create a duplicate');
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $result->status);

        $this->assertSame(
            1,
            OrderTransaction::query()->where('vendor_charge_id', 'pi_retry_123')->count(),
            'Must not leave a duplicate transaction row for the same PaymentIntent'
        );
    }

    // A failed row from a DIFFERENT PaymentIntent on the same invoice must not be
    // mistaken for the one being retried — that would destroy an unrelated attempt's
    // history and leave the actual matching failed row untouched.
    public function test_record_renewal_payment_does_not_overwrite_unrelated_failed_transaction(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscriptionSetup();

        $renewal = $this->orderFactory->withAddresses()->create([
            'customer_id'    => $order->customer_id,
            'parent_id'      => $order->id,
            'type'           => Status::ORDER_TYPE_RENEWAL,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);
        $unrelatedFailedTxn = $this->txnFactory->create($renewal->id, [
            'subscription_id'  => $sub->id,
            'vendor_charge_id' => 'pi_other_attempt_999',
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status'           => Status::TRANSACTION_FAILED,
        ]);

        $result = SubscriptionService::recordRenewalPayment([
            'subscription_id'  => $sub->id,
            'vendor_charge_id' => 'pi_retry_123',
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => $sub->recurring_total,
        ], $sub);

        $this->assertNotInstanceOf(\WP_Error::class, $result, 'Should succeed: ' . (is_wp_error($result) ? $result->get_error_message() : ''));
        $this->assertNotSame($unrelatedFailedTxn->id, $result->id, 'Must not reuse a failed row belonging to a different PaymentIntent');

        $untouched = OrderTransaction::query()->find($unrelatedFailedTxn->id);
        $this->assertSame(Status::TRANSACTION_FAILED, $untouched->status, 'Unrelated failed transaction must be left as-is');
        $this->assertSame('pi_other_attempt_999', $untouched->vendor_charge_id);
    }

    // The recovery branch (update-in-place on an existing pending/failed transaction)
    // must release its GET_LOCK before returning, same as every other exit path.
    public function test_record_renewal_payment_releases_webhook_lock_after_recovering_existing_transaction(): void
    {
        global $wpdb;

        ['sub' => $sub, 'order' => $order] = $this->makeSubscriptionSetup();

        $renewal = $this->orderFactory->withAddresses()->create([
            'customer_id'    => $order->customer_id,
            'parent_id'      => $order->id,
            'type'           => Status::ORDER_TYPE_RENEWAL,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);
        $this->txnFactory->create($renewal->id, [
            'subscription_id'  => $sub->id,
            'vendor_charge_id' => 'pi_lock_release_123',
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status'           => Status::TRANSACTION_FAILED,
        ]);

        $result = SubscriptionService::recordRenewalPayment([
            'subscription_id'  => $sub->id,
            'vendor_charge_id' => 'pi_lock_release_123',
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => $sub->recurring_total,
        ], $sub);

        $this->assertNotInstanceOf(\WP_Error::class, $result, 'Should succeed: ' . (is_wp_error($result) ? $result->get_error_message() : ''));

        // IS_USED_LOCK reports server-wide lock ownership (not just this connection's),
        // so a non-null result here proves the lock genuinely leaked past the return.
        $isUsed = $wpdb->get_var($wpdb->prepare("SELECT IS_USED_LOCK(%s)", 'fc_webhook_pi_lock_release_123'));
        $this->assertNull($isUsed, 'Webhook lock must be released after the recovery path returns');
    }

    // =========================================================================
    // Spec 111-123: recordRenewalPayment success path
    // =========================================================================

    public function test_record_renewal_payment_creates_child_renewal_order(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscriptionSetup();

        $result = SubscriptionService::recordRenewalPayment([
            'subscription_id' => $sub->id,
            'status'          => Status::TRANSACTION_SUCCEEDED,
            'total'           => $sub->recurring_total,
        ], $sub);

        $this->assertNotInstanceOf(\WP_Error::class, $result, 'Should succeed: ' . (is_wp_error($result) ? $result->get_error_message() : ''));

        // Verify a child order was created
        $childOrder = Order::query()
            ->where('parent_id', $order->id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->first();
        $this->assertNotNull($childOrder, 'Child renewal order should be created');
        $this->assertNotNull($childOrder->billing_address, 'Renewal must retain the parent billing snapshot.');
        $this->assertNotNull($childOrder->shipping_address, 'Renewal must retain the parent shipping snapshot.');
        $this->assertSame($order->billing_address->address_1, $childOrder->billing_address->address_1, 'Renewal billing must come from the parent order.');
        $this->assertSame($order->shipping_address->address_1, $childOrder->shipping_address->address_1, 'Renewal shipping must come from the parent order.');
    }

    public function test_record_renewal_payment_child_order_has_correct_payment_status(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscriptionSetup();

        SubscriptionService::recordRenewalPayment([
            'subscription_id' => $sub->id,
            'status'          => Status::TRANSACTION_SUCCEEDED,
            'total'           => $sub->recurring_total,
        ], $sub);

        $childOrder = Order::query()
            ->where('parent_id', $order->id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->first();
        $this->assertSame(Status::PAYMENT_PAID, $childOrder->payment_status);
    }

    public function test_record_renewal_payment_creates_transaction(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup();

        $result = SubscriptionService::recordRenewalPayment([
            'subscription_id' => $sub->id,
            'status'          => Status::TRANSACTION_SUCCEEDED,
            'total'           => $sub->recurring_total,
        ], $sub);

        $this->assertInstanceOf(OrderTransaction::class, $result);
        $this->assertSame(Status::TRANSACTION_TYPE_CHARGE, $result->transaction_type);
    }

    public function test_record_renewal_payment_creates_order_item_with_subscription_payment_type(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscriptionSetup();

        SubscriptionService::recordRenewalPayment([
            'subscription_id' => $sub->id,
            'status'          => Status::TRANSACTION_SUCCEEDED,
            'total'           => $sub->recurring_total,
        ], $sub);

        $childOrder = Order::query()
            ->where('parent_id', $order->id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->first();

        $item = OrderItem::query()
            ->where('order_id', $childOrder->id)
            ->where('payment_type', Status::ORDER_TYPE_SUBSCRIPTION)
            ->first();

        $this->assertNotNull($item, 'Renewal order item with payment_type=subscription should be created');
    }

    public function test_record_renewal_payment_dispatches_subscription_renewed_event(): void
    {
        $eventFired = false;
        add_action('fluent_cart/subscription_renewed', function () use (&$eventFired) {
            $eventFired = true;
        });

        ['sub' => $sub] = $this->makeSubscriptionSetup();
        SubscriptionService::recordRenewalPayment([
            'subscription_id' => $sub->id,
            'status'          => Status::TRANSACTION_SUCCEEDED,
            'total'           => $sub->recurring_total,
        ], $sub);

        $this->assertTrue($eventFired, 'SubscriptionRenewed event should fire');
    }

    public function test_record_renewal_payment_increments_bill_count(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup(['bill_count' => 0]);

        SubscriptionService::recordRenewalPayment([
            'subscription_id' => $sub->id,
            'status'          => Status::TRANSACTION_SUCCEEDED,
            'total'           => $sub->recurring_total,
        ], $sub);

        $fresh = Subscription::query()->find($sub->id);
        $this->assertEquals(1, $fresh->bill_count); // DB returns string, use loose equality
    }

    // A resync recovering a cycle charged in the past passes the gateway's
    // settlement moment as created_at. completed_at describes the same event,
    // so it must come from the same clock — not the wall-clock moment the
    // admin happened to click sync (which booked 2024 revenue into 2026).
    public function test_record_renewal_payment_dates_completed_at_by_the_settlement_moment(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscriptionSetup();

        SubscriptionService::recordRenewalPayment([
            'subscription_id' => $sub->id,
            'status'          => Status::TRANSACTION_SUCCEEDED,
            'total'           => $sub->recurring_total,
            'created_at'      => '2024-05-03 11:33:00',
        ], $sub);

        $childOrder = Order::query()
            ->where('parent_id', $order->id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->first();

        $this->assertSame('2024-05-03 11:33:00', (string) $childOrder->created_at);
        $this->assertSame(
            '2024-05-03 11:33:00',
            (string) $childOrder->completed_at,
            'completed_at must be the settlement moment, not the resync run time'
        );
    }

    // Same defect on the pending-invoice branch: recordManualRenewal completes
    // a pre-existing renewal order, so the settlement moment arrives as
    // meta.settled_at on the transaction instead of created_at on the order.
    public function test_record_manual_renewal_dates_completed_at_by_settled_at(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscriptionSetup();

        $invoice = $this->orderFactory->withAddresses()->create([
            'customer_id'    => $order->customer_id,
            'parent_id'      => $order->id,
            'type'           => Status::ORDER_TYPE_RENEWAL,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => $sub->recurring_total,
            'total_amount'   => $sub->recurring_total,
            'total_paid'     => 0,
        ]);
        $this->txnFactory->create($invoice->id, [
            'subscription_id'  => $sub->id,
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status'           => Status::TRANSACTION_PENDING,
            'vendor_charge_id' => '',
            'total'            => $sub->recurring_total,
        ]);

        SubscriptionService::recordRenewalPayment([
            'subscription_id'  => $sub->id,
            'vendor_charge_id' => 'ch_settled_' . uniqid(),
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => $sub->recurring_total,
            'meta'             => ['settled_at' => '2026-08-11 10:12:29'],
        ], $sub);

        $invoice = Order::query()->find($invoice->id);
        $this->assertSame(Status::PAYMENT_PAID, $invoice->payment_status);
        $this->assertSame(
            '2026-08-11 10:12:29',
            (string) $invoice->completed_at,
            'completed_at must be the remote settlement moment, not the local processing time'
        );
    }

    // =========================================================================
    // Spec 124-134: syncSubscriptionStates
    // =========================================================================

    public function test_sync_subscription_states_recalculates_bill_count(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscriptionSetup(['bill_times' => 5, 'bill_count' => 0]);

        // Create 2 succeeded charge transactions
        for ($i = 0; $i < 2; $i++) {
            $this->txnFactory->create($order->id, [
                'subscription_id'  => $sub->id,
                'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
                'status'           => Status::TRANSACTION_SUCCEEDED,
                'total'            => Helper::toCent(29.99),
            ]);
        }

        $updated = SubscriptionService::syncSubscriptionStates($sub, ['status' => Status::SUBSCRIPTION_ACTIVE]);
        $this->assertSame(2, $updated->bill_count);
    }

    public function test_sync_subscription_states_sets_completed_when_bill_count_meets_bill_times(): void
    {
        // config must be non-empty to avoid core bug when SubscriptionEOT calls cancelRemoteSubscription
        ['sub' => $sub, 'order' => $order] = $this->makeSubscriptionSetup([
            'bill_times'        => 3,
            'bill_count'        => 0,
            'config'            => ['_init' => true],
            // Sentinel: an active subscription has a scheduled billing date.
            // Starting NULL would make the null assert below a dead oracle.
            'next_billing_date' => '2027-01-01 00:00:00',
        ]);
        $this->assertSame('2027-01-01 00:00:00', $sub->next_billing_date, 'precondition: fixture must start with a scheduled billing date');

        for ($i = 0; $i < 3; $i++) {
            $this->txnFactory->create($order->id, [
                'subscription_id'  => $sub->id,
                'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
                'status'           => Status::TRANSACTION_SUCCEEDED,
                'total'            => Helper::toCent(29.99),
            ]);
        }

        $updated = SubscriptionService::syncSubscriptionStates($sub);
        $this->assertSame(Status::SUBSCRIPTION_COMPLETED, $updated->status);
        $this->assertNull($updated->next_billing_date);

        $fresh = Subscription::find($sub->id);
        $this->assertNull($fresh->next_billing_date, 'completion must persist a NULL next_billing_date, not just mutate the in-memory model');
    }

    public function test_cancel_remote_subscription_immediately_ends_billing_schedule_of_active_subscription(): void
    {
        // Sibling branch of the completed-status guard: for a subscription that
        // stays NON-completed, effective_from=immediately must replace the
        // future schedule with the cancellation moment — hasAccessValidity
        // otherwise keeps granting access until the old date.
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'config'            => ['_init' => true],
            'next_billing_date' => '2036-01-01 00:00:00',
        ]);

        $before = gmdate('Y-m-d H:i:s');
        $sub->cancelRemoteSubscription([
            'reason'         => 'customer request',
            'effective_from' => 'immediately',
        ]);
        $after = gmdate('Y-m-d H:i:s');

        $fresh = Subscription::find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_CANCELED, $fresh->status, 'immediate cancellation must persist the canceled status');
        $this->assertNotNull($fresh->next_billing_date, 'immediate cancellation must keep a billing-schedule end marker, not clear it');
        $this->assertGreaterThanOrEqual($before, $fresh->next_billing_date, 'the schedule end must be stamped at the cancellation moment, not left at the old future date');
        $this->assertLessThanOrEqual($after, $fresh->next_billing_date, 'the schedule end must be stamped at the cancellation moment');
    }

    public function test_sync_subscription_states_fires_eot_event_when_completed(): void
    {
        $eventFired = false;
        add_action('fluent_cart/subscription_eot', function () use (&$eventFired) {
            $eventFired = true;
        });

        ['sub' => $sub, 'order' => $order] = $this->makeSubscriptionSetup([
            'bill_times' => 2,
            'bill_count' => 0,
            'config'     => ['_init' => true],
        ]);

        for ($i = 0; $i < 2; $i++) {
            $this->txnFactory->create($order->id, [
                'subscription_id'  => $sub->id,
                'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
                'status'           => Status::TRANSACTION_SUCCEEDED,
                'total'            => Helper::toCent(29.99),
            ]);
        }

        SubscriptionService::syncSubscriptionStates($sub);
        $this->assertTrue($eventFired, 'SubscriptionEOT event should fire when bill_count reaches bill_times');
    }

    public function test_sync_subscription_states_fires_status_changed_action(): void
    {
        $actionFired = false;
        add_action('fluent_cart/payments/subscription_status_changed', function () use (&$actionFired) {
            $actionFired = true;
        });

        ['sub' => $sub] = $this->makeSubscriptionSetup(['status' => Status::SUBSCRIPTION_ACTIVE]);

        SubscriptionService::syncSubscriptionStates($sub, [
            'status' => Status::SUBSCRIPTION_PAUSED,
        ]);

        $this->assertTrue($actionFired, 'fluent_cart/payments/subscription_status_changed should fire on status change');
    }

    public function test_sync_subscription_states_sets_canceled_at_when_status_is_canceled(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup(['status' => Status::SUBSCRIPTION_ACTIVE]);

        $updated = SubscriptionService::syncSubscriptionStates($sub, [
            'status' => Status::SUBSCRIPTION_CANCELED,
        ]);

        $fresh = Subscription::query()->find($sub->id);
        $this->assertNotNull($fresh->canceled_at, 'canceled_at should be set when status=canceled');
    }

    public function test_sync_subscription_states_dispatches_validity_expired_on_expired_status(): void
    {
        $eventFired = false;
        add_action('fluent_cart/subscription_expired_validity', function () use (&$eventFired) {
            $eventFired = true;
        });

        ['sub' => $sub] = $this->makeSubscriptionSetup(['status' => Status::SUBSCRIPTION_ACTIVE]);

        SubscriptionService::syncSubscriptionStates($sub, [
            'status' => Status::SUBSCRIPTION_EXPIRED,
        ]);

        $this->assertTrue($eventFired, 'SubscriptionValidityExpired should fire when transitioning to expired');
    }

    // =========================================================================
    // Spec 141-147: SubscriptionHelper::getNextBillingDate
    // =========================================================================

    public function test_subscription_helper_returns_null_for_completed_subscription(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'status'     => Status::SUBSCRIPTION_COMPLETED,
            'bill_times' => 3,
            'bill_count' => 3,
        ]);
        $this->assertNull(SubscriptionHelper::getNextBillingDate($sub));
    }

    public function test_subscription_helper_returns_null_when_bill_count_meets_bill_times(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'status'     => Status::SUBSCRIPTION_ACTIVE,
            'bill_times' => 5,
            'bill_count' => 5,
        ]);
        $this->assertNull(SubscriptionHelper::getNextBillingDate($sub));
    }

    public function test_subscription_helper_returns_trial_ends_at_for_trial_period(): void
    {
        $trialEnd = gmdate('Y-m-d H:i:s', time() + 14 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'bill_count'    => 0,
            'trial_days'    => 14,
            'trial_ends_at' => $trialEnd,
        ]);
        $this->assertSame($trialEnd, SubscriptionHelper::getNextBillingDate($sub));
    }

    public function test_subscription_helper_advances_by_monthly_interval(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'billing_interval'  => 'monthly',
            'bill_count'        => 1,
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS), // past
            'status'            => Status::SUBSCRIPTION_ACTIVE,
        ]);
        $next = SubscriptionHelper::getNextBillingDate($sub);
        $this->assertNotNull($next);
        $nextTs = strtotime($next);
        $this->assertGreaterThan(time(), $nextTs, 'Next billing date should be in the future after advancing');
    }

    // =========================================================================
    // Spec 151-152: getSubscriptionsGracePeriodDays
    // =========================================================================

    public function test_grace_period_days_returns_correct_intervals(): void
    {
        $grace = SubscriptionHelper::getSubscriptionsGracePeriodDays();
        $this->assertSame(1, $grace['daily']);
        $this->assertSame(3, $grace['weekly']);
        $this->assertSame(7, $grace['monthly']);
        $this->assertSame(15, $grace['quarterly']);
        $this->assertSame(15, $grace['half_yearly']);
        $this->assertSame(15, $grace['yearly']);
    }

    public function test_grace_period_days_is_filterable(): void
    {
        add_filter('fluent_cart/subscription/grace_period_days', function ($days) {
            $days['monthly'] = 30;
            return $days;
        });

        $grace = SubscriptionHelper::getSubscriptionsGracePeriodDays();
        $this->assertSame(30, $grace['monthly']);

        // Cleanup
        remove_all_filters('fluent_cart/subscription/grace_period_days');
    }

    // =========================================================================
    // Spec 153-158: PlanUpgradeService
    // =========================================================================

    public function test_plan_upgrade_service_save_setting_creates_meta(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        $meta = PlanUpgradeService::saveUpgradeSetting([
            'from_variant'    => $variant->id,
            'to_variants'     => [99, 100],
            'is_prorate'      => false,
            'discount_amount' => 0,
        ]);

        $this->assertNotNull($meta);
        $this->assertSame($variant->id, $meta->object_id);
        $this->assertSame('variant_upgrade_path', $meta->meta_key);
    }

    public function test_plan_upgrade_service_get_settings_returns_meta_for_variant(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        $this->metaFactory->createVariantUpgradePath($variant->id, [100, 101]);

        $result = PlanUpgradeService::getUpgradeSettings($product->ID, $variant->id);
        $this->assertNotEmpty($result);
        $this->assertSame([100, 101], $result->first()->meta_value['to_variants'], 'the stored upgrade targets come back for the variant');
    }

    public function test_plan_upgrade_service_can_upgrade_returns_true_for_active_with_path(): void
    {
        ['sub' => $sub, 'variant' => $variant] = $this->makeSubscriptionSetup([
            'status' => Status::SUBSCRIPTION_ACTIVE,
        ]);
        $this->metaFactory->createVariantUpgradePath($variant->id, [999]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertTrue($fresh->canUpgrade());
    }

    public function test_plan_upgrade_validate_paths_accepts_unique_targets(): void
    {
        $paths = [
            1 => [['target_variation_id' => 10], ['target_variation_id' => 11]],
        ];
        // Duplicate targets end in wp_send_json(), which the WP test suite turns into
        // an exception, so unique targets completing silently is the contract.
        $this->expectNotToPerformAssertions();
        PlanUpgradeService::validateUpgradePaths($paths);
    }

    // =========================================================================
    // Spec 166-168: calculateUpgradeToDiscount() prorate math
    // =========================================================================

    /**
     * Active subscription with $daysRemaining days left on a $interval cycle whose
     * parent order line was paid in full (9000 cents, no tax, no refund) — chosen so
     * every expected credit below is an exact integer.
     */
    private function makeProrateSetup(string $interval, int $daysRemaining): array
    {
        ['order' => $order, 'item' => $item] = $this->makeSubscriptionSetup([
            'billing_interval'  => $interval,
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() + $daysRemaining * DAY_IN_SECONDS),
        ], [
            'line_total'   => 9000,
            'tax_amount'   => 0,
            'refund_total' => 0,
        ]);

        return [Order::query()->find($order->id), OrderItem::query()->find($item->id)];
    }

    public function test_prorate_credit_monthly_uses_days_in_current_month_as_divider(): void
    {
        [$order, $item] = $this->makeProrateSetup('monthly', 10);

        $divider = PaymentHelper::getIntervalDays('monthly');
        $this->assertSame((int) gmdate('t'), $divider);
        $this->assertSame((int) round(9000 * 10 / $divider), PlanUpgradeService::calculateUpgradeToDiscount($order, $item));
    }

    // Regression: quarterly/half_yearly previously hit a private map with no entry and
    // returned 0 credit — customers upgrading from those plans got no prorate at all.
    public function test_prorate_credit_quarterly_is_scaled_over_90_days(): void
    {
        [$order, $item] = $this->makeProrateSetup('quarterly', 45);

        $this->assertSame(4500, PlanUpgradeService::calculateUpgradeToDiscount($order, $item));
    }

    public function test_prorate_credit_half_yearly_is_scaled_over_182_days(): void
    {
        [$order, $item] = $this->makeProrateSetup('half_yearly', 91);

        $this->assertSame(4500, PlanUpgradeService::calculateUpgradeToDiscount($order, $item));
    }

    public function test_prorate_credit_is_zero_for_an_interval_nothing_resolves(): void
    {
        [$order, $item] = $this->makeProrateSetup('fortnightly', 10);

        $this->assertSame(0, PaymentHelper::getIntervalDays('fortnightly'));
        $this->assertSame(0, PlanUpgradeService::calculateUpgradeToDiscount($order, $item));
    }

    public function test_prorate_credit_never_exceeds_one_full_period(): void
    {
        // 400 days left on a 90-day cycle: clamp daysRemaining to the divider → full amount, not 4×.
        [$order, $item] = $this->makeProrateSetup('quarterly', 400);

        $this->assertSame(9000, PlanUpgradeService::calculateUpgradeToDiscount($order, $item));
    }

    public function test_prorate_credit_of_a_partly_refunded_tax_exclusive_line_is_what_is_left_of_the_charge(): void
    {
        // 2 x ($100 + $10 tax), one unit refunded: $110 of the $220 charge is left.
        ['order' => $order, 'item' => $item] = $this->makeSubscriptionSetup([
            'billing_interval'  => 'quarterly',
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() + 400 * DAY_IN_SECONDS),
        ], [
            'line_total'   => 20000,
            'tax_amount'   => 2000,
            'refund_total' => 11000,
            'line_meta'    => ['tax_config' => ['inclusive' => false]],
        ]);

        $this->assertSame(11000, PlanUpgradeService::calculateUpgradeToDiscount(
            Order::query()->find($order->id),
            OrderItem::query()->find($item->id)
        ));
    }

    // =========================================================================
    // Spec 169-174: EarlyPaymentFeature
    // =========================================================================

    public function test_early_payment_can_pay_returns_false_when_bill_times_zero(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'bill_times' => 0,
            'bill_count' => 0,
            'status'     => Status::SUBSCRIPTION_ACTIVE,
        ]);
        $this->assertFalse(EarlyPaymentFeature::canPay($sub));
    }

    public function test_early_payment_can_pay_returns_false_when_all_paid(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'bill_times' => 3,
            'bill_count' => 3,
            'status'     => Status::SUBSCRIPTION_ACTIVE,
        ]);
        $this->assertFalse(EarlyPaymentFeature::canPay($sub));
    }

    public function test_early_payment_can_pay_returns_false_for_non_active_status(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'bill_times' => 12,
            'bill_count' => 3,
            'status'     => Status::SUBSCRIPTION_CANCELED,
        ]);
        $this->assertFalse(EarlyPaymentFeature::canPay($sub));
    }

    // =========================================================================
    // Spec 125: sync includes early_payment_history in bill_count recount
    // =========================================================================

    public function test_sync_counts_early_payment_history_in_bill_count(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscriptionSetup([
            'bill_times' => 5,
            'bill_count' => 0,
        ]);
        // 1 paid txn + early_payment_history count=3 → 1 + 2 = 3
        $this->txnFactory->create($order->id, [
            'subscription_id'  => $sub->id,
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 1000,
        ]);
        $sub->updateMeta('early_payment_history', [['count' => 3]]);

        $fresh = Subscription::query()->find($sub->id);
        SubscriptionService::syncSubscriptionStates($fresh);

        $reloaded = Subscription::query()->find($sub->id);
        $this->assertEquals(3, $reloaded->bill_count);
    }

    // =========================================================================
    // Spec 127: sync sets NBD via guess when NOT EOT and NBD absent
    // =========================================================================

    public function test_sync_sets_next_billing_date_when_absent_and_not_eot(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'billing_interval'  => 'monthly',
            'bill_times'        => 12,
            'bill_count'        => 0,
            'next_billing_date' => null,
        ]);
        // MySQL non-strict mode stores NULL datetime as '0000-00-00 00:00:00' which is a truthy string.
        // The sync guard `if (!$model->next_billing_date)` would not fire the guess fallback in that case,
        // so we normalize in-memory to true null to exercise the fallback path.
        $fresh = Subscription::query()->find($sub->id);
        $fresh->next_billing_date = null;
        SubscriptionService::syncSubscriptionStates($fresh);

        $reloaded = Subscription::query()->find($sub->id);
        $nbd = $reloaded->next_billing_date;
        $this->assertNotEmpty($nbd);
        $this->assertNotSame('0000-00-00 00:00:00', $nbd);
    }

    // =========================================================================
    // Spec 129: sync fires data_updated when status unchanged but dirty
    // =========================================================================

    public function test_sync_fires_data_updated_when_dirty_without_status_change(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'status'      => Status::SUBSCRIPTION_ACTIVE,
            'bill_times'  => 12,
            'bill_count'  => 5,
        ]);
        $fired = false;
        add_action('fluent_cart/subscription/data_updated', function () use (&$fired) {
            $fired = true;
        });

        $fresh = Subscription::query()->find($sub->id);
        // No transactions → sync will reset bill_count to 0 (dirty), status stays active.
        SubscriptionService::syncSubscriptionStates($fresh);

        $this->assertTrue($fired, 'data_updated action should fire when status unchanged but data dirty');
    }

    // =========================================================================
    // Spec 132: sync fires dynamic action fluent_cart/payments/subscription_{new_status}
    // =========================================================================

    public function test_sync_fires_dynamic_status_action(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'status'     => Status::SUBSCRIPTION_ACTIVE,
            'bill_times' => 2,
            'bill_count' => 0,
            'config'     => ['_init' => true],
        ]);
        // 2 paid txns → EOT → status=completed → fires fluent_cart/payments/subscription_completed
        foreach (range(1, 2) as $_) {
            $this->txnFactory->create($sub->parent_order_id, [
                'subscription_id'  => $sub->id,
                'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
                'status'           => Status::TRANSACTION_SUCCEEDED,
                'total'            => 1000,
            ]);
        }
        $fired = false;
        add_action('fluent_cart/payments/subscription_completed', function () use (&$fired) {
            $fired = true;
        });

        $fresh = Subscription::query()->find($sub->id);
        SubscriptionService::syncSubscriptionStates($fresh);

        $this->assertTrue($fired, 'dynamic subscription_{status} action should fire on status change');
    }

    // =========================================================================
    // Spec 134: sync writes $args[meta] via updateMeta
    // =========================================================================

    public function test_sync_writes_meta_entries_from_args(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup();
        $fresh = Subscription::query()->find($sub->id);
        SubscriptionService::syncSubscriptionStates($fresh, [
            'meta' => [
                'gateway_ref' => 'sub_ref_123',
            ],
        ]);
        $reloaded = Subscription::query()->find($sub->id);
        $this->assertSame('sub_ref_123', $reloaded->getMeta('gateway_ref'));
    }

    // =========================================================================
    // Spec 142: SubscriptionHelper::getNextBillingDate returns canceled_at for canceled/expired
    // =========================================================================

    public function test_helper_returns_canceled_at_for_canceled_status(): void
    {
        $canceledAt = gmdate('Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'status'      => Status::SUBSCRIPTION_CANCELED,
            'canceled_at' => $canceledAt,
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame($canceledAt, SubscriptionHelper::getNextBillingDate($fresh));
    }

    public function test_helper_returns_canceled_at_for_expired_status(): void
    {
        $canceledAt = gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'status'      => Status::SUBSCRIPTION_EXPIRED,
            'canceled_at' => $canceledAt,
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame($canceledAt, SubscriptionHelper::getNextBillingDate($fresh));
    }

    // =========================================================================
    // Spec 144: returns created_at + trial_days when bill_count=0, trial_days>0, no trial_ends_at
    // =========================================================================

    public function test_helper_returns_created_plus_trial_when_no_trial_ends_at(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'bill_count'    => 0,
            'trial_days'    => 7,
            'trial_ends_at' => null,
        ]);
        $fresh = Subscription::query()->find($sub->id);
        // DB coerces null datetime to '0000-00-00 00:00:00' (truthy) which would short-circuit the helper
        // through the `!empty($model->trial_ends_at)` guard. Normalize in-memory to true null.
        $fresh->trial_ends_at = null;
        $nbd = SubscriptionHelper::getNextBillingDate($fresh);
        $expected = gmdate('Y-m-d H:i:s', strtotime($fresh->created_at . ' +7 days'));
        $this->assertSame($expected, $nbd);
    }

    // =========================================================================
    // Spec 145: returns next_billing_date when it's in the future
    // =========================================================================

    public function test_helper_returns_stored_nbd_when_future(): void
    {
        $future = gmdate('Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'bill_count'        => 2,
            'trial_days'        => 0,
            'next_billing_date' => $future,
        ]);
        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame($future, SubscriptionHelper::getNextBillingDate($fresh));
    }

    // =========================================================================
    // Spec 147: unknown interval follows the day-count contract
    // (PaymentHelper::getIntervalDays() + fluent_cart/subscription_interval_in_days)
    // =========================================================================

    public function test_helper_unknown_interval_follows_day_count_contract(): void
    {
        $past = gmdate('Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS);
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'bill_count'        => 2,
            'next_billing_date' => $past,
            'billing_interval'  => 'unknown_interval_xyz',
        ]);
        $fresh = Subscription::query()->find($sub->id);

        // Default day count for an unknown slug is 0 (no advance) — same contract the renewal engine applies.
        $expected = gmdate('Y-m-d H:i:s', strtotime($past) + PaymentHelper::getIntervalDays('unknown_interval_xyz') * DAY_IN_SECONDS);
        $this->assertSame($expected, SubscriptionHelper::getNextBillingDate($fresh));

        // A site that defines the custom cadence through the filter drives this path too.
        $filter = function ($days, $args) {
            return $args['interval'] === 'unknown_interval_xyz' ? 45 : $days;
        };
        add_filter('fluent_cart/subscription_interval_in_days', $filter, 10, 2);
        try {
            $this->assertSame(
                gmdate('Y-m-d H:i:s', strtotime($past) + 45 * DAY_IN_SECONDS),
                SubscriptionHelper::getNextBillingDate($fresh)
            );
        } finally {
            remove_filter('fluent_cart/subscription_interval_in_days', $filter, 10);
        }
    }

    // =========================================================================
    // Spec 148-150: getSubscriptionCancelAtTimeStamp
    // =========================================================================

    public function test_cancel_at_timestamp_returns_null_when_both_zero(): void
    {
        $this->assertNull(SubscriptionHelper::getSubscriptionCancelAtTimeStamp(0, 0, 'monthly'));
    }

    public function test_cancel_at_timestamp_computes_with_bill_times_and_trial(): void
    {
        $ts = SubscriptionHelper::getSubscriptionCancelAtTimeStamp(5, 3, 'monthly');
        $this->assertIsInt($ts);
        // Should be roughly now + (3 months) + (5 days in seconds)
        $expectedMin = strtotime('+3 months') + (5 * 86400) - 60;
        $expectedMax = strtotime('+3 months') + (5 * 86400) + 60;
        $this->assertGreaterThanOrEqual($expectedMin, $ts);
        $this->assertLessThanOrEqual($expectedMax, $ts);
    }

    public function test_cancel_at_timestamp_maps_daily_to_day(): void
    {
        $ts = SubscriptionHelper::getSubscriptionCancelAtTimeStamp(0, 3, 'daily');
        $this->assertIsInt($ts);
        // 3 days from now
        $expected = strtotime('+3 days');
        $this->assertGreaterThanOrEqual($expected - 60, $ts);
        $this->assertLessThanOrEqual($expected + 60, $ts);
    }

    // =========================================================================
    // Spec 156: PlanUpgradeService::updateUpgradeSetting updates meta_value JSON
    // =========================================================================

    public function test_plan_upgrade_update_setting_rewrites_meta_value(): void
    {
        $variantA = 11;
        $variantB = 22;
        $meta = PlanUpgradeService::saveUpgradeSetting([
            'from_variant'    => $variantA,
            'to_variants'     => [$variantB],
            'is_prorate'      => false,
            'discount_amount' => 0,
        ]);
        PlanUpgradeService::updateUpgradeSetting($meta->id, [
            'to_variants'     => [$variantB, 33],
            'is_prorate'      => true,
            'discount_amount' => 500,
        ]);

        $reloaded = \FluentCart\App\Models\Meta::query()->find($meta->id);
        $value = is_string($reloaded->meta_value) ? json_decode($reloaded->meta_value, true) : $reloaded->meta_value;
        $this->assertTrue($value['is_prorate']);
        $this->assertSame(500, $value['discount_amount']);
        $this->assertContains(33, $value['to_variants']);
    }

    // =========================================================================
    // Spec 169: EarlyPaymentFeature::isEnabled returns false when Pro not active
    // =========================================================================

    public function test_early_payment_is_enabled_false_when_pro_not_active(): void
    {
        $this->assertFalse(EarlyPaymentFeature::isEnabled());
    }

    // =========================================================================
    // Spec 171: isEnabled filter is applied
    // =========================================================================

    public function test_early_payment_is_enabled_filter_is_applied(): void
    {
        $called = false;
        $filter = function ($value) use (&$called) {
            $called = true;
            return $value;
        };
        add_filter('fluent_cart/subscription/early_payment_enabled', $filter);
        EarlyPaymentFeature::isEnabled();
        remove_filter('fluent_cart/subscription/early_payment_enabled', $filter);
        $this->assertTrue($called);
    }

    // =========================================================================
    // Spec 174: canPay passes through fluent_cart/subscription/can_early_pay filter
    // =========================================================================

    public function test_early_payment_can_pay_filter_is_applied(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'bill_times' => 5,
            'bill_count' => 0,
            'status'     => Status::SUBSCRIPTION_ACTIVE,
        ]);
        $called = false;
        $filter = function ($value, $ctx) use (&$called) {
            $called = true;
            $this->assertArrayHasKey('subscription', $ctx);
            return $value;
        };
        add_filter('fluent_cart/subscription/can_early_pay', $filter, 10, 2);
        EarlyPaymentFeature::canPay($sub);
        remove_filter('fluent_cart/subscription/can_early_pay', $filter, 10);
        $this->assertTrue($called);
    }

    // =========================================================================
    // Spec 110: recordRenewalPayment returns error when parent order missing
    // =========================================================================

    public function test_record_renewal_returns_error_when_parent_order_missing(): void
    {
        // Point parent_order_id at a nonexistent order so subscription->order resolves to null.
        $customer = $this->customerFactory->create();
        $product  = $this->productFactory->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant  = $product->variants->first();
        $sub = $this->subscriptionFactory->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => 9999999, // no such order
            'product_id'      => $product->ID,
            'variation_id'    => $variant->id,
        ]);

        $result = SubscriptionService::recordRenewalPayment([
            'subscription_id' => $sub->id,
            'status'          => Status::TRANSACTION_SUCCEEDED,
            'total'           => $sub->recurring_total,
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('parent_order_not_found', $result->get_error_code());
    }

    // =========================================================================
    // Spec 113: child order shipping_status — 'unshipped' for physical, '' for digital
    // =========================================================================

    public function test_record_renewal_sets_shipping_status_for_physical_fulfillment(): void
    {
        $customer = $this->customerFactory->create();
        $product  = $this->productFactory->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant  = $product->variants->first();
        $order    = $this->orderFactory->withAddresses()->create([
            'customer_id' => $customer->id,
            'type'        => 'subscription',
        ]);
        // Parent item is physical → child inherits physical → shipping_status='unshipped'
        $this->itemFactory->create($order->id, [
            'post_id'          => $product->ID,
            'object_id'        => $variant->id,
            'payment_type'     => Status::ORDER_TYPE_SUBSCRIPTION,
            'fulfillment_type' => 'physical',
        ]);
        $sub = $this->subscriptionFactory->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $variant->id,
        ]);

        SubscriptionService::recordRenewalPayment([
            'subscription_id' => $sub->id,
            'status'          => Status::TRANSACTION_SUCCEEDED,
            'total'           => $sub->recurring_total,
        ], $sub);

        $child = Order::query()
            ->where('parent_id', $order->id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->first();
        $this->assertSame(Status::SHIPPING_UNSHIPPED, $child->shipping_status);
        $this->assertSame('physical', $child->fulfillment_type);
    }

    // =========================================================================
    // Spec 115: copies parent tax_id meta to child
    // =========================================================================

    public function test_record_renewal_copies_parent_tax_id_meta(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscriptionSetup();
        $parent = Order::query()->find($order->id);
        $parent->updateMeta('tax_id', 'VAT-ABC-123');

        SubscriptionService::recordRenewalPayment([
            'subscription_id' => $sub->id,
            'status'          => Status::TRANSACTION_SUCCEEDED,
            'total'           => $sub->recurring_total,
        ], $sub);

        $child = Order::query()
            ->where('parent_id', $order->id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->first();
        $this->assertSame('VAT-ABC-123', $child->getMeta('tax_id'));
    }

    // =========================================================================
    // Spec 119: child OrderItem inherits bundle_item_ids from parent order_item.line_meta
    // =========================================================================

    public function test_record_renewal_inherits_bundle_item_ids_from_parent(): void
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
            'line_meta'    => ['bundle_item_ids' => [101, 102, 103]],
        ]);
        $sub = $this->subscriptionFactory->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $variant->id,
        ]);

        SubscriptionService::recordRenewalPayment([
            'subscription_id' => $sub->id,
            'status'          => Status::TRANSACTION_SUCCEEDED,
            'total'           => $sub->recurring_total,
        ], $sub);

        $child = Order::query()
            ->where('parent_id', $order->id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->first();
        $childItem = OrderItem::query()
            ->where('order_id', $child->id)
            ->where('payment_type', Status::ORDER_TYPE_SUBSCRIPTION)
            ->first();
        $this->assertSame([101, 102, 103], \FluentCart\Framework\Support\Arr::get($childItem->line_meta, 'bundle_item_ids'));
    }

    // =========================================================================
    // Spec 120: tax_amount falls back to recurring_tax_total when args tax_total=0
    // =========================================================================

    public function test_record_renewal_tax_falls_back_to_recurring_tax_total(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscriptionSetup([
            'recurring_tax_total' => 250,
        ]);

        SubscriptionService::recordRenewalPayment([
            'subscription_id' => $sub->id,
            'status'          => Status::TRANSACTION_SUCCEEDED,
            'total'           => $sub->recurring_total,
            // tax_total intentionally omitted → should pick up recurring_tax_total
        ], $sub);

        $child = Order::query()
            ->where('parent_id', $order->id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->first();
        $this->assertEquals(250, $child->tax_total);
    }

    // =========================================================================
    // Spec 120 (second branch): when args.tax_total=0 AND recurring_tax_total=0 AND
    // parent_order.tax_behavior=2, tax pulled from parent order_item.other_info.recurring_tax.
    // =========================================================================

    public function test_record_renewal_tax_pulls_from_parent_other_info_when_tax_behavior_is_two(): void
    {
        $customer = $this->customerFactory->create();
        $product  = $this->productFactory->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant  = $product->variants->first();
        // Parent order has tax_behavior=2 (inclusive tax) AND recurring_tax_total=0 on the subscription.
        $order = $this->orderFactory->withAddresses()->create([
            'customer_id'  => $customer->id,
            'type'         => 'subscription',
            'tax_behavior' => 2,
        ]);
        $this->itemFactory->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'payment_type' => Status::ORDER_TYPE_SUBSCRIPTION,
            'other_info'   => ['recurring_tax' => 175],
        ]);
        $sub = $this->subscriptionFactory->create([
            'customer_id'         => $customer->id,
            'parent_order_id'     => $order->id,
            'product_id'          => $product->ID,
            'variation_id'        => $variant->id,
            'recurring_tax_total' => 0,
        ]);

        SubscriptionService::recordRenewalPayment([
            'subscription_id' => $sub->id,
            'status'          => Status::TRANSACTION_SUCCEEDED,
            'total'           => $sub->recurring_total,
            // tax_total omitted AND recurring_tax_total=0 → falls to parent_item.other_info.recurring_tax
        ], $sub);

        $child = Order::query()
            ->where('parent_id', $order->id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->first();
        $this->assertEquals(175, $child->tax_total, 'child order tax_total should reflect parent other_info recurring_tax');

        $childItem = OrderItem::query()
            ->where('order_id', $child->id)
            ->where('payment_type', Status::ORDER_TYPE_SUBSCRIPTION)
            ->first();
        $this->assertEquals(175, $childItem->tax_amount, 'child item tax_amount should reflect parent other_info recurring_tax');
    }

    // =========================================================================
    // Spec 154: PlanUpgradeService::getUpgradeSettings without variantId uses upgradeablePath scope
    // =========================================================================

    public function test_plan_upgrade_get_settings_without_variant_uses_product_scope(): void
    {
        // Product A (in scope) + Product B (control). Both carry an upgrade-path row.
        // getUpgradeSettings($productA->ID) must return ONLY the row whose variant belongs to A.
        $productA = $this->productFactory->create();
        $variantA = $productA->variants->first();
        $productB = $this->productFactory->create();
        $variantB = $productB->variants->first();

        PlanUpgradeService::saveUpgradeSetting([
            'from_variant'    => $variantA->id,
            'to_variants'     => [9001],
            'is_prorate'      => false,
            'discount_amount' => 0,
        ]);
        PlanUpgradeService::saveUpgradeSetting([
            'from_variant'    => $variantB->id,
            'to_variants'     => [9002],
            'is_prorate'      => false,
            'discount_amount' => 0,
        ]);

        $result = PlanUpgradeService::getUpgradeSettings($productA->ID);
        $this->assertCount(1, $result, 'scope should return exactly one row for product A');
        $this->assertSame($variantA->id, (int) $result->first()->object_id,
            'returned meta row must belong to a variant of the requested product');
        $this->assertNotSame($variantB->id, (int) $result->first()->object_id,
            'scope must not leak product B variants');
    }

    // =========================================================================
    // Spec 229: sync does NOT fire data_updated when no status change AND no dirty data
    // =========================================================================

    public function test_sync_does_not_fire_data_updated_when_stable(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup([
            'status'     => Status::SUBSCRIPTION_ACTIVE,
            'bill_times' => 0,
            'bill_count' => 0,
        ]);
        // First sync normalizes state; second should find no dirty data.
        SubscriptionService::syncSubscriptionStates(Subscription::query()->find($sub->id));

        $fired = false;
        add_action('fluent_cart/subscription/data_updated', function () use (&$fired) {
            $fired = true;
        });
        SubscriptionService::syncSubscriptionStates(Subscription::query()->find($sub->id));
        $this->assertFalse($fired, 'data_updated must not fire on a stable second sync');
    }

    // =========================================================================
    // Spec 135-140: recordManualRenewal
    // =========================================================================

    private function prepareRenewalTransaction(array $subOverrides = [], string $fulfillment = 'digital'): array
    {
        $customer = $this->customerFactory->create();
        $product  = $this->productFactory->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant  = $product->variants->first();
        $parent   = $this->orderFactory->withAddresses()->create([
            'customer_id' => $customer->id,
            'type'        => 'subscription',
        ]);
        $renewal  = $this->orderFactory->withAddresses()->create([
            'customer_id'      => $customer->id,
            'parent_id'        => $parent->id,
            'type'             => 'renewal',
            'fulfillment_type' => $fulfillment,
            'status'           => Status::ORDER_ON_HOLD,
            'payment_status'   => Status::PAYMENT_PENDING,
            // recordManualRenewal no longer force-sets payment_status/total_paid —
            // syncOrderStatuses derives them from succeeded transactions, so the
            // invoice total must match the transaction for the paid transition.
            'total_amount'     => 2999,
        ]);
        $txn = $this->txnFactory->create($renewal->id, [
            'subscription_id'  => null, // recordManualRenewal fills via subscription context
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 2999,
            'payment_method'   => 'manual_gateway',
        ]);
        $sub = $this->subscriptionFactory->create(array_merge([
            'customer_id'     => $customer->id,
            'parent_order_id' => $parent->id,
            'product_id'      => $product->ID,
            'variation_id'    => $variant->id,
        ], $subOverrides));

        return compact('customer', 'product', 'variant', 'parent', 'renewal', 'txn', 'sub');
    }

    public function test_manual_renewal_updates_order_to_completed_paid(): void
    {
        ['sub' => $sub, 'renewal' => $renewal, 'txn' => $txn] = $this->prepareRenewalTransaction();

        // Strip email/side-effect listeners so this test asserts core state transitions only.
        remove_all_actions('fluent_cart/subscription_renewed');

        SubscriptionService::recordManualRenewal(
            Subscription::query()->find($sub->id),
            OrderTransaction::query()->find($txn->id)
        );
        $reloaded = Order::query()->find($renewal->id);
        $this->assertSame(Status::ORDER_COMPLETED, $reloaded->status);
        $this->assertSame(Status::ORDER_TYPE_RENEWAL, $reloaded->type);
        $this->assertSame(Status::PAYMENT_PAID, $reloaded->payment_status);
        $this->assertEquals(2999, $reloaded->total_paid);
        $this->assertNotEmpty($reloaded->completed_at);
    }

    public function test_manual_renewal_sets_physical_order_to_processing(): void
    {
        ['sub' => $sub, 'renewal' => $renewal, 'txn' => $txn] = $this->prepareRenewalTransaction([], 'physical');

        remove_all_actions('fluent_cart/subscription_renewed');
        SubscriptionService::recordManualRenewal(
            Subscription::query()->find($sub->id),
            OrderTransaction::query()->find($txn->id)
        );
        $reloaded = Order::query()->find($renewal->id);
        $this->assertSame(Status::ORDER_PROCESSING, $reloaded->status);
    }

    public function test_manual_renewal_stores_billing_info_meta(): void
    {
        ['sub' => $sub, 'txn' => $txn] = $this->prepareRenewalTransaction();

        remove_all_actions('fluent_cart/subscription_renewed');
        SubscriptionService::recordManualRenewal(
            Subscription::query()->find($sub->id),
            OrderTransaction::query()->find($txn->id),
            ['billing_info' => ['details' => ['method' => 'manual_bank']]]
        );
        $reloaded = Subscription::query()->find($sub->id);
        $billing = $reloaded->getMeta('active_payment_method');
        $decoded = is_string($billing) ? json_decode($billing, true) : $billing;
        $this->assertSame('manual_bank', \FluentCart\Framework\Support\Arr::get($decoded, 'details.method'));
    }

    public function test_manual_renewal_calls_sync_with_active_and_txn_payment_method(): void
    {
        ['sub' => $sub, 'txn' => $txn] = $this->prepareRenewalTransaction();

        remove_all_actions('fluent_cart/subscription_renewed');
        SubscriptionService::recordManualRenewal(
            Subscription::query()->find($sub->id),
            OrderTransaction::query()->find($txn->id)
        );
        $reloaded = Subscription::query()->find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, $reloaded->status);
        $this->assertSame('manual_gateway', $reloaded->current_payment_method);
    }

    public function test_manual_renewal_dispatches_renewed_when_total_positive(): void
    {
        ['sub' => $sub, 'txn' => $txn] = $this->prepareRenewalTransaction();

        // Remove all listeners first, then wire our spy — prevents email listener crash.
        remove_all_actions('fluent_cart/subscription_renewed');
        $fired = false;
        add_action('fluent_cart/subscription_renewed', function () use (&$fired) {
            $fired = true;
        });
        SubscriptionService::recordManualRenewal(
            Subscription::query()->find($sub->id),
            OrderTransaction::query()->find($txn->id)
        );
        $this->assertTrue($fired);
    }

    public function test_manual_renewal_does_not_dispatch_renewed_when_total_zero(): void
    {
        $ctx = $this->prepareRenewalTransaction();
        $txn = OrderTransaction::query()->find($ctx['txn']->id);
        $txn->total = 0;
        $txn->save();

        remove_all_actions('fluent_cart/subscription_renewed');
        $fired = false;
        add_action('fluent_cart/subscription_renewed', function () use (&$fired) {
            $fired = true;
        });
        SubscriptionService::recordManualRenewal(
            Subscription::query()->find($ctx['sub']->id),
            OrderTransaction::query()->find($txn->id)
        );
        $this->assertFalse($fired);
    }

    // =========================================================================
    // Renewal settlement date (Specs 236-238)
    // =========================================================================

    /**
     * Spec: Cases/Integration/subscriptions.md [236]
     *   "recordRenewalPayment carries a gateway-supplied meta.settled_at onto the
     *    created renewal transaction, and the model fallback does not overwrite it"
     */
    public function test_record_renewal_payment_carries_gateway_settled_at(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup();

        $result = SubscriptionService::recordRenewalPayment([
            'subscription_id' => $sub->id,
            'status'          => Status::TRANSACTION_SUCCEEDED,
            'total'           => $sub->recurring_total,
            'meta'            => ['settled_at' => '2026-08-01 10:26:00'],
        ], $sub);

        $this->assertInstanceOf(OrderTransaction::class, $result);
        $fresh = OrderTransaction::query()->find($result->id);
        $this->assertSame('2026-08-01 10:26:00', $fresh->meta['settled_at'] ?? null, 'the remote charge time supplied by the gateway must win over the processing-time fallback');
    }

    /**
     * Spec: Cases/Integration/subscriptions.md [237]
     *   "recordRenewalPayment without a gateway-supplied settlement time still
     *    yields a settled_at on the succeeded renewal transaction (the fallback stamp)"
     */
    public function test_record_renewal_payment_without_gateway_time_gets_fallback_settled_at(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup();

        $before = gmdate('Y-m-d H:i:s');
        $result = SubscriptionService::recordRenewalPayment([
            'subscription_id' => $sub->id,
            'status'          => Status::TRANSACTION_SUCCEEDED,
            'total'           => $sub->recurring_total,
        ], $sub);
        $after = gmdate('Y-m-d H:i:s');

        $this->assertInstanceOf(OrderTransaction::class, $result);
        $fresh = OrderTransaction::query()->find($result->id);
        $settledAt = $fresh->meta['settled_at'] ?? null;
        $this->assertNotEmpty($settledAt, 'a succeeded renewal must always carry a settlement date, even without gateway detail');
        $this->assertGreaterThanOrEqual($before, $settledAt);
        $this->assertLessThanOrEqual($after, $settledAt);
    }

    /**
     * Spec: Cases/Integration/subscriptions.md [238]
     *   "recordRenewalPayment paying an existing pending manual renewal invoice
     *    carries the gateway settlement time onto that pending transaction,
     *    without overwriting one already present"
     */
    public function test_record_renewal_payment_carries_settled_at_onto_pending_invoice_transaction(): void
    {
        ['sub' => $sub, 'order' => $order, 'customer' => $customer] = $this->makeSubscriptionSetup();

        $invoice = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'parent_id'      => $order->id,
            'type'           => Status::ORDER_TYPE_RENEWAL,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);
        $pendingTxn = $this->txnFactory->create($invoice->id, [
            'subscription_id' => $sub->id,
            'status'          => Status::TRANSACTION_PENDING,
        ]);

        $result = SubscriptionService::recordRenewalPayment([
            'subscription_id'  => $sub->id,
            'vendor_charge_id' => 'pi_renewal_settled_at',
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => $sub->recurring_total,
            'meta'             => ['settled_at' => '2026-08-01 10:26:00'],
        ], $sub);

        $this->assertInstanceOf(OrderTransaction::class, $result);
        $this->assertSame($pendingTxn->id, $result->id, 'the pending invoice transaction must be reused, not a new one created');

        $fresh = OrderTransaction::query()->find($pendingTxn->id);
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $fresh->status);
        $this->assertSame('2026-08-01 10:26:00', $fresh->meta['settled_at'] ?? null, 'the gateway settlement time must land on the reused pending transaction');
    }
}
