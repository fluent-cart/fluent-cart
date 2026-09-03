<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Database\Migrations\Migrator;
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
                INDEX `{$indexPrefix}_obj_id_idx` (`object_id` ASC),

                UNIQUE INDEX `{$indexPrefix}_obj_grp_term_unique` (`object_id` ASC, `group_id` ASC, `term_id` ASC)";
    }

    public static function migrated()
    {
        // Defensive guard so a missing relations table (e.g. dbDelta failed)
        // does not bubble up as "unexpected output during activation". The
        // dedupe + UNIQUE retry logic itself lives in AttributeTermsMigrator
        // because it depends on the term-level cleanup that runs in that
        // hook. Keeping this empty stub here surfaces the migrator's own
        // post-migration phase so future per-table maintenance has a
        // dedicated place to grow without breaking the layering.
        if (!Schema::hasTable(static::$tableName)) {
            return;
        }
    }
}
