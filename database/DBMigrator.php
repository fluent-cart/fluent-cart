<?php

namespace FluentCart\Database;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Models\Product;

require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

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
use FluentCart\Database\Migrations\RetentionSnapshotsMigrator;
use FluentCart\Database\Migrations\AttributeGroupsMigrator;
use FluentCart\Database\Migrations\AttributeObjectRelationsMigrator;
use FluentCart\Database\Migrations\AttributeTermsMigrator;
use FluentCart\Database\Seeder\AttributeSeeder;

class DBMigrator
{
    private static array $migrators = [
        MetaMigrator::class,
        CartMigrator::class,
        CouponsMigrator::class,
        CustomerAddressesMigrator::class,
        CustomerMetaMigrator::class,
        CustomersMigrator::class,
        OrderAddressesMigrator::class,
        OrderDownloadPermissionsMigrator::class,
        OrderOperationsMigrator::class,
        OrdersItemsMigrator::class,
        OrdersMigrator::class,
        OrderTaxRateMigrator::class,
        OrderMetaMigrator::class,
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
        ScheduledActionsMigrator::class,
        RetentionSnapshotsMigrator::class,
        // Order matters: AttributeTermsMigrator::migrated() INNER JOINs
        // fct_atts_relations to repoint orphaned term references, so the
        // relations table must exist before terms runs its post-migration hook.
        AttributeGroupsMigrator::class,
        AttributeObjectRelationsMigrator::class,
        AttributeTermsMigrator::class
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

        do_action('fluent_cart/after_migrate');
    }

