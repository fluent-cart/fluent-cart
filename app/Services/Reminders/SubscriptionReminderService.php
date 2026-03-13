<?php

namespace FluentCart\App\Services\Reminders;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\Framework\Support\Arr;

class SubscriptionReminderService extends ReminderService
{
    const RENEWAL_META_KEY = 'renewal_reminder_state';
    const TRIAL_META_KEY = 'trial_reminder_state';
    const RENEWAL_ASYNC_HOOK = 'fluent_cart/reminders/send_subscription_renewal';
    const TRIAL_ASYNC_HOOK = 'fluent_cart/reminders/send_trial_end';

    protected array $subscriptionMetaCache = [];

    protected array $trialMetaCache = [];

    public function isEnabled(): bool
    {
        // Check if any billing cycle reminders are enabled
        $hasRenewalReminders =
            $this->storeSettings->get('yearly_renewal_reminders_enabled', 'yes') === 'yes' ||
            $this->storeSettings->get('monthly_renewal_reminders_enabled', 'no') === 'yes' ||
            $this->storeSettings->get('quarterly_renewal_reminders_enabled', 'no') === 'yes' ||
            $this->storeSettings->get('half_yearly_renewal_reminders_enabled', 'no') === 'yes';

        // Check if trial end reminders are enabled
        $hasTrialReminders = $this->storeSettings->get('trial_end_reminders_enabled', 'yes') === 'yes';

        return $hasRenewalReminders || $hasTrialReminders;
    }

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    */

    public function sendRenewal($subscriptionId, $stage, $cycleKey): bool
    {
        try {
            $subscription = Subscription::query()
                ->with(['customer', 'order'])
                ->find($subscriptionId);

            if (!$subscription || !$subscription->customer) {
                return false;
            }

            $state = $this->normalizeReminderState($subscription->getMeta(static::RENEWAL_META_KEY, []));
            if ($this->isStageAlreadySent($state, $cycleKey, $stage)) {
                return false;
            }

            if (!$subscription->next_billing_date) {
                return false;
            }

            $billingAt = $this->getBillingTimestamp($subscription);
            if (!$billingAt) {
                $state = $this->clearStageQueue($state, $cycleKey, $stage);
                $subscription->updateMeta(static::RENEWAL_META_KEY, $state);
                return false;
            }

            $currentCycle = $this->getCycleKey($subscription, $billingAt);
            if ($cycleKey !== $currentCycle || !$this->isEligible($subscription)) {
                $state = $this->clearStageQueue($state, $cycleKey, $stage);
                $subscription->updateMeta(static::RENEWAL_META_KEY, $state);
                return false;
            }

            do_action('fluent_cart/subscription_renewal_reminder', [
                'subscription' => $subscription,
                'order'        => $subscription->order,
                'customer'     => $subscription->customer,
                'reminder'     => [
                    'stage'        => $stage,
                    'billing_cycle'=> $this->getBillingCycle($subscription),
                    'billing_date' => gmdate('Y-m-d H:i:s', $billingAt),
                ]
            ]);

            $state = $this->markStageSent($state, $cycleKey, $stage);
            $subscription->updateMeta(static::RENEWAL_META_KEY, $state);

            return true;
        } catch (\Throwable $e) {
            fluent_cart_error_log(
                'Renewal reminder send error',
                sprintf('Subscription #%d, stage: %s — %s', $subscriptionId, $stage, $e->getMessage())
            );
            return false;
        }
    }

    public function sendTrial($subscriptionId, $stage, $cycleKey): bool
    {
        try {
            $subscription = Subscription::query()
                ->with(['customer', 'order'])
                ->find($subscriptionId);

            if (!$subscription || !$subscription->customer) {
                return false;
            }

            if (!$this->isTrialSubscription($subscription) || !$subscription->next_billing_date) {
                return false;
            }

            $state = $this->normalizeReminderState($subscription->getMeta(static::TRIAL_META_KEY, []));
            if ($this->isStageAlreadySent($state, $cycleKey, $stage)) {
                return false;
            }

            return $this->sendTrialEndReminder($subscription, $stage, $cycleKey, $state);
        } catch (\Throwable $e) {
            fluent_cart_error_log(
                'Trial reminder send error',
                sprintf('Subscription #%d, stage: %s — %s', $subscriptionId, $stage, $e->getMessage())
            );
            return false;
        }
    }

