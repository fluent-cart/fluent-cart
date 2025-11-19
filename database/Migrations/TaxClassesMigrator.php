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
}
