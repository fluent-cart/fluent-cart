<?php

namespace FluentCart\App\Services\Permission;

use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\User;

class PermissionManager
{

    static $metaKey = '_fluent_cart_admin_role';
    public const ADMIN_CAP = 'fluent_cart_admin';

    public static function getAllRoles(): array
    {

        $allRoles = [
            'super_admin' => [
                'title'        => __('Super Admin', 'fluent-cart'),
                'descriptions' => __('With All Permissions', 'fluent-cart'),
                'permissions'  => array_keys(self::getAllPermissions())
            ],
            'manager'     => [
                'title'        => __('Manager', 'fluent-cart'),
                'descriptions' => __('With All Permissions Except Sensitive Settings', 'fluent-cart'),
                'permissions'  => [
                    'store/settings',
                    'products/view',
                    'products/create',
                    'products/edit',
                    'products/delete',
                    'customers/view',
                    'customers/manage',
                    'customers/delete',
                    'orders/view',
                    'orders/manage_statuses',
                    'orders/can_refund',
                    'orders/manage',
                    'orders/export',
                    'orders/delete',
                    'subscriptions/view',
                    'subscriptions/manage',
                    'subscriptions/delete',
                    'licenses/view',
                    'licenses/manage',
                    'licenses/delete',
                    'coupons/view',
                    'coupons/manage',
                    'coupons/delete',
                    'reports/view',
                    'reports/export',
                    'integrations/view',
                    'integrations/manage',
                    'integrations/delete'
                ]
            ],
            'worker'      => [
                'title'        => __('Worker', 'fluent-cart'),
                'descriptions' => __('View Access for products, customers, coupons, integretions. Manage Access for Order Statuses', 'fluent-cart'),
                'permissions'  => [
                    'products/view',
                    'customers/view',
                    'orders/view',
                    'orders/manage_statuses',
                    'subscriptions/view',
                    'licenses/view',
                    'coupons/view',
                    'coupons/manage',
                    'integrations/view'
                ]
            ],
            'accountant'  => [
                'title'        => __('Accountant', 'fluent-cart'),
                'descriptions' => __('View Access for products, customers, orders, subscriptions, licenses, coupons, reports and integrations', 'fluent-cart'),
                'permissions'  => [
                    'orders/view',
                    'orders/export',
                    'reports/view',
                    'reports/export',
                    'products/view',
                    'customers/view',
                    'subscriptions/view',
                    'licenses/view',
                    'coupons/view',
                    'integrations/view'
                ]
            ]
        ];

        return apply_filters('fluent_cart/permission/all_roles', $allRoles, []);
    }

    public static function getUserPermissions($userId = false)
    {
        if ($userId === false) {
            $userId = get_current_user_id();
        }

        $user = get_user_by('ID', $userId);

        if (user_can($user, 'manage_options')) {
            $allPermissions = array_keys(self::getAllPermissions());
            $allPermissions[] = 'is_super_admin';

            return $allPermissions;
        }

        if (!App::isProActive()) {
            return [];
        }

        $currentRole = get_user_meta($userId, static::$metaKey, true);


        if (empty($currentRole)) {
            return [];
        }

        $allRoles = self::getAllRoles();

        $permissions = Arr::get($allRoles, $currentRole . '.permissions', []);

        return $permissions;
    }

    public static function hasPermission($permissions, $userId = false): bool
    {
        if ($userId === false) {
            $userId = get_current_user_id();
        }


        if (!$userId) {
            return false;
        }

        $userPermissions = self::getUserPermissions($userId);
        $permissions = (array)$permissions;

        return array_intersect($userPermissions, $permissions) || in_array('super_admin', $userPermissions);
    }

    public static function hasAnyPermission(array $permissions, $userId = false): bool
    {
        if ($userId === false) {
            $userId = get_current_user_id();
        }


        if (!$userId) {
            return false;
        }

        $userPermissions = self::getUserPermissions($userId);

        return !empty(array_intersect($userPermissions, $permissions)) || in_array('is_super_admin', $userPermissions);
    }


    public static function getShopRole($userId)
    {
        $user = get_user_by('ID', $userId);

        if (user_can($user, 'manage_options')) {
            return 'super_admin';
        }

        $currentRole = get_user_meta($userId, static::$metaKey, true);

        if (empty($currentRole)) {
            return false;
        }

        return $currentRole;
    }

