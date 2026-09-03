<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Database\Migrations\Migrator;
use FluentCart\Framework\Database\Schema;

class AttributeGroupsMigrator extends Migrator
{
    public static string $tableName = 'fct_atts_groups';

    public static function getSqlSchema(): string
    {
        return "`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `title` VARCHAR(192) NOT NULL,
                `slug` VARCHAR(192) NOT NULL UNIQUE,
                `description` longtext NULL,
                `settings` longtext NULL,
                `serial` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `is_system` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL";
    }

    public static function migrated()
    {
        global $wpdb;
        $tableName      = static::getTableName();
        $slugUniqueName = static::getDbPrefix() . 'fct_atts_grp_slug_unique';
        $slugTempIndex  = static::getDbPrefix() . 'fct_atts_grp_slug_tmp';

        // Defensive: dbDelta can silently fail to create the table (read-only
        // DB user, full disk, plugin conflict). Bail before running cleanup
        // queries that would error against a missing table and surface as
        // "unexpected output during activation" warnings.
        if (!Schema::hasTable(static::$tableName)) {
            return;
        }

        // Add columns before the slug-dedupe transaction — independent of slug
        // cleanup, so a dedupe failure (non-convergent duplicates) cannot block
        // these columns from landing on existing installs.
        static::addColumnIfNotExists('is_system', 'TINYINT(1) NOT NULL DEFAULT 0');
        // `serial` drives the merchant's manual drag-order of attribute groups in
        // the Attributes library. New installs get it from getSqlSchema(); existing
        // installs get it here so the column lands on every activation. NOT NULL
        // DEFAULT 0 so existing rows (and any insert that omits serial) land at 0 —
        // our "unassigned" sentinel — rather than NULL, which would sort ahead of
        // every ordered row in MySQL.
        static::addColumnIfNotExists('serial', 'INT(11) UNSIGNED NOT NULL DEFAULT 0');

        // One-time backfill: give every unassigned group (serial 0 / NULL) a dense
        // serial so the manual order has a stable starting point. Assigned in the
        // legacy display order (system groups first, then title) so existing
        // installs keep a familiar order until the merchant drags. New serials
        // continue after any already-assigned (>0) rows so a backfill never
        // collides with a prior reorder. Gated on the presence of unassigned rows
        // so it's a no-op once every group has serial >= 1. The per-row UPDATE loop
        // is a bounded one-shot (groups are capped at 200) wrapped in a transaction
        // so a mid-loop failure rolls back rather than leaving a partial backfill.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $serialBackfillIds = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM %i WHERE serial = 0 OR serial IS NULL ORDER BY is_system DESC, title ASC, id ASC",
            $tableName
        ));
        if (!empty($serialBackfillIds)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $nextSerial = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(MAX(serial), 0) FROM %i WHERE serial > 0",
                $tableName
            ));
            $wpdb->query('START TRANSACTION');
            try {
                foreach ($serialBackfillIds as $groupId) {
                    $nextSerial++;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->query($wpdb->prepare(
                        "UPDATE %i SET serial = %d WHERE id = %d",
                        $tableName,
                        $nextSerial,
                        (int) $groupId
                    ));
                }
                $wpdb->query('COMMIT');
            } catch (\Throwable $e) {
                $wpdb->query('ROLLBACK');
                throw $e;
            }
        }

        // Performance: the slug dedupe self-join below joins g1.slug = g2.slug.
        // On a fresh install the inline UNIQUE on slug from getSqlSchema()
        // covers the join, but on an existing install with duplicates dbDelta
        // silently failed to add that UNIQUE, leaving the self-join with no
        // covering index. Without this temp index the UPDATE degrades to a
        // quadratic full table scan and can time out activation on large
        // groups tables. The temp is dropped after the post-cleanup UNIQUE
        // add below takes over.
        static::addIndexIfNotExists($slugTempIndex, ['slug']);

        // Reconcile duplicate slugs before any constraint enforcement can fail.
        // The inline UNIQUE on slug in getSqlSchema() applies cleanly on fresh
        // CREATE TABLE, but on existing installs whose data was touched by raw
        // SQL or partial imports, duplicates can sneak in and block the index.
        // Append a per-row suffix to the higher-id duplicate so the lowest id
        // keeps the canonical slug and no data is lost. LEFT slug 170 caps
        // the base so the dup-id suffix cannot overflow the column VARCHAR
        // 192 limit even with the largest plausible BIGINT id.
        //
        // The dedupe loops with an increasing iteration counter appended to
        // the generated slug so a pathological collision (e.g. a pre-existing
        // row whose slug already matches the first-pass generated slug-dup-id
        // value) gets resolved on a subsequent pass with extra entropy. Cap
        // at 50 iterations as a hard safety net so the migrator cannot spin
        // forever on truly pathological input. Each iteration appends a
        // larger counter so a fresh collision space is sampled every pass —
        // even on heavily corrupted data 50 rounds converge in practice.
        // EXISTS is used instead of COUNT for the convergence check so the
        // verification can short-circuit on the first duplicate row rather
        // than scanning the whole table on every pass.
        // Wrap the dedupe loop in a transaction so a mid-loop failure (lock
        // timeout, OOM, deadlock) rolls every UPDATE back to the pre-loop
        // state instead of leaving the table partially mutated. ALTER TABLE
        // statements above (temp index ADD) and below (final UNIQUE) auto-
        // commit and stay outside this transaction by design.
        $iterationLimit = 50;
        $converged      = false;
        $wpdb->query('START TRANSACTION');
        try {
            for ($iteration = 1; $iteration <= $iterationLimit; $iteration++) {
                $suffix = $iteration === 1 ? "CONCAT('-dup-', g1.id)"
                                           : "CONCAT('-dup-', g1.id, '-', " . (int) $iteration . ")";
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query($wpdb->prepare(
                    "UPDATE %i g1
                     INNER JOIN %i g2 ON g1.slug = g2.slug AND g1.id > g2.id
                     SET g1.slug = CONCAT(LEFT(g1.slug, 160), {$suffix})",
                    $tableName,
                    $tableName
                ));

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $hasDuplicate = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT EXISTS(SELECT 1 FROM %i GROUP BY slug HAVING COUNT(*) > 1)',
                    $tableName
                ));
                if ($hasDuplicate === 0) {
                    $converged = true;
                    break;
                }
            }

            // Throw BEFORE COMMIT so the catch block can rollback every
            // partial UPDATE pass. If we committed first and threw after,
            // the table would persist 50 iterations of -dup-id-N suffixes
            // even though activation failed. Matches the throw-before-commit
            // pattern in AttributeTermsMigrator::migrated().
            if (!$converged) {
                throw new \RuntimeException(
                    'Attributes migration: failed to converge group slug dedupe within ' .
                    $iterationLimit . ' iterations on table ' . $tableName . '. Manual repair required.'
                );
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        static::dropLegacyTitleUniqueIndexes();

        // Now that duplicate slugs are reconciled, ensure the UNIQUE constraint
        // is actually present on the slug column. On existing installs with
        // pre-existing duplicates, dbDelta silently fails to add the inline
        // UNIQUE declared in getSqlSchema() and never retries it — leaving the
        // table without DB-level slug uniqueness until a manual repair. Detect
        // any existing unique index on the slug column (handles both the
        // auto-named index dbDelta would have created from the inline UNIQUE
        // and our explicit named version) and add the explicit named one if
        // nothing covers it.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existingSlugUnique = $wpdb->get_results($wpdb->prepare(
            "SHOW INDEX FROM %i WHERE Column_name = %s AND Non_unique = 0",
            $tableName,
            'slug'
        ));
        if (empty($existingSlugUnique)) {
            Schema::alterTable(
                static::$tableName,
                "ADD UNIQUE INDEX `{$slugUniqueName}` (`slug`)"
            );
        }

        // Drop the temporary supporting index now that the final UNIQUE on
        // slug covers the same column. Leaving the non-unique index in place
        // would shadow the UNIQUE and waste write amplification on every
        // insert and update.
        static::dropIndexIfExists($slugTempIndex);
    }

    public static function dropLegacyTitleUniqueIndexes()
    {
        global $wpdb;

        $tableName = static::getTableName();

        // SHOW INDEX returns one row per column, so a composite UNIQUE that
        // happens to include "title" alongside other columns surfaces with
        // Column_name=title too. Pull every index row (no Column_name filter)
        // so we can group by Key_name and confirm the candidate is a
        // single-column UNIQUE strictly on title before dropping it. Without
        // this guard, a future schema that adds e.g. UNIQUE(title, slug) would
        // be silently dropped on every activation.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results($wpdb->prepare(
            "SHOW INDEX FROM %i",
            $tableName
        ), ARRAY_A);

        if (empty($rows)) {
            return;
        }

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['Key_name']][] = $row;
        }

        foreach ($byKey as $keyName => $cols) {
            if (count($cols) !== 1) {
                continue; // composite — leave alone
            }
            $only = $cols[0];
            if (($only['Non_unique'] ?? '1') !== '0') {
                continue; // not unique
            }
            if (($only['Column_name'] ?? '') !== 'title') {
                continue; // unique but not on title
            }
            static::dropIndexIfExists($keyName);
        }
    }
}
