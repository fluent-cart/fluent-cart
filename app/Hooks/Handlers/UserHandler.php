<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\Helpers\Status;
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

        // Account changes do not prove inbox ownership or change customer contact details.
        EmailVerificationService::markPending((int) $userId, $user->user_email);
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
        $user = $userId ? get_userdata($userId) : false;
        if ($user) {
            EmailVerificationService::markPending((int) $userId, $user->user_email);
        }
    }

}