    public static function getAllPermissions(): array
    {
        return [
            'store/settings'         => __('Store Settings', 'fluent-cart'),
            'store/sensitive'        => __('Sensitive Settings', 'fluent-cart'),
            'products/view'          => __('View Products', 'fluent-cart'),
            'products/create'        => __('Create Products', 'fluent-cart'),
            'products/edit'          => __('Edit Products', 'fluent-cart'),
            'products/delete'        => __('Delete Products', 'fluent-cart'),
            'customers/view'         => __('View Customers', 'fluent-cart'),
            'customers/manage'       => __('Manage Customers', 'fluent-cart'),
            'customers/delete'       => __('Delete Customers', 'fluent-cart'),
            'orders/view'            => __('View Orders', 'fluent-cart'),
            'orders/create'          => __('Create Orders', 'fluent-cart'),
            'orders/manage_statuses' => __('Manage Order Statuses', 'fluent-cart'),
            'orders/manage'          => __('Manage Orders', 'fluent-cart'),
            'orders/can_refund'      => __('Can Refund Orders', 'fluent-cart'),
            'orders/export'          => __('Export Orders', 'fluent-cart'),
            'orders/delete'          => __('Delete Orders', 'fluent-cart'),
            'subscriptions/view'     => __('View Subscriptions', 'fluent-cart'),
            'subscriptions/manage'   => __('Manage Subscriptions', 'fluent-cart'),
            'subscriptions/delete'   => __('Delete Subscriptions', 'fluent-cart'),
            'licenses/view'          => __('View Licenses', 'fluent-cart'),
            'licenses/manage'        => __('Manage Licenses', 'fluent-cart'),
            'licenses/delete'        => __('Delete Licenses', 'fluent-cart'),
            'coupons/view'           => __('View Coupons', 'fluent-cart'),
            'coupons/manage'         => __('Manage Coupons', 'fluent-cart'),
            'coupons/delete'         => __('Delete Coupons', 'fluent-cart'),
            'reports/view'           => __('View Reports', 'fluent-cart'),
            'reports/export'         => __('Export Reports', 'fluent-cart'),
            'integrations/view'      => __('View Integrations', 'fluent-cart'),
            'integrations/manage'    => __('Manage Integrations', 'fluent-cart'),
            'integrations/delete'    => __('Delete Integrations', 'fluent-cart'),
            'labels/view'            => __('View Labels', 'fluent-cart'),
            'labels/manage'          => __('Manage Labels', 'fluent-cart'),
            'labels/delete'          => __('Delete Labels', 'fluent-cart'),
            'dashboard_stats/view'   => __('View Dashboard Stats', 'fluent-cart')
        ];
    }

    /**
     * Get all users that have a FluentCart admin role assigned
     *
     * @return array
     */
    public static function getUsersWithShopRole(): array
    {
        $users = User::query()
            ->select(['users.*', 'usermeta.meta_value as shop_role'])
            ->join('usermeta', function ($join) {
                $join->on('users.ID', '=', 'usermeta.user_id')
                    ->where('usermeta.meta_key', '=', static::$metaKey)
                    ->whereNotNull('usermeta.meta_value')//->where('usermeta.meta_value', '!=', '')
                ;
            })
            ->get();

        // get all roles
        $allRoles = self::getAllRoles();

        if ($users->isEmpty()) {
            return [];
        }

        return $users->map(function ($user) use ($allRoles) {
            // Check if the shop_role exists in allRoles
            $role = $allRoles[$user->shop_role] ?? [];

            return [
                'id'               => (int)$user->ID,
                'email'            => $user->user_email,
                'display_name'     => $user->display_name,
                'username'         => $user->user_login,
                'shop_role'        => $user->shop_role,
                'description'      => $role['descriptions'] ?? '',
                'registered_at'    => $user->user_registered,
                'role_permissions' => self::getUserPermissions($user->ID)
            ];
        })->toArray();
    }

    public static function attachRole($userId, $role)
    {
        $wpUser = get_user_by('ID', $userId);

        if (!$wpUser) {
            return new \WP_Error('user_not_found', __('User not found', 'fluent-cart'));
        }
        if (user_can($wpUser, 'manage_options')) {
            return new \WP_Error('super_admin', __('The user already have all the accesses as part of Administrator Role', 'fluent-cart'));
        }
        // Assign the capability directly to the user
        if (!$wpUser->has_cap(static::ADMIN_CAP)) {
            $wpUser->add_cap(static::ADMIN_CAP);
        }

        return update_user_meta($userId, static::$metaKey, $role);
    }

    public static function detachRole($userId, $role)
    {
        $wpUser = get_user_by('ID', $userId);

        if (!$wpUser) {
            return new \WP_Error('user_not_found', __('User not found', 'fluent-cart'));
        }
        if (user_can($wpUser, 'manage_options')) {
            return new \WP_Error('super_admin', __('The user already have all the accesses as part of Administrator Role', 'fluent-cart'));
        }

        if (!$wpUser->has_cap(static::ADMIN_CAP)) {
            $wpUser->remove_cap(static::ADMIN_CAP);
        }

        return delete_user_meta($userId, static::$metaKey);
    }

    public static function userCan($permission): bool
    {
        $currentUser = Helper::getCurrentUser();
        return $currentUser && $currentUser->userCan($permission);
    }
}


