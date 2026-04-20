<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

abstract class Migrator
{
    public static string $tableName = '';

    /**
     * Migrate the table.
     *
     * @return void
     */

    public static function migrate()
    {
        Schema::createTableIfNotExist(
            static::getTableName(),
            static::getSqlSchema()
        );

        static::migrated();
    }

    /**
     * Called once migration is done.
     *
     * Override this method in child migrators to perform
     * post-migration tasks like seeding data or adding indexes.
     *
     * @return void
     */
    public static function migrated()
    {
        // Override in child classes as needed.
    }

    /**
     * Add a column if it does not exist.
     *
     * @param string $column Column name
     * @param string $definition Column definition (e.g. "VARCHAR(100) NULL")
     * @param string|null $after Place after this column (ignored on SQLite)
     * @return void
     */
    protected static function addColumnIfNotExists($column, $definition, $after = null)
    {
        if (Schema::hasColumn($column, static::$tableName)) {
            return;
        }

        $sql = "ADD COLUMN `{$column}` {$definition}";

        if ($after && !Schema::isSqlite()) {
            $sql .= " AFTER `{$after}`";
        }

        Schema::alterTable(static::$tableName, $sql);
    }

    /**
     * Rename a column if the old name exists.
     *
     * @param string $oldColumn Current column name
     * @param string $newColumn New column name
     * @param string $definition New column definition
     * @return void
     */
    protected static function renameColumnIfExists($oldColumn, $newColumn, $definition)
    {
        if (!Schema::hasColumn($oldColumn, static::$tableName)) {
            return;
        }

        Schema::alterTable(
            static::$tableName,
            "CHANGE `{$oldColumn}` `{$newColumn}` {$definition}"
        );
    }

    /**
     * Modify a column type/definition if it exists.
     *
     * @param string $column Column name
     * @param string $definition New column definition
     * @return void
     */
    protected static function modifyColumnIfExists($column, $definition)
    {
        if (!Schema::hasColumn($column, static::$tableName)) {
            return;
        }

        if (Schema::isSqlite()) {
            // SQLite has no MODIFY; use CHANGE with same name to trigger rebuild
            Schema::alterTable(
                static::$tableName,
                "CHANGE COLUMN `{$column}` `{$column}` {$definition}"
            );
        } else {
            Schema::alterTable(
                static::$tableName,
                "MODIFY COLUMN `{$column}` {$definition}"
            );
        }
    }

    /**
     * Check if an index exists on the table.
     *
     * @param string $indexName Index name
     * @return bool
     */
    protected static function hasIndex($indexName)
    {
        $wpdb = Schema::db();
        $tableName = static::getTableName();

        // SHOW INDEX is translated by the WP SQLite integration plugin
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results($wpdb->prepare(
            "SHOW INDEX FROM %i WHERE Key_name = %s",
            $tableName,
            $indexName
        ));

        return !empty($results);
    }

    /**
     * Add an index if it does not exist.
     *
     * @param string $indexName Index name
     * @param string|array $columns Column name(s)
     * @param bool $unique Whether the index is unique
     * @return void
     */
    protected static function addIndexIfNotExists($indexName, $columns, $unique = false)
    {
        if (static::hasIndex($indexName)) {
            return;
        }

        $type = $unique ? 'UNIQUE INDEX' : 'INDEX';

        if (is_array($columns)) {
            $colsSql = '`' . implode('`, `', $columns) . '`';
        } else {
            $colsSql = "`{$columns}`";
        }

        Schema::alterTable(
            static::$tableName,
            "ADD {$type} `{$indexName}` ({$colsSql})"
        );
    }

    /**
     * Drop an index if it exists.
     *
     * @param string $indexName Index name
     * @return void
     */
    protected static function dropIndexIfExists($indexName)
    {
        if (!static::hasIndex($indexName)) {
            return;
        }

        Schema::dropIndex(static::$tableName, $indexName);
    }

    public static function getTableName(bool $withPrefix = true): string
    {
        return ($withPrefix ? static::getDbPrefix() : '') . static::$tableName;
    }

    public static function getDbPrefix(): string
    {
        global $wpdb;
        return $wpdb->prefix;
    }

    public static function getCharsetCollate(): string
    {
        global $wpdb;
        return $wpdb->get_charset_collate();
    }

    public static function dropTable()
    {
        Schema::dropTableIfExists(static::getTableName(false));
    }

    abstract public static function getSqlSchema(): string;
}