<?php

namespace FluentCartPro\App\Modules\Promotional;


use FluentCart\Api\ModuleSettings;
use FluentCartPro\App\Modules\Promotional\OrderBump\OrderBumpBoot;

class PromotionalInit
{
    public function register($app)
    {
        add_filter('fluent_cart/module_setting/fields', function ($fields, $args) {
            $fields['order_bump'] = [
                'title'       => __('Order Bump', 'fluent-cart-pro'),
                'description' => __('Offer Bump Products in checkout and make more revenue per order', 'fluent-cart-pro'),
                'type'        => 'component',
                'component'   => 'ModuleSettings',
            ];
            return $fields;
        }, 10, 2);

        add_filter('fluent_cart/module_setting/default_values', function ($values, $args) {
            if (empty($values['order_bump']['active'])) {
                $values['order_bump']['active'] = 'no';
            }

            return $values;
        }, 10, 2);

        add_action('fluent_cart/module/activated/order_bump', [$this, 'maybeMigrateDB']);

        if (ModuleSettings::isActive('order_bump')) {
            (new OrderBumpBoot())->register();
        }

        $app->router->group(function ($router) {
            require_once __DIR__ . '/Http/order-bump-api.php';
        });

    }

    public function maybeMigrateDB()
    {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        global $wpdb;
        $tableName = 'fct_order_promotions';

        $fullTableName = $wpdb->prefix . $tableName;

        if ($wpdb->get_var("SHOW TABLES LIKE '$fullTableName'") !== $fullTableName) {
            $charsetCollate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $fullTableName (
                `id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `hash` VARCHAR(100) NOT NULL,
                `parent_id` BIGINT(20) UNSIGNED DEFAULT NULL,
                `type` VARCHAR(50) NOT NULL,
                `status` VARCHAR(50) NOT NULL DEFAULT 'draft',
                `src_object_id` BIGINT(20) UNSIGNED NULL,
                `src_object_type` VARCHAR(50) DEFAULT NULL,
                `title` VARCHAR(194) DEFAULT NULL,
                `description` TEXT DEFAULT NULL,
                `conditions` JSON DEFAULT NULL,
                `config` JSON DEFAULT NULL,
                `priority` INT NOT NULL DEFAULT 1,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                INDEX type_status_src_object_id_type (type, status, src_object_id, src_object_type)
            ) $charsetCollate;";
            dbDelta($sql);
        }

        // create stats table
        $statsTableName = 'fct_order_promotion_stats';
        $fullStatsTableName = $wpdb->prefix . $statsTableName;
        if ($wpdb->get_var("SHOW TABLES LIKE '$fullStatsTableName'") !== $fullStatsTableName) {
            $charsetCollate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $fullStatsTableName (
                `id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `promotion_id` BIGINT(20) UNSIGNED NOT NULL,
                `order_id` BIGINT(20) UNSIGNED NOT NULL,
                `object_id` BIGINT(20) UNSIGNED NOT NULL,
                `amount` BIGINT NOT NULL DEFAULT '0',
                `status` VARCHAR(50) NOT NULL DEFAULT 'offered',
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                INDEX `promotion_id_object_id_product_id` (promotion_id, object_id, order_id)
            ) $charsetCollate;";
            dbDelta($sql);
        }

    }
}
