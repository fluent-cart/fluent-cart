<?php
/**
 * LINT FIXTURE — INTENTIONALLY BROKEN. NOT PRODUCTION CODE. NEVER LOADED.
 *
 * These examples preserve the raw-SQL prefix bug class this suite exists to
 * catch. `tests/lint/raw-sql-prefix.php` MUST flag them. If this file ever
 * passes the linter, the linter has regressed — fix the linter, not this file.
 *
 * Proof-of-catch:
 *   php tests/lint/raw-sql-prefix.php tests/lint/fixtures   # must exit 1
 */

// Bug 1 — a qualified table reference inside raw() omits the WP prefix.
$countRows = $db->table('__PLUGIN_TABLE_PREFIX__order_items')
    ->leftJoin('__PLUGIN_TABLE_PREFIX__orders', '__PLUGIN_TABLE_PREFIX__orders.id', '=', '__PLUGIN_TABLE_PREFIX__order_items.order_id')
    ->select([
        $db->raw('COUNT(*) as total_count'),
        $db->raw("SUM(CASE WHEN __PLUGIN_TABLE_PREFIX__orders.status = 'paid' THEN 1 ELSE 0 END) as paid_count")
    ])
    ->get();

// Bug 2 — selectRaw() bypasses the query grammar in the same way.
$topOrders = Order::join('__PLUGIN_TABLE_PREFIX__customers', function ($join) {
})
    ->selectRaw('__PLUGIN_TABLE_PREFIX__orders.customer_id, __PLUGIN_TABLE_PREFIX__customers.first_name, COUNT(*) as order_count')
    ->get();

// Bug 2b — the conditional aggregate variant.
$globalAggregate = Customer::selectRaw("SUM(CASE WHEN __PLUGIN_TABLE_PREFIX__customers.status = 'active' THEN 1 ELSE 0 END) as active")
    ->first();

// Bug 3 — a bare table reference has no trailing dot for the old regex.
$bareTable = Order::selectRaw('SELECT id FROM __PLUGIN_TABLE_PREFIX__orders WHERE 1=0')
    ->get();

// Bug 4 — multi-line/heredoc raw calls are invisible to a line-by-line scan.
$multiLine = Order::selectRaw(
    <<<SQL
SELECT __PLUGIN_TABLE_PREFIX__orders.id
FROM __PLUGIN_TABLE_PREFIX__orders
WHERE 1=0
SQL
)->get();

// --- The following are CORRECT and must NOT be flagged (false-positive guard) ---

global $wpdb;
$t = $wpdb->prefix . '__PLUGIN_TABLE_PREFIX__orders';
$ok1 = $db->raw("SUM(CASE WHEN `{$t}`.`status` = 'paid' THEN 1 ELSE 0 END) as c");

// Bare column names on a single-table query are fine.
$ok2 = $db->raw("SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid");
$ok3 = $db->raw('COUNT(*) as total');

/**
 * NOT a violation — the prefix is concatenated immediately before the literal,
 * so the variable sits outside the quotes. If the lint starts reporting this
 * line, the concatenation check has regressed.
 */
function fixtureConcatenatedPrefixIsFine()
{
    global $wpdb;
    $tablePrefix = $wpdb->prefix;

    return $db->raw('COUNT(' . $tablePrefix . '__PLUGIN_TABLE_PREFIX__orders.id) AS count');
}
