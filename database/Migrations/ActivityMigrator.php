<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class ActivityMigrator extends Migrator
{

    public static string $tableName = 'fct_activity';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_act_';
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `status` VARCHAR(20) NOT NULL DEFAULT 'info' COMMENT 'success / warning / failed / info',
                `log_type` VARCHAR(20) NOT NULL DEFAULT 'activity' COMMENT 'api',
                `module_type` VARCHAR(100) NOT NULL DEFAULT 'order' COMMENT 'Full Model Path',
                `module_id` BIGINT NULL,
                `module_name` VARCHAR(192) NOT NULL DEFAULT 'order' COMMENT 'order / product / user / coupon / subscription / payment / refund / shipment / activity',
                `user_id` BIGINT UNSIGNED NULL,
                `title` VARCHAR(192) NULL,
                `content` LONGTEXT NULL,
                `read_status` VARCHAR(20) NOT NULL DEFAULT 'unread' COMMENT 'read / unread',
                `created_by` VARCHAR(100) NOT NULL DEFAULT 'FCT-BOT' COMMENT 'FCT-BOT / usename',
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                INDEX `{$indexPrefix}_module_id_idx` (`module_id` ASC)";

    }
}
