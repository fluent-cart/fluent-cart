<?php

namespace FluentCart\App\Services\Reminders;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Email\EmailNotifications;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;

class ReminderService
{
    const DEFAULT_SCAN_BATCH_SIZE = 100;
    const MIN_SCAN_BATCH_SIZE = 10;
    const MAX_SCAN_BATCH_SIZE = 500;

    protected StoreSettings $storeSettings;

    public function __construct()
    {
        $this->storeSettings = new StoreSettings();
    }

    public function runHourlyScan(): array
    {
        $stats = [
            'renewal_queued'   => 0,
            'trial_queued'     => 0,
            'processed_at_gmt' => gmdate('Y-m-d H:i:s')
        ];

        if (!$this->isRemindersEnabled()) {
            return $stats;
        }

        try {
            $startedAt = time();
            $maxRuntime = 20;

            $subscriptionService = new SubscriptionReminderService();

            if ($subscriptionService->isEnabled()) {
                $result = $subscriptionService->queueActions($startedAt, $maxRuntime);
                $stats['renewal_queued'] = $result['renewal'];
                $stats['trial_queued'] = $result['trial'];
            }
        } catch (\Throwable $e) {
            fluent_cart_error_log('Reminder hourly scan error', $e->getMessage());
        }

        return $stats;
    }

    protected function isRemindersEnabled(): bool
    {
        return $this->storeSettings->get('reminders_enabled', 'no') === 'yes';
    }

    /**
     * Get reminder permissions for a subscription (used by detail API).
     */
    public function getSubscriptionReminderPermissions(Subscription $subscription): array
    {
        $canSendRenewal = false;
        $canSendTrialEnd = false;

        if (!$this->isRemindersEnabled()) {
            return compact('canSendRenewal', 'canSendTrialEnd');
        }

        $status = $subscription->status;
        $isSimulatedTrial = $status === Status::SUBSCRIPTION_TRIALING
            && Arr::get($subscription->config, 'is_trial_days_simulated', 'no') === 'yes';

        // Active subscriptions and simulated trials are eligible for renewal reminders
        if (($status === Status::SUBSCRIPTION_ACTIVE || $isSimulatedTrial)
            && $subscription->next_billing_date
            && $this->isNotificationEnabled('subscription_renewal_reminder')
        ) {
            $canSendRenewal = true;
        }

        // Only real trials (not simulated) are eligible for trial end reminders
        if ($status === Status::SUBSCRIPTION_TRIALING
            && !$isSimulatedTrial
            && $subscription->next_billing_date
            && $this->isNotificationEnabled('subscription_trial_end_reminder')
        ) {
            $canSendTrialEnd = true;
        }

        return compact('canSendRenewal', 'canSendTrialEnd');
    }

    /**
     * Check if a payment reminder can be sent for an order (used by detail API).
     */
    public function canSendPaymentReminder(array $orderData): bool
    {
        $eligibleStatuses = [
            Status::PAYMENT_PENDING,
            Status::PAYMENT_PARTIALLY_PAID,
            Status::PAYMENT_FAILED
        ];

        if (!in_array(Arr::get($orderData, 'payment_status'), $eligibleStatuses, true)) {
            return false;
        }

        return max((int)Arr::get($orderData, 'total_amount', 0) - (int)Arr::get($orderData, 'total_paid', 0), 0) > 0;
    }

    /**
     * Check if at least one notification for the given event is active.
     */
    protected function isNotificationEnabled(string $event): bool
    {
        $notifications = EmailNotifications::getNotifications();
        foreach ($notifications as $notification) {
            if ($notification['event'] === $event && Arr::get($notification, 'settings.active') === 'yes') {
                return true;
            }
        }
        return false;
    }

    /**
     * Send a manual reminder for a specific order or subscription.
     *
     * @param string $event The reminder event hook (invoice_reminder_overdue, subscription_renewal_reminder, subscription_trial_end_reminder)
     * @param int $entityId The order ID or subscription ID
     * @return array{success: bool, message: string}
     */
    public function sendManualReminder(string $event, int $entityId): array
    {
        try {
            switch ($event) {
                case 'invoice_reminder_overdue':
                    return $this->sendManualInvoiceReminder($entityId);
                case 'subscription_renewal_reminder':
                    return $this->sendManualRenewalReminder($entityId);
                case 'subscription_trial_end_reminder':
                    return $this->sendManualTrialReminder($entityId);
                default:
                    return [
                        'success' => false,
                        'message' => __('Unknown reminder type', 'fluent-cart')
                    ];
            }
        } catch (\Throwable $e) {
            fluent_cart_error_log('Manual reminder send error', $e->getMessage());
            return [
                'success' => false,
                'message' => __('An unexpected error occurred while sending the reminder.', 'fluent-cart'),
            ];
        }
    }

