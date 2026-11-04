<?php

// Functional suite bootstrap. Runs after WPLoader has booted WordPress + plugin.

// Fake Pro active so non-admin shop roles (manager/worker/accountant) receive
// permissions through PermissionManager::getUserPermissions(). Without this the
// permission service early-returns [] for non-administrator users and every
// role-gated route returns 403.
if (!defined('FLUENTCART_PRO_PLUGIN_VERSION')) {
    define('FLUENTCART_PRO_PLUGIN_VERSION', '99.0.0-functional-test');
}

// Trigger database migrations since activation hooks don't fire in tests.
\FluentCart\Database\DBMigrator::migrate();
