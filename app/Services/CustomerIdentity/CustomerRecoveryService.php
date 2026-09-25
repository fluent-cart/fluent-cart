<?php

namespace FluentCart\App\Services\CustomerIdentity;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\User;
use FluentCart\Framework\Database\Orm\Builder;

/** Resumes large, verified recoveries without retaining frontend transactions. */
class CustomerRecoveryService
{
    const META_KEY = '_fct_customer_recovery';
    const HOOK = 'fluent_cart/customer/recover_verified_history';
    const FOREGROUND_SOURCES = 5;
    const MAX_ATTEMPTS = 3;

    public static function start(int $userId, Customer $target, string $email): void
    {
        if (!CustomerMerger::supportsTransactions()) {
            throw new \RuntimeException('Customer recovery requires transactional storage.');
        }

        $state = [
            'id' => wp_generate_uuid4(),
            'customer_id' => (int) $target->id,
            'email' => $email,
            'cursor' => 0,
            'upper_id' => (int) static::sources($email, (int) $target->id)->max('id'),
            'processed' => 0,
            'attempts' => 0,
            'incomplete' => false,
            'status' => 'pending',
        ];
        update_user_meta($userId, static::META_KEY, $state);
        if (!wp_schedule_single_event(time() + 1, static::HOOK, [['user_id' => $userId, 'id' => $state['id']]])) {
            throw new \RuntimeException('Unable to schedule customer recovery.');
        }
    }

    public static function progress(int $userId): array
    {
        $state = get_user_meta($userId, static::META_KEY, true);
        return is_array($state) ? $state : [];
    }

    protected static function sources(string $email, int $targetId): Builder
    {
        return Customer::query()->where('email', $email)->where('id', '!=', $targetId)->unclaimed();
    }

    /** One customer and at most BATCH_SIZE rows per resource table per invocation. */
    public static function run(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $jobId = (string) ($payload['id'] ?? '');
        wp_cache_delete($userId, 'user_meta');
        $state = static::progress($userId);
        if (($state['id'] ?? '') !== $jobId || ($state['status'] ?? '') !== 'pending') {
            return;
        }

        if (!CustomerMerger::supportsTransactions()) {
            // One compare-and-set write; no rollback is assumed on this path.
            // Do not replace a newer claim or advance any transfer progress.
            $blocked = array_merge($state, ['status' => 'blocked_storage']);
            update_user_meta($userId, static::META_KEY, $blocked, $state);
            return;
        }

        // Persist a watchdog BEFORE opening the work transaction. A hard timeout
        // rolls back that batch but leaves a scheduled retry and its attempt count.
        $args = [['user_id' => $userId, 'id' => $jobId]];
        $retryAt = time() + 15 * MINUTE_IN_SECONDS;
        $connection = Customer::query()->getConnection();
        if (!wp_schedule_single_event($retryAt, static::HOOK, $args) && !wp_next_scheduled(static::HOOK, $args)) {
            $connection->transaction(function () use ($userId, $jobId) {
                User::query()->where('ID', $userId)->lockForUpdate()->first();
                wp_cache_delete($userId, 'user_meta');
                $state = static::progress($userId);
                if (($state['id'] ?? '') === $jobId && ($state['status'] ?? '') === 'pending') {
                    $state['status'] = 'failed';
                    update_user_meta($userId, static::META_KEY, $state);
                }
            });
            return;
        }
        try {
            $ready = $connection->transaction(function () use ($userId, $jobId) {
                User::query()->where('ID', $userId)->lockForUpdate()->first();
                wp_cache_delete($userId, 'user_meta');
                $state = static::progress($userId);
                if (($state['id'] ?? '') !== $jobId || ($state['status'] ?? '') !== 'pending') {
                    return false;
                }
                if ($state['attempts'] >= static::MAX_ATTEMPTS) {
                    $state['status'] = 'failed';
                    update_user_meta($userId, static::META_KEY, $state);
                    return false;
                }
                $state['attempts']++;
                update_user_meta($userId, static::META_KEY, $state);
                return true;
            });
            if ($ready) {
                $connection->transaction(function () use ($userId, $jobId) {
                    User::query()->where('ID', $userId)->lockForUpdate()->first();
                    clean_user_cache($userId);
                    wp_cache_delete($userId, 'user_meta');
                    $state = static::progress($userId);
                    if (($state['id'] ?? '') !== $jobId || ($state['status'] ?? '') !== 'pending') {
                        return;
                    }
                    $user = get_userdata($userId);
                    $target = Customer::query()->where('user_id', $userId)->orderBy('id')->lockForUpdate()->first();
                    $conflict = Customer::query()->where('email', $state['email'])->where('id', '!=', $state['customer_id'])->where('user_id', '>', 0)->exists();
                    if (!$user || !$target || (int) $target->id !== $state['customer_id'] || !EmailClaimService::isEnabled() || EmailVerificationService::isRequired($userId)
                        || !EmailVerificationService::isSame($user->user_email, $state['email'])
                        || !EmailVerificationService::isSame($target->email, $state['email']) || $conflict) {
                        $state['status'] = 'cancelled';
                        update_user_meta($userId, static::META_KEY, $state);
                        return;
                    }
                    $source = static::sources($state['email'], (int) $target->id)
                        ->where('id', '>', $state['cursor'])->where('id', '<=', $state['upper_id'])
                        ->orderBy('id')->lockForUpdate()->first();
                    if ($source) {
                        $needsAnotherBatch = false;
                        $complete = CustomerMerger::absorb($source, $target, $needsAnotherBatch);
                        if (!$needsAnotherBatch) {
                            $state['cursor'] = (int) $source->id;
                            $state['processed']++;
                            $state['incomplete'] = $state['incomplete'] || !$complete;
                        }
                    } else {
                        $target->recountStat();
                        $state['status'] = $state['incomplete'] ? 'incomplete' : 'completed';
                    }
                    $state['attempts'] = 0;
                    update_user_meta($userId, static::META_KEY, $state);
                });
            }
        } catch (\Throwable $exception) {
            // The durable watchdog retries the same cursor; no partial batch commits.
            wp_cache_delete($userId, 'user_meta');
            return;
        }

        wp_cache_delete($userId, 'user_meta');
        $state = static::progress($userId);
        if (($state['id'] ?? '') === $jobId && ($state['status'] ?? '') === 'pending') {
            // Keep the watchdog unless the next invocation is durably scheduled.
            if (!wp_schedule_single_event(time() + 1, static::HOOK, $args)) {
                return;
            }
        }
        wp_unschedule_event($retryAt, static::HOOK, $args);
    }
}
