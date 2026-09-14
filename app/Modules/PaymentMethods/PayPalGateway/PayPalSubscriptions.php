<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

class PayPalSubscriptions extends AbstractSubscriptionModule
{
    /**
     * Read-only lookup used by the admin "Edit Vendor IDs" verify action.
     *
     * PayPal's status vocabulary is its own (ACTIVE / SUSPENDED / CANCELLED), so it is
     * mapped through SubscriptionManager the same way the resync path does.
     */
    public function verifyVendorSubscription(array $args, $mode = 'current')
    {
        $vendorSubscriptionId = Arr::get($args, 'vendor_subscription_id');

        if (!$vendorSubscriptionId) {
            return new \WP_Error('invalid_subscription', __('A Vendor Subscription ID is required to look up a PayPal subscription.', 'fluent-cart'));
        }

        $subscription = (new API())->verifySubscription($vendorSubscriptionId, $mode);

        if (is_wp_error($subscription)) {
            return $subscription;
        }

        $nextBilling = Arr::get($subscription, 'billing_info.next_billing_time');

        return [
            'id'                => Arr::get($subscription, 'id'),
            'status'            => (new SubscriptionManager)->getCorrectSubscriptionStatus(Arr::get($subscription, 'status')),
            'customer_id'       => Arr::get($subscription, 'subscriber.payer_id'),
            'amount'            => Arr::get($subscription, 'billing_info.last_payment.amount.value', ''),
            'currency'          => strtoupper((string) Arr::get($subscription, 'billing_info.last_payment.amount.currency_code')),
            'next_billing_date' => $nextBilling ? gmdate('Y-m-d H:i:s', strtotime($nextBilling)) : '',
        ];
    }

    /**
     * Fetch the subscription's remote transaction list and sort it ascending by
     * time — PayPal's own ordering is never trusted, so "earliest" means the same
     * thing to every consumer (the first-payment check, the resync loop).
     *
     * @param array $paypalSubscription already-fetched PayPal subscription payload
     * @return array|\WP_Error
     */
    public function fetchSortedRemoteTransactions(Subscription $subscriptionModel, array $paypalSubscription)
    {
        $order = $subscriptionModel->order;
        if (!$order) {
            return new \WP_Error(
                'parent_order_not_found',
                __('The subscription\'s parent order no longer exists.', 'fluent-cart')
            );
        }

        $response = (new API())->getResource('billing/subscriptions/' . $subscriptionModel->vendor_subscription_id . '/transactions', [
            'start_time' => Arr::get($paypalSubscription, 'start_time'),
            'end_time'   => DateTime::gmtNow()->format('Y-m-d\TH:i:s.v\Z')
        ], $order->mode);

        if (is_wp_error($response)) {
            return $response;
        }

        $paypalTransactions = Arr::get($response, 'transactions', []);
        usort($paypalTransactions, function ($a, $b) {
            return strtotime((string) Arr::get($a, 'time')) - strtotime((string) Arr::get($b, 'time'));
        });

        return $paypalTransactions;
    }

    /**
     * Earliest completed sale on the remote list, or null when none completed yet.
     *
     * @param array $paypalTransactions sorted output of fetchSortedRemoteTransactions()
     * @return array|null
     */
    public function getEarliestCompletedRemoteSale(array $paypalTransactions)
    {
        foreach ($paypalTransactions as $paypalTransaction) {
            if (strtolower((string) Arr::get($paypalTransaction, 'status')) === 'completed') {
                return $paypalTransaction;
            }
        }

        return null;
    }

