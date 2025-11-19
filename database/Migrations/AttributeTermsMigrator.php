<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class AttributeTermsMigrator extends Migrator
{

    public static string $tableName = 'fct_atts_terms';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_attt_';
        return "`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `group_id` BIGINT(20) UNSIGNED,
                `serial` INT(11) UNSIGNED,
                `title` VARCHAR(192) NOT NULL,
                `slug` VARCHAR(192) NOT NULL,
                `description` longtext NULL,
                `settings` longtext NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                INDEX `{$indexPrefix}_group_id_idx` (`group_id` ASC)";
    }
}


