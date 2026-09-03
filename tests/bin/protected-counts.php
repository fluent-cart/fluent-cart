<?php
/**
 * Print the current protected-table counts for diagnostics.
 *
 * Tier enforcement uses a run-start capture in the same WP process, not
 * machine-specific constants.
 */

$config = require dirname(__DIR__) . '/suite.config.php';
require_once dirname(__DIR__) . '/lib/protected-tables.php';

$actual = FcProtectedTables::capture(
    array_values($config['protected_tables'])
);
WP_CLI::log('protected counts: ' . FcProtectedTables::format($actual));