    protected function sendTrialEndReminder(Subscription $subscription, string $stage, string $cycleKey, array $state): bool
    {
        if (empty($subscription->next_billing_date)) {
            return false;
        }

        $trialEndAt = strtotime($subscription->next_billing_date . ' UTC');

        if (!$trialEndAt) {
            $state = $this->clearStageQueue($state, $cycleKey, $stage);
            $subscription->updateMeta(static::TRIAL_META_KEY, $state);
            return false;
        }

        $currentCycle = $this->getCycleKey($subscription, $trialEndAt);
        if ($cycleKey !== $currentCycle) {
            $state = $this->clearStageQueue($state, $cycleKey, $stage);
            $subscription->updateMeta(static::TRIAL_META_KEY, $state);
            return false;
        }

        do_action('fluent_cart/subscription_trial_end_reminder', [
            'subscription' => $subscription,
            'order'        => $subscription->order,
            'customer'     => $subscription->customer,
            'reminder'     => [
                'stage'          => $stage,
                'trial_end_date' => gmdate('Y-m-d H:i:s', $trialEndAt),
            ]
        ]);

        $state = $this->markStageSent($state, $cycleKey, $stage);
        $subscription->updateMeta(static::TRIAL_META_KEY, $state);

        return true;
    }

    public function clearState(Subscription $subscription): void
    {
        $subscription->deleteMeta(static::RENEWAL_META_KEY);
        $subscription->deleteMeta(static::TRIAL_META_KEY);
    }

    /*
    |--------------------------------------------------------------------------
    | Scanning & Queueing
    |--------------------------------------------------------------------------
    */

    public function queueActions($startedAt, $maxRuntime): array
    {
        $renewalQueued = 0;
        $trialQueued = 0;
        $lastId = 0;
        $batchSize = $this->getScanBatchSize();

        while (!$this->isRuntimeExpired($startedAt, $maxRuntime)) {
            $subscriptions = Subscription::query()
                ->where('id', '>', $lastId)
                ->whereNotNull('next_billing_date')
                ->whereIn('status', $this->getReminderStatuses())
                ->orderBy('id', 'ASC')
                ->limit($batchSize)
                ->get();

            if ($subscriptions->isEmpty()) {
                break;
            }

            $ids = $subscriptions->pluck('id')->toArray();
            $this->preloadRenewalMeta($ids);
            $this->preloadTrialMeta($ids);

            foreach ($subscriptions as $subscription) {
                $count = $this->queueForSubscription($subscription);
                if ($count && $this->isTrialSubscription($subscription)) {
                    $trialQueued += $count;
                } else {
                    $renewalQueued += $count;
                }

                if ($this->isRuntimeExpired($startedAt, $maxRuntime)) {
                    break;
                }
            }

            $lastId = $subscriptions->last()->id;

            if ($subscriptions->count() < $batchSize) {
                break;
            }
        }

        $this->subscriptionMetaCache = [];
        $this->trialMetaCache = [];

        return ['renewal' => $renewalQueued, 'trial' => $trialQueued];
    }

