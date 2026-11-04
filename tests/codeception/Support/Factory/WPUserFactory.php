<?php

namespace Tests\Support\Factory;

use FluentCart\App\Services\CustomerIdentity\EmailVerificationService;

/**
 * Creates trusted accounts by default. Pass false for an account whose email still needs
 * proof, the state a self-service email change leaves behind.
 */
class WPUserFactory
{
    private static int $counter = 0;

    /**
     * Create a WP user and return the user ID.
     */
    public static function create(array $overrides = [], bool $verified = true): int
    {
        self::$counter++;

        $defaults = [
            'user_login' => 'testuser' . self::$counter . '_' . wp_generate_password(4, false),
            'user_pass'  => 'password',
            'user_email' => 'testuser-' . self::$counter . '@example.com',
            'role'       => 'subscriber',
            'first_name' => 'Test',
            'last_name'  => 'User' . self::$counter,
        ];

        $data = array_merge($defaults, $overrides);
        $userId = wp_insert_user($data);

        if (is_wp_error($userId)) {
            throw new \RuntimeException('Failed to create WP user: ' . $userId->get_error_message());
        }

        if (!$verified) {
            EmailVerificationService::markUnverified($userId, $data['user_email']);
        }

        return $userId;
    }
}
