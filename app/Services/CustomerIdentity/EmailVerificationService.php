<?php

namespace FluentCart\App\Services\CustomerIdentity;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\Customer;
use FluentCart\Framework\Support\Arr;

/**
 * Tracks the one case where an account email is not trusted: the holder changed it
 * themselves and WordPress applied it with no inbox check (REST API, front-end forms).
 * Every other account email is trusted, including the one an account registered with.
 */
class EmailVerificationService
{
    /**
     * Holds ['email' => ..., 'verified' => false] while the account email still needs proof.
     * No row means trusted. Rows with 'verified' => true are from older versions and are ignored.
     */
    const UNVERIFIED_META_KEY = '_fct_email_verification';

    /** Proof captured before WordPress consumes the reset key; never persisted. */
    private static $passwordResetProof = [];

    public static function capturePasswordResetProof($user): void
    {
        unset(self::$passwordResetProof[$user->ID]);
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
        self::$passwordResetProof[$user->ID] = [
            'email' => $validated->user_email,
            'password_hash' => $validated->user_pass,
        ];
    }

    public static function verifyAfterPasswordReset($user): void
    {
        $proof = self::$passwordResetProof[$user->ID] ?? null;
        unset(self::$passwordResetProof[$user->ID]);
        if (!$proof) {
            return;
        }
        clean_user_cache($user->ID);
        $current = get_userdata($user->ID);
        if (!$current || !static::isSame($proof['email'], $current->user_email)
            || hash_equals($proof['password_hash'], $current->user_pass)) {
            return;
        }
        EmailClaimService::confirmInboxProof((int) $user->ID, $proof['email']);
    }

    public static function markUnverified(int $userId, string $email): void
    {
        update_user_meta($userId, static::UNVERIFIED_META_KEY, [
            'email' => static::normalize($email),
            'verified' => false,
        ]);
        // Even changing away and back must invalidate the previous link.
        delete_user_meta($userId, EmailClaimService::META_KEY);
        delete_user_meta($userId, CustomerRecoveryService::META_KEY);
        CustomerResource::resetCurrentCustomerRuntimeCache();
    }

    public static function markVerified(int $userId): void
    {
        delete_user_meta($userId, static::UNVERIFIED_META_KEY);
        CustomerResource::resetCurrentCustomerRuntimeCache();
    }

    /**
     * True when the account email is not proven: the holder changed it with no inbox
     * check (recorded), or the linked customer's contact email differs from the account
     * (a direct edit that skipped the hooks).
     */
    public static function isUnverified(int $userId): bool
    {
        $user = $userId ? get_userdata($userId) : false;
        if (!$user) {
            return true;
        }

        // Any pending record blocks until proof deletes it. We do not compare it to the
        // account's current email: a change saved in a parallel request can leave the
        // record naming a different address, and that must still count as unproven.
        $state = get_user_meta($userId, static::UNVERIFIED_META_KEY, true);
        if (is_array($state) && Arr::get($state, 'verified') === false) {
            return true;
        }

        // Backstop for an email change that bypassed the hooks (a raw database edit).
        $customer = Customer::query()->where('user_id', $userId)->orderBy('id', 'ASC')->first();
        return $customer && !static::isSame($customer->email, $user->user_email);
    }

    /** For staff screens: the live account's email while it still needs proof, else null. */
    public static function unverifiedEmail($userId): ?string
    {
        $user = $userId ? get_userdata((int) $userId) : false;
        return $user && static::isUnverified((int) $user->ID) ? $user->user_email : null;
    }

    /** An unproven email must not claim the guest records that hold it. */
    public static function canClaimByEmail(int $userId): bool
    {
        return !static::isUnverified($userId);
    }

    public static function isEnabled(): bool
    {
        return (new StoreSettings())->get('require_customer_email_verification', 'yes') !== 'no';
    }

    /** Whether the portal waits for inbox proof before showing customer data. */
    public static function isRequired(int $userId): bool
    {
        if (!$userId || !get_userdata($userId)) {
            return true;
        }

        return static::isEnabled() && static::isUnverified($userId);
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
