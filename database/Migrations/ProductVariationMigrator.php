<?php

namespace FluentCart\Database\Migrations;

class ProductVariationMigrator extends Migrator
{
    protected static int $chunkSize = 500;
    protected static string $taxBackfillCompletionOption = '_fluent_cart_variation_tax_backfill_completed';

    public static string $tableName = 'fct_product_variations';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_pd_var_';
        return "`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `post_id` BIGINT(20) UNSIGNED NOT NULL,
                `media_id` BIGINT(20) UNSIGNED NULL,
                `serial_index` INT(5) NULL,
                `sold_individually` TINYINT(1) UNSIGNED NULL DEFAULT 0,
                `variation_title` VARCHAR(192) NOT NULL,
                `variation_identifier` VARCHAR(100) NULL,
                `sku` VARCHAR(30) NULL DEFAULT NULL,
                `manage_stock` TINYINT(1) NULL DEFAULT 0,
                `payment_type` VARCHAR(50) NULL,
                `stock_status` VARCHAR(30) NULL DEFAULT 'out-of-stock',
                `backorders` TINYINT(1) UNSIGNED NULL DEFAULT 0,
                `total_stock` INT(11) NULL DEFAULT 0,
                `on_hold` INT(11) NULL DEFAULT 0,
                `committed` INT(11) NULL DEFAULT 0,
                `available` INT(11) NULL DEFAULT 0,
                `fulfillment_type` VARCHAR(100) NULL DEFAULT 'physical', /* physicl, digital, service, mixed*/
                `item_status` VARCHAR(30) NULL DEFAULT 'active',
                `manage_cost` VARCHAR(30) NULL DEFAULT 'false',
                `item_price` double DEFAULT 0 NOT NULL,
                `item_cost` double DEFAULT 0 NOT NULL,
                `compare_price` double DEFAULT 0 NULL,
                `shipping_class` BIGINT(20) NULL,
                `other_info` longtext NULL,
                `downloadable` VARCHAR(30) NULL DEFAULT 'false',
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                INDEX `{$indexPrefix}_post_id_idx` (`post_id` ASC),
                UNIQUE INDEX `sku_unique` (`sku` ASC),
                INDEX `{$indexPrefix}_stock_status_idx` (`stock_status` ASC)";
    }

    public static function migrated()
    {
        static::addSkuColumn();
        static::backfillProductLevelTaxToVariations();
    }

    public static function addSkuColumn()
    {
        // "ALTER TABLE %i ADD COLUMN `sku` VARCHAR(30) NULL DEFAULT NULL AFTER `variation_identifier`"
        static::addColumnIfNotExists('sku', 'VARCHAR(30) NULL DEFAULT NULL', 'variation_identifier');
        // "ALTER TABLE %i ADD UNIQUE INDEX `sku_unique` (`sku` ASC)"
        static::addIndexIfNotExists('sku_unique', 'sku', true);
    }

    /**
     * Copy tax settings from product.detail.other_info down to each variation that has
     * no explicit override. Runs only on variations that are missing the key entirely —
     * any variation that already carries its own tax_exempt or tax_class is left untouched.
     *
     * Idempotent: updates only missing variation keys, and marks completion so future
     * migration runs do not rescan every product detail row on activation.
     */
    public static function backfillProductLevelTaxToVariations()
    {
        if (get_option(static::$taxBackfillCompletionOption) === 'yes') {
            return;
        }

        global $wpdb;

        $detailsTable    = $wpdb->prefix . 'fct_product_details';
        $variationsTable = $wpdb->prefix . 'fct_product_variations';
        $lastId          = 0;
        $taxClassSlugMap = [];

        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT `id`, `post_id`, `other_info`
                FROM `{$detailsTable}`
                WHERE `id` > %d
                ORDER BY `id` ASC
                LIMIT %d",
                $lastId,
                static::$chunkSize
            ));

            if (empty($rows)) {
                break;
            }

            $qualifyingRows = [];
            $postIds = [];

            foreach ($rows as $row) {
                $lastId = (int) $row->id;

                $detailInfo = json_decode($row->other_info, true);
                if (!is_array($detailInfo)) {
                    continue;
                }

                $productTaxExempt = isset($detailInfo['tax_exempt']) ? (string) $detailInfo['tax_exempt'] : '';
                $productTaxClass  = isset($detailInfo['tax_class'])  ? (string) $detailInfo['tax_class']  : '';

                // Resolve tax_class stored as a numeric ID (product level) to its slug (variation level).
                $taxClassSlug = static::resolveVariationTaxClassSlug($productTaxClass, $taxClassSlugMap);

                $needsExempt = ($productTaxExempt === 'yes');
                $needsClass  = ($taxClassSlug !== '');

                if (!$needsExempt && !$needsClass) {
                    continue;
                }

                $postId = (int) $row->post_id;
                $qualifyingRows[] = [
                    'post_id'      => $postId,
                    'needs_exempt' => $needsExempt,
                    'tax_class'    => $taxClassSlug,
                ];
                $postIds[$postId] = $postId;
            }

            if (empty($qualifyingRows)) {
                continue;
            }

            $variationGroups = [];
            $variationInfoMap = [];
            $placeholders = implode(', ', array_fill(0, count($postIds), '%d'));

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $variations = $wpdb->get_results($wpdb->prepare(
                "SELECT `id`, `post_id`, `other_info`
                FROM `{$variationsTable}`
                WHERE `post_id` IN ({$placeholders})",
                array_values($postIds)
            ));

            foreach ($variations as $variation) {
                $variationGroups[(int) $variation->post_id][] = $variation;
            }

            foreach ($qualifyingRows as $qualifyingRow) {
                $variations = $variationGroups[$qualifyingRow['post_id']] ?? [];

                foreach ($variations as $variation) {
                    $variationId = (int) $variation->id;

                    if (!array_key_exists($variationId, $variationInfoMap)) {
                        $varInfo = !empty($variation->other_info)
                            ? json_decode($variation->other_info, true)
                            : [];

                        if (!is_array($varInfo)) {
                            $varInfo = [];
                        }

                        $variationInfoMap[$variationId] = $varInfo;
                    }

                    $varInfo = $variationInfoMap[$variationId];
                    $updated = false;

                    // Only set tax_exempt when the variation has no explicit value at all.
                    if ($qualifyingRow['needs_exempt'] && !array_key_exists('tax_exempt', $varInfo)) {
                        $varInfo['tax_exempt'] = 'yes';
                        $updated = true;
                    }

                    // Only set tax_class when the variation has no explicit value at all.
                    if ($qualifyingRow['tax_class'] !== '' && !array_key_exists('tax_class', $varInfo)) {
                        $varInfo['tax_class'] = $qualifyingRow['tax_class'];
                        $updated = true;
                    }

                    if (!$updated) {
                        continue;
                    }

                    $variationInfoMap[$variationId] = $varInfo;

                    $wpdb->update(
                        $variationsTable,
                        ['other_info' => wp_json_encode($varInfo)],
                        ['id'         => $variationId],
                        ['%s'],
                        ['%d']
                    );
                }
            }
        } while (count($rows) === static::$chunkSize);

        update_option(static::$taxBackfillCompletionOption, 'yes', 'no');
    }

    protected static function resolveVariationTaxClassSlug($productTaxClass, array &$taxClassSlugMap): string
    {
        if ($productTaxClass === '') {
            return '';
        }

        if (is_numeric($productTaxClass)) {
            $taxClassId = (int) $productTaxClass;

            if (!array_key_exists($taxClassId, $taxClassSlugMap)) {
                global $wpdb;

                $taxClassTable = $wpdb->prefix . 'fct_tax_classes';

                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $slug = $wpdb->get_var($wpdb->prepare(
                    "SELECT `slug` FROM `{$taxClassTable}` WHERE `id` = %d LIMIT 1",
                    $taxClassId
                ));

                $taxClassSlugMap[$taxClassId] = $slug ? sanitize_key((string) $slug) : '';
            }

            $productTaxClass = $taxClassSlugMap[$taxClassId];
        } else {
            $productTaxClass = sanitize_key($productTaxClass);
        }

        // 'standard' is already the variation default — nothing to backfill.
        if ($productTaxClass === 'standard') {
            return '';
        }

        return $productTaxClass;
    }
}
