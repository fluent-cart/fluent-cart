<?php

namespace FluentCart\Database\Migrations;

class LabelMigrator extends Migrator
{

    public static string $tableName = 'fct_label';

    public static function getSqlSchema(): string
    {
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `value` VARCHAR(192) NOT NULL UNIQUE,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL";
    }
}
