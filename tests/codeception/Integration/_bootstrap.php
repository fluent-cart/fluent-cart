<?php

// This file runs AFTER WordPress and plugins are loaded by WPLoader.
//
// Reset committed FluentCart rows to match the freshly (re)installed WP
// fixtures. WPLoader reinstalls the WordPress tables on every suite boot —
// post/user IDs restart from 1 — but committed fct_* rows survive across
// runs, so a stale row (e.g. fct_product_details) can collide with a reused
// post ID and shadow the row a test just created (PR #2336 review blocker).
// Truncation runs BEFORE the migration call below, so the production
// migrations restore their canonical seed rows (e.g. the mandatory
// 'standard' tax class) on the clean state — truncating after migrate()
// would erase them and hide a fresh-install seeding regression.
// Runs strictly after the TC-047 destructive-DB guard has validated
// FCT_TEST_DB_ALLOW at MODULE_INIT.
global $wpdb;
$fctTables = $wpdb->get_col(
    $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'fct_') . '%')
);
if ($fctTables) {
    $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
    foreach ($fctTables as $table) {
        $wpdb->query("TRUNCATE TABLE `{$table}`");
    }
    $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
}

// Trigger database migrations since activation hooks don't fire in tests.
\FluentCart\Database\DBMigrator::migrate();
