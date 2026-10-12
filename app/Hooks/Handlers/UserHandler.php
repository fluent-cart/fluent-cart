<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\CustomerIdentity\EmailClaimService;
use FluentCart\App\Services\CustomerIdentity\EmailVerificationService;
use FluentCart\App\Models\Customer;
use FluentCart\Framework\Support\Arr;

class UserHandler
{
    public function register()
    {
        add_action('delete_user', [$this, 'userDeleteHandler'], 10, 1);
        add_action('user_register', [$this, 'userRegistrationHandler'], 10, 1);
        add_action('password_reset', [EmailVerificationService::class, 'capturePasswordResetProof'], 10, 1);
        add_action('after_password_reset', [EmailVerificationService::class, 'verifyAfterPasswordReset'], 10, 1);

        // Let's handle auto user registration!
        add_action('fluent_cart/cart_completed', [$this, 'maybeCreateUser'], 10, 1);

        add_action('profile_update', [$this, 'handleWpUserProfileUpdated'], 10, 3);

    }

    public function handleWpUserProfileUpdated($userId, $oldData, $newData = [])
    {
        $user = $userId ? get_userdata($userId) : false;
        $oldEmail = is_object($oldData) && isset($oldData->user_email) ? wp_unslash($oldData->user_email) : '';
        if (!$user || EmailVerificationService::isSame($oldEmail, $user->user_email)) {
            return;
        }

        // Staff authority, or WordPress's own confirmation link, already proves the
        // change, so the contact email follows the account without a second link.
        if ($this->isStaffEmailChange((int) $userId) || $this->isWpConfirmedEmailChange((int) $userId, $user->user_email)) {
            $status = EmailClaimService::confirmAdminChange((int) $userId, $user->user_email);
            if (in_array($status, ['confirmed', 'recovering', 'incomplete'], true)) {
                return;
            }
            // Fell through on a conflict or a storage failure: the gate still applies.
        }

        // State is recorded only while the store requires verification; accounts
        // without it are treated as existing accounts if verification is enabled later.
        if (!EmailVerificationService::isEnabled()) {
            return;
        }

        // Account changes do not prove inbox ownership or change customer contact details.
        EmailVerificationService::markPending((int) $userId, $user->user_email);
    }

    /**
     * True when somebody who can edit this account, and is not the account holder,
     * made the change. A self-service edit still has to prove the new inbox.
     */
    private function isStaffEmailChange(int $userId): bool
    {
        $actorId = get_current_user_id();
        $isStaff = $actorId && $actorId !== $userId && current_user_can('edit_user', $userId);

        /*
         * Whether this email change is trusted enough to move the customer's
         * contact email without a confirmation link. Lets a store extend the
         * trust to its own tooling — WP-CLI and cron run with no current user.
         *
         * @param bool  $isStaff
         * @param array $context ['user_id' => int, 'actor_id' => int]
         */
        return (bool) apply_filters('fluent_cart/customer/trust_staff_email_change', $isStaff, [
            'user_id'  => $userId,
            'actor_id' => (int) $actorId,
        ]);
    }

    /**
     * True while profile.php applies the account holder's clicked newuseremail
     * link, which WordPress only sends to the new inbox.
     */
    private function isWpConfirmedEmailChange(int $userId, string $email): bool
    {
        $hash = isset($_GET['newuseremail']) ? sanitize_text_field(wp_unslash($_GET['newuseremail'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the hash is the proof
        if (!$hash || get_current_user_id() !== $userId) {
            return false;
        }

        $pending = get_user_meta($userId, '_new_email', true);

        return is_array($pending)
            && !empty($pending['hash'])
            && hash_equals((string) $pending['hash'], $hash)
            && EmailVerificationService::isSame((string) Arr::get($pending, 'newemail', ''), $email);
    }

    public function maybeCreateUser($data)
    {
        $cart = Arr::get($data, 'cart');
        $order = Arr::get($data, 'order');
        $customer = $order->customer;

        if ($customer->getWpUserId()) {
            return; // User already exists
        }

        $willCreateUser = $order->type === Status::ORDER_TYPE_SUBSCRIPTION;

        if (!$willCreateUser) {
            // check if cart has set it
            $willCreateUser = $cart && Arr::get($cart, '_register_user', false);
        }

        if (!$willCreateUser) {
            // get the global settings where we may have auto create user enabled
            $willCreateUser = ''; // TODO: get the global settings
        }

        if (!$willCreateUser) {
            return; // No need to create user
        }

        // create the user from the customer data


    }

    /**
     * @return void
     */
    public function userDeleteHandler($userId)
    {
        if (!$userId) {
            return;
        }

        // Unlink by identity. Matching on the account's email unlinked whichever
        // customer happened to hold it — possibly another account's — and left
        // this account's own customers pointing at a dead user id when their
        // contact address had drifted from the account's.
        Customer::query()->where('user_id', $userId)->update(['user_id' => null]);
    }

    public function userRegistrationHandler($userId)
    {
        if (!EmailVerificationService::isEnabled()) {
            return;
        }

        $user = $userId ? get_userdata($userId) : false;
        if ($user) {
            EmailVerificationService::markPending((int) $userId, $user->user_email);
        }
    }

}
