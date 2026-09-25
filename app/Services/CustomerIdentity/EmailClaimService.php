<?php

namespace FluentCart\App\Services\CustomerIdentity;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\User;
use FluentCart\App\Models\Customer;
use FluentCart\App\Services\Email\Mailer;
use FluentCart\Framework\Support\Arr;

/**
 * Verifies the signed-in account's inbox before updating contact details or
 * recovering guest purchases. A mailed link and an authenticated POST are
 * both required; authentication alone never proves ownership of an address.
 */
class EmailClaimService
{
    const TTL_SECONDS = DAY_IN_SECONDS;

    /** Domain separator so a token minted here is never valid anywhere else signing with the same salt. */
    const SIGNING_CONTEXT = 'fluent_cart_email_claim_v1';

    /** User meta holding the pending claim's nonce hash: only the newest link works, and once. */
    const META_KEY = EmailVerificationService::PENDING_CLAIM_META_KEY;

    const QUERY_TOKEN = 'fct_email_claim';

    const QUERY_STATUS = 'fct_email_claim_status';

    const RATE_LIMIT = 5;

    public static function isEnabled(): bool
    {
        /*
         * Whether customers may confirm an email address from the customer
         * portal to update their contact email and recover guest purchases.
         * Off, a diverged record stays on its old address until staff move it.
         *
         * @param bool $enabled
         */
        return (bool) apply_filters('fluent_cart/customer/enable_email_claim', true);
    }

    /**
     * What the signed-in account could confirm right now, or null.
     *
     * 'unverified' — a new account has not proved its current address.
     * 'diverged'  — the linked record carries a different address than the account.
     * 'recovery'  — unlinked records hold the account's address (guest purchases).
     *
     * Null covers a lot: not signed in, feature off, nothing to reconcile, or the
     * address is held by a record linked to another account — a conflict between
     * two accounts that is left for staff rather than resolved by whoever asks first.
     * Deliberately loads no guest data: the offer says nothing about what is there.
     *
     * @return array|null ['customer' => Customer|null, 'from' => string, 'to' => string, 'reason' => string]
     */
    public static function getOffer(): ?array
    {
        if (!static::isEnabled()) {
            return null;
        }

        $userId = get_current_user_id();
        $user = $userId ? get_user_by('ID', $userId) : false;
        if (!$user || !$user->user_email) {
            return null;
        }

        $to = $user->user_email;
        $customer = Customer::query()->where('user_id', $userId)->orderBy('id', 'ASC')->first();
        if ($customer && (int) $customer->user_id !== $userId) {
            return null;
        }
        $customerId = $customer ? (int) $customer->id : 0;

        if (static::heldByLinkedCustomer($to, $customerId)) {
            return null;
        }

        if ($customer && !static::isSame($customer->email, $to)) {
            return ['customer' => $customer, 'from' => $customer->email, 'to' => $to, 'reason' => 'diverged'];
        }

        if (EmailVerificationService::isRequired($userId)) {
            return ['customer' => $customer, 'from' => $customer ? $customer->email : '', 'to' => $to, 'reason' => 'unverified'];
        }

        if ((CustomerRecoveryService::progress($userId)['status'] ?? '') === 'pending') {
            return null;
        }

        if (CustomerMerger::hasRecoverableCustomers(static::unclaimedRecordsHolding($to, $customerId))) {
            return ['customer' => $customer, 'from' => $customer ? $customer->email : '', 'to' => $to, 'reason' => 'recovery'];
        }

        return null;
    }

    /**
     * Mail a confirmation link to the address being claimed.
     *
     * @return string 'sent', or one of unavailable|throttled|no_portal|send_failed
     */
    public static function issue(): string
    {
        $offer = static::getOffer();
        if (!$offer) {
            return 'unavailable';
        }

        $userId = get_current_user_id();
        $to = $offer['to'];

        // Two buckets for two abuses: an account cycling its own address to
        // mail-bomb a series of victims, and one inbox targeted from many accounts.
        if (static::hitRateLimit('user_' . $userId) || static::hitRateLimit('to_' . wp_hash(static::normalize($to)))) {
            return 'throttled';
        }

        $portal = (new StoreSettings())->getCustomerProfilePage();
        if (!$portal) {
            return 'no_portal';
        }

        $nonce = bin2hex(random_bytes(16));
        $expires = time() + static::TTL_SECONDS;
        $customerId = $offer['customer'] ? (int) $offer['customer']->id : 0;
        $token = static::buildToken($customerId, $userId, $offer['from'], $to, $expires, $nonce);

        // Replaces any earlier pending claim, so only the newest link works.
        update_user_meta($userId, static::META_KEY, ['hash' => static::hashNonce($nonce), 'expires' => $expires]);

        $link = add_query_arg(static::QUERY_TOKEN, $token, $portal);

        if (!static::mail($to, $offer, $link)) {
            delete_user_meta($userId, static::META_KEY);
            return 'send_failed';
        }

        return 'sent';
    }

