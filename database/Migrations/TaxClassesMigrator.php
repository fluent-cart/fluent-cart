<?php

namespace FluentCart\Database\Migrations;


use FluentCart\Framework\Database\Schema;

class TaxClassesMigrator extends Migrator
{

    public static string $tableName = 'fct_tax_classes';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_tcl_';
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
			`title` VARCHAR(192) NULL,
            `slug` VARCHAR(100) NULL,
            `description` longtext NULL,
            `meta` json DEFAULT NULL,
			`created_at` DATETIME NULL ,
			`updated_at` DATETIME NULL";
    }

    public static function migrated()
    {
        static::renameCategoriesToMeta();
        static::addMetaColumn();
        static::addSlugColumn();
        static::addDescriptionColumn();
    }

    public static function renameCategoriesToMeta()
    {
        // "ALTER TABLE %i CHANGE `categories` `meta` JSON"
        // Only rename if categories exists and meta doesn't (avoid collision)
        if (Schema::hasColumn('categories', static::$tableName) && !Schema::hasColumn('meta', static::$tableName)) {
            Schema::alterTable(
                static::$tableName,
                "CHANGE `categories` `meta` JSON"
            );
        }
    }

    public static function addMetaColumn()
    {
        // "ALTER TABLE %i ADD COLUMN `meta` JSON"
        static::addColumnIfNotExists('meta', 'JSON');
    }

    public static function addSlugColumn()
    {
        // "ALTER TABLE %i ADD COLUMN `slug` VARCHAR(100) NULL AFTER `title`"
        static::addColumnIfNotExists('slug', 'VARCHAR(100) NULL', 'title');
    }

    public static function addDescriptionColumn()
    {
        // "ALTER TABLE %i ADD COLUMN `description` LONGTEXT NULL AFTER `slug`"
        static::addColumnIfNotExists('description', 'LONGTEXT NULL', 'slug');
    }
}
