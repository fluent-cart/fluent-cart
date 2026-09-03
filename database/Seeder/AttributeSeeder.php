<?php

namespace FluentCart\Database\Seeder;

use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeTerm;

class AttributeSeeder
{
    /**
     * JSON encoding flags must match AttributeGroup / AttributeTerm
     * setSettingsAttribute mutators. Bulk insert() bypasses mutators, so any
     * encoding-flag drift between seed-time and runtime writes would store
     * the same logical value with two different byte representations
     * (UTF-8 unicode + forward slashes are the practical divergences).
     */
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public static function seed()
    {
        // settings.type controls how the editor renders a group's term picker:
        // 'options' (default, text chips), 'color' (swatch + hex), 'image' (thumbnail).
        // Read by AdvancedVariationConfig.vue::getGroupType().
        //
        // is_system = 1 marks these as protected store-level templates — the UI hides
        // destructive actions (edit/delete the group itself) on system rows. Merchant-
        // created groups stay is_system = 0 and are fully editable.
        // Every row must declare the same keys — WPFluent's bulk insert() builds the
        // column list from the first row and breaks if later rows omit a key.
        $now = gmdate('Y-m-d H:i:s');

        $groups = [
            self::groupRow('Color', 'color', json_encode(['type' => 'color'], self::JSON_FLAGS), $now),
            self::groupRow('Size', 'size', null, $now),
            self::groupRow('Material', 'material', null, $now),
            self::groupRow('Storage', 'storage', null, $now),
            self::groupRow('Memory (RAM)', 'memory', null, $now),
            self::groupRow('Weight', 'weight', null, $now),
            self::groupRow('Style', 'style', null, $now),
            self::groupRow('Pattern', 'pattern', null, $now),
            self::groupRow('Age Group', 'age-group', null, $now),
            self::groupRow('Target Gender', 'target-gender', null, $now),
        ];

        $systemSlugs = array_column($groups, 'slug');

        // Slug-based idempotency, NOT table-emptiness. A merchant on an
        // older Pro could have created their own groups before this seeder
        // existed; the seeder must still backfill any of the 10 canonical
        // system templates that are missing. We detect which of OUR slugs
        // are already present and skip only those — never touching merchant
        // rows, never duplicating system rows.
        $existingSystemSlugs = AttributeGroup::query()
            ->whereIn('slug', $systemSlugs)
            ->pluck('slug')
            ->toArray();

        $missingSlugs = array_values(array_diff($systemSlugs, $existingSystemSlugs));

        if (empty($missingSlugs)) {
            return;
        }

        // Term sets keyed by group slug. Plain strings = title only. Arrays let us
        // attach per-term settings (e.g. hex color for the swatch renderer).
        $terms = [
            'color' => [
                ['title' => 'Red',    'color' => '#ef4444'],
                ['title' => 'Blue',   'color' => '#3b82f6'],
                ['title' => 'Green',  'color' => '#22c55e'],
                ['title' => 'Yellow', 'color' => '#eab308'],
                ['title' => 'Black',  'color' => '#000000'],
                ['title' => 'White',  'color' => '#ffffff'],
                ['title' => 'Grey',   'color' => '#6b7280'],
                ['title' => 'Gold',   'color' => '#d4af37'],
                ['title' => 'Silver', 'color' => '#c0c0c0'],
                ['title' => 'Beige',  'color' => '#f5f5dc'],
            ],
            'size' => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
            'material' => ['Cotton', 'Polyester', 'Silk', 'Wool', 'Leather', 'Linen', 'Nylon', 'Wood', 'Metal', 'Plastic'],
            'storage' => ['64 GB', '128 GB', '256 GB', '512 GB', '1 TB', '2 TB'],
            'memory' => ['4 GB', '8 GB', '16 GB', '32 GB', '64 GB'],
            'weight' => ['100 g', '250 g', '500 g', '1 kg', '2 kg', '5 kg'],
            'style' => ['Classic', 'Modern', 'Vintage', 'Minimalist'],
            'pattern' => ['Solid', 'Striped', 'Checked', 'Floral', 'Geometric'],
            'age-group' => ['Newborn', 'Infant', 'Toddler', 'Kids', 'Teen', 'Adult', 'All Ages'],
            'target-gender' => ['Female', 'Male', 'Unisex'],
        ];

        // Wrap the bulk-insert chain in a single transaction. Without this, a
        // partial failure during the per-group term insert leaves the store
        // with some groups seeded and others empty — and because the
        // idempotency guard above is slug-based, the next activation would
        // see the partially-seeded slugs as "already present" and skip
        // them, leaving their terms permanently missing.
        $db = AttributeGroup::query()->getConnection();
        $db->beginTransaction();
        $progress = null;

        try {
            // Authoritative re-check INSIDE the transaction. Closes the
            // race window between the pre-flight check above and
            // beginTransaction(): if a parallel process inserted any of
            // our missing system slugs while we were preparing, recompute
            // the set so the slug UNIQUE constraint can't trip us.
            $existingSystemSlugs = AttributeGroup::query()
                ->whereIn('slug', $systemSlugs)
                ->pluck('slug')
                ->toArray();
            $missingSlugs = array_values(array_diff($systemSlugs, $existingSystemSlugs));

            if (empty($missingSlugs)) {
                $db->rollBack();
                return;
            }

            // Filter the row set to only the missing slugs so we never
            // try to re-insert a system group the merchant (or a parallel
            // activation) already has.
            $missingSlugSet = array_flip($missingSlugs);
            $rowsToInsert   = array_values(array_filter(
                $groups,
                static function ($row) use ($missingSlugSet) {
                    return isset($missingSlugSet[$row['slug']]);
                }
            ));

            // Give each seeded template a real serial so it joins the manual
            // drag-order with a position instead of the column default (0).
            // Continue after the current max so templates seeded later (e.g. on
            // an upgrade where the merchant already has groups) append to the end
            // rather than colliding at 0. On a fresh install max is 0, so the
            // templates take 1..N in the curated order above (Color, Size, …).
            $nextSerial = (int) AttributeGroup::query()->max('serial');
            foreach ($rowsToInsert as &$rowToInsert) {
                $rowToInsert['serial'] = ++$nextSerial;
            }
            unset($rowToInsert);

            if (!AttributeGroup::insert($rowsToInsert)) {
                throw new \RuntimeException('AttributeGroup::insert returned false during seeding.');
            }

            // Fetch back ONLY the rows we just inserted so the term-seed
            // loop below doesn't accidentally re-seed terms for system
            // groups the merchant already had (or modified).
            $savedGroups = AttributeGroup::query()->whereIn('slug', $missingSlugs)->get();

            if (defined('WP_CLI') && WP_CLI) {
                $progress = \WP_CLI\Utils\make_progress_bar(
                    __('Seeding attributes', 'fluent-cart'),
                    count($savedGroups)
                );
            }

            foreach ($savedGroups as $group) {
                if (!isset($terms[$group->slug])) {
                    continue;
                }

                $rows = [];
                $serial = 1;

                foreach ($terms[$group->slug] as $term) {
                    // Terms can be plain strings or ['title' => ..., 'color' => ...] arrays.
                    // Color hex is stored as JSON in term.settings.color so the swatch
                    // renderer in AdvancedVariationConfig.vue can read it.
                    if (is_array($term)) {
                        $title = $term['title'] ?? null;
                        if ($title === null) {
                            // Defensive: a future maintainer who adds a term entry
                            // without a 'title' key shouldn't crash the activation.
                            continue;
                        }
                        $settings = isset($term['color'])
                            ? json_encode(['color' => $term['color']], self::JSON_FLAGS)
                            : null;
                    } else {
                        $title = $term;
                        $settings = null;
                    }

                    $rows[] = [
                        'serial'     => $serial++,
                        'group_id'   => $group->id,
                        'title'      => $title,
                        'slug'       => sanitize_title($title),
                        'settings'   => $settings,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (empty($rows)) {
                    continue;
                }

                if (!AttributeTerm::insert($rows)) {
                    throw new \RuntimeException(
                        sprintf('AttributeTerm::insert returned false for group "%s".', $group->slug)
                    );
                }

                if ($progress) {
                    $progress->tick();
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            if ($progress) {
                // Make sure the CLI progress bar doesn't leave a stuck cursor
                // when the seeder bails out partway through.
                $progress->finish();
            }
            // Rethrow so the caller (the fluent_cart/after_migrate hook fired
            // from DBMigrator::migrate() during plugin activation/migrate) can
            // surface a visible activation failure instead of silently
            // committing a half-seeded state.
            throw $e;
        }

        if ($progress) {
            $progress->finish();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize('%n');
        }
    }

    /**
     * Builds a group row with the shared column set. Centralised so all 10
     * group rows declare the same keys (WPFluent's bulk insert() builds the
     * column list from the first row and silently drops keys missing from
     * later rows).
     */
    private static function groupRow(string $title, string $slug, $settings, string $now): array
    {
        return [
            'title'       => $title,
            'slug'        => $slug,
            'settings'    => $settings,
            // Placeholder — overwritten with a real position just before insert
            // (continuing after the current max serial). Declared here so every
            // row carries the same key set for WPFluent's bulk insert().
            'serial'      => 0,
            'is_system'   => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ];
    }
}