    protected function queueForSubscription(Subscription $subscription): int
    {
        if (!$this->isEligible($subscription)) {
            return 0;
        }

        $billingAt = $this->getBillingTimestamp($subscription);
        if (!$billingAt) {
            return 0;
        }

        if ($this->isTrialSubscription($subscription)) {
            if ($this->isTrialEndRemindersEnabled()) {
                return $this->queueTrialEndReminder($subscription);
            }
            return 0;
        }

        $billingCycle = $this->getBillingCycle($subscription);

        if (!$this->isBillingCycleEnabled($billingCycle)) {
            return 0;
        }

        $now = time();
        $cycleKey = $this->getCycleKey($subscription, $billingAt);
        $queued = 0;

        $reminderDays = $this->getRenewalDays($subscription);

        foreach ($reminderDays as $daysBefore) {
            $target = $billingAt - ((int)$daysBefore * DAY_IN_SECONDS);
            if ($now >= $target && $now < $billingAt) {
                $stage = 'before_' . (int)$daysBefore;
                if ($this->queueRenewalStage($subscription, $stage, $cycleKey)) {
                    $queued++;
                }
            }
        }

        return $queued;
    }

    protected function queueTrialEndReminder(Subscription $subscription): int
    {
        if (empty($subscription->next_billing_date)) {
            return 0;
        }

        $trialEndAt = strtotime($subscription->next_billing_date . ' UTC');

        if (!$trialEndAt || $trialEndAt <= time()) {
            return 0;
        }

        $now = time();
        $cycleKey = $this->getCycleKey($subscription, $trialEndAt);
        $queued = 0;

        $trialReminderDays = $this->getTrialEndReminderDays();

        foreach ($trialReminderDays as $daysBefore) {
            $target = $trialEndAt - ((int)$daysBefore * DAY_IN_SECONDS);
            if ($now >= $target && $now < $trialEndAt) {
                $stage = 'trial_end_' . (int)$daysBefore;
                if ($this->queueTrialStage($subscription, $stage, $cycleKey)) {
                    $queued++;
                }
            }
        }

        return $queued;
    }

    protected function queueRenewalStage(Subscription $subscription, string $stage, string $cycleKey): bool
    {
        $state = $this->normalizeReminderState($this->getCachedRenewalMeta($subscription));

        if ($this->isStageAlreadySent($state, $cycleKey, $stage)) {
            return false;
        }

        if ($this->isStageQueuedRecently($state, $cycleKey, $stage)) {
            return false;
        }

        $args = [$subscription->id, $stage, $cycleKey];

        if (function_exists('as_next_scheduled_action')) {
            $existing = as_next_scheduled_action(static::RENEWAL_ASYNC_HOOK, $args, 'fluent-cart');
            if ($existing) {
                $state = $this->markStageQueued($state, $cycleKey, $stage);
                $this->saveRenewalMeta($subscription, $state);
                return false;
            }
        }

        $state = $this->markStageQueued($state, $cycleKey, $stage);
        $this->saveRenewalMeta($subscription, $state);

        if (function_exists('as_enqueue_async_action')) {
            $result = as_enqueue_async_action(static::RENEWAL_ASYNC_HOOK, $args, 'fluent-cart');
            if ($result === 0) {
                $state = $this->clearStageQueue($state, $cycleKey, $stage);
                $this->saveRenewalMeta($subscription, $state);
                return false;
            }
            return true;
        }

        return $this->sendRenewal($subscription->id, $stage, $cycleKey);
    }

    protected function queueTrialStage(Subscription $subscription, string $stage, string $cycleKey): bool
    {
        $state = $this->normalizeReminderState($this->getCachedTrialMeta($subscription));

        if ($this->isStageAlreadySent($state, $cycleKey, $stage)) {
            return false;
        }

        if ($this->isStageQueuedRecently($state, $cycleKey, $stage)) {
            return false;
        }

        $args = [$subscription->id, $stage, $cycleKey];

        if (function_exists('as_next_scheduled_action')) {
            $existing = as_next_scheduled_action(static::TRIAL_ASYNC_HOOK, $args, 'fluent-cart');
            if ($existing) {
                $state = $this->markStageQueued($state, $cycleKey, $stage);
                $this->saveTrialMeta($subscription, $state);
                return false;
            }
        }

        $state = $this->markStageQueued($state, $cycleKey, $stage);
        $this->saveTrialMeta($subscription, $state);

        if (function_exists('as_enqueue_async_action')) {
            $result = as_enqueue_async_action(static::TRIAL_ASYNC_HOOK, $args, 'fluent-cart');
            if ($result === 0) {
                $state = $this->clearStageQueue($state, $cycleKey, $stage);
                $this->saveTrialMeta($subscription, $state);
                return false;
            }
            return true;
        }

        return $this->sendTrial($subscription->id, $stage, $cycleKey);
    }

