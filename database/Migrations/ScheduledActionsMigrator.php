<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class ScheduledActionsMigrator extends Migrator
{

    public static string $tableName = 'fct_scheduled_actions';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_sch_var_';
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `scheduled_at` DATETIME NULL,  -- the time when we will run it
                `action` VARCHAR(192) NULL,  -- the action to perform
                `status` VARCHAR(20) NULL,  --  pending -> processing -> completed -> failed
                `group` VARCHAR(100) NULL, -- order / subscription
                `object_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `object_type` VARCHAR(100) NULL DEFAULT NULL, -- order / subscription / etc.
                `completed_at` TIMESTAMP NULL, -- the time when we completed the action
                `retry_count` INT UNSIGNED DEFAULT 0, -- how many times we retried this action
                `data` json NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                `response_note` longtext NULL,
                INDEX `{$indexPrefix}_scheduled_at_idx` (`scheduled_at` ASC),
                INDEX `{$indexPrefix}_status_idx` (`status` ASC)";
    }
}
