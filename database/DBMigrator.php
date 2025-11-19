<?php

namespace FluentCart\Database;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Models\Product;

require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

use FluentCart\App\Models\Subscription;
use FluentCart\Database\Migrations\AttributeGroupsMigrator;
use FluentCart\Database\Migrations\AttributeObjectRelationsMigrator;
use FluentCart\Database\Migrations\AttributeTermsMigrator;
use FluentCart\Database\Migrations\CartMigrator;
use FluentCart\Database\Migrations\CustomersMigrator;
use FluentCart\Database\Migrations\MetaMigrator;
use FluentCart\Database\Migrations\Migrator;
use FluentCart\Database\Migrations\OrderMetaMigrator;
use FluentCart\Database\Migrations\OrdersMigrator;
use FluentCart\Database\Migrations\OrdersItemsMigrator;
use FluentCart\Database\Migrations\OrderTransactionsMigrator;
use FluentCart\Database\Migrations\ProductDetailsMigrator;
use FluentCart\Database\Migrations\ProductDownloadsMigrator;
use FluentCart\Database\Migrations\ProductMetaMigrator;
use FluentCart\Database\Migrations\ProductVariationMigrator;
use FluentCart\Database\Migrations\ScheduledActionsMigrator;
use FluentCart\Database\Migrations\ShippingClassesMigrator;
use FluentCart\Database\Migrations\SubscriptionMetaMigrator;
use FluentCart\Database\Migrations\SubscriptionsMigrator;
use FluentCart\Database\Migrations\TaxClassesMigrator;
use FluentCart\Database\Migrations\TaxRatesMigrator;
use FluentCart\Database\Migrations\OrderTaxRateMigrator;
use FluentCart\Database\Migrations\CouponsMigrator;
use FluentCart\Database\Migrations\CustomerAddressesMigrator;
use FluentCart\Database\Migrations\CustomerMetaMigrator;
use FluentCart\Database\Migrations\OrderAddressesMigrator;
use FluentCart\Database\Migrations\OrderDownloadPermissionsMigrator;
use FluentCart\Database\Migrations\OrderOperationsMigrator;
use FluentCart\Database\Migrations\AppliedCouponsMigrator;
use FluentCart\Database\Migrations\LabelMigrator;
use FluentCart\Database\Migrations\LabelRelationshipsMigrator;
use FluentCart\Database\Migrations\ActivityMigrator;
use FluentCart\Database\Migrations\WebhookLogger;
use FluentCart\Framework\Database\Schema;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Licensing\Models\LicenseActivation;
use FluentCartPro\App\Modules\Licensing\Models\LicenseMeta;
use FluentCartPro\App\Modules\Licensing\Models\LicenseSite;
use FluentCart\Database\Migrations\ShippingZonesMigrator;
use FluentCart\Database\Migrations\ShippingMethodsMigrator;

class DBMigrator
{
    private static array $migrators = [
        MetaMigrator::class,
        AttributeGroupsMigrator::class,
        AttributeObjectRelationsMigrator::class,
        AttributeTermsMigrator::class,
        CartMigrator::class,
        CouponsMigrator::class,
        CustomerAddressesMigrator::class,
        CustomerMetaMigrator::class,
        CustomersMigrator::class,
        OrderAddressesMigrator::class,
        OrderDownloadPermissionsMigrator::class,
        OrderMetaMigrator::class,
        OrderOperationsMigrator::class,
        OrdersItemsMigrator::class,
        OrdersMigrator::class,
        OrderTaxRateMigrator::class,
        OrderTransactionsMigrator::class,
        ProductDetailsMigrator::class,
        ProductDownloadsMigrator::class,
        ProductMetaMigrator::class,
        SubscriptionMetaMigrator::class,
        SubscriptionsMigrator::class,
        TaxClassesMigrator::class,
        TaxRatesMigrator::class,
        ProductVariationMigrator::class,
        AppliedCouponsMigrator::class,
        LabelMigrator::class,
        LabelRelationshipsMigrator::class,
        ActivityMigrator::class,
        WebhookLogger::class,
        ShippingZonesMigrator::class,
        ShippingMethodsMigrator::class,
        ShippingClassesMigrator::class,
        ScheduledActionsMigrator::class
    ];

    public static function migrateUp($network_wide = false)
    {
        global $wpdb;
        if ($network_wide) {
            // Retrieve all site IDs from this network (WordPress >= 4.6 provides easy to use functions for that).
            if (function_exists('get_sites') && function_exists('get_current_network_id')) {
                $site_ids = get_sites(array('fields' => 'ids', 'network_id' => get_current_network_id()));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $site_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs WHERE site_id = $wpdb->siteid;");
            }
            // Install the plugin for all these sites.
            foreach ($site_ids as $site_id) {
                switch_to_blog($site_id);
                self::run_migrate();
                restore_current_blog();
            }
        } else {
            self::run_migrate();
        }
    }