    /**
     * Credit the extra cycles a sale collected via auto_bill_outstanding: a gross of
     * exactly k × recurring_total (k >= 2) means k − 1 missed cycles were billed with
     * this one. Written as an early_payment_history entry, which calculateBillCount()
     * already folds in. Idempotent per sale id; runs under acquireHistoryLock().
     *
     * @param Subscription $subscriptionModel
     * @param string       $saleId
     * @param int          $grossCents
     * @return bool|\WP_Error true when this call wrote the credit, false when none owed
     *                        or already present, WP_Error on lock timeout (unknown —
     *                        caller must retry, not record uncredited)
     */
    public function creditOutstandingCollection(Subscription $subscriptionModel, $saleId, $grossCents)
    {
        $cyclePrice = PayPalHelper::wireCents($subscriptionModel->recurring_total, $subscriptionModel->currency);
        $grossCents = (int) $grossCents;

        if (!$saleId || $cyclePrice <= 0 || $grossCents <= $cyclePrice) {
            return false;
        }

        // payment_failure_threshold is 3, so more than 3 consecutive missed
        // cycles cannot accumulate — the subscription would have suspended.
        $maxCyclesPerSale = 4;

        if ($grossCents % $cyclePrice !== 0 || ($grossCents / $cyclePrice) > $maxCyclesPerSale) {
            fluent_cart_add_log(
                __('PayPal renewal amount exceeds the cycle price', 'fluent-cart'),
                sprintf(
                    /* translators: 1: PayPal sale ID, 2: charged amount in cents, 3: cycle price in cents */
                    __('PayPal sale %1$s charged %2$d against a cycle price of %3$d — not an exact cycle multiple, so no extra cycles were credited. Review the subscription\'s billing history manually.', 'fluent-cart'),
                    $saleId,
                    $grossCents,
                    $cyclePrice
                ),
                'error',
                [
                    'module_name' => 'subscription',
                    'module_id'   => $subscriptionModel->id
                ]
            );
            return false;
        }

        $cyclesPaid = (int) ($grossCents / $cyclePrice);

        // normal single-cycle payment, nothing to credit
        if ($cyclesPaid <= 1) {
            return false;
        }

        if (!$this->acquireHistoryLock($subscriptionModel)) {
            fluent_cart_add_log(
                __('PayPal outstanding credit deferred — lock timeout', 'fluent-cart'),
                sprintf(
                    /* translators: %s: PayPal sale ID */
                    __('Could not acquire the billing-history lock while crediting PayPal sale %s; the payment was left unrecorded so a redelivery or scheduled resync can credit and record it together.', 'fluent-cart'),
                    $saleId
                ),
                'warning',
                [
                    'module_name' => 'subscription',
                    'module_id'   => $subscriptionModel->id
                ]
            );
            return new \WP_Error(
                'paypal_history_lock_timeout',
                __('Could not acquire the billing-history lock to credit this collection.', 'fluent-cart')
            );
        }

        try {
            $history = (array) $subscriptionModel->getMeta('early_payment_history', []);

            foreach ($history as $entry) {
                if (Arr::get($entry, 'type') === 'outstanding_collection'
                    && Arr::get($entry, 'vendor_charge_id') === $saleId
                ) {
                    return false;
                }
            }

            $history[] = [
                'type'             => 'outstanding_collection',
                'count'            => $cyclesPaid,
                'vendor_charge_id' => $saleId,
                'amount'           => $grossCents,
                'date'             => DateTime::gmtNow()->format('Y-m-d H:i:s'),
            ];

            $subscriptionModel->updateMeta('early_payment_history', $history);
        } finally {
            $this->releaseHistoryLock($subscriptionModel);
        }

        fluent_cart_add_log(
            __('PayPal outstanding balance collected', 'fluent-cart'),
            sprintf(
                /* translators: 1: PayPal sale ID, 2: number of cycles the sale covered */
                __('PayPal sale %1$s collected the outstanding balance of previously missed cycles — one payment covering %2$d billing cycles. The extra cycles were credited to the local bill count.', 'fluent-cart'),
                $saleId,
                $cyclesPaid
            ),
            'info',
            [
                'module_name' => 'subscription',
                'module_id'   => $subscriptionModel->id
            ]
        );

        return true;
    }

