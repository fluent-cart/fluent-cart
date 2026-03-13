<?php

namespace FluentCart\App\Services\Reminders;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Services\Payments\PaymentHelper;

class InvoiceReminderService extends ReminderService
{
    const META_KEY = 'invoice_reminder_state';
    const ASYNC_HOOK = 'fluent_cart/reminders/send_invoice';

    protected array $orderMetaCache = [];

    public function isEnabled(): bool
    {
        return $this->storeSettings->get('invoice_reminders_enabled', 'no') === 'yes';
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

            $state = $this->markStageSent($state, $cycleKey, $stage);
            $order->updateMeta(static::META_KEY, $state);

            return true;
        } catch (\Throwable $e) {
            fluent_cart_error_log(
                'Invoice reminder send error',
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
                ->whereIn('payment_status', $this->getReminderPaymentStatuses())
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

        // @todo uncomment when invoice feature is deployed
        // Due reminder stage (`before_0`) should fire once the invoice is due.
        // Keep this bounded before the first overdue stage starts.
        // $firstOverdueAfter = !empty($overdueDays) ? (int)min($overdueDays) : 1;
        // $overdueWindowStart = $dueAt + ($firstOverdueAfter * DAY_IN_SECONDS);
        // if ($now >= $dueAt && $now < $overdueWindowStart) {
        //     if ($this->queueStage($order, 'before_0', $cycleKey)) {
        //         $queued++;
        //     }
        // }

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
        $days = (int)$this->storeSettings->get('invoice_reminder_due_days', 0);
        $days = max($days, 0);

        return (int)apply_filters('fluent_cart/reminders/invoice_due_days', $days);
    }

    protected function getOverdueDays(): array
    {
        $days = $this->parseDayList(
            $this->storeSettings->get('invoice_reminder_overdue_days', '1,3,7'),
            [1, 3, 7],
            1
        );

        return apply_filters('fluent_cart/reminders/invoice_overdue_days', $days);
    }

    protected function getReminderPaymentStatuses(): array
    {
        return [
            Status::PAYMENT_PENDING,
            Status::PAYMENT_PARTIALLY_PAID,
            Status::PAYMENT_FAILED,
            Status::PAYMENT_AUTHORIZED,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Eligibility & Helpers
    |--------------------------------------------------------------------------
    */

    protected function isEligible(Order $order): bool
    {
        if (!in_array($order->payment_status, $this->getReminderPaymentStatuses(), true)) {
            return false;
        }

        return $this->getOutstandingAmount($order) > 0;
    }

    protected function getDueTimestamp(Order $order): int
    {
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
            return 'invoice_reminder_overdue';
        }

        return 'invoice_reminder_due';
    }
}
