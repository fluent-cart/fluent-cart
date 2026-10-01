<?php

namespace FluentCart\Database\Migrations;

class ProductReviewsMigrator extends Migrator
{
    public static string $tableName = 'fct_product_reviews';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_prev_';
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `parent_id` BIGINT UNSIGNED NULL, -- NULL = a review; set = a reply to that review
                `post_id` BIGINT UNSIGNED NOT NULL, -- product post ID
                `item_id` BIGINT UNSIGNED NULL, -- fct_product_variations.id when the review targets one item; NULL = the product
                `order_id` BIGINT UNSIGNED NULL,
                `customer_id` BIGINT UNSIGNED NULL,
                `user_id` BIGINT UNSIGNED NULL,
                `reviewer_name` VARCHAR(192) NOT NULL DEFAULT '',
                `reviewer_email` VARCHAR(192) NOT NULL DEFAULT '',
                `title` VARCHAR(192) NULL,
                `review` LONGTEXT NULL,
                `rating` TINYINT UNSIGNED NULL, -- NULL for replies
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending', -- approved | pending | spam | trash
                `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
                `is_admin_reply` TINYINT(1) NOT NULL DEFAULT 0, -- replies can also come from the reviewer (customer follow-up)
                `media_count` TINYINT UNSIGNED NOT NULL DEFAULT 0, -- maintained on media attach/detach
                `ip_address` VARCHAR(45) NULL,
                `other_info` json NULL, -- media refs + extra review data
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                INDEX `{$indexPrefix}_post_parent_status_idx` (`post_id` ASC, `parent_id` ASC, `status` ASC),
                INDEX `{$indexPrefix}_post_media_idx` (`post_id` ASC, `media_count` ASC),
                INDEX `{$indexPrefix}_customer_id_idx` (`customer_id` ASC),
                INDEX `{$indexPrefix}_user_parent_created_idx` (`user_id` ASC, `parent_id` ASC, `created_at` ASC),
                INDEX `{$indexPrefix}_parent_status_id_idx` (`parent_id` ASC, `status` ASC, `id` ASC),
                INDEX `{$indexPrefix}_email_created_idx` (`reviewer_email` ASC, `created_at` ASC),
                INDEX `{$indexPrefix}_post_item_parent_status_idx` (`post_id` ASC, `item_id` ASC, `parent_id` ASC, `status` ASC)";
    }
}