    public static function run_migrate()
    {
        self::migrate();
        self::maybeMigrateDBChanges();
        update_option('_fluent_cart_db_version', FLUENTCART_DB_VERSION, 'no');
    }

    public static function migrate()
    {
        /**
         * @var $migrator Migrator
         */
        foreach (self::$migrators as $migrator) {
            $migrator::migrate();
        }
    }

    public static function maybeMigrateDBChanges()
    {

        /*
         * TODO We will remove this after final release
         */
        $currentDBVersion = get_option('_fluent_cart_db_version');

        if (!$currentDBVersion || version_compare($currentDBVersion, FLUENTCART_DB_VERSION, '<')) {

            update_option('_fluent_cart_db_version', FLUENTCART_DB_VERSION, 'no');

            // let's check the orders table sequence number
            global $wpdb;

            // Product Meta unique index removal
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $hasProductMetaUnqIndex = $wpdb->get_col($wpdb->prepare("SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME='" . $wpdb->prefix . "fct_pm__comp_unq' AND TABLE_NAME=%s", $wpdb->prefix . 'fct_product_meta'));
            if ($hasProductMetaUnqIndex) {

                $table_name = $wpdb->prefix . 'fct_product_meta';
                $index_name = 'fct_pm__comp_unq';

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query(
                    $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i DROP INDEX %i",
                    $table_name,
                    $index_name
                ));

            }

            if (!Schema::hasColumn('tax_behavior', 'fct_orders')) {
                $table_name = $wpdb->prefix . 'fct_orders';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i ADD COLUMN `tax_behavior` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 => no_tax, 1 => exclusive, 2 => inclusive' AFTER `rate`",
                    $table_name
                ));
            }

            if (!Schema::hasColumn('slug', 'fct_tax_classes')) {
                $table_name = $wpdb->prefix . 'fct_tax_classes';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i ADD COLUMN `slug` VARCHAR(100) NULL AFTER `title`",
                    $table_name
                ));
            }

            $ordersTable = $wpdb->prefix . 'fct_orders';

            // check if scheduled_at is exist or not
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $isReceiptNumberMigrated = $wpdb->get_col($wpdb->prepare("SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='receipt_number' AND TABLE_NAME=%s", $ordersTable));
            if (!$isReceiptNumberMigrated) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i ADD COLUMN `receipt_number` BIGINT NULL AFTER `parent_id`",
                    $ordersTable
                ));
            }

            /**
             * Changing fct_meta.key to fct_meta.meta_key
             */
            if (Schema::hasColumn('discount_total', 'fct_orders')) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i CHANGE `discount_total` `manual_discount_total` BIGINT NOT NULL DEFAULT '0'",
                    $ordersTable
                ));
            }

            /**
             * Changing fct_meta.key to fct_meta.meta_key
             */
            if (Schema::hasColumn('key', 'fct_meta')) {
                $table_name = $wpdb->prefix . 'fct_meta';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i CHANGE `key` `meta_key` VARCHAR(192)",
                    $table_name
                ));
            }

            /**
             * Changing fct_meta.value to fct_meta.meta_value
             */
            if (Schema::hasColumn('value', 'fct_meta')) {
                $table_name = $wpdb->prefix . 'fct_meta';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i CHANGE `value` `meta_value` LONGTEXT",
                    $table_name
                ));
            }

            /**
             * Changing fct_order_meta.key to fct_order_meta.meta_key and fct_order_meta.value to fct_order_meta.meta_value
             */
            if (Schema::hasColumn('key', 'fct_order_meta')) {
                $table_name = $wpdb->prefix . 'fct_order_meta';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i CHANGE `key` `meta_key` VARCHAR(192)",
                    $table_name
                ));
            }
            /**
             * Changing fct_meta.key to fct_meta.meta_key and fct_meta.value to fct_meta.meta_value
             */
            if (Schema::hasColumn('value', 'fct_order_meta')) {
                $table_name = $wpdb->prefix . 'fct_order_meta';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i CHANGE `value` `meta_value` LONGTEXT",
                    $table_name
                ));
            }

            /**
             * adding ltv column to fct_customers table
             */
            if (!Schema::hasColumn('ltv', 'fct_customers')) {
                $table_name = $wpdb->prefix . 'fct_customers';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i ADD COLUMN `ltv` BIGINT NOT NULL DEFAULT '0' AFTER `purchase_count`",
                    $table_name
                ));
            }

            /**
             *  adding states column to fct_shipping_methods table
             */
            if (!Schema::hasColumn('states', 'fct_shipping_methods')) {
                $table_name = $wpdb->prefix . 'fct_shipping_methods';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i ADD COLUMN `states` LONGTEXT NULL AFTER `is_enabled`",
                    $table_name
                ));
            }

            /**
             *  modify states column to json in fct_shipping_methods table
             */
            if (Schema::hasColumn('states', 'fct_shipping_methods')) {
                $table_name = $wpdb->prefix . 'fct_shipping_methods';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i MODIFY COLUMN `states` JSON NULL",
                    $table_name
                ));
            }

            /**
             * Changing fct_shipping_zones.regions to fct_shipping_zones.region
             */
            if (Schema::hasColumn('regions', 'fct_shipping_zones')) {
                $table_name = $wpdb->prefix . 'fct_shipping_zones';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i CHANGE `regions` `region` VARCHAR(192) NOT NULL",
                    $table_name
                ));
            }

            /**
             * adding uuid column to fct_subscriptions table
             */

            if (!Schema::hasColumn('uuid', 'fct_subscriptions')) {
                $table_name = $wpdb->prefix . 'fct_subscriptions';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i ADD COLUMN `uuid` VARCHAR(100) NOT NULL AFTER `id`",
                    $table_name
                ));
            }

            if (Schema::hasColumn('initial_amount', 'fct_subscriptions')) {
                $table_name = $wpdb->prefix . 'fct_subscriptions';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i CHANGE `initial_amount` `signup_fee` BIGINT UNSIGNED NOT NULL DEFAULT 0",
                    $table_name
                ));
            }

            $subscriptions = fluentCart('db')->table('fct_subscriptions')->select('id')->where('uuid', '')->orWhereNull('uuid');
            if ($subscriptions && $subscriptions->count() > 0) {
                $subscriptions = fluentCart('db')->table('fct_subscriptions')->select('id')->get()->keyBy('id')->toArray();
                $uuids = [];
                foreach ($subscriptions as $id => $subscription) {
                    $uuids[] = [
                        'id'   => $id,
                        'uuid' => md5(time() . wp_generate_uuid4())
                    ];

                }

                (new Subscription())->batchUpdate($uuids);
            }

            if (!Schema::hasColumn('meta', 'fct_order_addresses')) {
                $table_name = $wpdb->prefix . 'fct_order_addresses';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i ADD COLUMN `meta` JSON DEFAULT NULL AFTER `country`",
                    $table_name
                ));
            }

            if (!Schema::hasColumn('meta', 'fct_customer_addresses')) {
                $table_name = $wpdb->prefix . 'fct_customer_addresses';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i ADD COLUMN `meta` JSON DEFAULT NULL AFTER `country`",
                    $table_name
                ));
            }

            if (!Schema::hasColumn('meta', 'fct_order_tax_rate')) {
                $table_name = $wpdb->prefix . 'fct_order_tax_rate';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i ADD COLUMN `meta` JSON",
                    $table_name
                ));
            }

            if (!Schema::hasColumn('filed_at', 'fct_order_tax_rate')) {
                $table_name = $wpdb->prefix . 'fct_order_tax_rate';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i ADD COLUMN `filed_at` DATETIME NULL AFTER `meta`",
                    $table_name
                ));
            }

            if (Schema::hasColumn('categories', 'fct_tax_classes') && !Schema::hasColumn('meta', 'fct_tax_classes')) {
                $table_name = $wpdb->prefix . 'fct_tax_classes';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i CHANGE `categories` `meta` JSON",
                    $table_name
                ));
            }

            if (!Schema::hasColumn('meta', 'fct_tax_classes')) {
                $table_name = $wpdb->prefix . 'fct_tax_classes';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i ADD COLUMN `meta` JSON",
                    $table_name
                ));
            }

            if (!Schema::hasColumn('description', 'fct_tax_classes')) {
                $table_name = $wpdb->prefix . 'fct_tax_classes';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i ADD COLUMN `description` LONGTEXT NULL AFTER `title`",
                    $table_name
                ));
            }

            if (!Schema::hasColumn('group', 'fct_tax_rates')) {
                $table_name = $wpdb->prefix . 'fct_tax_rates';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i ADD COLUMN `group` VARCHAR(45) NULL AFTER `name`",
                    $table_name
                ));
            }

            if (!Schema::hasColumn('meta', 'fct_shipping_methods')) {
                $table_name = $wpdb->prefix . 'fct_shipping_methods';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i ADD COLUMN `meta` JSON NULL AFTER `order`",
                    $table_name
                ));

            }
        }
    }

    public static function migrateDown($network_wide = false)
    {
        /**
         * @var $migrator Migrator
         */
        foreach (self::$migrators as $migrator) {
            $migrator::dropTable();
        }

        Product::query()->where('post_type', '=', FluentProducts::CPT_NAME)->delete();

        //Migrate Down The Licenses
        if (class_exists(License::class)) {
            License::query()->truncate();
            LicenseActivation::query()->truncate();
            LicenseSite::query()->truncate();
            LicenseMeta::query()->truncate();
        }
    }

    public static function refresh($network_wide = false)
    {
        static::migrateDown($network_wide);
        static::migrateUp($network_wide);
    }
}