    /*
    |--------------------------------------------------------------------------
    | Meta Cache
    |--------------------------------------------------------------------------
    */

    protected function preloadRenewalMeta(array $subscriptionIds): void
    {
        if (empty($subscriptionIds)) {
            return;
        }

        $metas = SubscriptionMeta::query()
            ->whereIn('subscription_id', $subscriptionIds)
            ->where('meta_key', static::RENEWAL_META_KEY)
            ->get();

        foreach ($metas as $meta) {
            $this->subscriptionMetaCache[$meta->subscription_id] = $meta->meta_value;
        }

        foreach ($subscriptionIds as $id) {
            if (!array_key_exists($id, $this->subscriptionMetaCache)) {
                $this->subscriptionMetaCache[$id] = [];
            }
        }
    }

    protected function preloadTrialMeta(array $subscriptionIds): void
    {
        if (empty($subscriptionIds)) {
            return;
        }

        $metas = SubscriptionMeta::query()
            ->whereIn('subscription_id', $subscriptionIds)
            ->where('meta_key', static::TRIAL_META_KEY)
            ->get();

        foreach ($metas as $meta) {
            $this->trialMetaCache[$meta->subscription_id] = $meta->meta_value;
        }

        foreach ($subscriptionIds as $id) {
            if (!array_key_exists($id, $this->trialMetaCache)) {
                $this->trialMetaCache[$id] = [];
            }
        }
    }

    protected function getCachedRenewalMeta(Subscription $subscription): array
    {
        if (array_key_exists($subscription->id, $this->subscriptionMetaCache)) {
            $value = $this->subscriptionMetaCache[$subscription->id];
            return is_array($value) ? $value : [];
        }

        return $subscription->getMeta(static::RENEWAL_META_KEY, []) ?: [];
    }

    protected function getCachedTrialMeta(Subscription $subscription): array
    {
        if (array_key_exists($subscription->id, $this->trialMetaCache)) {
            $value = $this->trialMetaCache[$subscription->id];
            return is_array($value) ? $value : [];
        }

        return $subscription->getMeta(static::TRIAL_META_KEY, []) ?: [];
    }

    protected function saveRenewalMeta(Subscription $subscription, array $state): void
    {
        $subscription->updateMeta(static::RENEWAL_META_KEY, $state);
        $this->subscriptionMetaCache[$subscription->id] = $state;
    }

    protected function saveTrialMeta(Subscription $subscription, array $state): void
    {
        $subscription->updateMeta(static::TRIAL_META_KEY, $state);
        $this->trialMetaCache[$subscription->id] = $state;
    }

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    */

    protected function getRenewalDays(Subscription $subscription): array
    {
        $billingCycle = $this->getBillingCycle($subscription);

        switch ($billingCycle) {
            case 'yearly':
                return $this->getYearlyRenewalDays();
            case 'monthly':
                return $this->getMonthlyRenewalDays();
            case 'quarterly':
                return $this->getQuarterlyRenewalDays();
            case 'half_yearly':
                return $this->getHalfYearlyRenewalDays();
            default:
                return [];
        }
    }

    protected function getBillingCycle(Subscription $subscription): string
    {
        $interval = $subscription->billing_interval ?? '';

        $intervalMap = [
            'daily'       => 'daily',
            'weekly'      => 'weekly',
            'monthly'     => 'monthly',
            'quarterly'   => 'quarterly',
            'half_yearly' => 'half_yearly',
            'yearly'      => 'yearly',
        ];

        $cycle = $intervalMap[strtolower($interval)] ?? 'unsupported';

        return apply_filters('fluent_cart/reminders/billing_cycle', $cycle, $subscription);
    }

