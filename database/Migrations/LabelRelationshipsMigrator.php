<?php

namespace FluentCart\Database\Migrations;


use FluentCart\Framework\Database\Schema;

class LabelRelationshipsMigrator extends Migrator
{

    public static string $tableName = 'fct_label_relationships';


    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_labr_';
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `label_id` BIGINT(20) NOT NULL,
                `labelable_id` BIGINT(20) NOT NULL,
                `labelable_type` VARCHAR(192) NOT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                INDEX `{$indexPrefix}_label_id_idx` (`label_id` ASC),
                INDEX `{$indexPrefix}_labelable_id_idx` (`labelable_id` ASC)";
    }
}
