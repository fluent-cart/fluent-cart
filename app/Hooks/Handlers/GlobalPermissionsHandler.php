<?php

namespace FluentCart\App\Hooks\Handlers;

class GlobalPermissionsHandler
{
    public static $customCapabilities = array();

    public function register()
    {
        add_filter('fluent_cart_register_permission', function ($moduleName, $permissions) {
            $customPermissions = array();
            foreach ($permissions as $permission) {
                $capability = 'fluent_cart/permissions/' . $moduleName . '/' . $permission;
                $customPermissions[$capability] = false;
            }
            self::$customCapabilities = $customPermissions;
        }, 10, 2);
    }
}
