<?php

namespace FluentCart\Api;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\Resource\UserResource;
use FluentCart\App\App;
use FluentCart\Framework\Support\Arr;

class User
{
    public function register($data)
    {
        $this->validateAndCreate($data);
    }

    public function login($data)
    {
        $this->validateAndLogin($data);
    }

    public function validateAndLogin(array $data)
    {
        $userLogin = trim(Arr::get($data, 'user_login', ''));
        $password = Arr::get($data, 'password', '');
        $rememberMe = Arr::get($data, 'remember_me', false) === 'on';

        if (!$userLogin) {
            return $this->sendError(__('Email or username is required', 'fluent-cart'), 'missing_login');
        }

        if (!$password) {
            return $this->sendError(__('Password is required', 'fluent-cart'), 'missing_password');
        }

        $credentials = [
            'user_login'    => is_email($userLogin) ? sanitize_email($userLogin) : sanitize_user($userLogin, false),
            'user_password' => $password,
            'remember'      => $rememberMe
        ];

        $user = wp_signon($credentials, is_ssl());

        if (is_wp_error($user)) {
            return $this->sendError($user->get_error_message(), 'login_failed', 401);
        }

        // Log in the user
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, $rememberMe);

        $redirectUrl = (new StoreSettings())->getCustomerProfilePage() . '#/profile';

        return $this->sendSuccess([
            'message' => __('Login successful', 'fluent-cart'),
            'redirect_url' => $redirectUrl
        ]);
    }

    public function validateAndCreate(array $data)
    {
        $email = sanitize_email(Arr::get($data, 'email', ''));

        if (get_current_user_id()) {
            return $this->sendError(__('You are already logged in', 'fluent-cart'), 'already_logged_in');
        }

        if (!is_email($email)) {
            return $this->sendError(__('Please enter a valid email address', 'fluent-cart'), 'invalid_email');
        }

        if (email_exists($email)) {
            return $this->sendError(__('Email already exists', 'fluent-cart'), 'email_exists');
        }

        $fullName = sanitize_text_field(Arr::get($data, 'full_name'));
        $nameParts = explode(' ', $fullName, 2);

        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        $processedData = [
            'email'     => $email,
            'password'  => Arr::get($data, 'password'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username'  => $email
        ];

        if (!$processedData['password']) {
            $processedData['password'] = wp_generate_password(8);
        }

        do_action('fluent_cart/user/before_registration', $processedData);

        $this->createUser($processedData);
    }

    public function createUser($processedData)
    {
        $userId = wp_create_user(
            $processedData['username'],
            $processedData['password'],
            $processedData['email']
        );

        if (is_wp_error($userId)) {
            return $this->sendError($userId->get_error_message(), 'user_creation_failed');
        }

        CustomerResource::create([
            'first_name' => $processedData['first_name'],
            'last_name' => $processedData['last_name'],
            'wp_user' => 'no',
            'email' => $processedData['email'],
            'user_id' => $userId
        ]);

        $this->updateUser($processedData, $userId);
        $this->setUserLoggedIn($userId);
        $this->sendEmail($userId);


        $checkoutPage = (new StoreSettings())->getCustomerProfilePage() . '#/profile';

        return $this->sendSuccess([
            'message' => __('User created successfully! You are now logged in.', 'fluent-cart'),
            'code' => 'user_created',
            'redirect_url' => $checkoutPage
        ]);
    }

    protected function sendEmail($userId)
    {
            // This will send an email with a password setup link
            \wp_new_user_notification($userId, null, 'user');
    }

    protected function updateUser($processedData, $userId)
    {
        $firstName = Arr::get($processedData, 'first_name');
        $lastName = Arr::get($processedData, 'last_name');
        $name = trim($firstName. ' ' . $lastName);
        
        $data = array_filter([
            'ID' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'nickname' => $name,
            'user_nicename' => $name,
            'display_name' => $name,
            'user_url' => Arr::get($processedData, 'user_url')
        ]);

        if ($name) {
            wp_update_user($data);
        }
    }

    protected function setUserLoggedIn($userId)
    {
        wp_clear_auth_cookie();
        wp_set_current_user($userId);
        wp_set_auth_cookie($userId);
    }

    protected function sendError($message, $code = 'error', $status = 400)
    {
        wp_send_json_error([
            'message' => $message,
            'code'    => $code
        ], $status);
    }

    protected function sendSuccess($data = [], $status = 200)
    {
        wp_send_json_success($data, $status);
    }

}
