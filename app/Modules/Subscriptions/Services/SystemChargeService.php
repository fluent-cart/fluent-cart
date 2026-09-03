<?php

namespace FluentCart\App\Modules\Subscriptions\Services;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Payments\SubscriptionHelper;
use FluentCart\Framework\Support\Arr;

/**
 * Auto-charge engine for system (token-charged, store-billed) subscriptions.
 * Charges the stored token off-session on the invoice due date; failure flips
 * the invoice to `pending` and hands off to the normal manual dunning flow.
 * Token is resolved at fire time, never snapshotted, so a mid-cycle payment
 * method change is picked up by the next attempt.
 */
class SystemChargeService
{
    const HOOK = 'fluent_cart/subscriptions/system_charge_due';
    const RECONCILE_HOOK = 'fluent_cart/subscriptions/system_charge_reconcile';
    const SCHEDULER_GROUP = 'fluent-cart';

    // Async charges are re-checked daily; after this many checks the invoice
    // fails back to pending so normal dunning resumes.
    const RECONCILE_INTERVAL = DAY_IN_SECONDS;
    const MAX_RECONCILE_CHECKS = 7;

    // hasQueuedCharge()/unscheduleCharges() sweep exactly this many slots —
    // an attempt scheduled beyond it would be invisible to both.
    const MAX_ATTEMPT_SLOTS = 10;

    // Retry attempts as a fraction of the interval's grace period, so every
    // attempt lands before the subscription expires regardless of cadence.
    const RETRY_GRACE_FRACTIONS = [0.25, 0.6, 0.9];

    public function register()
    {
        add_action(self::HOOK, [$this, 'executeCharge'], 10, 2);
        add_action(self::RECONCILE_HOOK, [$this, 'reconcileProcessingCharge'], 10, 1);

        // A manual payment (Pay Now) against a scheduled/pending system invoice
        // makes the queued charge moot — unschedule it.
        add_action('fluent_cart/renewal_paid', [$this, 'cancelPendingCharge'], 20, 1);
    }

    /**
     * Kill switch for automatic system charging. Return false to stop every
     * charge attempt — e.g. on a staging clone whose live tokens would otherwise
     * double-charge real customers. Defaults on; billing is unaffected in prod.
     */
    public static function isSystemBillingEnabled(): bool
    {
        return (bool) apply_filters('fluent_cart/subscriptions/system_billing_enabled', true);
    }

    /**
     * Drop the stale decline reason after the payment method is replaced.
     * Retry bookkeeping (attempts, next_retry_at, processing marker) is kept —
     * the next attempt just reads the new token at fire time.
     */
    public static function clearFailureState($subscription)
    {
        $state = $subscription->getMeta('system_charge_state', []) ?: [];

        if (!isset($state['last_error'])) {
            return;
        }

        unset($state['last_error'], $state['last_attempt_at']);

        $hasBookkeeping = isset($state['status']) || isset($state['next_retry_at']) || isset($state['exhausted']);

        if ($hasBookkeeping) {
            $subscription->updateMeta('system_charge_state', $state);
            return;
        }

        $subscription->deleteMeta('system_charge_state');
    }

