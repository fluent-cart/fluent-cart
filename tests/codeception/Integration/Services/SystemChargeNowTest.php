<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\StoreManagedRenewal\Services\RenewalService;
use FluentCart\App\Modules\Subscriptions\Services\SystemChargeService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeSystemGateway;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\Traits\BuildsSystemSubscriptionInvoices;
use Tests\Support\Traits\CalculatesBillingDates;
use Tests\Support\Traits\ConfiguresStoreOrderMode;
use Tests\Support\Traits\SpiesOnEmailMailer;

/**
 * SCF-02: admin Charge Now (SystemChargeService::chargeNow) — guards, outcome
 * derivation, ladder semantics, queued-attempt supersede, canChargeNow permission,
 * and the route's permission meta. Uses the scriptable FakeSystemGateway.
 */
class SystemChargeNowTest extends DatabaseTestCase
{
    use BuildsSystemSubscriptionInvoices;
    use CalculatesBillingDates;
    use ConfiguresStoreOrderMode;
    use SpiesOnEmailMailer;

    private CustomerFactory $customerFactory;
    private ProductFactory $productFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private OrderTransactionFactory $txnFactory;
    private SubscriptionFactory $subscriptionFactory;

    public function setUp(): void
    {
        parent::setUp();

        $this->customerFactory = new CustomerFactory();
        $this->productFactory = new ProductFactory();
        $this->orderFactory = new OrderFactory();
        $this->itemFactory = new OrderItemFactory();
        $this->txnFactory = new OrderTransactionFactory();
        $this->subscriptionFactory = new SubscriptionFactory();

        FakeSystemGateway::register();
        add_filter('pre_wp_mail', '__return_true');

        // OrderFactory creates live-mode orders; charging only runs when the
        // store mode matches.
        $this->setStoreOrderMode('live');
    }

    public function tearDown(): void
    {
        FakeSystemGateway::remove();
        remove_filter('pre_wp_mail', '__return_true');
        parent::tearDown();
    }

    // =========================================================================
    // Renewal invoice opening status
    // =========================================================================

    /**
     * The fixture builder used to assert this inline. It is a production claim
     * about RenewalService, not fixture integrity, so it belongs in a test of
     * its own — the trait now only guarantees that an invoice exists.
     */
    public function test_renewal_invoice_opens_scheduled_for_system_and_pending_for_manual(): void
    {
        ['invoice' => $systemInvoice] = $this->buildSystemSubscriptionWithInvoice();
        $this->assertSame('payment_scheduled', $systemInvoice->payment_status,
            'a system-collected renewal opens already scheduled for automatic charging');

        ['invoice' => $manualInvoice] = $this->buildSystemSubscriptionWithInvoice([
            'collection_method' => 'manual',
        ]);
        $this->assertSame('pending', $manualInvoice->payment_status,
            'a manually collected renewal opens pending, waiting for the customer to pay');
    }

    // =========================================================================
    // Success path
    // =========================================================================

    public function test_charge_now_success_pays_invoice_and_renews(): void
    {
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice();

        $hookEvents = [];
        $listener = function ($payload) use (&$hookEvents) {
            $hookEvents[] = $payload;
        };
        add_action('fluent_cart/subscriptions/system_charge_manual_triggered', $listener, 10, 1);

        FakeSystemGateway::$nextResult = 'success';
        $oldNextBilling = $sub->next_billing_date;

        $chargedAtEarliest = time();
        $result = SystemChargeService::chargeNow(Order::query()->find($invoice->id), $sub, 7);
        $chargedAtLatest = time();

        remove_action('fluent_cart/subscriptions/system_charge_manual_triggered', $listener, 10);

        $this->assertIsArray($result);
        $this->assertSame('paid', $result['status']);

        $invoiceAfter = Order::query()->find($invoice->id);
        $this->assertSame('paid', $invoiceAfter->payment_status);
        $this->assertSame(2999, (int) $invoiceAfter->total_paid);

        $subAfter = $this->reloadSubscription($sub);
        $this->assertNotSame($oldNextBilling, $subAfter->next_billing_date, 'renewal must advance next_billing_date');

        // The exact cadence, not merely "some future date". This subscription is
        // monthly and was already overdue, so production takes the late branch and
        // bills one month from the payment. Asserting only that the date is in the
        // future accepts a next-day renewal, which would charge monthly subscribers
        // roughly thirty times too often. The window spans the charge call because
        // production reads its own time(). oneMonthAfterUtc() states the rule
        // independently and clamps month ends, which strtotime('+1 month') does
        // not — it turns 31 January into 3 March and would fail this test on
        // month-end days only.
        $actualNextBilling = strtotime($subAfter->next_billing_date);
        $this->assertGreaterThanOrEqual(
            $this->oneMonthAfterUtc($chargedAtEarliest),
            $actualNextBilling,
            'a monthly renewal bills one month after payment, not sooner'
        );
        $this->assertLessThanOrEqual(
            $this->oneMonthAfterUtc($chargedAtLatest),
            $actualNextBilling,
            'a monthly renewal bills one month after payment, not later'
        );
        $this->assertSame([], $subAfter->getMeta('system_charge_state', []) ?: [], 'charge state must be cleared after payment');

        $this->assertCount(1, $hookEvents);
        $this->assertSame('paid', $hookEvents[0]['result']);
        $this->assertSame(7, $hookEvents[0]['actor_id']);
        $this->assertSame(1, FakeSystemGateway::$chargeCalls, 'exactly one gateway charge attempt');
    }

