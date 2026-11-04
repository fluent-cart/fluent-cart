<?php

namespace Tests\Support\Traits;

/**
 * Setup helper for permissions tests that need to model FluentCart shop role
 * permission shaping for the duration of a single test method.
 *
 * Hooks fluent_cart/permission/all_roles to mutate role permission lists.
 * Each public method returns a remover callback so callers can finally{}
 * wrap the cleanup. Builds no model state — purely permission-shaping setup.
 */
trait AdjustsRolePermissions
{
    /**
     * Strip a single permission from a role's permission list for the rest of
     * the request. Returns a callback that removes the filter.
     */
    protected function stripPermissionFromRole(string $role, string $permission): callable
    {
        return $this->stripPermissionsFromRole($role, [$permission]);
    }

    /**
     * Strip multiple permissions from a role's permission list for the rest of
     * the request. Returns a callback that removes the filter.
     */
    protected function stripPermissionsFromRole(string $role, array $permissions): callable
    {
        $strip = static function ($roles) use ($role, $permissions) {
            if (isset($roles[$role]['permissions']) && is_array($roles[$role]['permissions'])) {
                $roles[$role]['permissions'] = array_values(
                    array_diff($roles[$role]['permissions'], $permissions)
                );
            }
            return $roles;
        };
        add_filter('fluent_cart/permission/all_roles', $strip);

        return static function () use ($strip) {
            remove_filter('fluent_cart/permission/all_roles', $strip);
        };
    }

    /**
     * Replace a role's permission list with the given set. Returns a callback
     * that removes the filter. Use when the spec narrates a role with an
     * unusual subset of permissions ("worker whose permissions are adjusted to
     * grant only X").
     */
    protected function setRolePermissions(string $role, array $permissions): callable
    {
        $set = static function ($roles) use ($role, $permissions) {
            if (isset($roles[$role]) && is_array($roles[$role])) {
                $roles[$role]['permissions'] = array_values($permissions);
            }
            return $roles;
        };
        add_filter('fluent_cart/permission/all_roles', $set);

        return static function () use ($set) {
            remove_filter('fluent_cart/permission/all_roles', $set);
        };
    }
}
