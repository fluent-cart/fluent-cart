<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class AttributeObjectRelationsMigrator extends Migrator
{

    public static string $tableName = 'fct_atts_relations';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_at_rel_';
        return "`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `group_id` BIGINT(20) UNSIGNED NOT NULL,
                `term_id` BIGINT(20) UNSIGNED NOT NULL,
                `object_id` BIGINT(20) UNSIGNED NOT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                INDEX `{$indexPrefix}_group_id_idx` (`group_id` ASC),
                INDEX `{$indexPrefix}_term_id_idx` (`term_id` ASC),
                INDEX `{$indexPrefix}_obj_id_idx` (`object_id` ASC)";
    }
}