    protected function sendManualInvoiceReminder(int $orderId): array
    {
        $order = Order::query()->with(['customer'])->find($orderId);

        if (!$order || !$order->customer) {
            return ['success' => false, 'message' => __('Order or customer not found', 'fluent-cart')];
        }

        $eligibleStatuses = [
            Status::PAYMENT_PENDING,
            Status::PAYMENT_PARTIALLY_PAID,
            Status::PAYMENT_FAILED,
            Status::PAYMENT_AUTHORIZED,
        ];

        if (!in_array($order->payment_status, $eligibleStatuses, true)) {
            return ['success' => false, 'message' => __('Order is not eligible for payment reminder', 'fluent-cart')];
        }

        $outstanding = max((int)$order->total_amount - (int)$order->total_paid, 0);
        if ($outstanding <= 0) {
            return ['success' => false, 'message' => __('No outstanding amount on this order', 'fluent-cart')];
        }

        $createdAt = (string)$order->created_at;
        $base = strtotime($createdAt . ' UTC');
        if (!$base || $base <= 0) {
            return ['success' => false, 'message' => __('Invalid order date', 'fluent-cart')];
        }

        $dueDays = (int)$this->storeSettings->get('invoice_reminder_due_days', 0);
        $dueAt = $base + (max($dueDays, 0) * DAY_IN_SECONDS);

        $orderRef = !empty($order->invoice_no) ? (string)$order->invoice_no : '#' . (string)$order->id;

        $data = [
            'order'    => $order,
            'customer' => $order->customer,
            'reminder' => [
                'stage'        => 'manual',
                'order_id'     => (int)$order->id,
                'order_ref'    => $orderRef,
                'due_at'       => gmdate('Y-m-d H:i:s', $dueAt),
                'due_amount'   => $outstanding,
                'payment_link' => PaymentHelper::getCustomPaymentLink($order->uuid),
            ]
        ];

        do_action('fluent_cart/invoice_reminder_overdue', $data);

        $state = $this->normalizeReminderState($order->getMeta(InvoiceReminderService::META_KEY, []));
        $cycleKey = md5(implode('|', ['order', $order->id, $dueAt, (int)$order->total_amount, (int)$order->total_paid]));
        $cycleState = $this->getCycleState($state, $cycleKey);

        foreach (array_keys($cycleState['queue']) as $stage) {
            $cycleState['sent'][$stage] = gmdate('Y-m-d H:i:s');
        }
        $cycleState['queue'] = [];
        $cycleState['sent']['manual_' . time()] = gmdate('Y-m-d H:i:s');

        $state = $this->setCycleState($state, $cycleKey, $cycleState);
        $state['updated_at'] = gmdate('Y-m-d H:i:s');
        $order->updateMeta(InvoiceReminderService::META_KEY, $state);

        return ['success' => true, 'message' => __('Payment reminder sent successfully', 'fluent-cart')];
    }