    /**
     * Undo a credit whose renewal recording rolled back. The credit meta commits
     * outside recordRenewalPayment()'s DB transaction, so it must be revoked by hand.
     *
     * @param Subscription $subscriptionModel
     * @param string       $saleId
     * @return void
     */
    public function revokeOutstandingCollection(Subscription $subscriptionModel, $saleId)
    {
        if (!$saleId || !$this->acquireHistoryLock($subscriptionModel)) {
            return;
        }

        $revoked = false;

        try {
            $history = (array) $subscriptionModel->getMeta('early_payment_history', []);
            $kept = [];

            foreach ($history as $entry) {
                if (Arr::get($entry, 'type') === 'outstanding_collection'
                    && Arr::get($entry, 'vendor_charge_id') === $saleId
                ) {
                    continue;
                }
                $kept[] = $entry;
            }

            if (count($kept) !== count($history)) {
                // An empty array does not survive the meta cast round-trip
                // (it comes back as the string "[]"), so drop the row instead.
                if ($kept) {
                    $subscriptionModel->updateMeta('early_payment_history', array_values($kept));
                } else {
                    $subscriptionModel->deleteMeta('early_payment_history');
                }
                $revoked = true;
            }
        } finally {
            $this->releaseHistoryLock($subscriptionModel);
        }

        if ($revoked) {
            fluent_cart_add_log(
                __('PayPal outstanding credit revoked', 'fluent-cart'),
                sprintf(
                    /* translators: 1: PayPal sale ID */
                    __('The outstanding-collection credit for PayPal sale %1$s was revoked because the renewal recording it backed failed. The retry or redelivery that finally records the sale will credit it again.', 'fluent-cart'),
                    $saleId
                ),
                'warning',
                [
                    'module_name' => 'subscription',
                    'module_id'   => $subscriptionModel->id
                ]
            );
        }
    }