    /**
     * Whether a charge attempt is still queued for this invoice. While one is
     * pending, the overdue scanner must not escalate the invoice out from under it.
     *
     * @param array|null $chargeState subscription's `system_charge_state` meta;
     *                                null if caller doesn't have it loaded
     */
    public static function hasQueuedCharge($order, $chargeState = null): bool
    {
        if (!function_exists('as_next_scheduled_action')) {
            return false;
        }

        foreach (self::queuedAttemptSlots($order, $chargeState) as $attempt) {
            if (as_next_scheduled_action(self::HOOK, [$order->id, $attempt], self::SCHEDULER_GROUP)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Attempt slots that could plausibly hold a queued action. Only one attempt
     * is ever queued at a time, so charge state pins the slot — except the retry
     * is scheduled BEFORE state is written, so a crash mid-write can leave state
     * one attempt behind the scheduler; probe a range, not just the one slot,
     * to cover that gap. No state (null) gets the full sweep.
     *
     * @return array<int,int>
     */
    private static function queuedAttemptSlots($order, $chargeState): array
    {
        if ($chargeState === null) {
            return range(1, self::MAX_ATTEMPT_SLOTS);
        }

        if (!$chargeState || (int) Arr::get($chargeState, 'order_id') !== (int) $order->id) {
            return [1];
        }

        if (Arr::get($chargeState, 'exhausted') === 'yes' || Arr::get($chargeState, 'status') === 'processing') {
            return [];
        }

        $attempts = max(1, (int) Arr::get($chargeState, 'attempts', 0));
        $ceiling = max((int) Arr::get($chargeState, 'max_attempts', 0), $attempts + 1);

        return range($attempts, min($ceiling, self::MAX_ATTEMPT_SLOTS));
    }

    /**
     * Whether auto-retries are exhausted for THIS renewal order — source of
     * truth for every Pay Now surface's exhaustion gate.
     */
    public static function isExhausted($subscription, Order $order): bool
    {
        $state = $subscription->getMeta('system_charge_state', []) ?: [];

        if ((int) Arr::get($state, 'order_id') !== (int) $order->id) {
            return false;
        }

        return Arr::get($state, 'exhausted') === 'yes';
    }

    /**
     * Drop every queued attempt (and the reconciliation check) for one invoice.
     */
    public static function unscheduleCharges($order)
    {
        if (!function_exists('as_unschedule_action')) {
            return;
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPT_SLOTS; $attempt++) {
            as_unschedule_action(self::HOOK, [$order->id, $attempt], self::SCHEDULER_GROUP);
        }

        as_unschedule_action(self::RECONCILE_HOOK, [$order->id], self::SCHEDULER_GROUP);
    }

    public static function restoreScheduledChargesForSubscription($subscription): void
    {
        if (!$subscription || !$subscription->isSystem()) {
            return;
        }

        $scheduledInvoices = Order::query()
            ->where('parent_id', $subscription->parent_order_id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->where('payment_status', Status::PAYMENT_SCHEDULED)
            ->get();

        foreach ($scheduledInvoices as $invoice) {
            $chargeState = $subscription->getMeta('system_charge_state', []) ?: [];
            $isSettling = Arr::get($chargeState, 'status') === 'processing'
                && (int) Arr::get($chargeState, 'order_id') === (int) $invoice->id;

            if ($isSettling || self::hasQueuedCharge($invoice, $chargeState)) {
                continue;
            }

            self::scheduleCharge($invoice, $subscription);

            $subscription->addLog(
                'Automatic charge restored',
                sprintf('Renewal order #%s automatic charge was re-queued after the subscription resumed.', $invoice->invoice_no ?: $invoice->id),
                'info'
            );
        }
    }

    /**
     * Retry offsets, in days after the invoice due date. Anchored to the
     * interval's grace period so attempts always fit inside the dunning window.
     *
     * @return array<int,float> days after the due date, ascending
     */
    public static function getRetryOffsets($subscription): array
    {
        $graceDays = SubscriptionHelper::getGracePeriodDaysForInterval($subscription->billing_interval);

        $offsets = [];
        foreach (self::RETRY_GRACE_FRACTIONS as $fraction) {
            $offsets[] = round($graceDays * $fraction, 3);
        }

        $offsets = (array) apply_filters('fluent_cart/subscriptions/system_charge_retry_offsets', $offsets, [
            'subscription' => $subscription,
            'grace_days'   => $graceDays,
        ]);

        $offsets = array_map('floatval', array_filter($offsets, function ($offset) {
            return is_numeric($offset) && (float) $offset > 0;
        }));

        // A filter can hand back offsets in any order; keep them ascending.
        sort($offsets);

        // Attempt 1 is the due-date charge, so N offsets occupy slots 2..N+1 —
        // truncate the tail so the last one still fits MAX_ATTEMPT_SLOTS.
        if (count($offsets) > self::MAX_ATTEMPT_SLOTS - 1) {
            $offsets = array_slice($offsets, 0, self::MAX_ATTEMPT_SLOTS - 1);
        }

        return $offsets;
    }

    /**
     * Stop auto-charging a subscription whose payment method can no longer be
     * token-charged (e.g. a failed invoice paid with a non-capable gateway),
     * and hand it back to plain manual invoicing.
     */
    public static function reconcileGatewayCapability($subscription)
    {
        if (!$subscription || !$subscription->isSystem()) {
            return;
        }

        // App::gateway(null) returns the gateway manager, not a gateway.
        $gateway = $subscription->current_payment_method
            ? App::gateway($subscription->current_payment_method)
            : null;

        if ($gateway && $gateway->has('system_subscription')) {
            return;
        }

        $methodLabel = $gateway instanceof AbstractPaymentGateway
            ? $gateway->getMeta('title')
            : $subscription->current_payment_method;

        self::demoteToManual($subscription, sprintf(
        /* translators: %1$s: payment method name now on file */
            __('%1$s cannot charge a saved payment method automatically.', 'fluent-cart'),
            $methodLabel ?: __('The payment method on file', 'fluent-cart')
        ));
    }

    /**
     * system → manual: cancel the queued charges, drop the charge bookkeeping, and
     * put any invoice that was waiting for an automatic charge back into the manual
     * flow (pending + the pay-now invoice email it was deliberately not sent).
     */
    public static function demoteToManual($subscription, string $reason)
    {
        if ($subscription->collection_method === 'manual') {
            return;
        }

        $subscription->collection_method = 'manual';
        $subscription->save();

        $subscription->deleteMeta('system_charge_state');

        $openInvoices = Order::query()
            ->where('parent_id', $subscription->parent_order_id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->whereIn('payment_status', [Status::PAYMENT_SCHEDULED, Status::PAYMENT_PENDING])
            ->get();

        foreach ($openInvoices as $invoice) {
            self::unscheduleCharges($invoice);

            if ($invoice->payment_status !== Status::PAYMENT_SCHEDULED) {
                continue;
            }

            // Already paid via a gateway that can't auto-charge — leave it alone,
            // syncOrderStatuses is about to mark the order paid.
            $paidTotal = OrderTransaction::query()
                ->where('order_id', $invoice->id)
                ->where('status', Status::TRANSACTION_SUCCEEDED)
                ->sum('total');

            if ($paidTotal >= $invoice->total_amount) {
                continue;
            }

            $invoice->payment_status = Status::PAYMENT_PENDING;
            $invoice->save();

            // Created silently since a charge was coming — now it needs the
            // pay-now email the manual flow normally sends at creation.
            do_action('fluent_cart/renewal_created', [
                'subscription' => $subscription,
                'order'        => $invoice,
                'parent_order' => $subscription->order,
                'customer'     => $invoice->customer,
                'transaction'  => (new PaymentInstance($invoice))->transaction,
            ]);
        }

        $subscription->addLog(
            'Automatic charging disabled',
            sprintf('%s Renewal orders will be sent for manual payment from now on.', $reason),
            'warning'
        );

        do_action('fluent_cart/subscriptions/system_charge_disabled', [
            'subscription' => $subscription,
            'reason'       => $reason,
        ]);
    }

    /**
     * Admin-triggered immediate charge attempt on a system subscription's open
     * renewal invoice. One attempt per call — the retry ladder is not restarted.
     *
     * @return array|\WP_Error ['status' => 'paid'|'processing'|'failed', 'message' => string]
     *                         on an executed attempt; WP_Error for state violations.
     */
    public static function chargeNow(Order $invoice, $subscription, $actorId = 0)
    {
        if (!self::isSystemBillingEnabled()) {
            return new \WP_Error('system_billing_disabled', __('Automatic charging is disabled.', 'fluent-cart'));
        }

        if (!SubscriptionHelper::canProcessInMode($invoice->mode)) {
            return new \WP_Error('store_mode_mismatch', sprintf(
                /* translators: 1: the invoice's payment mode (live/test), 2: the store's current mode (live/test) */
                __('This invoice is in %1$s mode but the store is currently in %2$s mode. Switch the store mode to charge it.', 'fluent-cart'),
                $invoice->mode,
                (new StoreSettings())->get('order_mode')
            ));
        }

        if ($invoice->type !== Status::ORDER_TYPE_RENEWAL
            || !in_array($invoice->payment_status, [Status::PAYMENT_PENDING, Status::PAYMENT_SCHEDULED], true)
        ) {
            return new \WP_Error('invalid_invoice', __('Only an open (pending or scheduled) renewal order can be charged.', 'fluent-cart'));
        }

        if (!$subscription || !$subscription->isSystem()) {
            return new \WP_Error('not_system', __('Only auto-charged (system) subscriptions can be charged from here.', 'fluent-cart'));
        }

        // Same chargeable set as executeCharge(): expired IS chargeable — a late
        // payment is exactly what brings the subscription back.
        if (!in_array($subscription->status, [
            Status::SUBSCRIPTION_ACTIVE,
            Status::SUBSCRIPTION_TRIALING,
            Status::SUBSCRIPTION_PAST_DUE,
            Status::SUBSCRIPTION_EXPIRED,
        ], true)) {
            return new \WP_Error('invalid_status', sprintf(
            /* translators: %1$s: current subscription status */
                __('A %1$s subscription cannot be charged.', 'fluent-cart'),
                $subscription->status
            ));
        }

        $gateway = App::gateway($subscription->current_payment_method);
        if (!$gateway instanceof AbstractPaymentGateway || !$gateway->has('system_subscription')) {
            return new \WP_Error('gateway_unavailable', __('The payment method for this subscription is unavailable or no longer supports automatic charging.', 'fluent-cart'));
        }

        $chargeState = $subscription->getMeta('system_charge_state', []) ?: [];
        $stateIsForThisInvoice = (int) Arr::get($chargeState, 'order_id') === (int) $invoice->id;

        // A charge that was accepted and is settling may still succeed — charging
        // again risks a double payment (same rule as the reconciliation loop).
        if ($stateIsForThisInvoice && Arr::get($chargeState, 'status') === 'processing') {
            return new \WP_Error('charge_settling', __('A charge for this invoice was already submitted and is awaiting confirmation from the payment provider.', 'fluent-cart'));
        }

        // Manual attempts consume real attempt slots so the per-attempt idempotency
        // key semantics hold. When every slot is used, the customer's Pay Now link
        // is the remaining path.
        $attempts = $stateIsForThisInvoice ? max(0, (int) Arr::get($chargeState, 'attempts', 0)) : 0;
        $slot = $attempts + 1;

        if ($slot > self::MAX_ATTEMPT_SLOTS) {
            return new \WP_Error('attempts_exhausted', __('All charge attempts for this invoice have been used. Ask the customer to pay through their Pay Now link.', 'fluent-cart'));
        }

        // Deliberate flag clear: the admin is overriding a recorded exhaustion.
        if ($stateIsForThisInvoice && Arr::get($chargeState, 'exhausted') === 'yes') {
            unset($chargeState['exhausted']);
            $subscription->updateMeta('system_charge_state', $chargeState);
        }

        // The manual attempt supersedes any queued automatic one — never both.
        self::unscheduleCharges($invoice);

        (new static())->executeCharge($invoice->id, $slot);

        // Derive the outcome from the state the attempt left behind.
        $freshInvoice = Order::query()->find($invoice->id);
        $freshState = $subscription->getMeta('system_charge_state', []) ?: [];

        if ($freshInvoice && $freshInvoice->payment_status === Status::PAYMENT_PAID) {
            $result = [
                'status'  => 'paid',
                'message' => __('The invoice was charged successfully and the subscription has renewed.', 'fluent-cart'),
            ];
        } elseif ((int) Arr::get($freshState, 'order_id') === (int) $invoice->id
            && Arr::get($freshState, 'status') === 'processing'
        ) {
            $result = [
                'status'  => 'processing',
                'message' => __('The charge was submitted and is awaiting confirmation from the payment provider.', 'fluent-cart'),
            ];
        } else {
            $lastError = (int) Arr::get($freshState, 'order_id') === (int) $invoice->id
                ? (string) Arr::get($freshState, 'last_error', '')
                : '';

            $result = [
                'status'  => 'failed',
                'message' => $lastError !== ''
                    ? $lastError
                    : __('The charge attempt did not complete. Check the subscription activity log for details.', 'fluent-cart'),
            ];
        }

        $subscription->addLog(
            'Automatic charge triggered by admin',
            sprintf(
            /* translators: %1$d: attempt number, %2$s: attempt outcome (paid, processing or failed) */
                __('Charge attempt %1$d was triggered manually: %2$s', 'fluent-cart'),
                $slot,
                $result['status']
            ),
            $result['status'] === 'failed' ? 'warning' : 'info'
        );

        do_action('fluent_cart/subscriptions/system_charge_manual_triggered', [
            'order'        => $freshInvoice ?: $invoice,
            'subscription' => $subscription,
            'attempt'      => $slot,
            'actor_id'     => (int) $actorId,
            'result'       => $result['status'],
        ]);

        return $result;
    }

    public static function scheduleCharge($order, $subscription, $attempt = 1)
    {
        if (!self::isSystemBillingEnabled() || !function_exists('as_schedule_single_action')) {
            return;
        }

        $args = [$order->id, $attempt];

        if (function_exists('as_next_scheduled_action') && as_next_scheduled_action(self::HOOK, $args, self::SCHEDULER_GROUP)) {
            return;
        }

        $dueDate = $order->getMeta('due_date');
        $timestamp = max(time(), $dueDate ? strtotime($dueDate) : time());

        as_schedule_single_action($timestamp, self::HOOK, $args, self::SCHEDULER_GROUP);
    }

    /**
     * Action Scheduler callback — guarded, idempotent charge attempt.
     * Every guard logs-and-returns; this method never throws.
     */
    public function executeCharge($orderId, $attempt = 1)
    {
        // Env kill switch — the primary guard, because a cloned Action Scheduler
        // job fires this hook directly, bypassing scheduleCharge().
        if (!self::isSystemBillingEnabled()) {
            return;
        }

        /** @var Order|null $order */
        $order = Order::query()->find($orderId);

        if (!$order || $order->type !== Status::ORDER_TYPE_RENEWAL) {
            return;
        }

        // Paid (manually or by an earlier fire) or voided invoices are never charged.
        if (!in_array($order->payment_status, [Status::PAYMENT_SCHEDULED, Status::PAYMENT_PENDING], true)) {
            return;
        }

        $paymentInstance = new PaymentInstance($order);
        $subscription = $paymentInstance->subscription;

        if (!$subscription || !$subscription->isSystem()) {
            return;
        }

        // Paused/canceled/completed must not be charged. Expired IS charged — a late
        // retry is what reactivates it (handleRenewalPaid on payment).
        if (!in_array($subscription->status, [
            Status::SUBSCRIPTION_ACTIVE,
            Status::SUBSCRIPTION_TRIALING,
            Status::SUBSCRIPTION_PAST_DUE,
            Status::SUBSCRIPTION_EXPIRED,
        ], true)) {
            $subscription->addLog(
                'Automatic charge skipped',
                sprintf('Scheduled charge for renewal order #%s skipped — subscription is %s.', $order->invoice_no ?: $order->id, $subscription->status),
                'info'
            );
            return;
        }

        // Invoice mode vs store mode at fire time — a store flipped to test (or a
        // clone left in test mode) must not charge a live invoice. Hold, don't
        // fail: re-arm a daily re-check so the charge fires once modes match
        // again (or the subscription_mode_guard setting is turned off).
        if (!SubscriptionHelper::canProcessInMode($order->mode)) {
            if (function_exists('as_schedule_single_action')) {
                // No as_next_scheduled_action() dedup needed here (unlike
                // scheduleCharge): the firing action is already consumed, so
                // this is the only pending copy.
                as_schedule_single_action(time() + DAY_IN_SECONDS, self::HOOK, [$order->id, $attempt], self::SCHEDULER_GROUP);
            }

            // Log the transition into held once, not on every daily re-check —
            // a long-lived clone would otherwise grow fct_activity unbounded.
            if (!$order->getMeta('mode_guard_hold_logged')) {
                $order->updateMeta('mode_guard_hold_logged', 'yes');
                $subscription->addLog(
                    'Automatic charge held',
                    sprintf('Scheduled charge for renewal order #%s held — the invoice is in %s mode but the store is in %s mode. Will re-check daily.', $order->invoice_no ?: $order->id, $order->mode, (new StoreSettings())->get('order_mode')),
                    'warning'
                );
            }
            return;
        }

        $order->deleteMeta('mode_guard_hold_logged');

        // Capability re-check at fire time: the gateway may have been deactivated
        // or removed since the subscription was created.
        $gateway = App::gateway($subscription->current_payment_method);
        if (!$gateway instanceof AbstractPaymentGateway || !$gateway->has('system_subscription')) {
            $this->handleFailure($order, $subscription, new \WP_Error(
                'gateway_unavailable',
                __('The payment method for this subscription is unavailable or no longer supports automatic charging.', 'fluent-cart')
            ), $attempt);
            return;
        }

        if (!$paymentInstance->transaction) {
            $this->handleFailure($order, $subscription, new \WP_Error(
                'missing_transaction',
                __('No pending transaction found for this renewal order.', 'fluent-cart')
            ), $attempt);
            return;
        }

        $result = $gateway->chargeRenewal($paymentInstance, ['attempt' => $attempt]);

        if (is_wp_error($result)) {
            $this->handleFailure($order, $subscription, $result, $attempt);
            return;
        }

        if ($result === 'processing') {
            // Charge accepted but not settled (e.g. bank debits). Success fires later
            // from renewal_paid; reconcileProcessingCharge polls daily so a
            // lost webhook can't strand the invoice forever.
            $subscription->updateMeta('system_charge_state', [
                'status'           => 'processing',
                'order_id'         => (int) $order->id,
                'attempts'         => (int) $attempt,
                'reconcile_checks' => 0,
                'last_attempt_at'  => gmdate('Y-m-d H:i:s'),
            ]);

            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time() + self::RECONCILE_INTERVAL, self::RECONCILE_HOOK, [$order->id], self::SCHEDULER_GROUP);
            }

            $subscription->addLog(
                'Automatic charge initiated',
                sprintf('Renewal order #%s charge submitted to the payment method and is awaiting confirmation.', $order->invoice_no ?: $order->id),
                'info'
            );
            return;
        }

        $this->recordChargeSucceeded($order, $subscription, $attempt);
    }

    /**
     * Success log + contract hook. Fired synchronously for confirmed charges, or
     * from the renewal_paid listener once an async charge's webhook lands.
     */
    private function recordChargeSucceeded($order, $subscription, $attempt)
    {
        $subscription->addLog(
            'Automatic charge succeeded',
            sprintf('Renewal order #%s charged automatically to the saved payment method.', $order->invoice_no ?: $order->id),
            'info'
        );

        do_action('fluent_cart/subscriptions/system_charge_succeeded', [
            'order'        => $order,
            'subscription' => $subscription,
            'attempt'      => (int) $attempt,
        ]);
    }

    /**
     * Reconcile an async (processing) charge. Runs daily until the gateway confirms
     * settlement, fails definitively, or the check budget runs out — then fails the
     * invoice back to pending so normal dunning resumes.
     */
    public function reconcileProcessingCharge($orderId)
    {
        // Paused (e.g. staging): don't poll the live gateway — a retrieve that reads
        // `succeeded` would settle a cloned renewal and email the real customer.
        // Re-arm the daily check (budget untouched) so nothing is stranded once
        // billing resumes.
        if (!self::isSystemBillingEnabled()) {
            if (function_exists('as_schedule_single_action')
                && function_exists('as_next_scheduled_action')
                && !as_next_scheduled_action(self::RECONCILE_HOOK, [(int) $orderId], self::SCHEDULER_GROUP)
            ) {
                as_schedule_single_action(time() + self::RECONCILE_INTERVAL, self::RECONCILE_HOOK, [(int) $orderId], self::SCHEDULER_GROUP);
            }

            return;
        }

        $order = Order::query()->find($orderId);

        // Already resolved (webhook confirmed, manual payment, voided) — nothing to do.
        if (!$order || $order->payment_status !== Status::PAYMENT_SCHEDULED) {
            return;
        }

        $paymentInstance = new PaymentInstance($order);
        $subscription = $paymentInstance->subscription;

        if (!$subscription || !$subscription->isSystem()) {
            return;
        }

        $chargeState = $subscription->getMeta('system_charge_state', []) ?: [];

        if (Arr::get($chargeState, 'status') !== 'processing' || (int) Arr::get($chargeState, 'order_id') !== (int) $order->id) {
            return;
        }

        // Invoice mode vs store mode — same clone risk as the billing pause
        // above: retrieving a live PaymentIntent from a test-mode clone would
        // settle the copied order and fire renewal-paid side effects. Re-arm
        // (budget untouched) so reconciliation resumes once modes match.
        if (!SubscriptionHelper::canProcessInMode($order->mode)) {
            if (function_exists('as_schedule_single_action')
                && function_exists('as_next_scheduled_action')
                && !as_next_scheduled_action(self::RECONCILE_HOOK, [(int) $orderId], self::SCHEDULER_GROUP)
            ) {
                as_schedule_single_action(time() + self::RECONCILE_INTERVAL, self::RECONCILE_HOOK, [(int) $orderId], self::SCHEDULER_GROUP);
            }

            return;
        }

        $attempt = (int) Arr::get($chargeState, 'attempts', 1);
        $gateway = App::gateway($subscription->current_payment_method);

        $result = ($gateway instanceof AbstractPaymentGateway && $gateway->has('system_subscription'))
            ? $gateway->reconcileRenewalCharge($paymentInstance)
            : new \WP_Error('gateway_unavailable', __('The payment method for this subscription is unavailable.', 'fluent-cart'));

        if ($result === true) {
            // Settled payment recovered — renewal_paid's listener handles
            // the deferred success and clears the marker.
            return;
        }

        if ($result === 'processing') {
            $checks = (int) Arr::get($chargeState, 'reconcile_checks', 0) + 1;

            if ($checks < self::MAX_RECONCILE_CHECKS) {
                $chargeState['reconcile_checks'] = $checks;
                $subscription->updateMeta('system_charge_state', $chargeState);
                as_schedule_single_action(time() + self::RECONCILE_INTERVAL, self::RECONCILE_HOOK, [$order->id], self::SCHEDULER_GROUP);
                return;
            }

            $result = new \WP_Error('processing_timeout', sprintf(
            /* translators: %1$d: number of days waited for payment confirmation */
                __('The payment was not confirmed within %1$d days.', 'fluent-cart'),
                self::MAX_RECONCILE_CHECKS
            ));
        }

        $this->handleFailure($order, $subscription, $result, $attempt);
    }

    /**
     * Failed off-session charge: flip invoice to `pending`, re-entering normal
     * dunning (reminders, past_due → expired), send the charge-failed email
     * (first failure only, filterable), and schedule the next retry per
     * getRetryOffsets().
     */
    private function handleFailure($order, $subscription, \WP_Error $error, $attempt)
    {
        if ($order->payment_status === Status::PAYMENT_SCHEDULED) {
            $order->payment_status = Status::PAYMENT_PENDING;
            $order->save();
        }

        $offsets = self::getRetryOffsets($subscription);
        $maxAttempts = count($offsets) + 1;

        // Processing timeout may still settle at the gateway — no auto-retry to
        // avoid a double charge; customer pays manually instead.
        $allowRetry = $error->get_error_code() !== 'processing_timeout';

        $nextRetryAt = null;
        if ($allowRetry && $attempt < $maxAttempts && isset($offsets[$attempt - 1])) {
            $dueDate = $order->getMeta('due_date');
            $base = $dueDate ? strtotime($dueDate) : time();
            $nextTimestamp = max(time() + 300, $base + (int) round((float) $offsets[$attempt - 1] * DAY_IN_SECONDS));

            if (function_exists('as_schedule_single_action')
                && !as_next_scheduled_action(self::HOOK, [$order->id, $attempt + 1], self::SCHEDULER_GROUP)
            ) {
                as_schedule_single_action($nextTimestamp, self::HOOK, [$order->id, $attempt + 1], self::SCHEDULER_GROUP);
            }

            $nextRetryAt = gmdate('Y-m-d H:i:s', $nextTimestamp);
        }

        $chargeState = [
            'order_id'        => (int) $order->id,
            'attempts'        => (int) $attempt,
            'max_attempts'    => $maxAttempts,
            'last_error'      => $error->get_error_message(),
            'last_attempt_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($nextRetryAt) {
            $chargeState['next_retry_at'] = $nextRetryAt;
        } else {
            $chargeState['exhausted'] = 'yes';
        }
        $subscription->updateMeta('system_charge_state', $chargeState);

        $subscription->addLog(
            'Automatic charge failed',
            sprintf(
                'Attempt %1$d of %2$d to charge renewal order #%3$s failed: %4$s %5$s',
                $attempt,
                $maxAttempts,
                $order->invoice_no ?: $order->id,
                $error->get_error_message(),
                $nextRetryAt
                    ? sprintf('Next retry: %s.', $nextRetryAt)
                    : 'No further automatic retries.'
            ),
            'warning'
        );

        do_action('fluent_cart/subscriptions/system_charge_failed', [
            'order'         => $order,
            'subscription'  => $subscription,
            'attempt'       => (int) $attempt,
            'error'         => $error->get_error_message(),
            'next_retry_at' => $nextRetryAt,
        ]);

        // First failure, or the ladder just exhausted — the latter is when the Pay
        // Now CTA actually renders (see charge_failed/customer.php), so it must also
        // trigger a notification, not just an early attempt no one can act on.
        $shouldNotify = apply_filters('fluent_cart/subscriptions/system_charge_failure_notify', $attempt === 1 || self::isExhausted($subscription, $order), [
            'order'        => $order,
            'subscription' => $subscription,
            'attempt'      => (int) $attempt,
        ]);

        if ($shouldNotify) {
            do_action('fluent_cart/subscriptions/system_charge_failed_notification', [
                'order'         => $order,
                'subscription'  => $subscription,
                'parent_order'  => $subscription->order,
                'customer'      => $order->customer,
                'transaction'   => (new PaymentInstance($order))->transaction,
                'error'         => $error->get_error_message(),
                'attempt'       => (int) $attempt,
                'next_retry_at' => $nextRetryAt,
            ]);
        }
    }

    /**
     * fluent_cart/renewal_paid listener — unschedules any queued charge
     * for the paid invoice, and fires the deferred success log/hook if this
     * confirms an async (processing) system charge.
     */
    public function cancelPendingCharge($data)
    {
        $order = Arr::get($data, 'order');

        if (!$order) {
            return;
        }

        self::unscheduleCharges($order);

        if (!$order->parent_id) {
            return;
        }

        $subscription = \FluentCart\App\Models\Subscription::query()
            ->where('parent_order_id', $order->parent_id)
            ->first();

        if (!$subscription || !$subscription->isSystem()) {
            return;
        }

        $chargeState = $subscription->getMeta('system_charge_state', []) ?: [];

        // The order_id scope prevents a stale marker from a previous cycle's voided
        // invoice from emitting a bogus success for a later invoice's payment.
        if ((int) Arr::get($chargeState, 'order_id') === (int) $order->id) {
            $wasProcessing = Arr::get($chargeState, 'status') === 'processing';

            // Paid — retry/failure bookkeeping for this invoice is complete.
            $subscription->deleteMeta('system_charge_state');

            if ($wasProcessing) {
                $this->recordChargeSucceeded($order, $subscription, Arr::get($chargeState, 'attempts', 1));
            }
        }
    }
}