    protected function sendManualRenewalReminder(int $subscriptionId): array
    {
        $subscription = Subscription::query()->with(['customer', 'order'])->find($subscriptionId);

        if (!$subscription || !$subscription->customer) {
            return ['success' => false, 'message' => __('Subscription or customer not found', 'fluent-cart')];
        }

        $isSimulatedTrial = $subscription->status === Status::SUBSCRIPTION_TRIALING
            && Arr::get($subscription->config, 'is_trial_days_simulated', 'no') === 'yes';

        if ($subscription->status !== Status::SUBSCRIPTION_ACTIVE && !$isSimulatedTrial) {
            return ['success' => false, 'message' => __('Subscription is not active', 'fluent-cart')];
        }

        if (!$subscription->next_billing_date) {
            return ['success' => false, 'message' => __('No next billing date set', 'fluent-cart')];
        }

        $billingAt = strtotime($subscription->next_billing_date . ' UTC');
        if (!$billingAt || $billingAt <= 0) {
            return ['success' => false, 'message' => __('Invalid billing date', 'fluent-cart')];
        }

        $intervalMap = [
            'daily' => 'daily', 'weekly' => 'weekly', 'monthly' => 'monthly',
            'quarterly' => 'quarterly', 'half_yearly' => 'half_yearly', 'yearly' => 'yearly',
        ];
        $billingCycle = $intervalMap[strtolower($subscription->billing_interval ?? '')] ?? 'unsupported';

        do_action('fluent_cart/subscription_renewal_reminder', [
            'subscription' => $subscription,
            'order'        => $subscription->order,
            'customer'     => $subscription->customer,
            'reminder'     => [
                'stage'         => 'manual',
                'billing_cycle' => $billingCycle,
                'billing_date'  => gmdate('Y-m-d H:i:s', $billingAt),
            ]
        ]);

        $state = $this->normalizeReminderState($subscription->getMeta(SubscriptionReminderService::RENEWAL_META_KEY, []));
        $cycleKey = md5(implode('|', ['subscription', $subscription->id, $billingAt, (string)$subscription->status, (int)$subscription->recurring_total]));
        $cycleState = $this->getCycleState($state, $cycleKey);

        foreach (array_keys($cycleState['queue']) as $stage) {
            $cycleState['sent'][$stage] = gmdate('Y-m-d H:i:s');
        }
        $cycleState['queue'] = [];
        $cycleState['sent']['manual_' . time()] = gmdate('Y-m-d H:i:s');

        $state = $this->setCycleState($state, $cycleKey, $cycleState);
        $state['updated_at'] = gmdate('Y-m-d H:i:s');
        $subscription->updateMeta(SubscriptionReminderService::RENEWAL_META_KEY, $state);

        return ['success' => true, 'message' => __('Renewal reminder sent successfully', 'fluent-cart')];
    }

    protected function sendManualTrialReminder(int $subscriptionId): array
    {
        $subscription = Subscription::query()->with(['customer', 'order'])->find($subscriptionId);

        if (!$subscription || !$subscription->customer) {
            return ['success' => false, 'message' => __('Subscription or customer not found', 'fluent-cart')];
        }

        $isRealTrial = $subscription->status === Status::SUBSCRIPTION_TRIALING
            && Arr::get($subscription->config, 'is_trial_days_simulated', 'no') !== 'yes';

        if (!$isRealTrial) {
            return ['success' => false, 'message' => __('Subscription is not in a trial period', 'fluent-cart')];
        }

        if (!$subscription->next_billing_date) {
            return ['success' => false, 'message' => __('No trial end date set', 'fluent-cart')];
        }

        $trialEndAt = strtotime($subscription->next_billing_date . ' UTC');
        if (!$trialEndAt || $trialEndAt <= 0) {
            return ['success' => false, 'message' => __('Invalid trial end date', 'fluent-cart')];
        }

        do_action('fluent_cart/subscription_trial_end_reminder', [
            'subscription' => $subscription,
            'order'        => $subscription->order,
            'customer'     => $subscription->customer,
            'reminder'     => [
                'stage'          => 'manual',
                'trial_end_date' => gmdate('Y-m-d H:i:s', $trialEndAt),
            ]
        ]);

        $state = $this->normalizeReminderState($subscription->getMeta(SubscriptionReminderService::TRIAL_META_KEY, []));
        $cycleKey = md5(implode('|', ['subscription', $subscription->id, $trialEndAt, (string)$subscription->status, (int)$subscription->recurring_total]));
        $cycleState = $this->getCycleState($state, $cycleKey);

        foreach (array_keys($cycleState['queue']) as $stage) {
            $cycleState['sent'][$stage] = gmdate('Y-m-d H:i:s');
        }
        $cycleState['queue'] = [];
        $cycleState['sent']['manual_' . time()] = gmdate('Y-m-d H:i:s');

        $state = $this->setCycleState($state, $cycleKey, $cycleState);
        $state['updated_at'] = gmdate('Y-m-d H:i:s');
        $subscription->updateMeta(SubscriptionReminderService::TRIAL_META_KEY, $state);

        return ['success' => true, 'message' => __('Trial ending reminder sent successfully', 'fluent-cart')];
    }

    /*
    |--------------------------------------------------------------------------
    | Shared Utilities
    |--------------------------------------------------------------------------
    */

    protected function getScanBatchSize(): int
    {
        $size = (int)apply_filters('fluent_cart/reminders/scan_batch_size', static::DEFAULT_SCAN_BATCH_SIZE);

        if ($size < static::MIN_SCAN_BATCH_SIZE) {
            return static::MIN_SCAN_BATCH_SIZE;
        }

        return min($size, static::MAX_SCAN_BATCH_SIZE);
    }

