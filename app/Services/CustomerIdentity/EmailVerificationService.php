<?php

namespace FluentCart\App\Services\CustomerIdentity;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\Models\Customer;
use FluentCart\Framework\Support\Arr;

/** Tracks inbox proof independently from WordPress authentication. */
class EmailVerificationService
{
    const META_KEY = '_fct_email_verification';

    const PENDING_CLAIM_META_KEY = '_fct_email_claim';

    /** Proof captured before WordPress consumes the reset key; never persisted. */
    private static $passwordResetProof = [];

    public static function capturePasswordResetProof($user): void
    {
        unset(static::$passwordResetProof[$user->ID]);
        $cookie = Arr::get($_COOKIE, 'wp-resetpass-' . COOKIEHASH, '');
        $postedKey = Arr::get($_POST, 'rp_key', '');
        if (!is_string($cookie) || !is_string($postedKey) || !$postedKey) {
            return;
        }
        $parts = explode(':', wp_unslash($cookie), 2);
        if (count($parts) !== 2 || !hash_equals($parts[1], wp_unslash($postedKey))) {
            return;
        }
        // Check the emailed key while it is still valid, not merely the reset hook.
        $validated = check_password_reset_key($parts[1], $parts[0]);
        if (is_wp_error($validated) || (int) $validated->ID !== (int) $user->ID) {
            return;
        }
        static::$passwordResetProof[$user->ID] = [
            'email' => $validated->user_email,
            'password_hash' => $validated->user_pass,
        ];
    }

    public static function verifyAfterPasswordReset($user): void
    {
        $proof = static::$passwordResetProof[$user->ID] ?? null;
        unset(static::$passwordResetProof[$user->ID]);
        if (!$proof) {
            return;
        }
        clean_user_cache($user->ID);
        $current = get_userdata($user->ID);
        if (!$current || !static::isSame($proof['email'], $current->user_email)
            || hash_equals($proof['password_hash'], $current->user_pass)) {
            return;
        }
        EmailClaimService::confirmPasswordReset((int) $user->ID, $proof['email']);
    }

    public static function markPending(int $userId, string $email): void
    {
        update_user_meta($userId, static::META_KEY, [
            'email' => static::normalize($email),
            'verified' => false,
        ]);
        // Even changing away and back must invalidate the previous link.
        delete_user_meta($userId, static::PENDING_CLAIM_META_KEY);
        delete_user_meta($userId, CustomerRecoveryService::META_KEY);
        CustomerResource::resetCurrentCustomerRuntimeCache();
    }

    public static function markVerified(int $userId, string $email): void
    {
        update_user_meta($userId, static::META_KEY, [
            'email' => static::normalize($email),
            'verified' => true,
        ]);
        CustomerResource::resetCurrentCustomerRuntimeCache();
    }

    public static function isRequired(int $userId): bool
    {
        $user = $userId ? get_userdata($userId) : false;
        if (!$user) {
            return true;
        }

        $state = get_user_meta($userId, static::META_KEY, true);
        if (!is_array($state) || Arr::get($state, 'verified') !== true || !static::isSame(Arr::get($state, 'email', ''), $user->user_email)) {
            return true;
        }

        // Check live customer data too: direct edits may bypass WordPress hooks.
        $customer = Customer::query()->where('user_id', $userId)->orderBy('id', 'ASC')->first();
        return $customer && !static::isSame($customer->email, $user->user_email);
    }

    public static function normalize($email): string
    {
        return strtolower(trim((string) $email));
    }

    public static function isSame($first, $second): bool
    {
        return static::normalize($first) === static::normalize($second);
    }
}