    /**
     * Check a confirmation token against the world as it is now, not as it was
     * when the link was issued.
     *
     * @return array ['status' => 'ok'|slug, 'customer' => Customer|null, 'email' => string, 'user_id' => int]
     */
    public static function resolveClaim(string $token): array
    {
        if (!static::isEnabled()) {
            return ['status' => 'disabled'];
        }

        $claim = static::parseToken($token);
        if (!$claim) {
            return ['status' => 'invalid'];
        }

        if ($claim['expires'] < time()) {
            return ['status' => 'expired'];
        }

        // The second half of the proof: reading the inbox is not enough on its own,
        // and a link forwarded to somebody else does nothing in their hands.
        $userId = get_current_user_id();
        if (!$userId || $userId !== $claim['user_id']) {
            return ['status' => 'wrong_account'];
        }

        // Used, superseded by a newer link, or minted before a re-request.
        $pending = get_user_meta($userId, static::META_KEY, true);
        if (!is_array($pending) || empty($pending['hash']) || !hash_equals((string) $pending['hash'], static::hashNonce($claim['nonce']))) {
            return ['status' => 'stale'];
        }

        $user = get_user_by('ID', $userId);
        if (!$user || !static::isSame($user->user_email, $claim['to'])) {
            return ['status' => 'stale'];
        }

        $customer = Customer::query()->where('user_id', $userId)->orderBy('id', 'ASC')->first();
        if ($claim['customer_id']) {
            if (!$customer || (int) $customer->id !== $claim['customer_id'] || !static::isSame($customer->email, $claim['from'])) {
                return ['status' => 'stale'];
            }
        }
        // customer_id 0: the account had no record when the link was issued. One it
        // gained since is simply used — it is linked to the same account.

        if (static::heldByLinkedCustomer($claim['to'], $customer ? (int) $customer->id : 0)) {
            return ['status' => 'conflict'];
        }

        // The account's address as WordPress stores it, not the normalised copy the token carries.
        return ['status' => 'ok', 'customer' => $customer, 'email' => $user->user_email, 'user_id' => $userId, 'pending' => $pending];
    }

    /**
     * Apply a confirmed claim: absorb unlinked records at the address, then move
     * the contact address onto it. Consumes the link.
     *
     * @return string 'confirmed', 'recovering', 'incomplete', or a resolveClaim() slug
     */
    public static function confirm(string $token): string
    {
        if (!CustomerMerger::supportsTransactions()) {
            return 'storage_unsupported';
        }

        // Lock the account for concurrent confirmations and recheck all proof
        // inside the transaction. WordPress and FluentCart share this connection.
        try {
            return Customer::query()->getConnection()->transaction(function () use ($token) {
                User::query()->where('ID', get_current_user_id())->lockForUpdate()->first();
                clean_user_cache(get_current_user_id());
                wp_cache_delete(get_current_user_id(), 'user_meta');
                $claim = static::resolveClaim($token);
                if ($claim['status'] !== 'ok') {
                    return $claim['status'];
                }

                $userId = (int) $claim['user_id'];
                // Compare-and-delete consumes only the link that was validated.
                if (!delete_user_meta($userId, static::META_KEY, $claim['pending'])) {
                    return 'stale';
                }

                return static::completeConfirmation($userId, $claim['email'], $claim['customer']);
            });
        } catch (\Throwable $exception) {
            // Do not leave a cached verified state after a transaction rollback.
            wp_cache_delete(get_current_user_id(), 'user_meta');
            CustomerResource::resetCurrentCustomerRuntimeCache();
            return 'failed';
        }
    }

