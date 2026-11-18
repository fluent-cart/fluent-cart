<?php

namespace Tests\Support\Extension;

use Codeception\Events;
use Codeception\Extension;
use Tests\Support\Guard\DestructiveDbGuard;

/**
 * Deletes rows a test committed, so they cannot leak into later tests.
 *
 * Production code that commits its own transaction (refund lock, renewals,
 * customer recovery) also commits wp-browser's per-test wrapper, because
 * MySQL has no nested transactions. TEST_AFTER fires after the rollback, so
 * any row above the TEST_BEFORE watermark was committed and is removed.
 * Same mechanism as Traits\HealsCommittedRows, applied to every test.
 */
class HealCommittedRowsExtension extends Extension
{
    public static array $events = [
        Events::TEST_BEFORE => 'captureWatermarks',
        Events::TEST_AFTER  => 'healCommittedRows',
    ];

    /** @var array<string, string>|null table => auto-increment column */
    private static $tables = null;

    /** @var array<string, int> */
    private $watermarks = [];

    public function captureWatermarks(): void
    {
        $this->watermarks = $this->readMaxIds();
    }

    public function healCommittedRows(): void
    {
        global $wpdb;

        if (!$this->watermarks || !isset($wpdb)) {
            return;
        }

        $dirty = [];
        foreach ($this->readMaxIds() as $table => $maxId) {
            if (isset($this->watermarks[$table]) && $maxId > $this->watermarks[$table]) {
                $dirty[$table] = $this->watermarks[$table];
            }
        }
        $this->watermarks = [];

        if (!$dirty) {
            return;
        }

        DestructiveDbGuard::assertDbAllowed(
            defined('DB_HOST') ? DB_HOST : '',
            defined('DB_NAME') ? DB_NAME : '',
            static::class . '::healCommittedRows()'
        );

        foreach ($dirty as $table => $watermark) {
            $column = self::$tables[$table];
            $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE `{$column}` > %d", $watermark));
        }
        $wpdb->query('COMMIT');
        wp_cache_flush();
    }

    /** @return array<string, int> */
    private function readMaxIds(bool $retried = false): array
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            return [];
        }

        $tables = $this->tables($retried);
        if (!$tables) {
            return [];
        }

        $selects = [];
        foreach ($tables as $table => $column) {
            $selects[] = "SELECT '{$table}' AS t, COALESCE(MAX(`{$column}`), 0) AS m FROM `{$table}`";
        }

        // Database tests drop and recreate tables; reload the list once on a miss.
        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results(implode(' UNION ALL ', $selects));
        $failed = $wpdb->last_error !== '';
        $wpdb->suppress_errors($suppress);

        if ($failed) {
            return $retried ? [] : $this->readMaxIds(true);
        }

        $maxIds = [];
        foreach ((array) $rows as $row) {
            $maxIds[$row->t] = (int) $row->m;
        }

        return $maxIds;
    }

    /** @return array<string, string> */
    private function tables(bool $reload): array
    {
        global $wpdb;

        if (self::$tables === null || $reload) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT c.TABLE_NAME AS t, c.COLUMN_NAME AS col
                   FROM information_schema.COLUMNS c
                   JOIN information_schema.TABLES tb
                     ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME
                  WHERE c.TABLE_SCHEMA = DATABASE()
                    AND tb.TABLE_TYPE = 'BASE TABLE'
                    AND c.TABLE_NAME LIKE %s
                    AND c.EXTRA LIKE %s",
                $wpdb->esc_like($wpdb->prefix) . '%',
                '%auto_increment%'
            ));

            self::$tables = [];
            foreach ((array) $rows as $row) {
                self::$tables[$row->t] = $row->col;
            }
        }

        return self::$tables;
    }
}