    // =========================================================================
    // Failure path — mid-ladder failure lets the retry schedule continue
    // =========================================================================

    public function test_charge_now_failure_surfaces_gateway_reason_and_records_attempt(): void
    {
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice();

        // End-to-end failure-notification oracle: the first failed attempt must
        // route the system_charge_failed event through the production mailer
        // (SystemChargeService fires system_charge_failed_notification on
        // attempt 1; EmailNotificationMailer routes it to system_charge_failed).
        $spy = $this->spyMailerCapturingDispatchedEvent();

        FakeSystemGateway::$nextResult = 'fail';
        $result = SystemChargeService::chargeNow(Order::query()->find($invoice->id), $sub, 1);

        $this->assertIsArray($result);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('declined', $result['message']);

        $invoiceAfter = Order::query()->find($invoice->id);
        $this->assertSame('pending', $invoiceAfter->payment_status, 'failed charge degrades the invoice to pending');

        $state = $this->reloadSubscription($sub)->getMeta('system_charge_state', []);
        $this->assertSame((int) $invoice->id, (int) ($state['order_id'] ?? 0));
        $this->assertSame(1, (int) ($state['attempts'] ?? 0));
        $this->assertNotEmpty($state['last_error'] ?? '');

        $failureEvents = array_keys(array_filter(
            $spy['captured']['events'],
            function ($event) {
                return $event === 'system_charge_failed';
            }
        ));
        $this->assertCount(
            1,
            $failureEvents,
            'the first failed attempt must dispatch system_charge_failed to the mailer exactly once'
        );
        $failurePayload = $spy['captured']['data'][$failureEvents[0]];
        $this->assertSame(
            (int) $invoice->id,
            (int) ($failurePayload['order']->id ?? 0),
            'the charge-failure email payload must carry the failed renewal order'
        );
        $this->assertStringContainsString(
            'declined',
            (string) ($failurePayload['error'] ?? ''),
            'the charge-failure email payload must carry the gateway error message'
        );
    }

    // =========================================================================
    // Post-exhaustion semantics — one attempt, no ladder restart
    // =========================================================================

    public function test_charge_now_after_exhaustion_runs_one_attempt_without_ladder_restart(): void
    {
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice();

        $sub->updateMeta('system_charge_state', [
            'order_id'     => (int) $invoice->id,
            'attempts'     => 4,
            'max_attempts' => 4,
            'exhausted'    => 'yes',
            'last_error'   => 'old decline',
        ]);

        FakeSystemGateway::$nextResult = 'fail';
        $result = SystemChargeService::chargeNow(Order::query()->find($invoice->id), $this->reloadSubscription($sub), 1);

        $this->assertIsArray($result);
        $this->assertSame('failed', $result['status']);

        $state = $this->reloadSubscription($sub)->getMeta('system_charge_state', []);
        $this->assertSame(5, (int) ($state['attempts'] ?? 0), 'manual attempt consumes the next slot');

        // The slot the GATEWAY was handed, not just the one recorded locally.
        // handleFailure() writes attempts independently, so dropping the attempt
        // from the chargeRenewal() call leaves the meta above correct while the
        // gateway silently charges slot 1. Real gateways derive the idempotency
        // key from that number, so every retry would reuse the first attempt's
        // key and the retry contract breaks with no local symptom.
        $this->assertCount(1, FakeSystemGateway::$chargeRenewalArgs,
            'exactly one renewal charge reaches the gateway');
        $this->assertSame(
            ['order_id' => (int) $invoice->id, 'subscription_id' => (int) $sub->id, 'attempt' => 5],
            FakeSystemGateway::$chargeRenewalArgs[0],
            'the gateway is handed this invoice, this subscription and slot 5'
        );
        $this->assertSame('yes', $state['exhausted'] ?? '', 'post-ladder failure records exhaustion again');
        $this->assertFalse(
            SystemChargeService::hasQueuedCharge(Order::query()->find($invoice->id), null),
            'no retry may be queued after a post-exhaustion manual failure'
        );
    }