    /** Called only after a successful password reset with validated inbox proof. */
    public static function confirmPasswordReset(int $userId, string $email): string
    {
        if (!static::isEnabled() || !CustomerMerger::supportsTransactions()) {
            return 'unavailable';
        }
        try {
            return Customer::query()->getConnection()->transaction(function () use ($userId, $email) {
                $user = User::query()->where('ID', $userId)->lockForUpdate()->first();
                if (!$user || !static::isSame($user->user_email, $email)) {
                    return 'stale';
                }
                $customer = Customer::query()->where('user_id', $userId)->orderBy('id')->lockForUpdate()->first();
                if (static::heldByLinkedCustomer($email, $customer ? (int) $customer->id : 0)) {
                    return 'conflict';
                }
                delete_user_meta($userId, static::META_KEY);
                return static::completeConfirmation($userId, $email, $customer);
            });
        } catch (\Throwable $exception) {
            wp_cache_delete($userId, 'user_meta');
            CustomerResource::resetCurrentCustomerRuntimeCache();
            return 'failed';
        }
    }

    /** Shared finalization after proof; caller holds the account transaction lock. */
    protected static function completeConfirmation(int $userId, string $email, ?Customer $customer): string
    {
        $customer = $customer ?: static::createCustomerFor($userId, $email);
        if (!$customer) {
            throw new \RuntimeException('Unable to create the verified customer.');
        }

        $sources = static::unclaimedRecordsHolding($email, (int) $customer->id)
            ->limit(CustomerRecoveryService::FOREGROUND_SOURCES + 1)->lockForUpdate()->get();
        $queued = $sources->count() > CustomerRecoveryService::FOREGROUND_SOURCES
            || !CustomerMerger::fitsForeground($sources->pluck('id')->toArray());
        $incomplete = false;
        if ($queued) {
            CustomerRecoveryService::start($userId, $customer, $email);
        } else {
            delete_user_meta($userId, CustomerRecoveryService::META_KEY);
            foreach ($sources as $source) {
                if (!CustomerMerger::absorb($source, $customer)) {
                    $incomplete = true;
                }
            }
        }

        if (!static::isSame($customer->email, $email)) {
            $previousCustomer = clone $customer;
            $previousEmail = $customer->email;
            $customer->email = $email;
            if (!$customer->save()) {
                throw new \RuntimeException('Unable to update the verified customer.');
            }

            do_action('fluent_cart/customer_email_changed', [
                'old_customer' => $previousCustomer,
                'new_customer' => $customer,
                'old_email'    => $previousEmail,
                'new_email'    => $email,
                'userId'       => $userId
            ]);
        }

        $customer->recountStat();
        EmailVerificationService::markVerified($userId, $email);
        return $queued ? 'recovering' : ($incomplete ? 'incomplete' : 'confirmed');
    }

