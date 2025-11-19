<?php

namespace FluentCart\App\Services;

use FluentCart\App\Models\Customer;

class AuthService
{

    public static function createUserFromCustomer(Customer $customer, $sendUserEmail = true)
    {
        $userName = self::createUserNameFromStrings($customer->email, [$customer->first_name, $customer->last_name]);

        return self::registerNewUser(
            $userName,
            $customer->email,
            '',
            [
                'first_name' => $customer->first_name,
                'last_name'  => $customer->last_name
            ]
        );
    }

    public static function registerNewUser($user_login, $user_email, $user_pass = '', $extraData = [])
    {
        $errors = new \WP_Error();

        $sanitized_user_login = sanitize_user($user_login);

        // Check the username.
        if ('' === $sanitized_user_login) {
            $errors->add('empty_username', __('<strong>Error</strong>: Please enter a username.', 'fluent-cart'));
        } elseif (username_exists($sanitized_user_login)) {
            $errors->add('username_exists', __('<strong>Error</strong>: This username is already registered. Please choose another one.', 'fluent-cart'));
        }

        // Check the email address.
        if ('' === $user_email) {
            $errors->add('empty_email', __('<strong>Error</strong>: Please type your email address.', 'fluent-cart'));
        } elseif (!is_email($user_email)) {
            $errors->add('invalid_email', __('<strong>Error</strong>: The email address is not correct.', 'fluent-cart'));
            $user_email = '';
        } elseif (email_exists($user_email)) {
            $errors->add(
                'email_exists',
                __('<strong>Error:</strong> This email address is already registered. Please login or try resetting your password.', 'fluent-cart')
            );
        }

        if ($errors->has_errors()) {
            return $errors;
        }

        $isGeneratedPassword = false;
        if (!$user_pass) {
            $isGeneratedPassword = true;
            $user_pass = wp_generate_password(8, false);
        }

        $data = [
            'user_login' => wp_slash($sanitized_user_login),
            'user_email' => wp_slash($user_email),
            'user_pass'  => $user_pass
        ];

        if (!empty($extraData['first_name'])) {
            $data['first_name'] = sanitize_text_field($extraData['first_name']);
        }

        if (!empty($extraData['last_name'])) {
            $data['last_name'] = sanitize_text_field($extraData['last_name']);
        }

        if (!empty($extraData['full_name']) && empty($extraData['first_name']) && empty($extraData['last_name'])) {
            $extraData['full_name'] = sanitize_text_field($extraData['full_name']);
            // extract the names
            $fullNameArray = explode(' ', $extraData['full_name']);
            $data['first_name'] = array_shift($fullNameArray);
            if ($fullNameArray) {
                $data['last_name'] = implode(' ', $fullNameArray);
            } else {
                $data['last_name'] = '';
            }
        }

        if (!empty($extraData['description'])) {
            $data['description'] = sanitize_textarea_field($extraData['description']);
        }

        if (!empty($extraData['user_url']) && filter_var($extraData['user_url'], FILTER_VALIDATE_URL)) {
            $data['user_url'] = sanitize_url($extraData['user_url']);
        }

        if (!empty($extraData['role'])) {
            $data['role'] = $extraData['role'];
        }

        $user_id = wp_insert_user($data);

        if (!$user_id || is_wp_error($user_id)) {
            $errors->add('registerfail', __('<strong>Error</strong>: Could not register you. Please contact the site admin!', 'fluent-cart'));
            return $errors;
        }

        if (!empty($_COOKIE['wp_lang'])) {
            $wp_lang = sanitize_text_field(wp_unslash($_COOKIE['wp_lang']));
            if (in_array($wp_lang, get_available_languages(), true)) {
                update_user_meta($user_id, 'locale', $wp_lang); // Set user locale if defined on registration.
            }
        }

        if ($isGeneratedPassword) {
            update_user_meta($user_id, 'default_password_nag', true); // Set up the password change nag.
        }

        do_action('fluent_cart/user/after_register', $user_id, [
            'user_id' => $user_id
        ]);

        if (apply_filters('fluent_cart/user/after_register/skip_hooks', false, $user_id)) {
            return $user_id;
        }

        do_action('register_new_user', $user_id);

        return $user_id;
    }

