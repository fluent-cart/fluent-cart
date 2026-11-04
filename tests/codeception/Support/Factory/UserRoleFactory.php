<?php

namespace Tests\Support\Factory;

use FluentCart\App\Services\Permission\PermissionManager;

/**
 * Create WP users with FluentCart shop-role metadata attached.
 *
 * FluentCart shop roles (`super_admin`, `manager`, `worker`, `accountant`) are
 * stored in usermeta under `_fluent_cart_admin_role`. The only true super_admin
 * path at runtime is a WP user holding the `manage_options` capability —
 * attaching the `super_admin` meta alone does NOT grant full access when the
 * user lacks `manage_options`.
 *
 * Returns WP user IDs so callers stay explicit about the auth context switch.
 */
class UserRoleFactory
{
    public static function createAdmin(): int
    {
        return WPUserFactory::create(['role' => 'administrator']);
    }

    public static function createSubscriber(): int
    {
        return WPUserFactory::create(['role' => 'subscriber']);
    }

    /**
     * Create a subscriber, then assign the FluentCart shop role directly
     * (bypasses `PermissionManager::attachRole()` so the test can exercise
     * edge cases like unknown roles without the guard rejecting them).
     */
    public static function createWithShopRole(string $shopRole): int
    {
        $userId = WPUserFactory::create(['role' => 'subscriber']);
        update_user_meta($userId, '_fluent_cart_admin_role', $shopRole);
        $wpUser = get_user_by('ID', $userId);
        if ($wpUser && !$wpUser->has_cap(PermissionManager::ADMIN_CAP)) {
            $wpUser->add_cap(PermissionManager::ADMIN_CAP);
        }
        return $userId;
    }
}
