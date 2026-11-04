<?php

namespace Tests\Support;

use FluentCart\Database\DBMigrator;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Base test case for database integration tests.
 *
 * All DB helper methods live here so test files contain only test_* methods.
 */
abstract class DatabaseTestCase extends WPTestCase
{
    // =========================================================================
    // Table inspection (read-only)
    // =========================================================================

    protected function fullTableName(string $table): string
    {
        global $wpdb;
        return $wpdb->prefix . $table;
    }

    protected function tableExists(string $table): bool
    {
        global $wpdb;
        $fullTable = $this->fullTableName($table);
        $result = $wpdb->get_var("SHOW TABLES LIKE '{$fullTable}'");
        return $result !== null;
    }

    protected function countFctTables(): int
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'fct_%';
        $tables = $wpdb->get_col("SHOW TABLES LIKE '{$prefix}'");
        return count($tables);
    }

    // =========================================================================
    // Column inspection (read-only)
    // =========================================================================

    protected function getColumns(string $table): array
    {
        global $wpdb;
        $fullTable = $this->fullTableName($table);
        $results = $wpdb->get_results("SHOW COLUMNS FROM `{$fullTable}`", ARRAY_A);
        $columns = [];
        foreach ($results as $row) {
            $columns[$row['Field']] = $row;
        }
        return $columns;
    }

    protected function getColumn(string $table, string $column): ?array
    {
        $columns = $this->getColumns($table);
        return $columns[$column] ?? null;
    }

    protected function getColumnNames(string $table): array
    {
        return array_keys($this->getColumns($table));
    }

    protected function hasColumn(string $table, string $column): bool
    {
        return $this->getColumn($table, $column) !== null;
    }

    // =========================================================================
    // Index inspection (read-only)
    // =========================================================================

    protected function getIndexes(string $table): array
    {
        global $wpdb;
        $fullTable = $this->fullTableName($table);
        $results = $wpdb->get_results("SHOW INDEX FROM `{$fullTable}`", ARRAY_A);
        $indexes = [];
        foreach ($results as $row) {
            $indexes[$row['Key_name']][] = $row['Column_name'];
        }
        return $indexes;
    }

    protected function hasIndex(string $table, string $indexName): bool
    {
        global $wpdb;
        $fullTable = $this->fullTableName($table);
        $results = $wpdb->get_results($wpdb->prepare(
            "SHOW INDEX FROM `{$fullTable}` WHERE Key_name = %s",
            $indexName
        ));
        return !empty($results);
    }

    /**
     * Find an index by name, accounting for WP table prefix in index names.
     *
     * WordPress's dbDelta() prepends the table name (including WP prefix) to index names
     * defined in CREATE TABLE SQL, producing names like `test_fct_ord__customer_id`
     * from `fct_ord_customer_id`. Indexes added via ALTER TABLE keep their original name.
     */
    protected function findIndexName(string $table, string $indexName): ?string
    {
        $indexes = $this->getIndexes($table);

        // Exact match
        if (isset($indexes[$indexName])) {
            return $indexName;
        }

        // Check if any actual index name contains the expected name (handles prefix prepending)
        foreach (array_keys($indexes) as $key) {
            // Strip underscores for fuzzy comparison: test_fct_ord__customer_id contains fct_ord_customer_id
            $normalizedKey = str_replace('__', '_', $key);
            $normalizedExpected = str_replace('__', '_', $indexName);
            if (str_contains($normalizedKey, $normalizedExpected)) {
                return $key;
            }
        }

        return null;
    }

    // =========================================================================
    // Column mutations (destructive)
    // =========================================================================

    protected function dropColumn(string $table, string $column): void
    {
        global $wpdb;
        $fullTable = $this->fullTableName($table);
        $wpdb->query("ALTER TABLE `{$fullTable}` DROP COLUMN `{$column}`");
    }

    protected function addColumn(string $table, string $column, string $definition, ?string $after = null): void
    {
        global $wpdb;
        $fullTable = $this->fullTableName($table);
        $sql = "ALTER TABLE `{$fullTable}` ADD COLUMN `{$column}` {$definition}";
        if ($after) {
            $sql .= " AFTER `{$after}`";
        }
        $wpdb->query($sql);
    }

    protected function renameColumn(string $table, string $from, string $to, string $definition): void
    {
        global $wpdb;
        $fullTable = $this->fullTableName($table);
        $wpdb->query("ALTER TABLE `{$fullTable}` CHANGE `{$from}` `{$to}` {$definition}");
    }

    protected function modifyColumn(string $table, string $column, string $definition): void
    {
        global $wpdb;
        $fullTable = $this->fullTableName($table);
        $wpdb->query("ALTER TABLE `{$fullTable}` MODIFY COLUMN `{$column}` {$definition}");
    }

    // =========================================================================
    // Index mutations (destructive)
    // =========================================================================

    protected function dropIndex(string $table, string $indexName): void
    {
        global $wpdb;
        $fullTable = $this->fullTableName($table);
        $wpdb->query("ALTER TABLE `{$fullTable}` DROP INDEX `{$indexName}`");
    }

    protected function addIndex(string $table, string $indexName, array $columns, bool $unique = false): void
    {
        global $wpdb;
        $fullTable = $this->fullTableName($table);
        $type = $unique ? 'UNIQUE INDEX' : 'INDEX';
        $colsSql = '`' . implode('`, `', $columns) . '`';
        $wpdb->query("ALTER TABLE `{$fullTable}` ADD {$type} `{$indexName}` ({$colsSql})");
    }

    // =========================================================================
    // Row helpers (CRUD)
    // =========================================================================

    protected function insertRow(string $table, array $data): int
    {
        global $wpdb;
        $fullTable = $this->fullTableName($table);
        $wpdb->insert($fullTable, $data);
        return $wpdb->insert_id;
    }

    protected function getRow(string $table, int $id): ?object
    {
        global $wpdb;
        $fullTable = $this->fullTableName($table);
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$fullTable}` WHERE `id` = %d", $id));
    }

    protected function deleteRow(string $table, int $id): void
    {
        global $wpdb;
        $fullTable = $this->fullTableName($table);
        $wpdb->delete($fullTable, ['id' => $id]);
    }

    // =========================================================================
    // Lifecycle helpers (bypass WPTestCase TEMPORARY table filter)
    // =========================================================================

    /**
     * Drop all fct_* tables via raw mysqli, bypassing WPTestCase's query filter.
     */
    protected function realDropAllFctTables(): void
    {
        global $wpdb;

        // TC-047: re-check the opt-in against the LIVE connection before
        // dropping — same repeat-guard as Functional::resetFluentCartTables().
        \Tests\Support\Guard\DestructiveDbGuard::assertDbAllowed(
            defined('DB_HOST') ? DB_HOST : '',
            defined('DB_NAME') ? DB_NAME : '',
            'DatabaseTestCase::realDropAllFctTables()'
        );

        $prefix = $wpdb->prefix . 'fct_%';
        $tables = $wpdb->get_col("SHOW TABLES LIKE '{$prefix}'");

        $mysqli = $wpdb->dbh;
        foreach ($tables as $table) {
            $mysqli->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    /**
     * Run DBMigrator::migrateUp() creating permanent (not temporary) tables.
     */
    protected function realMigrate(): void
    {
        remove_all_filters('query');
        DBMigrator::migrateUp();
        add_filter('query', [$this, '_create_temporary_tables']);
        add_filter('query', [$this, '_drop_temporary_tables']);
    }

    // =========================================================================
    // Custom assertions
    // =========================================================================

    protected function assertColumnsExist(string $table, array $expectedColumns): void
    {
        $actual = $this->getColumnNames($table);
        foreach ($expectedColumns as $col) {
            $this->assertContains($col, $actual, "Column '{$col}' missing from {$table}");
        }
    }

    protected function assertColumnType(string $table, string $column, string $typeFragment): void
    {
        $col = $this->getColumn($table, $column);
        $this->assertNotNull($col, "Column '{$column}' not found in {$table}");
        $this->assertStringContainsString(
            strtolower($typeFragment),
            strtolower($col['Type']),
            "Column {$table}.{$column} type '{$col['Type']}' does not contain '{$typeFragment}'"
        );
    }

    protected function assertColumnDefault(string $table, string $column, ?string $expected): void
    {
        $col = $this->getColumn($table, $column);
        $this->assertNotNull($col, "Column '{$column}' not found in {$table}");
        $this->assertSame($expected, $col['Default'], "Default for {$table}.{$column}");
    }

    protected function assertIndexExists(string $table, string $indexName): void
    {
        $found = $this->findIndexName($table, $indexName);
        $indexes = $this->getIndexes($table);
        $this->assertNotNull(
            $found,
            "Index '{$indexName}' missing from {$table}. Available: " . implode(', ', array_keys($indexes))
        );
    }

    protected function assertUniqueIndex(string $table, string $indexName): void
    {
        global $wpdb;
        $fullTable = $this->fullTableName($table);
        $actualName = $this->findIndexName($table, $indexName);
        $indexes = $this->getIndexes($table);
        $this->assertNotNull(
            $actualName,
            "Index '{$indexName}' not found on {$table}. Available: " . implode(', ', array_keys($indexes))
        );

        $results = $wpdb->get_results(
            $wpdb->prepare("SHOW INDEX FROM `{$fullTable}` WHERE Key_name = %s", $actualName),
            ARRAY_A
        );
        $this->assertNotEmpty($results);
        $this->assertSame('0', $results[0]['Non_unique'], "Index '{$actualName}' on {$table} should be UNIQUE");
    }

    protected function assertJsonRoundTrip(string $fullTable, string $column, int $id): void
    {
        global $wpdb;
        $stored = $wpdb->get_var($wpdb->prepare(
            "SELECT `{$column}` FROM `{$fullTable}` WHERE `id` = %d",
            $id
        ));
        $decoded = json_decode($stored, true);
        $this->assertIsArray($decoded, "{$fullTable}.{$column} should store valid JSON");
        $this->assertTrue($decoded['round_trip'], "{$fullTable}.{$column} JSON content should be preserved");
        $this->assertSame('value', $decoded['nested']['key'], "{$fullTable}.{$column} nested JSON should survive");
    }
}