    protected function isBillingCycleEnabled(string $cycle): bool
    {
        switch ($cycle) {
            case 'daily':
            case 'weekly':
            case 'unsupported':
                return false;
            case 'monthly':
                return $this->storeSettings->get('monthly_renewal_reminders_enabled', 'no') === 'yes';
            case 'quarterly':
                return $this->storeSettings->get('quarterly_renewal_reminders_enabled', 'no') === 'yes';
            case 'half_yearly':
                return $this->storeSettings->get('half_yearly_renewal_reminders_enabled', 'no') === 'yes';
            case 'yearly':
                return $this->storeSettings->get('yearly_renewal_reminders_enabled', 'yes') === 'yes';
            default:
                return false;
        }
    }

    protected function getYearlyRenewalDays(): array
    {
        $days = $this->parseDayList(
            $this->storeSettings->get('yearly_renewal_reminder_days', '30'),
            [30],
            7,
            90
        );

        return apply_filters('fluent_cart/reminders/yearly_before_days', $days);
    }

    protected function getMonthlyRenewalDays(): array
    {
        $days = $this->parseDayList(
            $this->storeSettings->get('monthly_renewal_reminder_days', '7'),
            [7],
            3,
            28
        );

        return apply_filters('fluent_cart/reminders/monthly_before_days', $days);
    }

    protected function getQuarterlyRenewalDays(): array
    {
        $days = $this->parseDayList(
            $this->storeSettings->get('quarterly_renewal_reminder_days', '14'),
            [14],
            7,
            60
        );

        return apply_filters('fluent_cart/reminders/quarterly_before_days', $days);
    }

    protected function getHalfYearlyRenewalDays(): array
    {
        $days = $this->parseDayList(
            $this->storeSettings->get('half_yearly_renewal_reminder_days', '21'),
            [21],
            7,
            60
        );

        return apply_filters('fluent_cart/reminders/half_yearly_before_days', $days);
    }

    protected function getTrialEndReminderDays(): array
    {
        $days = $this->parseDayList(
            $this->storeSettings->get('trial_end_reminder_days', '3'),
            [3],
            1,
            14
        );

        return apply_filters('fluent_cart/reminders/trial_end_days', $days);
    }

    protected function isTrialEndRemindersEnabled(): bool
    {
        return $this->storeSettings->get('trial_end_reminders_enabled', 'yes') === 'yes';
    }

    protected function getReminderStatuses(): array
    {
        return [
            Status::SUBSCRIPTION_ACTIVE,
            Status::SUBSCRIPTION_TRIALING,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Eligibility & Helpers
    |--------------------------------------------------------------------------
    */

    protected function isEligible(Subscription $subscription): bool
    {
        if (!in_array($subscription->status, $this->getReminderStatuses(), true)) {
            return false;
        }

        return !empty($subscription->next_billing_date);
    }

    protected function isTrialSubscription(Subscription $subscription): bool
    {
        return $subscription->status === Status::SUBSCRIPTION_TRIALING
            && Arr::get($subscription->config, 'is_trial_days_simulated', 'no') !== 'yes';
    }

    protected function getBillingTimestamp(Subscription $subscription): int
    {
        if (!$subscription->next_billing_date) {
            return 0;
        }

        $billingDate = (string)$subscription->next_billing_date;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $billingDate)) {
            return 0;
        }

        $timestamp = strtotime($billingDate . ' UTC');

        return ($timestamp && $timestamp > 0) ? (int)$timestamp : 0;
    }

    protected function getCycleKey(Subscription $subscription, int $billingAt): string
    {
        return md5(implode('|', [
            'subscription',
            $subscription->id,
            $billingAt,
            (string)$subscription->status,
            (int)$subscription->recurring_total,
        ]));
    }
}
