<?php

namespace Tests\Support\Isolation;

/**
 * TC-048 — per-test reset of the in-process WordPress runtime state that
 * neither the DB snapshot nor the object-cache snapshot covers:
 * superglobals, the in-memory role registry, the current user, and the
 * plugin's memoized per-request caches that expose a public reset seam.
 *
 * Function-static caches WITHOUT a reset seam (TESTABILITY-DEBT.md #1, #2)
 * stay out of reach by design — those are seam tickets, not cleanup targets.
 */
class WpRuntimeReset
{
    public static function reset(): void
    {
        $_GET     = [];
        $_POST    = [];
        $_REQUEST = [];
        unset($_SERVER['HTTP_X_WP_NONCE']);

        // wp_options may have been restored underneath the in-memory role
        // registry (tests add caps / roles via $user->add_cap()).
        $GLOBALS['wp_roles'] = new \WP_Roles();

        wp_set_current_user(0);

        if (class_exists(\FluentCart\App\Helpers\Helper::class)) {
            \FluentCart\App\Helpers\Helper::getCurrentUser(true);
        }

        if (class_exists(\FluentCart\Api\ModuleSettings::class)) {
            \FluentCart\Api\ModuleSettings::getAllSettings(false);
        }

        // TESTABILITY-DEBT #2 seam: without this, the first test to resolve
        // a customer pins that customer object for every later test in the
        // process, and results depend on execution order — the exact failure
        // class TC-048 exists to eliminate.
        if (class_exists(\FluentCart\Api\Resource\CustomerResource::class)) {
            \FluentCart\Api\Resource\CustomerResource::resetCurrentCustomerRuntimeCache();
        }

        if (method_exists(\FluentCart\App\Helpers\AddressHelper::class, 'resetRateCache')) {
            \FluentCart\App\Helpers\AddressHelper::resetRateCache();
        }
    }
}