    public static function maybeMigrateDBChanges()
    {
        /*
         * TODO We will remove this after final release
         */
        $currentDBVersion = get_option('_fluent_cart_db_version');

        if (!$currentDBVersion || version_compare($currentDBVersion, FLUENTCART_DB_VERSION, '<')) {

            // Right after a plugin update, multiple requests (admin page +
            // heartbeat/ajax) hit init before the version option is written and
            // would all run the schema changes in parallel, producing
            // duplicate-index / duplicate-entry errors. Only the request that
            // wins the advisory lock proceeds; the others skip — the winner
            // updates the version option for everyone.
            if (!self::acquireMigrationLock()) {
                return;
            }

            update_option('_fluent_cart_db_version', FLUENTCART_DB_VERSION, 'no');

            if (!$currentDBVersion) {
                // Brand-new store: default tax display to the simplified single line.
                // Existing stores fall through to the read-time default 'itemized'.
                $taxSettings = get_option('fluent_cart_tax_configuration_settings', []);
                if (!isset($taxSettings['checkout_tax_breakdown_display'])) {
                    $taxSettings['checkout_tax_breakdown_display'] = 'simplified';
                    update_option('fluent_cart_tax_configuration_settings', $taxSettings, true);
                }
            }

            // 2026-04-25
            TaxRatesMigrator::upgradeShippingOverridePrecision();

            // 2026-07-01
            OrderTaxRateMigrator::allowVirtualTaxRateIds();

            // 2026-05-27
            TaxRatesMigrator::fixPostcodeRangeSeparator();
            OrdersMigrator::addFeeTotalColumn();

            // 2026-07-24
            OrdersMigrator::addUuidIndex();

            // 2026-08-20
            ProductMetaMigrator::addObjectMetaIndex();

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

            if (!Schema::hasColumn('fee_total', 'fct_orders')) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    "ALTER TABLE %i ADD COLUMN `fee_total` BIGINT NOT NULL DEFAULT '0' AFTER `shipping_total`",
                    $ordersTable
                ));
            }

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

            SubscriptionsMigrator::backfillEmptyUuids();

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

            // Shipping schema upgrades — also run here so plugin updates
            // without deactivation/reactivation still apply new columns
            \FluentCart\Database\Migrations\ShippingZonesMigrator::migrated();
            \FluentCart\Database\Migrations\ShippingClassesMigrator::migrated();
            \FluentCart\Database\Migrations\SubscriptionsMigrator::migrated();

            // 2026-08-06
            // fct_order_transactions subscription_id index — also run here so
            // in-place plugin updates deliver it; migrate() only covers the
            // activation path.
            \FluentCart\Database\Migrations\OrderTransactionsMigrator::migrated();


            // 2026-05-06
            // Backfill, dedup, then add unique index — must run in this order
            // so the constraint can be applied without "Duplicate entry" errors.
            TaxClassesMigrator::backfillNullSlugs();
            TaxClassesMigrator::deduplicateSlugs();
            TaxClassesMigrator::addSlugUniqueIndex();

            // Ensure Standard tax class exists for all installs
            TaxClassesMigrator::seedDefaultTaxClass();

            // 2026-05-15
            // Move EU VAT country registrations from wp_options blob to fct_meta rows.
            \FluentCart\Database\Migrations\EuVatRegistrationMigrator::migrate();

            // 2026-06-10
            // One-time migration: move legacy price_suffix into the correct split field
            // based on the store's tax_inclusion setting at the time of migration.
            if (!get_option('_fluent_cart_price_suffix_migrated')) {
                $taxSettings = get_option('fluent_cart_tax_configuration_settings', []);
                $legacySuffix = isset($taxSettings['price_suffix']) ? $taxSettings['price_suffix'] : '';
                $hasIncluded  = isset($taxSettings['price_suffix_included']) && $taxSettings['price_suffix_included'] !== '';
                $hasExcluded  = isset($taxSettings['price_suffix_excluded']) && $taxSettings['price_suffix_excluded'] !== '';

                if ($legacySuffix !== '' && !$hasIncluded && !$hasExcluded) {
                    $taxInclusion = isset($taxSettings['tax_inclusion']) ? $taxSettings['tax_inclusion'] : 'excluded';
                    if ($taxInclusion === 'excluded') {
                        $taxSettings['price_suffix_excluded'] = $legacySuffix;
                    } else {
                        $taxSettings['price_suffix_included'] = $legacySuffix;
                    }
                    update_option('fluent_cart_tax_configuration_settings', $taxSettings, true);
                }
                update_option('_fluent_cart_price_suffix_migrated', '1', 'no');
            }

            // 2026-07-08
            // One-time migration: rename the legacy tax-display value 'both'
            // (and the removed 'label'/'tooltip') to 'itemized'. Value-guarded —
            // once migrated the stored value is 'itemized' so this no-ops; no
            // separate flag option is written (nothing left behind when this
            // block is removed later). Safe to remove after ~2027-07.
            $fctTaxSettings = get_option('fluent_cart_tax_configuration_settings', []);
            if (isset($fctTaxSettings['checkout_tax_breakdown_display'])
                && in_array($fctTaxSettings['checkout_tax_breakdown_display'], ['both', 'label', 'tooltip'], true)) {
                $fctTaxSettings['checkout_tax_breakdown_display'] = 'itemized';
                update_option('fluent_cart_tax_configuration_settings', $fctTaxSettings, true);
            }

            // 2026-05-18
            // One-time migration: copy seller identity fields from the Pro seller_details fct_meta
            // entry into fluent_cart_store_settings. Guarded by a sentinel so it runs exactly once.
            if (!get_option('_fluent_cart_seller_details_migrated')) {
                $sellerDetails = fluent_cart_get_option('seller_details', []);
                if (is_array($sellerDetails) && !empty($sellerDetails)) {
                    $storeSettingsOption = get_option('fluent_cart_store_settings', []);
                    if (!is_array($storeSettingsOption)) {
                        $storeSettingsOption = [];
                    }
                    $fieldMap = [
                        'seller_vat_id'                => 'seller_vat_id',
                        'seller_tax_id'                => 'seller_tax_id',
                        'seller_legal_name'            => 'company_name',
                        'seller_legal_registration_id' => 'legal_registration_id',
                    ];
                    $changed = false;
                    foreach ($fieldMap as $fromKey => $toKey) {
                        if (!empty($sellerDetails[$fromKey]) && empty($storeSettingsOption[$toKey])) {
                            $storeSettingsOption[$toKey] = sanitize_text_field($sellerDetails[$fromKey]);
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        update_option('fluent_cart_store_settings', $storeSettingsOption, true);
                    }
                }
                update_option('_fluent_cart_seller_details_migrated', '1', 'no');
            }

            // 2026-06-03
            // Fix: convert zero-date datetime columns to NULL (Issue #1911).
            // Zero-dates arise on MySQL servers without STRICT_TRANS_TABLES when an
            // empty string is written to a DATETIME column.
            $table_name = $wpdb->prefix . 'fct_subscriptions';
            foreach (['next_billing_date', 'canceled_at', 'expire_at'] as $col) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query($wpdb->prepare(
                    "UPDATE %i SET `{$col}` = NULL WHERE `{$col}` IN ('0000-00-00 00:00:00', '0000-00-00')",
                    $table_name
                ));
            }

            // 2026-06-17
            // Advanced Variation - Create the attribute
            // schema (and seed the system-template groups) on in-place plugin
            // updates too — migrate() only runs on activation, but existing
            // installs reach this version-gated path without reactivating. Each
            // migrator is idempotent (dbDelta) and the seeder early-returns when
            // any group already exists, so re-runs never duplicate data. Order
            // matters: relations table must exist before AttributeTermsMigrator's
            // post-migration JOIN cleanup.
            AttributeGroupsMigrator::migrate();
            AttributeObjectRelationsMigrator::migrate();
            AttributeTermsMigrator::migrate();
            AttributeSeeder::seed();

            self::releaseMigrationLock();
        }
    }

    /**
     * Try to acquire the cross-request migration lock.
     *
     * Uses a MySQL advisory lock (GET_LOCK with zero wait) so only one request
     * runs the schema upgrade at a time. If the connection dies mid-migration,
     * MySQL releases the lock automatically when the connection closes.
     *
     * @return bool True when this request may run the migration.
     */
    private static function acquireMigrationLock()
    {
        global $wpdb;

        if (Schema::isSqlite()) {
            // GET_LOCK is MySQL-only; SQLite has no concurrent writers.
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $acquired = $wpdb->get_var($wpdb->prepare(
            "SELECT GET_LOCK(%s, 0)",
            self::getMigrationLockName()
        ));

        // NULL means the server could not create the lock — fail open so a
        // locking hiccup can never block schema upgrades entirely.
        return $acquired === null || (string)$acquired === '1';
    }

    private static function releaseMigrationLock()
    {
        global $wpdb;

        if (Schema::isSqlite()) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "SELECT RELEASE_LOCK(%s)",
            self::getMigrationLockName()
        ));
    }

    private static function getMigrationLockName()
    {
        global $wpdb;

        // GET_LOCK names are server-wide; scope to this site's DB and prefix
        // so two WordPress installs on one MySQL server can't block each other.
        $dbName = defined('DB_NAME') ? DB_NAME : '';

        return 'fct_db_migration_' . md5($dbName . '|' . $wpdb->prefix);
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
