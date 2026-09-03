<?php

namespace FluentCart\App\Services\Reminders;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SystemChargeService;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\SubscriptionHelper;

class RenewalReminderService extends ReminderService
{
    const META_KEY = 'renewal_reminder_state';
    const ASYNC_HOOK = 'fluent_cart/reminders/send_renewal';

    protected array $orderMetaCache = [];

    public function isEnabled(): bool
    {
        return $this->storeSettings->get('renewal_reminders_enabled', 'no') === 'yes';
    }

    public function send($orderId, $stage, $cycleKey): bool
    {
        try {
            $order = Order::query()->with(['customer'])->find($orderId);
            if (!$order || !$order->customer) {
                return false;
            }

            $state = $this->normalizeReminderState($order->getMeta(static::META_KEY, []));

            if ($this->isStageAlreadySent($state, $cycleKey, $stage)) {
                return false;
            }

            $dueAt = $this->getDueTimestamp($order);
            if (!$dueAt) {
                $state = $this->clearStageQueue($state, $cycleKey, $stage);
                $order->updateMeta(static::META_KEY, $state);
                return false;
            }

            $currentCycle = $this->getCycleKey($order, $dueAt);
            if ($cycleKey !== $currentCycle || !$this->isEligible($order)) {
                $state = $this->clearStageQueue($state, $cycleKey, $stage);
                $order->updateMeta(static::META_KEY, $state);
                return false;
            }

            $eventName = $this->resolveEventName($stage);
            $data = [
                'order'    => $order,
                'customer' => $order->customer,
                'reminder' => [
                    'stage'        => $stage,
                    'order_id'     => (int)$order->id,
                    'order_ref'    => $this->getOrderReference($order),
                    'due_at'       => gmdate('Y-m-d H:i:s', $dueAt),
                    'due_amount'   => $this->getOutstandingAmount($order),
                    'payment_link' => PaymentHelper::getCustomPaymentLink($order->uuid),
                ]
            ];

            do_action('fluent_cart/' . $eventName, $data);

            if (strpos($stage, 'overdue_') === 0) {
                // Legacy hook shipped in 1.6.0 for scheduled overdue reminders.
                // Kept for third-party listeners; the flag tells the core mailer
                // the staged email above was already sent, so it must not mail
                // again — direct dispatches of the legacy hook lack the flag and
                // still deliver.
                $data['staged_email_dispatched'] = true;
                do_action_deprecated(
                    'fluent_cart/renewal_reminder_overdue',
                    [$data],
                    '1.6.3',
                    'fluent_cart/' . $eventName
                );
            }

            $state = $this->markStageSent($state, $cycleKey, $stage);
            $order->updateMeta(static::META_KEY, $state);

            return true;
        } catch (\Throwable $e) {
            fluent_cart_error_log(
                'Renewal reminder send error',
                sprintf('Order #%d, stage: %s — %s', $orderId, $stage, $e->getMessage())
            );
            return false;
        }
    }

    public function clearState(Order $order): void
    {
        $order->deleteMeta(static::META_KEY);
    }

    public function queueActions($startedAt, $maxRuntime): int
    {
        $queued = 0;
        $lastId = 0;
        $batchSize = $this->getScanBatchSize();
        $cutoffDate = $this->getScanCutoffDate();

        while (!$this->isRuntimeExpired($startedAt, $maxRuntime)) {
            $query = Order::query()
                ->where('id', '>', $lastId)
                ->where('type', Status::ORDER_TYPE_RENEWAL)
                ->whereIn('payment_status', static::getReminderPaymentStatuses())
                ->where('created_at', '>=', $cutoffDate)
                ->orderBy('id', 'ASC')
                ->limit($batchSize);

            $orders = $query->get();

            if ($orders->isEmpty()) {
                break;
            }

            $this->preloadMeta($orders->pluck('id')->toArray());

            foreach ($orders as $order) {
                $queued += $this->queueForOrder($order);

                if ($this->isRuntimeExpired($startedAt, $maxRuntime)) {
                    break;
                }
            }

            $lastId = $orders->last()->id;

            if ($orders->count() < $batchSize) {
                break;
            }
        }

        $this->orderMetaCache = [];

        return $queued;
    }

    /*
    |--------------------------------------------------------------------------
    | Queueing
    |--------------------------------------------------------------------------
    */