    // =========================================================================
    // Guards
    // =========================================================================

    public function test_charge_now_rejects_settling_processing_charge(): void
    {
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice();

        $sub->updateMeta('system_charge_state', [
            'status'   => 'processing',
            'order_id' => (int) $invoice->id,
            'attempts' => 1,
        ]);

        $result = SystemChargeService::chargeNow(Order::query()->find($invoice->id), $this->reloadSubscription($sub), 1);

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('charge_settling', $result->get_error_code());
        $this->assertSame(0, FakeSystemGateway::$chargeCalls, 'the gateway must not be called');
    }

    public function test_charge_now_rejects_when_slot_budget_exhausted(): void
    {
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice();

        $sub->updateMeta('system_charge_state', [
            'order_id'  => (int) $invoice->id,
            'attempts'  => SystemChargeService::MAX_ATTEMPT_SLOTS,
            'exhausted' => 'yes',
        ]);

        $result = SystemChargeService::chargeNow(Order::query()->find($invoice->id), $this->reloadSubscription($sub), 1);

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('attempts_exhausted', $result->get_error_code());
    }

    public function test_charge_now_rejects_paused_and_non_system_subscriptions(): void
    {
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice();

        Subscription::query()->where('id', $sub->id)->update(['status' => 'paused']);
        $result = SystemChargeService::chargeNow(Order::query()->find($invoice->id), $this->reloadSubscription($sub), 1);
        $this->assertTrue(is_wp_error($result));
        $this->assertSame('invalid_status', $result->get_error_code());

        Subscription::query()->where('id', $sub->id)->update(['status' => 'active', 'collection_method' => 'manual']);
        $result = SystemChargeService::chargeNow(Order::query()->find($invoice->id), $this->reloadSubscription($sub), 1);
        $this->assertTrue(is_wp_error($result));
        $this->assertSame('not_system', $result->get_error_code());
    }

    public function test_charge_now_rejects_paid_invoice(): void
    {
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice();

        Order::query()->where('id', $invoice->id)->update(['payment_status' => 'paid']);

        $result = SystemChargeService::chargeNow(Order::query()->find($invoice->id), $sub, 1);
        $this->assertTrue(is_wp_error($result));
        $this->assertSame('invalid_invoice', $result->get_error_code());
    }

    public function test_charge_now_rejects_store_mode_mismatch(): void
    {
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice();

        $this->setStoreOrderMode('test');

        $result = SystemChargeService::chargeNow(Order::query()->find($invoice->id), $sub, 1);
        $this->assertTrue(is_wp_error($result));
        $this->assertSame('store_mode_mismatch', $result->get_error_code());
        $this->assertSame(0, FakeSystemGateway::$chargeCalls);
    }

    public function test_execute_charge_holds_invoice_on_store_mode_mismatch(): void
    {

        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice();

        // The fixture's RenewalService::createRenewalOrders() already queued
        // attempt 1 through scheduleCharge(). Action Scheduler consumes the
        // firing job before the hook body runs, so drop it here to match
        // production. Without this the rescheduling assertion below reads the
        // fixture's own pending job and passes even when the hold schedules
        // nothing at all.
        as_unschedule_action(SystemChargeService::HOOK, [$invoice->id, 1], 'fluent-cart');
        $this->assertFalse(
            as_next_scheduled_action(SystemChargeService::HOOK, [$invoice->id, 1], 'fluent-cart'),
            'precondition: the firing job is consumed, so any queued action below is the hold\'s own'
        );

        $this->setStoreOrderMode('test');

        (new SystemChargeService())->executeCharge($invoice->id, 1);

        $this->assertSame(0, FakeSystemGateway::$chargeCalls, 'a mode-mismatched invoice must never reach the gateway');

        $invoiceAfter = Order::query()->find($invoice->id);
        $this->assertSame('payment_scheduled', $invoiceAfter->payment_status, 'the hold must not fail the invoice into dunning');

        $rearmed = as_next_scheduled_action(SystemChargeService::HOOK, [$invoice->id, 1], 'fluent-cart');
        $this->assertNotFalse($rearmed,
            'the held charge must re-arm a future re-check, or billing stops for good once modes match again');
        $this->assertGreaterThan(time() + (23 * HOUR_IN_SECONDS), $rearmed,
            'the re-check is armed roughly a day out, not immediately');

        // Modes match again — the re-check charges normally.
        $this->setStoreOrderMode('live');
        FakeSystemGateway::$nextResult = 'success';

        (new SystemChargeService())->executeCharge($invoice->id, 1);

        $this->assertSame(1, FakeSystemGateway::$chargeCalls);
        $this->assertSame('paid', Order::query()->find($invoice->id)->payment_status);
    }