    /**
     * Per-subscription lock for early_payment_history read-modify-writes (the blob
     * is one row per subscription, so a per-sale lock would let two sales clobber
     * it). Always released before recordRenewalPayment() takes its per-sale
     * fc_webhook_ lock — the two are never held together.
     *
     * @param Subscription $subscriptionModel
     * @return bool
     */
    private function acquireHistoryLock(Subscription $subscriptionModel)
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", 'fc_sub_history_' . $subscriptionModel->id));
    }

    /**
     * @param Subscription $subscriptionModel
     * @return void
     */
    private function releaseHistoryLock(Subscription $subscriptionModel)
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", 'fc_sub_history_' . $subscriptionModel->id));
    }

    /**
     * Repair local rows against PayPal: bind the earliest completed sale to the
     * first-cycle transaction, record every missing sale as a dated renewal, sync
     * status fields.
     *
     * @param Subscription $subscriptionModel
     * @param array|null   $paypalSubscription  fetched here when null
     * @param array|null   $paypalTransactions  sorted list; fetched here when null
     * @return Subscription|\WP_Error
     */
    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel, $paypalSubscription = null, $paypalTransactions = null)
    {
        $order = $subscriptionModel->order;
        if (!$order) {
            return new \WP_Error(
                'parent_order_not_found',
                __('The subscription\'s parent order no longer exists.', 'fluent-cart')
            );
        }

        // The webhook has usually fetched the subscription already (plan
        // verification) — reuse its payload instead of asking PayPal again.
        if (!is_array($paypalSubscription)) {
            $paypalSubscription = (new API())->verifySubscription($subscriptionModel->vendor_subscription_id, $order->mode);
        }

        if (is_wp_error($paypalSubscription)) {
            return $paypalSubscription;
        }

        $newPayment = false;

        $subscriptionStatus = (new SubscriptionManager)->getCorrectSubscriptionStatus(Arr::get($paypalSubscription, 'status'));
        $nextBillingDate = Arr::get($paypalSubscription, 'billing_info.next_billing_time') ?? null;

        $payer = Arr::get($paypalSubscription, 'subscriber', []);

        if ($nextBillingDate) {
            $nextBillingDate = gmdate('Y-m-d H:i:s', strtotime($nextBillingDate));
        }

        $subscriptionUpdateData = array_filter([
            'current_payment_method' => 'paypal',
            'status'                 => $subscriptionStatus,
            'vendor_customer_id'     => Arr::get($paypalSubscription, 'subscriber.payer_id'),
            'vendor_plan_id'         => Arr::get($paypalSubscription, 'plan_id')
        ]);

        if ($nextBillingDate) {
            $subscriptionUpdateData['next_billing_date'] = $nextBillingDate;
        }

        if (Arr::get($paypalSubscription, 'status') === 'CANCELLED') {
            $statusUpdateTime = Arr::get($paypalSubscription, 'status_update_time');
            if ($statusUpdateTime) {
                $subscriptionUpdateData['canceled_at'] = gmdate('Y-m-d H:i:s', strtotime($statusUpdateTime));
            }
        }

        // A caller that already pulled and sorted this same list (the IPN
        // first-payment check) hands it in directly — do not ask PayPal for it
        // a second time.
        if ($paypalTransactions === null) {
            $paypalTransactions = $this->fetchSortedRemoteTransactions($subscriptionModel, $paypalSubscription);

            if (is_wp_error($paypalTransactions)) {
                return $paypalTransactions;
            }
        }

        $completedRemoteIds = [];
        $completedRemoteSales = [];
        foreach ($paypalTransactions as $paypalTransaction) {
            if (strtolower((string) Arr::get($paypalTransaction, 'status')) === 'completed') {
                $completedRemoteSales[] = $paypalTransaction;
                $completedRemoteIds[] = Arr::get($paypalTransaction, 'id');
            }
        }

        // One indexed query for every locally recorded sale instead of one per
        // remote transaction — long-running subscriptions carry many cycles.
        $localByChargeId = [];
        if ($completedRemoteIds) {
            $localTransactions = OrderTransaction::query()
                ->select(['id', 'order_id', 'status', 'meta', 'vendor_charge_id'])
                ->whereIn('vendor_charge_id', $completedRemoteIds)
                ->get();
            foreach ($localTransactions as $localTransaction) {
                $localByChargeId[$localTransaction->vendor_charge_id] = $localTransaction;
            }
        }

        $isEarliestCompleted = true;

        foreach ($paypalTransactions as $paypalTransaction) {
            if (strtolower((string) Arr::get($paypalTransaction, 'status')) !== 'completed') {
                continue;
            }

            // Only the earliest completed sale may claim the first-cycle row —
            // consume the flag here, even when this sale matches by charge id
            // and never reaches the claim step.
            $mayClaimFirstCycle = $isEarliestCompleted;
            $isEarliestCompleted = false;

            $chargeId = Arr::get($paypalTransaction, 'id');
            $amount = Helper::toCent(Arr::get($paypalTransaction, 'amount_with_breakdown.gross_amount.value', 0));
            $settledAt = DateTime::anyTimeToGmt(Arr::get($paypalTransaction, 'time'))->format('Y-m-d H:i:s');

            // Step 1 — sale already recorded locally: make sure it is confirmed.
            $transaction = isset($localByChargeId[$chargeId]) ? $localByChargeId[$chargeId] : null;

            if ($transaction) {
                // @TODO Remove this call (and maybeBackfillOutstandingCredit itself)
                // if recurring_total ever becomes updatable on a live
                // subscription — see the method's docblock.
                if (!$mayClaimFirstCycle) {
                    $this->maybeBackfillOutstandingCredit($subscriptionModel, $completedRemoteSales, $chargeId, $amount);
                }
                $this->bindSaleToTransaction($transaction, $chargeId, $amount, $payer, $settledAt);
                continue;
            }

            // Step 2 — the earliest completed sale claims the first-cycle row:
            // either it never got its id (missed first-payment webhook) or it
            // was mis-stamped with a later sale id by the removed IPN fill-in.
            if ($mayClaimFirstCycle) {
                $firstCycleTransaction = $this->findClaimableFirstCycleTransaction(
                    $subscriptionModel,
                    $chargeId,
                    $amount,
                    $completedRemoteSales
                );

                if ($firstCycleTransaction) {

                    // A mis-stamped row was preloaded under the id it wrongly
                    // held; drop that stale key so the displaced sale falls
                    // through to Step 3 when its own iteration comes around.
                    if ($firstCycleTransaction->vendor_charge_id) {
                        unset($localByChargeId[$firstCycleTransaction->vendor_charge_id]);
                    }

                    $this->bindSaleToTransaction($firstCycleTransaction, $chargeId, $amount, $payer, $settledAt);
                    continue;
                }
            }

            // Step 3 — unknown completed sale: record a renewal payment dated
            // by PayPal's own settle time, not the resync run time. The
            // outstanding-collection credit goes on the books first —
            // recordRenewalPayment() recomputes bill_count and the installment
            // end-of-term inside itself, and the credit is idempotent per sale.
            // Never for the earliest sale: only a REGULAR-cycle price is an
            // immutable multiple base — the first sale can carry a signup fee.
            $credited = $mayClaimFirstCycle
                ? false
                : $this->creditOutstandingCollection($subscriptionModel, $chargeId, $amount);

            if (is_wp_error($credited)) {
                return $credited;
            }

            $result = SubscriptionService::recordRenewalPayment([
                'subscription_id'     => $subscriptionModel->id,
                'payment_method'      => 'paypal',
                'vendor_charge_id'    => $chargeId,
                'payment_method_type' => 'PayPal',
                'total'               => $amount,
                'meta'                => [
                    'payer'      => $payer,
                    'settled_at' => $settledAt
                ],
                'created_at'          => $settledAt,
            ], $subscriptionModel, $subscriptionUpdateData);

            if (is_wp_error($result)) {
                if ($result->get_error_code() === 'transaction_exists') {
                    continue;
                }

                if ($result->get_error_code() === 'lock_failed') {
                    // The preloaded map predates the lock wait. Read again before
                    // treating this sale as recorded; its holder may still fail.
                    if ($this->hasRecordedSale($subscriptionModel, $chargeId)) {
                        continue;
                    }

                    // Do not revoke credit that the concurrent recorder may need.
                    return $result;
                }

                if ($credited) {
                    $this->revokeOutstandingCollection($subscriptionModel, $chargeId);
                }

                return $result;
            }

            $newPayment = true;
        }

        if (!$newPayment) {
            $subscriptionModel = SubscriptionService::syncSubscriptionStates($subscriptionModel, $subscriptionUpdateData);
        } else {
            $subscriptionModel = Subscription::query()->find($subscriptionModel->id);
        }

        return $subscriptionModel;
    }

    /**
     * Whether a settled (succeeded or refunded) charge row exists for this sale.
     *
     * @param Subscription $subscriptionModel
     * @param string       $saleId
     * @return bool
     */
    public function hasRecordedSale(Subscription $subscriptionModel, $saleId): bool
    {
        return OrderTransaction::query()
            ->where('subscription_id', $subscriptionModel->id)
            ->where('payment_method', 'paypal')
            ->where('vendor_charge_id', $saleId)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->whereIn('status', [Status::TRANSACTION_SUCCEEDED])
            ->exists();
    }

    /**
     * Retroactive outstanding-collection credit for a sale recorded before the credit
     * existed. Credits only when another completed sale on the list charged exactly
     * the current recurring_total, since a historical gross is compared against the
     * CURRENT price. Self-contained: this method and its single Step-1 call are the
     * whole feature.
     *
     * @TODO REMOVE (do not patch) if recurring_total ever becomes updatable on a live
     * subscription — the comparison is only sound while the vendor plan price is
     * immutable.
     *
     * @param Subscription $subscriptionModel
     * @param array        $completedRemoteSales completed entries of the sorted remote list
     * @param string       $chargeId
     * @param int          $grossCents
     * @return void
     */
    private function maybeBackfillOutstandingCredit(Subscription $subscriptionModel, array $completedRemoteSales, $chargeId, $grossCents)
    {
        $cyclePrice = PayPalHelper::wireCents($subscriptionModel->recurring_total, $subscriptionModel->currency);

        if ($cyclePrice <= 0 || (int) $grossCents <= $cyclePrice) {
            return;
        }

        $corroborated = false;
        foreach ($completedRemoteSales as $remoteSale) {
            if (Arr::get($remoteSale, 'id') === $chargeId) {
                continue;
            }
            if (Helper::toCent(Arr::get($remoteSale, 'amount_with_breakdown.gross_amount.value', 0)) === $cyclePrice) {
                $corroborated = true;
                break;
            }
        }

        if (!$corroborated) {
            return;
        }

        // Best effort by design: this sale is already recorded, and a later
        // resync retries the backfill, so a lock timeout just skips this pass.
        if (true === $this->creditOutstandingCollection($subscriptionModel, $chargeId, $grossCents)) {
            SubscriptionService::syncSubscriptionStates($subscriptionModel, []);

            // The shared credit log reads like a live collection; make the
            // history say this one was added retroactively by a resync.
            fluent_cart_add_log(
                __('PayPal outstanding credit backfilled', 'fluent-cart'),
                sprintf(
                    /* translators: 1: PayPal sale ID */
                    __('A resync found PayPal sale %1$s already recorded with a multi-cycle gross but no outstanding-collection credit — the credit was added retroactively and the bill count resynced.', 'fluent-cart'),
                    $chargeId
                ),
                'info',
                [
                    'module_name' => 'subscription',
                    'module_id'   => $subscriptionModel->id
                ]
            );
        }
    }

    /**
     * Find the first-cycle transaction the earliest completed sale should own.
     * Two shapes, checked in order:
     *   1. Row never got its vendor_charge_id (missed first-payment webhook).
     *   2. Row was mis-stamped with a later sale id by the removed IPN fill-in.
     *
     * @param Subscription $subscriptionModel
     * @param string       $chargeId  earliest completed remote sale id
     * @param int          $amount    that sale's gross amount in cents
     * @param array        $completedRemoteSales completed entries of the sorted remote list
     * @return OrderTransaction|null
     */
    private function findClaimableFirstCycleTransaction(Subscription $subscriptionModel, $chargeId, $amount, array $completedRemoteSales)
    {
        // The amount cannot be an SQL constraint: a zero-decimal total is stored
        // x100 but charged rounded, so a stored JPY 100050 arrives back from
        // PayPal as 100100 and matches no row. Match on what PayPal would
        // actually have moved instead. Dropping the amount check altogether
        // would let any unbound charge row claim this sale.
        // id ASC: two unbound rows can share a wire amount (a missed first-payment
        // webhook plus a later renewal at the same price), and the earliest sale
        // owns the oldest row. Without it the claim rides on storage-engine order.
        $unbound = OrderTransaction::query()
            ->select(['id', 'order_id', 'status', 'meta', 'vendor_charge_id', 'total', 'currency'])
            ->where('subscription_id', $subscriptionModel->id)
            ->where('vendor_charge_id', '')
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->orderBy('id', 'ASC')
            ->get();

        foreach ($unbound as $candidate) {
            if (PayPalHelper::wireCents($candidate->total, $candidate->currency) === $amount) {
                return $candidate;
            }
        }

        return $this->findMisStampedFirstCycleTransaction($subscriptionModel, $chargeId, $completedRemoteSales);
    }

    /**
     * Bind a completed remote sale to its local transaction. A non-succeeded row
     * goes through full payment confirmation (order paid, events fire); an already
     * succeeded row only gets the id column restamped, since its paid side effects
     * already ran.
     *
     * @param OrderTransaction $transaction
     * @param string           $chargeId
     * @param int              $amount
     * @param array            $payer
     * @param string           $settledAt
     * @return void
     */
    public function bindSaleToTransaction(OrderTransaction $transaction, $chargeId, $amount, array $payer, $settledAt)
    {
        if ($transaction->status !== Status::TRANSACTION_SUCCEEDED) {
            (new Processor())->confirmPaymentSuccessByCharge(
                OrderTransaction::query()->find($transaction->id),
                [
                    'vendor_charge_id'    => $chargeId,
                    'status'              => Status::TRANSACTION_SUCCEEDED,
                    'total'               => $amount,
                    'payment_method_type' => 'PayPal',
                    'meta'                => [
                        'payer'      => $payer,
                        'settled_at' => $settledAt
                    ]
                ]
            );
            return;
        }

        if ($transaction->vendor_charge_id === $chargeId) {
            return;
        }

        $meta = array_merge($transaction->meta, ['payer' => $payer]);
        if (empty($meta['settled_at'])) {
            $meta['settled_at'] = $settledAt;
        }

        $transaction->update([
            'vendor_charge_id'    => $chargeId,
            'status'              => Status::TRANSACTION_SUCCEEDED,
            'payment_method_type' => 'PayPal',
            'meta'                => $meta
        ]);
    }

    /**
     * Find a first-cycle row mis-stamped with a later sale id by the removed IPN
     * fill-in. Fingerprint: the row predates the sale it carries — a legit row never
     * does. Restamping frees the displaced id to be recorded as its own renewal.
     *
     * @param Subscription $subscriptionModel
     * @param string       $earliestChargeId
     * @param array        $completedRemoteSales completed entries of the sorted remote list
     * @return OrderTransaction|null
     */
    private function findMisStampedFirstCycleTransaction(Subscription $subscriptionModel, $earliestChargeId, array $completedRemoteSales)
    {
        // Every completed sale except the earliest, with PayPal's own settle
        // time — the only ids the old fill-in could have wrongly stamped (it
        // stamped whichever sale arrived first, not necessarily the 2nd).
        $laterSaleTimes = [];
        foreach ($completedRemoteSales as $remoteSale) {
            $saleId = Arr::get($remoteSale, 'id');
            if ($saleId && $saleId !== $earliestChargeId) {
                $laterSaleTimes[$saleId] = strtotime((string) Arr::get($remoteSale, 'time'));
            }
        }

        if (!$laterSaleTimes) {
            return null;
        }

        // Charge rows claiming a later sale id — no position or order-type
        // filter, so parent-order and reactivation/switch renewal-order rows
        // are both covered. id ASC: the mis-stamped row is always the oldest.
        $candidates = OrderTransaction::query()
            ->select(['id', 'order_id', 'status', 'meta', 'vendor_charge_id', 'total', 'created_at'])
            ->where('subscription_id', $subscriptionModel->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->whereIn('vendor_charge_id', array_keys($laterSaleTimes))
            ->orderBy('id', 'ASC')
            ->get();

        foreach ($candidates as $candidate) {
            // A refund was executed at PayPal against the id this row currently
            // holds — that binding became real; restamping would fork the
            // ledgers. The displaced sale still gets its own renewal in Step 3.
            if ((int) Arr::get($candidate->meta, 'refunded_total', 0) > 0) {
                continue;
            }

            // Mis-stamp = row created first, id updated later: created_at
            // predates the claimed sale by a full cycle. A legit row never
            // does — recordRenewalPayment stamps created_at with the sale's
            // own settle time. (Pending-invoice rows do predate their sale, but
            // never hold agreement sale ids — invoices are store-billed only.)
            // 1h margin covers clock drift; PayPal's minimum interval is daily.
            // Both timestamps are UTC. Unparseable time on either side → skip.
            $claimedSaleTime = $laterSaleTimes[$candidate->vendor_charge_id];
            $rowCreatedTime = strtotime($candidate->created_at . ' UTC');

            if ($claimedSaleTime && $rowCreatedTime && $rowCreatedTime < $claimedSaleTime - HOUR_IN_SECONDS) {
                return $candidate;
            }
        }

        return null;
    }

    public function cancel($vendorSubscriptionId, $args = [])
    {
        if (!$vendorSubscriptionId) {
            return new \WP_Error('invalid_subscription', __('Invalid vendor subscription ID.', 'fluent-cart'));
        }

        // first check , before canceling the subscription
        $paypalSubscription = (new API())->verifySubscription($vendorSubscriptionId, Arr::get($args, 'mode', ''));

        if (is_wp_error($paypalSubscription)) {
            return $paypalSubscription;
        }

        $subscriptionStatus = (new SubscriptionManager)->getCorrectSubscriptionStatus(Arr::get($paypalSubscription, 'status'));

        // CANCELLED and EXPIRED are terminal at PayPal — the cancel API rejects them with SUBSCRIPTION_STATUS_INVALID
        if (in_array($subscriptionStatus, [Status::SUBSCRIPTION_CANCELED, Status::SUBSCRIPTION_EXPIRED])) {
            $result = [
                'status' => $subscriptionStatus
            ];

            if ($subscriptionStatus === Status::SUBSCRIPTION_CANCELED) {
                $statusUpdateTime = Arr::get($paypalSubscription, 'status_update_time');
                $result['canceled_at'] = $statusUpdateTime ? gmdate('Y-m-d H:i:s', strtotime($statusUpdateTime)) : NULL;
            }

            return $result;
        }

        $response = API::createResource('billing/subscriptions/' . $vendorSubscriptionId . '/cancel', [
            'reason' => Arr::get($args, 'reason', __('Subscription canceled.', 'fluent-cart')),
        ], Arr::get($args, 'mode', ''));

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'status' => Status::SUBSCRIPTION_CANCELED
        ];
    }

    /**
     * Resume (activate) a suspended PayPal subscription.
     *
     * Called by SubscriptionService::resumeSubscription for automatic subscriptions.
     * Remote first: activates the subscription at PayPal, and only on success flips
     * the local status and fires the SubscriptionResumed event.
     *
     * @param Subscription $subscription
     * @param string $reason
     * @return true|\WP_Error
     */
    public function resume(Subscription $subscription, $reason = '')
    {
        $vendorSubscriptionId = $subscription->vendor_subscription_id;

        if (!$vendorSubscriptionId) {
            return new \WP_Error('invalid_subscription', __('Invalid vendor subscription ID.', 'fluent-cart'));
        }

        $order = $subscription->order;

        $response = API::createResource('billing/subscriptions/' . $vendorSubscriptionId . '/activate', [
            'reason' => $reason ?: __('Subscription resumed.', 'fluent-cart'),
        ], $order ? $order->mode : '');

        if (is_wp_error($response)) {
            return $response;
        }

        $oldStatus = $subscription->status;
        $subscription->status = Status::SUBSCRIPTION_ACTIVE;
        $subscription->save();

        $subscription->addLog(
            'Subscription resumed',
            $reason ?: __('Subscription resumed via PayPal', 'fluent-cart'),
            'info'
        );

        SubscriptionService::dispatchStatusEvent($subscription, 'resumed', [
            'old_status' => $oldStatus,
            'reason'     => $reason,
        ]);

        return true;
    }

    public function getOrCreateNewPlan($subscriptionId, $reason)
    {
        (new SubscriptionManager())->getOrCreateNewPlan($subscriptionId, $reason);
    }

    public function confirmSubscriptionSwitch($data, $subscriptionId)
    {
        (new SubscriptionManager())->confirmSubscriptionSwitch($data, $subscriptionId);
    }

}
