<?php

namespace Tests\Support\Isolation;

use Tests\Support\Guard\DestructiveDbGuard;

/**
 * TC-048 — committed-state database snapshot/restore for the Functional suite.
 *
 * Captures every BASE TABLE in the test database once (right after WPLoader
 * install + suite bootstrap migrations) into `zzfcsnap_*` copies, then before
 * each test restores any table whose contents OR auto_increment drifted from
 * that baseline. This covers what `resetFluentCartTables()` never did:
 * wp_posts, wp_postmeta, wp_users, wp_usermeta, wp_options (transients),
 * wp_terms — every table checkout/cart/product flows write to.
 *
 * Deliberately NOT transaction-based: Phase 3 webhook tests (TC-301..307)
 * dispatch handlers in a separate PHP subprocess, and a child DB connection
 * can neither see the parent's uncommitted fixtures nor have its writes
 * rolled back by a parent transaction. Restoration therefore always operates
 * on COMMITTED state, so the same mechanism serves in-process REST dispatch
 * today and subprocess webhook ingress later.
 *
 * Per-test cost is two statements when nothing drifted — one batched
 * `CHECKSUM TABLE a, b, ...` and one information_schema read that doubles as
 * the table-existence check — measured at ~45ms against the 51-table schema.
 * Only drifted tables pay a restore, via DELETE + INSERT ... SELECT rather
 * than TRUNCATE (TRUNCATE is DDL, ~60x slower per table, and its
 * auto_increment reset is redundant: the counter is set explicitly below).
 * auto_increment drift matters even with identical rows: an
 * insert-then-delete test would otherwise shift the IDs every later test's
 * fixtures receive, which is exactly the order-dependence TC-048 exists to
 * kill.
 */
class DbStateSnapshot
{
    /**
     * Prefix for snapshot table copies. Chosen so it can never collide with
     * the suite's destructive globs: `resetFluentCartTables()` truncates
     * `{$wpdb->prefix}fct_%` and WPLoader owns `{$wpdb->prefix}%` — this
     * prefix matches neither.
     */
    private const SNAPSHOT_PREFIX = 'zzfcsnap_';

    /** @var bool */
    private $captured = false;

    /** @var array<string, string|null> live table => baseline checksum */
    private $baselineChecksums = [];

    /** @var array<string, int|null> live table => baseline auto_increment */
    private $baselineAutoIncrements = [];

    /** @var array<string, true> tables that HAVE an auto_increment column */
    private $aiColumns = [];

    public function isCaptured(): bool
    {
        return $this->captured;
    }

    /**
     * Snapshot every base table in the test database. Call once per PHP
     * process, after WordPress + plugin migrations have run.
     */
    public function capture(\wpdb $wpdb): void
    {
        $this->assertDbAllowed('DbStateSnapshot::capture()');

        $this->dropSnapshotTables($wpdb);
        $this->normalizeBaseline($wpdb);

        $schema = $this->schemaState($wpdb);
        $tables = array_keys($schema);
        if ($tables === []) {
            throw new \RuntimeException(
                'DbStateSnapshot::capture() found no base tables — WPLoader install or migrations did not run.'
            );
        }

        foreach ($tables as $table) {
            $snapshot = self::SNAPSHOT_PREFIX . $table;
            $this->queryOrFail($wpdb, "CREATE TABLE `{$snapshot}` LIKE `{$table}`");
            $this->queryOrFail($wpdb, "INSERT INTO `{$snapshot}` SELECT * FROM `{$table}`");
        }

        $this->aiColumns = $this->detectAiColumns($wpdb);

        $this->baselineChecksums = $this->checksums($wpdb, $tables);
        foreach ($tables as $table) {
            $this->baselineAutoIncrements[$table] = $this->effectiveAi($table, $schema[$table]);
        }
        $this->captured = true;
    }