    protected function queueForOrder(Order $order): int
    {
        if (!$this->isEligible($order)) {
            return 0;
        }

        $dueAt = $this->getDueTimestamp($order);
        if (!$dueAt) {
            return 0;
        }

        $now = time();
        $cycleKey = $this->getCycleKey($order, $dueAt);
        $queued = 0;
        $overdueDays = $this->getOverdueDays();

        // Due reminder stage (`before_0`) fires once the renewal order is due (billing date reached).
        // Bounded before the first overdue stage window opens.
        // Skip if the renewal order was created ON the due date (e.g. trial subscriptions where advance
        // days = 0) — the renewal order creation email already notified the customer, sending a due-date
        // reminder seconds later would be redundant.
        $firstOverdueAfter = !empty($overdueDays) ? (int)min($overdueDays) : 1;
        $overdueWindowStart = $dueAt + ($firstOverdueAfter * DAY_IN_SECONDS);
        $renewalCreatedAt = $order->created_at ? (int)strtotime($order->created_at . ' UTC') : 0;
        if ($now >= $dueAt && $now < $overdueWindowStart && $renewalCreatedAt < $dueAt) {
            if ($this->queueStage($order, 'before_0', $cycleKey)) {
                $queued++;
            }
        }

        // $overdueDays is sorted descending (e.g. [7, 3, 1]).
        // Each stage fires only within its window:
        //   overdue_1: [dueAt+1d, dueAt+3d)
        //   overdue_3: [dueAt+3d, dueAt+7d)
        //   overdue_7: [dueAt+7d, dueAt+7d+2d grace)
        foreach ($overdueDays as $index => $daysAfter) {
            $target = $dueAt + ((int)$daysAfter * DAY_IN_SECONDS);

            if ($now < $target) {
                continue;
            }

            if ($index === 0) {
                // Last (largest) stage — 2-day grace for late cron runs, then hard stop
                $upperBound = $target + (2 * DAY_IN_SECONDS);
            } else {
                // Window closes when the next stage's window opens
                $upperBound = $dueAt + ((int)$overdueDays[$index - 1] * DAY_IN_SECONDS);
            }

            if ($now >= $upperBound) {
                continue;
            }

            $stage = 'overdue_' . (int)$daysAfter;
            if ($this->queueStage($order, $stage, $cycleKey)) {
                $queued++;
            }
        }

        return $queued;
    }

    protected function queueStage(Order $order, string $stage, string $cycleKey): bool
    {
        $state = $this->normalizeReminderState($this->getCachedMeta($order));

        if ($this->isStageAlreadySent($state, $cycleKey, $stage)) {
            return false;
        }

        if ($this->isStageQueuedRecently($state, $cycleKey, $stage)) {
            return false;
        }

        $args = [$order->id, $stage, $cycleKey];

        if (function_exists('as_next_scheduled_action')) {
            $existing = as_next_scheduled_action(static::ASYNC_HOOK, $args, 'fluent-cart');
            if ($existing) {
                $state = $this->markStageQueued($state, $cycleKey, $stage);
                $this->saveMeta($order, $state);
                return false;
            }
        }

        $state = $this->markStageQueued($state, $cycleKey, $stage);
        $this->saveMeta($order, $state);

        if (function_exists('as_enqueue_async_action')) {
            $result = as_enqueue_async_action(static::ASYNC_HOOK, $args, 'fluent-cart');
            if ($result === 0) {
                $state = $this->clearStageQueue($state, $cycleKey, $stage);
                $this->saveMeta($order, $state);
                return false;
            }

            return true;
        }

        return $this->send($order->id, $stage, $cycleKey);
    }

    /*
    |--------------------------------------------------------------------------
    | Meta Cache
    |--------------------------------------------------------------------------
    */

    protected function preloadMeta(array $orderIds): void
    {
        if (empty($orderIds)) {
            return;
        }

        $metas = OrderMeta::query()
            ->whereIn('order_id', $orderIds)
            ->where('meta_key', static::META_KEY)
            ->get();

        foreach ($metas as $meta) {
            $this->orderMetaCache[$meta->order_id] = $meta->meta_value;
        }

        foreach ($orderIds as $id) {
            if (!array_key_exists($id, $this->orderMetaCache)) {
                $this->orderMetaCache[$id] = [];
            }
        }
    }

    protected function getCachedMeta(Order $order): array
    {
        if (array_key_exists($order->id, $this->orderMetaCache)) {
            $value = $this->orderMetaCache[$order->id];
            return is_array($value) ? $value : [];
        }

        return $order->getMeta(static::META_KEY, []) ?: [];
    }

    protected function saveMeta(Order $order, array $state): void
    {
        $order->updateMeta(static::META_KEY, $state);
        $this->orderMetaCache[$order->id] = $state;
    }

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    */

    protected function getDueDays(): int
    {
        $days = (int)$this->storeSettings->get('renewal_reminder_due_days', 0);
        $days = max($days, 0);

        return (int)apply_filters('fluent_cart/reminders/renewal_due_days', $days);
    }

