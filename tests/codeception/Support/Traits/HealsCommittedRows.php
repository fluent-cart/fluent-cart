<?php

namespace Tests\Support\Traits;

use Tests\Support\Guard\DestructiveDbGuard;

/**
 * For test classes whose code under test commits its own transaction.
 *
 * MySQL has no nested transactions, so a production beginTransaction()/commit()
 * also commits wp-browser's per-test wrapper and the fixtures survive the
 * rollback, leaking into later tests' counts (e.g. OrderTest's scope tests).
 *
 * setUp: call captureRowWatermarks() to record each table's highest id.
 * tearDown: call healCommittedRows() BEFORE parent::tearDown(). It rolls back
 * first, so only rows that were really committed remain, deletes every row
 * above the watermark and commits the deletes.
 *
 * Covers inserted rows only; a committed UPDATE to a pre-existing row is not
 * reverted, so fixtures should be created by the test itself.
 */
trait HealsCommittedRows
{
    /** @var array<string, string>|null table => auto-increment column, shared per class */
    private static $healTables = null;

    /** @var array<string, int> */
    private $rowWatermarks = [];

    protected function captureRowWatermarks(): void
    {
        $this->rowWatermarks = $this->readMaxIds();
    }

    protected function healCommittedRows(): void
    {
        global $wpdb;

        if (!$this->rowWatermarks) {
            return;
        }

        DestructiveDbGuard::assertDbAllowed(
            defined('DB_HOST') ? DB_HOST : '',
            defined('DB_NAME') ? DB_NAME : '',
            static::class . '::healCommittedRows()'
        );

        $wpdb->query('ROLLBACK');

        $deleted = false;
        foreach ($this->readMaxIds() as $table => $maxId) {
            $watermark = $this->rowWatermarks[$table] ?? 0;
            if ($maxId <= $watermark) {
                continue;
            }
            $column = self::$healTables[$table];
            $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE `{$column}` > %d", $watermark));
            $deleted = true;
        }

        $wpdb->query('COMMIT');
        $this->rowWatermarks = [];

        if ($deleted) {
            wp_cache_flush();
        }
    }

    /** @return array<string, int> */
    private function readMaxIds(): array
    {
        global $wpdb;

        $tables = $this->healTables();
        if (!$tables) {
            return [];
        }

        $selects = [];
        foreach ($tables as $table => $column) {
            $selects[] = "SELECT '{$table}' AS t, COALESCE(MAX(`{$column}`), 0) AS m FROM `{$table}`";
        }

        $maxIds = [];
        foreach ((array) $wpdb->get_results(implode(' UNION ALL ', $selects)) as $row) {
            $maxIds[$row->t] = (int) $row->m;
        }

        return $maxIds;
    }

    /** @return array<string, string> */
    private function healTables(): array
    {
        global $wpdb;

        if (self::$healTables === null) {
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

            self::$healTables = [];
            foreach ((array) $rows as $row) {
                self::$healTables[$row->t] = $row->col;
            }
        }

        return self::$healTables;
    }
}