    protected function isRuntimeExpired(int $startedAt, int $maxRuntime): bool
    {
        return (time() - $startedAt) >= $maxRuntime;
    }

    protected function parseDayList($values, array $defaults, int $minimum, int $maximum = 365): array
    {
        if (is_numeric($values) && !is_array($values)) {
            $values = [(int)$values];
        } elseif (is_string($values)) {
            $values = explode(',', $values);
        } elseif (!is_array($values)) {
            $values = $defaults;
        }

        $days = [];
        foreach ($values as $value) {
            $day = (int)trim((string)$value);
            if ($day < $minimum || $day > $maximum) {
                continue;
            }
            $days[$day] = $day;
        }

        if (empty($days)) {
            $days = array_combine($defaults, $defaults);
        }

        rsort($days, SORT_NUMERIC);

        return array_values($days);
    }

    /*
    |--------------------------------------------------------------------------
    | Reminder State Management
    |--------------------------------------------------------------------------
    */

    protected function normalizeReminderState($state): array
    {
        if (!is_array($state)) {
            $state = [];
        }

        $cycles = Arr::get($state, 'cycles', []);
        if (!is_array($cycles)) {
            $cycles = [];
        }

        $state['cycles'] = $cycles;

        return $state;
    }

    protected function isStageAlreadySent(array $state, string $cycleKey, string $stage): bool
    {
        return !empty(Arr::get($state, "cycles.$cycleKey.sent.$stage"));
    }

    protected function isStageQueuedRecently(array $state, string $cycleKey, string $stage): bool
    {
        $queuedAt = (int)Arr::get($state, "cycles.$cycleKey.queue.$stage", 0);
        if (!$queuedAt) {
            return false;
        }

        return ($queuedAt + (6 * HOUR_IN_SECONDS)) > time();
    }

    protected function markStageQueued(array $state, string $cycleKey, string $stage): array
    {
        $cycleState = $this->getCycleState($state, $cycleKey);
        $cycleState['queue'][$stage] = time();
        $state = $this->setCycleState($state, $cycleKey, $cycleState);
        $state['updated_at'] = gmdate('Y-m-d H:i:s');

        return $state;
    }

    protected function markStageSent(array $state, string $cycleKey, string $stage): array
    {
        $cycleState = $this->getCycleState($state, $cycleKey);
        $cycleState['sent'][$stage] = gmdate('Y-m-d H:i:s');

        if (isset($cycleState['queue'][$stage])) {
            unset($cycleState['queue'][$stage]);
        }

        $state = $this->setCycleState($state, $cycleKey, $cycleState);
        $state['updated_at'] = gmdate('Y-m-d H:i:s');

        return $state;
    }

    protected function clearStageQueue(array $state, string $cycleKey, string $stage): array
    {
        $cycleState = $this->getCycleState($state, $cycleKey);
        if (isset($cycleState['queue'][$stage])) {
            unset($cycleState['queue'][$stage]);
        }

        $state = $this->setCycleState($state, $cycleKey, $cycleState);
        $state['updated_at'] = gmdate('Y-m-d H:i:s');

        return $state;
    }

    protected function getCycleState(array $state, string $cycleKey): array
    {
        $cycleState = Arr::get($state, "cycles.$cycleKey", []);
        if (!is_array($cycleState)) {
            $cycleState = [];
        }

        $cycleState['sent'] = Arr::get($cycleState, 'sent', []);
        $cycleState['queue'] = Arr::get($cycleState, 'queue', []);

        if (!is_array($cycleState['sent'])) {
            $cycleState['sent'] = [];
        }

        if (!is_array($cycleState['queue'])) {
            $cycleState['queue'] = [];
        }

        return $cycleState;
    }

    protected function setCycleState(array $state, string $cycleKey, array $cycleState): array
    {
        $cycles = Arr::get($state, 'cycles', []);
        if (!is_array($cycles)) {
            $cycles = [];
        }

        if (isset($cycles[$cycleKey])) {
            unset($cycles[$cycleKey]);
        }

        $cycles[$cycleKey] = $cycleState;

        if (count($cycles) > 5) {
            $cycles = array_slice($cycles, -5, null, true);
        }

        $state['cycles'] = $cycles;

        return $state;
    }
}
