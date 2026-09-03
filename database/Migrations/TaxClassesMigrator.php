<?php

namespace FluentCart\Database\Migrations;


use FluentCart\App\Models\TaxClass;
use FluentCart\Framework\Database\Schema;
use FluentCart\Framework\Support\Str;

class TaxClassesMigrator extends Migrator
{

    public static string $tableName = 'fct_tax_classes';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_tcl_';
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
			`title` VARCHAR(192) NULL,
            `slug` VARCHAR(100) NULL,
            `description` longtext NULL,
            `meta` json DEFAULT NULL,
			`created_at` DATETIME NULL ,
			`updated_at` DATETIME NULL";
    }

    public static function migrated()
    {
        static::renameCategoriesToMeta();
        static::addMetaColumn();
        static::addSlugColumn();
        static::addDescriptionColumn();
        static::backfillNullSlugs();
        static::deduplicateSlugs();
        static::addSlugUniqueIndex();
        static::seedDefaultTaxClass();
    }

    public static function renameCategoriesToMeta()
    {
        // "ALTER TABLE %i CHANGE `categories` `meta` JSON"
        // Only rename if categories exists and meta doesn't (avoid collision)
        if (Schema::hasColumn('categories', static::$tableName) && !Schema::hasColumn('meta', static::$tableName)) {
            Schema::alterTable(
                static::$tableName,
                "CHANGE `categories` `meta` JSON"
            );
        }
    }

    public static function addMetaColumn()
    {
        // "ALTER TABLE %i ADD COLUMN `meta` JSON"
        static::addColumnIfNotExists('meta', 'JSON');
    }

    public static function addSlugColumn()
    {
        // "ALTER TABLE %i ADD COLUMN `slug` VARCHAR(100) NULL AFTER `title`"
        static::addColumnIfNotExists('slug', 'VARCHAR(100) NULL', 'title');
    }

    public static function addDescriptionColumn()
    {
        // "ALTER TABLE %i ADD COLUMN `description` LONGTEXT NULL AFTER `slug`"
        static::addColumnIfNotExists('description', 'LONGTEXT NULL', 'slug');
    }

    /**
     * Backfill NULL slugs for rows created before the slug column existed.
     */
    public static function backfillNullSlugs()
    {
        $rows = TaxClass::query()->whereNull('slug')->get();

        foreach ($rows as $row) {
            $base = Str::slug($row->title);
            if (!$base) {
                $base = 'tax-class';
            }

            $slug = $base;
            $suffix = 2;

            while (TaxClass::query()->where('slug', $slug)->where('id', '!=', $row->id)->exists()) {
                $slug = $base . '-' . $suffix;
                $suffix++;
            }

            TaxClass::query()->where('id', $row->id)->update(['slug' => $slug]);
        }
    }

    /**
     * Remove duplicate slug rows, keeping the one with the lowest ID.
     * Repoints `fct_tax_rates.class_id` references to the kept row first
     * to avoid orphaning tax rates.
     */
    public static function deduplicateSlugs()
    {
        global $wpdb;
        $table = $wpdb->prefix . static::$tableName;
        $ratesTable = $wpdb->prefix . 'fct_tax_rates';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $duplicates = $wpdb->get_results(
            "SELECT `slug`, MIN(`id`) AS keep_id, COUNT(*) AS cnt FROM `{$table}` WHERE `slug` IS NOT NULL GROUP BY `slug` HAVING cnt > 1"
        );

        if (empty($duplicates)) {
            return;
        }

        foreach ($duplicates as $dup) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $deleteIds = $wpdb->get_col($wpdb->prepare(
                "SELECT `id` FROM `{$table}` WHERE `slug` = %s AND `id` != %d",
                $dup->slug,
                $dup->keep_id
            ));

            if (empty($deleteIds)) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($deleteIds), '%d'));

            // Repoint tax rates to the kept class so rates aren't orphaned.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                "UPDATE `{$ratesTable}` SET `class_id` = %d WHERE `class_id` IN ({$placeholders})",
                array_merge([$dup->keep_id], $deleteIds)
            ));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$table}` WHERE `id` IN ({$placeholders})",
                $deleteIds
            ));
        }
    }

    /**
     * Add a UNIQUE index on the slug column.
     */
    public static function addSlugUniqueIndex()
    {
        $indexName = static::getDbPrefix() . 'fct_tcl_slug_unq';
        static::addIndexIfNotExists($indexName, 'slug', true);
    }

    public static function seedDefaultTaxClass()
    {
        global $wpdb;

        if (TaxClass::query()->where('slug', 'standard')->exists()) {
            return;
        }

        $table = static::getTableName();

        // INSERT IGNORE so a concurrent migration run that inserted the row
        // between our exists() check and this insert can't raise a
        // duplicate-entry error on the unique slug index.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO %i (`slug`, `title`, `created_at`, `updated_at`) VALUES (%s, %s, %s, %s)",
            $table,
            'standard',
            'Standard',
            current_time('mysql', true),
            current_time('mysql', true)
        ));
    }
}
