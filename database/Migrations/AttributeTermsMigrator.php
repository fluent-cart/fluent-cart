<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Database\Migrations\Migrator;
use FluentCart\Framework\Database\Schema;

class AttributeTermsMigrator extends Migrator
{

    public static string $tableName = 'fct_atts_terms';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_attt_';
        return "`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `group_id` BIGINT(20) UNSIGNED NOT NULL,
                `serial` INT(11) UNSIGNED,
                `title` VARCHAR(192) NOT NULL,
                `slug` VARCHAR(192) NOT NULL,
                `description` longtext NULL,
                `settings` longtext NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                INDEX `{$indexPrefix}_group_id_idx` (`group_id` ASC),
                UNIQUE INDEX `{$indexPrefix}_group_slug_unique` (`group_id` ASC, `slug`(192) ASC)";
    }

    public static function migrated()
    {
        global $wpdb;

        $indexPrefix          = static::getDbPrefix() . 'fct_attt_';
        $tableName            = static::getTableName();
        $relationsTable       = static::getDbPrefix() . 'fct_atts_relations';
        $relationsIndexPrefix = static::getDbPrefix() . 'fct_at_rel_';

        // Defensive: bail if either table is missing. dbDelta can silently
        // fail to create tables (read-only DB user, full disk, plugin conflict),
        // and the cross-table cleanup below would error against a missing
        // table and surface as "unexpected output during activation" warnings.
        if (!Schema::hasTable(static::$tableName)
            || !Schema::hasTable(AttributeObjectRelationsMigrator::$tableName)) {
            return;
        }

        $termsUniqueName        = "{$indexPrefix}_group_slug_unique";
        $relationsUniqueName    = "{$relationsIndexPrefix}_obj_grp_term_unique";
        $termsTempIndexName     = "{$indexPrefix}_grp_slug_tmp";
        $relsTempIndexName      = "{$relationsIndexPrefix}_obj_grp_term_tmp";
        $relsOrphanTempIndex    = "{$relationsIndexPrefix}_term_grp_tmp";

        // Performance: every cleanup query below joins on (group_id, slug) on
        // terms, (object_id, group_id, term_id) on relations, or
        // (term_id, group_id) on relations (the orphan LEFT JOIN sweep). On
        // existing installs whose tables predate the final UNIQUE composites,
        // those self-joins degrade to full table scans and can time out during
        // activation. Add temporary non-unique supporting indexes first so the
        // cleanup runs against an index either way. The temps are dropped at
        // the bottom of this method once the final UNIQUE composites take over.
        static::addIndexIfNotExists($termsTempIndexName, ['group_id', 'slug']);
        if (!static::indexExistsOnTable($relationsTable, $relsTempIndexName)) {
            Schema::alterTable(
                AttributeObjectRelationsMigrator::$tableName,
                "ADD INDEX `{$relsTempIndexName}` (`object_id` ASC, `group_id` ASC, `term_id` ASC)"
            );
        }
        if (!static::indexExistsOnTable($relationsTable, $relsOrphanTempIndex)) {
            Schema::alterTable(
                AttributeObjectRelationsMigrator::$tableName,
                "ADD INDEX `{$relsOrphanTempIndex}` (`term_id` ASC, `group_id` ASC)"
            );
        }

        // Wrap the whole cleanup chain (every DELETE/UPDATE through the
        // terms+relations dedupe loops below) in a transaction so a mid-
        // chain failure rolls every write back to the pre-cleanup state
        // instead of leaving the tables partially repaired. ALTER TABLE
        // statements above (temp index ADDs) and below (final UNIQUE adds,
        // NOT NULL, plain index, temp DROPs) auto-commit and stay outside
        // this transaction by design.
        $iterationLimit = 50;
        $wpdb->query('START TRANSACTION');
        try {

        // Drop relations that point at NULL-group terms BEFORE we delete those
        // terms below. A NULL-group term has no group context, so any relation
        // referencing it is meaningless and cannot be repointed (the slug-twin
        // repoint below depends on a non-NULL group_id for the JOIN). Without
        // this step, deleting the orphan term would leave the relation row
        // pointing at a non-existent term_id.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            'DELETE r FROM %i r
             INNER JOIN %i t ON r.term_id = t.id
             WHERE t.group_id IS NULL',
            $relationsTable,
            $tableName
        ));

        // Remove orphaned terms with no group before enforcing NOT NULL.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE `group_id` IS NULL', $tableName));

        // Repoint any relations that reference a term about to be discarded to its
        // surviving twin (lowest id wins), so no fct_atts_relations rows are orphaned.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            'UPDATE %i r
             INNER JOIN %i t1 ON r.term_id = t1.id
             INNER JOIN %i t2 ON t1.group_id = t2.group_id AND t1.slug = t2.slug AND t1.id > t2.id
             SET r.term_id = t2.id',
            $relationsTable,
            $tableName,
            $tableName
        ));

        // Drop pre-existing orphan relations whose term no longer exists OR whose
        // group_id does not match the matched term's group_id. The cleanup steps
        // above all use INNER JOIN on terms, so they skip relations with dangling
        // term_id references (rows left over from terms that were hard-deleted
        // via raw SQL bypass before this migrator shipped) and rows where the
        // relation's stored group_id disagrees with its term's group_id. A LEFT
        // JOIN that matches both fields catches both classes in one pass —
        // anything that does not have a live matching term row is an orphan and
        // gets removed before the UNIQUE add below.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            'DELETE r FROM %i r
             LEFT JOIN %i t ON r.term_id = t.id AND r.group_id = t.group_id
             WHERE t.id IS NULL',
            $relationsTable,
            $tableName
        ));

        // The repoint above can leave duplicate (object_id, group_id, term_id) rows when
        // an object was related to both a duplicate term and its surviving twin before
        // migration — both rows now have the same term_id. Also catches any pre-existing
        // duplicates (rare import artifacts) so the UNIQUE index below can be added
        // without violations. Keep the lowest id, delete the rest. Loop with
        // verification in case a concurrent write during activation produces a fresh
        // duplicate after the first DELETE pass; bail with an exception if 50
        // iterations cannot converge so activation fails loudly instead of silently
        // skipping the UNIQUE add downstream.
        $relsConverged = false;
        for ($iteration = 1; $iteration <= $iterationLimit; $iteration++) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                'DELETE r1 FROM %i r1
                 INNER JOIN %i r2
                     ON r1.object_id = r2.object_id
                     AND r1.group_id = r2.group_id
                     AND r1.term_id = r2.term_id
                     AND r1.id > r2.id',
                $relationsTable,
                $relationsTable
            ));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $hasDup = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT EXISTS(SELECT 1 FROM %i GROUP BY object_id, group_id, term_id HAVING COUNT(*) > 1)',
                $relationsTable
            ));
            if ($hasDup === 0) {
                $relsConverged = true;
                break;
            }
        }
        if (!$relsConverged) {
            throw new \RuntimeException(
                'Attributes migration: failed to converge relations dedupe within ' .
                $iterationLimit . ' iterations on table ' . $relationsTable . '. Manual repair required.'
            );
        }

        // Remove duplicate (group_id, slug) pairs, keeping the lowest id, so the
        // unique index below can be added without a constraint violation. Same
        // loop + verify + bail pattern as the relations dedupe above for symmetry.
        $termsConverged = false;
        for ($iteration = 1; $iteration <= $iterationLimit; $iteration++) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                'DELETE t1 FROM %i t1 INNER JOIN %i t2
                 ON t1.group_id = t2.group_id AND t1.slug = t2.slug AND t1.id > t2.id',
                $tableName,
                $tableName
            ));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $hasDup = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT EXISTS(SELECT 1 FROM %i GROUP BY group_id, slug HAVING COUNT(*) > 1)',
                $tableName
            ));
            if ($hasDup === 0) {
                $termsConverged = true;
                break;
            }
        }
        if (!$termsConverged) {
            throw new \RuntimeException(
                'Attributes migration: failed to converge terms dedupe within ' .
                $iterationLimit . ' iterations on table ' . $tableName . '. Manual repair required.'
            );
        }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        // Now that relations are clean, enforce the composite UNIQUE on existing
        // tables that predate it. Fresh installs already have this index from
        // AttributeObjectRelationsMigrator::getSqlSchema().
        if (!static::indexExistsOnTable($relationsTable, $relationsUniqueName)) {
            Schema::alterTable(
                AttributeObjectRelationsMigrator::$tableName,
                "ADD UNIQUE INDEX `{$relationsUniqueName}` (`object_id` ASC, `group_id` ASC, `term_id` ASC)"
            );
        }

        // Enforce NOT NULL on existing tables that were created with the old nullable schema.
        static::modifyColumnIfExists('group_id', 'BIGINT(20) UNSIGNED NOT NULL');

        // Add plain group_id index if missing.
        static::addIndexIfNotExists("{$indexPrefix}_group_id_idx", 'group_id');

        // Add composite unique index — uses slug(192) prefix so built manually.
        if (!static::hasIndex($termsUniqueName)) {
            Schema::alterTable(
                static::$tableName,
                "ADD UNIQUE INDEX `{$termsUniqueName}` (`group_id` ASC, `slug`(192) ASC)"
            );
        }

        // Drop the temporary supporting indexes now that the final UNIQUE
        // composites cover the same columns. Leaving them in place would
        // shadow the UNIQUE indexes and waste write amplification on every
        // insert/update.
        static::dropIndexIfExists($termsTempIndexName);
        if (static::indexExistsOnTable($relationsTable, $relsTempIndexName)) {
            Schema::alterTable(AttributeObjectRelationsMigrator::$tableName, "DROP INDEX `{$relsTempIndexName}`");
        }
        if (static::indexExistsOnTable($relationsTable, $relsOrphanTempIndex)) {
            Schema::alterTable(AttributeObjectRelationsMigrator::$tableName, "DROP INDEX `{$relsOrphanTempIndex}`");
        }
    }

    /**
     * Inline equivalent of Migrator::hasIndex() for an arbitrary table — the
     * base helper hardcodes static::getTableName() so we cannot reuse it when
     * the cleanup needs to touch fct_atts_relations from inside the terms
     * migrator.
     */
    private static function indexExistsOnTable(string $tableName, string $indexName): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results($wpdb->prepare(
            "SHOW INDEX FROM %i WHERE Key_name = %s",
            $tableName,
            $indexName
        ));
        return !empty($rows);
    }
}