    protected function getOverdueDays(): array
    {
        $days = $this->parseDayList(
            $this->storeSettings->get('renewal_reminder_overdue_days', '1,3,7'),
            [1, 3, 7],
            1
        );

        return apply_filters('fluent_cart/reminders/renewal_overdue_days', $days);
    }

    /**
     * Canonical reminder-eligible payment statuses — shared by the hourly scan,
     * manual "send now", and the admin UI's button-visibility gate (see
     * ReminderService::sendManualRenewalReminder / canSendPaymentReminder).
     * authorized and partially_paid are excluded: both reflect payment already
     * in progress, so a "you owe money" reminder would be misleading.
     */
    public static function getReminderPaymentStatuses(): array
    {
        return [
            Status::PAYMENT_PENDING,
            Status::PAYMENT_FAILED,
            Status::PAYMENT_SCHEDULED,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Eligibility & Helpers
    |--------------------------------------------------------------------------
    */

    protected function isEligible(Order $order): bool
    {
        // Live invoice on a test-mode store (or vice versa): don't email — the
        // guard's promise covers reminders too. Checked at both scan and send
        // time so pre-queued actions copied to a clone are also caught.
        if (!SubscriptionHelper::canProcessInMode($order->mode)) {
            return false;
        }

        if (!in_array($order->payment_status, static::getReminderPaymentStatuses(), true)) {
            return false;
        }

        // Voided invoice — payment_status alone can't distinguish "voided" from a
        // genuine decline (both land on PAYMENT_FAILED), so gate on order status too.
        if ($order->status === Status::ORDER_CANCELED) {
            return false;
        }

        // System (auto-charge) renewal: no pay-now reminders while still retrying,
        // scheduled or pending — reminders resume once retries are exhausted.
        if ($order->parent_id) {
            $subscription = Subscription::query()->where('parent_order_id', $order->parent_id)->first();
            if ($subscription && $subscription->isSystem() && !SystemChargeService::isExhausted($subscription, $order)) {
                return false;
            }
        }

        return $this->getOutstandingAmount($order) > 0;
    }

    protected function getDueTimestamp(Order $order): int
    {
        $dueDate = $order->getMeta('due_date');

        if ($dueDate) {
            $ts = strtotime($dueDate . ' UTC');
            return ($ts && $ts > 0) ? (int)$ts : 0;
        }

        // Fallback for renewal orders without due_date meta (legacy)
        if (!$order->created_at) {
            return 0;
        }

        $createdAt = (string)$order->created_at;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $createdAt)) {
            return 0;
        }

        $base = strtotime($createdAt . ' UTC');
        if (!$base || $base <= 0) {
            return 0;
        }

        return $base + ($this->getDueDays() * DAY_IN_SECONDS);
    }

    protected function getScanCutoffDate(): string
    {
        $overdueDays = $this->getOverdueDays();
        $maxOverdue = !empty($overdueDays) ? max($overdueDays) : 0;
        $maxAgeDays = $this->getDueDays() + $maxOverdue + 7;
        $maxAgeDays = max($maxAgeDays, 30);

        return gmdate('Y-m-d H:i:s', time() - ($maxAgeDays * DAY_IN_SECONDS));
    }

    protected function getCycleKey(Order $order, int $dueAt): string
    {
        return md5(implode('|', [
            'order',
            $order->id,
            $dueAt,
            (int)$order->total_amount,
            (int)$order->total_paid,
        ]));
    }

    protected function getOutstandingAmount(Order $order): int
    {
        $due = (int)$order->total_amount - (int)$order->total_paid;
        return max($due, 0);
    }

    protected function getOrderReference(Order $order): string
    {
        if (!empty($order->invoice_no)) {
            return (string)$order->invoice_no;
        }

        return '#' . (string)$order->id;
    }

    protected function resolveEventName(string $stage): string
    {
        if (strpos($stage, 'overdue_') === 0) {
            return $this->resolveOverdueEventName((int)substr($stage, strlen('overdue_')));
        }

        return 'renewal_reminder_due';
    }

    /**
     * Map an overdue stage to a notification event by its position in the
     * configured day list, not by a fixed day threshold — the escalation tone
     * follows the admin's schedule (e.g. 14,30 → first at 14, final at 30).
     */
    protected function resolveOverdueEventName(int $daysAfter): string
    {
        $days = $this->getOverdueDays();

        if (empty($days) || $daysAfter <= min($days)) {
            return 'renewal_overdue_first';
        }

        if ($daysAfter >= max($days)) {
            return 'renewal_overdue_final';
        }

        return 'renewal_overdue_followup';
    }
}
