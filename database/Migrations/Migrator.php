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