    public static function buildToken(int $customerId, int $userId, string $from, string $to, int $expires, string $nonce): string
    {
        // Emails are percent-encoded before joining: is_email() accepts '|' in the local part.
        $payload = implode('|', ['v1', $customerId, $userId, rawurlencode(static::normalize($from)), rawurlencode(static::normalize($to)), $expires, $nonce]);
        $raw = $payload . '|' . static::sign($payload);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @return array|null Decoded only after the signature verifies, so what is checked is exactly what was signed.
     */
    protected static function parseToken(string $token): ?array
    {
        if ($token === '' || strlen($token) > 2048 || !preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            return null;
        }

        $padded = str_pad(strtr($token, '-_', '+/'), (int) (ceil(strlen($token) / 4) * 4), '=');
        $raw = base64_decode($padded, true);
        if (!$raw) {
            return null;
        }

        $parts = explode('|', $raw);
        if (count($parts) !== 8 || $parts[0] !== 'v1') {
            return null;
        }

        $signature = array_pop($parts);
        if (!hash_equals(static::sign(implode('|', $parts)), $signature)) {
            return null;
        }

        return [
            'customer_id' => (int) $parts[1],
            'user_id'     => (int) $parts[2],
            'from'        => rawurldecode($parts[3]),
            'to'          => rawurldecode($parts[4]),
            'expires'     => (int) $parts[5],
            'nonce'       => $parts[6],
        ];
    }

    protected static function sign(string $payload): string
    {
        return hash_hmac('sha256', static::SIGNING_CONTEXT . '|' . $payload, wp_salt('auth'));
    }

    protected static function hashNonce(string $nonce): string
    {
        return hash_hmac('sha256', $nonce, wp_salt('auth'));
    }

    protected static function mail(string $to, array $offer, string $link): bool
    {
        $siteName = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $customer = $offer['customer'];
        $firstName = $customer ? $customer->first_name : '';
        if (!$firstName) {
            $user = get_user_by('ID', get_current_user_id());
            $firstName = $user ? $user->first_name : '';
        }

        // translators: %1$s is the site name
        $subject = sprintf(__('[%1$s] Confirm your email address', 'fluent-cart'), $siteName);

        $paragraphs = [
            sprintf(
                // translators: %1$s is the customer's first name.
                esc_html__('Hello %1$s,', 'fluent-cart'),
                esc_html($firstName)
            ),
            sprintf(
                // translators: 1: site name, 2: email address being confirmed.
                esc_html__('Someone asked to use this address for their customer account on %1$s. Confirming will set %2$s as your contact email and bring any purchases made with it into your account.', 'fluent-cart'),
                esc_html($siteName),
                esc_html($to)
            ),
            sprintf('<a style="display: inline-block; background: #2271b1; color: #ffffff; text-decoration: none; padding: 10px 24px; border-radius: 4px;" href="%1$s">%2$s</a>', esc_url($link), esc_html__('Confirm this address', 'fluent-cart')),
            esc_html__('You will be asked to sign in first, so this link only works for the account that requested it. It expires in 24 hours.', 'fluent-cart'),
            esc_html__('If you did not ask for this, no action is needed and nothing has changed.', 'fluent-cart'),
        ];
        $body = '';
        foreach ($paragraphs as $paragraph) {
            $body .= sprintf('<p style="font-family: Arial, sans-serif; font-size: 16px; margin: 0 0 16px;">%1$s</p>', $paragraph);
        }

        return (bool) Mailer::make($to, $subject, $body)->send();
    }

    /**
     * @return \FluentCart\App\Models\Customer|null
     */
    protected static function createCustomerFor(int $userId, string $email): ?Customer
    {
        $user = get_user_by('ID', $userId);
        if (!$user) {
            return null;
        }

        // Inbox proof has been validated inside the confirmation transaction.
        // Claim the existing guest row so its purchases keep the same customer ID.
        $customer = Customer::query()->where('email', $email)->unclaimed()->orderBy('id')->lockForUpdate()->first();
        if ($customer) {
            $customer->user_id = $userId;
            if (!$customer->save()) {
                throw new \RuntimeException('Unable to link the verified customer.');
            }
            return $customer;
        }
        if (static::heldByLinkedCustomer($email, 0)) {
            throw new \RuntimeException('The customer was linked to another account.');
        }

        return Customer::query()->create([
            'user_id'    => $userId,
            'email'      => $email,
            'first_name' => (string) $user->first_name,
            'last_name'  => (string) $user->last_name,
            'status'     => 'active',
        ]);
    }

    protected static function heldByLinkedCustomer(string $email, int $excludeId): bool
    {
        return Customer::query()->where('email', $email)->where('id', '!=', $excludeId)->where('user_id', '>', 0)->exists();
    }

    protected static function unclaimedRecordsHolding(string $email, int $excludeId)
    {
        return Customer::query()->where('email', $email)->where('id', '!=', $excludeId)->unclaimed()->orderBy('id', 'ASC');
    }

    public static function normalize($email): string
    {
        return EmailVerificationService::normalize($email);
    }

    public static function isSame($first, $second): bool
    {
        return EmailVerificationService::isSame($first, $second);
    }

    /**
     * Counted with the object cache when one is present. The transient fallback
     * is read-modify-write and therefore not atomic: two simultaneous requests
     * can both pass. It bounds abuse; it is not a hard limit.
     */
    protected static function hitRateLimit(string $bucket): bool
    {
        $key = 'fct_email_claim_' . $bucket;

        if (wp_using_ext_object_cache()) {
            if (wp_cache_add($key, 1, 'fct_email_claim', HOUR_IN_SECONDS)) {
                return false;
            }
            return (int) wp_cache_incr($key, 1, 'fct_email_claim') > static::RATE_LIMIT;
        }

        $state = get_transient($key);
        $count = (int) Arr::get($state ?: [], 'count', 0) + 1;
        $expires = (int) Arr::get($state ?: [], 'expires', time() + HOUR_IN_SECONDS);
        set_transient($key, ['count' => $count, 'expires' => $expires], max(1, $expires - time()));

        return $count > static::RATE_LIMIT;
    }
}
