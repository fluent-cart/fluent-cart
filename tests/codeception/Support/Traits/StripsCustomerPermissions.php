<?php

namespace Tests\Support\Traits;

/**
 * Setup helper for permissions tests that need to model "<role> WITHOUT <permission>" scenarios.
 *
 * Hooks fluent_cart/permission/all_roles to remove a single permission from a role's permission
 * list for the duration of the wrapped action. Returns a remover callback so callers can finally{}
 * wrap the cleanup. Builds no model state — purely permission-shaping setup.
 */
trait StripsCustomerPermissions
{
    /**
     * Strip the given permission from the named role for the rest of the request.
     * Returns a callback that removes the filter.
     */
    protected function stripPermissionFromRole(string $role, string $permission): callable
    {
        $strip = static function ($roles) use ($role, $permission) {
            if (isset($roles[$role]['permissions']) && is_array($roles[$role]['permissions'])) {
                $roles[$role]['permissions'] = array_values(
                    array_diff($roles[$role]['permissions'], [$permission])
                );
            }
            return $roles;
        };
        add_filter('fluent_cart/permission/all_roles', $strip);

        return static function () use ($strip) {
            remove_filter('fluent_cart/permission/all_roles', $strip);
        };
    }
}