    public static function makeLogin($user)
    {
        wp_clear_auth_cookie();
        wp_set_current_user($user->ID, $user->user_login);
        wp_set_auth_cookie($user->ID, true, is_ssl());

        $user = get_user_by('ID', $user->ID);

        if ($user) {
          //  do_action('wp_login', $user->user_login, $user);
        }

        return $user;
    }

    public static function createUserNameFromStrings($maybeEmail, $fallbacks = [])
    {
        $emailParts = explode('@', $maybeEmail);
        $userName = $emailParts[0];

        $userName = self::sanitizeUserName($userName);

        if (self::isUsernameAvailable($userName)) {
            return $userName;
        }

        foreach ($fallbacks as $fallback) {
            // only take alphanumeric characters and _ -
            $fallback = preg_replace('/[^a-zA-Z0-9_-]/', '', $fallback);
            $userName = self::sanitizeUserName($fallback);
            if (self::isUsernameAvailable($userName)) {
                return $userName;
            }
        }

        $userName = strtolower($emailParts[0]);

        $finalUserName = $userName;

        // loop until we find a unique username
        $counter = 2;
        while (!self::isUsernameAvailable($userName)) {
            $userName = $finalUserName . $counter;
            $counter++;
            if ($counter % 100 === 0) {
                $finalUserName = $finalUserName . '-' . time();
            }
        }

        return $userName;
    }

    private static function sanitizeUserName($username)
    {
        $username = strtolower($username);

        // check of @ symbol
        if (strpos($username, '@') !== false) {
            $username = explode('@', $username)[0];
        }

        $username = sanitize_user($username);
        $username = preg_replace('/[^a-zA-Z0-9_]/', '', $username);
        return $username;
    }

    private static function isUsernameAvailable($userName)
    {
        $userName = strtolower($userName);
        if (strlen($userName) < 3) {
            return false;
        }

        $reservedUserNames = self::getReservedUserNames();
        if (in_array($userName, $reservedUserNames)) {
            return false;
        }

        if (defined('FLUENT_COMMUNITY_PLUGIN_VERSION')) {
            $xProfile = \FluentCommunity\App\Models\XProfile::where('username', $userName)
                ->exists();
            if ($xProfile) {
                return false;
            }
        }

        $illegal_user_logins = (array)apply_filters('illegal_user_logins', array());
        if (in_array($userName, array_map('strtolower', $illegal_user_logins), true)) {
            return false;
        }

        if (username_exists($userName)) {
            return false;
        }

        return true;
    }

    private static function getReservedUserNames()
    {
        return apply_filters('fluent_community/reserved_usernames', [
            'admin', 'administrator', 'me', 'moderator', 'mod', 'superuser', 'root', 'system', 'official', 'staff', 'support', 'helpdesk', 'user', 'guest', 'anonymous', 'everyone', 'anybody', 'someone', 'webmaster', 'postmaster', 'hostmaster', 'abuse', 'security', 'ssl', 'firewall', 'no-reply', 'noreply', 'mail', 'email', 'mailer', 'smtp', 'pop', 'imap', 'ftp', 'sftp', 'ssh', 'ceo', 'cfo', 'cto', 'founder', 'cofounder', 'owner', 'president', 'vicepresident', 'director', 'manager', 'supervisor', 'executive', 'info', 'contact', 'sales', 'marketing', 'support', 'billing', 'accounting', 'finance', 'hr', 'humanresources', 'legal', 'compliance', 'it', 'itsupport', 'customerservice', 'customersupport', 'dev', 'developer', 'api', 'sdk', 'app', 'bot', 'chatbot', 'sysadmin', 'devops', 'infosec', 'security', 'test', 'testing', 'beta', 'alpha', 'staging', 'production', 'development', 'home', 'about', 'contact', 'faq', 'help', 'news', 'blog', 'forum', 'community', 'events', 'calendar', 'shop', 'store', 'cart', 'checkout', 'social', 'follow', 'like', 'share', 'tweet', 'post', 'status', 'privacy', 'terms', 'copyright', 'trademark', 'legal', 'policy', 'all', 'none', 'null', 'undefined', 'true', 'false', 'default', 'example', 'sample', 'demo', 'temporary', 'delete', 'remove', 'profanity', 'explicit', 'offensive', 'yourappname', 'yourbrandname', 'yourdomain',
        ]);
    }

}