    /**
     * Restore every table that drifted from the captured baseline. Returns
     * the list of restored table names (empty when the DB was already clean).
     *
     * @return string[]
     */
    public function restore(\wpdb $wpdb): array
    {
        if (!$this->captured) {
            throw new \RuntimeException('DbStateSnapshot::restore() called before capture().');
        }

        $this->assertDbAllowed('DbStateSnapshot::restore()');

        $baseline = array_keys($this->baselineChecksums);
        $schema   = $this->schemaState($wpdb);
        $current  = array_keys($schema);

        // Tables created after capture (e.g. a migration triggered mid-test)
        // are foreign to the baseline — drop them. schemaState() hides the
        // snapshot prefix, so a stray table a test created UNDER that prefix
        // would evade both this diff and the checksum sweep — catch those
        // explicitly by dropping any snapshot-prefixed table that does not
        // back a baseline table.
        foreach (array_diff($current, $baseline) as $table) {
            $this->queryOrFail($wpdb, "DROP TABLE `{$table}`");
        }

        $snapshots = $wpdb->get_col(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like(self::SNAPSHOT_PREFIX) . '%'
            )
        );
        foreach ((array) $snapshots as $snapshotTable) {
            $backs = substr($snapshotTable, strlen(self::SNAPSHOT_PREFIX));
            if (!in_array($backs, $baseline, true)) {
                $this->queryOrFail($wpdb, "DROP TABLE `{$snapshotTable}`");
            }
        }

        // Tables dropped after capture are recreated empty, then repopulated
        // below via the normal dirty path (their checksum cannot match).
        $recreated = array_diff($baseline, $current);
        foreach ($recreated as $table) {
            $snapshot = self::SNAPSHOT_PREFIX . $table;
            $this->queryOrFail($wpdb, "CREATE TABLE `{$table}` LIKE `{$snapshot}`");
            $schema[$table] = null;
        }

        $checksums = $this->checksums($wpdb, $baseline);

        $dirty = [];
        foreach ($baseline as $table) {
            if (in_array($table, $recreated, true)
                || ($checksums[$table] ?? null) !== $this->baselineChecksums[$table]
                || $this->effectiveAi($table, $schema[$table] ?? null) !== $this->baselineAutoIncrements[$table]
            ) {
                $dirty[] = $table;
            }
        }

        if ($dirty === []) {
            return [];
        }