    public function test_reconcile_holds_on_store_mode_mismatch(): void
    {

        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice();

        $sub->updateMeta('system_charge_state', [
            'status'   => 'processing',
            'order_id' => (int) $invoice->id,
            'attempts' => 1,
        ]);

        $this->setStoreOrderMode('test');

        (new SystemChargeService())->reconcileProcessingCharge($invoice->id);

        // Without the guard, the fake gateway's WP_Error reconcile result would
        // fail the invoice into dunning (pending).
        $this->assertSame('payment_scheduled', Order::query()->find($invoice->id)->payment_status);
        $this->assertSame('processing', $this->reloadSubscription($sub)->getMeta('system_charge_state')['status']);
        $this->assertNotFalse(
            as_next_scheduled_action(SystemChargeService::RECONCILE_HOOK, [(int) $invoice->id], 'fluent-cart'),
            'the held reconcile must re-arm a future re-check'
        );
    }

    public function test_disabled_mode_guard_lets_execute_charge_proceed_on_mismatch(): void
    {
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice();

        $this->setStoreOrderMode('test');
        $this->setStoreSetting('subscription_mode_guard', 'no');
        FakeSystemGateway::$nextResult = 'success';

        (new SystemChargeService())->executeCharge($invoice->id, 1);

        $this->assertSame(1, FakeSystemGateway::$chargeCalls);
        $this->assertSame('paid', Order::query()->find($invoice->id)->payment_status);
    }

    // =========================================================================
    // Queued automatic attempt is superseded by the manual one
    // =========================================================================

    public function test_charge_now_unschedules_queued_automatic_attempt(): void
    {

        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice();

        // Invoice creation queued the slot-1 attempt.
        $this->assertTrue(SystemChargeService::hasQueuedCharge(Order::query()->find($invoice->id), null));

        FakeSystemGateway::$nextResult = 'success';
        SystemChargeService::chargeNow(Order::query()->find($invoice->id), $sub, 1);

        $this->assertFalse(
            SystemChargeService::hasQueuedCharge(Order::query()->find($invoice->id), null),
            'the manual attempt must supersede (unschedule) the queued automatic one'
        );
    }

    // =========================================================================
    // canChargeNow permission
    // =========================================================================

    public function test_can_charge_now_permission_matrix(): void
    {
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice();

        $this->assertTrue((bool) $this->reloadSubscription($sub)->permissions['canChargeNow'], 'open invoice → chargeable');

        $sub->updateMeta('system_charge_state', [
            'status'   => 'processing',
            'order_id' => (int) $invoice->id,
        ]);
        $this->assertFalse((bool) $this->reloadSubscription($sub)->permissions['canChargeNow'], 'settling → hidden');
        $sub->deleteMeta('system_charge_state');

        Order::query()->where('id', $invoice->id)->update(['payment_status' => 'paid']);
        $this->assertFalse((bool) $this->reloadSubscription($sub)->permissions['canChargeNow'], 'no open invoice → hidden');
    }

    public function test_manual_subscription_never_gets_can_charge_now(): void
    {
        ['sub' => $sub] = $this->buildSystemSubscriptionWithInvoice([
            'collection_method' => 'manual',
        ]);

        $this->assertFalse((bool) $this->reloadSubscription($sub)->permissions['canChargeNow']);
    }

    // =========================================================================
    // Route contract
    // =========================================================================

    public function test_charge_now_route_requires_subscriptions_manage_permission(): void
    {
        $src = file_get_contents(
            FLUENTCART_PLUGIN_PATH . 'app/Modules/Subscriptions/Http/subscriptions-api.php'
        );

        $pos = strpos($src, "'chargeNow'");
        $this->assertNotFalse($pos, 'charge-now route must be registered');
        $window = substr($src, $pos, 400);
        $this->assertStringContainsString("'permissions' => 'subscriptions/manage'", $window);
    }
}
