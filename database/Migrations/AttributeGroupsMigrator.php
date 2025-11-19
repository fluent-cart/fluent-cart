<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class AttributeGroupsMigrator extends Migrator
{
    public static string $tableName = 'fct_atts_groups';

    public static function getSqlSchema(): string
    {
        return "`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `title` VARCHAR(192) NOT NULL UNIQUE,
                `slug` VARCHAR(192) NOT NULL UNIQUE,
                `description` longtext NULL,
                `settings` longtext NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL";
    }
}