        $this->queryOrFail($wpdb, 'SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($dirty as $table) {
                $snapshot = self::SNAPSHOT_PREFIX . $table;
                $this->queryOrFail($wpdb, "DELETE FROM `{$table}`");
                $this->queryOrFail($wpdb, "INSERT INTO `{$table}` SELECT * FROM `{$snapshot}`");

                // DELETE leaves the auto_increment counter wherever the test
                // pushed it, so reset every dirty AI table unconditionally —
                // information_schema's counter value is lazily maintained and
                // reads NULL right after TRUNCATE and can lag behind the live
                // counter, so "compare then maybe skip" would skip real drift.
                // InnoDB accepts lowering the counter as long as the target
                // exceeds max(id) — the baseline value always does, being the
                // counter those exact rows produced.
                $targetAi = $this->baselineAutoIncrements[$table];
                if ($targetAi !== null) {
                    $this->queryOrFail($wpdb, "ALTER TABLE `{$table}` AUTO_INCREMENT = " . (int) $targetAi);
                }
            }
        } finally {
            // A throw above must not leave FK checks disabled for the
            // session — the connection outlives this test.
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $dirty;
    }

    /**
     * One information_schema read serving two purposes: the list of live
     * base tables (snapshot copies and views excluded) and each table's
     * current auto_increment counter (null when it has none).
     *
     * @return array<string, int|null> table => auto_increment
     */
    private function schemaState(\wpdb $wpdb): array
    {
        // MySQL 8 caches information_schema table stats for
        // information_schema_stats_expiry seconds (default 86400) — stale
        // auto_increment values would blind drift detection. MariaDB has no
        // such variable; suppress the error there and read directly.
        $suppressing = $wpdb->suppress_errors(true);
        $wpdb->query('SET SESSION information_schema_stats_expiry = 0');
        $wpdb->suppress_errors($suppressing);

        $rows = $wpdb->get_results(
            "SELECT TABLE_NAME AS table_name, AUTO_INCREMENT AS auto_increment
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'",
            ARRAY_A
        );

        if (!is_array($rows) || $rows === []) {
            throw new \RuntimeException(
                'information_schema returned no tables for the current database. Last DB error: ' . $wpdb->last_error
            );
        }

        $schema = [];
        foreach ($rows as $row) {
            $table = (string) $row['table_name'];
            if (strpos($table, self::SNAPSHOT_PREFIX) === 0) {
                continue;
            }
            $schema[$table] = $row['auto_increment'] === null ? null : (int) $row['auto_increment'];
        }

        ksort($schema);

        return $schema;
    }

    /**
     * One batched CHECKSUM TABLE statement for all tables.
     *
     * @param string[] $tables
     * @return array<string, string|null>
     */
    private function checksums(\wpdb $wpdb, array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $quoted = implode(', ', array_map(static function ($table) {
            return "`{$table}`";
        }, $tables));

        $rows = $wpdb->get_results("CHECKSUM TABLE {$quoted}", ARRAY_A);
        if (!is_array($rows) || count($rows) !== count($tables)) {
            throw new \RuntimeException(
                'CHECKSUM TABLE returned ' . (is_array($rows) ? count($rows) : 'no') . ' rows for '
                . count($tables) . ' tables. Last DB error: ' . $wpdb->last_error
            );
        }

        $checksums = [];
        foreach ($rows as $row) {
            // The Table column comes back as `database.table` — strip the schema.
            $name = (string) $row['Table'];
            $dot  = strrpos($name, '.');
            if ($dot !== false) {
                $name = substr($name, $dot + 1);
            }
            $checksums[$name] = isset($row['Checksum']) ? (string) $row['Checksum'] : null;
        }

        return $checksums;
    }

    /**
     * The baseline must be a CLEAN install, not "whatever the previous run
     * left behind". WPLoader reinstalls WordPress core tables fresh every
     * run, but plugin-owned tables survive between runs: capture fires
     * before the first Cest's `_before`, so `fct_*` still holds the previous
     * run's final fixtures, and Action Scheduler's queue accumulates junk
     * rows forever (525 stale actions observed). Left in the baseline those
     * rows would be reinstated on every restore — machine-dependent state
     * plus pointless per-test churn on the busiest tables.
     */
    private function normalizeBaseline(\wpdb $wpdb): void
    {
        foreach (['fct_', 'actionscheduler_'] as $group) {
            $tables = $wpdb->get_col(
                $wpdb->prepare(
                    'SHOW TABLES LIKE %s',
                    $wpdb->esc_like($wpdb->prefix . $group) . '%'
                )
            );

            $this->queryOrFail($wpdb, 'SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ((array) $tables as $table) {
                    $this->queryOrFail($wpdb, "TRUNCATE TABLE `{$table}`");
                }
            } finally {
                $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
    }

    /**
     * Which tables have an auto_increment column at all. Needed because
     * information_schema reports AUTO_INCREMENT = NULL both for "no such
     * column" AND for a freshly TRUNCATEd counter — without the column map
     * those are indistinguishable and truncated-at-capture tables would
     * never get their counters restored (the exact bug this fixes: customer
     * IDs climbed 1/test across the whole run instead of resetting to 1).
     *
     * @return array<string, true>
     */
    private function detectAiColumns(\wpdb $wpdb): array
    {
        $rows = $wpdb->get_col(
            "SELECT TABLE_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE '%auto_increment%'"
        );

        $map = [];
        foreach ((array) $rows as $table) {
            $map[(string) $table] = true;
        }

        return $map;
    }

    /**
     * Normalize a raw information_schema AUTO_INCREMENT value: NULL on a
     * table that HAS an auto_increment column means "counter uninitialized
     * after TRUNCATE", i.e. the next insert gets 1.
     *
     * Known limit: information_schema maintains this value lazily, so a
     * test that inserts rows and deletes them again (leaving both checksum
     * and the LAGGING reported counter at baseline) can slip a drifted
     * counter past detection. Tests that leave any row behind — practically
     * all of them — dirty the checksum and get the unconditional reset in
     * restore().
     */
    private function effectiveAi(string $table, $raw): ?int
    {
        if (!isset($this->aiColumns[$table])) {
            return null;
        }

        return $raw === null ? 1 : (int) $raw;
    }

    private function dropSnapshotTables(\wpdb $wpdb): void
    {
        $stale = $wpdb->get_col(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like(self::SNAPSHOT_PREFIX) . '%'
            )
        );

        foreach ((array) $stale as $table) {
            $this->queryOrFail($wpdb, "DROP TABLE `{$table}`");
        }
    }

    private function queryOrFail(\wpdb $wpdb, string $sql): void
    {
        $result = $wpdb->query($sql);
        if ($result === false) {
            throw new \RuntimeException(
                'DbStateSnapshot query failed: ' . $sql . ' — ' . $wpdb->last_error
            );
        }
    }

    private function assertDbAllowed(string $context): void
    {
        DestructiveDbGuard::assertDbAllowed(
            defined('DB_HOST') ? DB_HOST : '',
            defined('DB_NAME') ? DB_NAME : '',
            'TC-048 ' . $context
        );
    }
}
