<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class ProductDownloadsMigrator extends Migrator
{

    public static string $tableName = "fct_product_downloads";

    public static function getSqlSchema(): string
    {
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `post_id` BIGINT(20) UNSIGNED NOT NULL,
                `product_variation_id` longtext NOT NULL,
                `download_identifier` VARCHAR(100) NOT NULL UNIQUE,
                `title` VARCHAR(192) NULL,
                `type` VARCHAR(100) NULL,
                `driver` VARCHAR(100) NULL DEFAULT 'local',
                `file_name` VARCHAR(192) NULL,
                `file_path` TEXT NULL,
                `file_url` TEXT NULL,
                `file_size` TEXT NULL,
                `settings` TEXT NULL,
                `serial` INT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL";
    }
}
