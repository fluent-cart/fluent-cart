<?php

namespace FluentCart\App\Modules\MCP\Support;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Services\Permission\PermissionManager;

/**
 * Maps MCP abilities to FluentCart's existing capability model. The MCP user
 * IS a WordPress user with a FluentCart shop role (super_admin / manager /
 * worker / accountant), so we never invent a parallel permission system — we
 * reuse PermissionManager, the same check the admin REST routes use.
 *
 * Two layers:
 *   - transport(): can this user reach the FluentCart MCP endpoint at all?
 *   - can()/canAny(): per-ability permission_callback gating.
 *
 * Annotations are UX hints only; THIS is the enforcement boundary.
 */
class PermissionGate
{
    /** Per-ability check. Used inside permission_callback closures. */
    public static function can($permission)
    {
        return PermissionManager::hasPermission($permission);
    }

    /** True if the user holds ANY of the given capabilities. */
    public static function canAny(array $permissions)
    {
        return PermissionManager::hasAnyPermission($permissions);
    }

    /**
     * Transport gate for the `fluent-cart` server. Reaching the endpoint at all
     * requires (a) the feature is enabled and (b) the user holds at least a
     * read-level FluentCart role. The adapter's default is merely
     * current_user_can('read'), which is too loose for commerce data.
     *
     * Per-ability permission_callback still runs on top — a viewer who reaches
     * the endpoint still can't refund or mutate.
     */
    public static function transport($request = null)
    {
        if (!self::isEnabled()) {
            return new \WP_Error(
                'fluent_cart_mcp_disabled',
                __('The FluentCart MCP server is disabled. Enable it in Settings → MCP.', 'fluent-cart')
            );
        }

        if (!is_user_logged_in()) {
            return new \WP_Error(
                'fluent_cart_mcp_unauthorized',
                __('Authentication required to access the FluentCart MCP server.', 'fluent-cart')
            );
        }

        if (!PermissionManager::hasAnyPermission(self::readRoleCaps())) {
            return new \WP_Error(
                'fluent_cart_mcp_forbidden',
                __('Your account does not have FluentCart access.', 'fluent-cart')
            );
        }

        return true;
    }

    /**
     * Any one of these means "has at least a FluentCart role." Kept as a method
     * (not a const) so it can grow without touching call sites.
     */
    public static function readRoleCaps()
    {
        return [
            'dashboard_stats/view',
            'orders/view',
            'products/view',
            'customers/view',
            'reports/view',
            'subscriptions/view',
            'coupons/view',
        ];
    }

    /** Write-level caps — used by the optional two-server (admin) hardening. */
    public static function writeRoleCaps()
    {
        return [
            'orders/manage',
            'orders/manage_statuses',
            'orders/can_refund',
            'products/edit',
            'customers/manage',
            'coupons/manage',
            'subscriptions/manage',
        ];
    }

    /**
     * The master on/off switch. Ships OFF; enabled from Settings → Features &
     * addon → MCP.
     *
     * Stored under the `mcp` key of the shared `fluent_cart_modules_settings`
     * option (autoloaded, so reading it here costs no extra query on init —
     * unlike a dedicated meta option, which the boot guard would query on every
     * request). Persisted via setEnabled().
     */
    public static function isEnabled()
    {
        return ModuleSettings::isActive('mcp');
    }

    /**
     * Persist the master switch into the shared modules blob. Reads the RAW
     * option (not the defaults-merged view) and rewrites only the `mcp` key so
     * no other module's settings are touched.
     */
    public static function setEnabled($enabled)
    {
        // Defense in depth: enabling MCP opens the whole tool surface. The REST
        // route is is_super_admin-gated, but the fluent_kit/mcp_toggle_handlers
        // path delegates auth to an external plugin — so re-check here. Both
        // callers run in an admin request context (no CLI/system toggle exists).
        if (!current_user_can('manage_options')) {
            return false;
        }

        $settings = get_option(ModuleSettings::MODULE_SETTINGS_OPTION, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $mcp = (isset($settings['mcp']) && is_array($settings['mcp'])) ? $settings['mcp'] : [];
        $mcp['active'] = $enabled ? 'yes' : 'no';
        $settings['mcp'] = $mcp;

        ModuleSettings::saveSettings($settings);

        return (bool) $enabled;
    }
}
