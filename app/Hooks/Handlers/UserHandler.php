<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\CustomerMeta;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderDownloadPermission;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;

class UserHandler
{
    public function register()
    {
        add_action('delete_user', [$this, 'userDeleteHandler'], 10, 1);
        add_action('user_register', [$this, 'userRegistrationHandler'], 10, 1);

        // Let's handle auto user registration!
        add_action('fluent_cart/cart_completed', [$this, 'maybeCreateUser'], 10, 1);

        add_action('profile_update', [$this, 'handleWpUserProfileUpdated'], 10, 3);

    }

    public function handleWpUserProfileUpdated($userId, $oldData, $newData = [])
    {
        $emailChanged = $oldData->user_email !== $newData['user_email'];

        if (!$emailChanged) {
            return; //  we will change the first name and last name a bit later
        }

        $newEmail = $newData['user_email'];
        $oldEmail = $oldData->user_email;


        $attachToCustomer = Customer::query()->where('email', $newEmail)->first();


        // if $attachToCustomer is empty, then simply just update the customer email
        if (empty($attachToCustomer)) {
            $oldCustomer = Customer::query()->where('email', $oldEmail)->first();
            Customer::query()->where('email', $oldEmail)->update(['email' => $newEmail]);
            do_action('fluent_cart/customer_email_changed', [
                'old_customer' => $oldCustomer,
                'new_customer' => $oldCustomer,
                'old_email'    => $oldEmail,
                'new_email'    => $newEmail,
                'userId'       => $userId
            ]);
        } else {
            $oldCustomer = Customer::query()->where('email', $oldEmail)->first();
            if (empty($oldCustomer)) {
                $attachToCustomer->update(['user_id' => $userId]);
                return;
            }

            $this->moveCustomerResources($oldCustomer->id, $attachToCustomer->id);
            $oldCustomer->recountStat();
            $attachToCustomer->recountStat();
        }

    }


    public function maybeCreateUser($data)
    {
        $cart = Arr::get($data, 'cart');
        $order = Arr::get($data, 'order');
        $customer = $order->customer;

        if ($customer->getWpUserId(true)) {
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
        $user = get_user_by('ID', $userId);
        if (!$user) {
            return;
        }

        // Check if the user_email is a customer
        $customer = Customer::query()
            ->where('email', $user->user_email)
            ->first();

        // remove user_id from $customer
        if ($customer) {
            $customer->update(['user_id' => NULL]);
        }
    }

    public function userRegistrationHandler($userId)
    {
        // get user by $userId
        $user = get_user_by('ID', $userId);

        // Check if the user_email is a customer
        $customer = Customer::query()
            ->where('email', $user->user_email)
            ->first();

        if ($customer) {
            $this->updateCustomer($customer, $user, $userId);
            return;
        }
    }

    private function updateCustomer(Customer $customer, object $user, int $userId): void
    {
        $data = ['user_id' => $userId];

        if (!empty($user->first_name)) {
            $data['first_name'] = $user->first_name;
        }

        if (!empty($user->last_name)) {
            $data['last_name'] = $user->last_name;
        }

        $customer->update($data);
    }

    private function moveCustomerResources($fromCustomerId, $toCustomerId)
    {
        OrderDownloadPermission::query()->where('customer_id', $fromCustomerId)->update(['customer_id' => $toCustomerId]);
        Order::query()->where('customer_id', $fromCustomerId)->update(['customer_id' => $toCustomerId]);
        AppliedCoupon::query()->where('customer_id', $fromCustomerId)->update(['customer_id' => $toCustomerId]);
        Cart::query()->where('customer_id', $fromCustomerId)->update(['customer_id' => $toCustomerId]);
        CustomerMeta::query()->where('customer_id', $fromCustomerId)->update(['customer_id' => $toCustomerId]);
        CustomerAddresses::query()->where('customer_id', $fromCustomerId)->update(['customer_id' => $toCustomerId]);
        Subscription::query()->where('customer_id', $fromCustomerId)->update(['customer_id' => $toCustomerId]);

        do_action('fluent_cart/customer_resources_moved', [
            'from_customer_id' => $fromCustomerId,
            'to_customer_id'   => $toCustomerId
        ]);
    }
}
