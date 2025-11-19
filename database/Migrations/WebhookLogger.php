<?php

namespace FluentCart\Database\Migrations;

class WebhookLogger extends Migrator
{

    public static string $tableName = 'fct_webhook_logger';

    public static function getSqlSchema(): string
    {
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `source` VARCHAR(20) NOT NULL,
                `event_type` VARCHAR(100) NOT NULL,
                `payload` LONGTEXT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL";
    }
}
